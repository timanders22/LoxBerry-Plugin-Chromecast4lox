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

import errno
import hashlib
import json
import logging
import logging.handlers
import math
import os
import queue
import re
import signal
import socket
import sys
import threading
import time
import unicodedata
import urllib.parse


# Grund einer abgewiesenen MQTT-Anmeldung in Worten. paho 1.x liefert die
# CONNACK-Codes aus MQTT 3.1.1 (1-5), paho 2.x fuer dieselben Faelle die
# Ursachencodes aus MQTT 5 (132-136) - je nach installierter Fassung kommt
# also die eine ODER die andere Zahl an (Regeln/07). Nachgetragen 17.09.2026.
MQTT_ANMELDUNG_TEXT = {
    1: "der Broker lehnt die Protokollfassung ab",
    2: "der Broker lehnt die Kennung des Clients ab",
    3: "der Broker ist nicht verfuegbar",
    4: "Benutzer oder Kennwort des Brokers sind falsch (System -> MQTT Gateway)",
    5: "nicht berechtigt - Benutzer und Kennwort des Brokers pruefen (System -> MQTT Gateway)",
}
for _alt, _neu in ((1, 132), (2, 133), (3, 136), (4, 134), (5, 135)):
    MQTT_ANMELDUNG_TEXT[_neu] = MQTT_ANMELDUNG_TEXT[_alt]


def mqtt_anmeldegrund(rc):
    """'Code 135: nicht berechtigt - ...' aus einer Zahl oder einem ReasonCode."""
    try:
        code = int(getattr(rc, "value", rc))
    except (TypeError, ValueError):
        return "Code %s" % rc
    return "Code %d: %s" % (code, MQTT_ANMELDUNG_TEXT.get(code, "unbekannter Grund"))
from configparser import ConfigParser


# ---------------------------------------------------------------------------
# Pfade - LoxBerry ersetzt die Platzhalter bei der Installation
# ---------------------------------------------------------------------------
#
# Installiert hat LoxBerry jeden Platzhalter durch den Pfad der Anlage
# ersetzt; dann gelten genau diese Pfade. Steht er noch da, laeuft die Datei
# aus einem ausgepackten Archiv oder einem Pruefordner. Bis 1.3.11 suchte sie
# dann vom eigenen Ablageort aufwaerts das erste Verzeichnis mit
# config/plugins und webfrontend und nahm es als Wurzel: ein Archiv unterhalb
# einer echten Installation arbeitete damit auf deren Konfiguration, Daten
# und Broker, ein Archiv unter einem fremden Baum ohne general.json schrieb
# dorthin, und mit LBHOMEDIR allein - so steht es am Geraet in
# /etc/environment - lagen die Datenpfade trotzdem ab "/" (in WSL gemessen
# 25.09.2026, Pruefung-Chromecast4lox-1.3.12, Faelle P1 bis P3).
#
# Aus dem Archiv gilt jetzt nur, was der Aufrufer ausdruecklich nennt:
# LBHOMEDIR UND LBPPLUGINDIR, und unter LBHOMEDIR liegt
# config/system/general.json (so arbeiten die Pruefwerkzeuge mit ihrer
# Attrappe). Sonst gibt es keine Wurzel: die Pfade bleiben leer, und main()
# steigt aus, bevor etwas gelesen, gesendet oder geschrieben wird. Bauart
# Spotpreis-Tibber 0.9.19 (tb_paths), Muster 1 bis 3 der Nachlese.

PLUGIN_NAME = "REPLACELBPPLUGINDIR"
if PLUGIN_NAME.startswith("REPLACE"):
    _lbp = os.path.basename(os.environ.get("LBPPLUGINDIR", "").rstrip("/"))
    _home = os.environ.get("LBHOMEDIR", "").rstrip("/")
    if _lbp not in ("", ".", "bin", "plugins") and _home != "" \
            and os.path.isfile(os.path.join(_home, "config", "system", "general.json")):
        HOME_DIR = _home
        PLUGIN_NAME = _lbp
    else:
        HOME_DIR = ""
        PLUGIN_NAME = "chromecast-4lox-ng"
else:
    HOME_DIR = "REPLACELBHOMEDIR"


def _anlage(*teile):
    """Ein Pfad der Anlage - oder "", wenn es keine Wurzel gibt."""
    return os.path.join(HOME_DIR, *teile) if HOME_DIR else ""


CONFIG_DIR = "REPLACELBPCONFIGDIR"
if CONFIG_DIR.startswith("REPLACE"):
    CONFIG_DIR = _anlage("config", "plugins", PLUGIN_NAME)

LOG_DIR = "REPLACELBPLOGDIR"
if LOG_DIR.startswith("REPLACE"):
    LOG_DIR = _anlage("log", "plugins", PLUGIN_NAME)

# Das Datenverzeichnis liegt NICHT auf der Ramdisk - anders als log/.
# Dorthin schreibt der Dienst sein Lebenszeichen, und das soll einen
# Neustart des Rechners ueberstehen.
DATA_DIR = "REPLACELBPDATADIR"
if DATA_DIR.startswith("REPLACE"):
    DATA_DIR = _anlage("data", "plugins", PLUGIN_NAME)

# Der unangemeldete html-Ordner. Dorthin legt die oertliche Ansage ihre
# Datei - der Chromecast holt sie ueber HTTP ab und bringt dafuer keine
# Zugangsdaten mit.
HTML_DIR = "REPLACELBPHTMLDIR"
if HTML_DIR.startswith("REPLACE"):
    HTML_DIR = _anlage("webfrontend", "html", "plugins", PLUGIN_NAME)
CONFIG_FILE = os.path.join(CONFIG_DIR, PLUGIN_NAME + ".cfg")
ZUSTAND_FILE = os.path.join(DATA_DIR, "zustand.json")
# Sprech-Eingang fuer andere Plugins (Punkt Ansage-3, 01.10.2026): Auftraege des
# Endpunkts webfrontend/html/index.php und das Ergebnis der letzten Ansage.
SPRECHEN_ORDNER = os.path.join(DATA_DIR, "sprechen") if DATA_DIR else ""
SPRECHEN_ERGEBNIS = os.path.join(DATA_DIR, "sprechen_ergebnis.json") if DATA_DIR else ""
# Sperre aus Loxone (B1, 1.3.22): der zuletzt gesetzte Zustand, fuer Dienst und Endpunkt.
LOXSPERRE_FILE = os.path.join(DATA_DIR, "loxsperre.json") if DATA_DIR else ""
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

# Beim EINBINDEN nach stderr, nicht nach stdout: auf stdout antworten
# "--themen" (JSON fuer den Reiter Test) und "--mqtt-leeren" (Zeilen fuer das
# Installationsprotokoll). Im Dienstbetrieb stellt main() auf die eigene
# Datei um (log_datei_einrichten()).
#
# Bis 1.3.12 schrieb der Dienst NUR nach stdout, und die drei Startwege
# (daemon/daemon, cron/cron.05min, cc_dienst() in cc_lib.php) leiteten mit
# "nohup ... >> <plugin>.log" in die Datei um. Der Deskriptor gehoerte damit
# der Schale: wurde die Datei darunter entfernt (Ramdisk geleert,
# trim_logcount, neu angelegte Protokollordner beim Einspielen eines anderen
# Plugins), schrieb der Dienst in einen geloeschten Inode - bis zum naechsten
# Neustart (Regeln/03, "Die dritte Protokollart"; am Geraet 07.09.2026
# gemessen, in WSL 30.09.2026 nachgestellt: fd 1 auf "(deleted)").
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)-7s %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
    handlers=[logging.StreamHandler(sys.stderr)],
)
log = logging.getLogger("chromecast4lox_ng")


def log_datei_einrichten():
    """Im Dienstbetrieb schreibt der Dienst sein Protokoll SELBST (seit 1.3.13).

    Ein WatchedFileHandler prueft vor jeder Zeile Geraetenummer und Inode und
    legt die Datei neu an, wenn sie fort ist (Regeln/03). Genau EIN Schreiber
    auf <plugin>.log: die Startwege leiten nur noch in <plugin>_start.log um -
    dort steht, was vor diesem Aufruf scheitert (Interpreter, Import).
    Gerufen nur aus main() im Dienstbetrieb; "--themen" und "--mqtt-leeren"
    schreiben nicht in das Dienstprotokoll. Bauart BLE-Scanner NG 1.3.20.
    """
    if not LOG_DIR:
        return
    wurzel = logging.getLogger()
    try:
        os.makedirs(LOG_DIR, exist_ok=True)
        datei = logging.handlers.WatchedFileHandler(
            os.path.join(LOG_DIR, PLUGIN_NAME + ".log"))
        datei.setFormatter(logging.Formatter(
            "%(asctime)s %(levelname)-7s %(message)s", "%Y-%m-%d %H:%M:%S"))
        for h in list(wurzel.handlers):
            wurzel.removeHandler(h)
        wurzel.addHandler(datei)
    except OSError as fehler:
        log.warning("Protokolldatei in %s nicht zu oeffnen (%s) - es bleibt bei "
                    "stderr (%s_start.log).", LOG_DIR, fehler, PLUGIN_NAME)


# ---------------------------------------------------------------------------
# Konfiguration
# ---------------------------------------------------------------------------

VORGABEN_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)),
                             "cc_vorgaben.json")


def _vorgaben_lesen():
    """Die Vorgabewerte - aus derselben Datei, die auch die Oberflaeche liest.

    Bis 1.3.0 stand hier eine eigene Liste, in der Oberflaeche eine zweite
    und in der ausgelieferten Konfiguration eine dritte: 26, 28 und 27
    Schluessel. Zwei getrennt gepflegte Vorgabelisten sind zwei Wahrheiten.

    Fail closed: laesst sich die Datei nicht lesen, bleibt die Liste leer.
    Der Dienst sagt das und arbeitet mit dem, was in der Konfiguration
    steht - er erfindet keine Werte und schreibt erst recht keine.
    """
    try:
        with open(VORGABEN_FILE, "r", encoding="utf-8") as fh:
            d = json.load(fh)
    except (OSError, ValueError) as fehler:
        log.error("Vorgabeliste %s nicht lesbar (%s) - es gelten nur die "
                  "Werte, die in der Konfiguration stehen.",
                  VORGABEN_FILE, fehler)
        return {}
    werte = d.get("vorgaben")
    if not isinstance(werte, dict) or not werte:
        log.error("Vorgabeliste %s ist leer oder unvollstaendig.", VORGABEN_FILE)
        return {}
    return dict((str(k), str(v)) for k, v in werte.items())


VORGABEN = _vorgaben_lesen()


# ---------------------------------------------------------------------------
# Kodierung der Werte (seit 1.3.13, C3)
# ---------------------------------------------------------------------------
#
# Die Datei ist zeilenorientiert; ein Wert darf keinen Zeilenumbruch tragen.
# Bis 1.3.12 ersetzte cc_config_write() jeden Umbruch durch ";" - auch in den
# Favoriten, die Oberflaeche und Dienst NUR am Umbruch trennen. Aus drei
# Favoriten wurde einer mit kaputter Adresse (Pruefung 30.09.2026, Code-Befund
# 3, gemessen PHP 7.4/8.5 und Dienst). Jetzt eine eigene Kodierung in beide
# Richtungen, auf beiden Seiten gleich (cc_wert_kodieren()/cc_wert_dekodieren()
# in cc_lib.php):
#     "\"  -> "\\"      Zeilenumbruch -> "\n"
# Andere Folgen hinter einem Rueckstrich bleiben beim Lesen stehen, wie sie
# sind - eine alte Datei ohne Kodierung liest sich unveraendert.

def wert_kodieren(text):
    text = str(text).replace("\r\n", "\n").replace("\r", "\n")
    return text.replace("\\", "\\\\").replace("\n", "\\n")


def wert_dekodieren(text):
    return re.sub(r"\\(.)", lambda m: "\n" if m.group(1) == "n"
                  else ("\\" if m.group(1) == "\\" else m.group(0)),
                  str(text), flags=re.S)


def konfiguration_gekuerzt(roh):
    """Ist die Datei mitten im Schreiben abgebrochen? (seit 1.3.13, C9)

    Jeder Schreiber dieser Linie (cc_config_write(), das Vervollstaendigen
    hier, die mitgelieferte Vorgabe) legt den Abschnitt [CONFIG] an und endet
    mit einem Zeilenende. Fehlt eines davon, ist die Datei gekuerzt - sie zu
    "vervollstaendigen" hiesse, fehlende Werte mit der Werkseinstellung zu
    fuellen: Lautstaerkegrenze 100, Ruhezeit aus, Aktionstoken leer (in WSL
    gemessen 30.09.2026, ulimit -f 1, Code-Befund 9)."""
    if roh is None:
        return False
    if not roh.endswith("\n"):
        return True
    return re.search(r"^\s*\[CONFIG\]\s*$", roh, re.M) is None


def konfiguration_vervollstaendigen(werte, vorhanden, roh=None):
    """Fehlende Schluessel EINMAL in die Konfigurationsdatei schreiben.

    Ergaenzen hiesse: beim Lesen tritt die Vorgabe ein, die Datei bleibt
    lueckenhaft, und "fehlt" ist von "steht auf dem Vorgabewert" nicht mehr
    zu unterscheiden.

    Geschrieben wird NUR ueber eine Datei, die es schon gibt. Eine frische
    Anlage bekommt ihre Konfiguration von der Oberflaeche; der Dienst legt
    sie nicht an - sonst schriebe er eine Datei, die der Anwender nie
    gesehen hat.

    Schluessel, die NICHT in der Vorgabeliste stehen, bleiben erhalten. Der
    Dienst ist nicht die Stelle, die entscheidet, was in der Konfiguration
    stehen darf.
    """
    fehlen = [k for k in VORGABEN if k not in vorhanden]
    if not fehlen or not os.path.isfile(CONFIG_FILE):
        return fehlen
    if konfiguration_gekuerzt(roh):
        # Melden, nicht fuellen (C9). Die Zweitschrift neben dem Konfigordner
        # und das Zurueckspielen in der Oberflaeche bleiben unberuehrt.
        log.error("Konfiguration %s ist gekuerzt (Abschnitt [CONFIG] oder das "
                  "Zeilenende am Schluss fehlt) - sie wird NICHT mit Vorgaben "
                  "vervollstaendigt. Es fehlen: %s", CONFIG_FILE, ", ".join(fehlen))
        return fehlen
    zeilen = ["; Chromecast 4 Lox NG",
              "; Wird von der Plugin-Oberflaeche geschrieben.", "", "[CONFIG]"]
    geschrieben = set()
    for k in VORGABEN:
        zeilen.append("%s=%s" % (k, wert_kodieren(werte.get(k, VORGABEN[k]))))
        geschrieben.add(k)
    for k in sorted(vorhanden - geschrieben):
        zeilen.append("%s=%s" % (k, wert_kodieren(werte.get(k, ""))))
    inhalt = ("\n".join(zeilen) + "\n").encode("utf-8")
    # Nebendatei mit PID, Rechte 0600 VOR dem Inhalt, Laenge nachgemessen,
    # dann umbenennen (Regeln/03 "Atomares Schreiben"; C9/C10). Bis 1.3.12:
    # ".neu" ohne PID, Rechte nach umask (644) - die Datei traegt das
    # Aktionstoken (in WSL gemessen 30.09.2026, Code-Befund 10).
    vorlaeufig = "%s.neu.%d" % (CONFIG_FILE, os.getpid())
    try:
        fd = os.open(vorlaeufig, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
        try:
            os.fchmod(fd, 0o600)
            fertig = 0
            while fertig < len(inhalt):
                n = os.write(fd, inhalt[fertig:])
                if n <= 0:
                    raise OSError(errno.EIO, "kurz geschrieben")
                fertig += n
        finally:
            os.close(fd)
        if os.path.getsize(vorlaeufig) != len(inhalt):
            raise OSError(errno.EIO, "Laenge weicht ab")
        os.replace(vorlaeufig, CONFIG_FILE)
        log.info("Konfiguration vervollstaendigt, es fehlten: %s",
                 ", ".join(fehlen))
    except OSError as fehler:
        try:
            os.remove(vorlaeufig)
        except OSError:
            pass
        log.warning("Konfiguration liess sich nicht vervollstaendigen: %s",
                    fehler)
    return fehlen


def konfiguration_lesen():
    """Konfiguration im Config::Lite-Format lesen. Fehlende Werte werden
    durch die Vorgaben ersetzt, damit der Dienst nie ohne Werte dasteht."""
    werte = dict(VORGABEN)
    # Mitzaehlen, was WIRKLICH in der Datei stand: nach dem Untermischen
    # der Vorgaben ist das nicht mehr zu erkennen.
    gelesen = set()
    parser = ConfigParser(interpolation=None)
    parser.optionxform = str
    roh = None
    try:
        with open(CONFIG_FILE, "r", encoding="utf-8") as fh:
            roh = fh.read()
        parser.read_string(roh)
    except (OSError, Exception) as fehler:  # noqa: BLE001
        log.warning("Konfiguration %s nicht lesbar: %s", CONFIG_FILE, fehler)
        return werte

    for abschnitt in parser.sections():
        for schluessel, wert in parser.items(abschnitt):
            k = schluessel.strip().lower()
            w = wert.strip()
            # Umschliessende Anfuehrungszeichen nur als PAAR entfernen - wie
            # cc_config_roh() in PHP. Bis 1.3.12 nahm strip('"').strip("'")
            # jedes einzelne am Rand weg; ein Geraetename auf ' endete hier
            # anders als in der Oberflaeche (O5, seit 1.3.13).
            if len(w) >= 2 and w[0] == w[-1] and w[0] in ("'", '"'):
                w = w[1:-1]
            werte[k] = wert_dekodieren(w)
            gelesen.add(k)
    konfiguration_vervollstaendigen(werte, gelesen, roh)
    return werte


def geraeteliste(cfg):
    """Geraetenamen aus der Konfiguration. Semikolon oder Zeilenumbruch
    trennen; leere Eintraege entfallen.

    Seit 1.3.13 trennt ein Komma NICHT mehr (O5): das Feld heisst "einer je
    Zeile", und cc_geraete() in der Oberflaeche trennt gleich. Bis 1.3.12 wurde
    "Bad, oben" zu zwei Geraeten."""
    roh = cfg.get("geraete", "") or ""
    teile = re.split(r"[;\n\r]+", roh)
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

    Ein Thema ohne Eintrag geht NICHT retained hinaus (seit 1.3.9; bis 1.3.8
    war es umgekehrt). Retained ist eine Zusage, dass der Wert auch ohne
    neue Nachricht gilt - die gibt nur, wer das Thema eingetragen hat.
    """
    e = thema_info(bereich, schluessel)
    return False if e is None else bool(e.get("retain", False))


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
    # Nur endliche Zahlen (seit 1.3.13, C5/M2). int(float("inf")) wirft einen
    # OverflowError, den bis 1.3.12 niemand fing: "volume inf" oder
    # "seek 1e400" trennten die Verbindung zum Lautsprecher - genau das, was
    # dieser Docstring ausschliessen will (gemessen 30.09.2026, Code-Befund 5).
    try:
        zahl = float(text)
        if not math.isfinite(zahl):
            raise ValueError("nicht endlich")
        return int(zahl)
    except (TypeError, ValueError, OverflowError):
        log.warning("'%s' ist keine endliche Zahl - es gilt die Vorgabe %s",
                    text[:40], vorgabe)
        return vorgabe


def befehlszahl(wert, vorgabe, unten, oben, name):
    """Eine Zahl aus einer Befehlsnutzlast - oder eine Abweisung (seit 1.3.13).

    Rueckgabe (zahl, None) oder (None, meldung). Leer heisst Vorgabe. Was
    keine endliche Zahl ist oder ausserhalb von [unten, oben] liegt, wird
    ABGEWIESEN und gemeldet, nicht still gekappt (CLAUDE.md Abschnitt 4; bis
    1.3.12 wurde "volume 150" still zu 100 und "-5" zu 0, Pruefung MQTT M2).
    Eine Abweisung trennt die Verbindung nicht."""
    text = "" if wert is None else str(wert).strip()
    if text == "":
        return vorgabe, None
    try:
        zahl = float(text)
        if not math.isfinite(zahl):
            raise ValueError("nicht endlich")
        ganz = int(round(zahl))
    except (TypeError, ValueError, OverflowError):
        return None, "{0}: '{1}' ist keine endliche Zahl - abgewiesen".format(name, text[:40])
    if ganz < unten or ganz > oben:
        return None, "{0}: {1} liegt ausserhalb {2} bis {3} - abgewiesen".format(
            name, ganz, unten, oben)
    return ganz, None


# Namen, unter denen ein Befehl ALLE Geraete erreicht. Sie sind bewusst
# keine gueltigen Geraetenamen: ein Chromecast, der wirklich "alle" heisst,
# waere sonst nicht mehr einzeln ansprechbar - deshalb gewinnt ein echter
# Geraetename, und das Sammelziel greift erst danach.
SAMMELZIEL = ("alle", "all", "*")


def befehl_kennung(thema):
    """Der Befehlsname eines Geraets fuer die Befehlsbruecke (Gruppe 6b, 1.3.24, Entwurf cc E2).

    Das MQTT-Thema des Geraets (thema_saeubern) klein, "-" wird "_", hoechstens 40 Zeichen: die Bruecke erlaubt je
    Ebene nur [a-z0-9_]{1,40} (befehlsbruecke.php, bb_ebene_gueltig). Aus "Wohnzimmer" wird "wohnzimmer", aus
    "Bad-Oben" "bad_oben". Die Werte-Themen behalten ihre Schreibweise (chromecast4lox/Wohnzimmer/volume). Dieselbe
    Funktion steht als cc_befehl_kennung() in cc_lib.php; der Reiter Test haelt beide gegeneinander (--themen)."""
    k = re.sub(r"[^a-z0-9_]", "_", str(thema or "").lower().replace("-", "_"))[:40]
    return k or "geraet"


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


# ---------------------------------------------------------------------------
# Audio Stufe 1 (1.3.25, Entscheidungen 30.10.2026, Musterprojekt/ENTSCHEIDUNGEN_AUDIO_STUFE1.md)
#
# Zonen statt Geraetenamen (E1), Quellenliste als Radiotasten (S3), Medienablage SSD/NAS ueber einen Verweis im
# eigenen Webordner, Apache liefert (E2), Klingel auf eine Google-Gruppe mit Rueckfall parallel (E3-E5),
# Wiedergabelisten aus M3U-Datei oder Ordner (E7). Alles hier ist rein (liest hoechstens Dateien) und wird im
# Selbsttest (--selbsttest) ohne Netz und ohne Lautsprecher geprueft.
# ---------------------------------------------------------------------------

ZONEN_MAX = 24
QUELLEN_MAX = 99
LISTE_MAX = 200
QUELLEN_ARTEN = ("radio", "liste", "datei")
ZONE_KENNUNG = re.compile(r"^zone_([0-9]{1,2})$")

# Inhaltstyp je Endung - nur Audio. Ein Ordner liefert nur Dateien mit diesen Endungen.
# Nachfassung 8 (30.10.2026, Zusage an den Hausherrn): .mp4 gilt als Audio (audio/mp4) - der Lautsprecher
# spielt den Ton (AAC) eines MP4, bei Videos nur den Ton. Aus dieser Liste folgen Durchsicht/Wache (Zaehlung),
# Ordner- und M3U-Listen, Dateiquellen und ein Gong aus der Ablage. Apache liefert .mp4 laut /etc/mime.types als
# video/mp4 aus (am LoxBerry gelesen); der Lautsprecher bekommt den Typ aber mit dem Ladebefehl (audio/mp4).
AUDIO_ENDUNGEN = {
    ".mp3": "audio/mpeg", ".m4a": "audio/mp4", ".aac": "audio/aac", ".flac": "audio/flac",
    ".ogg": "audio/ogg", ".oga": "audio/ogg", ".opus": "audio/ogg", ".wav": "audio/wav",
    ".webm": "audio/webm", ".mp4": "audio/mp4",
}
LISTEN_ENDUNGEN = (".m3u", ".m3u8")
HLS_TYP = "application/x-mpegURL"

# Dateien, die Apache im Webordner AUSFUEHREN oder auswerten koennte. Liegt eine davon in der Medienablage, wird
# der Verweis nicht angelegt: der Ordner waere sonst ueber den Pfadschluessel als Programm erreichbar.
WEB_AUSFUEHRBAR = (".php", ".php3", ".php4", ".php5", ".php7", ".php8", ".phtml", ".phar", ".pht", ".phps",
                   ".cgi", ".pl", ".py", ".sh", ".shtml", ".shtm")
ABLAGE_PRUEF_MAX = 50000          # Eintraege; mehr wird nicht durchsucht, der Verweis dann nicht angelegt
# Nachfassung 6 (30.10.2026): dieselbe Endungsliste wie die zweite Sperre der .htaccess (SPERRE_MUSTER) - im Namen
# ueberall, nicht nur am Ende (mod_mime nimmt jede Endung eines Namens). Die Wache sieht die freigegebene Ablage alle
# ABLAGE_WACHE_ALLE s erneut durch (eigener Faden, hoechstens ABLAGE_WACHE_FRIST s und ABLAGE_PRUEF_MAX Eintraege;
# reicht die Grenze nicht oder laeuft eine Klingel/Ansage, bleibt die Freigabe, wie sie ist). 120 s: eine Musiksammlung
# mit 50000 Dateien hat grob 5000 Ordner, je Ordner ein Verzeichnislesen ueber SMB (an der FRITZ!Box geschaetzt
# 5-20 ms, nicht gemessen) -> 25-100 s; mehr waere eine Last, die alle 15 min wiederkehrt.
SPERRE_ENDUNGEN = "php[0-9]*|pht|phtml|phps|phar|cgi|pl|py|sh|shtml?"
ABLAGE_UNSICHER = re.compile(r"\.(" + SPERRE_ENDUNGEN + r")(\.|$)", re.I)
ABLAGE_WACHE_ALLE = 900
ABLAGE_WACHE_FRIST = 120.0
ABLAGE_SCHLUESSEL = re.compile(r"^[0-9a-f]{24,64}$")

# Nachfassung 7 (30.10.2026, Befund Hausherr 20:31): der Klingel-Gong haengt nicht mehr an der Medienablage. Die Ablage
# wechselt mit der Musiksammlung (z. B. USB -> FRITZ!Box), Dateinamen gelten relativ zu ihr - "gong.mp3" zeigte danach
# ins Leere, und die Klingel blieb stumm. Jetzt: die Datei der Ablage, wenn es sie dort gibt (wie bisher), sonst der
# eigene Gong (Reiter Audio, hochgeladen nach config/plugins/<ordner>/klaenge/ - die Sicherung beim Update nimmt den
# ganzen Konfigurationsordner mit), sonst der mitgelieferte Standard-Gong, den der Dienst selbst erzeugt. Ausgeliefert
# aus html/plugins/<ordner>/klang/ (wie die oertliche Ansage aus ansage/); die Namen dort vergibt der Dienst.
KLANG_ENDUNGEN = {".mp3": "audio/mpeg", ".ogg": "audio/ogg", ".wav": "audio/wav"}
KLANG_MAX = 1048576
KLANG_STANDARD = "standard_gong_1.wav"
KLANG_EIGEN_NAME = re.compile(r"^eigener_gong\.(mp3|ogg|wav)$")
KLANG_WEB_EIGEN = re.compile(r"^eigen_[0-9a-f]{16}\.(mp3|ogg|wav)$")
_GONG_RATE = 22050
_GONG_PROBEN = _GONG_RATE * 28 // 10          # 2,8 s
_KLANG_ORTE = {}                               # nur der Selbsttest lenkt die beiden Ordner um

# Was der Dienst zur Medienablage weiss - gesetzt von Dienst.ablage_einrichten(), gelesen beim Abspielen.
ABLAGE_STAND = {"eingerichtet": 0, "ok": 0, "grund": "", "dateien": 0, "pfad": "", "schluessel": ""}

# Im Netz gesehene Cast-Geraete (S2): Name -> Modell. Nur Name und Modell, keine Adresse und keine Kennung.
SICHTBAR = {}

# Nachfassung 3 (30.10.2026, O1 am Geraet gemessen): Mitglieder der Google-Gruppen. Der MultizoneController von
# pychromecast fragt die Gruppe (GET_STATUS im Namensraum urn:x-cast:com.google.cast.multizone) - eine Statusabfrage,
# es wird nichts gespielt. Gemessen am LoxBerry mit pychromecast 9.4.0: Gruppe "Klingel" -> zwei Mitglieder mit Namen.
# Die Antwort nennt je Mitglied Kennung und Namen; der Dienst merkt sie unter data/ (mitglieder.json), damit sie nach
# einem Neustart auch dann bekannt sind, wenn die Gruppe noch nicht verbunden ist (Klingel-Rueckfall). Aenderungen
# meldet die Gruppe selbst; nach MZ_NACHFRAGE Sekunden fragt der Dienst eine verbundene Gruppe trotzdem erneut.
MZ_NACHFRAGE = 900.0

# 1.3.26 (Hoertest 01.11.2026, Befund 2): eine Verbindung, die im Zustand "connecting" haengt. pychromecast verbindet
# ein Cast-Objekt immer wieder mit derselben Adresse. Eine in Google Home geloeschte und neu angelegte Gruppe hat aber
# eine neue Kennung und einen neuen Port - das alte Objekt kommt nie mehr an, jeder Sendeversuch endet in NotConnected
# (am Geraet 31.10. 22:57 bis 01.11. 03:41, erst ein Neustart des Dienstes half). Steht ein Geraet laenger als
# HAENGT_GRENZE s so da oder scheitern HAENGT_FEHLER Sendeversuche hintereinander daran, verwirft der Dienst das Objekt
# und sucht neu - per Name in der Dauersuche, sonst einzeln wie beim ersten Verbinden.
HAENGT_GRENZE = 60.0
HAENGT_FEHLER = 2
# Die Klingel sucht ein haengendes Ziel einmal sofort neu und versucht es noch einmal - nur ueber die Dauersuche (keine
# Einzelsuche, die kostet 8 s) und hoechstens KLINGEL_NEUSUCHE s insgesamt; danach greift der Ersatzweg wie bisher.
KLINGEL_NEUSUCHE = 2.0
# Befund 1: spielende Mitglieder der Zielgruppe vor dem Gong anhalten (STOP). Gewartet wird, bis jedes IDLE meldet
# (Statusmeldung von pychromecast, alle KLINGEL_BLICK s nachgesehen), hoechstens KLINGEL_ANHALTEN s.
KLINGEL_ANHALTEN = 1.2
KLINGEL_BLICK = 0.05
# Befund 5: eine eingehaengte Netzfreigabe, deren Datentraeger ab- und wieder angesteckt wurde, liefert "Stale file
# handle" (ESTALE, unter Linux errno 116): das Verzeichnis listet noch, das Lesen einer Datei scheitert - Apache
# meldet 403, der Lautsprecher spielt nur den Verbindungston. Der Text geht ins Protokoll, in den Reiter Test und als
# letzte Meldung des Geraets; server/ablage_ok wird 0.
ESTALE_NUMMERN = (116, getattr(errno, "ESTALE", 116))
# Der Pfad der autofs-Datei kommt aus dem LoxBerry-Verzeichnis (HOME_DIR), nicht fest im Code.
ESTALE_TEXT = ("Netzfreigabe haengt (Stale file handle) - am LoxBerry neu einhaengen; bei FRITZ!Box Option "
               "noserverino in %s und danach autofs neu starten"
               % (_anlage("system", "autofs", "auto.smb").replace(os.sep, "/") or "system/autofs/auto.smb"))
ABLAGE_PROBE = {}          # zuletzt gelesene Datei der Ablage (Wurzel -> Pfad), damit die Pruefung nicht sucht


def nicht_verbunden(fehler):
    """1.3.26: Ist das die Ausnahme "is connecting" von pychromecast (NotConnected)?"""
    return type(fehler).__name__ == "NotConnected" or "is connecting" in str(fehler)


def fehler_lesbar(fehler, name=""):
    """1.3.26 (Befund 4): eine lesbare Meldung statt des Textes der Ausnahme. pychromecast 9.4.0 wirft
    NotConnected("Chromecast {self.host}:{self.port} is connecting...") - der Platzhalter steht dort woertlich, weil
    das Format fehlt. Daraus wird "Gerät/Gruppe 'Name' noch nicht verbunden". Aus jedem anderen Text fallen
    unformatierte Platzhalter und Adressen (IP, Port) heraus."""
    if nicht_verbunden(fehler):
        return ("Gerät/Gruppe '%s' noch nicht verbunden" % name) if name else "noch nicht verbunden"
    text = str(fehler)
    text = re.sub(r"\{self\.[A-Za-z_]+\}(:\{self\.[A-Za-z_]+\})?", "", text)
    text = re.sub(r"\b[0-9]{1,3}(\.[0-9]{1,3}){3}(:[0-9]+)?\b", "<Adresse>", text)
    text = " ".join(text.split())
    return text or type(fehler).__name__


def ablage_lesefehler(fehler):
    """1.3.26 (Befund 5): Grund zu einem Lesefehler in der Ablage - ESTALE bekommt den Hinweis zum Neu-Einhaengen."""
    if getattr(fehler, "errno", None) in ESTALE_NUMMERN or "stale file handle" in str(fehler).lower():
        return ESTALE_TEXT
    return "Datei der Ablage nicht lesbar (%s)" % (getattr(fehler, "strerror", None) or fehler)


def _ein_byte(pfad):
    with open(pfad, "rb") as fh:
        fh.read(1)


def ablage_lesbar(echt, datei=None, grenze=2000):
    """1.3.26 (Befund 5): Laesst sich eine Datei der Ablage wirklich lesen (1 Byte)? datei = diese (echter Pfad),
    sonst die zuletzt gelesene bzw. die erste Audiodatei, die beim Durchgehen auffaellt (hoechstens grenze Eintraege,
    Ordner alphabetisch, versteckte nicht). Rueckgabe "" = lesbar oder keine Datei zu finden, sonst der Grund
    (ESTALE_TEXT bzw. "Datei der Ablage nicht lesbar (...)"). Ein Verzeichnislesen allein beweist nichts: bei
    "Stale file handle" listet die Freigabe weiter."""
    try:
        if datei:
            _ein_byte(datei)
            return ""
        alt = ABLAGE_PROBE.get(echt)
        if alt:
            try:
                _ein_byte(alt)
                return ""
            except OSError as fehler:
                if getattr(fehler, "errno", None) != errno.ENOENT:
                    raise
                ABLAGE_PROBE.pop(echt, None)
        gesehen = 0
        offen = [echt]
        while offen:
            ordner = offen.pop(0)
            with os.scandir(ordner) as it:
                eintraege = sorted((e for e in it if not e.name.startswith(".")), key=lambda e: e.name.lower())
            for e in eintraege:
                gesehen += 1
                if gesehen > grenze:
                    return ""
                if inhaltstyp(e.name) and e.is_file(follow_symlinks=False):
                    _ein_byte(e.path)
                    ABLAGE_PROBE[echt] = e.path
                    return ""
            offen.extend(e.path for e in eintraege if e.is_dir(follow_symlinks=False))
        return ""
    except OSError as fehler:
        return ablage_lesefehler(fehler)



def kennung_gleich(a, b):
    """Zwei Cast-Kennungen gleich? Striche und Gross-/Kleinschreibung zaehlen nicht; leer ist nie gleich."""
    a = re.sub(r"[^0-9a-f]", "", str(a or "").lower())
    b = re.sub(r"[^0-9a-f]", "", str(b or "").lower())
    return a != "" and a == b


def name_gleich(a, b):
    """Zwei Geraetenamen gleich? Gross-/Kleinschreibung und Unicode-Form (Umlaut zusammengesetzt oder nicht) zaehlen
    nicht; leer ist nie gleich."""
    a = unicodedata.normalize("NFC", str(a or "")).strip().casefold()
    b = unicodedata.normalize("NFC", str(b or "")).strip().casefold()
    return a != "" and a == b


def _zahl_in(text, unten, oben):
    """Eine ganze Zahl aus Ziffern im Bereich - sonst None (kein Runden, kein Zurechtbiegen)."""
    t = str(text or "").strip()
    if not re.match(r"^[0-9]{1,3}$", t):
        return None
    n = int(t)
    return n if unten <= n <= oben else None


def zonen(cfg):
    """Die Zonentabelle (E1): je Zeile "Nr = Ziel" oder "Nr = Anzeigename | Ziel", Nr 1 bis 24, Ziel = Google-Name
    eines eingetragenen Geraets oder einer eingetragenen Gruppe. Rueckgabe: Liste von dict(nr, name, ziel) nach
    Nummer. Unverstaendliche Zeilen und doppelte Nummern fallen weg und stehen im Protokoll - dieselben Regeln
    prueft cc_zonen() in der Oberflaeche beim Speichern."""
    aus = {}
    for zeile in re.split(r"[\r\n]+", str(cfg.get("zonen", "") or "")):
        zeile = zeile.strip()
        if zeile == "" or zeile.startswith("#"):
            continue
        if "=" not in zeile:
            log.warning("Zone ohne Nummer uebergangen: %s", zeile[:60])
            continue
        nr_text, rest = zeile.split("=", 1)
        nr = _zahl_in(nr_text, 1, ZONEN_MAX)
        if nr is None:
            log.warning("Zone '%s': Nummer 1 bis %d erwartet - uebergangen", nr_text.strip()[:10], ZONEN_MAX)
            continue
        if "|" in rest:
            name, ziel = rest.split("|", 1)
        else:
            name, ziel = rest, rest
        name, ziel = name.strip(), ziel.strip()
        if ziel == "" or ";" in ziel:
            log.warning("Zone %d hat kein gueltiges Ziel - uebergangen", nr)
            continue
        if nr in aus:
            log.warning("Zone %d steht doppelt - die erste Zeile gilt", nr)
            continue
        aus[nr] = {"nr": nr, "name": name or ziel, "ziel": ziel}
    return [aus[k] for k in sorted(aus)]


def quellen(cfg):
    """Die Quellenliste (S3): je Zeile "Nr = Art | Name | Adresse", Nr 1 bis 99, Art radio, liste oder datei.
    Ist die Liste leer, gelten die Favoriten als Radioquellen 1 bis n - die Nummern bleiben gleich (S3: "die
    heutigen Favoriten werden als radio uebernommen"). Rueckgabe: dict Nr -> dict(nr, art, name, adresse)."""
    roh = str(cfg.get("quellen", "") or "")
    if roh.strip() == "":
        return dict((i, {"nr": i, "art": "radio", "name": n, "adresse": a})
                    for i, (n, a) in enumerate(favoriten(cfg), start=1) if i <= QUELLEN_MAX)
    aus = {}
    for zeile in re.split(r"[\r\n]+", roh):
        zeile = zeile.strip()
        if zeile == "" or zeile.startswith("#"):
            continue
        if "=" not in zeile:
            log.warning("Quelle ohne Nummer uebergangen: %s", zeile[:60])
            continue
        nr_text, rest = zeile.split("=", 1)
        nr = _zahl_in(nr_text, 1, QUELLEN_MAX)
        teile = [t.strip() for t in rest.split("|", 2)]
        if nr is None or len(teile) != 3 or teile[0].lower() not in QUELLEN_ARTEN or teile[2] == "":
            log.warning("Quelle '%s' hat nicht die Form 'Nr = radio|liste|datei | Name | Adresse' - uebergangen",
                        zeile[:60])
            continue
        art, adresse = teile[0].lower(), teile[2]
        web = re.match(r"^https?://", adresse, re.I) is not None
        if (art == "radio" and not web) or (art == "liste" and ablage_relativ(adresse) is None) \
                or (art == "datei" and not web and ablage_relativ(adresse) is None):
            log.warning("Quelle %d: Adresse passt nicht zur Art %s - uebergangen", nr, art)
            continue
        if nr in aus:
            log.warning("Quelle %d steht doppelt - die erste Zeile gilt", nr)
            continue
        aus[nr] = {"nr": nr, "art": art, "name": teile[1] or adresse, "adresse": adresse}
    return aus


def inhaltstyp(pfad):
    """Inhaltstyp aus der Endung - nur fuer Dateien der Medienablage; None, wenn es keine Audiodatei ist."""
    p = str(pfad or "").split("?", 1)[0].lower()
    for endung, typ in AUDIO_ENDUNGEN.items():
        if p.endswith(endung):
            return typ
    return None


# Nachfassung 30.10.2026: im eigenen Webordner gilt die LoxBerry-Seitenkonfiguration (Options +Indexes,
# AllowOverride All) - ohne diese Datei zeigte Apache hinter dem Verweis m_<Schluessel> eine Verzeichnisliste.
# Apache liest .htaccess entlang des nicht aufgeloesten Pfads, sie gilt also auch hinter dem Verweis. Punkt-Pfade
# -> 404: die Durchsicht sieht versteckte Ordner nicht an. Der Installer kopiert Punktdateien nicht mit, darum
# legt sie der Dienst an.
# Nachfassung 5 (30.10.2026, Entscheidung Hausherr): zweite Sperre unter m_<Schluessel>/ - unabhaengig von der
# Durchsicht der Ablage, die nur beim Einrichten laeuft (wer spaeter eine .php in den Ordner legt, dem fuehrte Apache
# sie sonst aus). Am LoxBerry gelesen: Apache 2.4.68, AllowOverride All, Options +ExecCGI +MultiViews,
# AddHandler cgi-script cgi (mod_mime nimmt jede Endung eines Namens), PHP 7.4 ueber FilesMatch ".+\.ph(ar|p|tml)$"
# (gross geschrieben also nicht PHP, aber als Quelltext ausgeliefert). Drei Riegel, keiner haengt am PHP-Modul:
#  1. Programm-Endungen unter m_<Schluessel>/ -> 404, ohne Gross/klein, auch mitten im Namen und vor PATH_INFO.
#  2. <If> auf den Pfad: dort jede Datei nur als Datei (SetHandler default-handler schlaegt FilesMatch und
#     AddHandler), keine Startseite (DirectoryIndex disabled).
#  3. im ganzen Webordner keine Inhaltsaushandlung (MultiViews: /m_<S>/test liefe sonst als test.php), kein CGI,
#     kein SSI (die eigene index.php bleibt, wie sie ist).
# Kein "php_flag engine off": ohne geladenes mod_php ist das ein unbekannter Befehl, und eine ungueltige Zeile macht
# den ganzen Webordner zum 500er (am Geraet mit apache2 -t belegt); dort liegt auch libphp8.4 (anderer Modulname).
# Grenze: eine .htaccess, die spaeter jemand IN die Ablage legt, liest Apache mit (die Durchsicht weist sie nur beim
# Einrichten ab) - dagegen hilft eine Freigabe nur mit Leserecht.
SPERRE_MUSTER = r"(?i)/m_[0-9a-f]+/.*\.(" + SPERRE_ENDUNGEN + r")(\.|/|$)"
INDEX_SCHUTZ = ("# Chromecast 4 Lox NG: vom Dienst angelegt (Medienablage). Keine Verzeichnisliste, nur einzelne\n"
                "# Dateien; Pfade mit Punkt-Anteil gibt es nicht. Wird beim Freigeben der Ablage neu geschrieben.\n"
                "Options -Indexes\n"
                "RedirectMatch 404 \"/\\.\"\n"
                "# Zweite Sperre: unter m_<Schluessel>/ nie ein Programm, unabhaengig von der Durchsicht der Ablage.\n"
                "Options -MultiViews -ExecCGI -Includes\n"
                "RedirectMatch 404 \"" + SPERRE_MUSTER + "\"\n"
                "<If \"%{REQUEST_URI} =~ m#/m_[0-9a-f]+/#i\">\n"
                "    SetHandler default-handler\n"
                "    DirectoryIndex disabled\n"
                "</If>\n")


def index_schutz_setzen(ordner):
    """Die .htaccess mit INDEX_SCHUTZ in den Webordner legen (nur wenn sie fehlt oder anders lautet, atomar).
    Rueckgabe "" oder der Grund, warum es nicht ging - dann wird die Ablage nicht freigegeben."""
    ziel = os.path.join(ordner, ".htaccess")
    try:
        with open(ziel, "r", encoding="utf-8", newline="") as fh:
            if fh.read(4096) == INDEX_SCHUTZ:
                return ""
    except (OSError, ValueError):
        pass
    vorlaeufig = ziel + ".neu.%d" % os.getpid()
    try:
        with open(vorlaeufig, "w", encoding="utf-8", newline="") as fh:
            fh.write(INDEX_SCHUTZ)
        os.replace(vorlaeufig, ziel)
    except OSError as fehler:
        try:
            os.remove(vorlaeufig)
        except OSError:
            pass
        return "Schutz gegen die Verzeichnisliste (.htaccess) nicht anlegbar (%s)" % fehler
    return ""


def ablage_relativ(eintrag):
    """Ein Pfad in der Medienablage, wie er in Quellenliste, Gong-Feld oder M3U steht: relativ, mit "/" getrennt,
    ohne ".." und ohne fuehrenden Schraegstrich. Rueckgabe der bereinigte Pfad oder None (abgewiesen)."""
    t = str(eintrag or "").strip().replace("\\", "/")
    if t == "" or t.startswith("/") or re.search(r"[\x00-\x1f\x7f]", t) or re.match(r"^[A-Za-z]:", t):
        return None
    teile = [x for x in t.split("/") if x not in ("", ".")]
    if not teile or any(x == ".." for x in teile):
        return None
    return "/".join(teile)


def ablage_wurzeln():
    """Wo eine Medienablage liegen darf: was LoxBerry selbst einhaengt (Speicherverwaltung) - USB-Datentraeger und
    Netzfreigaben unter system/storage sowie /media und /mnt. Als echte Pfade, Verweise aufgeloest."""
    orte = ["/media", "/mnt"]
    if HOME_DIR:
        orte.insert(0, os.path.join(HOME_DIR, "system", "storage"))
    return [os.path.realpath(o) for o in orte]


def ablage_pfad_taugt(pfad, wurzeln=None):
    """Taugt der Pfad als Medienablage? Rueckgabe (echter Pfad, "") oder ("", Grund)."""
    p = str(pfad or "").strip()
    if p == "":
        return "", "keine Medienablage eingetragen"
    # os.path statt "/": dieselbe Regel unter Linux (Geraet) und Windows (Pruefstand).
    if not os.path.isabs(p) or re.search(r"[\x00-\x1f\x7f]", p) or ".." in re.split(r"[\\/]", p):
        return "", "der Pfad muss absolut sein und darf kein '..' enthalten"
    echt = os.path.realpath(p)
    for w in (wurzeln if wurzeln is not None else ablage_wurzeln()):
        if echt == w or echt.startswith(w.rstrip(os.sep) + os.sep):
            if echt == w:
                return "", "die Wurzel selbst ist keine Medienablage - einen Ordner darin waehlen"
            return echt, ""
    return "", "die Medienablage muss unter dem LoxBerry-Speicher (USB/Netzfreigabe) oder /media bzw. /mnt liegen"


def ablage_durchsehen(echt, grenze=ABLAGE_PRUEF_MAX, frist=None, abbrechen=None, fund=None):
    """Den Ordner einmal durchsehen: (zahl der Audiodateien, Grund). Grund nicht leer = nicht freigeben - eine Datei,
    die Apache ausfuehren koennte, oder mehr Eintraege als die Grenze (dann ist nichts bewiesen).
    Nachfassung 6: frist (s) und abbrechen() (Klingel/Ansage) beenden die Durchsicht mit "Durchsicht abgebrochen ..."
    - das ist kein Befund; fund (Liste) bekommt den Pfad des Funds relativ zur Ablage (ohne Pfadschluessel)."""
    ende = (time.monotonic() + frist) if frist is not None else None
    zahl = 0
    gesehen = 0
    try:
        for wurzel, ordner, dateien in os.walk(echt, followlinks=False):
            ordner[:] = sorted(o for o in ordner if not o.startswith("."))
            if abbrechen is not None and abbrechen():
                return zahl, "Durchsicht abgebrochen (Klingel oder Ansage laeuft)"
            if ende is not None and time.monotonic() > ende:
                return zahl, "Durchsicht abgebrochen (Zeitgrenze %d s)" % frist
            # Nachfassung 4 (30.10.2026): Apache folgt Verweisen (FollowSymLinks). Ein Verweis in der Ablage, der
            # hinauszeigt, gaebe ueber den Pfadschluessel fremde Dateien frei; os.walk folgt ihm nicht und saehe ihn
            # sonst nie an. Versteckte Namen liefert Apache ohnehin nicht (.htaccess, RedirectMatch 404).
            for n in ordner + list(dateien):
                voll = os.path.join(wurzel, n)
                if not n.startswith(".") and os.path.islink(voll):
                    ziel = os.path.realpath(voll)
                    if ziel != echt and not ziel.startswith(echt.rstrip(os.sep) + os.sep):
                        rel = os.path.relpath(voll, echt)
                        if fund is not None:
                            fund.append(rel)
                        return zahl, "enthaelt einen Verweis nach ausserhalb der Ablage (%s)" % rel[:80]
            for d in dateien:
                gesehen += 1
                if gesehen > grenze:
                    return zahl, "mehr als %d Eintraege - zu gross zum Durchsehen, kleineren Ordner waehlen" % grenze
                klein = d.lower()
                # Nach dem NAMEN, nicht nach Rechten: auf einer Netzfreigabe (cifs, file_mode=0666) traegt jede Datei
                # dieselben Rechte. ".cgi." mitten im Namen: LoxBerry-Apache hat "AddHandler cgi-script cgi", und
                # mod_mime nimmt jede Endung eines Namens (lied.cgi.mp3 liefe als Programm).
                if klein.startswith(".ht") or klein.endswith(WEB_AUSFUEHRBAR) or ABLAGE_UNSICHER.search(klein):
                    rel = os.path.relpath(os.path.join(wurzel, d), echt)
                    if fund is not None:
                        fund.append(rel)
                    return zahl, "enthaelt eine Datei, die der Webserver ausfuehren koennte (%s)" % rel[:80]
                if inhaltstyp(klein):
                    zahl += 1
    except OSError as fehler:
        return zahl, "nicht lesbar (%s)" % fehler
    return zahl, ""


def _verweis_weg(pfad):
    """Einen Verweis entfernen (nur Selbsttest) - unter Windows ist ein Ordnerverweis mit rmdir zu loeschen."""
    try:
        os.remove(pfad)
    except OSError:
        os.rmdir(pfad)


def ablage_dateisystem(echt, mounts="/proc/mounts"):
    """Das Dateisystem, auf dem die Medienablage liegt (Zusatz Hausherr 30.10.2026: im Reiter Audio anzeigen) - aus
    /proc/mounts, laengster passender Einhaengepunkt. "" = nicht feststellbar (kein Linux, Datei fehlt)."""
    try:
        with open(mounts, "r", encoding="utf-8", errors="replace") as fh:
            zeilen = fh.read(262144).splitlines()
    except OSError:
        return ""
    best, art = "", ""
    for z in zeilen:
        teile = z.split()
        if len(teile) < 3:
            continue
        punkt = teile[1].replace("\\040", " ")
        if (echt == punkt or echt.startswith(punkt.rstrip("/") + "/")) and len(punkt) >= len(best):
            best, art = punkt, teile[2]
    return art[:20]


def ablage_datei(rel):
    """Echter Pfad einer Datei der Medienablage, nur wenn sie DRIN liegt (Verweise aufgeloest) - sonst None."""
    basis = ABLAGE_STAND.get("pfad") or ""
    r = ablage_relativ(rel)
    if not basis or r is None:
        return None
    echt = os.path.realpath(os.path.join(basis, r))
    if not echt.startswith(basis.rstrip(os.sep) + os.sep):
        return None
    return echt


def ablage_adresse(cfg, rel, geraete_ip=""):
    """Die Adresse, unter der der Lautsprecher eine Datei der Medienablage abholt: Grundadresse (Feld der oertlichen
    Ansage) oder http://<eigene Adresse>[:Port]/plugins/<ordner>, dahinter m_<Schluessel>/<Pfad>. "" = nicht moeglich."""
    r = ablage_relativ(rel)
    schluessel = ABLAGE_STAND.get("schluessel") or ""
    if r is None or not ABLAGE_STAND.get("ok") or not ABLAGE_SCHLUESSEL.match(schluessel):
        return ""
    basis = str(cfg.get("tts_lokal_basis", "") or "").strip().rstrip("/")
    if basis == "":
        ip = eigene_ip(geraete_ip or None)
        if ip == "":
            return ""
        port = webport()
        basis = "http://{0}{1}/plugins/{2}".format(ip, "" if port == 80 else ":%d" % port, PLUGIN_NAME)
    return basis + "/m_" + schluessel + "/" + urllib.parse.quote(r, safe="/")


def m3u_lesen(text, ordner_rel=""):
    """Eintraege einer M3U-Liste: [(adresse_oder_relpfad, titel, interpret)]. #EXTINF:<sek>,Interpret - Titel liefert
    die Anzeige; ein relativer Eintrag gilt relativ zum Ordner der Liste. Was die Ablage verlaesst, faellt weg."""
    aus = []
    info = ("", "")
    for zeile in str(text or "").replace("\r", "\n").split("\n"):
        zeile = zeile.strip().lstrip("﻿")
        if zeile == "":
            continue
        if zeile.upper().startswith("#EXTINF:"):
            anzeige = zeile.split(",", 1)[1].strip() if "," in zeile else ""
            if " - " in anzeige:
                interpret, titel = anzeige.split(" - ", 1)
                info = (titel.strip(), interpret.strip())
            else:
                info = (anzeige, "")
            continue
        if zeile.startswith("#"):
            continue
        if re.match(r"^https?://", zeile, re.I):
            aus.append((zeile, info[0], info[1]))
        else:
            rel = ablage_relativ((ordner_rel + "/" if ordner_rel else "") + zeile)
            if rel is not None:
                aus.append((rel, info[0], info[1]))
        info = ("", "")
        if len(aus) >= LISTE_MAX:
            break
    return aus


def pls_lesen(text):
    """Die erste Adresse einer PLS-Liste (File1=...)."""
    treffer = sorted((int(m.group(1)), m.group(2).strip())
                     for m in re.finditer(r"^\s*File([0-9]+)\s*=\s*(\S+)\s*$", str(text or ""), re.I | re.M))
    for _n, adresse in treffer:
        if re.match(r"^https?://", adresse, re.I):
            return adresse
    return ""


def _holen(adresse, frist=5.0, menge=65536):
    """(Inhaltstyp, Anfang des Inhalts) einer Adresse. Liest hoechstens 'menge' Byte - ein Radiostrom endet nie."""
    import urllib.request
    anfrage = urllib.request.Request(adresse, headers={"User-Agent": "Chromecast4LoxNG", "Icy-MetaData": "0"})
    with urllib.request.urlopen(anfrage, timeout=frist) as antwort:
        typ = str(antwort.headers.get("Content-Type", "") or "").split(";", 1)[0].strip().lower()
        if "mpegurl" in typ or "scpls" in typ or "x-pls" in typ or typ in ("text/plain", "application/octet-stream") \
                or adresse.lower().split("?", 1)[0].endswith(LISTEN_ENDUNGEN + (".pls",)):
            return typ, antwort.read(menge).decode("utf-8", "replace")
        return typ, ""


def radio_aufloesen(adresse, holen=None, tiefe=0):
    """Eine Radioadresse abspielfertig machen (S3): .m3u/.pls werden aufgeloest (erste gueltige Zeile), HLS bleibt
    eine Liste mit eigenem Typ, sonst entscheidet der Content-Type der Antwort statt der Endung (AAC spielte sonst
    nicht). Rueckgabe (adresse, typ, hinweis). Antwortet die Adresse nicht, bleibt sie, wie sie ist - der Lautsprecher
    holt selbst ab, und der Hinweis steht im Protokoll."""
    holen = holen or _holen
    endung = adresse.lower().split("?", 1)[0]
    try:
        typ, inhalt = holen(adresse)
    except Exception as fehler:  # noqa: BLE001
        typ, inhalt = "", ""
        hinweis = "Adresse antwortete nicht beim Pruefen (%s) - sie wird trotzdem uebergeben" % fehler
        if endung.endswith(".m3u8"):
            return adresse, HLS_TYP, hinweis
        return adresse, inhaltstyp(endung) or "audio/mpeg", hinweis
    if "#EXT-X-" in inhalt or endung.endswith(".m3u8") and inhalt.lstrip().startswith("#EXTM3U"):
        return adresse, HLS_TYP, ""
    if inhalt and tiefe < 2 and ("scpls" in typ or "x-pls" in typ or endung.endswith(".pls")
                                 or inhalt.lstrip().lower().startswith("[playlist]")):
        neu = pls_lesen(inhalt)
        if neu:
            return radio_aufloesen(neu, holen, tiefe + 1)
        return adresse, "audio/mpeg", "PLS-Liste ohne gueltige Adresse"
    if inhalt and tiefe < 2 and ("mpegurl" in typ or endung.endswith(".m3u")):
        neu = [a for a, _t, _i in m3u_lesen(inhalt) if re.match(r"^https?://", a, re.I)]
        if neu:
            return radio_aufloesen(neu[0], holen, tiefe + 1)
        return adresse, "audio/mpeg", "M3U-Liste ohne gueltige Adresse"
    if typ.startswith("audio/") or typ in ("application/ogg", "video/mp2t"):
        return adresse, typ, ""
    return adresse, inhaltstyp(endung) or "audio/mpeg", ""


def liste_aufbauen(quelle, ordner_lesen=None):
    """Die Titel einer Quelle der Art liste (E7): ein Ordner = alle Audiodateien darin alphabetisch (mit
    Unterordnern), eine M3U-Datei = ihre Reihenfolge. Rueckgabe (eintraege, grund): eintraege [(relpfad_oder_adresse,
    titel, interpret)] hoechstens LISTE_MAX; grund nicht leer = nichts abzuspielen."""
    rel = ablage_relativ(quelle.get("adresse"))
    if rel is None:
        return [], "Adresse der Liste ist kein Pfad in der Medienablage"
    echt = ablage_datei(rel)
    if echt is None:
        return [], "Medienablage nicht eingerichtet oder Pfad ausserhalb"
    if os.path.isdir(echt):
        eintraege = []
        for wurzel, ordner, dateien in os.walk(echt):
            ordner[:] = sorted(o for o in ordner if not o.startswith("."))
            for d in sorted(dateien, key=lambda x: x.lower()):
                if d.startswith(".") or not inhaltstyp(d):
                    continue
                pfad = os.path.relpath(os.path.join(wurzel, d), ABLAGE_STAND["pfad"]).replace(os.sep, "/")
                eintraege.append((pfad, os.path.splitext(d)[0], ""))
                if len(eintraege) >= LISTE_MAX:
                    break
            if len(eintraege) >= LISTE_MAX:
                break
        return (eintraege, "") if eintraege else ([], "keine Audiodatei im Ordner")
    if os.path.isfile(echt) and rel.lower().endswith(LISTEN_ENDUNGEN):
        try:
            with open(echt, "r", encoding="utf-8", errors="replace") as fh:
                text = fh.read(1024 * 1024)
        except OSError as fehler:
            return [], "Liste nicht lesbar (%s)" % fehler
        eintraege = m3u_lesen(text, os.path.dirname(rel))
        return (eintraege, "") if eintraege else ([], "keine gueltige Zeile in der Liste")
    return [], "weder Ordner noch M3U-Datei"


def _klang_web():
    """Der Klangordner im unangemeldeten Webordner (html/plugins/<ordner>/klang). "" = keine Anlage."""
    return _KLANG_ORTE.get("web") or (os.path.join(HTML_DIR, "klang") if HTML_DIR else "")


def _klang_eigen_ordner():
    """Wo der eigene Gong liegt (config/plugins/<ordner>/klaenge - die Oberflaeche legt ihn dort ab)."""
    return _KLANG_ORTE.get("eigen") or (os.path.join(CONFIG_DIR, "klaenge") if CONFIG_DIR else "")


def standard_gong_wav():
    """Der mitgelieferte Standard-Gong (Nachfassung 7) als WAV - PCM 16 bit, mono, 22050 Hz, 2,8 s. Vom Plugin selbst
    berechnet, also ohne fremde Rechte: 0,4 s Stille (Googles eigener Ton beim Start einer Wiedergabe und das
    Anlaufen), dann ein Zweiklang e'' - c'' mit weichem Einsatz (40 ms) und Abklingen, die letzten 0,15 s ausgeblendet -
    so knackt nichts. Spitze knapp 60 % der Vollaussteuerung (gemessen 19115 von 32767); laut macht die Klingel ihr
    Pegel, nicht die Datei."""
    import struct
    werte = []
    toene = ((0.40, 659.255), (1.00, 523.251))
    for i in range(_GONG_PROBEN):
        t = i / float(_GONG_RATE)
        s = 0.0
        for beginn, f in toene:
            d = t - beginn
            if d < 0:
                continue
            huelle = math.exp(-d / 0.55)
            if d < 0.04:
                huelle *= 0.5 - 0.5 * math.cos(math.pi * d / 0.04)
            w = 2.0 * math.pi * f * d
            s += huelle * (math.sin(w) + 0.3 * math.sin(2.0 * w) + 0.1 * math.sin(3.0 * w))
        rest = (_GONG_PROBEN - i) / float(_GONG_RATE)
        if rest < 0.15:
            s *= rest / 0.15
        werte.append(int(round(max(-1.0, min(1.0, s * 0.42)) * 32767)))
    daten = struct.pack("<%dh" % _GONG_PROBEN, *werte)
    kopf = struct.pack("<4sI4s4sIHHIIHH4sI", b"RIFF", 36 + len(daten), b"WAVE", b"fmt ", 16, 1, 1, _GONG_RATE,
                       _GONG_RATE * 2, 2, 16, b"data", len(daten))
    return kopf + daten


def klang_art(daten, endung):
    """Passt der Anfang des Inhalts zur Endung? WAV RIFF/WAVE, OGG OggS, MP3 ID3 oder Rahmenkopf. Dieselbe Regel
    prueft die Oberflaeche beim Hochladen (cc_klang_art)."""
    k = bytes(daten[:12])
    if endung == ".wav":
        return k[:4] == b"RIFF" and k[8:12] == b"WAVE"
    if endung == ".ogg":
        return k[:4] == b"OggS"
    if endung == ".mp3":
        return k[:3] == b"ID3" or (len(k) >= 2 and k[0] == 0xFF and (k[1] & 0xE0) == 0xE0)
    return False


def _klang_schreiben(name, daten):
    """Eine Datei in den Klangordner legen: 0644, erst vollstaendig, dann umbenannt (je Faden eine eigene
    Zwischendatei - mehrere Lautsprecher klingeln gleichzeitig). Rueckgabe "" oder Grund."""
    ordner = _klang_web()
    if not ordner:
        return "kein Webordner"
    try:
        os.makedirs(ordner, exist_ok=True)
        ziel = os.path.join(ordner, name)
        neu = "%s.%d.neu" % (ziel, threading.get_ident())
        fd = os.open(neu, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o644)
        if hasattr(os, "fchmod"):
            os.fchmod(fd, 0o644)
        with os.fdopen(fd, "wb") as fh:
            fh.write(daten)
        os.replace(neu, ziel)
    except OSError as fehler:
        return "Klangordner nicht beschreibbar (%s)" % fehler
    return ""


def klang_standard():
    """Name des Standard-Gongs im Klangordner; legt ihn an, wenn er fehlt oder nicht die richtige Groesse hat.
    "" = nicht moeglich (steht im Protokoll)."""
    ordner = _klang_web()
    if not ordner:
        return ""
    try:
        if os.path.getsize(os.path.join(ordner, KLANG_STANDARD)) == 44 + 2 * _GONG_PROBEN:
            return KLANG_STANDARD
    except OSError:
        pass
    grund = _klang_schreiben(KLANG_STANDARD, standard_gong_wav())
    if grund:
        log.warning("Standard-Gong nicht anlegbar: %s", grund)
        return ""
    return KLANG_STANDARD


def klang_eigen():
    """Der eigene Gong: (Pfad, Endung) oder None. Nur eigener_gong.<mp3|ogg|wav>, kein Verweis, hoechstens KLANG_MAX
    Byte, Inhalt passend zur Endung."""
    ordner = _klang_eigen_ordner()
    if not ordner or not os.path.isdir(ordner):
        return None
    try:
        namen = sorted(n for n in os.listdir(ordner) if KLANG_EIGEN_NAME.match(n))
    except OSError:
        return None
    for n in namen:
        p = os.path.join(ordner, n)
        if os.path.islink(p) or not os.path.isfile(p):
            continue
        try:
            if os.path.getsize(p) > KLANG_MAX:
                continue
            with open(p, "rb") as fh:
                kopf = fh.read(12)
        except OSError:
            continue
        endung = os.path.splitext(n)[1]
        if klang_art(kopf, endung):
            return p, endung
    return None


def klang_eigen_bereitstellen(eigen):
    """Den eigenen Gong in den Klangordner legen - Name aus dem Inhalt (eigen_<16 hex>.<endung>), ein neuer Gong hat
    also eine neue Adresse und kommt nie aus einem Zwischenspeicher. Aeltere eigene Klaenge dort fallen weg; eigen None
    = nur aufraeumen. Rueckgabe der Name oder ""."""
    ordner = _klang_web()
    name = ""
    if eigen is not None and ordner:
        try:
            with open(eigen[0], "rb") as fh:
                daten = fh.read(KLANG_MAX + 1)
        except OSError as fehler:
            log.warning("Eigener Gong nicht lesbar: %s", fehler)
            daten = b""
        if daten and len(daten) <= KLANG_MAX:
            name = "eigen_" + hashlib.sha256(daten).hexdigest()[:16] + eigen[1]
            if not os.path.isfile(os.path.join(ordner, name)):
                grund = _klang_schreiben(name, daten)
                if grund:
                    log.warning("Eigener Gong nicht bereitstellbar: %s", grund)
                    name = ""
    if ordner and os.path.isdir(ordner):
        try:
            for n in os.listdir(ordner):
                if KLANG_WEB_EIGEN.match(n) and n != name:
                    os.remove(os.path.join(ordner, n))
        except OSError:
            pass
    return name


def web_basis(cfg, geraete_ip=""):
    """Grundadresse des eigenen Webordners fuer den Lautsprecher: Feld der oertlichen Ansage oder
    http://<eigene Adresse>[:Port]/plugins/<ordner> (wie ablage_adresse()). "" = nicht ermittelbar."""
    basis = str(cfg.get("tts_lokal_basis", "") or "").strip().rstrip("/")
    if basis:
        return basis
    ip = eigene_ip(geraete_ip or None)
    if ip == "":
        return ""
    port = webport()
    return "http://{0}{1}/plugins/{2}".format(ip, "" if port == 80 else ":%d" % port, PLUGIN_NAME)


def klingel_gong(cfg, geraete_ip=""):
    """(Adresse, Inhaltstyp, Hinweis) des Klingel-Gongs. Nachfassung 7: nie stumm.
      1. Feld klingel_gong (leer und kein eigener Gong: der Gong der Ansage, tts_gong - wie bisher): eine
         http(s)-Adresse gilt, wie sie ist; eine Datei der Medienablage, wenn es sie dort gibt (freigegeben).
      2. sonst der eigene Gong (Reiter Audio), 3. sonst der mitgelieferte Standard-Gong.
    Hinweis nicht leer = das Feld nannte eine Datei, die es in der freigegebenen Ablage nicht gibt - fuers Protokoll.
    None nur, wenn sich die Adresse des eigenen Webservers nicht ermitteln laesst (dann Feld Grundadresse)."""
    eigen = klang_eigen()
    g = str(cfg.get("klingel_gong", "") or "").strip()
    if g == "" and eigen is None:
        g = str(cfg.get("tts_gong", "") or "").strip()
    hinweis = ""
    if g != "":
        if re.match(r"^https?://", g, re.I):
            return g, inhaltstyp(g) or "audio/mpeg", ""
        typ = inhaltstyp(g)
        echt = ablage_datei(g)
        if typ is not None and echt is not None and os.path.isfile(echt):
            url = ablage_adresse(cfg, g, geraete_ip)
            if url:
                return url, typ, ""
        if typ is None:
            grund = "ist keine Audiodatei"
        elif not ABLAGE_STAND.get("ok"):
            grund = "nicht abspielbar (Medienablage nicht freigegeben)"
        else:
            grund = "fehlt in der Medienablage"
        hinweis = "Klingel-Gong '%s' %s" % (g[:80], grund)
    basis = web_basis(cfg, geraete_ip)
    if eigen is not None:
        name = klang_eigen_bereitstellen(eigen)
        if name and basis:
            return (basis + "/klang/" + name, KLANG_ENDUNGEN[eigen[1]],
                    (hinweis + " - es klingt der eigene Gong") if hinweis else "")
        hinweis = (hinweis + "; " if hinweis else "") + "eigener Gong nicht bereitstellbar"
    else:
        klang_eigen_bereitstellen(None)
    name = klang_standard()
    if name and basis:
        return basis + "/klang/" + name, "audio/wav", (hinweis + " - es klingt der Standard-Gong") if hinweis else ""
    return None


def zone_nummer(kennung):
    """Zonennummer aus einem Befehlsnamen zone_<n> - sonst None."""
    m = ZONE_KENNUNG.match(str(kennung or "").strip().lower())
    if not m:
        return None
    n = int(m.group(1))
    return n if 1 <= n <= ZONEN_MAX else None


def sichtbar_merken(geraete):
    """Gesehene Cast-Geraete festhalten (S2): CastInfo-Werte aus browser.devices. Nur Name und Modell."""
    try:
        werte = list(geraete.values()) if hasattr(geraete, "values") else list(geraete)
    except Exception:  # noqa: BLE001
        return
    for info in werte:
        name = str(getattr(info, "friendly_name", "") or "")
        if name:
            SICHTBAR[name[:80]] = str(getattr(info, "model_name", "") or "")[:60]
    if len(SICHTBAR) > 200:
        for k in list(SICHTBAR)[:len(SICHTBAR) - 200]:
            del SICHTBAR[k]


def sichtbar_fremd(eingetragen):
    """Im Netz gesehen, aber nicht eingetragen: Liste von dict(name, modell), nach Name."""
    return [{"name": n, "modell": SICHTBAR[n]} for n in sorted(SICHTBAR) if n not in eingetragen][:40]


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


def webport():
    """Der Port des LoxBerry-Webservers aus general.json (Abschnitt Webserver bzw. WEBSERVER, Schluessel Port),
    Rueckfall 80 - wie cc_webport() in cc_lib.php (Fremdbefund C11, 1.3.24). Bis 1.3.23 baute die oertliche
    Ansage ihre Adresse ohne Port; bei einem Webserver-Port ungleich 80 bekam der Lautsprecher eine Adresse, unter
    der nichts antwortet (Pruefbericht C11)."""
    pfad = os.path.join(HOME_DIR, "config", "system", "general.json") if HOME_DIR else ""
    try:
        with open(pfad, "r", encoding="utf-8") as fh:
            daten = json.load(fh)
    except (OSError, ValueError):
        return 80
    if not isinstance(daten, dict):
        return 80
    for abschnitt in ("Webserver", "WEBSERVER"):
        block = daten.get(abschnitt)
        if not isinstance(block, dict):
            continue
        try:
            port = int(str(block.get("Port") or "0").strip())
        except (TypeError, ValueError):
            port = 0
        if port > 0:
            return port
    return 80


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

def praefix_taugt(praefix):
    """Taugt das Themenpraefix fuer MQTT? (seit 1.3.13, M9)

    Nicht leer, kein Platzhalter (# +), kein Leerraum oder Steuerzeichen,
    kein Schraegstrich am Rand und keine leere Ebene. Bis 1.3.12 nahm der
    Dienst jedes Praefix: mit "haus/#" starb der Netzfaden von paho, und MQTT
    war still tot (gemessen 30.09.2026, Pruefung MQTT M9). Die Oberflaeche
    prueft enger (nur Buchstaben, Ziffern, _ und -); der Dienst weist nur ab,
    was MQTT bricht - ein altes Praefix mit Punkt laeuft weiter."""
    p = str(praefix or "")
    if p == "" or len(p) > 128:
        return False
    if "#" in p or "+" in p or re.search(r"[\s\x00-\x1f\x7f]", p):
        return False
    return not (p.startswith("/") or p.endswith("/") or "//" in p)


def _merker_datei():
    """Das zuletzt benutzte Praefix (seit 1.3.13, M8) - NEBEN dem Datenordner,
    damit es ein Upgrade uebersteht; uninstall raeumt es ab."""
    if not DATA_DIR:
        return ""
    return os.path.join(os.path.dirname(DATA_DIR.rstrip("/")),
                        PLUGIN_NAME + ".mqtt_praefix")


def praefix_merker_lesen():
    datei = _merker_datei()
    if not datei:
        return ""
    try:
        with open(datei, "r", encoding="utf-8") as fh:
            return fh.read(256).strip()
    except OSError:
        return ""


def praefix_merker_schreiben(praefix):
    datei = _merker_datei()
    if not datei:
        return False
    vorlaeufig = "%s.neu.%d" % (datei, os.getpid())
    try:
        with open(vorlaeufig, "w", encoding="utf-8") as fh:
            fh.write(str(praefix) + "\n")
        os.replace(vorlaeufig, datei)
        return True
    except OSError as fehler:
        try:
            os.remove(vorlaeufig)
        except OSError:
            pass
        log.warning("Merker des MQTT-Praefixes %s nicht schreibbar: %s", datei, fehler)
        return False


def miniserver_adressen():
    """Die Adressen der Miniserver aus general.json (seit 1.3.13, M10).

    Nur sie und 127.0.0.1 duerfen dem UDP-Eingang Befehle schicken. Ein
    Name statt einer Adresse wird EINMAL aufgeloest; laesst er sich nicht
    aufloesen, fehlt er in der Liste - dann nimmt der Eingang nur noch, was
    von 127.0.0.1 kommt, und das steht im Protokoll."""
    aus = set(["127.0.0.1"])
    pfad = os.path.join(HOME_DIR, "config", "system", "general.json") if HOME_DIR else ""
    try:
        with open(pfad, "r", encoding="utf-8") as fh:
            daten = json.load(fh)
    except (OSError, ValueError) as fehler:
        log.warning("general.json nicht lesbar (%s) - UDP-Befehle nur von 127.0.0.1", fehler)
        return aus
    for _nr, ms in (daten.get("Miniserver") or {}).items():
        if not isinstance(ms, dict):
            continue
        adresse = str(ms.get("Ipaddress") or ms.get("IPAddress") or "").strip()
        if adresse == "":
            continue
        try:
            socket.inet_aton(adresse)
            if re.match(r"^\d{1,3}(\.\d{1,3}){3}$", adresse):
                aus.add(adresse)
                continue
        except OSError:
            pass
        try:
            for eintrag in socket.getaddrinfo(adresse, None, socket.AF_INET):
                aus.add(eintrag[4][0])
        except (OSError, UnicodeError) as fehler:
            log.warning("Miniserver-Adresse '%s' laesst sich nicht aufloesen (%s) - "
                        "von dort werden keine UDP-Befehle angenommen.", adresse, fehler)
    return aus


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


# a1 (Verbesserungsbau 30.09.2026): so oft wird ein retained Befehl je
# Verbindung geloescht und nachgelesen, bevor er als "steht" gemeldet wird.
BEFEHL_ABRAEUMEN_VERSUCHE = 3


class MqttAnbindung:
    """Duenne Huelle um paho-mqtt. Faellt still aus, wenn die Bibliothek
    oder das Gateway fehlt - der UDP-Weg funktioniert dann weiter."""

    def __init__(self, praefix, befehl_rueckruf, cmd_ein=True):
        self.praefix = praefix
        self.befehl_rueckruf = befehl_rueckruf
        # Gruppe 6b (1.3.24, Entwurf cc 3.4): der alte Befehlsweg <praefix>/+/cmd/# nur mit dem Haken
        # befehle_cmd_ein. Ohne ihn wird nicht abonniert (Befehle laufen ueber die Befehlsbruecke), und was im
        # Fenster beim Abbestellen noch ankommt, wird verworfen. cmd_zuletzt: Zeit des letzten Befehls ueber den
        # alten Weg (zustand.json, Hinweis im Reiter MQTT).
        self.cmd_ein = bool(cmd_ein)
        self.cmd_zuletzt = 0
        self.client = None
        self.verbunden = False
        # Nach einer Neuverbindung muessen ALLE Werte erneut gesendet
        # werden: der Broker kann seine retained-Werte verloren haben.
        self.neumeldung_faellig = False
        self.verluste = 0
        # Gezaehlt wird erst ab der ersten Anmeldung (seit 1.3.13). Bis 1.3.12
        # zaehlte der erste Durchgang vor dem Verbinden mit, und server/verluste
        # stand nach jedem Start auf einer Zahl ueber 0 - ein Fehlalarm fuer
        # jeden, der in Loxone darauf prueft (Pruefung MQTT, Hinweis).
        self.jemals_verbunden = False
        # Verworfene retained Befehle, einmal je Thema und Verbindung gemeldet.
        self.retained_gemeldet = set()
        # Retained Befehle abraeumen (Verbesserungsbau 30.09.2026, a1):
        #   befehl_offen     Thema -> Zeit, zuletzt RETAINED ueber das Abo
        #                    gekommen; das Nachlesen fuellt es erneut
        #   befehl_versuche  Thema -> Loeschversuche in DIESER Verbindung
        #   im_abraeumen     Thema -> [Frist, erwartete Rueckmeldungen]: die
        #                    leere Nachricht, die der Broker nach dem Loeschen
        #                    an das eigene Abo weiterreicht, ist kein Befehl
        #   befehle_abgeraeumt / befehle_stehen  fuer zustand.json und Test
        self.abraeum_schloss = threading.Lock()
        self.befehl_offen = {}
        self.befehl_versuche = {}
        self.im_abraeumen = {}
        self.befehle_abgeraeumt = 0
        self.befehle_stehen = []

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

        # Die Fassung der Rueckruf-Schnittstelle wird ABGETASTET, nicht
        # angenommen. Reihenfolge: VERSION2, sonst VERSION1, sonst gar kein
        # Argument (paho 1.x kennt die Aufzaehlung nicht).
        #
        # Bis 1.3.6 stand hier VERSION1 zuerst. Am Geraet gemessen am
        # 06.09.2026 mit paho-mqtt 2.1.0: das legt bei JEDEM Dienststart
        # eine Zeile "Callback API version 1 is deprecated" in die
        # Protokolldatei. Unter VERSION2 kommt sie nicht.
        #
        # Voraussetzung dafuer waren die fassungsfesten Rueckrufe darunter -
        # VERSION2 uebergibt andere Argumente. Siehe _grund().
        self.client = None
        for fassung in ("VERSION2", "VERSION1"):
            merkmal = getattr(mqtt.CallbackAPIVersion, fassung, None) \
                if hasattr(mqtt, "CallbackAPIVersion") else None
            if merkmal is None:
                continue
            try:
                self.client = mqtt.Client(merkmal)
                break
            except (AttributeError, TypeError, ValueError):
                self.client = None
        if self.client is None:
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

    @staticmethod
    def _grund(rest):
        """Den Anmelde- bzw. Trenngrund aus dem holen, was paho uebergibt.

        Am Geraet gemessen (paho 2.1.0): die beiden Fassungen uebergeben
        NICHT dasselbe.

            on_connect     VERSION1: (flags, rc)
                           VERSION2: (ConnectFlags, ReasonCode, Properties)
            on_disconnect  VERSION1: (rc,)
                           VERSION2: (DisconnectFlags, ReasonCode, Properties)

        Der Grund steht also einmal an erster, einmal an zweiter Stelle. Er
        wird deshalb gesucht, nicht abgezaehlt: das erste Element, das sich
        mit einer Zahl vergleichen laesst und keine Flags-Struktur ist.

        "rc != 0" traegt auf beiden - auf dem int von VERSION1 und auf der
        ReasonCode von VERSION2 (gemessen: False bei Erfolg).
        """
        for wert in rest:
            name = type(wert).__name__
            if name in ("ConnectFlags", "DisconnectFlags", "Properties", "dict"):
                continue
            return wert
        return 0

    def _on_connect(self, client, userdata, *rest):
        rc = self._grund(rest)
        if rc != 0:
            log.error("MQTT-Anmeldung abgelehnt (%s).", mqtt_anmeldegrund(rc))
            return
        self.verbunden = True
        self.jemals_verbunden = True
        self.neumeldung_faellig = True
        self.retained_gemeldet = set()
        # a1: je Verbindung wieder bis zu drei Versuche; das Abo liefert jeden
        # noch zurueckbehaltenen Befehl erneut.
        with self.abraeum_schloss:
            self.befehl_versuche = {}
            self.befehle_stehen = []
        thema = self.praefix + "/+/cmd/#"
        if self.cmd_ein:
            client.subscribe(thema)
            log.info("MQTT verbunden, Befehle abonniert: %s (alter Befehlsweg)", thema)
        else:
            log.info("MQTT verbunden; der alte Befehlsweg %s ist aus (befehle_cmd_ein=0) - Befehle laufen ueber "
                     "die Befehlsbruecke", thema)
        self.senden("server/online", "1")

    def cmd_umschalten(self, ein):
        """Den alten Befehlsweg zur Laufzeit an- oder abschalten (Entwurf cc 3.4: verbindungen_nachziehen).
        Verbunden wird sofort abonniert bzw. abbestellt; sonst gilt der Wert beim naechsten Verbinden."""
        ein = bool(ein)
        if ein == self.cmd_ein:
            return
        self.cmd_ein = ein
        thema = self.praefix + "/+/cmd/#"
        if self.client is not None and self.verbunden:
            try:
                if ein:
                    self.client.subscribe(thema)
                else:
                    self.client.unsubscribe(thema)
            except Exception as fehler:  # noqa: BLE001
                log.warning("MQTT: alter Befehlsweg liess sich nicht %s: %s",
                            "abonnieren" if ein else "abbestellen", fehler)
        log.info("Alter Befehlsweg %s jetzt %s (befehle_cmd_ein)", thema, "an" if ein else "aus")

    def _on_disconnect(self, client, userdata, *rest):
        self.verbunden = False
        log.warning("MQTT-Verbindung getrennt (Code %s), Wiederaufbau laeuft",
                    self._grund(rest))

    def _on_message(self, client, userdata, nachricht):
        try:
            teile = nachricht.topic.split("/")
            # <praefix>/<geraet>/cmd/<befehl>
            if len(teile) < 4 or teile[-2] != "cmd":
                return
            # Gruppe 6b (1.3.24): ohne den Haken des alten Wegs nichts annehmen - auch nicht, was im Fenster
            # beim Abbestellen noch ankommt.
            if not self.cmd_ein:
                log.debug("Alter Befehlsweg aus - %s verworfen", nachricht.topic)
                return
            geraet = teile[-3]
            befehl = teile[-1]
            # Ein RETAINED Befehl wird verworfen (seit 1.3.13, M1). Bis 1.3.12
            # fuehrte der Dienst ihn bei jedem Verbinden erneut aus - eine
            # Ansage, "volume 100" oder eine Adresse, nach jedem Neustart und
            # jeder Neuverbindung (gemessen 30.09.2026, Pruefung MQTT M1).
            # Befehle sind Ereignisse, keine Zustaende. uninstall und der
            # Praefixwechsel raeumen solche Themen ab (eigenes_thema()).
            if getattr(nachricht, "retain", False):
                if nachricht.topic not in self.retained_gemeldet:
                    self.retained_gemeldet.add(nachricht.topic)
                    log.warning("Retained Befehl verworfen, nicht ausgefuehrt: %s "
                                "(ein Befehl wird ohne Retain gesendet)", nachricht.topic)
                # a1 (Verbesserungsbau 30.09.2026): zum Abraeumen vormerken -
                # nur einen EIGENEN Befehl aus der Befehlsliste, nie ein
                # fremdes Thema. Geloescht wird im Takt des Dienstes
                # (befehle_abraeumen), nicht hier im Netzfaden von paho.
                if nachricht.payload and eigenes_thema(self.praefix, nachricht.topic):
                    with self.abraeum_schloss:
                        self.befehl_offen[nachricht.topic] = time.time()
                return
            # a1: die leere Nachricht, die der Broker nach dem EIGENEN
            # Abraeumen an dieses Abo weiterreicht, ist kein Befehl.
            if not nachricht.payload and self._eigene_loeschung(nachricht.topic):
                log.debug("Rueckmeldung des Abraeumens, kein Befehl: %s", nachricht.topic)
                return
            nutzlast = nachricht.payload.decode("utf-8", "replace").strip()
            # Eine LEERE Nachricht ist nie ein Befehl (Entscheidung Nr. 18,
            # 01.10.2026). So sieht die Loeschung eines retained Themas aus -
            # loeschte jemand anderes ein retained cmd/-Thema, lief bis hierher
            # z. B. volume_up los. Ueber MQTT braucht jeder Befehl einen Wert
            # (z. B. 1); die Ausgangsvorlage sendet immer einen. UDP bleibt, wie
            # es ist.
            if nutzlast == "":
                log.info("Leere Nachricht auf %s verworfen, nicht ausgefuehrt "
                         "(ueber MQTT braucht ein Befehl einen Wert, z. B. 1)",
                         nachricht.topic)
                return
            log.info("MQTT-Befehl %s -> %s %s", geraet, befehl, nutzlast)
            self.cmd_zuletzt = int(time.time())
            self.befehl_rueckruf(geraet, befehl, nutzlast)
        except Exception as fehler:  # noqa: BLE001
            log.error("MQTT-Nachricht nicht verarbeitbar: %s", fehler)

    def senden(self, unterthema, wert, retain=True):
        if not self.client:
            return False
        text = str(wert)
        # Ein LEERER Wert geht nie retained hinaus: eine leere Nutzlast mit
        # retain loescht das zurueckbehaltene Thema im Broker (Regeln/07, am
        # Broker belegt 14.09.2026). app, title, artist, album ("" ohne
        # laufende Wiedergabe) und last_error ("" ohne Fehler) verschwanden bis
        # 1.3.8 damit aus dem Broker, obwohl die Themenliste sie retained fuehrt.
        try:
            erg = self.client.publish(self.praefix + "/" + unterthema,
                                      text, qos=0, retain=bool(retain) and text != "")
        except Exception as fehler:  # noqa: BLE001
            log.error("MQTT-Veroeffentlichung fehlgeschlagen: %s", fehler)
            return False
        # Der Rueckgabewert wird ausgewertet. Bei qos=0 und getrennter
        # Verbindung ist die Nachricht verloren - das gehoert gezaehlt und
        # nicht verschwiegen.
        rc = getattr(erg, "rc", 0)
        if rc != 0:
            if not self.jemals_verbunden:
                return False
            self.verluste += 1
            if self.verluste in (1, 10, 100) or self.verluste % 1000 == 0:
                log.warning("MQTT: %d Nachricht(en) nicht abgesetzt (letzter Code %s). "
                            "Laeuft das MQTT-Gateway?", self.verluste, rc)
            return False
        return True

    def senden_und_warten(self, unterthema, wert, frist=2.0):
        """Retained senden und auf die Uebergabe an den Broker warten
        (seit 1.3.13, M4) - noetig, bevor ein zweiter Client dasselbe Thema
        abraeumt und nachliest."""
        if not self.client or not self.verbunden:
            return False
        try:
            info = self.client.publish(self.praefix + "/" + unterthema, str(wert),
                                       qos=1, retain=True)
            try:
                info.wait_for_publish(frist)
            except TypeError:           # paho 1.x vor 1.6 kennt keine Frist
                info.wait_for_publish()
            return bool(getattr(info, "is_published", lambda: True)())
        except Exception as fehler:  # noqa: BLE001
            log.error("MQTT-Veroeffentlichung fehlgeschlagen: %s", fehler)
            return False

    def abraeumen(self, unterthema):
        """Ein zurueckbehaltenes Thema im Broker loeschen (leere Nutzlast,
        retain). Fuer Themen, die frueher retained gingen und es nicht mehr
        tun - sonst liegt ihr alter Wert dort weiter (Regeln/07)."""
        if not self.client:
            return False
        try:
            self.client.publish(self.praefix + "/" + unterthema, "", qos=0, retain=True)
        except Exception as fehler:  # noqa: BLE001
            log.error("MQTT-Abraeumen fehlgeschlagen: %s", fehler)
            return False
        return True

    def _eigene_loeschung(self, thema):
        """Ist diese leere Nachricht die Rueckmeldung eines eigenen
        Loeschens? Jede Loeschung erwartet genau eine; abgelaufene Eintraege
        fallen weg, damit ein spaeterer echter Befehl mit leerer Nutzlast
        (volume_up ohne Wert) wieder ausgefuehrt wird."""
        jetzt = time.time()
        with self.abraeum_schloss:
            for t in [t for t, e in self.im_abraeumen.items() if e[0] < jetzt]:
                del self.im_abraeumen[t]
            eintrag = self.im_abraeumen.get(thema)
            if not eintrag:
                return False
            eintrag[1] -= 1
            if eintrag[1] <= 0:
                del self.im_abraeumen[thema]
            return True

    def befehle_abraeumen(self, warten):
        """Retained Befehle am Broker abraeumen und NACHLESEN (seit dem
        Verbesserungsbau 30.09.2026, a1).

        Bis 1.3.14 wurde ein retained Befehl unter cmd/ nur verworfen und
        blieb im Broker stehen: nach jedem Verbinden kam er wieder, und ein
        Werkzeug oder Plugin, das dasselbe Thema abonniert, sah ihn als
        gueltig. Geloescht wird mit leerer Nutzlast und Retain, QoS 1.
        Erledigt ist ein Thema erst, wenn es nach einem ERNEUTEN Abonnement
        nicht wieder zurueckbehalten ankommt - der Rueckgabewert von publish()
        sagt nur, dass etwas abging (Regeln/07, Nachtrag 19.09.2026).
        Hoechstens BEFEHL_ABRAEUMEN_VERSUCHE je Thema und Verbindung.

        warten: Funktion(sekunden) -> True, wenn der Dienst enden soll.
        Laeuft im Takt des Dienstes, nie im Netzfaden von paho (dort wuerde
        wait_for_publish() auf sich selbst warten)."""
        with self.abraeum_schloss:
            offen = sorted(t for t in self.befehl_offen
                           if self.befehl_versuche.get(t, 0) < BEFEHL_ABRAEUMEN_VERSUCHE)
            self.befehl_offen.clear()
        if not offen or not self.client or not self.verbunden:
            return
        filter_thema = self.praefix + "/+/cmd/#"
        while offen:
            with self.abraeum_schloss:
                for t in offen:
                    self.befehl_versuche[t] = self.befehl_versuche.get(t, 0) + 1
                    eintrag = self.im_abraeumen.setdefault(t, [0.0, 0])
                    eintrag[0] = time.time() + 30
                    eintrag[1] += 1
            for t in offen:
                try:
                    info = self.client.publish(t, b"", qos=1, retain=True)
                    try:
                        info.wait_for_publish(5)
                    except TypeError:           # paho 1.x vor 1.6 kennt keine Frist
                        info.wait_for_publish()
                except Exception as fehler:  # noqa: BLE001
                    log.warning("MQTT: retained Befehl %s nicht abraeumbar: %s", t, fehler)
            # NACHLESEN: ein erneutes Abonnement desselben Filters bekommt vom
            # Broker alles, was noch zurueckbehalten steht (MQTT 3.1.1, 3.8.4).
            try:
                self.client.subscribe(filter_thema)
            except Exception as fehler:  # noqa: BLE001
                log.warning("MQTT: Nachlesen der Befehle nicht moeglich: %s", fehler)
                return
            if warten(2.0) or not self.verbunden:
                return
            with self.abraeum_schloss:
                wieder = sorted(t for t in offen if t in self.befehl_offen)
                for t in offen:
                    self.befehl_offen.pop(t, None)
                erschoepft = [t for t in wieder
                              if self.befehl_versuche.get(t, 0) >= BEFEHL_ABRAEUMEN_VERSUCHE]
                weg = [t for t in offen if t not in wieder]
                self.befehle_abgeraeumt += len(weg)
                self.befehle_stehen = sorted((set(self.befehle_stehen) - set(weg))
                                             | set(erschoepft))
            if weg:
                log.info("MQTT: %d retained Befehl(e) am Broker abgeraeumt und nachgelesen: %s",
                         len(weg), ", ".join(weg[:5]))
            if erschoepft:
                log.warning("MQTT: %d retained Befehl(e) stehen nach %d Versuchen noch im "
                            "Broker: %s - von Hand loeschen (MQTT Finder des Gateways). "
                            "Bis zur naechsten Verbindung wird es nicht erneut versucht.",
                            len(erschoepft), BEFEHL_ABRAEUMEN_VERSUCHE,
                            ", ".join(erschoepft[:5]))
            offen = [t for t in wieder if t not in erschoepft]

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
# Zurueckbehaltene Themen am Broker loeschen - UND NACHLESEN (seit 1.3.12)
# ---------------------------------------------------------------------------
#
# Zwei Aufrufer: uninstall/uninstall ("--mqtt-leeren") und der Dienst selbst
# bei einem Praefixwechsel (Dienst.verbindungen_nachziehen). Entschieden am
# 18.09.2026 (Regeln/07, Abschnitt 3): der Letzte Wille server/online darf
# retained sein, wenn die Deinstallation das Thema abraeumt - sonst bliebe
# die 0 eines entfernten Plugins fuer immer im Broker. Bis 1.3.11 raeumte
# uninstall nichts ab (klasse-E/Dienstzustand-retained_2026-09-19.md).
#
# Geloescht wird mit leerer Nutzlast und Retain, QoS 1; danach ein neues
# Abonnement: was dann noch zurueckbehalten ankommt, ist stehengeblieben
# (der Rueckgabewert von publish() sagt nur, dass etwas abging - Regeln/07,
# Nachtrag 19.09.2026). Ein Abonnement gilt erst mit seinem SUBACK; 0x80
# heisst, der Broker verweigert das Lesen - dann ist er "nicht zu fragen",
# nie "nichts zurueckbehalten". Ebenso eine abgewiesene Anmeldung (Muster 11
# der Nachlese; Bauart Bewaesserung 0.9.34 _broker_leeren(),
# Beschattungswaechter 0.9.21).
#
# Zur Linie gehoert <praefix>/server/<dienstthema> und
# <praefix>/<geraet>/<geraetethema> nach bin/cc_themen.json - auch fuer ein
# Geraet, das nicht mehr in den Einstellungen steht. Seit 1.3.13 (M1) auch
# ein RETAINED Befehl <praefix>/<geraet>/cmd/<befehl> aus der Befehlsliste:
# der Dienst fuehrt ihn nicht mehr aus, und stehen bleiben soll er auch
# nicht. Jedes fremde Thema unter demselben Praefix bleibt stehen.

def eigenes_thema(praefix, thema):
    """Gehoert dieses Thema zu dieser Linie?"""
    if not thema.startswith(praefix + "/"):
        return False
    teile = thema[len(praefix) + 1:].split("/")
    # B1 (1.3.22): der einzige Dienstbefehl, server/cmd/sperre.
    if len(teile) == 3 and teile[0] == "server" and teile[1] == "cmd":
        return teile[2] == "sperre"
    if len(teile) == 3 and teile[0] not in ("", "server") and teile[1] == "cmd":
        return thema_info("befehle", teile[2]) is not None
    # Audio Stufe 1 (1.3.25): Zonenthemen <praefix>/zone/<n>/<schluessel> (Liste zone).
    if len(teile) == 3 and teile[0] == "zone" and re.match(r"^[0-9]{1,2}$", teile[1]):
        return thema_info("zone", teile[2]) is not None
    if len(teile) != 2 or teile[0] == "":
        return False
    bereich = "dienst" if teile[0] == "server" else "geraet"
    return thema_info(bereich, teile[1]) is not None


def broker_leeren(praefix, warten=2.0, nur=None):
    """Rueckgabe (code, geleert, rest, grund): code 0 = nichts (mehr)
    zurueckbehalten, 1 = nach dem Loeschen steht noch etwas, 2 = nicht zu
    fragen (keine Bibliothek, kein Broker, Anmeldung abgewiesen, Lesen
    verweigert).

    nur: ein Geraetethema - dann wird nur <praefix>/<nur>/... abgeraeumt
    (seit 1.3.13, M4: ein entferntes Geraet)."""
    praefix = str(praefix or "").strip("/")
    if praefix == "" or "#" in praefix or "+" in praefix:
        return 2, [], [], "das Themenpraefix '%s' taugt nicht fuer ein Abonnement" % praefix
    # Audio Stufe 1 (1.3.25): auch eine Zone "zone/<n>".
    if nur is not None and (str(nur) in ("", "server") or re.search(r"[#+]", str(nur))
                            or ("/" in str(nur) and not re.match(r"^zone/[0-9]{1,2}$", str(nur)))):
        return 2, [], [], "das Geraetethema '%s' taugt nicht fuer ein Abonnement" % nur
    filter_thema = praefix + ("/" + str(nur) if nur is not None else "") + "/#"
    try:
        import paho.mqtt.client as mqtt
    except ImportError:
        return 2, [], [], "paho-mqtt fehlt"
    zugang = mqtt_zugangsdaten()
    if not zugang:
        return 2, [], [], "kein MQTT-Broker in general.json"
    wo = "%s:%s" % (zugang["host"], zugang["port"])
    gesehen = set()
    antwort = {"code": None}
    subacks = {}

    def bei_verbindung(_k, _d, *rest):
        rc = MqttAnbindung._grund(rest)
        try:
            antwort["code"] = int(getattr(rc, "value", rc))
        except (TypeError, ValueError):
            antwort["code"] = -1

    def bei_nachricht(_k, _d, nachricht):
        # Nur ZURUECKBEHALTENES mit Inhalt: ein leeres Thema ist schon weg.
        if nachricht.retain and nachricht.payload \
                and eigenes_thema(praefix, nachricht.topic) \
                and (nur is None or nachricht.topic.startswith(praefix + "/" + str(nur) + "/")):
            gesehen.add(nachricht.topic)

    def bei_abo(_k, _d, mid, codes, *_rest):
        # paho 1.x und VERSION1: Zahlen; VERSION2: ReasonCode mit .value.
        werte = []
        try:
            for c in (codes or ()):
                werte.append(int(getattr(c, "value", c)))
        except (TypeError, ValueError):
            werte = [0x80]
        subacks[mid] = werte or [0x80]

    # Jede Ausnahme bis zum Anmelden heisst "nicht zu fragen" - nie ein
    # Absturz des Aufrufers: der Dienst ruft das beim Praefixwechsel mitten im
    # Betrieb (mit einer paho-Attrappe gemessen 25.09.2026, Rueckschritt
    # Pruefung-Chromecast4lox-1.3.10 k7: ohne diese Klammer endete der Dienst).
    try:
        try:
            k = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2)
        except (AttributeError, TypeError, ValueError):
            k = mqtt.Client()
        if zugang["user"]:
            k.username_pw_set(zugang["user"], zugang["pass"] or "")
        k.on_connect = bei_verbindung
        k.on_message = bei_nachricht
        k.on_subscribe = bei_abo
    except Exception as fehler:  # noqa: BLE001
        return 2, [], [], "paho-mqtt laesst sich nicht einrichten (%s)" % fehler

    def abonnieren():
        erg = k.subscribe(filter_thema)
        try:
            rc_sub, mid = int(erg[0]), erg[1]
        except (TypeError, ValueError, IndexError):
            return "das Abonnement liess sich nicht absenden (%r)" % (erg,)
        if rc_sub != 0:
            return "das Abonnement liess sich nicht absenden (rc %d)" % rc_sub
        ende = time.time() + 10
        while mid not in subacks and time.time() < ende:
            time.sleep(0.05)
        if mid not in subacks:
            return "der Broker %s hat das Abonnement nicht bestaetigt (kein SUBACK)" % wo
        schlecht = [w for w in subacks[mid] if w >= 0x80]
        if schlecht:
            return ("der Broker %s verweigert das Lesen von '%s' (SUBACK 0x%02X)"
                    % (wo, filter_thema, schlecht[0]))
        return ""

    try:
        k.connect(zugang["host"], zugang["port"], 30)
        k.loop_start()
    except Exception as fehler:  # noqa: BLE001
        return 2, [], [], "der Broker %s ist nicht erreichbar (%s)" % (wo, fehler)
    geleert = []
    rest = []
    try:
        ende = time.time() + 10
        while antwort["code"] is None and time.time() < ende:
            time.sleep(0.05)
        if antwort["code"] is None:
            return 2, [], [], "der Broker %s hat auf die Anmeldung nicht geantwortet" % wo
        if antwort["code"] != 0:
            return 2, [], [], ("der Broker %s hat die Anmeldung abgewiesen (CONNACK %d: %s)"
                               % (wo, antwort["code"], MQTT_ANMELDUNG_TEXT.get(
                                   antwort["code"], "unbekannter Grund")))
        grund = abonnieren()
        if grund:
            return 2, [], [], grund
        time.sleep(warten)
        k.unsubscribe(filter_thema)
        geleert = sorted(gesehen)
        for thema in geleert:
            info = k.publish(thema, b"", qos=1, retain=True)
            try:
                info.wait_for_publish(5)
            except TypeError:           # paho 1.x vor 1.6 kennt keine Frist
                info.wait_for_publish()
        # NACHLESEN: ein neues Abonnement bekommt alles, was noch steht.
        gesehen.clear()
        grund = abonnieren()
        if grund:
            return 2, geleert, [], "Nachlesen nicht moeglich - " + grund
        time.sleep(warten)
        rest = sorted(gesehen)
    except Exception as fehler:  # noqa: BLE001
        return 2, geleert, [], "das Loeschen am Broker %s scheiterte (%s)" % (wo, fehler)
    finally:
        try:
            k.disconnect()
        except Exception:  # noqa: BLE001
            pass
        try:
            k.loop_stop()
        except Exception:  # noqa: BLE001
            pass
    return (1 if rest else 0), geleert, rest, ""


def praefix_lesen():
    """Das Themenpraefix aus der Konfiguration - NUR lesen.

    konfiguration_lesen() vervollstaendigt die Datei und schriebe damit
    waehrend der Deinstallation in eine Konfiguration, die gleich geloescht
    wird."""
    vorgabe = VORGABEN.get("mqtt_topic") or "chromecast4lox"
    parser = ConfigParser(interpolation=None)
    parser.optionxform = str
    try:
        with open(CONFIG_FILE, "r", encoding="utf-8") as fh:
            parser.read_string(fh.read())
    except Exception:  # noqa: BLE001
        return vorgabe
    for abschnitt in parser.sections():
        for schluessel, wert in parser.items(abschnitt):
            if schluessel.strip().lower() == "mqtt_topic":
                return wert.strip().strip('"').strip("'") or vorgabe
    return vorgabe


def mqtt_leeren():
    """Fuer uninstall/uninstall. Ausgabe in der Form der
    Installationsmeldungen; Rueckgabe 0 erledigt, 1 Reste, 2 nicht moeglich."""
    if not HOME_DIR:
        print("<WARNING> MQTT: keine LoxBerry-Wurzel - zurueckbehaltene Themen "
              "wurden nicht geloescht.")
        return 2
    # Das aktuelle Praefix UND das zuletzt benutzte (seit 1.3.13, M8): wurde
    # das Praefix bei angehaltenem Dienst oder ueber "Einstellungen
    # zurueckspielen" geaendert, blieb bis 1.3.12 unter dem alten alles
    # stehen, darunter server/online 0 (gemessen 30.09.2026, Pruefung MQTT M8).
    liste = [praefix_lesen()]
    alt = praefix_merker_lesen()
    if alt and alt not in liste and praefix_taugt(alt):
        liste.append(alt)
    schlimmster = 0
    for praefix in liste:
        schlimmster = max(schlimmster, _praefix_leeren_melden(praefix))
    return schlimmster


def _praefix_leeren_melden(praefix):
    code, geleert, rest, grund = broker_leeren(praefix)
    if code == 2:
        print("<WARNING> MQTT: zurueckbehaltene Themen unter {0}/ nicht geleert - "
              "{1}. Sie sind von Hand zu loeschen (MQTT Finder des Gateways)."
              .format(praefix, grund))
        return 2
    if rest:
        print("<WARNING> MQTT: {0} zurueckbehaltene Themen stehen nach dem "
              "Loeschen noch im Broker: {1}".format(len(rest), ", ".join(rest)))
        return 1
    if geleert:
        print("<OK> MQTT: {0} zurueckbehaltene Themen unter {1}/ geloescht und "
              "nachgelesen ({2}).".format(len(geleert), praefix, ", ".join(geleert)))
    else:
        print("<OK> MQTT: unter {0}/ stand nichts zurueckbehalten (nachgelesen)."
              .format(praefix))
    return 0


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
                sichtbar_merken([info])     # Audio Stufe 1 (S2)

        def weg(uuid, service, cast_info):
            name = getattr(cast_info, "friendly_name", "")
            if self.abmelden(name, uuid):      # 1.3.26: nur dieselbe Kennung, nicht der Name allein
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

    def abmelden(self, name, uuid):
        """1.3.26 (Befund 2): ein abgemeldetes Geraet aus der Liste nehmen - nur, wenn der Eintrag dieses Namens
        dieselbe Kennung traegt. Bis 1.3.25 galt der Name allein: wurde eine Gruppe in Google Home geloescht und
        gleich wieder angelegt, strich die Abmeldung der ALTEN (am Geraet 31.10. 22:48 und 22:50) den Eintrag der
        NEUEN gleichen Namens. Rueckgabe True = entfernt."""
        info = self.gefunden.get(name)
        if info is None:
            return False
        jetzt = str(getattr(info, "uuid", "") or "")
        weg = str(uuid or "")
        if jetzt and weg and not kennung_gleich(jetzt, weg):
            log.info("'%s': eine alte Kennung hat sich abgemeldet - der neue Eintrag gleichen Namens bleibt", name)
            return False
        del self.gefunden[name]
        return True

    def info(self, name):
        return self.gefunden.get(name)

    def info_kennung(self, kennung):
        """Audio Stufe 1 (E1): ein Zonenziel, das in Google Home umbenannt wurde - ueber die gemerkte Kennung."""
        if not kennung:
            return None
        for info in list(self.gefunden.values()):
            if str(getattr(info, "uuid", "") or "") == kennung:
                return info
        return None

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
        # X-7 (Entscheidung Nr. 19, seit 1.3.17): zuletzt GESENDETER Pegel
        # (0-100) und Zeitpunkt (time.monotonic) - siehe _x7_gleich().
        self.x7_laut = None
        # Audio Stufe 1 (1.3.25): Zonen dieses Geraets, gemerkte und aktuelle Cast-Kennung, aktive Quelle.
        self.zonen_nr = []
        self.uuid_merker = ""
        self.uuid = ""
        self.quelle = 0
        self.quelle_name = ""
        # E-NF3 (Nachfassung 2): die Geraeteliste des Dienstes - eine Gruppe merkt sich vor Klingel/Ansage, was auf
        # den anderen spielt, und setzt danach fort, was sie unterbrochen hat. Der Dienst setzt sie beim Aufbau.
        self.nachbarn = {}
        # Nachfassung 3 (O1): nur bei einer Google-Gruppe - ihre Mitglieder als Liste von dict(kennung, name) aus dem
        # MultizoneController; None = noch nicht gemessen (dann bleibt alles wie vor der Nachfassung). mz = der
        # Controller am aktuellen Cast-Objekt, mz_frisch = die Gruppe hat eine Aenderung gemeldet.
        self.mitglieder = None
        self.mitglieder_zeit = 0
        self.mitglieder_geaendert = False
        self.mz = None
        self.mz_frisch = False
        self.mz_nachfrage = 0.0
        # 1.3.26 (Befund 2): seit wann (time.monotonic) das Cast-Objekt nicht sendebereit ist, und wie oft ein
        # Sendeversuch hintereinander an "is connecting" scheiterte. Eine neue Verbindung setzt beides zurueck.
        self.haengt_seit = 0.0
        self.haengt_fehler = 0

    # -- Verbindung ---------------------------------------------------------

    def verbinden(self, frist=None):
        """Geraet suchen und verbinden. Rueckgabe: True bei Erfolg. 1.3.26: mit frist (Neusuche der Klingel)
        nur ueber die Dauersuche - keine Einzelsuche (8 s) - und hoechstens frist s auf die Verbindung warten."""
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
        if info is None and self.netzsuche and self.uuid_merker:
            info = self.netzsuche.info_kennung(self.uuid_merker)
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
        if info is None and frist is not None:
            log.info("'%s' steht nicht in der Dauersuche - waehrend der Klingel keine Einzelsuche", self.name)
            return False
        if info is None:
            try:
                # Audio Stufe 1 (E1): ein Zonenziel auch ueber seine gemerkte Kennung finden.
                kennung = self._kennung_objekt()
                gefunden, browser = pychromecast.get_listed_chromecasts(
                    friendly_names=[self.name], discovery_timeout=8,
                    **({"uuids": [kennung]} if kennung is not None else {})
                )
            except Exception as fehler:  # noqa: BLE001
                log.error("Suche nach '%s' fehlgeschlagen: %s", self.name, fehler)
                return False

            if not gefunden:
                # Audio Stufe 1 (S2): was die Suche gesehen hat, nennen - Name und Modell, keine Adresse.
                try:
                    sichtbar_merken(getattr(browser, "devices", None) or {})
                except Exception:  # noqa: BLE001
                    pass
                gesehen = sorted(SICHTBAR)[:8]
                log.warning("Chromecast '%s' nicht gefunden%s", self.name,
                            (" - im Netz sichtbar: " + ", ".join("%s (%s)" % (n, SICHTBAR[n] or "?")
                                                               for n in gesehen)) if gesehen else "")
                self._browser_beenden(browser)
                self.melden_offline()
                return False

            self.cast = gefunden[0]
            self.browser = browser
            try:
                sichtbar_merken(getattr(browser, "devices", None) or {})
            except Exception:  # noqa: BLE001
                pass
        try:
            self.cast.wait(timeout=10 if frist is None else max(0.2, float(frist)))
        except Exception as fehler:  # noqa: BLE001
            log.error("Verbindung zu '%s' fehlgeschlagen: %s", self.name, fehler_lesbar(fehler, self.name))
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
        # Audio Stufe 1 (E1): Kennung merken; heisst das Geraet in Google Home anders, weiter verbinden und melden.
        self.uuid = str(getattr(self.cast, "uuid", "") or "")
        jetzt_name = str(getattr(self.cast, "name", "") or "")
        if jetzt_name and jetzt_name != self.name:
            log.warning("'%s' heisst in Google Home jetzt '%s' - verbunden ueber die gemerkte Kennung; Themen "
                        "und Zonen bleiben. Den Namen in der Geraeteliste nachziehen.", self.name, jetzt_name)
            self.meldung_setzen("umbenannt in Google Home: {0}".format(jetzt_name))
        # X-7: nach einer (Neu-)Verbindung ist der gemerkte Pegel nichts mehr
        # wert - das Geraet kann neu gestartet sein.
        self.x7_laut = None
        self.haengt_seit = 0.0      # 1.3.26
        self.haengt_fehler = 0
        self._lauscher_anmelden()
        self._mitglieder_anmelden()     # Nachfassung 3 (O1): nur bei einer Gruppe
        return True

    # -- Nachfassung 3: Mitglieder einer Google-Gruppe (O1, gemessen 30.10.2026) ---------------------------------
    #
    # Schnittstelle in pychromecast 9.4.0 (am LoxBerry gelesen) wie in 14.x: MultizoneController(uuid),
    # register_listener(obj) mit multizone_member_added/_removed/multizone_status_received, update_members() schickt
    # GET_STATUS, members = Liste der Kennungen, _members = Kennung -> Name. Alles ueber getattr mit Rueckfall; fehlt
    # der Controller, bleibt die Mitgliedschaft unbekannt (None) und alles wie vorher. Angemeldet wird am Cast-Objekt
    # ueber register_handler (9.4 leitet es an socket_client weiter, neuere ebenso).

    def _mitglieder_anmelden(self):
        if not self.ist_gruppe or self.cast is None:
            return
        try:
            from pychromecast.controllers.multizone import MultizoneController
        except ImportError as fehler:
            log.warning("'%s': die Mitglieder der Gruppe lassen sich nicht abfragen (%s)", self.name, fehler)
            return
        geraet = self

        class _MzLauscher(object):
            """Duck-Typing wie _Lauscher: nicht von MultiZoneControllerListener erben (abstrakte Methoden je Fassung)."""

            def multizone_member_added(self, *_a, **_k):
                geraet.mz_frisch = True

            def multizone_member_removed(self, *_a, **_k):
                geraet.mz_frisch = True

            def multizone_status_received(self, *_a, **_k):
                geraet._mitglieder_lesen()

            def new_connection_status(self, status):
                if str(getattr(status, "status", "") or "") == "CONNECTED":
                    geraet._mitglieder_nachfragen()

        lauscher = _MzLauscher()
        try:
            mz = MultizoneController(getattr(self.cast, "uuid", None) or self.uuid)
            mz.register_listener(lauscher)
            self.mz = mz
            anmelden = getattr(self.cast, "register_handler", None)
            if anmelden is None:
                anmelden = self.cast.socket_client.register_handler
            anmelden(mz)
        except Exception as fehler:  # noqa: BLE001
            self.mz = None
            log.warning("'%s': die Mitglieder der Gruppe lassen sich nicht abfragen (%s)", self.name, fehler)
            return
        try:
            self.cast.register_connection_listener(lauscher)
        except Exception:  # noqa: BLE001
            pass
        log.info("'%s': Mitglieder der Gruppe werden abgefragt (Multizone)", self.name)
        self._mitglieder_nachfragen()

    def _mitglieder_nachfragen(self):
        """GET_STATUS an die Gruppe - die Antwort kommt ueber den Lauscher (multizone_status_received)."""
        mz = self.mz
        if mz is None:
            return
        self.mz_nachfrage = time.time()
        try:
            mz.update_members()
        except Exception as fehler:  # noqa: BLE001
            self.nv_vermerken(fehler)       # 1.3.26: zaehlt fuer das Verwerfen (Befund 2)
            log.warning("'%s': Nachfrage nach den Mitgliedern gescheitert: %s", self.name,
                        fehler_lesbar(fehler, self.name))

    def _mitglieder_lesen(self):
        """Die Mitglieder aus dem Controller uebernehmen (Kennung und Name). Aendert sich etwas, steht es im Protokoll
        und der Dienst merkt es unter data/."""
        mz = self.mz
        if mz is None:
            return
        self.mz_frisch = False
        roh = getattr(mz, "_members", None)
        if isinstance(roh, dict):
            paare = list(roh.items())
        else:
            paare = [(k, "") for k in (getattr(mz, "members", None) or [])]
        neu = sorted(({"kennung": str(k or "")[:64], "name": str(n or "")[:80]} for k, n in paare),
                     key=lambda m: (m["name"].lower(), m["kennung"]))
        if neu == self.mitglieder:
            return
        self.mitglieder = neu
        self.mitglieder_zeit = int(time.time())
        self.mitglieder_geaendert = True
        log.info("Gruppe '%s': Mitglieder %s", self.name, ", ".join(m["name"] or "?" for m in neu) or "(keine)")

    def _mitglieder_pflegen(self):
        """Aus _melden: was die Gruppe einzeln gemeldet hat (hinzu/weg) uebernehmen, und eine verbundene Gruppe alle
        MZ_NACHFRAGE Sekunden erneut fragen."""
        if getattr(self, "mz", None) is None:
            return
        if self.mz_frisch:
            self._mitglieder_lesen()
        if self.verbunden() and time.time() - self.mz_nachfrage >= MZ_NACHFRAGE:
            self._mitglieder_nachfragen()

    def ist_mitglied(self, m):
        """Ist dieses Geraet das Mitglied m (dict kennung, name)? Ueber die Kennung (aktuelle oder gemerkte), sonst
        ueber den Namen."""
        k = m.get("kennung") if isinstance(m, dict) else ""
        if kennung_gleich(k, getattr(self, "uuid", "")) or kennung_gleich(k, getattr(self, "uuid_merker", "")):
            return True
        return name_gleich(m.get("name") if isinstance(m, dict) else "", self.name)

    def mitglied_von(self):
        """Namen der eingetragenen Google-Gruppen, in denen dieses Geraet Mitglied ist (sortiert). Eine Gruppe ist in
        keiner Gruppe; unbekannte Mitgliedschaften zaehlen nicht."""
        if getattr(self, "ist_gruppe", False):
            return []
        aus = []
        for g in list((getattr(self, "nachbarn", None) or {}).values()):
            if g is self:
                continue
            if any(self.ist_mitglied(m) for m in (getattr(g, "mitglieder", None) or [])):
                aus.append(g.name)
        return sorted(aus, key=lambda n: n.lower())

    def _kennung_objekt(self):
        """Die gemerkte Kennung als UUID fuer get_listed_chromecasts(uuids=...) - oder None."""
        if not self.uuid_merker:
            return None
        try:
            import uuid as _uuid
            return _uuid.UUID(self.uuid_merker)
        except (ValueError, TypeError):
            return None

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

    def beenden(self):
        """Arbeitsfaden anhalten - beim Dienstende und fuer ein entferntes Geraet."""
        # Fremdbefund C9 (1.3.24, Pruefbericht wortgleich): bis 1.3.23 stand das nur in Dienst.stop(). Ein beim
        # Neueinlesen entferntes Geraet behielt seinen Faden; eingereihte Auftraege liefen danach noch und verbanden
        # neu, und nach dem Wiederaufnehmen liefen zwei Faeden cc-<thema> (probe_c9). Mit der Befehlsbruecke wichtiger:
        # ein Befehl an ein eben entferntes Geraet liefe sonst ueber den alten Faden.
        self.faden_laeuft = False
        self.ansage_abbrechen = True
        try:
            self.auftraege.put_nowait(None)
        except Exception:  # noqa: BLE001
            pass

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
            befehl, wert, schrittweite, cfg = auftrag[:4]
            # Ansage-3: ein Auftrag aus dem Sprech-Eingang traegt eine
            # Rueckmeldung - das Ergebnis geht in sprechen_ergebnis.json.
            rueckmeldung = auftrag[4] if len(auftrag) > 4 else None
            try:
                ergebnis = self.befehl(befehl, wert, schrittweite, cfg)
            except Exception as fehler:  # noqa: BLE001
                ergebnis = "Fehler: {0}".format(fehler_lesbar(fehler, self.name))
                log.error("'%s': Auftrag %s abgebrochen: %s",
                          self.name, befehl, fehler_lesbar(fehler, self.name))
            if ergebnis != "OK":
                log.warning("%s: %s", self.name, ergebnis)
            self.meldung_setzen("" if ergebnis == "OK" else ergebnis)
            if rueckmeldung is not None:
                try:
                    rueckmeldung(self.name, ergebnis)
                except Exception as fehler:  # noqa: BLE001
                    log.warning("'%s': Rueckmeldung der Ansage misslungen: %s",
                                self.name, fehler)
            self.auftraege.task_done()

    def einreihen(self, befehl, wert, schrittweite, cfg, rueckmeldung=None):
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
                    weg = self.auftraege.get_nowait()
                    self.auftraege.task_done()
                    geleert += 1
                    # Ansage-3: auch ein verworfener Auftrag meldet sein Ende.
                    if weg is not None and len(weg) > 4 and weg[4] is not None:
                        try:
                            weg[4](self.name, "Abgebrochen")
                        except Exception:  # noqa: BLE001
                            pass
                except queue.Empty:
                    break
            log.info("'%s': Ansage abgebrochen, %d wartende Auftraege verworfen",
                     self.name, geleert)
            return
        self.faden_starten()
        self.auftraege.put((befehl, wert, schrittweite, cfg, rueckmeldung))

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

    # -- X-7: Gleichwert-Bremse der absoluten Lautstaerke (seit 1.3.17) ------
    #
    # Entscheidung Nr. 19 (X-7): ein Sollwert-Befehl mit demselben Wert geht
    # innerhalb von 60 s nicht erneut hinaus. Bei diesem Plugin ist das die
    # absolute Lautstaerke (volume/set_volume): ein Analogausgang in Loxone,
    # der denselben Wert wiederholt, setzte sonst jedes Mal die Lautstaerke am
    # Lautsprecher. Verglichen wird der WIRKSAME Pegel (nach Obergrenze und
    # Ruhezeit) mit dem zuletzt gesendeten. Ereignisse und Tasten (Schritte,
    # lauter/leiser, mute, play/pause, Favorit, Ansage) bremst X-7 nicht; die
    # Schritte schreiben aber ihren gesetzten Pegel in den Merker, sonst ginge
    # "40, lauter, 40" beim zweiten 40 nicht hinaus. Der Merker lebt nur im
    # Speicher, je Geraet; die Befehle eines Geraets laufen nacheinander im
    # Arbeitsfaden. Er verfaellt bei einer neuen Verbindung und wenn eine
    # Ansage die Lautstaerke stellt. Eine Aenderung in der Google-App sieht er
    # nicht - die Regel vergleicht mit dem zuletzt GESENDETEN Wert.

    def _x7_gleich(self, pegel):
        """Ging derselbe Pegel vor weniger als 60 s hinaus? Rueckgabe:
        Sekunden seitdem, oder None."""
        merker = self.x7_laut
        if merker is None or merker[0] != int(pegel):
            return None
        seit = time.monotonic() - merker[1]
        return seit if 0 <= seit < 60 else None

    def _x7_merken(self, pegel):
        self.x7_laut = (int(pegel), time.monotonic())

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
        self.mz = None      # Nachfassung 3: der Controller ebenso; die Mitglieder bleiben bekannt

    def verbunden(self):
        return self.cast is not None and getattr(self.cast, "socket_client", None) is not None

    # -- 1.3.26 (Befund 2): haengende Verbindung verwerfen und neu suchen -------------------------------------------
    #
    # Hoertest 01.11.2026: die Gruppe "Klingel" wurde in Google Home geloescht und neu angelegt. Das alte Cast-Objekt
    # verband weiter mit der alten Adresse; verbunden() blieb wahr (das Objekt ist ja da), jede Klingel und jede
    # Mitgliederabfrage endete in NotConnected, bis der Dienst neu startete. Jetzt: steht das Objekt HAENGT_GRENZE s
    # nicht sendebereit da oder scheitern HAENGT_FEHLER Sendeversuche hintereinander daran, verwirft der Dienst es und
    # sucht neu - die neue Gruppe findet er ueber ihren Namen (Dauersuche, sonst Einzelsuche).

    def haengt(self):
        """Sekunden, die das Cast-Objekt schon nicht sendebereit ist (0 = sendebereit oder kein Objekt). pychromecast
        9.4.0 (am LoxBerry gelesen) sendet nur, wenn socket_client weder "connecting" noch "_force_recon" traegt -
        sonst NotConnected; is_connected ist "not connecting". Fehlen die Merkmale (andere Fassung, Attrappe), gilt das
        Objekt als sendebereit."""
        sc = getattr(self.cast, "socket_client", None) if self.cast is not None else None
        if sc is None or (getattr(sc, "is_connected", True) and not getattr(sc, "_force_recon", False)):
            self.haengt_seit = 0.0
            return 0.0
        jetzt = time.monotonic()
        if not getattr(self, "haengt_seit", 0.0):
            self.haengt_seit = jetzt
        return max(0.0, jetzt - self.haengt_seit)

    def nv_vermerken(self, fehler):
        """Einen Sendeversuch zaehlen, der an "is connecting" scheiterte. Rueckgabe True = so ein Fehlschlag."""
        if nicht_verbunden(fehler):
            self.haengt_fehler = getattr(self, "haengt_fehler", 0) + 1
            return True
        return False

    def verwerfen_faellig(self):
        """Grund, das Cast-Objekt zu verwerfen, oder "" (nicht verbunden, sendebereit, oder noch nicht lange genug)."""
        if not self.verbunden():
            return ""
        s = self.haengt()
        if s >= HAENGT_GRENZE:
            return "seit %.0f s nicht verbunden" % s
        if getattr(self, "haengt_fehler", 0) >= HAENGT_FEHLER:
            return "%d Sendeversuche hintereinander gescheitert (noch nicht verbunden)" % self.haengt_fehler
        return ""

    def verwerfen(self, grund):
        """Das Cast-Objekt verwerfen; die Hauptschleife (bzw. neu_suchen) verbindet danach neu."""
        log.warning("Gerät/Gruppe '%s': %s - Verbindung verworfen, es wird neu gesucht", self.name, grund)
        self.trennen()
        self.haengt_seit = 0.0
        self.haengt_fehler = 0
        self.naechster_versuch = 0.0
        self.wartezeit = 0.0

    def neu_suchen(self, grund, frist=None):
        """Verwerfen und sofort neu verbinden. Mit frist (Klingel) nur ueber die Dauersuche und hoechstens frist s
        warten - keine Einzelsuche, die kostet 8 s. Rueckgabe True = wieder verbunden."""
        self.verwerfen(grund)
        try:
            return bool(self.verbinden(frist=frist))
        except Exception as fehler:  # noqa: BLE001
            log.warning("Gerät/Gruppe '%s': Neusuche gescheitert: %s", self.name, fehler_lesbar(fehler, self.name))
            return False

    # -- Zustand melden -----------------------------------------------------

    def _senden(self, schluessel, wert, erzwingen=False, platzhalter=False):
        """Nur senden, wenn sich der Wert geaendert hat. Sonst laeuft der
        Broker bei kurzem Intervall unnoetig voll.

        Ob retained gesendet wird, entscheidet die Themenliste je Thema -
        nicht dieser Code und schon gar nicht pauschal.

        Ein PLATZHALTER geht nie retained hinaus (seit 1.3.12): state
        OFFLINE und playing 0 sagt nicht das Geraet, sondern der Dienst aus
        seinem eigenen Fehlschlag. Loxone sieht sie wie bisher; der Broker
        behaelt den letzten Stand des Geraets (Muster 12 der Nachlese,
        Bauart KODI-NG 1.2.10 und Robonect 1.1.12). Bis 1.3.11 gingen sie
        retained und ueberschrieben ihn (in WSL gemessen 25.09.2026,
        Pruefung-Chromecast4lox-1.3.12, Fall M1c). Gemerkt wird ein
        Platzhalter ALS Platzhalter: der erste echte Wert danach geht auch
        dann retained hinaus, wenn er gleich lautet - sonst stuende nach
        "playing 0" als Platzhalter und "playing 0" vom Geraet weiter die
        alte 1 im Broker (Fall M4).
        """
        wert = "" if wert is None else str(wert)
        merk = ("\0platzhalter:" + wert) if platzhalter else wert
        if not erzwingen and self.letzter_stand.get(schluessel) == merk:
            return
        self.letzter_stand[schluessel] = merk
        self.mqtt.senden(self.thema + "/" + schluessel, wert,
                         thema_retain("geraet", schluessel) and not platzhalter)
        self._zonen_spiegeln(schluessel, wert, platzhalter)     # Audio Stufe 1

    def melden_offline(self, erzwingen=False):
        """Das Geraet ist nicht erreichbar - Platzhalter, fluechtig.

        Mit erzwingen gehen sie mit jeder Vollmeldung erneut hinaus (seit
        1.3.13, M5; Frage 6/11 vom 29.09.2026). Bis 1.3.12 ging "online 0"
        eines ausgefallenen Geraets genau einmal hinaus; startete danach das
        Gateway oder der Miniserver neu, kam die 0 nie mehr an, der retained
        Zustand aber schon (gemessen 30.09.2026, Pruefung MQTT M5)."""
        self._senden("online", "0", erzwingen, platzhalter=True)
        self._senden("state", "OFFLINE", erzwingen, platzhalter=True)
        self._senden("playing", "0", erzwingen, platzhalter=True)

    def melden(self, erzwingen=False):
        """Aktuellen Zustand einsammeln und veroeffentlichen.

        Kann aus der Hauptschleife UND aus einem Rueckruf kommen - deshalb
        unter einem Schloss.
        """
        with self.schloss:
            self._melden(erzwingen)

    def _melden(self, erzwingen=False):
        # Nachfassung 3 (O1): Mitgliedschaft in Google-Gruppen - auch bei einem nicht verbundenen Lautsprecher, sie
        # haengt an den Gruppen. Text, retained; "-" = in keiner (bekannten) Gruppe, nie leer (Entscheidung Nr. 5).
        self._mitglieder_pflegen()
        self._zone_senden("mitglied_von", "; ".join(self.mitglied_von()) or "-", erzwingen)
        if not self.verbunden():
            self.melden_offline(erzwingen)
            return

        try:
            cast_status = self.cast.status
            medien = self.cast.media_controller.status
        except Exception as fehler:  # noqa: BLE001
            log.warning("Zustand von '%s' nicht lesbar: %s", self.name, fehler)
            self.melden_offline(erzwingen)
            return

        self._senden("online", "1", erzwingen)

        self._senden("type", self.art or "cast", erzwingen)
        self._senden("group", "1" if self.ist_gruppe else "0", erzwingen)

        if cast_status is not None:
            pegel = cast_status.volume_level
            # E9 (1.3.24): -1, wenn der Lautsprecher keine Lautstaerke meldet. Bis 1.3.23 stand dort 0 - und 0 % ist
            # ein echter Wert ("stumm geregelt").
            self._senden("volume", -1 if pegel is None else int(round(pegel * 100)), erzwingen)
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
        # Audio Stufe 1: aktive Quelle der Zone(n).
        self._zone_senden("quelle", self.quelle, erzwingen)
        self._zone_senden("quelle_name", self.quelle_name, erzwingen)

    # -- Befehle ------------------------------------------------------------

    # -- Ansage mit Wiederaufnahme -----------------------------------------

    def _lage_merken(self):
        """Festhalten, was gerade laeuft (self.vorher) - siehe _lage_lesen()."""
        self.vorher = self._lage_lesen()

    def _lage_lesen(self):
        """Lesen, was gerade laeuft - fuer die Wiederaufnahme. Rueckgabe dict oder None (E-NF3: so liest eine
        Gruppe auch die Lage ihrer Mitglieder, ohne deren self.vorher anzufassen).

        Gemerkt wird nur, was sich ueber pychromecast auch wieder herstellen
        laesst: die Adresse des Mediums, sein Typ, die Abspielposition und
        die Lautstaerke. Bei einer App wie Spotify oder YouTube gibt es
        keine Adresse, die man zurueckspielen koennte - dann wird
        ausdruecklich NICHTS gemerkt und hinterher auch nichts behauptet.
        """
        try:
            st = self.cast.media_controller.status
            cs = self.cast.status
        except Exception:  # noqa: BLE001
            return None
        lautstaerke = cs.volume_level if cs else None
        if st is None or not st.content_id or st.player_state not in ("PLAYING", "PAUSED"):
            # Nichts Wiederherstellbares - aber die Lautstaerke schon.
            return {"art": "nur_lautstaerke", "lautstaerke": lautstaerke}
        return {
            "art": "medium",
            "url": st.content_id,
            "typ": st.content_type or "audio/mp3",
            "position": float(st.adjusted_current_time or 0.0),
            "lief": st.player_state == "PLAYING",
            "lautstaerke": lautstaerke,
            # Nachfassung 30.10.2026 (Geraetetest 17:57: Radio nach der Klingel still, "bei 13 s wieder
            # aufgenommen"): ein Live-Strom hat keine Stelle - er startet ohne current_time neu; eine eigene
            # Liste geht an Titel und Stelle weiter, mit den folgenden Titeln dahinter.
            "live": (str(getattr(st, "stream_type", "") or "").upper() == "LIVE"
                     or not getattr(st, "duration", None)),
            "titel": str(getattr(st, "title", "") or ""),
            "liste": self._liste_index(st.content_id),
        }

    def _lautstaerke_merken(self):
        """Nur die Lautstaerke festhalten (Ansage-3: "laut" gilt nur fuer die
        Ansage, auch wenn "danach fortsetzen" aus ist)."""
        self.vorher = None
        try:
            cs = self.cast.status
        except Exception:  # noqa: BLE001
            return
        if cs is not None and cs.volume_level is not None:
            self.vorher = {"art": "nur_lautstaerke", "lautstaerke": cs.volume_level}

    def _lage_laden(self, lage, zeit):
        """Das gemerkte Medium laden: eigene Liste ab ihrem Titel (Rest eingereiht), sonst die Adresse - ein
        Live-Strom als LIVE und ohne Stelle (Nachfassung 30.10.2026)."""
        mc = self.cast.media_controller
        if lage.get("liste") is not None and not lage.get("live"):
            grund = self._liste_ab(lage["liste"], zeit, lage["lief"])
            if grund:
                log.warning("'%s': Wiederaufnahme der Liste: %s", self.name, grund)
            return
        kw = {"autoplay": lage["lief"]}
        if zeit > 0:
            kw["current_time"] = zeit
        if lage.get("live"):
            kw["stream_type"] = "LIVE"
        if lage.get("titel"):
            kw["title"] = lage["titel"]
        mc.play_media(lage["url"], lage["typ"], **kw)
        mc.block_until_active(timeout=10)

    def _spielt_wieder(self, blicke=30):
        """Nach dem Fortsetzen bis zu ~6 s nachsehen, ob der Lautsprecher spielt (PLAYING/BUFFERING)."""
        mc = self.cast.media_controller
        for _ in range(blicke):
            st = mc.status
            if st is not None and getattr(st, "player_state", "") in ("PLAYING", "BUFFERING"):
                return True
            time.sleep(0.2)
        return False

    def _lage_herstellen(self):
        """Nach der Ansage wieder aufnehmen, was vorher lief."""
        lage = self.vorher
        self.vorher = None
        if not lage:
            return
        try:
            if lage.get("lautstaerke") is not None:
                lautstaerke_setzen(self.cast, lage["lautstaerke"])
                self.x7_laut = None  # X-7: die Ansage hat den Pegel gestellt
            if lage["art"] != "medium":
                return
            zeit = 0.0 if lage.get("live") else max(0.0, float(lage.get("position") or 0.0))
            self._lage_laden(lage, zeit)
            if lage["lief"] and not self._spielt_wieder():
                log.warning("'%s': vorheriges Medium spielt nach dem Fortsetzen nicht - einmal ohne Stelle "
                            "neu geladen", self.name)
                zeit = 0.0
                self._lage_laden(lage, zeit)
            if lage.get("live"):
                log.info("'%s': vorheriger Live-Strom neu gestartet", self.name)
            elif lage.get("liste") is not None:
                log.info("'%s': Wiedergabeliste bei Titel %d, %.0f s wieder aufgenommen", self.name,
                         lage["liste"] + 1, zeit)
            else:
                log.info("'%s': vorheriges Medium bei %.0f s wieder aufgenommen", self.name, zeit)
        except Exception as fehler:  # noqa: BLE001
            log.warning("'%s': Wiederaufnahme misslungen: %s", self.name, fehler_lesbar(fehler, self.name))

    # -- E-NF3 (Nachfassung 2, 30.10.2026): Gruppe unterbricht ihre Mitglieder ------------------------------------
    #
    # Geraetetest 30.10. 18:17: Wohnzimmer spielt Radio, Klingel an die Gruppe "Klingel" -> Wohnzimmer meldet UNKNOWN,
    # playing 0 (die Gruppe uebernimmt das Mitglied und beendet dessen Sitzung), Gong aus beiden Lautsprechern, danach
    # bleibt das Wohnzimmer still. Welche Geraete Mitglieder sind, weiss der Dienst nicht (O1). Darum: vor der
    # Gruppenwiedergabe die Lage aller verbundenen anderen Geraete merken, die gerade spielen; danach fortsetzen, was
    # waehrenddessen von PLAYING auf UNKNOWN/IDLE fiel - das sind genau die, die die Gruppe uebernommen hat. Wer
    # weiterspielt (kein Mitglied) oder pausiert, bleibt unberuehrt; IDLE mit Grund FINISHED ist ein natuerliches Ende.

    def _mitglieder_merken(self):
        """Nur fuer eine Google-Gruppe: [(Geraet, Lage)] aller verbundenen anderen Geraete, die gerade spielen und
        sich wiederherstellen lassen (Adresse bekannt). Ihre eigene Lage (self.vorher) bleibt unberuehrt."""
        if not (self.ist_gruppe or str(self.art or "").lower() == "group"):
            return []
        aus = []
        for g in list((getattr(self, "nachbarn", None) or {}).values()):
            if g is self or g.cast is None or not g.verbunden() or g.ansage_laeuft:
                continue
            try:
                st = g.cast.media_controller.status
                zustand = str(getattr(st, "player_state", "") or "") if st is not None else ""
            except Exception:  # noqa: BLE001
                continue
            if zustand != "PLAYING":
                continue
            lage = g._lage_lesen()
            if lage and lage.get("art") == "medium":
                aus.append((g, lage))
        if aus:
            log.info("'%s': vor der Gruppenwiedergabe spielen %s - danach wird nachgesehen, ob die Gruppe sie "
                     "unterbrochen hat", self.name, ", ".join("'%s'" % g.name for g, _l in aus))
        return aus

    def _mitglieder_anhalten(self, gemerkt):
        """1.3.26 (Befund 1, Hoertest 01.11.2026 03:43): lief im Gaestezimmer Musik, kam der Gong der Gruppe "Klingel"
        dort versetzt - das Mitglied musste erst seine eigene Wiedergabe beenden, bevor es der Gruppe folgte. Ohne Musik
        (03:45, nach zone_1/stop) war er synchron. Darum vor dem Gong: STOP an jedes MITGLIED der Gruppe, das gerade
        spielt (alle zugleich), dann warten, bis es IDLE meldet - der Takt kommt aus der Statusmeldung von pychromecast,
        alle KLINGEL_BLICK s nachgesehen, hoechstens KLINGEL_ANHALTEN s. Nur was sich danach fortsetzen laesst
        (gemerkt, also mit Adresse) und nur bekannte Mitglieder (gemeldet oder unter data/ gemerkt); ein Lautsprecher,
        der nicht Mitglied ist, spielt weiter. Fortgesetzt wird wie bisher in _mitglieder_fortsetzen().
        Rueckgabe: Namen der angehaltenen Geraete."""
        if not gemerkt:
            return []
        bekannt = list(getattr(self, "mitglieder", None) or [])
        if not bekannt:
            log.info("'%s': Mitglieder der Gruppe noch unbekannt - vor dem Gong wird niemand angehalten", self.name)
            return []
        ziel = [g for g, _l in gemerkt if any(g.ist_mitglied(m) for m in bekannt)]
        if not ziel:
            return []
        beginn = time.monotonic()
        for g in ziel:
            try:
                g.cast.media_controller.stop()
            except Exception as fehler:  # noqa: BLE001
                log.warning("'%s': '%s' liess sich vor dem Gong nicht anhalten: %s", self.name, g.name,
                            fehler_lesbar(fehler, g.name))
        offen = list(ziel)
        for _ in range(int(round(KLINGEL_ANHALTEN / KLINGEL_BLICK))):
            offen = [g for g in offen if g._spielt_noch()]
            if not offen:
                break
            time.sleep(KLINGEL_BLICK)
        dauer = time.monotonic() - beginn
        namen = ", ".join("'%s'" % g.name for g in ziel)
        if offen:
            log.info("'%s': vor dem Gong angehalten: %s - nach %.2f s noch nicht IDLE: %s, der Gong geht trotzdem",
                     self.name, namen, dauer, ", ".join("'%s'" % g.name for g in offen))
        else:
            log.info("'%s': vor dem Gong angehalten: %s - IDLE nach %.2f s", self.name, namen, dauer)
        return [g.name for g in ziel]

    def _spielt_noch(self):
        """Meldet der Lautsprecher noch PLAYING, BUFFERING oder PAUSED? (Fehler beim Lesen = nein.)"""
        try:
            st = self.cast.media_controller.status if self.cast is not None else None
        except Exception:  # noqa: BLE001
            return False
        z = str(getattr(st, "player_state", "") or "") if st is not None else ""
        return z in ("PLAYING", "BUFFERING", "PAUSED")

    def _mitglieder_fortsetzen(self, gemerkt, cfg, angehalten=()):
        """Nach Gong/Ansage der Gruppe: wer vorher spielte und jetzt UNKNOWN/IDLE meldet, wurde von der Gruppe
        uebernommen - im EIGENEN Arbeitsfaden fortsetzen (Auftrag fortsetzen_nach_gruppe, Regeln von _lage_herstellen)."""
        for g, lage in gemerkt:
            try:
                st = g.cast.media_controller.status if g.cast is not None else None
                zustand = (str(getattr(st, "player_state", "") or "") if st is not None else "") or "UNKNOWN"
                grund = str(getattr(st, "idle_reason", "") or "") if st is not None else ""
            except Exception:  # noqa: BLE001
                zustand, grund = "UNKNOWN", ""
            war_angehalten = g.name in angehalten      # 1.3.26: vor dem Gong angehalten (STOP -> IDLE)
            if zustand in ("PLAYING", "BUFFERING", "PAUSED"):
                continue
            if zustand == "IDLE" and grund.upper() == "FINISHED" and not war_angehalten:
                log.info("'%s': '%s' ist waehrenddessen regulaer zu Ende gegangen - nicht fortgesetzt", self.name, g.name)
                continue
            if war_angehalten:
                log.info("'%s': '%s' war vor dem Gong angehalten - wird fortgesetzt", self.name, g.name)
            else:
                log.info("'%s': '%s' spielte vorher und meldet jetzt %s - von der Gruppe unterbrochen (Mitglied), "
                         "wird fortgesetzt", self.name, g.name, zustand)
            try:
                g.einreihen("fortsetzen_nach_gruppe", lage, 0, cfg)
            except Exception as fehler:  # noqa: BLE001
                log.warning("'%s': Fortsetzen von '%s' nicht eingereiht: %s", self.name, g.name, fehler)

    def ansage(self, text, cfg):
        """Text ansagen. Rueckgabe: Meldung fuer das Protokoll."""
        text = " ".join(str(text or "").split())
        if text == "":
            return "Ansage ohne Text - nichts zu sagen"
        war_lokal = False
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
                # C11 (1.3.24): mit dem Port des Webservers, wenn er nicht 80 ist.
                port = webport()
                basis = "http://{0}{1}/plugins/{2}".format(
                    ip, "" if port == 80 else ":%d" % port, PLUGIN_NAME)
            adressen = [basis + "/ansage/" + name]
            modus = "chromecast"   # ab hier derselbe Weg
            # Gemerkt, weil unten anders gemeldet wird: dieser Weg ist an
            # echter Hardware nicht erprobt, und ob der Lautsprecher die
            # Adresse auf dem eigenen Webserver ueberhaupt erreicht, laesst
            # sich nur am Abspielzustand ablesen.
            war_lokal = True
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
        # Ansage-3: eine Lautstaerke aus dem Sprech-Eingang gilt nur fuer diese
        # Ansage. Mit "danach fortsetzen" stellt _lage_herstellen() sie ohnehin
        # zurueck; ohne wird hier die vorherige Lautstaerke gemerkt.
        nur_laut = (not fortsetzen) and str(cfg.get("_sprechen_laut", "")) == "1"
        self.ansage_abbrechen = False
        self.ansage_laeuft = True
        gespielt = False
        # Loxone soll wissen, dass gerade gesprochen wird - wer in dieser
        # Zeit Befehle schickt, unterbricht die Ansage.
        self._senden("tts_active", "1")
        mitglieder = []
        try:
            if fortsetzen:
                self._lage_merken()
                mitglieder = self._mitglieder_merken()      # E-NF3, nur bei einer Gruppe
            elif nur_laut:
                self._lautstaerke_merken()
            if str(ansagepegel or "").strip() != "":
                stufe = max(0, min(100, zahl_oder(ansagepegel, 40)))
                grenze = lautstaerke_grenze(cfg)
                if stufe > grenze:
                    log.info("'%s': Ansagelautstaerke %d auf die Grenze %d "
                             "gesenkt", self.name, stufe, grenze)
                    stufe = grenze
                lautstaerke_setzen(self.cast, stufe / 100.0)
                self.x7_laut = None  # X-7: die Ansage hat den Pegel gestellt
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
                if self._auf_ende_warten(mc):
                    gespielt = True
                if len(adressen) > 1:
                    log.info("'%s': Ansageteil %d von %d gesprochen",
                             self.name, nummer, len(adressen))
            if fortsetzen or nur_laut:
                self._lage_herstellen()
            if war_lokal and not gespielt:
                # Kein erfundener Erfolg. Der Lautsprecher hat den Auftrag
                # angenommen, aber nie zu spielen begonnen - das ist genau
                # das Bild, wenn er die Adresse nicht erreicht.
                return ("Ansage abgesetzt, Ergebnis unbekannt - der "
                        "Lautsprecher hat nicht zu spielen begonnen. "
                        "Meistens erreicht er die Grundadresse nicht; sie "
                        "steht im Reiter Einstellungen.")
            return "OK"
        except Exception as fehler:  # noqa: BLE001
            log.error("Ansage an '%s' fehlgeschlagen: %s", self.name, fehler_lesbar(fehler, self.name))
            return "Fehler: {0}".format(fehler_lesbar(fehler, self.name))
        finally:
            self.ansage_laeuft = False
            self.ansage_abbrechen = False
            self._senden("tts_active", "0")
            if mitglieder:
                self._mitglieder_fortsetzen(mitglieder, cfg)

    def _auf_ende_warten(self, mc, hoechstens=60.0, anlauf=8.0):
        """Warten, bis das laufende Stueck zu Ende ist.

        Die Obergrenze ist eine Notbremse: haenge der Lautsprecher in
        BUFFERING fest, wartete der Dienst sonst ewig und meldete in dieser
        Zeit keinen Zustand mehr.

        Rueckgabe: ob PLAYING oder BUFFERING ueberhaupt einmal zu sehen
        war. Wer eine Adresse abspielen laesst, die der Lautsprecher nicht
        erreicht, bekommt sonst dasselbe stille Gelingen wie bei einer
        Ansage, die wirklich gesprochen wurde.
        """
        gespielt = False
        ende = time.time() + hoechstens
        # Kurz Anlauf geben - unmittelbar nach play_media steht der Zustand
        # noch auf IDLE, und die Schleife waere sofort fertig.
        time.sleep(1.0)
        # Nachfassung 30.10.2026 (Geraetetest 17:59: Gruppe, App startete erst - nach ~1 s "Ergebnis unbekannt",
        # Rueckfall an alle): bis zum ersten PLAYING/BUFFERING zaehlt ein IDLE als "noch nicht angelaufen", bis
        # zur Anlauffrist (App-Start eingerechnet).
        anlauf_ende = time.time() + max(0.0, anlauf - 1.0)
        while time.time() < ende:
            try:
                zustand = mc.status.player_state if mc.status else "IDLE"
            except Exception:  # noqa: BLE001
                return gespielt
            if zustand not in ("PLAYING", "BUFFERING"):
                if not gespielt and time.time() < anlauf_ende and not self.ansage_abbrechen:
                    time.sleep(0.25)
                    continue
                return gespielt
            gespielt = True
            if self.ansage_abbrechen:
                try:
                    mc.stop()
                except Exception:  # noqa: BLE001
                    pass
                return gespielt
            time.sleep(0.5)
        log.warning("'%s': Ansage laeuft nach %.0f s noch - es wird weitergemacht",
                    self.name, hoechstens)
        return True

    # -- Audio Stufe 1: Zonen, Quellen, Klingel (1.3.25) ------------------
    #
    # Eine Zone ist eine feste Nummer, die auf DIESES Geraet (oder diese Google-Gruppe) zeigt (E1). Ihre Themen
    # chromecast4lox/zone/<n>/... sind Spiegel der Geraetethemen, die in der Themenliste unter "zone" stehen; "group"
    # heisst dort "art". Gesendet wird, wenn das Geraet sendet - derselbe Doppelt-senden-Filter, dieselbe
    # Retain-Regel je Thema, Platzhalter nie retained. quelle und quelle_name gibt es nur an der Zone.

    def _zonen_spiegeln(self, schluessel, wert, platzhalter=False):
        if not self.zonen_nr:
            return
        k = "art" if schluessel == "group" else schluessel
        if thema_info("zone", k) is None:
            return
        for nr in list(self.zonen_nr):
            self.mqtt.senden("zone/%d/%s" % (nr, k), wert, thema_retain("zone", k) and not platzhalter)

    def _zone_senden(self, schluessel, wert, erzwingen=False):
        """Ein Thema, das es nur an der Zone gibt (quelle, quelle_name)."""
        if not self.zonen_nr:
            return
        wert = "" if wert is None else str(wert)
        merk_k = "\0zone:" + schluessel
        if not erzwingen and self.letzter_stand.get(merk_k) == wert:
            return
        self.letzter_stand[merk_k] = wert
        for nr in list(self.zonen_nr):
            self.mqtt.senden("zone/%d/%s" % (nr, schluessel), wert, thema_retain("zone", schluessel))

    def _quelle_setzen(self, nummer, name):
        self.quelle = int(nummer)
        self.quelle_name = str(name or "")[:120]
        self._zone_senden("quelle", self.quelle)
        self._zone_senden("quelle_name", self.quelle_name)

    def quelle_spielen(self, wert, cfg):
        """Befehl quelle (S3): 0 = Stopp, sonst die Quelle dieser Nummer. Rueckgabe "OK" oder Meldung."""
        nummer, fehlertext = befehlszahl(wert, 0, 0, QUELLEN_MAX, "quelle")
        if fehlertext:
            return fehlertext
        mc = self.cast.media_controller
        if nummer <= 0:
            # Radiotasten "Aus" - und ein Ausgang, der nach dem Neustart des Miniservers auf 0 steht, haelt nur an.
            try:
                mc.stop()
            except Exception:  # noqa: BLE001
                pass
            self._quelle_setzen(0, "")
            return "OK"
        liste = quellen(cfg)
        q = liste.get(nummer)
        if q is None:
            return "Quelle {0} gibt es nicht - eingetragen sind {1}".format(nummer, len(liste))
        if q["art"] == "radio":
            if not re.match(r"^https?://", q["adresse"], re.I):
                return "Quelle {0}: eine Radioquelle braucht eine http(s)-Adresse".format(nummer)
            adresse, typ, hinweis = radio_aufloesen(q["adresse"])
            if hinweis:
                log.info("'%s': Quelle %d: %s", self.name, nummer, hinweis)
            mc.play_media(adresse, typ, title=q["name"], stream_type="LIVE")
            mc.block_until_active(timeout=10)
        elif q["art"] == "datei":
            if re.match(r"^https?://", q["adresse"], re.I):
                adresse, typ = q["adresse"], inhaltstyp(q["adresse"]) or "audio/mpeg"
            else:
                if not ABLAGE_STAND.get("ok"):
                    return "Quelle {0}: Medienablage nicht erreichbar ({1})".format(
                        nummer, ABLAGE_STAND.get("grund") or "nicht eingerichtet")
                echt = ablage_datei(q["adresse"])
                typ = inhaltstyp(q["adresse"])
                if echt is None or not os.path.isfile(echt) or typ is None:
                    return "Quelle {0}: Datei fehlt in der Medienablage oder ist keine Audiodatei".format(nummer)
                lesefehler = ablage_lesbar(ABLAGE_STAND.get("pfad") or "", datei=echt)     # 1.3.26 (Befund 5)
                if lesefehler:
                    return self._ablage_haengt(nummer, lesefehler)
                adresse = ablage_adresse(cfg, q["adresse"], self._geraete_adresse())
                if adresse == "":
                    return "Quelle {0}: Adresse der Medienablage nicht zu bilden (eigene Adresse unbekannt)".format(nummer)
            mc.play_media(adresse, typ, title=q["name"])
            mc.block_until_active(timeout=10)
        else:
            if not ABLAGE_STAND.get("ok"):
                return "Quelle {0}: Medienablage nicht erreichbar ({1})".format(
                    nummer, ABLAGE_STAND.get("grund") or "nicht eingerichtet")
            eintraege, grund = liste_aufbauen(q)
            if grund:
                return "Quelle {0}: {1}".format(nummer, grund)
            # 1.3.26 (Befund 5): den ersten Titel der Ablage einmal lesen - die Liste selbst kam aus dem Verzeichnis.
            erste = next((e[0] for e in eintraege if not re.match(r"^https?://", e[0], re.I)), None)
            echt = ablage_datei(erste) if erste else None
            lesefehler = ablage_lesbar(ABLAGE_STAND.get("pfad") or "", datei=echt) if echt else ""
            if lesefehler:
                return self._ablage_haengt(nummer, lesefehler)
            grund = self._liste_laden(eintraege, cfg)
            if grund:
                return "Quelle {0}: {1}".format(nummer, grund)
        if q["art"] in ("datei", "liste"):
            # 1.3.26 (Befund 5): "gestartet" erst, wenn der Lautsprecher auch spielt.
            kam, zustand = self._wiedergabe_kam()
            if not kam:
                log.warning("'%s': Quelle %d (%s, %s): Wiedergabe kam nicht zustande (Lautsprecher meldet %s)",
                            self.name, nummer, q["name"], q["art"], zustand)
                self._quelle_setzen(0, "")
                return ("Quelle {0}: Wiedergabe kam nicht zustande (Lautsprecher meldet {1}) - Medienablage "
                        "und Grundadresse pruefen".format(nummer, zustand))
        log.info("'%s': Quelle %d (%s, %s) gestartet", self.name, nummer, q["name"], q["art"])
        self._quelle_setzen(nummer, q["name"])
        return "OK"

    def _ablage_haengt(self, nummer, grund):
        """1.3.26 (Befund 5): eine Datei der Ablage liess sich beim Start einer Quelle nicht lesen (z. B. "Stale file
        handle" nach Ab-/Anstecken der SSD an der FRITZ!Box). Die Ablage gilt dann als nicht freigegeben
        (server/ablage_ok 0, Grund im Reiter Test); die Vollmeldung gibt sie wieder frei, sobald sie lesbar ist."""
        if ABLAGE_STAND.get("ok") or ABLAGE_STAND.get("grund") != grund:
            log.warning("Medienablage: %s", grund)
        ABLAGE_STAND["ok"] = 0
        ABLAGE_STAND["grund"] = grund
        try:
            self.mqtt.senden("server/ablage_ok", 0, thema_retain("dienst", "ablage_ok"))
        except Exception:  # noqa: BLE001
            pass
        return "Quelle {0}: {1}".format(nummer, grund)

    def _wiedergabe_kam(self, blicke=40):
        """1.3.26 (Befund 5): nach dem Start einer Datei- oder Listenquelle bis zu ~8 s nachsehen, ob der Lautsprecher
        spielt. Hoertest 01.11.: die Freigabe hing, Apache antwortete 403, der Lautsprecher spielte nur den
        Verbindungston - und das Plugin meldete "gestartet". PLAYING = ja; IDLE mit Grund ERROR = sofort nein;
        BUFFERING am Ende zaehlt als ja (laedt noch). Rueckgabe (ja/nein, zuletzt gemeldeter Zustand)."""
        mc = self.cast.media_controller
        zuletzt = ""
        for _ in range(blicke):
            try:
                st = mc.status
            except Exception:  # noqa: BLE001
                st = None
            zuletzt = str(getattr(st, "player_state", "") or "") if st is not None else ""
            if zuletzt == "PLAYING":
                return True, zuletzt
            if zuletzt == "IDLE" and str(getattr(st, "idle_reason", "") or "").upper() == "ERROR":
                return False, "IDLE/ERROR"
            time.sleep(0.2)
        return zuletzt == "BUFFERING", zuletzt or "keinen Zustand"

    def _liste_laden(self, eintraege, cfg):
        """Eine Wiedergabeliste als Cast-Warteschlange (S3): der erste Titel mit LOAD, die uebrigen mit QUEUE_INSERT
        (play_media(..., enqueue=True), pychromecast 9.4 und neuer). Danach wirken next/prev. Rueckgabe "" oder Grund."""
        mc = self.cast.media_controller
        ip = self._geraete_adresse()
        titel = []
        for ziel, name, interpret in eintraege:
            if re.match(r"^https?://", ziel, re.I):
                url, typ = ziel, inhaltstyp(ziel) or "audio/mpeg"
            else:
                url, typ = ablage_adresse(cfg, ziel, ip), inhaltstyp(ziel)
            if not url or not typ:
                continue
            meta = {"metadataType": 3, "title": name or os.path.basename(ziel)}
            if interpret:
                meta["artist"] = interpret
            titel.append((url, typ, meta))
        if not titel:
            return "keine abspielbare Datei in der Liste"
        self.liste_titel = titel
        grund = self._liste_ab(0)
        if not grund:
            log.info("'%s': Wiedergabeliste mit %d Titeln geladen", self.name, len(titel))
        return grund

    def _liste_ab(self, start, zeit=0.0, autoplay=True):
        """Die eigene Liste ab Titel <start> spielen: diesen mit LOAD, die folgenden mit QUEUE_INSERT. Eingereiht wird
        erst, wenn der Lautsprecher fuer den neuen Titel eine NEUE Sitzungsnummer meldet (sonst gingen die Eintraege
        an die alte Sitzung), und mit kleinem Abstand. Rueckgabe "" oder Grund."""
        mc = self.cast.media_controller
        titel = list(getattr(self, "liste_titel", None) or [])[start:]
        if not titel:
            return "keine abspielbare Datei in der Liste"
        alt = getattr(mc.status, "media_session_id", None) if mc.status is not None else None
        url, typ, meta = titel[0]
        if zeit and zeit > 0:
            mc.play_media(url, typ, metadata=dict(meta), current_time=zeit, autoplay=autoplay)
        else:
            mc.play_media(url, typ, metadata=dict(meta), autoplay=autoplay)
        mc.block_until_active(timeout=10)
        ende = time.time() + 5.0
        while time.time() < ende and getattr(mc.status, "media_session_id", None) in (None, alt):
            time.sleep(0.2)
        if len(titel) > 1 and not getattr(mc.status, "media_session_id", None):
            return "der Lautsprecher hat den ersten Titel nicht angenommen - die Warteschlange fehlt"
        for url, typ, meta in titel[1:]:
            mc.play_media(url, typ, metadata=dict(meta), enqueue=True)
            time.sleep(0.3)
        return ""

    def _liste_index(self, adresse):
        """Stelle einer Adresse in der eigenen Liste oder None."""
        urls = [t[0] for t in (getattr(self, "liste_titel", None) or [])]
        return urls.index(adresse) if adresse and adresse in urls else None

    def _liste_schritt(self, schritt):
        """next/prev in einer Wiedergabeliste des Plugins (Nachfassung 30.10.2026; Geraetetest: next auf Titel 2 von 3
        -> sofort Stille, IDLE, prev ging). Das Ziel kommt aus der EIGENEN Liste, nicht aus der Warteschlange des
        Lautsprechers: laufender Titel = content_id, dann das Ziel mit LOAD und der Rest dahinter. Weiter nach dem
        letzten Titel haelt an (wie das Ende der Liste); Zurueck auf dem ersten beginnt ihn neu. Rueckgabe None =
        es laeuft keine eigene Liste (dann wie bisher queue_next/queue_prev), "" = erledigt, sonst Grund."""
        titel = getattr(self, "liste_titel", None) or []
        mc = self.cast.media_controller
        jetzt = str(getattr(mc.status, "content_id", "") or "") if mc.status is not None else ""
        urls = [t[0] for t in titel]
        if jetzt == "" or jetzt not in urls:
            return None
        ziel = urls.index(jetzt) + schritt
        if ziel >= len(titel):
            mc.stop()
            log.info("'%s': Weiter nach dem letzten Titel - Liste zu Ende, angehalten", self.name)
            return ""
        ziel = max(0, ziel)
        grund = self._liste_ab(ziel)
        if not grund:
            log.info("'%s': Titel %d von %d", self.name, ziel + 1, len(titel))
        return grund

    def klingeln(self, cfg):
        """Befehl klingel - 1.3.26 (Befund 2) mit einer sofortigen Neusuche: steht das Ziel nicht sendebereit da (eine
        geloeschte und neu angelegte Gruppe, "is connecting"), wird es einmal verworfen, ueber die Dauersuche neu
        gesucht und dann geklingelt; scheitert der Versuch selbst an "is connecting", ebenso einmal. Beides zusammen
        hoechstens KLINGEL_NEUSUCHE s (keine Einzelsuche, die kostet 8 s) - danach meldet die Klingel den Fehler, und der
        Ersatzweg des Dienstes greift wie bisher. Die Klingel selbst steht in _klingeln()."""
        beginn = time.monotonic()
        neu = False
        if self.haengt() > 0:
            neu = True
            if not self.neu_suchen("vor der Klingel noch nicht verbunden", frist=KLINGEL_NEUSUCHE) \
                    or not self.verbunden():
                return "Fehler: {0} - neu gesucht, nicht gefunden".format(
                    fehler_lesbar(RuntimeError("is connecting"), self.name))
        self._klingel_nv = False
        meldung = self._klingeln(cfg)
        rest = KLINGEL_NEUSUCHE - (time.monotonic() - beginn)
        if meldung != "OK" and self._klingel_nv and not neu:
            if rest > 0.2 and self.neu_suchen("Klingel scheiterte, noch nicht verbunden", frist=rest) \
                    and self.verbunden():
                meldung = self._klingeln(cfg)
            else:
                meldung = "Fehler: {0} - neu gesucht, nicht gefunden".format(
                    fehler_lesbar(RuntimeError("is connecting"), self.name))
        if meldung == "OK":
            self.haengt_fehler = 0
        return meldung

    def _klingeln(self, cfg):
        """Befehl klingel (S5, E3-E5): Gong (Feld Klingel-Gong, sonst der Ansage-Gong), optional ein Ansagetext danach.
        Pegel: Klingel-Pegel, gedeckelt von Obergrenze und Ruhezeit - die Klingel geht in der Ruhezeit mit dem
        Ruhepegel hinaus, nicht gar nicht (E5); wer nachts nichts will, setzt die Sperre aus Loxone. Was vorher lief,
        wird wie bei der Ansage fortgesetzt (Haken "danach fortsetzen"); bei einer Google-Gruppe dazu die Lautsprecher,
        die sie unterbrochen hat (E-NF3, Nachfassung 2)."""
        gong = klingel_gong(cfg, self._geraete_adresse())
        if gong is None:
            # Nachfassung 7: fehlt eine Datei, klingt der eigene bzw. der Standard-Gong - None nur ohne eigene Adresse.
            return ("Klingel: kein Gong - die eigene Adresse liess sich nicht ermitteln (Feld Grundadresse der "
                    "oertlichen Ansage eintragen) oder der Klangordner ist nicht beschreibbar")
        if gong[2]:
            log.warning("'%s': %s", self.name, gong[2])
        text = str(cfg.get("klingel_text", "") or "").strip()
        pegel_roh = str(cfg.get("klingel_pegel", "") or "").strip()
        fortsetzen = str(cfg.get("tts_fortsetzen", "1")).strip() == "1"
        grenze = lautstaerke_grenze(cfg)
        self.ansage_abbrechen = False
        self.ansage_laeuft = True
        self._senden("tts_active", "1")
        gespielt = False
        mitglieder = []
        angehalten = []
        try:
            if fortsetzen:
                self._lage_merken()
                mitglieder = self._mitglieder_merken()      # E-NF3, nur bei einer Gruppe
            else:
                self._lautstaerke_merken()
            # 1.3.26 (Befund 1): spielende Mitglieder der Gruppe vor dem Gong anhalten - sonst kommt er dort versetzt.
            # Nur mit "danach fortsetzen" (sonst ist mitglieder leer): angehalten wird nur, was danach weiterspielt.
            angehalten = self._mitglieder_anhalten(mitglieder)
            jetzt = self.cast.status.volume_level if self.cast.status else None
            stufe = None
            if pegel_roh != "":
                stufe = max(0, min(100, zahl_oder(pegel_roh, 40)))
            elif jetzt is not None and int(round(jetzt * 100)) > grenze:
                stufe = grenze
            if stufe is not None:
                if stufe > grenze:
                    log.info("'%s': Klingelpegel %d auf die Grenze %d gesenkt", self.name, stufe, grenze)
                    stufe = grenze
                lautstaerke_setzen(self.cast, stufe / 100.0)
                self.x7_laut = None
            mc = self.cast.media_controller
            mc.play_media(gong[0], gong[1], title="Klingel")
            mc.block_until_active(timeout=10)
            gespielt = self._auf_ende_warten(mc, 20.0)
            if text and not self.ansage_abbrechen:
                # Dieselbe Sprachausgabe wie tts - ohne zweiten Gong, ohne eigenes Merken (das tut die Klingel).
                meldung = self.ansage(text, dict(cfg, tts_gong="", tts_fortsetzen="0", tts_pegel="",
                                                 _sprechen_laut=""))
                self.ansage_laeuft = True
                if meldung != "OK":
                    log.warning("'%s': Ansage nach der Klingel: %s", self.name, meldung)
            self._lage_herstellen()
            if not gespielt:
                return ("Klingel abgesetzt, Ergebnis unbekannt - der Lautsprecher hat nicht zu spielen begonnen "
                        "(Gong-Adresse erreichbar?)")
            return "OK"
        except Exception as fehler:  # noqa: BLE001
            self._klingel_nv = self.nv_vermerken(fehler)     # 1.3.26: klingeln() sucht dann einmal neu
            log.error("Klingel an '%s' fehlgeschlagen: %s", self.name, fehler_lesbar(fehler, self.name))
            return "Fehler: {0}".format(fehler_lesbar(fehler, self.name))
        finally:
            self.ansage_laeuft = False
            self.ansage_abbrechen = False
            self._senden("tts_active", "0")
            if mitglieder:
                self._mitglieder_fortsetzen(mitglieder, cfg, angehalten)

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
                self._quelle_setzen(0, "")
            elif befehl == "quit":
                self.cast.quit_app()
                self._quelle_setzen(0, "")
            # Zahlen aus MQTT und UDP (seit 1.3.13, C5/C8/M2): nur endliche
            # Werte im Bereich, sonst abgewiesen und gemeldet - ohne die
            # Verbindung zu trennen. Die Bereiche sind die der Befehlsliste
            # (bin/cc_themen.json); fuer volume_up/volume_down ist der Wert
            # eine Schrittweite 0 bis 100 (eigene Wahl, wie volume_step).
            elif befehl in ("volume", "set_volume"):
                jetzt_proz = int(round((self.cast.status.volume_level or 0) * 100)) \
                    if self.cast.status else 0
                pegel, fehlertext = befehlszahl(wert, jetzt_proz, 0, 100, befehl)
                if fehlertext:
                    return fehlertext
                # X-7 (Entscheidung Nr. 19): derselbe wirksame Pegel innerhalb
                # von 60 s geht nicht erneut hinaus - siehe _x7_gleich().
                seit = self._x7_gleich(min(pegel, lautstaerke_grenze(cfg or {})))
                if seit is not None:
                    log.info("'%s': %s %s - UNVERAENDERT=1, derselbe Pegel ging vor "
                             "%.0f s hinaus (X-7), nichts gesendet", self.name, befehl,
                             pegel, seit)
                    return "OK"
                gesetzt = self._gedeckelt(pegel, cfg)
                lautstaerke_setzen(self.cast, gesetzt / 100.0)
                self._x7_merken(gesetzt)
            elif befehl in ("volume_step", "adjust_volume"):
                delta, fehlertext = befehlszahl(wert, schrittweite, -100, 100, befehl)
                if fehlertext:
                    return fehlertext
                jetzt = self.cast.status.volume_level if self.cast.status else 0
                pegel = max(0, min(100, int(round(jetzt * 100)) + delta))
                gesetzt = self._gedeckelt(pegel, cfg)
                lautstaerke_setzen(self.cast, gesetzt / 100.0)
                self._x7_merken(gesetzt)
            elif befehl in ("volume_up", "lauter"):
                # Ein negativer Schritt wird abgewiesen: "lauter -60" war bis
                # 1.3.12 ein zweiter Weg, leiser zu werden.
                delta, fehlertext = befehlszahl(wert, schrittweite, 0, 100, befehl)
                if fehlertext:
                    return fehlertext
                jetzt = self.cast.status.volume_level if self.cast.status else 0
                pegel = int(round(min(1.0, jetzt + delta / 100.0) * 100))
                gesetzt = self._gedeckelt(pegel, cfg)
                lautstaerke_setzen(self.cast, gesetzt / 100.0)
                self._x7_merken(gesetzt)
            elif befehl in ("volume_down", "leiser"):
                # Ueber _gedeckelt() wie jeder andere Lautstaerkebefehl, und ein
                # negativer Schritt wird abgewiesen (seit 1.3.13, C8). Bis 1.3.12
                # umging "volume_down -60" bei Grenze 30 die Obergrenze und die
                # Ruhezeit: 0,8 statt 0,3, mit -500 sogar 5,2 (gemessen
                # 30.09.2026, Code-Befund 8).
                delta, fehlertext = befehlszahl(wert, schrittweite, 0, 100, befehl)
                if fehlertext:
                    return fehlertext
                jetzt = self.cast.status.volume_level if self.cast.status else 0
                pegel = max(0, int(round(jetzt * 100)) - delta)
                gesetzt = self._gedeckelt(pegel, cfg)
                lautstaerke_setzen(self.cast, gesetzt / 100.0)
                self._x7_merken(gesetzt)
            elif befehl == "mute":
                stumm_setzen(self.cast, str(wert).strip() not in ("0", "", "false", "aus"))
            elif befehl in ("next", "prev", "previous"):
                # Nachfassung 30.10.2026: in einer eigenen Liste aus der Liste, sonst die Warteschlange.
                grund = self._liste_schritt(1 if befehl == "next" else -1)
                if grund is None:
                    if befehl == "next":
                        mc.queue_next()
                    else:
                        mc.queue_prev()
                elif grund:
                    return grund
            elif befehl == "seek":
                stelle, fehlertext = befehlszahl(wert, 0, 0, 86400, befehl)
                if fehlertext:
                    return fehlertext
                mc.seek(stelle)
            elif befehl in ("play_favorit", "favorit"):
                liste = favoriten(cfg or {})
                nummer, fehlertext = befehlszahl(wert, 0, 0, 99, befehl)
                if fehlertext:
                    return fehlertext
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
            # Audio Stufe 1 (S3, S5)
            elif befehl == "quelle":
                meldung = self.quelle_spielen(wert, cfg or {})
                if meldung != "OK":
                    return meldung
            elif befehl == "klingel":
                return self.klingeln(cfg or {})
            elif befehl == "fortsetzen_nach_gruppe" and isinstance(wert, dict):
                # E-NF3: nur intern (Wert = gemerkte Lage); von MQTT/UDP kommt hier Text an -> unbekannt.
                self.vorher = wert
                self._lage_herstellen()
            elif befehl in ("tts", "say", "ansage"):
                return self.ansage(wert, cfg or {})
            else:
                return "Unbekannter Befehl '{0}'".format(befehl)
        except Exception as fehler:  # noqa: BLE001
            log.error("Befehl %s an '%s' fehlgeschlagen: %s", befehl, self.name, fehler_lesbar(fehler, self.name))
            self.trennen()
            return "Fehler: {0}".format(fehler_lesbar(fehler, self.name))

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
        # Abgewiesene Absender: Adresse -> [zuletzt gemeldet, seither verworfen]
        self.fremde = {}

    def fremd_melden(self, adresse):
        """Ein fremder Absender, gebremst: die erste Nachricht sofort, danach
        hoechstens einmal je Stunde mit der Zahl der verworfenen."""
        jetzt = time.time()
        eintrag = self.fremde.get(adresse)
        if eintrag is None or jetzt - eintrag[0] >= 3600:
            zusatz = "" if eintrag is None or not eintrag[1] else \
                " (seit der letzten Meldung %d weitere verworfen)" % eintrag[1]
            log.warning("UDP von %s verworfen: nur der Miniserver (general.json) und "
                        "127.0.0.1 duerfen Befehle schicken%s", adresse, zusatz)
            self.fremde[adresse] = [jetzt, 0]
        else:
            eintrag[1] += 1

    def run(self):
        # Kein SO_REUSEADDR mehr (seit 1.3.13, C7): damit banden zwei Dienste
        # denselben Port, und ein UDP-Befehl kam nur bei einem an (gemessen
        # 30.09.2026, Code-Befund 7, T3). Jetzt scheitert ein zweiter sichtbar.
        # OverflowError: ein Port ausserhalb 0-65535 (Code-Befund 6).
        try:
            self.sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
            self.sock.bind(("0.0.0.0", self.port))
            self.sock.settimeout(1.0)
            log.info("UDP-Befehle werden auf Port %s entgegengenommen (Absender: %s)",
                     self.port, ", ".join(sorted(self.dienst.udp_absender)))
        except (OSError, OverflowError) as fehler:
            log.error("UDP-Port %s nicht belegbar: %s", self.port, fehler)
            return

        while self.laeuft:
            try:
                daten, absender = self.sock.recvfrom(2048)
            except socket.timeout:
                continue
            except OSError:
                break

            # Nur der Miniserver und dieser Rechner (seit 1.3.13, M10; Frage
            # 18 vom 29.09.2026). Bis 1.3.12 konnte jedes Geraet im Heimnetz
            # ab Werk alle Lautsprecher auf 100 % stellen und Adressen
            # abspielen lassen (gemessen 30.09.2026, Pruefung MQTT M10).
            if absender[0] not in self.dienst.udp_absender:
                self.fremd_melden(absender[0])
                continue
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
                self.dienst.befehl_ausfuehren(geraet, befehl, wert,
                                              quelle="udp:" + str(absender[0]))

    def stop(self):
        self.laeuft = False
        try:
            if self.sock:
                self.sock.close()
        except Exception:  # noqa: BLE001
            pass


# ---------------------------------------------------------------------------
# Sperre aus Loxone (B1, 1.3.22; Muster Alexa-NG 1.0.4, ax_loxsperre_*)
# ---------------------------------------------------------------------------
#
# Loxone haelt die Ansagen an, etwa bei Abwesend, Gaesten oder Schlafen:
# <praefix>/server/cmd/sperre mit 1 (sperren) oder 0 (aufheben), per UDP
# "server/SPERRE 1;", am Endpunkt aktion=sperre (webfrontend/html/index.php,
# Sprechtoken, nur von diesem LoxBerry). Ab Werk ohne Wirkung: nur mit dem
# Haken sperre_ein (Reiter Einstellungen) wird ein Wert angenommen, und nur
# dann wirkt er. Gesperrt werden Ansagen - tts aus Loxone und aktion=sprechen;
# am Endpunkt geht dringend=1 durch. Musik, Lautstaerke und alle uebrigen
# Befehle bleiben frei, wie bei Alexa-NG. Der Zustand liegt in
# data/plugins/<ordner>/loxsperre.json - EINE Datei fuer Dienst und Endpunkt
# (cc_loxsperre_lesen() in cc_lib.php liest dieselbe Form). Kein Wert (frische
# Installation; der Datenordner ist nach einem Update leer) heisst "offen",
# bis Loxone ihn setzt; der Reiter Test zeigt das an. Ein MQTT-Zustandsthema
# dazu gibt es bewusst nicht: die Vorlagen und das Musterprojekt bleiben
# unveraendert, und Loxone kennt den Wert, den es selbst gesetzt hat.

LOXSPERRE_WERTE = ("0", "1")


def _loxsperre_quelle(text):
    return re.sub(r"[^A-Za-z0-9_.:\-]", "", str(text))[:60]


def loxsperre_lesen():
    """Der zuletzt gesetzte Zustand: dict(bekannt, gesperrt, seit, zuletzt, quelle)."""
    aus = {"bekannt": False, "gesperrt": 0, "seit": 0, "zuletzt": 0, "quelle": ""}
    if not LOXSPERRE_FILE:
        return aus
    try:
        with open(LOXSPERRE_FILE, "r", encoding="utf-8") as fh:
            d = json.loads(fh.read(4096))
    except (OSError, ValueError):
        return aus
    if not isinstance(d, dict):
        return aus
    g = d.get("gesperrt")
    if isinstance(g, bool) or g not in (0, 1):
        return aus
    aus["bekannt"] = True
    aus["gesperrt"] = g
    for k in ("seit", "zuletzt"):
        v = d.get(k)
        aus[k] = v if isinstance(v, int) and not isinstance(v, bool) else 0
    aus["quelle"] = _loxsperre_quelle(d.get("quelle", ""))
    return aus


def loxsperre_wirkt(cfg):
    """Wirkt die Sperre jetzt? Nur mit Haken und einem gesetzten Wert 1."""
    if str((cfg or {}).get("sperre_ein", "0")).strip() != "1":
        return False
    s = loxsperre_lesen()
    return s["bekannt"] and s["gesperrt"] == 1


def loxsperre_setzen(cfg, wert, quelle):
    """Die Sperre setzen (MQTT server/cmd/sperre, UDP server/SPERRE). Wert '0'
    oder '1' - alles andere wird abgewiesen, nie umgedeutet. Rueckgabe
    (grund, gesperrt): grund '' heisst gesetzt."""
    herkunft = _loxsperre_quelle(quelle)
    if str((cfg or {}).get("sperre_ein", "0")).strip() != "1":
        log.warning("Sperre aus Loxone ist ausgeschaltet (Reiter Einstellungen) - "
                    "Befehl sperre von %s verworfen", herkunft)
        return "SPERRE_AUS", None
    w = "" if wert is None else str(wert).strip()
    if w not in LOXSPERRE_WERTE:
        log.warning("Befehl sperre von %s: Wert '%s' ist nicht 0 oder 1 - abgewiesen",
                    herkunft, w[:20])
        return "WERT_SPERRE", None
    if not LOXSPERRE_FILE:
        return "SPEICHERN", None
    alt = loxsperre_lesen()
    neu = int(w)
    gleich = alt["bekannt"] and alt["gesperrt"] == neu
    jetzt = int(time.time())
    daten = {"gesperrt": neu, "seit": alt["seit"] if gleich else jetzt,
             "zuletzt": jetzt, "quelle": herkunft}
    vorlaeufig = "%s.tmp.%d" % (LOXSPERRE_FILE, os.getpid())
    try:
        os.makedirs(DATA_DIR, exist_ok=True)
        # 0644: die Datei traegt keine Geheimnisse, und die Oberflaeche liest
        # sie. Rechte VOR dem Inhalt, dann umbenennen (Regeln/03).
        fd = os.open(vorlaeufig, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o644)
        if hasattr(os, "fchmod"):
            os.fchmod(fd, 0o644)
        with os.fdopen(fd, "w", encoding="utf-8") as fh:
            json.dump(daten, fh)
        os.replace(vorlaeufig, LOXSPERRE_FILE)
    except OSError as fehler:
        try:
            os.remove(vorlaeufig)
        except OSError:
            pass
        log.warning("Sperre aus Loxone nicht speicherbar: %s", fehler)
        return "SPEICHERN", None
    if not gleich:
        log.info("Sperre aus Loxone: %s (von %s)", "gesperrt" if neu else "offen", herkunft)
    return "", neu


# ---------------------------------------------------------------------------
# Sprech-Eingang fuer andere Plugins (Punkt Ansage-3, 01.10.2026)
# ---------------------------------------------------------------------------
#
# Der Endpunkt webfrontend/html/index.php (aktion=sprechen, dieselbe
# Schnittstelle wie Alexa-NG) legt je Ansage eine Auftragsdatei
# <kennung>.auftrag (0600) nach data/plugins/<ordner>/sprechen/ und wartet
# hoechstens fuenf Sekunden auf <kennung>.antwort. Dieser Faden holt den
# Auftrag ab, indem er ihn nach <kennung>.inarbeit UMBENENNT: wer zuerst
# umbenennt, hat ihn. Zieht der Endpunkt den Auftrag nach Ablauf seiner Frist
# zurueck (unlink), scheitert das Umbenennen hier, und es wird nichts
# gesprochen - nie eine Ansage, die der Aufrufer schon als gescheitert
# gemeldet bekam. Die Datei wird sofort geloescht; der Text bleibt nicht
# liegen und steht nie im Protokoll (nur seine Laenge).
#
# Eingereiht wird mit Geraet.einreihen("tts", ...) - derselbe Weg wie cmd/tts
# aus Loxone: dieselbe Ruhezeit und Obergrenze der Lautstaerke, derselbe Klang
# vorab, dieselbe Wiederaufnahme, dieselbe Warteschlange je Geraet. Eine
# zweite Abspiel-Logik gibt es nicht. Neu ist nur "laut" (Lautstaerke fuer
# diese eine Ansage, als tts_pegel einer Kopie der Einstellungen).
#
# Warum nicht ueber MQTT cmd/tts: der Weg gibt keine Antwort, haengt am
# Schalter "MQTT ein" und am laufenden Broker, und jede Ansage stuende im
# Broker fuer jeden Abonnenten lesbar. Warum nicht UDP: ";" zerlegt den Text,
# und es gibt ebenfalls keine Antwort.
#
# Die Antwort sagt: eingereiht bei n Geraeten, m nicht verbunden (ausgelassen
# und gezaehlt, wie "offline" bei Alexa-NG). Ein nicht verbundenes Geraet
# bekommt die Ansage nicht - ein Verbindungsversuch kostete bis zu 18 s.
# Das Ergebnis der Wiedergabe kommt spaeter; es steht je Geraet in
# sprechen_ergebnis.json (Reiter Test), ohne Text.

SPRECHEN_HOECHSTALTER = 10      # Sekunden; ein aelterer Auftrag wird verworfen
SPRECHEN_TAKT = 0.2             # Sekunden zwischen zwei Blicken in den Ordner


def _ohne_adresse(text):
    """Eine Meldung ohne Adressen: in einer Google-Adresse stuende der Text."""
    return re.sub(r"[a-zA-Z][a-zA-Z0-9+.-]*://\S+", "<Adresse>", str(text))


class SprechEingang(threading.Thread):
    """Holt Sprechauftraege des Endpunkts ab und reiht sie ein."""

    def __init__(self, dienst):
        super().__init__(daemon=True, name="cc-sprechen")
        self.dienst = dienst
        self.laeuft = True
        self.schloss = threading.Lock()

    def run(self):
        try:
            os.makedirs(SPRECHEN_ORDNER, mode=0o700, exist_ok=True)
        except OSError as fehler:
            log.warning("Sprech-Eingang %s nicht anlegbar: %s", SPRECHEN_ORDNER, fehler)
        log.info("Sprech-Eingang fuer andere Plugins bereit: %s", SPRECHEN_ORDNER)
        while self.laeuft and self.dienst.laeuft:
            try:
                self.durchgang()
            except Exception as fehler:  # noqa: BLE001
                log.error("Sprech-Eingang: %s", fehler)
            if self.dienst.halt.wait(SPRECHEN_TAKT):
                break

    def durchgang(self):
        try:
            namen = sorted(os.listdir(SPRECHEN_ORDNER))
        except OSError:
            return
        jetzt = time.time()
        for name in namen:
            if re.match(r"^[0-9a-f]{16,40}\.auftrag$", name):
                self.auftrag(name[:-len(".auftrag")])
                continue
            # Liegengebliebenes nach einer Minute weg: eine Antwort, die
            # niemand abgeholt hat, eine abgebrochene Nebendatei.
            pfad = os.path.join(SPRECHEN_ORDNER, name)
            try:
                if jetzt - os.path.getmtime(pfad) > 60:
                    os.remove(pfad)
            except OSError:
                pass

    def auftrag(self, kennung):
        quelle = os.path.join(SPRECHEN_ORDNER, kennung + ".auftrag")
        arbeit = os.path.join(SPRECHEN_ORDNER, kennung + ".inarbeit")
        try:
            os.rename(quelle, arbeit)
        except OSError:
            return              # vom Endpunkt zurueckgezogen
        daten = None
        try:
            with open(arbeit, "r", encoding="utf-8") as fh:
                daten = json.loads(fh.read(65536))
        except (OSError, ValueError):
            daten = None
        finally:
            try:
                os.remove(arbeit)
            except OSError:
                pass
        self.antworten(kennung, self.annehmen(kennung, daten))

    def annehmen(self, kennung, d):
        aus = {"id": kennung, "eingereiht": [], "nicht_verbunden": 0,
               "unbekannt": [], "grund": ""}
        if not isinstance(d, dict):
            aus["grund"] = "AUFTRAG_KAPUTT"
            log.warning("Sprechauftrag %s nicht lesbar - verworfen", kennung)
            return aus
        # B2 (1.3.22): aktion=lautstaerke kommt ueber denselben Eingang.
        if d.get("art") == "lautstaerke":
            return self.annehmen_lautstaerke(kennung, d, aus)
        # Gruppe 6b (1.3.24, E1): Befehle der Befehlsbruecke ueber denselben Eingang.
        if d.get("art") == "befehl":
            return self.annehmen_befehl(kennung, d, aus)
        text = d.get("text")
        geraete = d.get("geraete")
        laut = d.get("laut")
        zeit = d.get("zeit")
        herkunft = "%s %s" % (str(d.get("quelle", "?"))[:20], str(d.get("wer", "?"))[:46])
        if (not isinstance(text, str) or not 1 <= len(text) <= 1000
                or re.search(r"[\x00-\x1f\x7f]", text)
                or not isinstance(geraete, list) or not geraete or len(geraete) > 64
                or any(not isinstance(g, str) or g == "" for g in geraete)
                or (laut is not None and (isinstance(laut, bool) or not isinstance(laut, int)
                                          or not 0 <= laut <= 100))
                or isinstance(zeit, bool) or not isinstance(zeit, (int, float))):
            aus["grund"] = "AUFTRAG_KAPUTT"
            log.warning("Sprechauftrag %s (%s) hat eine unerwartete Form - verworfen",
                        kennung, herkunft)
            return aus
        alter = time.time() - float(zeit)
        if alter > SPRECHEN_HOECHSTALTER or alter < -60:
            aus["grund"] = "VERALTET"
            log.warning("Sprechauftrag %s (%s) ist %.0f s alt - verworfen, nicht gesprochen",
                        kennung, herkunft, alter)
            return aus
        cfg = self.dienst.cfg
        modus = (cfg.get("tts_modus") or "chromecast").strip().lower()
        # Nur wo der Lautsprecher selbst spricht, braucht es die Verbindung;
        # in den Modi mit fremdem Sprachdienst ist er nicht beteiligt.
        braucht_verbindung = modus in ("chromecast", "lokal")
        nach_name = dict((g.name, g) for g in list(self.dienst.geraete.values()))
        schritt = self.dienst._zahl("lautstaerke_schritt", 5)
        auftrag_cfg = dict(cfg)
        if laut is not None:
            auftrag_cfg["tts_pegel"] = str(laut)
            auftrag_cfg["_sprechen_laut"] = "1"
        for name in geraete:
            geraet = nach_name.get(name)
            if geraet is None and zone_nummer(name) is not None:     # Audio Stufe 1 (S6)
                treffer = self.dienst.geraete_fuer(name)
                geraet = treffer[0] if treffer else None
            if geraet is None:
                aus["unbekannt"].append(name[:60])
                continue
            if braucht_verbindung and not geraet.verbunden():
                aus["nicht_verbunden"] += 1
                continue
            geraet.einreihen("tts", text, schritt, auftrag_cfg,
                             lambda g, m, k=kennung, h=herkunft: self.ergebnis(k, h, g, m))
            aus["eingereiht"].append(name)
        log.info("Sprechauftrag %s (%s): %d Zeichen%s - eingereiht bei %s, nicht verbunden %d, "
                 "unbekannt %d", kennung, herkunft, len(text),
                 "" if laut is None else ", Lautstaerke %d" % laut,
                 ", ".join(aus["eingereiht"]) or "keinem Geraet", aus["nicht_verbunden"],
                 len(aus["unbekannt"]))
        return aus

    def annehmen_lautstaerke(self, kennung, d, aus):
        """B2 (1.3.22): Lautstaerke vom Endpunkt (aktion=lautstaerke).

        Eingereiht wird "volume" - derselbe Weg wie cmd/volume aus Loxone:
        Obergrenze und Ruhezeit (_gedeckelt) und die Gleichwert-Bremse X-7.
        X-7 wird HIER schon gefragt, damit die Antwort UNVERAENDERT nennen
        kann; der Arbeitsfaden fragt beim Ausfuehren noch einmal. Ein nicht
        verbundenes Geraet wird ausgelassen und gezaehlt wie bei der Ansage."""
        aus["unveraendert"] = []
        geraete = d.get("geraete")
        wert = d.get("wert")
        zeit = d.get("zeit")
        herkunft = "%s %s" % (str(d.get("quelle", "?"))[:20], str(d.get("wer", "?"))[:46])
        if (not isinstance(geraete, list) or not geraete or len(geraete) > 64
                or any(not isinstance(g, str) or g == "" for g in geraete)
                or isinstance(wert, bool) or not isinstance(wert, int) or not 0 <= wert <= 100
                or isinstance(zeit, bool) or not isinstance(zeit, (int, float))):
            aus["grund"] = "AUFTRAG_KAPUTT"
            log.warning("Lautstaerkeauftrag %s (%s) hat eine unerwartete Form - verworfen",
                        kennung, herkunft)
            return aus
        alter = time.time() - float(zeit)
        if alter > SPRECHEN_HOECHSTALTER or alter < -60:
            aus["grund"] = "VERALTET"
            log.warning("Lautstaerkeauftrag %s (%s) ist %.0f s alt - verworfen",
                        kennung, herkunft, alter)
            return aus
        cfg = self.dienst.cfg
        grenze = lautstaerke_grenze(cfg)
        wirksam = min(wert, grenze)
        aus["grenze"] = grenze
        nach_name = dict((g.name, g) for g in list(self.dienst.geraete.values()))
        schritt = self.dienst._zahl("lautstaerke_schritt", 5)
        for name in geraete:
            geraet = nach_name.get(name)
            if geraet is None:
                aus["unbekannt"].append(name[:60])
                continue
            if not geraet.verbunden():
                aus["nicht_verbunden"] += 1
                continue
            if geraet._x7_gleich(wirksam) is not None:
                aus["unveraendert"].append(name)
                continue
            geraet.einreihen("volume", str(wert), schritt, cfg)
            aus["eingereiht"].append(name)
        log.info("Lautstaerkeauftrag %s (%s): %d (wirksam %d) - eingereiht bei %s, "
                 "unveraendert %d, nicht verbunden %d, unbekannt %d", kennung, herkunft,
                 wert, wirksam, ", ".join(aus["eingereiht"]) or "keinem Geraet",
                 len(aus["unveraendert"]), aus["nicht_verbunden"], len(aus["unbekannt"]))
        return aus

    def annehmen_befehl(self, kennung, d, aus):
        """Gruppe 6b (1.3.24, Entwurf cc 3.3, E1): ein Befehl der Befehlsbruecke (bin/befehlsbruecke.php ->
        befehle_zuordnung.php -> cc_befehl_bruecke() in cc_lib.php) ueber denselben Eingang wie Ansage und Lautstaerke.

        Ausgefuehrt wird mit Dienst.befehl_ausfuehren() - DERSELBEN Stelle wie der alte cmd-Weg und UDP: die Sperre aus
        Loxone, X-7, Obergrenze und Ruhezeit bleiben die eine Pruefung im Dienst, eine zweite Logik gibt es nicht. Hier
        steht nur die Form: geraet ist ein Befehlsname ([a-z0-9_]{1,40}, befehl_kennung) oder "server" fuer die Sperre;
        befehl steht in der Befehlsliste (bin/cc_themen.json) oder ist server/sperre; wert ist Text bis 4000 Byte ohne
        Steuerzeichen; der Auftrag ist hoechstens SPRECHEN_HOECHSTALTER s alt. Dazu (Entwurf 3.3): tts leer oder "0"
        -> uebersprungen TEXT_NULL (die Statusbaustein-Falle wie am Endpunkt), mehr als 1000 Zeichen -> TEXT; play mit
        "://" -> ADRESSE (ueber die Bruecke nur Fortsetzen, E6). Ein Ansagetext steht nie im Protokoll, nur seine Laenge."""
        aus["uebersprungen"] = ""
        geraet = d.get("geraet")
        befehl = d.get("befehl")
        wert = d.get("wert")
        zeit = d.get("zeit")
        herkunft = "%s %s" % (str(d.get("quelle", "?"))[:20], str(d.get("wer", "?"))[:46])
        if (not isinstance(geraet, str) or not re.match(r"^[a-z0-9_]{1,40}$", geraet)
                or not isinstance(befehl, str) or not re.match(r"^[a-z_]{1,20}$", befehl)
                or not isinstance(wert, str) or len(wert.encode("utf-8")) > 4000
                or re.search(r"[\x00-\x1f\x7f]", wert)
                or isinstance(zeit, bool) or not isinstance(zeit, (int, float))):
            aus["grund"] = "AUFTRAG_KAPUTT"
            log.warning("Befehlsauftrag %s (%s) hat eine unerwartete Form - verworfen", kennung, herkunft)
            return aus
        if not ((geraet == "server" and befehl == "sperre") or thema_info("befehle", befehl) is not None):
            aus["grund"] = "BEFEHL_UNBEKANNT"
            log.warning("Befehlsauftrag %s (%s): Befehl '%s' gibt es nicht - verworfen", kennung, herkunft, befehl)
            return aus
        alter = time.time() - float(zeit)
        if alter > SPRECHEN_HOECHSTALTER or alter < -60:
            aus["grund"] = "VERALTET"
            log.warning("Befehlsauftrag %s (%s) ist %.0f s alt - verworfen, nicht ausgefuehrt",
                        kennung, herkunft, alter)
            return aus
        if befehl == "tts":
            text = wert.strip()
            if text in ("", "0"):
                aus["uebersprungen"] = "TEXT_NULL"
                log.info("Befehlsauftrag %s (%s): %s/tts ohne Text (leer oder 0) - nichts gesprochen",
                         kennung, herkunft, geraet)
                return aus
            if len(text) > 1000:
                aus["grund"] = "TEXT"
                log.warning("Befehlsauftrag %s (%s): Ansage mit %d Zeichen - hoechstens 1000, abgewiesen",
                            kennung, herkunft, len(text))
                return aus
            wert = text
        if befehl == "play" and "://" in wert:
            aus["grund"] = "ADRESSE"
            log.warning("Befehlsauftrag %s (%s): play mit Adresse - ueber MQTT nur Fortsetzen (Favoriten nehmen)",
                        kennung, herkunft)
            return aus
        erg = self.dienst.befehl_ausfuehren(geraet, befehl, wert, quelle="mqtt-bruecke")
        for k in ("eingereiht", "nicht_verbunden", "unbekannt", "uebersprungen", "grund", "sperre"):
            if k in erg:
                aus[k] = erg[k]
        log.info("Befehlsauftrag %s (%s): %s/%s %s - eingereiht bei %s, davon nicht verbunden %d%s%s", kennung,
                 herkunft, geraet, befehl,
                 ("%d Zeichen" % len(wert)) if befehl == "tts" else ("'%s'" % wert[:20]),
                 ", ".join(aus["eingereiht"]) or "keinem Geraet", aus["nicht_verbunden"],
                 (", uebersprungen " + aus["uebersprungen"]) if aus["uebersprungen"] else "",
                 (", Grund " + aus["grund"]) if aus["grund"] else "")
        return aus

    @staticmethod
    def _schreiben(pfad, daten, rechte):
        vorlaeufig = "%s.tmp.%d" % (pfad, os.getpid())
        fd = os.open(vorlaeufig, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, rechte)
        try:
            if hasattr(os, "fchmod"):
                os.fchmod(fd, rechte)
            with os.fdopen(fd, "w", encoding="utf-8") as fh:
                json.dump(daten, fh, ensure_ascii=False)
        except Exception:
            try:
                os.remove(vorlaeufig)
            except OSError:
                pass
            raise
        os.replace(vorlaeufig, pfad)

    def antworten(self, kennung, aus):
        try:
            self._schreiben(os.path.join(SPRECHEN_ORDNER, kennung + ".antwort"), aus, 0o600)
        except OSError as fehler:
            log.warning("Sprechauftrag %s: Antwort nicht schreibbar: %s", kennung, fehler)

    def ergebnis(self, kennung, herkunft, geraet, meldung):
        """Das Ergebnis der Wiedergabe je Geraet - fuer den Reiter Test, ohne Text."""
        eintrag = {"zeit": int(time.time()), "id": kennung, "herkunft": herkunft,
                   "geraet": geraet, "ok": 1 if meldung == "OK" else 0,
                   "meldung": "" if meldung == "OK" else _ohne_adresse(meldung)[:160]}
        if not SPRECHEN_ERGEBNIS:
            return
        with self.schloss:
            try:
                self._schreiben(SPRECHEN_ERGEBNIS, eintrag, 0o644)
            except OSError as fehler:
                log.warning("Ergebnis der Ansage nicht schreibbar: %s", fehler)


# ---------------------------------------------------------------------------
# Dienst
# ---------------------------------------------------------------------------

class Dienst:
    def __init__(self):
        self.cfg = konfiguration_lesen()
        self.praefix = self.cfg.get("mqtt_topic", "chromecast4lox") or "chromecast4lox"
        self.geraete = {}
        self.mqtt = MqttAnbindung(self.praefix, self.befehl_ausfuehren, self.cmd_ein_lesen())
        self.udp = None
        self.laeuft = True
        # Unterbrechbares Warten (seit 1.3.13, C11): SIGTERM beendet den Takt
        # sofort, nicht erst nach bis zu "intervall" Sekunden.
        self.halt = threading.Event()
        self.udp_absender = miniserver_adressen()
        self.config_mtime = self._mtime()
        # Ansage-3: der Sprech-Eingang (Faden), gestartet in start().
        self.sprechen = None
        # Umlaufender Taktzaehler. Bleibt er stehen, arbeitet der Dienst
        # nicht mehr - das erkennt Loxone mit einer Aenderungsueberwachung,
        # und der Reiter Test an der Zustandsdatei.
        self.zaehler = 0
        self.netzsuche = Netzsuche()
        # Audio Stufe 1 (1.3.25): Zonen und gemerkte Kennungen ihrer Ziele.
        self.zonen_liste = []
        self.zonen_merker = self.zonen_merker_lesen() if DATA_DIR else {}
        # Nachfassung 3 (O1): zuletzt gemeldete Mitglieder je Gruppe, bis die Gruppe selbst antwortet.
        self.mitglieder_gemerkt = self.mitglieder_merker_lesen() if DATA_DIR else {}

    def _mtime(self):
        try:
            return os.path.getmtime(CONFIG_FILE)
        except OSError:
            return 0

    def _zahl(self, schluessel, vorgabe, unten=None, oben=None):
        """Eine Zahl aus der Konfiguration - endlich und im Bereich (seit
        1.3.13, C6). Sonst gilt die Vorgabe, und das steht im Protokoll.
        Bis 1.3.12 liess "intervall=inf" den Dienst sofort an einem
        OverflowError sterben, "1e9" ihn 31 Jahre schlafen, und
        "udp_port=99999" den UDP-Faden sterben (gemessen 30.09.2026,
        Code-Befund 6)."""
        roh = self.cfg.get(schluessel, vorgabe)
        try:
            zahl = float(roh)
            if not math.isfinite(zahl):
                raise ValueError("nicht endlich")
            wert = int(zahl)
        except (TypeError, ValueError, OverflowError):
            log.warning("Einstellung %s='%s' ist keine endliche Zahl - es gilt %s",
                        schluessel, str(roh)[:40], vorgabe)
            return int(vorgabe)
        if (unten is not None and wert < unten) or (oben is not None and wert > oben):
            log.warning("Einstellung %s=%d liegt ausserhalb %s bis %s - es gilt %s",
                        schluessel, wert, unten, oben, vorgabe)
            return int(vorgabe)
        return wert

    def praefix_pruefen(self):
        """Taugt das Praefix? Sonst bleibt MQTT aus, und das steht einmal im
        Protokoll (seit 1.3.13, M9)."""
        if praefix_taugt(self.praefix):
            return True
        log.error("MQTT bleibt aus: das Themenpraefix '%s' taugt nicht (leer, # oder +, "
                  "Leerraum, Schraegstrich am Rand). Im Reiter MQTT berichtigen.",
                  str(self.praefix)[:80])
        return False

    def altes_praefix_abraeumen(self, alt):
        """Unter einem nicht mehr benutzten Praefix abraeumen und nachlesen.
        Ein Aufraeumschritt haelt den Dienst nie an. Rueckgabe: code."""
        try:
            code, geleert, rest, grund = broker_leeren(alt)
        except Exception as fehler:  # noqa: BLE001
            code, geleert, rest, grund = 2, [], [], str(fehler)
        if code == 0:
            log.info("MQTT: altes Praefix %s abgeraeumt und nachgelesen "
                     "(%d Themen)", alt, len(geleert))
        elif code == 1:
            log.warning("MQTT: unter dem alten Praefix %s stehen noch %d "
                        "zurueckbehaltene Themen: %s", alt, len(rest),
                        ", ".join(rest[:5]))
        else:
            log.warning("MQTT: altes Praefix %s nicht abgeraeumt - %s",
                        alt, grund)
        return code

    def praefix_merken(self):
        """Das zuletzt benutzte Praefix festhalten (seit 1.3.13, M8). Liegt ein
        anderes im Merker, wird es zuerst abgeraeumt; laesst es sich nicht
        abraeumen (Broker nicht zu fragen), bleibt der alte Merker stehen, und
        der naechste Start versucht es wieder."""
        alt = praefix_merker_lesen()
        if alt and alt != self.praefix and praefix_taugt(alt):
            if self.altes_praefix_abraeumen(alt) == 2:
                return
        if alt != self.praefix:
            praefix_merker_schreiben(self.praefix)

    def verbindungen_nachziehen(self, vorher):
        """Themenpraefix, MQTT-Schalter und UDP beim Neueinlesen nachziehen.

        Bis 1.3.9 las der Dienst diese vier Werte nur beim Start. Das
        Neueinlesen uebernahm Geraete und Takt, sendete aber weiter unter
        dem alten Praefix und hoerte auf dem alten UDP-Port - bis zum
        naechsten Neustart (in WSL gemessen am 17.09.2026). Die Oberflaeche
        startet nach dem Speichern ohnehin neu; getroffen hat es jede
        Aenderung ohne Neustart, etwa die Rueckholung der Einstellungen in
        postinstall.sh bei einem Dienst, den der Waechter in der
        Upgrade-Luecke gestartet hatte.

        Die Huelle self.mqtt bleibt dasselbe Objekt: die Geraete halten
        einen Verweis darauf.
        """
        praefix = self.cfg.get("mqtt_topic", "chromecast4lox") or "chromecast4lox"
        mqtt_ein = self.cfg.get("mqtt_ein", "1") == "1"
        mqtt_vorher = vorher.get("mqtt_ein", "1") == "1"
        # Gruppe 6b (1.3.24): der Haken des alten Befehlswegs gilt ohne Neustart. Bei einem Neuaufbau der
        # Verbindung (unten) abonniert _on_connect nach diesem Wert.
        self.mqtt.cmd_umschalten(self.cmd_ein_lesen())
        self.udp_absender = miniserver_adressen()
        geleert = None
        if praefix != self.praefix or mqtt_ein != mqtt_vorher:
            log.info("MQTT-Einstellungen geaendert (Praefix %s -> %s, MQTT %s -> %s)"
                     " - die Verbindung wird neu aufgebaut", self.praefix, praefix,
                     "ein" if mqtt_vorher else "aus", "ein" if mqtt_ein else "aus")
            self.mqtt.stop()
            self.mqtt.client = None
            self.mqtt.verbunden = False
            if praefix != self.praefix:
                # Das alte Praefix abraeumen und nachlesen (seit 1.3.12).
                # Bis 1.3.11 blieb dort alles Zurueckbehaltene stehen, darunter
                # server/online 0 - und uninstall raeumt nur das aktuelle
                # Praefix ab. Die 0 eines Praefixes, unter dem niemand mehr
                # sendet, bliebe damit fuer immer im Broker (Regeln/07,
                # Letzter Wille (c); in WSL gemessen 25.09.2026, Fall M5).
                # Ein Aufraeumschritt haelt den Dienst nie an.
                if praefix_taugt(self.praefix):
                    geleert = self.altes_praefix_abraeumen(self.praefix)
            self.praefix = praefix
            self.mqtt.praefix = praefix
            if mqtt_ein:
                if self.praefix_pruefen():
                    self.mqtt.start()
                    # Merker nachziehen (M8): ist das alte Praefix eben
                    # abgeraeumt, gilt das neue; sonst raeumt praefix_merken()
                    # ab, was im Merker steht.
                    if geleert in (0, 1):
                        praefix_merker_schreiben(praefix)
                    else:
                        self.praefix_merken()
            else:
                log.info("MQTT ist ausgeschaltet")

        udp_ein = self.cfg.get("udp", "1") == "1"
        port = self._zahl("udp_port", 7090, 1024, 65535)
        port_bisher = self.udp.port if self.udp else None
        if (udp_ein and port != port_bisher) or (not udp_ein and self.udp):
            log.info("UDP-Einstellungen geaendert (Port %s -> %s) - der Empfang "
                     "wird neu eingerichtet", port_bisher or "aus",
                     port if udp_ein else "aus")
            if self.udp:
                self.udp.stop()
                self.udp.join(timeout=3)
                self.udp = None
            if udp_ein:
                self.udp = UdpEmpfaenger(port, self)
                self.udp.start()

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
            # "-" statt leer (seit 1.3.13, M11; Entscheidung 5): ein leerer
            # Wert geht nie retained hinaus, und der retained Altwert der
            # vorigen Fassung blieb stehen (gemessen 30.09.2026, MQTT M11).
            "fassung": version() or "-",
            # Audio Stufe 1: Zahl der gueltigen Quellen, Medienablage freigegeben (beide nie retained).
            "quellen": len(quellen(self.cfg)),
            "ablage_ok": 1 if ABLAGE_STAND.get("ok") else 0,
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
            # 0644 ausdruecklich, unabhaengig von der umask (Verbesserungsbau
            # 30.09.2026, a2): die Datei traegt keine Geheimnisse, und die
            # Oberflaeche liest sie. Rechte VOR dem Inhalt (Regeln/03).
            fd = os.open(vorlaeufig, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o644)
            if hasattr(os, "fchmod"):
                os.fchmod(fd, 0o644)
            with os.fdopen(fd, "w", encoding="utf-8") as fh:
                json.dump({
                    "zeit": jetzt,
                    "zaehler": self.zaehler,
                    "geraete_erreichbar": erreichbar,
                    "geraete_konfiguriert": len(self.geraete),
                    "verluste": self.mqtt.verluste,
                    "mqtt_verbunden": bool(self.mqtt.verbunden),
                    # a1: seit dem Start abgeraeumt / stehen nach drei Versuchen
                    "befehle_abgeraeumt": self.mqtt.befehle_abgeraeumt,
                    "befehle_stehen": list(self.mqtt.befehle_stehen),
                    "fassung": version(),
                    # Ansage-3: dieser Dienst nimmt Sprechauftraege an.
                    "sprechen_eingang": 1 if self.sprechen is not None else 0,
                    # B2 (1.3.22): Lautstaerke ueber den Endpunkt, und der Stand
                    # je Geraet fuer aktion=geraete (Name, kein Netzwert).
                    "lautstaerke_eingang": 1 if self.sprechen is not None else 0,
                    # Gruppe 6b (1.3.24): Befehle der Befehlsbruecke ueber denselben Eingang, und der alte
                    # Befehlsweg (Haken, letzter Befehl) fuer den Hinweis im Reiter MQTT.
                    "befehl_eingang": 1 if self.sprechen is not None else 0,
                    "cmd_ein": 1 if self.mqtt.cmd_ein else 0,
                    "cmd_zuletzt": int(self.mqtt.cmd_zuletzt),
                    "geraete_stand": self.geraete_stand(),
                    # Audio Stufe 1: Zonen, Quellen, Ablage, sichtbare Geraete (ohne Pfad, Adresse, Kennung).
                    "audio": self.audio_stand(),
                }, fh)
            # Erst vollstaendig schreiben, dann umbenennen: sonst liest die
            # Oberflaeche irgendwann eine halbe Datei und meldet einen
            # Defekt, den es nicht gibt.
            os.replace(vorlaeufig, ZUSTAND_FILE)
        except OSError as fehler:
            log.warning("Zustandsdatei %s nicht schreibbar: %s",
                        ZUSTAND_FILE, fehler)

    def geraete_stand(self):
        """B2 (1.3.22): je Geraet Name, Thema, verbunden, zuletzt gemeldete
        Lautstaerke und Zustand - aus dem, was der Dienst ohnehin meldet
        (letzter_stand). Keine Adresse, kein Token."""
        aus = []
        for g in list(self.geraete.values()):
            st = str(g.letzter_stand.get("state", "") or "")
            if st.startswith("\0platzhalter:"):
                st = st[len("\0platzhalter:"):]
            laut = str(g.letzter_stand.get("volume", "") or "")
            aus.append({"name": g.name, "thema": g.thema,
                        "online": 1 if g.verbunden() else 0,
                        "laut": int(laut) if laut.isdigit() else -1,
                        "state": st or "-"})
        return aus

    def geraete_aufbauen(self):
        namen = geraeteliste(self.cfg)
        if not namen:
            log.warning("Kein Chromecast konfiguriert - "
                        "im Reiter Einstellungen mindestens einen eintragen.")
        for alt in list(self.geraete):
            if alt not in namen:
                weg = self.geraete[alt]
                weg.beenden()      # C9 (1.3.24): Arbeitsfaden anhalten, eingereihte Auftraege verfallen
                weg.trennen()
                del self.geraete[alt]
                self.geraet_abraeumen(weg, namen)
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
            self.geraete[name].nachbarn = self.geraete      # E-NF3: dasselbe Verzeichnis, nicht eine Kopie
            gemerkt = getattr(self, "mitglieder_gemerkt", None) or {}
            if getattr(self.geraete[name], "mitglieder", None) is None and name in gemerkt:
                # Nachfassung 3: Mitglieder aus data/, bis die Gruppe selbst antwortet
                self.geraete[name].mitglieder = list(gemerkt[name])
        # Audio Stufe 1: Zonen zuordnen - nach jedem Aufbau, die Liste kann sich geaendert haben.
        self.zonen_aufbauen()

    def geraet_abraeumen(self, weg, namen):
        """Ein entferntes Geraet (seit 1.3.13, M4; Entscheidung 5).

        Einmal der Fehlwert retained fuer jeden retained Zustand - Loxone sieht
        "keine Aussage" statt des letzten Stands -, dann wird unter
        <praefix>/<geraet>/ abgeraeumt und nachgelesen. Bis 1.3.12 blieben
        neun retained Zustaende stehen (state IDLE, volume 100 ...), und nach
        jedem Neustart von Gateway oder Miniserver meldete das entfernte
        Geraet seinen letzten Stand als gueltig (gemessen 30.09.2026, MQTT M4).
        Traegt ein verbliebenes Geraet dasselbe Thema, bleibt alles stehen."""
        if any(thema_saeubern(n) == weg.thema for n in namen):
            return
        if not self.mqtt.verbunden or not praefix_taugt(self.praefix):
            log.warning("'%s' ist entfernt; seine retained Themen unter %s/%s/ "
                        "bleiben stehen - MQTT ist nicht verbunden.",
                        weg.name, self.praefix, weg.thema)
            return
        gesendet = 0
        # E9 (1.3.24): der Fehlwert je Art aus bin/cc_themen.json - Zahl -1 (volume, duration, favorit), Ja/Nein 0
        # (playing, muted, group, tts_active: "spielt nicht" ist fuer einen entfernten Lautsprecher richtig), Text
        # "-". Bis 1.3.23 ging fuer jedes Thema "-", und Loxone zeigte an einem Zahleneingang 0.
        for eintrag in THEMEN.get("geraet", []):
            if eintrag.get("retain", False):
                art = eintrag.get("art")
                fehlwert = "-1" if art == "analog" else ("0" if art == "digital" else "-")
                if self.mqtt.senden_und_warten(weg.thema + "/" + eintrag["schluessel"], fehlwert):
                    gesendet += 1
        try:
            code, geleert, rest, grund = broker_leeren(self.praefix, nur=weg.thema)
        except Exception as fehler:  # noqa: BLE001
            code, geleert, rest, grund = 2, [], [], str(fehler)
        if code == 0:
            log.info("'%s' entfernt: %d Zustaende mit Fehlwert (-1/0/-) gemeldet, %d Themen unter "
                     "%s/%s/ abgeraeumt und nachgelesen", weg.name, gesendet,
                     len(geleert), self.praefix, weg.thema)
        elif code == 1:
            log.warning("'%s' entfernt: unter %s/%s/ stehen noch %d Themen: %s",
                        weg.name, self.praefix, weg.thema, len(rest), ", ".join(rest[:5]))
        else:
            log.warning("'%s' entfernt: %d Zustaende mit Fehlwert (-1/0/-) gemeldet, Abraeumen nicht "
                        "moeglich - %s", weg.name, gesendet, grund)

    def geraete_fuer(self, kennung):
        """Welche Geraete meint diese Kennung? Immer eine LISTE.

        Ein echter Geraetename gewinnt vor dem Sammelziel: wer seinen
        Lautsprecher tatsaechlich "alle" nennt, soll ihn weiter einzeln
        ansprechen koennen.
        """
        einzeln = self.geraet_finden(kennung)
        if einzeln is not None:
            return [einzeln]
        # Audio Stufe 1 (E1): zone_<n> meint das Ziel der Zone.
        nr = zone_nummer(kennung)
        if nr is not None:
            for z in self.zonen_liste:
                if z["nr"] == nr and z["ziel"] in self.geraete:
                    return [self.geraete[z["ziel"]]]
            return []
        if kennung and str(kennung).strip().lower() in SAMMELZIEL:
            return list(self.geraete.values())
        return []

    def geraet_finden(self, kennung):
        """Geraet nach Name, nach gesaeubertem Thema oder (seit 1.3.24, Gruppe 6b) nach seinem Befehlsnamen
        (befehl_kennung) suchen - die Befehlsbruecke nennt Geraete nur klein und mit "_" statt "-"."""
        if not kennung:
            return next(iter(self.geraete.values()), None)
        for geraet in self.geraete.values():
            if kennung in (geraet.name, geraet.thema):
                return geraet
        for geraet in self.geraete.values():
            if kennung.lower() in (geraet.name.lower(), geraet.thema.lower()):
                return geraet
        for geraet in self.geraete.values():
            if kennung == befehl_kennung(geraet.thema):
                return geraet
        return None

    def befehl_ausfuehren(self, kennung, befehl, wert, quelle="mqtt"):
        """Einen Befehl annehmen und je Geraet einreihen - die EINE Pruefstelle fuer den alten cmd-Weg, UDP und
        (seit 1.3.24, Gruppe 6b E1) die Befehlsbruecke ueber den Sprech-Eingang (Art "befehl").

        Rueckgabe (seit 1.3.24): dict eingereiht (Geraetenamen), nicht_verbunden (davon gerade ohne Verbindung - der
        Arbeitsfaden verbindet wie bisher selbst), unbekannt, uebersprungen (GESPERRT), grund (GERAET_UNBEKANNT,
        SPERRE_AUS, WERT_SPERRE, SPEICHERN) und sperre (1 = Sperre gesetzt). cmd und UDP verwerfen es wie bisher."""
        aus = {"eingereiht": [], "nicht_verbunden": 0, "unbekannt": [], "uebersprungen": "", "grund": ""}
        b = str(befehl or "").strip().lower()
        # B1 (1.3.22): der Dienstbefehl sperre - <praefix>/server/cmd/sperre
        # bzw. UDP "server/SPERRE 1;". Nur dieser eine Befehl unter "server";
        # jeder andere geht den bisherigen Weg.
        if b == "sperre" and str(kennung or "").strip().lower() == "server":
            grund, _gesetzt = loxsperre_setzen(self.cfg, wert, quelle)
            aus["grund"] = grund
            if not grund:
                aus["sperre"] = 1
            return aus
        # Audio Stufe 1 (S5): die Klingel hat ihre eigene Zielwahl samt Rueckfall.
        if b == "klingel":
            return self.klingel_ausfuehren(kennung, quelle)
        ziele = self.geraete_fuer(kennung)
        if not ziele:
            log.warning("Kein Geraet fuer '%s' - konfiguriert sind: %s "
                        "(Sammelziel: %s)", kennung,
                        ", ".join(self.geraete) or "keine", "/".join(SAMMELZIEL))
            aus["unbekannt"].append(str(kennung or "")[:60])
            aus["grund"] = "GERAET_UNBEKANNT"
            return aus
        # B1: eine Ansage aus Loxone haelt die Sperre an (ein dringend gibt es
        # nur am Endpunkt). tts_stop und alle uebrigen Befehle gehen weiter.
        if b in ("tts", "say", "ansage") and loxsperre_wirkt(self.cfg):
            s = loxsperre_lesen()
            log.info("Ansage aus Loxone (%s) an %s uebersprungen: Sperre aus Loxone "
                     "(gesetzt von %s)", quelle,
                     ", ".join(g.name for g in ziele), s["quelle"] or "-")
            aus["uebersprungen"] = "GESPERRT"
            return aus
        if len(ziele) > 1:
            log.info("Sammelbefehl '%s' an %d Geraete", befehl, len(ziele))
        for geraet in ziele:
            # EINREIHEN, nicht aufrufen. Der Aufrufer ist der Netzfaden von
            # paho, der UDP-Faden oder der Sprech-Eingang; keiner darf
            # minutenlang in einer Ansage stehen bleiben.
            if not geraet.verbunden():
                aus["nicht_verbunden"] += 1
            geraet.einreihen(befehl, wert,
                             self._zahl("lautstaerke_schritt", 5), self.cfg)
            aus["eingereiht"].append(geraet.name)
        return aus

    # -- Audio Stufe 1 (1.3.25): Zonen, Medienablage, Klingel --------------

    ZONEN_MERKER = "zonen.json"

    def zonen_merker_lesen(self):
        """Gemerkte Cast-Kennung je Zonenziel (E1: "beim ersten Fund gemerkt"). Liegt unter data/, nie in einer
        Ausgabe. Rueckgabe dict Geraetename -> Kennung."""
        try:
            with open(os.path.join(DATA_DIR, self.ZONEN_MERKER), "r", encoding="utf-8") as fh:
                d = json.load(fh)
            return dict((str(k), str(v)) for k, v in d.items() if isinstance(v, str)) if isinstance(d, dict) else {}
        except (OSError, ValueError):
            return {}

    def zonen_merker_schreiben(self):
        ziel = os.path.join(DATA_DIR, self.ZONEN_MERKER)
        try:
            SprechEingang._schreiben(ziel, self.zonen_merker, 0o600)
        except OSError as fehler:
            log.warning("Merker der Zonen %s nicht schreibbar: %s", ziel, fehler)

    def zonen_aufbauen(self):
        """Zonen den Geraeten zuordnen (E1). Ein Ziel, das nicht in der Geraeteliste steht, bleibt stumm (und steht
        im Protokoll); eine entfernte Zone wird abgeraeumt wie ein entferntes Geraet."""
        alt = set(z["nr"] for z in self.zonen_liste)
        self.zonen_liste = zonen(self.cfg)
        neu = set(z["nr"] for z in self.zonen_liste)
        for g in self.geraete.values():
            nrs = sorted(z["nr"] for z in self.zonen_liste if z["ziel"] == g.name)
            if nrs != g.zonen_nr:
                g.zonen_nr = nrs
                g.letzter_stand.clear()     # die Zone bekommt beim naechsten Melden alles einmal
            g.uuid_merker = self.zonen_merker.get(g.name, "") if nrs else ""
        for z in self.zonen_liste:
            if z["ziel"] not in self.geraete:
                log.warning("Zone %d: Ziel '%s' steht nicht in der Geraeteliste - die Zone bleibt stumm",
                            z["nr"], z["ziel"])
        for nr in sorted(alt - neu):
            self.zone_abraeumen(nr)

    def zone_abraeumen(self, nr):
        """Eine aus der Tabelle genommene Zone: retained Zustaende einmal mit Fehlwert, dann abraeumen."""
        if not self.mqtt.verbunden or not praefix_taugt(self.praefix):
            log.warning("Zone %d entfernt; ihre retained Themen bleiben stehen - MQTT ist nicht verbunden.", nr)
            return
        for eintrag in THEMEN.get("zone", []):
            if eintrag.get("retain", False):
                art = eintrag.get("art")
                fehlwert = "-1" if art == "analog" else ("0" if art == "digital" else "-")
                self.mqtt.senden_und_warten("zone/%d/%s" % (nr, eintrag["schluessel"]), fehlwert)
        try:
            code, geleert, rest, grund = broker_leeren(self.praefix, nur="zone/%d" % nr)
        except Exception as fehler:  # noqa: BLE001
            code, geleert, rest, grund = 2, [], [], str(fehler)
        log.info("Zone %d entfernt: Abraeumen %s", nr, "fertig" if code == 0 else (grund or "unvollstaendig"))

    def zonen_merken(self):
        """Nach einer Verbindung die Cast-Kennung eines Zonenziels festhalten (Umbenennung in Google Home, E1)."""
        geaendert = False
        for g in self.geraete.values():
            if g.zonen_nr and g.uuid and self.zonen_merker.get(g.name) != g.uuid:
                self.zonen_merker[g.name] = g.uuid
                g.uuid_merker = g.uuid
                geaendert = True
        if geaendert:
            self.zonen_merker_schreiben()

    MITGLIEDER_MERKER = "mitglieder.json"

    def mitglieder_merker_lesen(self):
        """Nachfassung 3: zuletzt gemeldete Mitglieder je Gruppe (Gruppenname -> [dict(kennung, name)]). Liegt unter
        data/ (0600) und steht nie in einer Ausgabe - die Oberflaeche bekommt ueber zustand.json nur die Namen."""
        try:
            with open(os.path.join(DATA_DIR, self.MITGLIEDER_MERKER), "r", encoding="utf-8") as fh:
                d = json.load(fh)
        except (OSError, ValueError):
            return {}
        aus = {}
        if isinstance(d, dict):
            for k, v in d.items():
                if isinstance(v, list):
                    aus[str(k)] = [{"kennung": str(m.get("kennung", "") or "")[:64],
                                    "name": str(m.get("name", "") or "")[:80]} for m in v if isinstance(m, dict)]
        return aus

    def mitglieder_merken(self):
        """Nach einer Aenderung die Mitglieder aller eingetragenen Gruppen unter data/ festhalten (nur Gruppen, die in
        der Geraeteliste stehen; eine entfernte Gruppe faellt dabei heraus)."""
        geraete = list(self.geraete.values())
        if not any(getattr(g, "mitglieder_geaendert", False) for g in geraete):
            return
        daten = {}
        for g in geraete:
            g.mitglieder_geaendert = False
            if getattr(g, "mitglieder", None) is not None:
                daten[g.name] = list(g.mitglieder)
        self.mitglieder_gemerkt = daten
        if not DATA_DIR:
            return
        ziel = os.path.join(DATA_DIR, self.MITGLIEDER_MERKER)
        try:
            SprechEingang._schreiben(ziel, daten, 0o600)
        except OSError as fehler:
            log.warning("Merker der Gruppenmitglieder %s nicht schreibbar: %s", ziel, fehler)

    def ablage_einrichten(self, durchsicht=None):
        """Die Medienablage (S4, E2): Pfad pruefen, einmal durchsehen (keine Datei, die Apache ausfuehren koennte),
        dann den Verweis html/plugins/<ordner>/m_<Schluessel> auf den Ordner setzen. Ohne Freigabe gibt es keinen
        Verweis - ein alter wird entfernt. Haelt den Dienst nie an."""
        pfad = str(self.cfg.get("ablage_pfad", "") or "").strip()
        schluessel = str(self.cfg.get("ablage_schluessel", "") or "").strip()
        st = {"eingerichtet": 1 if pfad else 0, "ok": 0, "grund": "", "dateien": 0, "pfad": "", "schluessel": ""}
        if pfad == "":
            self._verweise_aufraeumen("")
            ABLAGE_STAND.clear()
            ABLAGE_STAND.update(st)
            return
        echt, grund = ablage_pfad_taugt(pfad)
        if not grund and not ABLAGE_SCHLUESSEL.match(schluessel):
            grund = "kein Pfadschluessel - im Reiter Audio einmal speichern"
        if not grund and not os.path.isdir(echt):
            grund = "Ordner nicht vorhanden oder Datentraeger nicht eingehaengt"
        if not grund:
            st["dateisystem"] = ablage_dateisystem(echt)
            if durchsicht is not None and durchsicht[0] == echt:
                st["dateien"], grund = durchsicht[1], ""     # Nachfassung 6: eben von der Wache durchgesehen
            else:
                st["dateien"], grund = ablage_durchsehen(echt)
            # 1.3.26 (Befund 5): ein Verzeichnislesen beweist nichts - bei "Stale file handle" listet die
            # Freigabe weiter, nur das Lesen einer Datei scheitert. Darum eine Datei lesen, bevor freigegeben wird.
            if grund.startswith("nicht lesbar") and "stale file handle" in grund.lower():
                grund = ESTALE_TEXT
            elif not grund:
                grund = ablage_lesbar(echt)
        if not grund:
            grund = self._verweis_setzen(echt, schluessel)
        if grund:
            st["grund"] = grund
            self._verweise_aufraeumen("")
            log.warning("Medienablage nicht freigegeben: %s", grund)
        else:
            st.update({"ok": 1, "pfad": echt, "schluessel": schluessel})
            log.info("Medienablage freigegeben: %d Audiodateien", st["dateien"])
        ABLAGE_STAND.clear()
        ABLAGE_STAND.update(st)

    def ablage_nachsehen(self):
        """Mit jeder Vollmeldung: ist die freigegebene Ablage noch lesbar (Datentraeger ausgehaengt, NAS fort)? Und
        kommt eine nicht erreichbare wieder, wird sie neu eingerichtet."""
        if ABLAGE_STAND.get("ok"):
            try:
                os.listdir(ABLAGE_STAND["pfad"])
            except OSError as fehler:
                ABLAGE_STAND["ok"] = 0
                if getattr(fehler, "errno", None) in ESTALE_NUMMERN:     # 1.3.26 (Befund 5)
                    ABLAGE_STAND["grund"] = ESTALE_TEXT
                else:
                    ABLAGE_STAND["grund"] = "nicht erreichbar (%s)" % fehler.strerror
                log.warning("Medienablage nicht mehr erreichbar: %s", ABLAGE_STAND["grund"])
                self._ablage_ok_senden()
        elif ABLAGE_STAND.get("eingerichtet") and ABLAGE_STAND.get("grund") == ESTALE_TEXT:
            # 1.3.26 (Befund 5): solange die Netzfreigabe haengt, mit jeder Vollmeldung eine Datei lesen (billig);
            # geht es wieder (neu eingehaengt), die Ablage wie beim Start einrichten - einmal durchsehen, freigeben.
            echt = ABLAGE_STAND.get("pfad") or ablage_pfad_taugt(str(self.cfg.get("ablage_pfad", "") or "").strip())[0]
            if echt and os.path.isdir(echt) and not ablage_lesbar(echt):
                log.info("Medienablage wieder lesbar - wird neu eingerichtet")
                self.ablage_einrichten()
                self._ablage_ok_senden()
        elif ABLAGE_STAND.get("eingerichtet") and str(ABLAGE_STAND.get("grund", "")).startswith(
                ("nicht erreichbar", "Ordner nicht vorhanden")):
            self.ablage_einrichten()

    def _klingel_oder_ansage(self):
        """Laeuft gerade an irgendeinem Geraet eine Klingel oder Ansage? Dann wartet die Wache der Ablage."""
        return any(getattr(g, "ansage_laeuft", False) for g in list(getattr(self, "geraete", {}).values()))

    def _halt_gesetzt(self):
        h = getattr(self, "halt", None)
        return bool(h is not None and h.is_set())

    def _ablage_ok_senden(self):
        """server/ablage_ok sofort (nicht erst mit der naechsten Vollmeldung)."""
        m = getattr(self, "mqtt", None)
        if m is not None and getattr(m, "verbunden", False):
            m.senden("server/ablage_ok", 1 if ABLAGE_STAND.get("ok") else 0, thema_retain("dienst", "ablage_ok"))

    def ablage_wache(self, jetzt=None):
        """Nachfassung 6 (30.10.2026, E-NF5): die Durchsicht beim Einrichten sieht nur, was DANN in der Ablage liegt.
        Alle ABLAGE_WACHE_ALLE s wird die freigegebene Ablage erneut durchgesehen - in einem eigenen Faden (eine
        Netzfreigabe kann dauern), nicht waehrend einer Klingel oder Ansage, hoechstens ABLAGE_WACHE_FRIST s und
        ABLAGE_PRUEF_MAX Eintraege. Fund (.ht*, Programm-Endung auch mitten im Namen, Verweis nach aussen) -> Freigabe
        sofort zurueck; eine so (oder schon beim Einrichten wegen eines Funds) gesperrte Ablage wird beim naechsten
        Durchgang wieder freigegeben, wenn sie sauber ist. Grenze erreicht oder abgebrochen: nichts aendern.
        Aus der Hauptschleife in jedem Durchgang gerufen; billig, solange nichts faellig ist."""
        jetzt = time.time() if jetzt is None else jetzt
        w = getattr(self, "_wache", None)
        if w is None:
            w = self._wache = {"naechste": jetzt + ABLAGE_WACHE_ALLE, "faden": None, "ergebnis": None}
        erg = w.get("ergebnis")
        if erg is not None:
            w["ergebnis"] = None
            self._wache_anwenden(erg)
        faden = w.get("faden")
        if faden is not None and faden.is_alive():
            return
        if jetzt < w["naechste"]:
            return
        st = ABLAGE_STAND
        zurueck = bool(not st.get("ok") and st.get("eingerichtet")
                       and str(st.get("grund", "")).startswith(("unsichere Datei gefunden", "enthaelt")))
        if not st.get("ok") and not zurueck:
            w["naechste"] = jetzt + ABLAGE_WACHE_ALLE
            return
        if self._klingel_oder_ansage():
            return                      # beim naechsten Durchgang der Hauptschleife wieder versuchen
        w["naechste"] = jetzt + ABLAGE_WACHE_ALLE
        if zurueck:
            echt, grund = ablage_pfad_taugt(str(self.cfg.get("ablage_pfad", "") or "").strip())
            schluessel = str(self.cfg.get("ablage_schluessel", "") or "").strip()
            if grund or not os.path.isdir(echt):
                return
        else:
            echt, schluessel = st.get("pfad", ""), st.get("schluessel", "")
        auftrag = {"echt": echt, "schluessel": schluessel, "zurueck": zurueck}

        def lauf():
            beginn = time.monotonic()
            fund = []
            try:
                zahl, grund2 = ablage_durchsehen(echt, grenze=ABLAGE_PRUEF_MAX, frist=ABLAGE_WACHE_FRIST,
                                                 abbrechen=lambda: self._klingel_oder_ansage() or self._halt_gesetzt(),
                                                 fund=fund)
            except Exception as fehler:  # noqa: BLE001
                zahl, grund2 = 0, "Durchsicht abgebrochen (%s)" % fehler
            # 1.3.26 (Befund 5): dazu eine Datei der Ablage lesen ("Stale file handle" sieht die Durchsicht nicht).
            lese = ""
            if not str(grund2).startswith(("Durchsicht abgebrochen", "enthaelt", "mehr als")):
                lese = ablage_lesbar(echt)
            auftrag.update({"zahl": zahl, "grund": grund2, "fund": fund[0] if fund else "",
                            "dauer": time.monotonic() - beginn, "lesefehler": lese})
            w["ergebnis"] = auftrag

        w["faden"] = threading.Thread(target=lauf, daemon=True, name="ablage-wache")
        w["faden"].start()

    def _wache_anwenden(self, erg):
        """Ergebnis der Wache in der Hauptschleife uebernehmen (nur, wenn die Ablage inzwischen nicht anders
        eingerichtet wurde)."""
        st = ABLAGE_STAND
        grund, rel = str(erg.get("grund", "")), str(erg.get("fund", ""))
        if erg.get("zurueck"):
            if st.get("ok") or str(self.cfg.get("ablage_schluessel", "") or "").strip() != erg.get("schluessel"):
                return
        elif not st.get("ok") or st.get("pfad") != erg.get("echt") or st.get("schluessel") != erg.get("schluessel"):
            return
        lese = str(erg.get("lesefehler", "") or "")
        if lese and st.get("ok"):
            # 1.3.26 (Befund 5): die Datei liess sich nicht lesen - nicht freigegeben, klarer Grund, ablage_ok 0. Die
            # Vollmeldung (ablage_nachsehen) gibt die Ablage wieder frei, sobald sie lesbar ist.
            st["ok"] = 0
            st["grund"] = lese
            log.warning("Wache der Medienablage: %s", lese)
            self._ablage_ok_senden()
            return
        if grund.startswith("enthaelt") and rel:
            text = "unsichere Datei gefunden: %s" % rel[:80]
            if st.get("ok"):
                self._verweise_aufraeumen("")
                st["ok"] = 0
                st["grund"] = text
                log.warning("Medienablage zurueckgezogen - %s (Verweis entfernt; wieder frei, sobald die Datei fort ist)",
                            text)
                self._ablage_ok_senden()
            else:
                if st.get("grund") != text:
                    log.warning("Medienablage bleibt gesperrt - %s", text)
                st["grund"] = text
            return
        if grund:
            log.info("Wache der Medienablage: %s - Freigabe unveraendert", grund)
            return
        log.info("Wache der Medienablage: %d Audiodateien in %.1f s durchgesehen, nichts Unsicheres",
                 erg.get("zahl", 0), erg.get("dauer", 0.0))
        if erg.get("zurueck"):
            self.ablage_einrichten(durchsicht=(erg.get("echt"), erg.get("zahl", 0)))
            if ABLAGE_STAND.get("ok"):
                log.info("Medienablage wieder freigegeben")
                self._ablage_ok_senden()
        else:
            st["dateien"] = erg.get("zahl", st.get("dateien", 0))

    def _verweis_setzen(self, echt, schluessel):
        if not HTML_DIR or not os.path.isdir(HTML_DIR):
            return "Webordner des Plugins fehlt"
        schutz = index_schutz_setzen(HTML_DIR)
        if schutz:
            return schutz
        name = "m_" + schluessel
        ziel = os.path.join(HTML_DIR, name)
        try:
            if os.path.islink(ziel) and os.readlink(ziel) == echt:
                self._verweise_aufraeumen(name)
                return ""
            if os.path.exists(ziel) and not os.path.islink(ziel):
                return "im Webordner liegt schon etwas unter diesem Namen"
            vorlaeufig = ziel + ".neu.%d" % os.getpid()
            os.symlink(echt, vorlaeufig)
            os.replace(vorlaeufig, ziel)
        except (OSError, NotImplementedError, AttributeError) as fehler:
            return "Verweis nicht anlegbar (%s)" % fehler
        self._verweise_aufraeumen(name)
        return ""

    @staticmethod
    def _verweise_aufraeumen(behalten):
        """Nur VERWEISE m_<hex> im eigenen Webordner entfernen - nie eine Datei, nie den Ordner dahinter."""
        if not HTML_DIR or not os.path.isdir(HTML_DIR):
            return
        try:
            namen = os.listdir(HTML_DIR)
        except OSError:
            return
        for n in namen:
            p = os.path.join(HTML_DIR, n)
            if n != behalten and re.match(r"^m_[0-9a-f]{24,64}(\.neu\.[0-9]+)?$", n) and os.path.islink(p):
                try:
                    os.remove(p)
                except OSError as fehler:
                    log.warning("Alter Verweis %s nicht entfernbar: %s", n, fehler)

    def klingel_ausfuehren(self, kennung, quelle):
        """Befehl klingel (S5): an zone_<n>, an ein Geraet/eine Gruppe oder - ohne Ziel bzw. "server" - an das
        Standardziel klingel_ziel. Hauptweg ist das Ziel (bei einer Google-Gruppe synchron, E3); antwortet es nicht
        (nicht verbunden, oder die Wiedergabe scheitert), geht die Klingel parallel an den Rueckfall (klingel_rueckfall,
        leer = die gemessenen Mitglieder der Ziel-Gruppe, sonst alle eingetragenen Lautsprecher, keine Gruppen, keine
        Fernseher). Die Sperre aus Loxone haelt sie an wie eine Ansage."""
        aus = {"eingereiht": [], "nicht_verbunden": 0, "unbekannt": [], "uebersprungen": "", "grund": ""}
        k = str(kennung or "").strip()
        standard = k.lower() in ("", "server")
        if standard:
            k = str(self.cfg.get("klingel_ziel", "") or "").strip()
            if k == "":
                log.warning("Klingel (%s): kein Standardziel eingetragen (Reiter Audio)", quelle)
                aus["grund"] = "KLINGEL_ZIEL_FEHLT"
                return aus
        ziele = self.geraete_fuer(k)
        if not ziele:
            log.warning("Klingel (%s): kein Geraet fuer '%s'", quelle, k)
            aus["unbekannt"].append(k[:60])
            aus["grund"] = "GERAET_UNBEKANNT"
            return aus
        if loxsperre_wirkt(self.cfg):
            log.info("Klingel (%s) an %s uebersprungen: Sperre aus Loxone", quelle, ", ".join(g.name for g in ziele))
            aus["uebersprungen"] = "GESPERRT"
            return aus
        rueck = self.klingel_rueckfall(ziele)
        schritt = self._zahl("lautstaerke_schritt", 5)
        # Sofort parallel nur, wenn das Standardziel oder eine bekannte Gruppe gerade keine Verbindung hat - ein einzeln
        # angesprochener Lautsprecher verbindet im Arbeitsfaden selbst und faellt erst bei einem Fehlschlag zurueck.
        if len(ziele) == 1 and not ziele[0].verbunden() and rueck and (
                standard or ziele[0].ist_gruppe or ziele[0].art.lower() == "group"
                or getattr(ziele[0], "mitglieder", None) is not None):     # Nachfassung 3: bekannte Gruppe
            log.info("Klingel: '%s' ist nicht verbunden - parallel an %s", ziele[0].name, ", ".join(g.name for g in rueck))
            ziele, rueck = rueck, []
        erledigt = {"rueckfall": False}

        def nachher(name, meldung, rueck=rueck):
            if meldung == "OK" or not rueck or erledigt["rueckfall"]:
                return
            erledigt["rueckfall"] = True
            log.info("Klingel an '%s' gescheitert (%s) - parallel an %s", name, meldung,
                     ", ".join(g.name for g in rueck))
            for g in rueck:
                g.einreihen("klingel", "1", schritt, self.cfg)

        for g in ziele:
            if not g.verbunden():
                aus["nicht_verbunden"] += 1
            g.einreihen("klingel", "1", schritt, self.cfg, nachher if len(ziele) == 1 else None)
            aus["eingereiht"].append(g.name)
        return aus

    def klingel_rueckfall(self, ziele):
        """Die Geraete fuer den Rueckfall: klingel_rueckfall ("a;b", "alle"), leer = die gemessenen Mitglieder der
        Ziel-Gruppe (Nachfassung 3), sonst alle Lautsprecher. Nie das Ziel selbst."""
        namen = [n.strip() for n in re.split(r"[;\r\n]+", str(self.cfg.get("klingel_rueckfall", "") or "")) if n.strip()]
        weg = set(g.name for g in ziele)
        if not namen or any(n.lower() in SAMMELZIEL for n in namen):
            # Nachfassung 3 (E-NF1 verfeinert, O1 gemessen): ist das Ziel eine Gruppe mit bekannten Mitgliedern, sind
            # es genau diese (soweit sie in der Geraeteliste stehen).
            kand = self.klingel_mitglieder(ziele)
            if not kand:
                # Nachfassung 30.10.2026 (Geraetetest 17:59: der Rueckfall klingelte auch am Fernseher): leer bzw.
                # "alle" = keine Fernseher/Videogeraete (cast_type cast) und keine Gruppen - die nur, wenn sie
                # ausdruecklich in der Liste stehen. Ein Geraet, das seit dem Dienststart noch nie verbunden war (Art
                # unbekannt), bleibt drin: der Rueckfall ist gerade fuer nicht verbundene Lautsprecher da.
                kand = [g for g in self.geraete.values() if not g.ist_gruppe
                        and g.art.lower() not in ("group", "cast")
                        and getattr(g, "mitglieder", None) is None]
        else:
            kand = [self.geraete[n] for n in namen if n in self.geraete]
        return [g for g in kand if g.name not in weg]

    def klingel_mitglieder(self, ziele):
        """Nachfassung 3: die gemessenen Mitglieder der Ziel-Gruppe(n), soweit sie in der Geraeteliste stehen (keine
        Gruppen, nie das Ziel). [] = keine Gruppe mit bekannten Mitgliedern dabei, oder keines steht in der Liste."""
        aus = []
        for z in ziele:
            for m in (getattr(z, "mitglieder", None) or []):
                for g in self.geraete.values():
                    if g is z or g in aus or g in ziele or getattr(g, "ist_gruppe", False) \
                            or getattr(g, "mitglieder", None) is not None:
                        continue
                    if g.ist_mitglied(m):
                        aus.append(g)
        return aus

    def audio_stand(self):
        """Was der Reiter Audio und der Reiter Test zur Audio-Stufe brauchen - ohne Pfade, Adressen und Kennungen."""
        z = []
        for zone in self.zonen_liste:
            g = self.geraete.get(zone["ziel"])
            z.append({"nr": zone["nr"], "name": zone["name"], "ziel": zone["ziel"],
                      "online": 1 if g is not None and g.verbunden() else 0,
                      "quelle": g.quelle if g is not None else 0,
                      "gruppe": 1 if g is not None and (g.ist_gruppe or g.mitglieder is not None) else 0,
                      # Nachfassung 3: Namen der Mitglieder (Gruppe; None = noch nicht gemessen) bzw. der Gruppen
                      "mitglieder": ([m.get("name") or "?" for m in g.mitglieder]
                                     if g is not None and g.mitglieder is not None else None),
                      "mitglied_von": g.mitglied_von() if g is not None else []})
        return {"zonen": z,
                "quellen": len(quellen(self.cfg)),
                "ablage": {"eingerichtet": ABLAGE_STAND.get("eingerichtet", 0), "ok": ABLAGE_STAND.get("ok", 0),
                           "grund": ABLAGE_STAND.get("grund", ""), "dateien": ABLAGE_STAND.get("dateien", 0),
                           "dateisystem": ABLAGE_STAND.get("dateisystem", "")},
                "sichtbar": sichtbar_fremd(set(self.geraete)),
                # Nachfassung 7: Stand (mtime) der Konfiguration, die der Dienst uebernommen hat - weicht sie von der
                # Datei ab, zeigt die Oberflaeche "wird geprueft ..." statt eines Ablagezustands von vorher.
                "cfg_zeit": int(getattr(self, "config_mtime", 0) or 0)}

    def cmd_ein_lesen(self):
        """Den alten Befehlsweg <praefix>/<Geraet>/cmd/... annehmen? Haken befehle_cmd_ein (Gruppe 6b, 1.3.24,
        Entwurf cc 3.4, Bauart WOLF ISM NG 3.1.12): bei einem Update an (postupgrade.sh setzt ihn), bei einer
        Neuinstallation aus - dort laufen Befehle ueber die Befehlsbruecke."""
        return str(self.cfg.get("befehle_cmd_ein", "0")).strip() == "1"

    def start(self):
        log.info("Chromecast 4 Lox NG %s startet", version() or "(Fassung unbekannt)")
        log.info("Konfiguration: %s", CONFIG_FILE)

        if self.cfg.get("mqtt_ein", "1") == "1":
            if self.praefix_pruefen():
                self.mqtt.start()
                self.praefix_merken()
        else:
            log.info("MQTT ist ausgeschaltet")
        log.info("Alter Befehlsweg %s/+/cmd/# %s (befehle_cmd_ein)", self.praefix,
                 "an" if self.mqtt.cmd_ein else "aus - Befehle ueber die Befehlsbruecke")

        self.geraete_aufbauen()
        self.ablage_einrichten()     # Audio Stufe 1 (S4)
        if klang_standard():     # Nachfassung 7: Rueckfall der Klingel bereitlegen
            log.info("Standard-Gong bereit (klang/%s)", KLANG_STANDARD)

        # Ansage-3: Sprechauftraege anderer Plugins (immer an; ob der Endpunkt
        # sie annimmt, entscheidet dort der Schalter sprechen_ein).
        if SPRECHEN_ORDNER:
            self.sprechen = SprechEingang(self)
            self.sprechen.start()

        if self.cfg.get("udp", "1") == "1":
            self.udp = UdpEmpfaenger(self._zahl("udp_port", 7090, 1024, 65535), self)
            self.udp.start()

        if str(self.cfg.get("beschleunigung", "0")).strip() == "1":
            self.netzsuche.start()

        intervall = self._zahl("intervall", 10, 2, 3600)
        vollmeldung_alle = max(intervall, self._zahl("aktualisierung", 60, 5, 86400))
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
                    # Themen OHNE retain einmal je Verbindung abraeumen: bis
                    # 1.3.7 gingen app/title/artist/album/last_error retained
                    # hinaus, und ihr letzter Wert liegt noch im Broker. Der
                    # frische Wert folgt im selben Durchgang (erzwingen).
                    for eintrag in THEMEN.get("geraet", []):
                        if not eintrag.get("retain", False):
                            self.mqtt.abraeumen(geraet.thema + "/" + eintrag["schluessel"])
                # Ebenso das Lebenszeichen des Dienstes: bis 1.3.8 gingen
                # server/ts und server/zaehler retained hinaus. Ohne das
                # Abraeumen laege ihr letzter Wert nach einem Update weiter im
                # Broker. Der frische Wert folgt mit dem naechsten Durchgang.
                for eintrag in THEMEN.get("dienst", []):
                    if not eintrag.get("retain", False):
                        self.mqtt.abraeumen("server/" + eintrag["schluessel"])
                # tts_active beim Verbinden auf den wahren Stand (seit 1.3.13,
                # C11/M6) - auch fuer ein Geraet, das gerade nicht antwortet.
                # Bis 1.3.12 blieb "1" aus einer abgebrochenen Ansage retained
                # stehen, solange der Lautsprecher nicht erreichbar war.
                for geraet in self.geraete.values():
                    geraet._senden("tts_active", "1" if geraet.ansage_laeuft else "0", True)
                log.info("MQTT neu verbunden - alle Zustaende werden erneut gemeldet")

            # a1: retained Befehle, die beim Verbinden ankamen, am Broker
            # abraeumen und nachlesen (nur, wenn welche da sind).
            if self.mqtt.verbunden and self.mqtt.cmd_ein:
                self.mqtt.befehle_abraeumen(self.halt.wait)

            for geraet in list(self.geraete.values()):
                if geraet.ansage_laeuft:
                    # Waehrend einer Ansage nicht dazwischenfunken: der
                    # Zustand waere ohnehin nur der der Ansage, und ein
                    # Verbindungsaufbau mitten hinein wuerde sie abbrechen.
                    continue
                faellig = geraet.verwerfen_faellig()     # 1.3.26 (Befund 2): haengt bei "is connecting"
                if faellig:
                    geraet.verwerfen(faellig)
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
                # server/online 1 mit jeder Vollmeldung (seit 1.3.13): endet
                # ein zweiter Dienst, setzt sein Letzter Wille die 0 - der
                # verbliebene sagte bis 1.3.12 erst beim naechsten eigenen
                # Verbinden wieder 1 (Pruefung MQTT M7).
                if self.mqtt.verbunden:
                    self.mqtt.senden("server/online", "1")
                self.ablage_nachsehen()     # Audio Stufe 1: Datentraeger noch da?
            self.ablage_wache()     # Nachfassung 6: Ablage alle 15 min erneut durchsehen (eigener Faden)

            # Das Lebenszeichen geht in JEDEM Durchgang hinaus, auch wenn
            # sich sonst nichts geaendert hat.
            self.zonen_merken()     # Audio Stufe 1 (E1)
            self.mitglieder_merken()     # Nachfassung 3 (O1)

            self.herzschlag(sum(1 for g in self.geraete.values() if g.verbunden()))

            # Konfigurationsaenderung uebernehmen, ohne Neustart
            if self._mtime() != self.config_mtime:
                log.info("Konfiguration geaendert - wird neu eingelesen")
                self.config_mtime = self._mtime()
                vorher = self.cfg
                self.cfg = konfiguration_lesen()
                self.verbindungen_nachziehen(vorher)
                self.geraete_aufbauen()
                if not ABLAGE_STAND.get("ok") or any(vorher.get(k) != self.cfg.get(k)
                                                     for k in ("ablage_pfad", "ablage_schluessel")):
                    self.ablage_einrichten()
                intervall = self._zahl("intervall", 10, 2, 3600)
                vollmeldung_alle = max(intervall, self._zahl("aktualisierung", 60, 5, 86400))

            self.halt.wait(intervall)

    def stop(self):
        self.laeuft = False
        self.halt.set()
        # -1 heisst: der Takt laeuft nicht mehr. Ein stehengebliebener
        # Zaehler waere von einem langsamen nicht zu unterscheiden. Seit 1.3.9
        # nicht retained - das erreicht nur, wer gerade verbunden ist; den
        # dauerhaften Stand traegt server/online (retained, mit Testament).
        try:
            self.mqtt.senden("server/zaehler", -1, thema_retain("dienst", "zaehler"))
        except Exception:  # noqa: BLE001
            pass
        if self.udp:
            self.udp.stop()
        if self.sprechen is not None:
            self.sprechen.laeuft = False
        for geraet in self.geraete.values():
            geraet.beenden()   # C9 (1.3.24): dieselbe Stelle wie fuer ein entferntes Geraet
        for geraet in self.geraete.values():
            # tts_active retained auf 0 (seit 1.3.13, C11/M6): eine beim
            # Anhalten abgebrochene Ansage liess bis 1.3.12 "1" stehen.
            geraet._senden("tts_active", "0", True)
            geraet.melden_offline(True)
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
            # Was der SENDECODE wirklich veroeffentlicht (seit 1.3.13, O17):
            # _melden() einmal gegen eine Attrappe des Lautsprechers und eine
            # aufzeichnende MQTT-Huelle. Bis 1.3.12 verglich der Reiter Test
            # die Liste aus bin/cc_themen.json mit derselben Liste, hier nur
            # wieder ausgegeben - ein Thema, das der Code nicht mehr sendet,
            # blieb gruen (Pruefung Oberflaeche 17).
            "gesendet_geraet": sendecode_geraetethemen(),
            "fassung": THEMEN.get("fassung", 0),
            "geraet": [e["schluessel"] for e in THEMEN.get("geraet", ())],
            "dienst": [e["schluessel"] for e in THEMEN.get("dienst", ())],
            "befehle": [e["schluessel"] for e in THEMEN.get("befehle", ())],
            # Audio Stufe 1: die Zonenliste und was der Sendecode an einer Zone wirklich sendet.
            "zone": [e["schluessel"] for e in THEMEN.get("zone", ())],
            "gesendet_zone": sendecode_zonenthemen(),
            # Die Retain-Entscheidung, wie DIESER Code sie trifft - mit einem
            # Thema, das in keiner Liste steht, als Gegenprobe der Vorgabe.
            "retain_dienst": {e["schluessel"]: thema_retain("dienst", e["schluessel"])
                              for e in THEMEN.get("dienst", ())},
            "retain_unbekannt": thema_retain("dienst", "_kein_eintrag_"),
            # Gruppe 6b (1.3.24): Thema und Befehlsname je Name - die Namen der Geraeteliste reicht der Reiter Test
            # als weitere Argumente hinter --themen herein, dazu feste Proben. Haelt cc_thema()/cc_befehl_kennung()
            # gegen thema_saeubern()/befehl_kennung().
            "kennung": [{"name": n, "thema": thema_saeubern(n), "befehl": befehl_kennung(thema_saeubern(n))}
                        for n in (KENNUNG_PROBEN + [a for a in sys.argv[sys.argv.index("--themen") + 1:]
                                                    if not a.startswith("--")])],
        }))
        return

    # "--mqtt-leeren" fuer die Deinstallation (seit 1.3.12). Mit drei
    # Argumenten gilt der Aufruf fuer jede Diensterkennung dieses Plugins als
    # Einmallauf, nicht als Dienst. Jeder andere Schalter wird abgewiesen:
    # bis 1.3.11 startete ein Aufruf mit unbekanntem Schalter den Dienst.
    # Audio Stufe 1 (1.3.25): Selbsttest der reinen Teile - ohne Netz, ohne Lautsprecher, ohne Anlage.
    if sys.argv[1:] == ["--selbsttest"]:
        gut, schlecht, zeilen = selbsttest()
        for z in zeilen:
            print(z)
        print("SELBSTTEST %d/%d" % (gut, schlecht))
        sys.exit(0 if schlecht == 0 else 1)
    if sys.argv[1:] == ["--mqtt-leeren"]:
        sys.exit(mqtt_leeren())
    if sys.argv[1:]:
        sys.stderr.write("Unbekannter Schalter: %s - dieses Skript kennt "
                         "--themen, --selbsttest und --mqtt-leeren.\n" % " ".join(sys.argv[1:]))
        sys.exit(2)

    # Ohne Wurzel steigt der Dienst aus (siehe "Pfade" oben) - nichts
    # gelesen, nichts gesendet, nichts geschrieben.
    if not HOME_DIR:
        sys.stderr.write(
            "Diese Datei liegt nicht in einer LoxBerry-Installation "
            "(ausgepacktes Archiv oder Pruefordner), und LBHOMEDIR und "
            "LBPPLUGINDIR sind nicht beide gesetzt. Damit nichts in eine "
            "Anlage kommt, wurde nichts gestartet.\n")
        sys.exit(1)

    # Das eigene Protokoll (seit 1.3.13, C12) - erst hier, damit "--themen"
    # und "--mqtt-leeren" nicht in das Dienstprotokoll schreiben.
    log_datei_einrichten()

    # EIN Dienst (seit 1.3.13, C7; Regeln/03 "Ein Dauerlaeufer nimmt eine
    # Sperrdatei"). Bis 1.3.12 gab es keine Sperre: zwei Waechterlaeufe in
    # derselben Sekunde (cron holt nach dem Uhrsprung beim Boot nach) oder
    # daemon und Waechter zugleich ergaben 10 von 10 Runden zwei Dienste, beim
    # Boot mit zwei Waechtern 20 von 20 Runden drei (gemessen 30.09.2026,
    # Code-Befund 7, Installer-Befund 1). Das Handle bleibt bis zum
    # Prozessende offen (global); faellt es, faellt die Sperre. Python-Dateien
    # werden seit 3.4 nicht an Kindprozesse vererbt (PEP 446) - espeak-ng
    # bekommt sie also nicht. Bauart BLE-Scanner NG 1.3.20.
    global _SPERRE
    sperrdatei = os.path.join(DATA_DIR, "dienst.lock")
    try:
        import fcntl
        os.makedirs(DATA_DIR, exist_ok=True)
        _SPERRE = open(sperrdatei, "a")
        fcntl.flock(_SPERRE.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
    except ImportError:
        _SPERRE = None          # kein fcntl (nicht Linux): ohne Sperre weiter
    except OSError as fehler:
        if fehler.errno in (errno.EAGAIN, errno.EACCES, errno.EWOULDBLOCK):
            meldung = ("Chromecast 4 Lox NG laeuft bereits (Sperre %s belegt) - "
                       "dieser zweite Start endet." % sperrdatei)
            log.warning("%s", meldung)
            sys.stderr.write(meldung + "\n")
            sys.exit(3)
        log.warning("Sperrdatei %s nicht zu nehmen (%s) - weiter ohne Sperre.",
                    sperrdatei, fehler)

    dienst = Dienst()

    # SIGTERM beendet den Dienst SAUBER (seit 1.3.13, C11/M6): Dienst.stop()
    # meldet server/online 0, die Geraete als Platzhalter offline und
    # tts_active 0. Bis 1.3.12 lief der finally-Zweig nur bei
    # KeyboardInterrupt; cc_dienst('stop'), uninstall und die Hakenskripte
    # schicken aber SIGTERM - 0 Zeilen "Beendet" im Protokoll, tts_active 1
    # blieb retained stehen (gemessen 30.09.2026, Code-Befund 11, MQTT M6).
    def beenden(signum, _rahmen):
        log.info("Signal %s empfangen - der Dienst endet", signum)
        dienst.laeuft = False
        dienst.halt.set()

    signal.signal(signal.SIGTERM, beenden)
    signal.signal(signal.SIGINT, beenden)

    try:
        dienst.start()
    except KeyboardInterrupt:
        log.info("Abbruch durch Signal")
    finally:
        dienst.stop()
        log.info("Beendet")


_SPERRE = None

# Feste Proben fuer "kennung" in --themen (Gruppe 6b): Umlaut, Leerzeichen, Strich, Zeichen ohne Zerlegung.
KENNUNG_PROBEN = ["Wohnzimmer", "Küche Lautsprecher", "Bad-Oben", "Ræv", "Ålesund Box", "WOHN-zimmer 2"]


class _AufzeichnendeHuelle(object):
    """Nimmt auf, was gesendet wuerde - fuer sendecode_geraetethemen()."""

    def __init__(self):
        self.themen = []

    def senden(self, unterthema, wert, retain=True):
        teil = unterthema.split("/", 1)[-1]
        if teil not in self.themen:
            self.themen.append(teil)
        return True


class _AttrappeCast(object):
    """Ein Lautsprecher mit festem Zustand; baut keine Verbindung auf."""

    class _Status(object):
        volume_level = 0.3
        volume_muted = False
        display_name = "Probe"

    class _Medien(object):
        player_state = "PLAYING"
        title = "t"
        artist = "a"
        album_artist = ""
        album_name = "b"
        duration = 10
        adjusted_current_time = 1
        content_id = ""
        content_type = ""

    class _Steuerung(object):
        status = None

    def __init__(self):
        self.status = self._Status()
        self.media_controller = self._Steuerung()
        self.media_controller.status = self._Medien()
        self.socket_client = object()
        self.cast_type = "audio"


def sendecode_geraetethemen():
    """Die Geraetethemen, die _melden(erzwingen=True) und melden_offline()
    wirklich senden - in der Reihenfolge des ersten Auftretens. Kein Netz,
    keine Datei: der Lautsprecher und die MQTT-Huelle sind Attrappen."""
    huelle = _AufzeichnendeHuelle()
    geraet = Geraet("Probe", huelle)
    geraet.cast = _AttrappeCast()
    geraet._melden(erzwingen=True)
    geraet.cast = None
    geraet.melden_offline(True)
    return huelle.themen


def sendecode_zonenthemen():
    """Die Zonenthemen, die ein Geraet mit einer Zone wirklich sendet (Audio Stufe 1) - fuer die Namenspruefung im
    Reiter Test, wie sendecode_geraetethemen(). Kein Netz, keine Datei."""
    themen = []

    class _Huelle(object):
        def senden(self, unterthema, wert, retain=True):
            teile = unterthema.split("/")
            if len(teile) == 3 and teile[0] == "zone" and teile[2] not in themen:
                themen.append(teile[2])
            return True

    geraet = Geraet("Probe", _Huelle())
    geraet.zonen_nr = [1]
    geraet.cast = _AttrappeCast()
    geraet._melden(erzwingen=True)
    geraet._quelle_setzen(0, "")
    geraet.cast = None
    geraet.melden_offline(True)
    return themen


# Die Probedateien des Selbsttests - genau diese werden danach einzeln wieder entfernt, nichts sonst.
_PROBE_DATEIEN = ("rock/b.mp3", "rock/A.mp3", "gong.mp3", "liste.m3u", "notiz.txt")
_PROBE_ORDNER = ("media/nas/musik/rock", "media/nas/musik", "media/nas", "media")


def selbsttest():
    """Selbsttest der reinen Teile von Audio Stufe 1 (--selbsttest): Zonen- und Quellenliste, Pfade der
    Medienablage, M3U/PLS, Radioaufloesung gegen eine eingebaute Attrappe, Inhaltstypen, Zonenspiegel. Kein Netz,
    kein Lautsprecher; Dateien nur in einem eigenen Probeordner, die danach einzeln entfernt werden. Rueckgabe
    (gut, schlecht, zeilen)."""
    import tempfile
    zeilen = []

    def pruef(name, bedingung):
        zeilen.append(("ok  " if bedingung else "FEHL") + " " + name)

    z = zonen({"zonen": "1 = Gaeste | Zimmer A\n2=Zimmer B\n 3 = | Gruppe X\n0 = falsch\n25 = falsch\n2 = doppelt\n"
                         "x = y\n4 = Name | \n5 = a;b\n# Kommentar"})
    pruef("Zonen: gueltige Zeilen 1, 2, 3", [e["nr"] for e in z] == [1, 2, 3])
    pruef("Zonen: Anzeigename und Ziel getrennt", z[0]["name"] == "Gaeste" and z[0]["ziel"] == "Zimmer A")
    pruef("Zonen: ohne Name gilt das Ziel", z[1]["name"] == "Zimmer B" and z[2]["name"] == "Gruppe X")
    q = quellen({"quellen": "1 = radio | Sender | http://radio.example/s.mp3\n2 = liste | Rock | musik/rock\n"
                            "3 = datei | Gong | gong.mp3\n4 = video | x | y\n5 = radio | ohne\n1 = radio | d | http://x\n",
                 "favoriten": "Alt = http://alt.example/a.mp3"})
    pruef("Quellen: 1, 2, 3 gueltig, 4/5 und doppelte 1 uebergangen", sorted(q) == [1, 2, 3])
    pruef("Quellen: Art und Name", q[2]["art"] == "liste" and q[2]["name"] == "Rock")
    fq = quellen({"quellen": "", "favoriten": "A = http://a.example/1\nB = http://b.example/2"})
    pruef("Quellen: leer -> Favoriten als radio 1..n", sorted(fq) == [1, 2] and fq[2]["art"] == "radio"
          and fq[2]["adresse"] == "http://b.example/2")
    pruef("Ablage relativ: gut", ablage_relativ("musik//rock/./a.mp3") == "musik/rock/a.mp3")
    pruef("Ablage relativ: .. abgewiesen", ablage_relativ("musik/../../etc/passwd") is None)
    pruef("Ablage relativ: absolut abgewiesen", ablage_relativ("/etc/passwd") is None
          and ablage_relativ("C:/x.mp3") is None and ablage_relativ(chr(92) * 2 + "srv" + chr(92) + "x") is None)
    pruef("Inhaltstyp", inhaltstyp("a/B.MP3") == "audio/mpeg" and inhaltstyp("x.flac") == "audio/flac"
          and inhaltstyp("x.php") is None)
    pruef("Inhaltstyp: .mp4 = Ton (audio/mp4), auch gross; .mp4.php und .mkv nicht",
          inhaltstyp("Konzert/Live.MP4") == "audio/mp4" and inhaltstyp("http://a.example/v.mp4?x=1") == "audio/mp4"
          and inhaltstyp("v.mp4.php") is None and inhaltstyp("v.mkv") is None and inhaltstyp("v.m4v") is None)
    m = m3u_lesen("#EXTM3U\n#EXTINF:123,Interpret A - Titel A\nlied1.mp3\n../../boese.mp3\n"
                  "http://srv.example/x.mp3\n#EXTINF:-1,Nur Titel\nsub/lied2.flac\n", "listen")
    pruef("M3U: Eintraege relativ zur Liste, .. faellt weg", [e[0] for e in m] ==
          ["listen/lied1.mp3", "http://srv.example/x.mp3", "listen/sub/lied2.flac"])
    pruef("M3U: Titel und Interpret", m[0][1] == "Titel A" and m[0][2] == "Interpret A" and m[2][1] == "Nur Titel")
    pruef("PLS: erste Adresse", pls_lesen("[playlist]\nFile2=http://b\nFile1=http://a\nNumberOfEntries=2") == "http://a")

    netz = {
        "http://r.example/sender.m3u": ("audio/x-mpegurl", "#EXTM3U\nhttp://r.example/strom.aac\n"),
        "http://r.example/strom.aac": ("audio/aacp", ""),
        "http://r.example/sender.pls": ("audio/x-scpls", "[playlist]\nFile1=http://r.example/strom.mp3\n"),
        "http://r.example/strom.mp3": ("audio/mpeg", ""),
        "http://r.example/hls.m3u8": ("application/vnd.apple.mpegurl", "#EXTM3U\n#EXT-X-VERSION:3\nseg.ts\n"),
        "http://r.example/ohne_typ": ("text/html", ""),
    }

    def holen(a):
        if a not in netz:
            raise OSError("keine Antwort")
        return netz[a]

    pruef("Radio: m3u aufgeloest, Typ aus der Antwort (AAC)",
          radio_aufloesen("http://r.example/sender.m3u", holen)[:2] == ("http://r.example/strom.aac", "audio/aacp"))
    pruef("Radio: pls aufgeloest", radio_aufloesen("http://r.example/sender.pls", holen)[:2]
          == ("http://r.example/strom.mp3", "audio/mpeg"))
    pruef("Radio: HLS bleibt Liste mit eigenem Typ", radio_aufloesen("http://r.example/hls.m3u8", holen)[:2]
          == ("http://r.example/hls.m3u8", HLS_TYP))
    a, t, h = radio_aufloesen("http://r.example/tot.mp3", holen)
    pruef("Radio: keine Antwort -> Adresse bleibt, Hinweis", a == "http://r.example/tot.mp3" and t == "audio/mpeg" and h)
    pruef("Radio: fremder Typ -> Rueckfall audio/mpeg", radio_aufloesen("http://r.example/ohne_typ", holen)[1]
          == "audio/mpeg")

    probe = tempfile.mkdtemp(prefix="cc_selbsttest_")
    wurzel = os.path.join(probe, "media")
    ablage = os.path.join(wurzel, "nas", "musik")
    os.makedirs(os.path.join(ablage, "rock"))
    php = os.path.join(ablage, "rock", "x.php")
    try:
        for n in _PROBE_DATEIEN:
            with open(os.path.join(ablage, n), "w", encoding="utf-8") as fh:
                fh.write("#EXTM3U\nrock/b.mp3\n" if n.endswith(".m3u") else "x")
        w = [os.path.realpath(wurzel)]
        echt, grund = ablage_pfad_taugt(ablage, w)
        pruef("Ablage: Pfad unter der Wurzel taugt", grund == "" and echt == os.path.realpath(ablage))
        pruef("Ablage: Pfad ausserhalb abgewiesen", ablage_pfad_taugt(probe, w)[1] != "")
        pruef("Ablage: Wurzel selbst abgewiesen", ablage_pfad_taugt(wurzel, w)[1] != "")
        pruef("Ablage: .. abgewiesen", ablage_pfad_taugt(ablage + "/../musik", w)[1] != "")
        zahl, grund = ablage_durchsehen(echt)
        pruef("Ablage: 3 Audiodateien, freigegeben", zahl == 3 and grund == "")
        with open(php, "w", encoding="utf-8") as fh:
            fh.write("<?php")
        pruef("Ablage: PHP-Datei -> nicht freigegeben", ablage_durchsehen(echt)[1] != "")
        os.remove(php)
        # Nachfassung 4: nach dem Namen, Grossschreibung egal (Netzfreigabe: alle Dateien 0666), .cgi. im Namen.
        for name in ("X.PHP", "lied.cgi.mp3", "Lied.PHTML"):
            p2 = os.path.join(ablage, "rock", name)
            with open(p2, "w", encoding="utf-8") as fh:
                fh.write("x")
            g2 = ablage_durchsehen(echt)[1]
            os.remove(p2)
            pruef("Ablage: %s -> nicht freigegeben" % name, g2 != "")
        aussen = os.path.join(ablage, "rock", "aussen")
        try:
            os.symlink(probe, aussen, target_is_directory=True)
        except (OSError, NotImplementedError):
            aussen = ""
        if aussen:
            pruef("Ablage: Verweis nach ausserhalb -> nicht freigegeben",
                  "ausserhalb" in ablage_durchsehen(echt)[1])
            _verweis_weg(aussen)
            os.symlink(os.path.join(ablage, "rock"), aussen, target_is_directory=True)
            pruef("Ablage: Verweis nach innen -> freigegeben", ablage_durchsehen(echt)[1] == "")
            _verweis_weg(aussen)
        else:
            print("INFO Ablage: hier ist kein Verweis anlegbar - die zwei Verweis-Faelle sind nicht gemessen")
        pruef("Ablage: Grenze der Durchsicht", ablage_durchsehen(echt, grenze=2)[1] != "")
        for name in ("lied.php.mp3", "Mix.SH.ogg"):
            p3 = os.path.join(ablage, "rock", name)
            with open(p3, "w", encoding="utf-8") as fh:
                fh.write("x")
            fund = []
            g3 = ablage_durchsehen(echt, fund=fund)[1]
            os.remove(p3)
            pruef("Ablage: %s (Endung mitten im Namen) -> nicht frei, Fund relativ" % name,
                  g3 != "" and fund == [os.path.join("rock", name)])
        pruef("Ablage: Durchsicht bricht bei Klingel/Ansage ab",
              ablage_durchsehen(echt, abbrechen=lambda: True)[1].startswith("Durchsicht abgebrochen"))
        pruef("Ablage: Durchsicht bricht an der Zeitgrenze ab",
              ablage_durchsehen(echt, frist=-1.0)[1].startswith("Durchsicht abgebrochen"))
        schutz = os.path.join(probe, ".htaccess")
        try:
            g1 = index_schutz_setzen(probe)
            with open(schutz, "r", encoding="utf-8", newline="") as fh:
                inhalt = fh.read()
            schutz_zeilen = [z.strip() for z in inhalt.split("\n") if z.strip() and not z.startswith("#")]
            pruef("Ablage: .htaccess ohne Verzeichnisliste, Punkt-Pfade 404, LF", g1 == "" and schutz_zeilen[:2] ==
                  ["Options -Indexes", "RedirectMatch 404 \"/\\.\""] and "\r" not in inhalt)
            pruef("Ablage: .htaccess mit zweiter Sperre (Endungen 404, nur als Datei, keine Aushandlung)",
                  "RedirectMatch 404 \"%s\"" % SPERRE_MUSTER in schutz_zeilen
                  and "Options -MultiViews -ExecCGI -Includes" in schutz_zeilen
                  and "SetHandler default-handler" in schutz_zeilen and "DirectoryIndex disabled" in schutz_zeilen
                  and schutz_zeilen.count("</If>") == 1 and schutz_zeilen[-1] == "</If>")
            sperre = re.compile(SPERRE_MUSTER)
            mm = "/plugins/x/m_0123456789abcdef01234567/"
            gesperrt = [mm + n for n in ("test.php", "Test.PHP", "a.php5", "rock/lied.cgi.mp3", "x.phtml", "x.phar",
                                         "y.PL", "z.py", "s.sh", "i.shtml", "x.php/a.mp3", "x.php.", "o.x/t.Php7")]
            frei = [mm + n for n in ("rock/A.mp3", "Lied.MP3", "liste.m3u", "radio.pls", "alt.shn", "phpfrei.ogg",
                                     "sh.flac", "Pl.wav")] + ["/plugins/x/index.php", "/plugins/x/a/test.php"]
            pruef("Ablage: zweite Sperre trifft Programme unter m_<Schluessel>/, nicht Audio, nicht die eigene index.php",
                  all(sperre.search(u) for u in gesperrt) and not any(sperre.search(u) for u in frei))
            with open(schutz, "w", encoding="utf-8") as fh:
                fh.write("Options +Indexes\n")
            pruef("Ablage: fremde .htaccess wird ersetzt, keine Zwischendatei bleibt",
                  index_schutz_setzen(probe) == "" and open(schutz, encoding="utf-8").read() == INDEX_SCHUTZ
                  and not [n for n in os.listdir(probe) if n.startswith(".htaccess.neu")])
            with open(schutz, "w", encoding="utf-8", newline="") as fh:
                fh.write(INDEX_SCHUTZ.split("# Zweite Sperre")[0])
            pruef("Ablage: .htaccess der Fassung ohne zweite Sperre wird erkannt und ersetzt",
                  index_schutz_setzen(probe) == "" and open(schutz, encoding="utf-8").read() == INDEX_SCHUTZ)
            pruef("Ablage: Schutz nicht anlegbar -> Grund", index_schutz_setzen(os.path.join(probe, "fehlt")) != "")
        finally:
            try:
                os.remove(schutz)
            except OSError:
                pass
        mounts = os.path.join(probe, "mounts")
        with open(mounts, "w", encoding="utf-8") as fh:
            fh.write("/dev/root / ext4 rw 0 0\n/dev/sda1 /media/usb exfat rw 0 0\n//nas/m /media/smb/nas cifs rw 0 0\n")
        pruef("Ablage: Dateisystem aus den Einhaengepunkten (laengster gewinnt)",
              ablage_dateisystem("/media/usb/Musik", mounts) == "exfat"
              and ablage_dateisystem("/media/smb/nas/x", mounts) == "cifs"
              and ablage_dateisystem("/opt/x", mounts) == "ext4" and ablage_dateisystem("/x", mounts + ".fehlt") == "")
        os.remove(mounts)
        alt = dict(ABLAGE_STAND)
        ABLAGE_STAND.update({"ok": 1, "pfad": echt, "schluessel": "0123456789abcdef01234567"})
        try:
            e, g = liste_aufbauen({"adresse": "rock"})
            pruef("Liste: Ordner alphabetisch", g == "" and [x[0] for x in e] == ["rock/A.mp3", "rock/b.mp3"])
            e, g = liste_aufbauen({"adresse": "liste.m3u"})
            pruef("Liste: M3U", g == "" and [x[0] for x in e] == ["rock/b.mp3"])
            pruef("Liste: Pfad hinaus abgewiesen", liste_aufbauen({"adresse": "../x"})[1] != "")
            u = ablage_adresse({"tts_lokal_basis": "http://lb.example/plugins/p/"}, "rock/A b.mp3")
            pruef("Adresse: Basis + Schluessel + kodierter Pfad",
                  u == "http://lb.example/plugins/p/m_0123456789abcdef01234567/rock/A%20b.mp3")
            pruef("Adresse: Pfad hinaus -> leer", ablage_adresse({"tts_lokal_basis": "http://lb"}, "../x.mp3") == "")
            # Nachfassung 7: der Klingel-Gong haengt nicht an der Ablage - fehlt die Datei, klingt der eigene Gong bzw. der
            # Standard-Gong. Klangordner und Ordner des eigenen Gongs liegen hier im Probeordner.
            kw, ke = os.path.join(probe, "klang"), os.path.join(probe, "klaenge")
            _KLANG_ORTE.update({"web": kw, "eigen": ke})
            try:
                import struct
                lb = {"tts_lokal_basis": "http://lb"}
                gg = klingel_gong(dict(lb, klingel_gong="gong.mp3"))
                pruef("Klingel-Gong aus der Ablage", gg is not None and gg[0].endswith("/gong.mp3") and gg[1] == "audio/mpeg"
                      and gg[2] == "")
                gg = klingel_gong(dict(lb, klingel_gong="fehlt.mp3"))
                pruef("Klingel-Gong fehlt in der Ablage -> Standard-Gong, Hinweis fuers Protokoll (nie stumm)",
                      gg is not None and gg[:2] == ("http://lb/klang/" + KLANG_STANDARD, "audio/wav")
                      and "fehlt.mp3" in gg[2] and "Standard-Gong" in gg[2])
                with open(os.path.join(kw, KLANG_STANDARD), "rb") as fh:
                    w = fh.read()
                pcm = struct.unpack("<%dh" % ((len(w) - 44) // 2), w[44:])
                pruef("Standard-Gong: WAV PCM 22050 Hz mono, 0,4 s Stille, nicht uebersteuert, am Ende ausgeblendet",
                      w[:4] == b"RIFF" and w[8:16] == b"WAVEfmt " and len(w) == 44 + 2 * _GONG_PROBEN
                      and struct.unpack("<HHI", w[20:28]) == (1, 1, _GONG_RATE) and not any(pcm[:int(0.4 * _GONG_RATE)])
                      and 16000 < max(abs(x) for x in pcm) < 32767 and abs(pcm[-1]) < 100)
                os.makedirs(ke)
                with open(os.path.join(ke, "eigener_gong.mp3"), "wb") as fh:
                    fh.write(b"ID3" + bytes(40))
                gg = klingel_gong(dict(lb, klingel_gong="fehlt.mp3"))
                ew = sorted(n for n in os.listdir(kw) if KLANG_WEB_EIGEN.match(n))
                pruef("Klingel-Gong fehlt -> eigener Gong, bereitgestellt unter klang/eigen_<Inhalt>.mp3",
                      gg is not None and len(ew) == 1 and gg[:2] == ("http://lb/klang/" + ew[0], "audio/mpeg")
                      and "eigene Gong" in gg[2])
                gg = klingel_gong(dict(lb, klingel_gong="", tts_gong="https://g.example/g.mp3"))
                pruef("Klingel-Gong leer: eigener Gong vor dem Ansage-Gong", gg is not None and ew
                      and gg == ("http://lb/klang/" + ew[0], "audio/mpeg", ""))
                gg = klingel_gong(dict(lb, klingel_gong="gong.mp3"))
                pruef("Klingel-Gong: Datei der Ablage geht vor dem eigenen", gg is not None and gg[0].endswith("/gong.mp3"))
                with open(os.path.join(ke, "eigener_gong.mp3"), "wb") as fh:
                    fh.write(b"<?php echo 1; ?>")
                gg = klingel_gong(dict(lb, klingel_gong=""))
                pruef("Eigener Gong mit fremdem Inhalt -> Standard-Gong, alte Bereitstellung fort",
                      gg is not None and gg[0].endswith("/" + KLANG_STANDARD)
                      and not [n for n in os.listdir(kw) if KLANG_WEB_EIGEN.match(n)])
                os.remove(os.path.join(ke, "eigener_gong.mp3"))
                pruef("Klingel-Gong: leer, kein eigener -> Ansage-Gong",
                      klingel_gong(dict(lb, klingel_gong="", tts_gong="https://g.example/g.mp3"))
                      == ("https://g.example/g.mp3", "audio/mpeg", ""))
                ABLAGE_STAND["ok"] = 0
                gg = klingel_gong(dict(lb, klingel_gong="gong.mp3"))
                ABLAGE_STAND["ok"] = 1
                pruef("Ablage nicht freigegeben -> Standard-Gong, Hinweis nennt den Grund",
                      gg is not None and gg[0].endswith("/" + KLANG_STANDARD) and "nicht freigegeben" in gg[2])
                pruef("Klang: Inhalt passt zur Endung", klang_art(b"OggS" + bytes(8), ".ogg")
                      and klang_art(b"RIFF" + bytes(4) + b"WAVE", ".wav") and klang_art(bytes([255, 251, 144]), ".mp3")
                      and not klang_art(b"RIFF" + bytes(4) + b"WAVE", ".mp3") and not klang_art(b"<?php", ".mp3"))
            finally:
                _KLANG_ORTE.clear()
                for o in (kw, ke):
                    if os.path.isdir(o):
                        for n in os.listdir(o):
                            if n == KLANG_STANDARD or KLANG_WEB_EIGEN.match(n) or KLANG_EIGEN_NAME.match(n):
                                try:
                                    os.remove(os.path.join(o, n))
                                except OSError:
                                    pass
                        try:
                            os.rmdir(o)
                        except OSError:
                            pass
        finally:
            ABLAGE_STAND.clear()
            ABLAGE_STAND.update(alt)
    finally:
        # Genau die eigenen Probedateien und -ordner, einzeln und nicht rekursiv.
        for n in _PROBE_DATEIEN + ("rock/x.php",):
            try:
                os.remove(os.path.join(ablage, n))
            except OSError:
                pass
        for o in _PROBE_ORDNER + ("",):
            try:
                os.rmdir(os.path.join(probe, o) if o else probe)
            except OSError:
                pass

    pruef("Zonennummer", zone_nummer("zone_3") == 3 and zone_nummer("Zone_24") == 24 and zone_nummer("zone_25") is None
          and zone_nummer("zone_0") is None and zone_nummer("wohnzimmer") is None)
    zt = sendecode_zonenthemen()
    soll = [e["schluessel"] for e in THEMEN.get("zone", [])]
    pruef("Zonenspiegel: genau die Themen der Liste 'zone'", sorted(zt) == sorted(soll) and len(soll) > 0)
    sichtbar_merken({"a": type("I", (), {"friendly_name": "Fremd", "model_name": "M"})()})
    pruef("Sichtbar: nur Name und Modell, eingetragene fallen weg",
          sichtbar_fremd({"Andere"}) == [{"name": "Fremd", "modell": "M"}] and sichtbar_fremd({"Fremd"}) == [])
    # Nachfassung 3 (O1): Mitgliedschaft ueber Kennung oder Namen, eine Gruppe ist in keiner Gruppe.
    ga, gb, gc, gd = Geraet("Wohnzimmer", None), Geraet("Klingel", None), Geraet("Bad", None), Geraet("Flur", None)
    nb = {"Wohnzimmer": ga, "Klingel": gb, "Bad": gc, "Flur": gd}
    for gx in nb.values():
        gx.nachbarn = nb
    gb.ist_gruppe = True
    gb.mitglieder = [{"kennung": "00000000-0000-0000-0000-00000000000A", "name": "Anders"},
                     {"kennung": "", "name": "wohnzimmer"}]
    gc.uuid = "0" * 31 + "a"
    pruef("Mitgliedschaft (O1): Name oder Kennung, nicht Flur, Gruppe in keiner",
          ga.mitglied_von() == ["Klingel"] and gc.mitglied_von() == ["Klingel"] and gd.mitglied_von() == []
          and gb.mitglied_von() == [] and kennung_gleich("AB-cd", "abcd") and not kennung_gleich("", "-")
          and name_gleich("G\u00e4ste", "ga\u0308ste") and not name_gleich("", ""))
    # 1.3.26 (Hoertest 01.11.2026): Fehlertext, Lesefehler der Ablage, Abmeldung in der Dauersuche
    nv = type("NotConnected", (Exception,), {})("Chromecast {self.host}:{self.port} is connecting...")
    pruef("1.3.26 Fehlertext: 'is connecting' -> Gerät/Gruppe 'Klingel' noch nicht verbunden, ohne Platzhalter",
          nicht_verbunden(nv) and fehler_lesbar(nv, "Klingel") == "Gerät/Gruppe 'Klingel' noch nicht verbunden"
          and not nicht_verbunden(OSError("x")))
    pruef("1.3.26 Fehlertext: Adresse und unformatierter Platzhalter fallen heraus",
          fehler_lesbar(OSError("Fehler bei 192.168.178.20:8009 {self.host}:{self.port}")) == "Fehler bei <Adresse>")
    pruef("1.3.26 Ablage: errno 116 -> Hinweis noserverino/autofs; anderer Lesefehler -> 'nicht lesbar'",
          ablage_lesefehler(OSError(116, "Stale file handle")) == ESTALE_TEXT and "noserverino" in ESTALE_TEXT
          and "auto.smb" in ESTALE_TEXT
          and ablage_lesefehler(OSError(13, "Permission denied")) == "Datei der Ablage nicht lesbar (Permission denied)")
    ns = Netzsuche()
    ns.gefunden["Klingel"] = type("I", (), {"uuid": "00000000-0000-0000-0000-0000000000b2"})()
    pruef("1.3.26 Dauersuche: Abmeldung der alten Kennung laesst den neuen Eintrag gleichen Namens stehen",
          not ns.abmelden("Klingel", "00000000-0000-0000-0000-0000000000b1") and "Klingel" in ns.gefunden
          and ns.abmelden("Klingel", "00000000-0000-0000-0000-0000000000b2") and "Klingel" not in ns.gefunden)
    SICHTBAR.clear()
    gut = sum(1 for zz in zeilen if zz.startswith("ok"))
    return gut, len(zeilen) - gut, zeilen


if __name__ == "__main__":
    main()
