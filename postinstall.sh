#!/bin/bash

# Bashscript which is executed by bash *AFTER* complete installation is done
# (but *BEFORE* postupdate). Use with caution and remember, that all systems
# may be different! Better to do this in your own Pluginscript if possible.
#
# Exit code must be 0 if executed successfull. 
# Exit code 1 gives a warning but continues installation.
# Exit code 2 cancels installation.
#
# Will be executed as user "loxberry".
#
# You can use all vars from /etc/environment in this script.
#
# We add 5 additional arguments when executing this script:
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# For logging, print to STDOUT. You can use the following tags for showing
# different colorized information during plugin installation:
#
# <OK> This was ok!"
# <INFO> This is just for your information."
# <WARNING> This is a warning!"
# <ERROR> This is an error!"
# <FAIL> This is a fail!"

# To use important variables from command line use the following code:
COMMAND=$0    # Zero argument is shell command
PTEMPDIR=$1   # First argument is temp folder during install
PSHNAME=$2    # Second argument is Plugin-Name for scipts etc.
PDIR=$3       # Third argument is Plugin installation folder
PVERSION=$4   # Forth argument is Plugin version
#LBHOMEDIR=$5 # Comes from /etc/environment now. Fifth argument is
              # Base folder of LoxBerry

# Rueckfall, falls sudo die Umgebung ausgeraeumt hat (env_reset) - wie in
# preupgrade.sh und postupgrade.sh. Bis 1.3.9 fehlte er hier: mit leerer
# Umgebung zeigte $PBIN auf /<ordner>, und chmod lief ins Leere (in WSL
# gemessen am 17.09.2026).
LBHOMEDIR="${LBHOMEDIR:-$5}"
LBPCONFIG="${LBPCONFIG:-$5/config/plugins}"
LBPLOG="${LBPLOG:-$5/log/plugins}"
LBPBIN="${LBPBIN:-$5/bin/plugins}"
LBPHTML="${LBPHTML:-$5/webfrontend/html/plugins}"
LBPTEMPL="${LBPTEMPL:-$5/templates/plugins}"
LBPSBIN="${LBPSBIN:-$5/sbin/plugins}"
LBPCGI="${LBPCGI:-$5/webfrontend/htmlauth/plugins}"
LBPDATA="${LBPDATA:-$5/data/plugins}"

# Combine them with /etc/environment
PCGI=$LBPCGI/$PDIR
PHTML=$LBPHTML/$PDIR
PTEMPL=$LBPTEMPL/$PDIR
PDATA=$LBPDATA/$PDIR
PLOG=$LBPLOG/$PDIR # Note! This is stored on a Ramdisk now!
PCONFIG=$LBPCONFIG/$PDIR
PSBIN=$LBPSBIN/$PDIR
PBIN=$LBPBIN/$PDIR

# ---------- Die LoxBerry-Wurzel, geprueft (seit 1.3.12) ----------
# Aus dem fuenften Argument des Installers, sonst LBHOMEDIR, sonst vom
# eigenen Ablageort aufwaerts - und nur, wenn dort config/plugins,
# data/plugins UND config/system/general.json liegen (Regeln/06, Muster 1
# der Nachlese). Bis 1.3.11 lief dieses Skript ohne beides gegen Pfade ab
# "/" und meldete am Ende Erfolg; mit $5 auf einem fremden Baum legte
# preupgrade.sh dort Marke und Sicherung an (in WSL gemessen 25.09.2026,
# Pruefung-Chromecast4lox-1.3.12, Faelle S1 bis S5). Ohne Wurzel: <WARNING>,
# nichts tun, Rueckgabe 1.
cc_wurzel_suchen() {
    cc_v=$(cd "$(dirname "$(readlink -f "$0")")" 2>/dev/null && pwd -P)
    cc_i=0
    while [ -n "$cc_v" ] && [ "$cc_v" != "/" ] && [ "$cc_i" -lt 8 ]; do
        if [ -d "$cc_v/config/plugins" ] && [ -d "$cc_v/data/plugins" ] \
           && [ -f "$cc_v/config/system/general.json" ]; then
            echo "$cc_v"
            return 0
        fi
        cc_v=$(dirname "$cc_v")
        cc_i=$((cc_i + 1))
    done
    return 1
}
CC_BASE="${5:-}"
[ -n "$CC_BASE" ] || CC_BASE="${LBHOMEDIR:-}"
[ -n "$CC_BASE" ] || CC_BASE=$(cc_wurzel_suchen) || CC_BASE=""
if [ -z "$CC_BASE" ] || [ ! -d "$CC_BASE/config/plugins" ] \
   || [ ! -d "$CC_BASE/data/plugins" ] \
   || [ ! -f "$CC_BASE/config/system/general.json" ]; then
    echo "<WARNING> Keine LoxBerry-Wurzel erkannt: '${CC_BASE:-leer}' traegt nicht"
    echo "<WARNING> config/plugins, data/plugins und config/system/general.json."
    echo "<WARNING> $(basename "$0") hat nichts getan."
    exit 1
fi
LBHOMEDIR="$CC_BASE"
CC_PFOLDER="${3:-chromecast-4lox-ng}"
PDIR="$CC_PFOLDER"
PBIN="$CC_BASE/bin/plugins/$CC_PFOLDER"

echo "<INFO> Command is: $COMMAND"
echo "<INFO> Temporary folder is: $PTEMPDIR"
echo "<INFO> (Short) Name is: $PSHNAME"
echo "<INFO> Installation folder is: $PDIR"
echo "<INFO> Plugin version is: $PVERSION"
echo "<INFO> Plugin CGI folder is: $PCGI"
echo "<INFO> Plugin HTML folder is: $PHTML"
echo "<INFO> Plugin Template folder is: $PTEMPL"
echo "<INFO> Plugin Data folder is: $PDATA"
echo "<INFO> Plugin Log folder (on RAMDISK!) is: $PLOG"
echo "<INFO> Plugin CONFIG folder is: $PCONFIG"
echo "<INFO> Plugin SBIN folder is: $PSBIN"
echo "<INFO> Plugin BIN folder is: $PBIN"

# --- Chromecast 4 Lox NG ---------------------------------------------------
# Ausfuehrbar machen. Ohne das startet der Daemon beim Systemstart nicht.
chmod 755 "$PBIN"/chromecast4lox_ng-server.py "$PBIN"/cc_discover.py 2>/dev/null
# Die JSON-Dateien sind Daten, keine Programme, und tragen keine Geheimnisse:
# 0644 (Verbesserungsbau 30.09.2026, a2). Bis 1.3.14 kamen sie aus dem Archiv
# mit 0755. Die Konfiguration (Aktionstoken) und die Einmalmeldung der
# Oberflaeche bleiben 0600.
chmod 644 "$PBIN"/cc_themen.json "$PBIN"/cc_vorgaben.json 2>/dev/null

# Pruefen, ob die Python-Abhaengigkeit wirklich da ist. dpkg/apt sollte sie
# eingerichtet haben; schlaegt das fehl, laeuft der Dienst nicht und der
# Benutzer soll das hier lesen und nicht erst im Protokoll suchen.
if python3 -c "import pychromecast" >/dev/null 2>&1; then
    echo "<OK> pychromecast ist vorhanden."
else
    echo "<WARNING> pychromecast fehlt. Bitte nachinstallieren:"
    echo "<WARNING>   sudo apt-get install -y python3-pychromecast"
fi

if python3 -c "import paho.mqtt.client" >/dev/null 2>&1; then
    echo "<OK> paho-mqtt ist vorhanden."
else
    echo "<WARNING> paho-mqtt fehlt. Bitte nachinstallieren:"
    echo "<WARNING>   sudo apt-get install -y python3-paho-mqtt"
fi

# Die Erstanleitung steht seit 1.3.12 am Ende: erst nach der Rueckholung ist
# bekannt, ob schon Geraete eingerichtet sind.

# Exit with Status 0

# ==== NETZ-EINSTELLUNGEN-UPDATE (automatisch eingefuegt, nicht doppeln) ====
# Zurueckspielen aus der Zweitschrift - aber NUR, wenn die Datei des Nutzers
# wirklich verloren ist. Erkannt wird das an dreierlei: sie fehlt, sie ist
# leer, oder sie ist zeichengenau die mitgelieferte Vorgabe (Pruefsumme
# unten). Der letzte Fall ist der eigentliche: genau so sieht die Datei nach
# dem Kopierschritt des Installers aus.
#
# Eine gueltige Konfiguration wird NIE ueberschrieben. Eine Sicherung, die
# echte Einstellungen ersetzt, waere schlimmer als gar keine.
NETZ_BASE="${5:-$LBHOMEDIR}"
NETZ_PDIR="${3:-chromecast-4lox-ng}"
NETZ_CFG="$NETZ_BASE/config/plugins/$NETZ_PDIR"

# ---------- Hat eine Einstellungsdatei Inhalt? ----------
# "Inhalt" heisst: lesbar, mit Abschnitt [CONFIG] und einem Aktionstoken, und
# die Datei endet mit einem Zeilenende. cc_config_write() (cc_lib.php)
# schreibt aktionstoken als LETZTEN Schluessel (Reihenfolge aus
# bin/cc_vorgaben.json) und jede Zeile mit "\n"; eine abgeschnittene Datei
# verliert damit zuerst genau diese Zeile oder ihr Ende. Die Groesse sagt
# darueber nichts: eine abgeschnittene Datei und die mitgelieferte Vorgabe
# sind nicht leer (in WSL gemessen 18.09.2026,
# Pruefung-Chromecast4lox-1.3.11, Faelle c1-c3).
cc_cfg_inhalt() {
    [ -f "$1" ] && [ -r "$1" ] || return 1
    grep -q '^\[CONFIG\]' "$1" 2>/dev/null || return 1
    grep -Eq "^[[:space:]]*aktionstoken[[:space:]]*=[[:space:]]*[\"']?[^[:space:]\"']" "$1" 2>/dev/null || return 1
    [ "$(tail -c 1 "$1" 2>/dev/null | od -An -tx1 | tr -d ' \n')" = "0a" ]
}

netz_zurueck() {
    datei=$1; soll=$2
    ziel="$NETZ_CFG/$datei"
    zweit="$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.$datei"
    [ -f "$zweit" ] || return 0
    verloren=0
    if [ ! -f "$ziel" ] || [ ! -s "$ziel" ]; then
        verloren=1
    else
        ist=$(sha256sum "$ziel" 2>/dev/null | cut -d" " -f1)
        [ -n "$ist" ] && [ "$ist" = "$soll" ] && verloren=1
    fi
    # Der vierte Fall: die Datei ist da, nicht leer, nicht die Vorgabe - und
    # traegt trotzdem kein vollstaendiges Aktionstoken, waehrend die
    # Zweitschrift eines traegt. Bis 1.3.10 blieb eine abgeschnittene Datei
    # dann stehen, weil sie nicht leer war (Fall c6). Geholt wird nur aus
    # einer Zweitschrift MIT Inhalt (Fall c9), und der verdraengte Stand
    # bleibt als .kaputt liegen (0600, er kann das Token tragen).
    if [ "$verloren" = 0 ] && ! cc_cfg_inhalt "$ziel" && cc_cfg_inhalt "$zweit"; then
        verloren=2
        if cp -p "$ziel" "$ziel.kaputt" 2>/dev/null && chmod 0600 "$ziel.kaputt" 2>/dev/null \
           && cmp -s "$ziel" "$ziel.kaputt"; then
            echo "<WARNING> $datei traegt kein vollstaendiges Aktionstoken. Der bisherige"
            echo "<WARNING> Inhalt liegt unter $ziel.kaputt"
        else
            echo "<WARNING> $datei traegt kein vollstaendiges Aktionstoken, laesst sich aber"
            echo "<WARNING> nicht beiseitelegen - es wird nichts zurueckgespielt. Die"
            echo "<WARNING> Zweitschrift liegt unter $zweit"
            return 0
        fi
    fi
    # Geholt wird nur aus einer Zweitschrift MIT Inhalt, auch wenn die Datei
    # fehlt oder die Vorgabe ist (seit 1.3.12, Muster 9 der Nachlese). Bis
    # 1.3.11 kam in diesem Fall jede Zweitschrift zurueck, auch eine ohne
    # Aktionstoken aus einer Vorfassung, und die Meldung sagte
    # "wiederhergestellt" (in WSL gemessen 25.09.2026, Fall I1). Die Wirkung
    # wird mit cmp geprueft, nicht am Rueckgabewert von cp.
    if [ "$verloren" = 1 ] && ! cc_cfg_inhalt "$zweit"; then
        echo "<WARNING> Die Zweitschrift $zweit traegt kein vollstaendiges"
        echo "<WARNING> Aktionstoken und wird nicht zurueckgespielt. Bitte die"
        echo "<WARNING> Einstellungen im Reiter Einstellungen pruefen."
        return 0
    fi
    if [ "$verloren" != "0" ]; then
        if cp -p "$zweit" "$ziel" 2>/dev/null && cmp -s "$zweit" "$ziel"; then
            echo "<OK> $datei aus der Zweitschrift wiederhergestellt."
        else
            echo "<WARNING> $datei liess sich nicht zurueckspielen. Die Sicherung"
            echo "<WARNING> liegt unter $zweit und kann von Hand kopiert werden."
        fi
    fi
}
netz_zurueck "chromecast-4lox-ng.cfg" "72c8fbab6eedc6cecbf0db75449df6086115afac34120ee99edc29f07f9566d7"

# ---------- Erstanleitung nur ohne eingerichtete Geraete (seit 1.3.12) ----------
# Entschieden am 24.09.2026 (AUFTRAG_postinstall-hinweis): die Anleitung zur
# Ersteinrichtung nur, wenn nach dem Zurueckspielen keine eingerichtete
# Konfiguration vorliegt - entschieden nach Inhalt, hier: stehen Geraete in
# den Einstellungen? Bis 1.3.11 riet jede Aktualisierung zur Geraetesuche
# (in WSL gemessen 25.09.2026, Faelle I3/I5).
if grep -Eq '^[[:space:]]*geraete[[:space:]]*=[[:space:]]*[^[:space:]]' "$NETZ_CFG/chromecast-4lox-ng.cfg" 2>/dev/null; then
    echo "<OK> Einstellungen uebernommen - es sind Geraete eingerichtet."
else
    echo "<INFO> Naechster Schritt: Reiter Test -> Chromecasts im Netz suchen,"
    echo "<INFO> dann die gefundenen Namen im Reiter Einstellungen eintragen."
fi

exit 0
