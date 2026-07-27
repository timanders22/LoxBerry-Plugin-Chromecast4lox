#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Chromecast 4 Lox - Serverdienst

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
from configparser import ConfigParser

VERSION = "1.0.0"

# ---------------------------------------------------------------------------
# Pfade - LoxBerry ersetzt die REPLACE-Marken bei der Installation
# ---------------------------------------------------------------------------

PLUGIN_NAME = "REPLACELBPPLUGINDIR"
if PLUGIN_NAME.startswith("REPLACE"):
    PLUGIN_NAME = "chromecast-4lox"

CONFIG_DIR = "REPLACELBPCONFIGDIR"
if CONFIG_DIR.startswith("REPLACE"):
    CONFIG_DIR = "/opt/loxberry/config/plugins/" + PLUGIN_NAME

LOG_DIR = "REPLACELBPLOGDIR"
if LOG_DIR.startswith("REPLACE"):
    LOG_DIR = "/opt/loxberry/log/plugins/" + PLUGIN_NAME

HOME_DIR = os.environ.get("LBHOMEDIR", "/opt/loxberry")
CONFIG_FILE = os.path.join(CONFIG_DIR, PLUGIN_NAME + ".cfg")

# ---------------------------------------------------------------------------
# Protokoll
# ---------------------------------------------------------------------------

_handlers = []
try:
    os.makedirs(LOG_DIR, exist_ok=True)
    _handlers.append(logging.FileHandler(os.path.join(LOG_DIR, PLUGIN_NAME + ".log")))
except OSError:
    pass
_handlers.append(logging.StreamHandler(sys.stdout))

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)-7s %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
    handlers=_handlers,
)
log = logging.getLogger("chromecast4lox")


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

        try:
            self.client.connect(zugang["host"], zugang["port"], keepalive=60)
        except OSError as fehler:
            log.error("MQTT-Broker %s:%s nicht erreichbar: %s",
                      zugang["host"], zugang["port"], fehler)
            return False

        self.client.loop_start()
        log.info("MQTT verbunden mit %s:%s, Themenpraefix %s",
                 zugang["host"], zugang["port"], self.praefix)
        return True

    def _on_connect(self, client, userdata, flags, rc, properties=None):
        if rc != 0:
            log.error("MQTT-Anmeldung abgelehnt, Code %s", rc)
            return
        self.verbunden = True
        thema = self.praefix + "/+/cmd/#"
        client.subscribe(thema)
        log.info("MQTT-Befehle abonniert: %s", thema)
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
            return
        try:
            self.client.publish(self.praefix + "/" + unterthema,
                                str(wert), qos=0, retain=True)
        except Exception as fehler:  # noqa: BLE001
            log.error("MQTT-Veroeffentlichung fehlgeschlagen: %s", fehler)

    def stop(self):
        if not self.client:
            return
        try:
            self.senden("server/online", "0")
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

    # -- Verbindung ---------------------------------------------------------

    def verbinden(self):
        """Geraet suchen und verbinden. Rueckgabe: True bei Erfolg."""
        try:
            import pychromecast
        except ImportError:
            log.error("pychromecast fehlt - Paket python3-pychromecast "
                      "nachinstallieren.")
            return False

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

        log.info("Verbunden mit '%s' (%s)", self.name,
                 getattr(self.cast, "model_name", "?"))
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

    def befehl(self, befehl, wert, schrittweite):
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
                pegel = max(0, min(100, int(float(wert))))
                lautstaerke_setzen(self.cast, pegel / 100.0)
            elif befehl in ("volume_step", "adjust_volume"):
                delta = int(float(wert)) if wert else schrittweite
                jetzt = self.cast.status.volume_level if self.cast.status else 0
                pegel = max(0, min(100, int(round(jetzt * 100)) + delta))
                lautstaerke_setzen(self.cast, pegel / 100.0)
            elif befehl in ("volume_up", "lauter"):
                delta = int(float(wert)) if wert else schrittweite
                jetzt = self.cast.status.volume_level if self.cast.status else 0
                lautstaerke_setzen(self.cast, min(1.0, jetzt + delta / 100.0))
            elif befehl in ("volume_down", "leiser"):
                delta = int(float(wert)) if wert else schrittweite
                jetzt = self.cast.status.volume_level if self.cast.status else 0
                lautstaerke_setzen(self.cast, max(0.0, jetzt - delta / 100.0))
            elif befehl == "mute":
                stumm_setzen(self.cast, str(wert).strip() not in ("0", "", "false", "aus"))
            elif befehl == "next":
                mc.queue_next()
            elif befehl in ("prev", "previous"):
                mc.queue_prev()
            elif befehl == "seek":
                mc.seek(int(float(wert)))
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
        ergebnis = geraet.befehl(befehl, wert, self._zahl("lautstaerke_schritt", 5))
        if ergebnis != "OK":
            log.warning("%s: %s", geraet.name, ergebnis)

    def start(self):
        log.info("Chromecast 4 Lox %s startet", VERSION)
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
            for geraet in list(self.geraete.values()):
                if not geraet.verbunden():
                    geraet.verbinden()
                erzwingen = (time.time() - letzte_vollmeldung) >= vollmeldung_alle
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
