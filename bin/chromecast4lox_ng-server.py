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

    def __init__(self, praefix, befehl_rueckruf):
        self.praefix = praefix
        self.befehl_rueckruf = befehl_rueckruf
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
        client.subscribe(thema)
        log.info("MQTT verbunden, Befehle abonniert: %s", thema)
        self.senden("server/online", "1")

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
    if len(teile) == 3 and teile[0] not in ("", "server") and teile[1] == "cmd":
        return thema_info("befehle", teile[2]) is not None
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
    if nur is not None and (str(nur) in ("", "server") or re.search(r"[/#+]", str(nur))):
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
                basis = "http://{0}/plugins/{1}".format(ip, PLUGIN_NAME)
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
        self.ansage_abbrechen = False
        self.ansage_laeuft = True
        gespielt = False
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
                if self._auf_ende_warten(mc):
                    gespielt = True
                if len(adressen) > 1:
                    log.info("'%s': Ansageteil %d von %d gesprochen",
                             self.name, nummer, len(adressen))
            if fortsetzen:
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
        while time.time() < ende:
            try:
                zustand = mc.status.player_state if mc.status else "IDLE"
            except Exception:  # noqa: BLE001
                return gespielt
            if zustand not in ("PLAYING", "BUFFERING"):
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
                lautstaerke_setzen(self.cast, self._gedeckelt(pegel, cfg) / 100.0)
            elif befehl in ("volume_step", "adjust_volume"):
                delta, fehlertext = befehlszahl(wert, schrittweite, -100, 100, befehl)
                if fehlertext:
                    return fehlertext
                jetzt = self.cast.status.volume_level if self.cast.status else 0
                pegel = max(0, min(100, int(round(jetzt * 100)) + delta))
                lautstaerke_setzen(self.cast, self._gedeckelt(pegel, cfg) / 100.0)
            elif befehl in ("volume_up", "lauter"):
                # Ein negativer Schritt wird abgewiesen: "lauter -60" war bis
                # 1.3.12 ein zweiter Weg, leiser zu werden.
                delta, fehlertext = befehlszahl(wert, schrittweite, 0, 100, befehl)
                if fehlertext:
                    return fehlertext
                jetzt = self.cast.status.volume_level if self.cast.status else 0
                pegel = int(round(min(1.0, jetzt + delta / 100.0) * 100))
                lautstaerke_setzen(self.cast, self._gedeckelt(pegel, cfg) / 100.0)
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
                lautstaerke_setzen(self.cast, self._gedeckelt(pegel, cfg) / 100.0)
            elif befehl == "mute":
                stumm_setzen(self.cast, str(wert).strip() not in ("0", "", "false", "aus"))
            elif befehl == "next":
                mc.queue_next()
            elif befehl in ("prev", "previous"):
                mc.queue_prev()
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
        # Unterbrechbares Warten (seit 1.3.13, C11): SIGTERM beendet den Takt
        # sofort, nicht erst nach bis zu "intervall" Sekunden.
        self.halt = threading.Event()
        self.udp_absender = miniserver_adressen()
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
                weg = self.geraete[alt]
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

    def geraet_abraeumen(self, weg, namen):
        """Ein entferntes Geraet (seit 1.3.13, M4; Entscheidung 5).

        Einmal "-" retained fuer jeden retained Zustand - Loxone sieht
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
        for eintrag in THEMEN.get("geraet", []):
            if eintrag.get("retain", False):
                if self.mqtt.senden_und_warten(weg.thema + "/" + eintrag["schluessel"], "-"):
                    gesendet += 1
        try:
            code, geleert, rest, grund = broker_leeren(self.praefix, nur=weg.thema)
        except Exception as fehler:  # noqa: BLE001
            code, geleert, rest, grund = 2, [], [], str(fehler)
        if code == 0:
            log.info("'%s' entfernt: %d Zustaende als '-' gemeldet, %d Themen unter "
                     "%s/%s/ abgeraeumt und nachgelesen", weg.name, gesendet,
                     len(geleert), self.praefix, weg.thema)
        elif code == 1:
            log.warning("'%s' entfernt: unter %s/%s/ stehen noch %d Themen: %s",
                        weg.name, self.praefix, weg.thema, len(rest), ", ".join(rest[:5]))
        else:
            log.warning("'%s' entfernt: %d Zustaende als '-' gemeldet, Abraeumen nicht "
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
            if self.praefix_pruefen():
                self.mqtt.start()
                self.praefix_merken()
        else:
            log.info("MQTT ist ausgeschaltet")

        self.geraete_aufbauen()

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
            if self.mqtt.verbunden:
                self.mqtt.befehle_abraeumen(self.halt.wait)

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
                # server/online 1 mit jeder Vollmeldung (seit 1.3.13): endet
                # ein zweiter Dienst, setzt sein Letzter Wille die 0 - der
                # verbliebene sagte bis 1.3.12 erst beim naechsten eigenen
                # Verbinden wieder 1 (Pruefung MQTT M7).
                if self.mqtt.verbunden:
                    self.mqtt.senden("server/online", "1")

            # Das Lebenszeichen geht in JEDEM Durchgang hinaus, auch wenn
            # sich sonst nichts geaendert hat.
            self.herzschlag(sum(1 for g in self.geraete.values() if g.verbunden()))

            # Konfigurationsaenderung uebernehmen, ohne Neustart
            if self._mtime() != self.config_mtime:
                log.info("Konfiguration geaendert - wird neu eingelesen")
                self.config_mtime = self._mtime()
                vorher = self.cfg
                self.cfg = konfiguration_lesen()
                self.verbindungen_nachziehen(vorher)
                self.geraete_aufbauen()
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
        for geraet in self.geraete.values():
            geraet.faden_laeuft = False
            geraet.ansage_abbrechen = True
            try:
                geraet.auftraege.put_nowait(None)
            except Exception:  # noqa: BLE001
                pass
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
            # Die Retain-Entscheidung, wie DIESER Code sie trifft - mit einem
            # Thema, das in keiner Liste steht, als Gegenprobe der Vorgabe.
            "retain_dienst": {e["schluessel"]: thema_retain("dienst", e["schluessel"])
                              for e in THEMEN.get("dienst", ())},
            "retain_unbekannt": thema_retain("dienst", "_kein_eintrag_"),
        }))
        return

    # "--mqtt-leeren" fuer die Deinstallation (seit 1.3.12). Mit drei
    # Argumenten gilt der Aufruf fuer jede Diensterkennung dieses Plugins als
    # Einmallauf, nicht als Dienst. Jeder andere Schalter wird abgewiesen:
    # bis 1.3.11 startete ein Aufruf mit unbekanntem Schalter den Dienst.
    if sys.argv[1:] == ["--mqtt-leeren"]:
        sys.exit(mqtt_leeren())
    if sys.argv[1:]:
        sys.stderr.write("Unbekannter Schalter: %s - dieses Skript kennt "
                         "--themen und --mqtt-leeren.\n" % " ".join(sys.argv[1:]))
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


if __name__ == "__main__":
    main()
