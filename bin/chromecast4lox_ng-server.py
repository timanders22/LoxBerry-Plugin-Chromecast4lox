#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Chromecast 4 Lox NG - Serverdienst

Verbindet Google-Chromecast-Geraete mit dem Loxone Miniserver.
Zustaende gehen per MQTT retained an den Broker, Befehle kommen per MQTT
oder - als Rueckfallweg - per UDP herein.

Grundlage ist das Plugin von Ales Berka (Aleq). Der Serverteil wurde fuer
LoxBerry 4 neu geschrieben:

  * Python 3 statt Python 2 (Python 2 fehlt auf Bookworm und Trixie
    vollstaendig, das Original startete dort gar nicht)
  * pychromecast statt der mitgelieferten Go-Binaerdatei "cast" 0.1.0.
    Von der gab es keine arm64-Fassung, auf einem 64-Bit-Raspberry-Pi-OS
    lief sie deshalb nicht.
  * mehrere Geraete statt genau einem
  * MQTT retained statt UDP als Weg zum Miniserver

Getestet gegen pychromecast 9.4 (Debian) und 13.1 (pip). Wo sich die
Schnittstelle zwischen beiden unterscheidet, sind beide Wege abgedeckt.
"""

import json
import logging
import os
import re
import socket
import sys
import threading
import time
import unicodedata
import urllib.parse
from configparser import ConfigParser


def lb_wurzel_ermitteln():
    """Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.

    Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
    config/plugins UND webfrontend enthaelt. Trifft die uebliche
    Installation genauso wie eine an einem anderen Ort.
    """
    d = os.path.dirname(os.path.abspath(__file__))
    for _ in range(8):
        if os.path.isdir(os.path.join(d, "config", "plugins")) \
                and os.path.isdir(os.path.join(d, "webfrontend")):
            return d
        eltern = os.path.dirname(d)
        if eltern == d:
            break
        d = eltern
    return ""


VERSION = "1.2.1"

# ---------------------------------------------------------------------------
# Pfade - LoxBerry ersetzt die REPLACE-Marken bei der Installation
# ---------------------------------------------------------------------------

PLUGIN_NAME = "REPLACELBPPLUGINDIR"
if PLUGIN_NAME.startswith("REPLACE"):
    PLUGIN_NAME = "chromecast-4lox-ng"

CONFIG_DIR = "REPLACELBPCONFIGDIR"
if CONFIG_DIR.startswith("REPLACE"):
    CONFIG_DIR = lb_wurzel_ermitteln() + "/config/plugins/" + PLUGIN_NAME

LOG_DIR = "REPLACELBPLOGDIR"
if LOG_DIR.startswith("REPLACE"):
    LOG_DIR = lb_wurzel_ermitteln() + "/log/plugins/" + PLUGIN_NAME

HOME_DIR = os.environ.get("LBHOMEDIR") or lb_wurzel_ermitteln()
CONFIG_FILE = os.path.join(CONFIG_DIR, PLUGIN_NAME + ".cfg")

# ---------------------------------------------------------------------------
# Protokoll
# ---------------------------------------------------------------------------

# NUR nach stdout schreiben, keinen eigenen FileHandler.
#
# Bis 1.1.0 stand hier beides: ein FileHandler auf <plugin>.log UND ein
# StreamHandler. Das Startskript leitet stdout und stderr aber ohnehin in
# genau diese Datei um ('nohup ... >> $log 2>&1'). Zwei Schreiber auf einer
# Datei, einer davon mit eigenem Dateizeiger - beim Rotieren durch LoxBerry
# schreibt der eine dann in die weggeschobene Datei weiter, waehrend der
# andere die neue benutzt.
#
# Wer das Skript von Hand aufruft, sieht die Ausgabe jetzt im Terminal -
# und das ist auch das erwartete Verhalten.
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)-7s %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
    handlers=[logging.StreamHandler(sys.stdout)],
)
log = logging.getLogger("chromecast4lox_ng")


# ---------------------------------------------------------------------------
# Konfiguration
# ---------------------------------------------------------------------------

VORGABEN = {
    "geraete": "",
    "themenpraefix": "chromecast4lox",
    "mqtt": "1",
    "udp": "1",
    "udp_port": "7090",
    "intervall": "10",
    "aktualisierung": "60",
    "lautstaerke_schritt": "5",
    # --- Ansage (TTS) ---
    # Die Felder heissen genau wie im Abfahrtsassistenten und tun dasselbe.
    # Wer dort schon eine Ansage eingerichtet hat, traegt hier dieselben
    # Werte ein und bekommt dasselbe Verhalten.
    "tts_modus": "chromecast",   # chromecast | musicserver | ms4h | audioserver | custom
    "tts_ip": "",
    "tts_port": "7091",
    "tts_zonen": "1",
    "tts_lautstaerke": "8",
    "tts_sprache": "de",
    "tts_vorlage": "",
    # Ansagelautstaerke am Chromecast. Leer = die aktuelle beibehalten.
    "tts_pegel": "",
    # Nach der Ansage wieder aufnehmen, was vorher lief.
    "tts_fortsetzen": "1",
    # Gruppen mitsuchen (Google-Lautsprechergruppen).
    "gruppen": "1",
}


def konfiguration_lesen():
    """Konfiguration im Config::Lite-Format lesen. Fehlende Werte werden
    durch die Vorgaben ersetzt, damit der Dienst nie ohne Werte dasteht."""
    werte = dict(VORGABEN)
    parser = ConfigParser(interpolation=None)
    parser.optionxform = str
    try:
        with open(CONFIG_FILE, "r", encoding="utf-8") as fh:
            parser.read_string(fh.read())
    except (OSError, Exception) as fehler:  # noqa: BLE001
        log.warning("Konfiguration %s nicht lesbar: %s", CONFIG_FILE, fehler)
        return werte

    for abschnitt in parser.sections():
        for schluessel, wert in parser.items(abschnitt):
            werte[schluessel.strip().lower()] = wert.strip().strip('"').strip("'")
    return werte


def geraeteliste(cfg):
    """Geraetenamen aus der Konfiguration. Semikolon, Komma oder Zeilenumbruch
    trennen; leere Eintraege entfallen."""
    roh = cfg.get("geraete", "") or ""
    teile = re.split(r"[;,\n\r]+", roh)
    return [t.strip() for t in teile if t.strip()]


def zahl_oder(wert, vorgabe):
    """Aus einer Nutzlast eine ganze Zahl machen - oder die Vorgabe nehmen.

    Bis 1.1.0 stand an mehreren Stellen 'int(float(wert)) if wert else
    vorgabe'. Kommt dort etwas Unnumerisches an - und aus einem virtuellen
    Ausgang in Loxone kommt schnell einmal 'up' oder 'on' -, wirft float()
    einen ValueError. Der wurde vom grossen except-Block gefangen, und der
    ruft self.trennen() auf: ein Tippfehler in der Nutzlast trennte also die
    Verbindung zum Lautsprecher. Das ist der eigentliche Schaden, nicht die
    Unsauberkeit.
    """
    if wert is None:
        return vorgabe
    text = str(wert).strip()
    if text == "":
        return vorgabe
    try:
        return int(float(text))
    except (TypeError, ValueError):
        log.warning("'%s' ist keine Zahl - es gilt die Vorgabe %s", text, vorgabe)
        return vorgabe


def thema_saeubern(name):
    """Geraetenamen in ein MQTT-taugliches Thema umformen.
    Umlaute werden umgeschrieben, alles andere ausser Buchstaben, Ziffern,
    Strich und Unterstrich wird zu einem Unterstrich. Ohne das ergaebe
    'Wohnzimmer Lautsprecher' ein Thema mit Leerzeichen."""
    ersetzungen = {
        "ä": "ae", "ö": "oe", "ü": "ue",
        "Ä": "Ae", "Ö": "Oe", "Ü": "Ue", "ß": "ss",
    }
    for alt, neu in ersetzungen.items():
        name = name.replace(alt, neu)
    name = unicodedata.normalize("NFKD", name)
    name = "".join(c for c in name if not unicodedata.combining(c))
    name = re.sub(r"[^A-Za-z0-9_-]+", "_", name)
    return name.strip("_") or "geraet"


# ---------------------------------------------------------------------------
# Ansage (TTS)
#
# Der Bauplan der Adresse ist Wort fuer Wort der aus dem Abfahrtsassistenten
# 1.5.0 (abfahrt_tts_url): dieselben Modi, dieselben Feldnamen, dieselben
# Platzhalter. Wer dort eine Ansage eingerichtet hat, traegt hier dieselben
# Werte ein.
#
# Dazu kommt ein Modus, den es dort nicht geben kann: 'chromecast'. Ein
# Chromecast ist kein Loxone Music Server - er nimmt keine TTS-Adresse
# entgegen, sondern spielt eine Audiodatei ab. Also wird eine Adresse
# gebaut, die eine Audiodatei liefert, und die bekommt der Lautsprecher
# ueber play_media().
#
# Warum Google Translate und nicht etwas Eigenes: es braucht keinen
# Schluessel, keine Anmeldung und keine zusaetzliche Installation. Es ist
# ausdruecklich KEINE zugesicherte Schnittstelle - Google kann sie jederzeit
# aendern. Deshalb steht sie hier nicht allein: 'custom' nimmt jede eigene
# Adresse, und wer eine lokale Sprachausgabe betreibt (Piper, MaryTTS,
# opentts), traegt deren Adresse dort ein und ist von Google unabhaengig.
# ---------------------------------------------------------------------------

# Google Translate nimmt hoechstens 200 Zeichen je Anfrage.
TTS_MAX = 200


def tts_teile(text, laenge=TTS_MAX):
    """Langen Text an Satzgrenzen teilen.

    Ein Chromecast spielt eine Adresse nach der anderen ab; ohne Teilung
    schnitte Google Translate nach 200 Zeichen einfach ab, und der Rest der
    Ansage fiele weg - ohne jede Meldung.
    """
    text = " ".join(str(text).split())
    if len(text) <= laenge:
        return [text] if text else []
    teile = []
    rest = text
    while rest:
        if len(rest) <= laenge:
            teile.append(rest)
            break
        schnitt = -1
        for zeichen in (". ", "! ", "? ", "; ", ", ", " "):
            k = rest.rfind(zeichen, 0, laenge)
            if k > schnitt:
                schnitt = k + len(zeichen) - 1
        if schnitt <= 0:
            schnitt = laenge
        teile.append(rest[:schnitt].strip())
        rest = rest[schnitt:].strip()
    return [t for t in teile if t]


def tts_adressen(text, cfg):
    """Adresse(n) fuer die Ansage bauen.

    Rueckgabe: (Liste von Adressen, Modus). Eine leere Liste heisst: in
    diesem Modus spricht das Plugin nicht selbst.
    """
    modus = (cfg.get("tts_modus") or "chromecast").strip().lower()
    ip = (cfg.get("tts_ip") or "").strip()
    port = zahl_oder(cfg.get("tts_port"), 7091)
    zonen = (cfg.get("tts_zonen") or "1").strip()
    pegel = zahl_oder(cfg.get("tts_lautstaerke"), 8)
    sprache = (cfg.get("tts_sprache") or "de").strip() or "de"

    if modus == "audioserver":
        # Der originale Loxone Audioserver bietet keine HTTP-TTS-Schnitt-
        # stelle. Dort baut man die Ansage in Loxone Config: Textgenerator
        # an den TTS-Eingang. Genauso steht es im Abfahrtsassistenten.
        return [], modus

    if modus == "chromecast":
        return ([("https://translate.google.com/translate_tts?ie=UTF-8&client=tw-ob"
                  "&tl=" + urllib.parse.quote(sprache)
                  + "&q=" + urllib.parse.quote(stueck))
                 for stueck in tts_teile(text)], modus)

    if modus == "musicserver":
        # Zonenliste normalisieren: "2,4,6" plus Lautstaerkefeld ergibt
        # "2~8,4~8,6~8". Ausdrueckliche Angaben "Zone~Lautstaerke" gewinnen.
        if ip == "":
            return [], modus
        liste = []
        for z in zonen.split(","):
            z = z.strip()
            if z == "":
                continue
            liste.append(z if "~" in z else "{0}~{1}".format(z, max(1, min(100, pegel))))
        zonenteil = ",".join(liste) if liste else "1~{0}".format(max(1, min(100, pegel)))
        return (["http://{0}:{1}/audio/grouped/tts/{2}/{3}".format(
            ip, port, zonenteil,
            urllib.parse.quote(sprache + "|" + text, safe=""))], modus)

    # ms4h und custom: Vorlage mit Platzhaltern
    vorlage = (cfg.get("tts_vorlage") or "").strip()
    if vorlage == "":
        vorlage = "http://{ip}:{port}/tts?text={text}&zone={zones}&vol={vol}"
    # Die IP wird nur verlangt, wenn die Vorlage sie benutzt - eine eigene
    # Adresse wie "http://sprich.local/say?text={text}" braucht keine.
    if ip == "" and "{ip}" in vorlage:
        return [], modus
    fertig = vorlage
    for marke, wert in (("{ip}", ip), ("{port}", str(port)), ("{zones}", zonen),
                        ("{vol}", str(pegel)), ("{lang}", sprache),
                        ("{text}", urllib.parse.quote(text, safe=""))):
        fertig = fertig.replace(marke, wert)
    return [fertig], modus


# ---------------------------------------------------------------------------
# MQTT
# ---------------------------------------------------------------------------

def mqtt_zugangsdaten():
    """Zugangsdaten des MQTT-Gateways aus general.json lesen.
    Gross- und Kleinschreibung der Schluessel ist dort uneinheitlich -
    deshalb beide Varianten pruefen."""
    pfad = os.path.join(HOME_DIR, "config", "system", "general.json")
    try:
        with open(pfad, "r", encoding="utf-8") as fh:
            daten = json.load(fh)
    except (OSError, ValueError) as fehler:
        log.warning("general.json nicht lesbar (%s) - MQTT nicht moeglich", fehler)
        return None

    for abschnitt in ("Mqtt", "mqtt"):
        block = daten.get(abschnitt)
        if not isinstance(block, dict):
            continue

        def hole(*namen):
            for n in namen:
                if block.get(n):
                    return block[n]
            return None

        host = hole("Brokerhost", "brokerhost")
        if not host:
            continue
        return {
            "host": str(host),
            "port": int(hole("Brokerport", "brokerport") or 1883),
            "user": hole("Brokeruser", "brokeruser"),
            "pass": hole("Brokerpass", "brokerpass"),
        }
    log.warning("Kein MQTT-Broker in general.json gefunden")
    return None


class MqttAnbindung:
    """Duenne Huelle um paho-mqtt. Faellt still aus, wenn die Bibliothek
    oder das Gateway fehlt - der UDP-Weg funktioniert dann weiter."""

    def __init__(self, praefix, befehl_rueckruf):
        self.praefix = praefix
        self.befehl_rueckruf = befehl_rueckruf
        self.client = None
        self.verbunden = False
        # Nach einer Neuverbindung muessen ALLE Werte erneut gesendet
        # werden: der Broker kann seine retained-Werte verloren haben.
        self.neumeldung_faellig = False
        self.verluste = 0

    def start(self):
        try:
            import paho.mqtt.client as mqtt
        except ImportError:
            log.error("paho-mqtt fehlt - MQTT bleibt aus. "
                      "Paket python3-paho-mqtt nachinstallieren.")
            return False

        zugang = mqtt_zugangsdaten()
        if not zugang:
            return False

        try:
            self.client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION1)
        except (AttributeError, TypeError):
            # paho-mqtt 1.x kennt CallbackAPIVersion noch nicht
            self.client = mqtt.Client()

        if zugang["user"]:
            self.client.username_pw_set(zugang["user"], zugang["pass"] or "")
        self.client.will_set(self.praefix + "/server/online", "0", qos=0, retain=True)
        self.client.on_connect = self._on_connect
        self.client.on_message = self._on_message
        self.client.on_disconnect = self._on_disconnect

        # Wartezeit zwischen den Versuchen begrenzen - paho geht sonst bis
        # auf zwei Minuten hoch, und das ist beim Systemstart zu lang.
        try:
            self.client.reconnect_delay_set(min_delay=1, max_delay=30)
        except Exception:  # noqa: BLE001
            pass

        # connect_async statt connect.
        #
        # Bis 1.1.0 stand hier connect() in einem try, und schlug es fehl,
        # kehrte start() mit False zurueck - OHNE loop_start() aufzurufen,
        # aber MIT gesetztem self.client. Jedes spaetere publish() lief
        # danach auf einen Client ohne Netzwerkschleife: die Nachricht
        # verschwand, ohne Fehler, fuer die gesamte Laufzeit des Dienstes.
        # Beim Systemstart ist genau das der Regelfall, weil das
        # MQTT-Gateway noch nicht laeuft.
        #
        # connect_async wirft nicht; loop_start versucht es weiter, und
        # on_connect richtet Abo und Erstmeldung dann selbst ein.
        try:
            self.client.connect_async(zugang["host"], zugang["port"], keepalive=60)
        except AttributeError:
            try:
                self.client.connect(zugang["host"], zugang["port"], keepalive=60)
            except OSError as fehler:
                log.warning("MQTT-Broker %s:%s noch nicht erreichbar (%s) - "
                            "es wird weiter versucht.",
                            zugang["host"], zugang["port"], fehler)
        self.client.loop_start()
        log.info("MQTT-Schleife gestartet, Ziel %s:%s", zugang["host"], zugang["port"])
        return True

    def _on_connect(self, client, userdata, flags, rc, properties=None):
        if rc != 0:
            log.error("MQTT-Anmeldung abgelehnt, Code %s", rc)
            return
        self.verbunden = True
        self.neumeldung_faellig = True
        thema = self.praefix + "/+/cmd/#"
        client.subscribe(thema)
        log.info("MQTT verbunden, Befehle abonniert: %s", thema)
        self.senden("server/online", "1")

    def _on_disconnect(self, client, userdata, rc, properties=None, reason=None):
        self.verbunden = False
        log.warning("MQTT-Verbindung getrennt (Code %s), Wiederaufbau laeuft", rc)

    def _on_message(self, client, userdata, nachricht):
        try:
            teile = nachricht.topic.split("/")
            # <praefix>/<geraet>/cmd/<befehl>
            if len(teile) < 4 or teile[-2] != "cmd":
                return
            geraet = teile[-3]
            befehl = teile[-1]
            nutzlast = nachricht.payload.decode("utf-8", "replace").strip()
            log.info("MQTT-Befehl %s -> %s %s", geraet, befehl, nutzlast)
            self.befehl_rueckruf(geraet, befehl, nutzlast)
        except Exception as fehler:  # noqa: BLE001
            log.error("MQTT-Nachricht nicht verarbeitbar: %s", fehler)

    def senden(self, unterthema, wert):
        if not self.client:
            return False
        try:
            erg = self.client.publish(self.praefix + "/" + unterthema,
                                      str(wert), qos=0, retain=True)
        except Exception as fehler:  # noqa: BLE001
            log.error("MQTT-Veroeffentlichung fehlgeschlagen: %s", fehler)
            return False
        # Der Rueckgabewert wird ausgewertet. Bei qos=0 und getrennter
        # Verbindung ist die Nachricht verloren - das gehoert gezaehlt und
        # nicht verschwiegen.
        rc = getattr(erg, "rc", 0)
        if rc != 0:
            self.verluste += 1
            if self.verluste in (1, 10, 100) or self.verluste % 1000 == 0:
                log.warning("MQTT: %d Nachricht(en) nicht abgesetzt (letzter Code %s). "
                            "Laeuft das MQTT-Gateway?", self.verluste, rc)
            return False
        return True

    def stop(self):
        if not self.client:
            return
        try:
            self.senden("server/online", "0")
            # Kurz warten, damit die letzte Nachricht noch hinausgeht - sonst
            # steht im Broker retained weiter '1', und Loxone glaubt an einen
            # laufenden Dienst.
            time.sleep(0.3)
            self.client.loop_stop()
            self.client.disconnect()
        except Exception:  # noqa: BLE001
            pass


# ---------------------------------------------------------------------------
# Chromecast
# ---------------------------------------------------------------------------

def lautstaerke_setzen(cast, wert):
    """set_volume sass bis pychromecast 12 auf dem Chromecast-Objekt und
    liegt seit 13 nur noch auf dem receiver_controller. Beide Wege abdecken."""
    if hasattr(cast, "set_volume"):
        cast.set_volume(wert)
    else:
        cast.socket_client.receiver_controller.set_volume(wert)


def stumm_setzen(cast, stumm):
    if hasattr(cast, "set_volume_muted"):
        cast.set_volume_muted(stumm)
    else:
        cast.socket_client.receiver_controller.set_volume_muted(stumm)


class Geraet:
    """Ein konfigurierter Chromecast samt zuletzt gemeldetem Zustand."""

    def __init__(self, name, mqtt):
        self.name = name
        self.thema = thema_saeubern(name)
        self.mqtt = mqtt
        self.cast = None
        self.browser = None
        self.letzter_stand = {}
        self.art = ""
        self.ist_gruppe = False
        # Was lief vor einer Ansage? Fuer die Wiederaufnahme.
        self.vorher = None
        self.ansage_laeuft = False

    # -- Verbindung ---------------------------------------------------------

    def verbinden(self):
        """Geraet suchen und verbinden. Rueckgabe: True bei Erfolg."""
        try:
            import pychromecast
        except ImportError:
            log.error("pychromecast fehlt - Paket python3-pychromecast "
                      "nachinstallieren.")
            return False

        # Lautsprechergruppen werden mitgesucht.
        #
        # Eine Google-Gruppe meldet sich per mDNS wie ein eigenes Geraet,
        # traegt aber cast_type 'group'. get_listed_chromecasts findet sie
        # ohne Zutun - die Gruppe steht einfach mit ihrem Namen in den
        # Einstellungen. Der Wert ist erheblich: eine Gruppe spielt synchron
        # auf allen ihren Lautsprechern, was sich mit Einzelbefehlen nicht
        # nachbauen laesst (sie liefen um Sekundenbruchteile versetzt).
        try:
            gefunden, browser = pychromecast.get_listed_chromecasts(
                friendly_names=[self.name], discovery_timeout=8
            )
        except Exception as fehler:  # noqa: BLE001
            log.error("Suche nach '%s' fehlgeschlagen: %s", self.name, fehler)
            return False

        if not gefunden:
            log.warning("Chromecast '%s' nicht gefunden", self.name)
            self._browser_beenden(browser)
            self.melden_offline()
            return False

        self.cast = gefunden[0]
        self.browser = browser
        try:
            self.cast.wait(timeout=10)
        except Exception as fehler:  # noqa: BLE001
            log.error("Verbindung zu '%s' fehlgeschlagen: %s", self.name, fehler)
            self.cast = None
            self._browser_beenden(browser)
            self.melden_offline()
            return False

        self.art = str(getattr(self.cast, "cast_type", "") or "")
        self.ist_gruppe = self.art.lower() == "group"
        log.info("Verbunden mit '%s' (%s%s)", self.name,
                 getattr(self.cast, "model_name", "?"),
                 ", Lautsprechergruppe" if self.ist_gruppe else "")
        return True

    def _browser_beenden(self, browser):
        try:
            if browser:
                browser.stop_discovery()
        except Exception:  # noqa: BLE001
            pass

    def trennen(self):
        try:
            if self.cast:
                self.cast.disconnect(blocking=False)
        except Exception:  # noqa: BLE001
            pass
        self._browser_beenden(self.browser)
        self.cast = None
        self.browser = None

    def verbunden(self):
        return self.cast is not None and getattr(self.cast, "socket_client", None) is not None

    # -- Zustand melden -----------------------------------------------------

    def _senden(self, schluessel, wert, erzwingen=False):
        """Nur senden, wenn sich der Wert geaendert hat. Sonst laeuft der
        Broker bei kurzem Intervall unnoetig voll."""
        wert = "" if wert is None else str(wert)
        if not erzwingen and self.letzter_stand.get(schluessel) == wert:
            return
        self.letzter_stand[schluessel] = wert
        self.mqtt.senden(self.thema + "/" + schluessel, wert)

    def melden_offline(self):
        self._senden("online", "0")
        self._senden("state", "OFFLINE")
        self._senden("playing", "0")

    def melden(self, erzwingen=False):
        """Aktuellen Zustand einsammeln und veroeffentlichen."""
        if not self.verbunden():
            self.melden_offline()
            return

        try:
            cast_status = self.cast.status
            medien = self.cast.media_controller.status
        except Exception as fehler:  # noqa: BLE001
            log.warning("Zustand von '%s' nicht lesbar: %s", self.name, fehler)
            self.melden_offline()
            return

        self._senden("online", "1", erzwingen)

        self._senden("type", self.art or "cast", erzwingen)
        self._senden("group", "1" if self.ist_gruppe else "0", erzwingen)

        if cast_status is not None:
            pegel = cast_status.volume_level
            self._senden("volume", int(round((pegel or 0) * 100)), erzwingen)
            self._senden("muted", "1" if cast_status.volume_muted else "0", erzwingen)
            self._senden("app", cast_status.display_name or "", erzwingen)

        zustand = "IDLE"
        if medien is not None:
            zustand = medien.player_state or "IDLE"
            self._senden("title", medien.title or "", erzwingen)
            self._senden("artist", medien.artist or medien.album_artist or "", erzwingen)
            self._senden("album", medien.album_name or "", erzwingen)
            self._senden("duration", int(medien.duration or 0), erzwingen)
            self._senden("position", int(medien.adjusted_current_time or 0), erzwingen)
        self._senden("state", zustand, erzwingen)
        self._senden("playing", "1" if zustand == "PLAYING" else "0", erzwingen)

    # -- Befehle ------------------------------------------------------------

    # -- Ansage mit Wiederaufnahme -----------------------------------------

    def _lage_merken(self):
        """Festhalten, was gerade laeuft - fuer die Wiederaufnahme.

        Gemerkt wird nur, was sich ueber pychromecast auch wieder herstellen
        laesst: die Adresse des Mediums, sein Typ, die Abspielposition und
        die Lautstaerke. Bei einer App wie Spotify oder YouTube gibt es
        keine Adresse, die man zurueckspielen koennte - dann wird
        ausdruecklich NICHTS gemerkt und hinterher auch nichts behauptet.
        """
        self.vorher = None
        try:
            st = self.cast.media_controller.status
            cs = self.cast.status
        except Exception:  # noqa: BLE001
            return
        lautstaerke = cs.volume_level if cs else None
        if st is None or not st.content_id or st.player_state not in ("PLAYING", "PAUSED"):
            # Nichts Wiederherstellbares - aber die Lautstaerke schon.
            self.vorher = {"art": "nur_lautstaerke", "lautstaerke": lautstaerke}
            return
        self.vorher = {
            "art": "medium",
            "url": st.content_id,
            "typ": st.content_type or "audio/mp3",
            "position": float(st.adjusted_current_time or 0.0),
            "lief": st.player_state == "PLAYING",
            "lautstaerke": lautstaerke,
        }

    def _lage_herstellen(self):
        """Nach der Ansage wieder aufnehmen, was vorher lief."""
        lage = self.vorher
        self.vorher = None
        if not lage:
            return
        try:
            if lage.get("lautstaerke") is not None:
                lautstaerke_setzen(self.cast, lage["lautstaerke"])
            if lage["art"] != "medium":
                return
            mc = self.cast.media_controller
            mc.play_media(lage["url"], lage["typ"], current_time=lage["position"],
                          autoplay=lage["lief"])
            mc.block_until_active(timeout=10)
            log.info("'%s': vorheriges Medium bei %.0f s wieder aufgenommen",
                     self.name, lage["position"])
        except Exception as fehler:  # noqa: BLE001
            log.warning("'%s': Wiederaufnahme misslungen: %s", self.name, fehler)

    def ansage(self, text, cfg):
        """Text ansagen. Rueckgabe: Meldung fuer das Protokoll."""
        text = " ".join(str(text or "").split())
        if text == "":
            return "Ansage ohne Text - nichts zu sagen"
        adressen, modus = tts_adressen(text, cfg)
        if modus == "audioserver":
            return ("Modus 'audioserver': der originale Loxone Audioserver hat keine "
                    "HTTP-Schnittstelle fuer Ansagen. Die Ansage baut man in Loxone "
                    "Config ueber den Textgenerator am TTS-Eingang.")
        if not adressen:
            return ("Fuer den Modus '{0}' fehlt die Adresse des Sprachdienstes "
                    "(Reiter Einstellungen).".format(modus))
        if modus != "chromecast":
            # In allen anderen Modi spricht ein fremdes Geraet - der
            # Chromecast ist dann gar nicht beteiligt. Die Adresse wird
            # aufgerufen, mehr nicht.
            import urllib.request
            for adresse in adressen:
                try:
                    with urllib.request.urlopen(adresse, timeout=10):
                        pass
                except Exception as fehler:  # noqa: BLE001
                    return "Sprachdienst nicht erreichbar: {0}".format(fehler)
            return "OK"

        if not self.verbunden() and not self.verbinden():
            return "Geraet nicht erreichbar"

        fortsetzen = str(cfg.get("tts_fortsetzen", "1")).strip() == "1"
        ansagepegel = cfg.get("tts_pegel")
        self.ansage_laeuft = True
        try:
            if fortsetzen:
                self._lage_merken()
            if str(ansagepegel or "").strip() != "":
                stufe = max(0, min(100, zahl_oder(ansagepegel, 40)))
                lautstaerke_setzen(self.cast, stufe / 100.0)
            mc = self.cast.media_controller
            for nummer, adresse in enumerate(adressen, start=1):
                mc.play_media(adresse, "audio/mp3")
                mc.block_until_active(timeout=10)
                # Warten, bis dieses Stueck durch ist. Ohne das ueberschriebe
                # das naechste play_media() die laufende Ansage nach
                # Sekundenbruchteilen, und man hoerte nur den letzten Satz.
                self._auf_ende_warten(mc)
                if len(adressen) > 1:
                    log.info("'%s': Ansageteil %d von %d gesprochen",
                             self.name, nummer, len(adressen))
            if fortsetzen:
                self._lage_herstellen()
            return "OK"
        except Exception as fehler:  # noqa: BLE001
            log.error("Ansage an '%s' fehlgeschlagen: %s", self.name, fehler)
            return "Fehler: {0}".format(fehler)
        finally:
            self.ansage_laeuft = False

    def _auf_ende_warten(self, mc, hoechstens=60.0):
        """Warten, bis das laufende Stueck zu Ende ist.

        Die Obergrenze ist eine Notbremse: haenge der Lautsprecher in
        BUFFERING fest, wartete der Dienst sonst ewig und meldete in dieser
        Zeit keinen Zustand mehr.
        """
        ende = time.time() + hoechstens
        # Kurz Anlauf geben - unmittelbar nach play_media steht der Zustand
        # noch auf IDLE, und die Schleife waere sofort fertig.
        time.sleep(1.0)
        while time.time() < ende:
            try:
                zustand = mc.status.player_state if mc.status else "IDLE"
            except Exception:  # noqa: BLE001
                return
            if zustand not in ("PLAYING", "BUFFERING"):
                return
            time.sleep(0.5)
        log.warning("'%s': Ansage laeuft nach %.0f s noch - es wird weitergemacht",
                    self.name, hoechstens)

    def befehl(self, befehl, wert, schrittweite, cfg=None):
        """Einen Befehl ausfuehren. Rueckgabe: Meldung fuer das Protokoll."""
        if not self.verbunden() and not self.verbinden():
            return "Geraet nicht erreichbar"

        befehl = befehl.strip().lower()
        mc = self.cast.media_controller

        try:
            if befehl in ("play", "start"):
                # Nur eine echte Adresse startet ein neues Medium. Ein
                # virtueller Ausgang aus Loxone schickt als Nutzlast eine 1;
                # das darf nicht als URL durchgehen, sonst laeuft jeder
                # Play-Klick in einen Fehler statt fortzusetzen.
                if wert and "://" in wert:
                    typ = "audio/mp3"
                    if re.search(r"\.(mp4|mkv|avi|mov|webm)(\?|$)", wert, re.I):
                        typ = "video/mp4"
                    mc.play_media(wert, typ)
                    mc.block_until_active(timeout=10)
                else:
                    if wert and wert not in ("1", "0", "true", "on", "ein"):
                        log.warning("play: '%s' sieht nicht wie eine Adresse aus "
                                    "- es wird stattdessen fortgesetzt", wert)
                    mc.play()
            elif befehl == "pause":
                mc.pause()
            elif befehl == "stop":
                mc.stop()
            elif befehl == "quit":
                self.cast.quit_app()
            elif befehl in ("volume", "set_volume"):
                jetzt_proz = int(round((self.cast.status.volume_level or 0) * 100)) \
                    if self.cast.status else 0
                pegel = max(0, min(100, zahl_oder(wert, jetzt_proz)))
                lautstaerke_setzen(self.cast, pegel / 100.0)
            elif befehl in ("volume_step", "adjust_volume"):
                delta = zahl_oder(wert, schrittweite)
                jetzt = self.cast.status.volume_level if self.cast.status else 0
                pegel = max(0, min(100, int(round(jetzt * 100)) + delta))
                lautstaerke_setzen(self.cast, pegel / 100.0)
            elif befehl in ("volume_up", "lauter"):
                delta = zahl_oder(wert, schrittweite)
                jetzt = self.cast.status.volume_level if self.cast.status else 0
                lautstaerke_setzen(self.cast, min(1.0, jetzt + delta / 100.0))
            elif befehl in ("volume_down", "leiser"):
                delta = zahl_oder(wert, schrittweite)
                jetzt = self.cast.status.volume_level if self.cast.status else 0
                lautstaerke_setzen(self.cast, max(0.0, jetzt - delta / 100.0))
            elif befehl == "mute":
                stumm_setzen(self.cast, str(wert).strip() not in ("0", "", "false", "aus"))
            elif befehl == "next":
                mc.queue_next()
            elif befehl in ("prev", "previous"):
                mc.queue_prev()
            elif befehl == "seek":
                mc.seek(max(0, zahl_oder(wert, 0)))
            elif befehl in ("tts", "say", "ansage"):
                return self.ansage(wert, cfg or {})
            else:
                return "Unbekannter Befehl '{0}'".format(befehl)
        except Exception as fehler:  # noqa: BLE001
            log.error("Befehl %s an '%s' fehlgeschlagen: %s", befehl, self.name, fehler)
            self.trennen()
            return "Fehler: {0}".format(fehler)

        # Nach jedem Befehl den Zustand nachreichen, damit Loxone nicht
        # bis zum naechsten Intervall auf die Rueckmeldung wartet.
        time.sleep(0.4)
        self.melden()
        return "OK"


# ---------------------------------------------------------------------------
# UDP-Rueckfallweg
# ---------------------------------------------------------------------------

class UdpEmpfaenger(threading.Thread):
    """Nimmt Befehle als UDP-Text entgegen - der Weg, den die Fassung von
    Ales Berka benutzt hat. Bleibt erhalten, damit bestehende
    Loxone-Konfigurationen weiterlaufen.

    Syntax:  [<Geraet>/]<BEFEHL> [<Wert>];[...]
    Ohne Geraetenamen gilt der Befehl fuer das erste konfigurierte Geraet.
    """

    def __init__(self, port, dienst):
        super().__init__(daemon=True)
        self.port = port
        self.dienst = dienst
        self.laeuft = True
        self.sock = None

    def run(self):
        try:
            self.sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
            self.sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
            self.sock.bind(("0.0.0.0", self.port))
            self.sock.settimeout(1.0)
            log.info("UDP-Befehle werden auf Port %s entgegengenommen", self.port)
        except OSError as fehler:
            log.error("UDP-Port %s nicht belegbar: %s", self.port, fehler)
            return

        while self.laeuft:
            try:
                daten, absender = self.sock.recvfrom(2048)
            except socket.timeout:
                continue
            except OSError:
                break

            text = daten.decode("utf-8", "replace").strip()
            log.info("UDP von %s: %s", absender[0], text)
            for teil in text.split(";"):
                teil = teil.strip()
                if not teil:
                    continue
                geraet = None
                if "/" in teil.split(" ")[0]:
                    geraet, teil = teil.split("/", 1)
                stueck = teil.split(" ", 1)
                befehl = stueck[0].strip()
                wert = stueck[1].strip() if len(stueck) > 1 else ""
                self.dienst.befehl_ausfuehren(geraet, befehl, wert)

    def stop(self):
        self.laeuft = False
        try:
            if self.sock:
                self.sock.close()
        except Exception:  # noqa: BLE001
            pass


# ---------------------------------------------------------------------------
# Dienst
# ---------------------------------------------------------------------------

class Dienst:
    def __init__(self):
        self.cfg = konfiguration_lesen()
        self.praefix = self.cfg.get("themenpraefix", "chromecast4lox") or "chromecast4lox"
        self.geraete = {}
        self.mqtt = MqttAnbindung(self.praefix, self.befehl_ausfuehren)
        self.udp = None
        self.laeuft = True
        self.config_mtime = self._mtime()

    def _mtime(self):
        try:
            return os.path.getmtime(CONFIG_FILE)
        except OSError:
            return 0

    def _zahl(self, schluessel, vorgabe):
        try:
            return int(float(self.cfg.get(schluessel, vorgabe)))
        except (TypeError, ValueError):
            return int(vorgabe)

    def geraete_aufbauen(self):
        namen = geraeteliste(self.cfg)
        if not namen:
            log.warning("Kein Chromecast konfiguriert - "
                        "im Reiter Einstellungen mindestens einen eintragen.")
        for alt in list(self.geraete):
            if alt not in namen:
                self.geraete[alt].trennen()
                del self.geraete[alt]
        for name in namen:
            if name not in self.geraete:
                self.geraete[name] = Geraet(name, self.mqtt)

    def geraet_finden(self, kennung):
        """Geraet nach Name oder nach gesaeubertem Thema suchen."""
        if not kennung:
            return next(iter(self.geraete.values()), None)
        for geraet in self.geraete.values():
            if kennung in (geraet.name, geraet.thema):
                return geraet
        for geraet in self.geraete.values():
            if kennung.lower() in (geraet.name.lower(), geraet.thema.lower()):
                return geraet
        return None

    def befehl_ausfuehren(self, kennung, befehl, wert):
        geraet = self.geraet_finden(kennung)
        if not geraet:
            log.warning("Kein Geraet fuer '%s' - konfiguriert sind: %s",
                        kennung, ", ".join(self.geraete) or "keine")
            return
        ergebnis = geraet.befehl(befehl, wert,
                                 self._zahl("lautstaerke_schritt", 5), self.cfg)
        if ergebnis != "OK":
            log.warning("%s: %s", geraet.name, ergebnis)

    def start(self):
        log.info("Chromecast 4 Lox NG %s startet", VERSION)
        log.info("Konfiguration: %s", CONFIG_FILE)

        if self.cfg.get("mqtt", "1") == "1":
            self.mqtt.start()
        else:
            log.info("MQTT ist ausgeschaltet")

        self.geraete_aufbauen()

        if self.cfg.get("udp", "1") == "1":
            self.udp = UdpEmpfaenger(self._zahl("udp_port", 7090), self)
            self.udp.start()

        intervall = max(2, self._zahl("intervall", 10))
        vollmeldung_alle = max(intervall, self._zahl("aktualisierung", 60))
        letzte_vollmeldung = 0

        while self.laeuft:
            # Nach einer Neuverbindung zum Broker sind dessen retained-Werte
            # womoeglich weg. Dann muss ALLES neu gesendet werden - sonst
            # bleiben die Themen leer, bis sich zufaellig etwas aendert.
            neu_verbunden = self.mqtt.neumeldung_faellig
            if neu_verbunden:
                self.mqtt.neumeldung_faellig = False
                for geraet in self.geraete.values():
                    geraet.letzter_stand.clear()
                log.info("MQTT neu verbunden - alle Zustaende werden erneut gemeldet")

            for geraet in list(self.geraete.values()):
                if geraet.ansage_laeuft:
                    # Waehrend einer Ansage nicht dazwischenfunken: der
                    # Zustand waere ohnehin nur der der Ansage, und ein
                    # Verbindungsaufbau mitten hinein wuerde sie abbrechen.
                    continue
                if not geraet.verbunden():
                    geraet.verbinden()
                erzwingen = neu_verbunden or (time.time() - letzte_vollmeldung) >= vollmeldung_alle
                geraet.melden(erzwingen=erzwingen)
            if (time.time() - letzte_vollmeldung) >= vollmeldung_alle:
                letzte_vollmeldung = time.time()

            # Konfigurationsaenderung uebernehmen, ohne Neustart
            if self._mtime() != self.config_mtime:
                log.info("Konfiguration geaendert - wird neu eingelesen")
                self.config_mtime = self._mtime()
                self.cfg = konfiguration_lesen()
                self.geraete_aufbauen()
                intervall = max(2, self._zahl("intervall", 10))
                vollmeldung_alle = max(intervall, self._zahl("aktualisierung", 60))

            time.sleep(intervall)

    def stop(self):
        self.laeuft = False
        if self.udp:
            self.udp.stop()
        for geraet in self.geraete.values():
            geraet.melden_offline()
            geraet.trennen()
        self.mqtt.stop()


def main():
    dienst = Dienst()
    try:
        dienst.start()
    except KeyboardInterrupt:
        log.info("Abbruch durch Signal")
    finally:
        dienst.stop()
        log.info("Beendet")


if __name__ == "__main__":
    main()
