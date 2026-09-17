#!/bin/sh

# Bash script which is executed in case of an update (if this plugin is already
# installed on the system). This script is executed as very first step (*BEFORE*
# preinstall.sh) and can be used e.g. to save existing configfiles to /tmp 
# during installation. Use with caution and remember, that all systems may be
# different!
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
# Rueckfall, falls sudo die Umgebung ausgeraeumt hat (env_reset).
# Das fuenfte Argument ist das Wurzelverzeichnis und traegt immer.
LBHOMEDIR="${LBHOMEDIR:-$5}"
LBPCONFIG="${LBPCONFIG:-$5/config/plugins}"
LBPLOG="${LBPLOG:-$5/log/plugins}"
LBPBIN="${LBPBIN:-$5/bin/plugins}"
LBPHTML="${LBPHTML:-$5/webfrontend/html/plugins}"
LBPTEMPL="${LBPTEMPL:-$5/templates/plugins}"
LBPSBIN="${LBPSBIN:-$5/sbin/plugins}"
LBPCGI="${LBPCGI:-$5/webfrontend/htmlauth/plugins}"
# sudo -n -u loxberry setzt die Umgebung zurueck - ohne diesen
# Rueckfall zeigte $LBPDATA ins Nichts und der Pfad auf /<ordner>.
LBPDATA="${LBPDATA:-$5/data/plugins}"
PVERSION=$4   # Forth argument is Plugin version
#LBHOMEDIR=$5 # Comes from /etc/environment now. Fifth argument is
              # Base folder of LoxBerry

# Combine them with /etc/environment
PCGI=$LBPCGI/$PDIR
PHTML=$LBPHTML/$PDIR
PTEMPL=$LBPTEMPL/$PDIR
PDATA=$LBPDATA/$PDIR
PLOG=$LBPLOG/$PDIR # Note! This is stored on a Ramdisk now!
PCONFIG=$LBPCONFIG/$PDIR
PSBIN=$LBPSBIN/$PDIR
PBIN=$LBPBIN/$PDIR

# Wurzel und Ordner fuer die Bloecke unten - aus den Argumenten des
# Installers, die Umgebung nur als Rueckfall.
CC_BASE="${5:-$LBHOMEDIR}"
CC_PFOLDER="${3:-chromecast-4lox-ng}"

# ---------- Prozesse des Dienstes finden und beenden ----------
# Ein Treffer hat GENAU zwei Argumente: einen python-Interpreter und den
# vollen Dienstpfad DIESES Plugins ($1). Dazu muss der Prozess dem Benutzer
# mit der Nummer $2 gehoeren. Ein Editor oder "tail" auf die Datei, eine
# Suche mit dem Namen im Muster, der Selbsttest mit "--themen" und der
# Dienst eines anderen Plugin-Ordners treffen damit nicht. "pgrep -f" traf
# jede Befehlszeile, in der die Zeichenkette irgendwo vorkam.
cc_dienste_finden() {
    for cc_d in /proc/[0-9]*; do
        grep -qaF "chromecast4lox_ng-server.py" "$cc_d/cmdline" 2>/dev/null || continue
        [ "$(stat -c %u "$cc_d" 2>/dev/null)" = "$2" ] || continue
        cc_n=0
        cc_treffer=0
        while IFS= read -r cc_arg; do
            cc_n=$((cc_n + 1))
            if [ "$cc_n" = 1 ]; then
                case "${cc_arg##*/}" in
                    python|python3|python3.*) ;;
                    *) break ;;
                esac
            elif [ "$cc_n" = 2 ] && [ "$cc_arg" = "$1" ]; then
                cc_treffer=1
            fi
        done <<CC_ARGUMENTE
$(tr '\0' '\n' < "$cc_d/cmdline" 2>/dev/null)
CC_ARGUMENTE
        if [ "$cc_treffer" = 1 ] && [ "$cc_n" = 2 ]; then
            echo "${cc_d#/proc/}"
        fi
    done
}

# Beendet alle Treffer mit Geduld: TERM, bis zu zehn Sekunden warten, dann
# KILL fuer den Rest. Danach wird NEU gesucht, nicht angenommen.
# Ergebnis: CC_GEFUNDEN (Zahl der Treffer) und CC_UEBRIG (leer, wenn
# hinterher keiner mehr laeuft, sonst die Nummern).
cc_dienste_beenden() {
    CC_GEFUNDEN=0
    CC_UEBRIG=""
    cc_pids=$(cc_dienste_finden "$1" "$2")
    for cc_p in $cc_pids; do
        CC_GEFUNDEN=$((CC_GEFUNDEN + 1))
    done
    [ -n "$cc_pids" ] || return 0
    kill $cc_pids 2>/dev/null
    cc_i=0
    while [ $cc_i -lt 20 ]; do
        cc_lebt=0
        for cc_p in $cc_pids; do
            kill -0 "$cc_p" 2>/dev/null && cc_lebt=1
        done
        [ "$cc_lebt" = 0 ] && break
        sleep 0.5
        cc_i=$((cc_i + 1))
    done
    cc_rest=$(cc_dienste_finden "$1" "$2")
    if [ -n "$cc_rest" ]; then
        kill -9 $cc_rest 2>/dev/null
        sleep 1
        cc_rest=$(cc_dienste_finden "$1" "$2")
    fi
    for cc_p in $cc_rest; do
        CC_UEBRIG="$CC_UEBRIG $cc_p"
    done
}

# ---------- Marke "Aktualisierung laeuft" ----------
# Als Erstes, vor jedem anderen Schritt. Der Installer legt die Cron-Datei
# neu an, lange bevor postroot.sh den Dienst startet (am Geraet an der
# Einspeisebremse gemessen: fast eine Minute, Regeln/06). Lief
# cron.05min in dieser Luecke, fand er keine PID-Datei - purge_installation
# loescht sie mit data/plugins/<ordner>/ - und startete den Dienst mit der
# mitgelieferten Vorgabe-Konfiguration (in WSL nachgestellt am 17.09.2026).
# cron.05min startet nicht, solange die Marke juenger als eine Stunde ist;
# postroot.sh entfernt sie. Sie liegt NEBEN dem Datenordner, sonst
# loeschte purge_installation sie mit.
CC_MARKE="$CC_BASE/data/plugins/$CC_PFOLDER.upgrade_laeuft"
mkdir -p "$CC_BASE/data/plugins" 2>/dev/null
date +%s > "$CC_MARKE" 2>/dev/null
if [ -s "$CC_MARKE" ]; then
    echo "<OK> Dienststart durch den Waechter bis zum Ende der Installation gesperrt."
else
    echo "<WARNING> Die Marke $CC_MARKE liess sich nicht anlegen - der Waechter"
    echo "<WARNING> kann den Dienst waehrend der Installation starten."
fi

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

# ---------- Den laufenden Dienst anhalten ----------
# Bis 1.3.9 lief der alte Dienst durch das ganze Upgrade weiter. Seine
# PID-Datei loescht purge_installation mit dem Datenordner; danach sah ihn
# niemand mehr: der Waechter startete einen zweiten, postroot.sh beendete
# nur einen, und der alte lief bis zum Neustart des Rechners weiter (in WSL
# nachgestellt am 17.09.2026: zwei Dienste auf demselben UDP-Port). Dieses
# Skript laeuft als loxberry und beendet nur Prozesse von loxberry.
CC_UID=$(id -u loxberry 2>/dev/null)
if [ -z "$CC_UID" ]; then
    echo "<WARNING> Benutzer loxberry nicht gefunden - der Dienst wird nicht angehalten."
else
    cc_dienste_beenden "$CC_BASE/bin/plugins/$CC_PFOLDER/chromecast4lox_ng-server.py" "$CC_UID"
    if [ -n "$CC_UEBRIG" ]; then
        echo "<WARNING> Der Dienst laesst sich nicht anhalten (PID$CC_UEBRIG)."
    elif [ "$CC_GEFUNDEN" != 0 ]; then
        echo "<OK> Dienst angehalten ($CC_GEFUNDEN Prozess(e))."
    else
        echo "<INFO> Es lief kein Dienst."
    fi
fi
rm -f "$CC_BASE/data/plugins/$CC_PFOLDER/dienst.pid" 2>/dev/null

# Der Sicherungsordner liegt unter data/, NICHT unter /tmp.
#
# /tmp ist auf dem LoxBerry eine Ramdisk: bricht die Installation ab oder
# startet der Rechner dazwischen neu, ist die Sicherung weg - und mit ihr die
# Sicherungsarchive, die hier ausdruecklich mitgerettet werden. Ausserdem ist
# /tmp fuer jeden lesbar. Geaendert am 10.08.2026.
# Die Sicherung liegt NEBEN dem Ordner, nicht darin. Gemessen an
# sbin/plugininstall.pl (Zweig master, 23.08.2026): der Installer ruft
# &purge_installation nicht nur beim Deinstallieren, sondern auch im
# Upgrade-Zweig (:886), und deren Rumpf loescht ohne jede Bedingung
# (:1629 ff.) config/plugins/<x>/, bin/plugins/<x>/, data/plugins/<x>/,
# templates/plugins/<x>/ und beide webfrontend/-Ordner. Eine Sicherung IN
# data/plugins/<x>/ wird also von genau dem Schritt vernichtet, den sie
# ueberdauern soll. Der Punkt im Namen ist der ganze Unterschied:
# "rm -rf .../<x>/" trifft den Nachbarn "<x>.upgrade_sicherung" nicht.
SICHER="$LBHOMEDIR/data/plugins/$PDIR.upgrade_sicherung"

echo "<INFO> Creating backup folder for upgrading $SICHER"
rm -rf "$SICHER" 2>/dev/null
mkdir -p "$SICHER/config" "$SICHER/files"
chmod 0700 "$SICHER" 2>/dev/null

echo "<INFO> Backing up existing config files"
cp -a "$LBHOMEDIR/config/plugins/$PDIR/." "$SICHER/config/" 2>/dev/null

# Das Protokoll wird nicht gesichert: purge_installation loescht
# log/plugins/<ordner>/ beim Upgrade nicht, nur beim Deinstallieren
# (Regeln/06). Bis 1.3.9 spielte postupgrade.sh die Sicherung zurueck und
# ueberschrieb damit jede Zeile, die waehrend der Installation dazukam (in
# WSL nachgestellt am 17.09.2026).

echo "<INFO> Backing up existing backup archives"
cp -a "$LBHOMEDIR/webfrontend/html/plugins/$PDIR/files/." "$SICHER/files/" 2>/dev/null

# Exit with Status 0

# ==== NETZ-EINSTELLUNGEN-UPDATE (automatisch eingefuegt, nicht doppeln) ====
# Zweitschrift NEBEN den Konfigurationsordner, zusaetzlich zur bisherigen
# Sicherung. Grund: der Installer kopiert config/* aus dem Archiv ueber
# config/plugins/<ordner> (plugininstall.pl Zeile 899, cp -r ohne -n) und
# ueberschreibt dabei die Datei des Nutzers. Bisher haing die Rettung allein
# an postupgrade.sh. Laeuft das aus irgendeinem Grund nicht durch, greift
# jetzt postinstall.sh auf diese Zweitschrift zu - sie liegt ausserhalb des
# ueberschriebenen Ordners und wird vom Installer nicht angefasst.
NETZ_BASE="${5:-$LBHOMEDIR}"
NETZ_PDIR="${3:-chromecast-4lox-ng}"
NETZ_CFG="$NETZ_BASE/config/plugins/$NETZ_PDIR"
if [ -s "$NETZ_CFG/chromecast-4lox-ng.cfg" ]; then
    cp -p "$NETZ_CFG/chromecast-4lox-ng.cfg" "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.chromecast-4lox-ng.cfg" 2>/dev/null \
        && chmod 0600 "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.chromecast-4lox-ng.cfg" 2>/dev/null
fi
echo "<INFO> Zweitschrift der Einstellungen angelegt."

exit 0
