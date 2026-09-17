#!/bin/bash

# Bashscript which is executed by bash *AFTER* complete installation is done
# (*AFTER* postinstall and *AFTER* postupgrade). Use with caution and remember,
# that all systems may be different!
#
# Exit code must be 0 if executed successfull. 
# Exit code 1 gives a warning but continues installation.
# Exit code 2 cancels installation.
#
# !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
# Will be executed as user "root".
# !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
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
# Installers, die Umgebung nur als Rueckfall. postroot.sh ist das LETZTE
# Hakenskript, nach postinstall und postupgrade (sbin/plugininstall.pl am
# Geraet, Regeln/06).
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

#logfile="<Log-Ordner des Plugins>/$PSHNAME.log"
#echo Postroot started >> $logfile
#date >> $logfile

echo "<INFO> Killing any old instances..."
# ALLE Prozesse dieses Dienstes, nicht einer. Bis 1.3.9 wurde hier genau
# einer beendet: der aus der PID-Datei, ohne sie der aelteste Treffer von
# "pgrep -o -f" im GANZEN System. Lief der alte Dienst ohne PID-Datei
# weiter und hatte der Waechter in der Upgrade-Luecke einen zweiten
# gestartet, blieb einer uebrig - auch nach dem naechsten Waechterlauf und
# nach uninstall (in WSL nachgestellt am 17.09.2026). Dieses Skript laeuft
# als root, der Dienst als loxberry: nur Prozesse von loxberry.
CC_STARTEN=1
CC_UID=$(id -u loxberry 2>/dev/null)
if [ -z "$CC_UID" ]; then
    echo "<WARNING> Benutzer loxberry nicht gefunden - alte Dienste wurden nicht gesucht."
else
    cc_dienste_beenden "$CC_BASE/bin/plugins/$CC_PFOLDER/chromecast4lox_ng-server.py" "$CC_UID"
    if [ -n "$CC_UEBRIG" ]; then
        echo "<WARNING> Ein alter Dienst laesst sich nicht beenden (PID$CC_UEBRIG)."
        echo "<WARNING> Es wird kein zweiter gestartet. Bitte den LoxBerry neu starten."
        CC_STARTEN=0
    elif [ "$CC_GEFUNDEN" != 0 ]; then
        echo "<INFO> $CC_GEFUNDEN laufende(r) Dienst(e) beendet."
    fi
fi
rm -f "$CC_BASE/data/plugins/$CC_PFOLDER/dienst.pid"
# Ein bewusst angehaltener Dienst bleibt angehalten. "Dienst anhalten" im
# Reiter Test setzt enabled=0; bis 1.3.10 startete postroot.sh trotzdem, und
# nach jedem Update lief das Plugin wieder (in WSL gemessen 17.09.2026,
# Fall q5). daemon/daemon prueft dasselbe - hier steht es zusaetzlich, damit
# der Grund im Installationsprotokoll steht und nicht nur im Systemlogger.
CC_CFG="$CC_BASE/config/plugins/$CC_PFOLDER/chromecast-4lox-ng.cfg"
if [ "$CC_STARTEN" = 1 ] && [ -f "$CC_CFG" ] && grep -q '^enabled=0' "$CC_CFG"; then
    echo "<INFO> Der Dienst bleibt aus: im Reiter Test steht \"Dienst laufen lassen\" auf aus."
    CC_STARTEN=0
fi
if [ "$CC_STARTEN" = 1 ]; then
    echo "<INFO> Starting server"
    # CC_START_TROTZ_MARKE=1: daemon/daemon startet seit 1.3.10 nicht, solange
    # die Marke aus preupgrade.sh gilt. Hier ist sie die eigene - postroot.sh
    # ist der letzte Schritt der Installation und raeumt sie gleich darunter
    # weg. Ohne diese Ausnahme bliebe der Dienst bis zum naechsten
    # Waechterlauf aus, der die Marke ebenfalls sieht: bis zu einer Stunde.
    CC_START_TROTZ_MARKE=1 bash REPLACELBHOMEDIR/system/daemons/plugins/$PSHNAME
fi

# Die Marke aus preupgrade.sh erst NACH dem Start entfernen.
#
# Seit 1.3.10 fragt auch daemon/daemon nach der Marke; damit waere die
# umgekehrte Reihenfolge - Marke weg, dann starten - moeglich geworden. Sie
# ist trotzdem falsch, und das ist gemessen: zwischen dem Entfernen und dem
# Augenblick, in dem der neue Dienst dasteht, sieht ein Waechterlauf weder
# die Marke noch einen laufenden Dienst - und startet einen eigenen.
#
# In WSL nachgestellt (17.09.2026, Fall q5; 400 Waechterlaeufe im Abstand von
# 0,02 s, waehrend postinstall, postupgrade und postroot arbeiten):
#   diese Reihenfolge      5 von 5 Laeufen mit genau EINEM Dienst, 0 Nachstarts
#   umgekehrt (Variante QV) 5 von 5 Laeufen mit VIER Diensten, je 3 Nachstarts
# Die Ausnahme CC_START_TROTZ_MARKE oben schliesst das Fenster ganz: die
# Marke liegt waehrend des ganzen Starts und weist jeden anderen Starter ab.
# Am Geraet liegen fuenf Minuten zwischen zwei Waechterlaeufen - das Fenster
# waere also selten getroffen worden, aber "selten" ist keine Bauweise.
#
# Entfernt wird immer, auch wenn der Start unterblieb - sonst sperrte sie
# den Waechter eine Stunde lang.
rm -f "$CC_BASE/data/plugins/$CC_PFOLDER.upgrade_laeuft"

# Exit with Status 0
exit 0
