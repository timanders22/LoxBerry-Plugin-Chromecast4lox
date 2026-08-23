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

import hashlib
import json
import logging
import os
import queue
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

# Das Datenverzeichnis liegt NICHT auf der Ramdisk - anders als log/.
# Dorthin schreibt der Dienst sein Lebenszeichen, und das soll einen
# Neustart des Rechners ueberstehen.
DATA_DIR = "REPLACELBPDATADIR"
if DATA_DIR.startswith("REPLACE"):
    DATA_DIR = lb_wurzel_ermitteln() + "/data/plugins/" + PLUGIN_NAME

# Der unangemeldete html-Ordner. Dorthin legt die oertliche Ansage ihre
# Datei - der Chromecast holt sie ueber HTTP ab und bringt dafuer keine
# Zugangsdaten mit.
HTML_DIR = "REPLACELBPHTMLDIR"
if HTML_DIR.startswith("REPLACE"):
    HTML_DIR = (lb_wurzel_ermitteln()
                + "/webfrontend/html/plugins/" + PLUGIN_NAME)

HOME_DIR = os.environ.get("LBHOMEDIR") or lb_wurzel_ermitteln()
CONFIG_FILE = os.path.join(CONFIG_DIR, PLUGIN_NAME + ".cfg")
ZUSTAND_FILE = os.path.join(DATA_DIR, "zustand.json")
THEMEN_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)),
                           "cc_themen.json")


def version():
    """Fassung aus der Plugindatenbank von LoxBerry.

    Bewusst nicht fest eingetragen: bis 1.2.12 stand hier VERSION = "1.2.1",
    und zwar seit mindestens 1.2.10 - jede Startzeile im Protokoll nannte
    also eine Fassung, die es so nicht mehr gab. Eine Nummer im Quelltext
    bleibt beim naechsten Release stehen; massgeblich ist, was LoxBerry bei
    der Installation uebernommen hat.

    Gibt "" zurueck, wenn sich die Fassung nicht ermitteln laesst - dann
    steht im Protokoll keine Nummer, was besser ist als eine falsche.
    """
    datei = os.path.join(HOME_DIR, "data", "system", "plugindatabase.json")
    try:
        with open(datei, "r", encoding="utf-8") as fh:
            inhalt = json.load(fh)
    except (OSError, ValueError):
        return ""
    liste = inhalt.get("plugins", inhalt) if isinstance(inhalt, dict) else inhalt
    if isinstance(liste, dict):
        liste = list(liste.values())
    if not isinstance(liste, list):
        return ""
    for eintrag in liste:
        if not isinstance(eintrag, dict):
            continue
        if eintrag.get("folder") == PLUGIN_NAME \
                or eintrag.get("PLUGINDB_FOLDER") == PLUGIN_NAME:
            return str(eintrag.get("version")
                       or eintrag.get("PLUGINDB_VERSION") or "").strip()
    return ""

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
    "mqtt_topic": "chromecast4lox",
    "mqtt_ein": "1",
    "udp": "1",
    "udp_port": "7090",
    "intervall": "10",
    "aktualisierung": "60",
    "lautstaerke_schritt": "5",
    # Favoriten: je Zeile "Name = Adresse". Ab Werk leer.
    "favoriten": "",
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
    # B10: Klang vor der Ansage. Leer = keiner.
    "tts_gong": "",
    # B7: Grundadresse, unter der der LoxBerry den html-Ordner des
    # Plugins ausliefert. Leer = selbst ermitteln.
    "tts_lokal_basis": "",
    # B9: Obergrenzen. 100 heisst "keine Grenze"; die Ruhezeit ist mit
    # leeren Zeiten ausgeschaltet. AB WERK AUS.
    "lautstaerke_max": "100",
    "ruhe_von": "",
    "ruhe_bis": "",
    "ruhe_max": "30",
    # Gruppen mitsuchen (Google-Lautsprechergruppen).
    "gruppen": "1",
    # Schneller melden: Rueckrufe von pychromecast statt Abfragetakt,
    # und eine dauerhafte Netzsuche statt einer Einzelsuche je Geraet.
    # AB WERK AUS: an echter Hardware noch nicht erprobt. Der bisherige
    # Takt bleibt in beiden Faellen als Rueckfallebene erhalten.
    "beschleunigung": "0",
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


def _themen_lesen():
    """Die eine Themenliste - dieselbe Datei, die auch die Oberflaeche liest.

    Bis 1.2.12 gab es zwei Listen: cc_status_themen() in PHP und die
    _senden()-Aufrufe hier. Sie stimmten ueberein, aber nichts hielt sie
    zusammen. Jetzt entscheidet EINE Datei, was gesendet wird und was
    retained geht; der Reiter Test haelt beide Seiten gegeneinander.
    """
    try:
        with open(THEMEN_FILE, "r", encoding="utf-8") as fh:
            d = json.load(fh)
    except (OSError, ValueError) as fehler:
        log.error("Themenliste %s nicht lesbar (%s) - es wird nichts "
                  "veroeffentlicht, was nicht darin steht.", THEMEN_FILE, fehler)
        return {"geraet": [], "dienst": [], "befehle": []}
    for schluessel in ("geraet", "dienst", "befehle"):
        if not isinstance(d.get(schluessel), list):
            log.error("Themenliste unvollstaendig: %s fehlt", schluessel)
            d[schluessel] = []
    return d


THEMEN = _themen_lesen()


def thema_info(bereich, schluessel):
    """Angaben zu einem Thema, oder None, wenn es nicht in der Liste steht."""
    for e in THEMEN.get(bereich, ()):
        if e.get("schluessel") == schluessel:
            return e
    return None


def thema_retain(bereich, schluessel):
    """Retained oder nicht - je Thema entschieden, nicht pauschal.

    position ist bewusst NICHT retained: ein Abnehmer, der sich eine Stunde
    spaeter verbindet, bekaeme sonst eine stundenalte Laufzeit serviert und
    hielte sie fuer aktuell.
    """
    e = thema_info(bereich, schluessel)
    return True if e is None else bool(e.get("retain", True))


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


# Namen, unter denen ein Befehl ALLE Geraete erreicht. Sie sind bewusst
# keine gueltigen Geraetenamen: ein Chromecast, der wirklich "alle" heisst,
# waere sonst nicht mehr einzeln ansprechbar - deshalb gewinnt ein echter
# Geraetename, und das Sammelziel greift erst danach.
SAMMELZIEL = ("alle", "all", "*")


def favoriten(cfg):
    """Die Favoritenliste aus der Konfiguration.

    Je Zeile "Name = Adresse". Die Reihenfolge ist die Adresse: play_favorit
    bekommt aus Loxone eine ZAHL, und die zaehlt ab 1. Wer eine Zeile
    mittendrin loescht, verschiebt alle folgenden - das steht so im
    Hinweistext, und es ist der Grund, warum die Nummer im Reiter
    Einstellungen neben jedem Eintrag steht.

    Rueckgabe: Liste von (name, adresse). Zeilen ohne Adresse fallen weg -
    lieber ein Eintrag weniger als einer, der ins Leere sendet.
    """
    aus = []
    for zeile in re.split(r"[\r\n]+", str(cfg.get("favoriten", "") or "")):
        zeile = zeile.strip()
        if zeile == "" or zeile.startswith("#"):
            continue
        if "=" not in zeile:
            log.warning("Favorit ohne Adresse uebergangen: %s", zeile[:60])
            continue
        name, adresse = zeile.split("=", 1)
        name, adresse = name.strip(), adresse.strip()
        if adresse == "":
            log.warning("Favorit '%s' hat keine Adresse - uebergangen", name)
            continue
        aus.append((name or adresse, adresse))
    return aus


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

    if modus == "lokal":
        # Die Adresse baut der Aufrufer - er kennt das Geraet und damit die
        # richtige eigene Adresse. Hier gibt es nur das Kennzeichen.
        return ([], modus)

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


def eigene_ip(ziel=None):
    """Die Adresse, unter der dieser Rechner vom Lautsprecher aus erreichbar ist.

    Kein Verkehr: ein UDP-Socket, der nur verbunden wird, verraet ueber
    getsockname(), welche Quelladresse das Betriebssystem fuer dieses Ziel
    waehlen wuerde. Mit dem Lautsprecher als Ziel ist das die richtige
    Adresse auch dann, wenn der Rechner mehrere hat.
    """
    s = None
    try:
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        s.connect((ziel or "8.8.8.8", 9))
        return s.getsockname()[0]
    except OSError:
        return ""
    finally:
        try:
            if s:
                s.close()
        except OSError:
            pass


def ansage_lokal_erzeugen(text, sprache):
    """Den Text mit espeak-ng in eine WAV-Datei schreiben.

    Rueckgabe: (dateiname, fehlertext). Genau eines von beiden ist leer.

    Belegt an der Google-Cast-Dokumentation: WAV (LPCM) gehoert zu den
    unterstuetzten Formaten. Am Geraet nachgemessen ist es nicht - das steht
    so im Bericht.
    """
    import subprocess
    ordner = os.path.join(HTML_DIR, "ansage")
    try:
        os.makedirs(ordner, exist_ok=True)
    except OSError as fehler:
        return "", "Ansageordner nicht anlegbar: {0}".format(fehler)

    # Der Name haengt am Inhalt: derselbe Satz erzeugt dieselbe Datei, und
    # ein zweiter Aufruf spart den Lauf.
    kennung = hashlib.sha256((sprache + "|" + text).encode("utf-8")).hexdigest()[:16]
    name = "cc_" + kennung + ".wav"
    pfad = os.path.join(ordner, name)
    if os.path.isfile(pfad) and os.path.getsize(pfad) > 44:
        return name, ""

    # espeak-ng kennt Sprachen wie "de", "en" - genau die Kuerzel, die auch
    # im Feld Sprache stehen.
    befehl = ["espeak-ng", "-v", sprache or "de", "-w", pfad, "--stdin"]
    try:
        lauf = subprocess.run(befehl, input=text.encode("utf-8"),
                              stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                              timeout=30)
    except FileNotFoundError:
        return "", ("espeak-ng ist nicht eingerichtet. Nachholen mit: "
                    "sudo apt-get install -y espeak-ng")
    except (OSError, subprocess.SubprocessError) as fehler:
        return "", "espeak-ng liess sich nicht aufrufen: {0}".format(fehler)
    # Den RUECKGABEWERT auswerten, nicht nur die Ausgabe: eine leere Ausgabe
    # bei einem Abbruch ist kein Erfolg.
    if lauf.returncode != 0:
        return "", ("espeak-ng endete mit {0}: {1}".format(
            lauf.returncode,
            lauf.stderr.decode("utf-8", "replace").strip()[:160]))
    if not os.path.isfile(pfad) or os.path.getsize(pfad) <= 44:
        return "", "espeak-ng hat keine brauchbare Datei geschrieben"
    _ansagen_aufraeumen(ordner)
    return name, ""


def _ansagen_aufraeumen(ordner, behalten=40):
    """Alte Ansagedateien wegraeumen.

    Wer Dateien anlegt, raeumt sie auch weg - sonst fuellt eine Ansage je
    Ereignis irgendwann die Karte.
    """
    try:
        dateien = [os.path.join(ordner, n) for n in os.listdir(ordner)
                   if n.startswith("cc_") and n.endswith(".wav")]
    except OSError:
        return
    if len(dateien) <= behalten:
        return
    dateien.sort(key=lambda p: os.path.getmtime(p))
    for p in dateien[:len(dateien) - behalten]:
        try:
            os.remove(p)
        except OSError:
            pass


def lautstaerke_grenze(cfg, jetzt=None):
    """Die Obergrenze, die gerade gilt - 0 bis 100.

    Zwei Grenzen: eine allgemeine und eine fuer ein Zeitfenster. Beide sind
    ab Werk wirkungslos (100 beziehungsweise leere Zeiten). Eine neue
    Funktion, die beim ersten Lauf ungefragt eingreift, ist ein Fehler.
    """
    grenze = max(0, min(100, zahl_oder(cfg.get("lautstaerke_max"), 100)))
    von = str(cfg.get("ruhe_von", "") or "").strip()
    bis = str(cfg.get("ruhe_bis", "") or "").strip()
    if von == "" or bis == "":
        return grenze
    def minuten(s):
        m = re.match(r"^(\d{1,2}):(\d{2})$", s)
        if not m:
            return None
        st, mi = int(m.group(1)), int(m.group(2))
        if st > 23 or mi > 59:
            return None
        return st * 60 + mi
    a, b = minuten(von), minuten(bis)
    if a is None or b is None:
        # Abweisen, nicht zurechtbiegen: eine unverstaendliche Zeit darf
        # nicht heimlich zu "immer" oder "nie" werden.
        log.warning("Ruhezeit '%s' bis '%s' ist keine Uhrzeit (HH:MM) - "
                    "die Ruhezeit bleibt aus.", von, bis)
        return grenze
    t = jetzt if jetzt is not None else time.localtime()
    jetzt_min = t.tm_hour * 60 + t.tm_min
    # Ein Fenster darf ueber Mitternacht gehen - 22:00 bis 07:00 ist der
    # Regelfall, nicht die Ausnahme.
    drin = (a <= jetzt_min < b) if a < b else (jetzt_min >= a or jetzt_min < b)
    if drin:
        grenze = min(grenze, max(0, min(100, zahl_oder(cfg.get("ruhe_max"), 30))))
    return grenze


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

    def senden(self, unterthema, wert, retain=True):
        if not self.client:
            return False
        try:
            erg = self.client.publish(self.praefix + "/" + unterthema,
                                      str(wert), qos=0, retain=bool(retain))
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
    """Lautstaerke setzen, ueber beide moeglichen Wege.

    Hier stand bis 1.2.12, set_volume habe "bis pychromecast 12 auf dem
    Chromecast-Objekt gesessen und liege seit 13 nur noch auf dem
    receiver_controller". Das ist nicht so. Nachgemessen am 22.08.2026 an
    den Quelltexten beider Fassungen: 9.4.0 (__init__.py, Zeilen ~356-365)
    und 14.0.10 (Zeilen 289-290) setzen den Namen im Konstruktor als Alias
    auf receiver_controller.set_volume. Der zweite Zweig wurde also in
    keiner der beiden je erreicht.

    Er bleibt trotzdem stehen: gemessen sind zwei Fassungen, nicht alle,
    und die Abfrage kostet nichts. Was falsch war, war die Begruendung.
    """
    if hasattr(cast, "set_volume"):
        cast.set_volume(wert)
    else:
        cast.socket_client.receiver_controller.set_volume(wert)


def stumm_setzen(cast, stumm):
    if hasattr(cast, "set_volume_muted"):
        cast.set_volume_muted(stumm)
    else:
        cast.socket_client.receiver_controller.set_volume_muted(stumm)


class Netzsuche:
    """Dauerhaft lauschen, statt je Geraet einzeln zu suchen.

    Der bisherige Weg ist teuer: verbinden() ruft je Geraet
    get_listed_chromecasts(discovery_timeout=8), und die Hauptschleife tut
    das fuer JEDES nicht verbundene Geraet in JEDEM Durchgang. Bei drei
    abwesenden Geraeten sind das 24 Sekunden in einem Takt, der auf 10
    eingestellt ist - die Schleife hinkt, und die Meldungen der LAUFENDEN
    Geraete kommen mit ihr zu spaet.

    CastBrowser und SimpleCastListener stehen in pychromecast 9.4 wie in
    14.x mit denselben Signaturen; get_chromecast_from_cast_info nimmt in
    beiden (cast_info, zconf) in dieser Stellung.
    """

    def __init__(self):
        self.browser = None
        self.zconf = None
        self.gefunden = {}
        self.laeuft = False

    def start(self):
        try:
            import zeroconf
            from pychromecast.discovery import CastBrowser, SimpleCastListener
        except ImportError as fehler:
            log.warning("Dauersuche nicht moeglich (%s) - es bleibt bei der "
                        "Einzelsuche je Geraet.", fehler)
            return False

        def dazu(uuid, service):
            info = None
            try:
                info = self.browser.devices.get(uuid)
            except Exception:  # noqa: BLE001
                pass
            name = getattr(info, "friendly_name", "") if info else ""
            if name:
                self.gefunden[name] = info

        def weg(uuid, service, cast_info):
            name = getattr(cast_info, "friendly_name", "")
            if name in self.gefunden:
                del self.gefunden[name]
                log.info("'%s' hat sich aus dem Netz abgemeldet", name)

        try:
            self.zconf = zeroconf.Zeroconf()
            self.browser = CastBrowser(SimpleCastListener(dazu, weg, dazu),
                                       self.zconf)
            self.browser.start_discovery()
        except Exception as fehler:  # noqa: BLE001
            log.warning("Dauersuche liess sich nicht starten (%s) - es bleibt "
                        "bei der Einzelsuche je Geraet.", fehler)
            self.browser = None
            return False
        self.laeuft = True
        log.info("Dauersuche laeuft - Geraete werden gemeldet, sobald sie sich "
                 "im Netz zeigen.")
        return True

    def info(self, name):
        return self.gefunden.get(name)

    def stop(self):
        try:
            if self.browser:
                self.browser.stop_discovery()
        except Exception:  # noqa: BLE001
            pass
        try:
            if self.zconf:
                self.zconf.close()
        except Exception:  # noqa: BLE001
            pass
        self.laeuft = False


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
        # Duerfen Lautsprechergruppen benutzt werden? Der Dienst setzt das
        # bei jedem Aufbau der Geraeteliste neu, damit eine Aenderung der
        # Einstellung ohne Neustart ankommt.
        self.gruppen_erlaubt = True
        # Was lief vor einer Ansage? Fuer die Wiederaufnahme.
        self.vorher = None
        self.ansage_laeuft = False
        # Zuletzt gestarteter Favorit und zuletzt aufgetretene Meldung.
        # Beide gehen nach Loxone: dort war bisher nicht erkennbar, ob ein
        # Befehl angekommen ist - die Meldung stand nur im Protokoll.
        self.favorit = "0"
        self.letzte_meldung = ""
        # Wartezeit-Staffelung: eine erfolglose Suche kostet acht Sekunden.
        # Sie in JEDEM Durchgang zu wiederholen laesst die Schleife hinken,
        # und zwar zu Lasten der Geraete, die laufen.
        self.naechster_versuch = 0.0
        self.wartezeit = 0.0
        # Rueckrufe (B2). Ab Werk aus; der Dienst setzt es beim Aufbau.
        self.lauscher_erlaubt = False
        self.lauscher_an = False
        self.letzter_rueckruf = 0.0
        self.netzsuche = None
        # B4: Ein Arbeitsfaden je Geraet. Der MQTT-Rueckruf legt nur ab.
        self.auftraege = queue.Queue()
        self.faden = None
        self.faden_laeuft = False
        # Wird gesetzt, wenn eine laufende Ansage abgebrochen werden soll.
        self.ansage_abbrechen = False
        # melden() kann jetzt aus zwei Faeden kommen - der Hauptschleife und
        # dem Arbeitsfaden. Ein Schloss kostet nichts und spart eine Klasse
        # von Fehlern, die sich hinterher nicht mehr nachstellen laesst.
        self.schloss = threading.Lock()

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
        # Erst in der Dauersuche nachsehen - das kostet nichts. Nur wenn
        # sie nicht laeuft oder das Geraet dort nicht steht, wird die teure
        # Einzelsuche bemueht.
        info = self.netzsuche.info(self.name) if self.netzsuche else None
        if info is not None:
            try:
                self.cast = pychromecast.get_chromecast_from_cast_info(
                    info, self.netzsuche.zconf)
                self.browser = None
            except Exception as fehler:  # noqa: BLE001
                log.warning("'%s' steht in der Dauersuche, liess sich aber "
                            "nicht verbinden (%s) - es wird einzeln gesucht.",
                            self.name, fehler)
                info = None
        if info is None:
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
        if self.ist_gruppe and not self.gruppen_erlaubt:
            # Der Haken "Google-Lautsprechergruppen mitsuchen" steht aus.
            # Abweisen und sagen warum - nicht stillschweigend doch benutzen.
            log.warning("'%s' ist eine Lautsprechergruppe, und Gruppen sind "
                        "in den Einstellungen abgeschaltet - nicht benutzt.",
                        self.name)
            self.trennen()
            self.melden_offline()
            return False
        log.info("Verbunden mit '%s' (%s%s)", self.name,
                 getattr(self.cast, "model_name", "?"),
                 ", Lautsprechergruppe" if self.ist_gruppe else "")
        self._lauscher_anmelden()
        return True

    def _lauscher_anmelden(self):
        """Rueckrufe anmelden - OHNE von den Basisklassen zu erben.

        MediaStatusListener hat ab pychromecast 12 ZWEI abstrakte Methoden
        (dazu load_media_failed), und deren Parametername wechselte in
        14.0.0 noch einmal. Eine Klasse, die davon erbt und nur
        new_media_status umsetzt, laesst sich dort nicht einmal anlegen.
        Die Anmeldung ist reines Duck-Typing - also wird nicht geerbt, und
        die zweite Methode nimmt einfach alles entgegen.
        """
        if not self.lauscher_erlaubt or self.lauscher_an or self.cast is None:
            return
        geraet = self

        class _Lauscher(object):
            def new_cast_status(self, status):
                geraet._rueckruf()

            def new_media_status(self, status):
                geraet._rueckruf()

            def load_media_failed(self, *args, **kwargs):
                geraet._rueckruf()

            def new_connection_status(self, status):
                geraet._rueckruf()

        h = _Lauscher()
        try:
            self.cast.register_status_listener(h)
            self.cast.media_controller.register_status_listener(h)
            self.cast.register_connection_listener(h)
        except Exception as fehler:  # noqa: BLE001
            log.warning("'%s': Rueckrufe liessen sich nicht anmelden (%s) - "
                        "es bleibt beim Abfragetakt.", self.name, fehler)
            return
        self.lauscher_an = True
        log.info("'%s': meldet jetzt ereignisgesteuert", self.name)

    def _rueckruf(self):
        """Ein Zustand hat sich geaendert - sofort melden.

        Zwei Wachen: waehrend einer Ansage wird nicht dazwischengefunkt, und
        oefter als zweimal je Sekunde wird nicht gemeldet. Eine App kann in
        einer Sekunde dutzende Statuswechsel schicken; jeder davon eine
        MQTT-Nachricht waere eine Last, die niemand braucht.
        """
        if self.ansage_laeuft:
            return
        jetzt = time.time()
        if jetzt - self.letzter_rueckruf < 0.5:
            return
        self.letzter_rueckruf = jetzt
        try:
            self.melden()
        except Exception as fehler:  # noqa: BLE001
            log.warning("'%s': Meldung aus dem Rueckruf misslungen: %s",
                        self.name, fehler)

    def faden_starten(self):
        """Den Arbeitsfaden anwerfen, falls er nicht schon laeuft."""
        if self.faden_laeuft:
            return
        self.faden_laeuft = True
        self.faden = threading.Thread(target=self._arbeiten, daemon=True,
                                      name="cc-" + self.thema)
        self.faden.start()

    def _arbeiten(self):
        """Auftraege nacheinander abarbeiten.

        Ein Faden je Geraet, nicht einer fuer alle: sonst blockierte eine
        Ansage im Wohnzimmer den Pausenbefehl in der Kueche. Und
        NACHEINANDER, nicht gleichzeitig: zwei play_media() auf demselben
        Lautsprecher ueberschrieben einander.
        """
        while self.faden_laeuft:
            try:
                auftrag = self.auftraege.get(timeout=1.0)
            except queue.Empty:
                continue
            if auftrag is None:
                break
            befehl, wert, schrittweite, cfg = auftrag
            try:
                ergebnis = self.befehl(befehl, wert, schrittweite, cfg)
            except Exception as fehler:  # noqa: BLE001
                ergebnis = "Fehler: {0}".format(fehler)
                log.error("'%s': Auftrag %s abgebrochen: %s",
                          self.name, befehl, fehler)
            if ergebnis != "OK":
                log.warning("%s: %s", self.name, ergebnis)
            self.meldung_setzen("" if ergebnis == "OK" else ergebnis)
            self.auftraege.task_done()

    def einreihen(self, befehl, wert, schrittweite, cfg):
        """Einen Auftrag ablegen und SOFORT zurueckkehren.

        Genau darum geht es: der Aufrufer ist der Netzfaden von paho. Bleibt
        er in einer Ansage stehen, geht kein Lebenszeichen mehr an den
        Broker, und der trennt.
        """
        befehl_klein = (befehl or "").strip().lower()
        if befehl_klein in ("tts_stop", "ansage_stop"):
            # Der Abbruch geht NICHT in die Warteschlange - er soll ja
            # gerade das ueberholen, was darin steht.
            self.ansage_abbrechen = True
            geleert = 0
            while True:
                try:
                    self.auftraege.get_nowait()
                    self.auftraege.task_done()
                    geleert += 1
                except queue.Empty:
                    break
            log.info("'%s': Ansage abgebrochen, %d wartende Auftraege verworfen",
                     self.name, geleert)
            return
        self.faden_starten()
        self.auftraege.put((befehl, wert, schrittweite, cfg))

    def meldung_setzen(self, text):
        """Die letzte Meldung nach Loxone geben.

        Bis 1.2.12 stand sie nur im Protokoll - in Loxone war nicht
        erkennbar, ob ein Befehl angekommen ist.
        """
        text = " ".join(str(text or "").split())[:200]
        if text == self.letzte_meldung:
            return
        self.letzte_meldung = text
        self._senden("last_error", text)

    def _geraete_adresse(self):
        """Die IP des Lautsprechers, soweit bekannt - sonst leer."""
        info = getattr(self.cast, "cast_info", None)
        host = getattr(info, "host", "") if info else ""
        if host:
            return host
        uri = str(getattr(self.cast, "uri", "") or "")
        return uri.split(":")[0] if ":" in uri else ""

    def _gedeckelt(self, pegel, cfg):
        """Die Obergrenze anwenden und es sagen, wenn sie greift."""
        grenze = lautstaerke_grenze(cfg or {})
        if pegel <= grenze:
            return pegel
        log.info("'%s': Lautstaerke %d auf die Grenze %d gesenkt",
                 self.name, pegel, grenze)
        self.meldung_setzen("Lautstaerke auf {0} begrenzt".format(grenze))
        return grenze

    def darf_suchen(self, jetzt=None):
        """Ist die Wartezeit bis zum naechsten Suchversuch abgelaufen?"""
        return (jetzt or time.time()) >= self.naechster_versuch

    def suche_vermerken(self, erfolg, grenze):
        """Nach einem Suchversuch die Wartezeit fortschreiben.

        Erfolg setzt sie zurueck. Ein Fehlschlag verdoppelt sie, bis zur
        Grenze - sonst kostet ein abwesendes Geraet in jedem Durchgang acht
        Sekunden, und die Geraete, die LAUFEN, melden dadurch zu spaet.
        """
        if erfolg:
            self.wartezeit = 0.0
            self.naechster_versuch = 0.0
            return
        self.wartezeit = min(grenze, max(2.0, self.wartezeit * 2))
        self.naechster_versuch = time.time() + self.wartezeit

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
        # Die Rueckrufe hingen am alten Cast-Objekt und sind mit ihm fort.
        self.lauscher_an = False

    def verbunden(self):
        return self.cast is not None and getattr(self.cast, "socket_client", None) is not None

    # -- Zustand melden -----------------------------------------------------

    def _senden(self, schluessel, wert, erzwingen=False):
        """Nur senden, wenn sich der Wert geaendert hat. Sonst laeuft der
        Broker bei kurzem Intervall unnoetig voll.

        Ob retained gesendet wird, entscheidet die Themenliste je Thema -
        nicht dieser Code und schon gar nicht pauschal.
        """
        wert = "" if wert is None else str(wert)
        if not erzwingen and self.letzter_stand.get(schluessel) == wert:
            return
        self.letzter_stand[schluessel] = wert
        self.mqtt.senden(self.thema + "/" + schluessel, wert,
                         thema_retain("geraet", schluessel))

    def melden_offline(self):
        self._senden("online", "0")
        self._senden("state", "OFFLINE")
        self._senden("playing", "0")

    def melden(self, erzwingen=False):
        """Aktuellen Zustand einsammeln und veroeffentlichen.

        Kann aus der Hauptschleife UND aus einem Rueckruf kommen - deshalb
        unter einem Schloss.
        """
        with self.schloss:
            self._melden(erzwingen)

    def _melden(self, erzwingen=False):
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

        # Diese drei stehen in der Themenliste und bekommen ihren Ruhewert,
        # damit im Broker kein Thema leer bleibt. Belegt werden sie von der
        # Ansage und vom Favoritenbefehl.
        self._senden("tts_active", "1" if self.ansage_laeuft else "0", erzwingen)
        self._senden("favorit", self.favorit, erzwingen)
        self._senden("last_error", self.letzte_meldung, erzwingen)

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
        if modus == "lokal":
            if not self.verbunden() and not self.verbinden():
                return "Geraet nicht erreichbar"
            name, fehlertext = ansage_lokal_erzeugen(
                text, (cfg.get("tts_sprache") or "de").strip() or "de")
            if fehlertext:
                return fehlertext
            basis = str(cfg.get("tts_lokal_basis", "") or "").strip().rstrip("/")
            if basis == "":
                ip = eigene_ip(self._geraete_adresse())
                if ip == "":
                    return ("Die eigene Adresse liess sich nicht ermitteln - "
                            "im Feld Grundadresse eintragen.")
                basis = "http://{0}/plugins/{1}".format(ip, PLUGIN_NAME)
            adressen = [basis + "/ansage/" + name]
            modus = "chromecast"   # ab hier derselbe Weg
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
        self.ansage_abbrechen = False
        self.ansage_laeuft = True
        # Loxone soll wissen, dass gerade gesprochen wird - wer in dieser
        # Zeit Befehle schickt, unterbricht die Ansage.
        self._senden("tts_active", "1")
        try:
            if fortsetzen:
                self._lage_merken()
            if str(ansagepegel or "").strip() != "":
                stufe = max(0, min(100, zahl_oder(ansagepegel, 40)))
                grenze = lautstaerke_grenze(cfg)
                if stufe > grenze:
                    log.info("'%s': Ansagelautstaerke %d auf die Grenze %d "
                             "gesenkt", self.name, stufe, grenze)
                    stufe = grenze
                lautstaerke_setzen(self.cast, stufe / 100.0)
            gong = str(cfg.get("tts_gong", "") or "").strip()
            if gong != "":
                # Ein Klang vor der Ansage. Wer ohne Vorwarnung angesprochen
                # wird, ueberhoert es oder erschrickt.
                try:
                    self.cast.media_controller.play_media(gong, "audio/mp3")
                    self.cast.media_controller.block_until_active(timeout=10)
                    self._auf_ende_warten(self.cast.media_controller, 15.0)
                except Exception as fehler:  # noqa: BLE001
                    log.warning("'%s': Gong misslungen: %s", self.name, fehler)
            mc = self.cast.media_controller
            for nummer, adresse in enumerate(adressen, start=1):
                if self.ansage_abbrechen:
                    log.info("'%s': Ansage nach Teil %d abgebrochen",
                             self.name, nummer - 1)
                    try:
                        mc.stop()
                    except Exception:  # noqa: BLE001
                        pass
                    return "Abgebrochen"
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
            self.ansage_abbrechen = False
            self._senden("tts_active", "0")

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
            if self.ansage_abbrechen:
                try:
                    mc.stop()
                except Exception:  # noqa: BLE001
                    pass
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
                lautstaerke_setzen(self.cast, self._gedeckelt(pegel, cfg) / 100.0)
            elif befehl in ("volume_step", "adjust_volume"):
                delta = zahl_oder(wert, schrittweite)
                jetzt = self.cast.status.volume_level if self.cast.status else 0
                pegel = max(0, min(100, int(round(jetzt * 100)) + delta))
                lautstaerke_setzen(self.cast, self._gedeckelt(pegel, cfg) / 100.0)
            elif befehl in ("volume_up", "lauter"):
                delta = zahl_oder(wert, schrittweite)
                jetzt = self.cast.status.volume_level if self.cast.status else 0
                pegel = int(round(min(1.0, jetzt + delta / 100.0) * 100))
                lautstaerke_setzen(self.cast, self._gedeckelt(pegel, cfg) / 100.0)
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
            elif befehl in ("play_favorit", "favorit"):
                liste = favoriten(cfg or {})
                nummer = zahl_oder(wert, 0)
                if nummer <= 0:
                    # 0 tut nichts. Ein virtueller Ausgang in Loxone steht
                    # nach dem Neustart des Miniservers auf 0, und das darf
                    # nicht das erste Lied starten.
                    return "OK"
                if nummer > len(liste):
                    return ("Favorit {0} gibt es nicht - eingetragen sind {1}"
                            .format(nummer, len(liste)))
                name, adresse = liste[nummer - 1]
                typ = "audio/mp3"
                if re.search(r"\.(mp4|mkv|avi|mov|webm)(\?|$)", adresse, re.I):
                    typ = "video/mp4"
                log.info("'%s': Favorit %d (%s) wird gestartet",
                         self.name, nummer, name)
                mc.play_media(adresse, typ)
                mc.block_until_active(timeout=10)
                self.favorit = str(nummer)
                self._senden("favorit", self.favorit)
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
        self.praefix = self.cfg.get("mqtt_topic", "chromecast4lox") or "chromecast4lox"
        self.geraete = {}
        self.mqtt = MqttAnbindung(self.praefix, self.befehl_ausfuehren)
        self.udp = None
        self.laeuft = True
        self.config_mtime = self._mtime()
        # Umlaufender Taktzaehler. Bleibt er stehen, arbeitet der Dienst
        # nicht mehr - das erkennt Loxone mit einer Aenderungsueberwachung,
        # und der Reiter Test an der Zustandsdatei.
        self.zaehler = 0
        self.netzsuche = Netzsuche()

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

    def herzschlag(self, erreichbar):
        """Lebenszeichen - per MQTT UND in eine Datei.

        Wer nur bei Aenderungen sendet, hoert bei einer Stoerung einfach auf.
        Die zuletzt gesendeten Werte bleiben im Broker stehen, und in Loxone
        sieht ein toter Dienst genauso aus wie ein ruhiges Haus.

        Diese Themen gehen deshalb bei JEDEM Durchgang hinaus, am
        Doppelt-senden-Filter vorbei - sonst waere der Zeitstempel selbst der
        aelteste Wert im Broker.
        """
        self.zaehler = (self.zaehler + 1) % 1000
        jetzt = int(time.time())
        werte = {
            "ts": jetzt,
            "zaehler": self.zaehler,
            "geraete": erreichbar,
            "verluste": self.mqtt.verluste,
            "fassung": version(),
        }
        for schluessel, wert in werte.items():
            self.mqtt.senden("server/" + schluessel, wert,
                             thema_retain("dienst", schluessel))
        self.zustand_schreiben(jetzt, erreichbar)

    def zustand_schreiben(self, jetzt, erreichbar):
        """Denselben Stand in eine Datei unter data/.

        Unabhaengig von MQTT: daran erkennt der Reiter Test, ob der Dienst
        noch arbeitet. Eine Prozessnummer beantwortet das nicht - ein Prozess
        kann dastehen und nichts mehr tun.

        Nach data/, nicht nach log/: log/plugins liegt auf einer Ramdisk.
        """
        try:
            os.makedirs(DATA_DIR, exist_ok=True)
            vorlaeufig = ZUSTAND_FILE + ".neu"
            with open(vorlaeufig, "w", encoding="utf-8") as fh:
                json.dump({
                    "zeit": jetzt,
                    "zaehler": self.zaehler,
                    "geraete_erreichbar": erreichbar,
                    "geraete_konfiguriert": len(self.geraete),
                    "verluste": self.mqtt.verluste,
                    "mqtt_verbunden": bool(self.mqtt.verbunden),
                    "fassung": version(),
                }, fh)
            # Erst vollstaendig schreiben, dann umbenennen: sonst liest die
            # Oberflaeche irgendwann eine halbe Datei und meldet einen
            # Defekt, den es nicht gibt.
            os.replace(vorlaeufig, ZUSTAND_FILE)
        except OSError as fehler:
            log.warning("Zustandsdatei %s nicht schreibbar: %s",
                        ZUSTAND_FILE, fehler)

    def geraete_aufbauen(self):
        namen = geraeteliste(self.cfg)
        if not namen:
            log.warning("Kein Chromecast konfiguriert - "
                        "im Reiter Einstellungen mindestens einen eintragen.")
        for alt in list(self.geraete):
            if alt not in namen:
                self.geraete[alt].trennen()
                del self.geraete[alt]
        erlaubt = str(self.cfg.get("gruppen", "1")).strip() != "0"
        schnell = str(self.cfg.get("beschleunigung", "0")).strip() == "1"
        for name in namen:
            if name not in self.geraete:
                self.geraete[name] = Geraet(name, self.mqtt)
            # Bei jedem Aufbau neu setzen: die Einstellung kann sich
            # geaendert haben, und der Dienst liest sie ohne Neustart nach.
            self.geraete[name].gruppen_erlaubt = erlaubt
            self.geraete[name].lauscher_erlaubt = schnell
            self.geraete[name].netzsuche = self.netzsuche if schnell else None

    def geraete_fuer(self, kennung):
        """Welche Geraete meint diese Kennung? Immer eine LISTE.

        Ein echter Geraetename gewinnt vor dem Sammelziel: wer seinen
        Lautsprecher tatsaechlich "alle" nennt, soll ihn weiter einzeln
        ansprechen koennen.
        """
        einzeln = self.geraet_finden(kennung)
        if einzeln is not None:
            return [einzeln]
        if kennung and str(kennung).strip().lower() in SAMMELZIEL:
            return list(self.geraete.values())
        return []

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
        ziele = self.geraete_fuer(kennung)
        if not ziele:
            log.warning("Kein Geraet fuer '%s' - konfiguriert sind: %s "
                        "(Sammelziel: %s)", kennung,
                        ", ".join(self.geraete) or "keine", "/".join(SAMMELZIEL))
            return
        if len(ziele) > 1:
            log.info("Sammelbefehl '%s' an %d Geraete", befehl, len(ziele))
        for geraet in ziele:
            # EINREIHEN, nicht aufrufen. Der Aufrufer ist der Netzfaden von
            # paho oder der UDP-Faden; beide duerfen nicht minutenlang in
            # einer Ansage stehen bleiben.
            geraet.einreihen(befehl, wert,
                             self._zahl("lautstaerke_schritt", 5), self.cfg)

    def start(self):
        log.info("Chromecast 4 Lox NG %s startet", version() or "(Fassung unbekannt)")
        log.info("Konfiguration: %s", CONFIG_FILE)

        if self.cfg.get("mqtt_ein", "1") == "1":
            self.mqtt.start()
        else:
            log.info("MQTT ist ausgeschaltet")

        self.geraete_aufbauen()

        if self.cfg.get("udp", "1") == "1":
            self.udp = UdpEmpfaenger(self._zahl("udp_port", 7090), self)
            self.udp.start()

        if str(self.cfg.get("beschleunigung", "0")).strip() == "1":
            self.netzsuche.start()

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
                    # Nicht in jedem Durchgang suchen: ein erfolgloser
                    # Versuch kostet acht Sekunden, und die zahlen die
                    # Geraete, die gerade laufen.
                    if geraet.darf_suchen():
                        geraet.suche_vermerken(geraet.verbinden(),
                                               max(60.0, intervall * 6))
                erzwingen = neu_verbunden or (time.time() - letzte_vollmeldung) >= vollmeldung_alle
                geraet.melden(erzwingen=erzwingen)
            if (time.time() - letzte_vollmeldung) >= vollmeldung_alle:
                letzte_vollmeldung = time.time()

            # Das Lebenszeichen geht in JEDEM Durchgang hinaus, auch wenn
            # sich sonst nichts geaendert hat.
            self.herzschlag(sum(1 for g in self.geraete.values() if g.verbunden()))

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
        # -1 heisst: der Takt laeuft nicht mehr. Ein stehengebliebener
        # Zaehler waere von einem langsamen nicht zu unterscheiden.
        try:
            self.mqtt.senden("server/zaehler", -1, thema_retain("dienst", "zaehler"))
        except Exception:  # noqa: BLE001
            pass
        if self.udp:
            self.udp.stop()
        for geraet in self.geraete.values():
            geraet.faden_laeuft = False
            geraet.ansage_abbrechen = True
            try:
                geraet.auftraege.put_nowait(None)
            except Exception:  # noqa: BLE001
                pass
        for geraet in self.geraete.values():
            geraet.melden_offline()
            geraet.trennen()
        self.netzsuche.stop()
        self.mqtt.stop()


def main():
    # Die Selbstpruefung im Reiter Test ruft den Dienst mit --themen auf und
    # haelt die Liste gegen die der Oberflaeche. Zwei Listen in zwei Sprachen
    # halten sich nicht von selbst gleich - und ein Kommentar ist kein
    # Nachweis.
    if "--themen" in sys.argv[1:]:
        print(json.dumps({
            "fassung": THEMEN.get("fassung", 0),
            "geraet": [e["schluessel"] for e in THEMEN.get("geraet", ())],
            "dienst": [e["schluessel"] for e in THEMEN.get("dienst", ())],
            "befehle": [e["schluessel"] for e in THEMEN.get("befehle", ())],
        }))
        return

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
