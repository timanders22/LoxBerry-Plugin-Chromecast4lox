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

# ---------- Steht alles in der Kopie? ----------
# Nennt jede Datei aus $1, die in $2 fehlt oder byteweise abweicht; leer
# heisst: alles da und gleich. Ein fehlendes $1 hat nichts, was fehlen
# koennte. Die Wirkung wird geprueft, nicht der Rueckgabewert allein
# (CLAUDE.md, Abschnitt 2; Bauart GardenaSmartSystem 1.2.10 preupgrade.sh).
cc_abweichend() {
    [ -d "$1" ] || return 0
    ( cd "$1" && find . -type f | while IFS= read -r cc_f; do
          cmp -s "$cc_f" "$2/$cc_f" || printf '%s ' "${cc_f#./}"
      done ) 2>/dev/null || echo "(nicht lesbar: $1)"
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

# Die neue Sicherung entsteht NEBEN der alten und ersetzt sie erst, wenn sie
# vollstaendig steht. Bis 1.3.10 stand hier "rm -rf $SICHER" VOR dem
# Kopieren: brach ein Update nach purge_installation ab und wurde erneut
# angestossen, gab es nichts mehr zu sichern, und die einzige Abschrift war
# schon geloescht (Bestand-2026-09-18/klasse-D: 11 von 12 Dateien; in WSL
# nachgemessen 18.09.2026, Pruefung-Chromecast4lox-1.3.11, Fall d1: 14 von
# 14, Fall d2: Abbruch beim Schreiben). Reihenfolge wie GardenaSmartSystem
# 1.2.10: in $SICHER.neu bauen -> jede Datei byteweise pruefen -> die alte
# nach $SICHER.alt -> die neue an ihren Platz -> die alte wegwerfen.
# "mv -T" schiebt nie IN ein vorhandenes Verzeichnis hinein.
CC_NEU="$SICHER.neu"
CC_QCFG="$LBHOMEDIR/config/plugins/$PDIR"
CC_QFILES="$LBHOMEDIR/webfrontend/html/plugins/$PDIR/files"

echo "<INFO> Creating backup folder for upgrading $SICHER"
rm -rf "$CC_NEU" 2>/dev/null
mkdir -p "$CC_NEU/config" "$CC_NEU/files" 2>/dev/null
chmod 0700 "$CC_NEU" 2>/dev/null
CC_OK=1
CC_GRUND=""

echo "<INFO> Backing up existing config files"
# Ohne Konfigordner gibt es nichts, was eine vorhandene Sicherung ersetzen
# duerfte: so sieht der zweite Versuch nach einem abgebrochenen Update aus
# (purge_installation hat den Ordner schon entfernt, Fall d1).
if [ -d "$CC_QCFG" ]; then
    cp -a "$CC_QCFG/." "$CC_NEU/config/" 2>/dev/null \
        || { CC_OK=0; CC_GRUND="$CC_GRUND Konfiguration: cp Rueckgabewert $?;"; }
else
    CC_OK=0
    CC_GRUND="$CC_GRUND keine Konfiguration unter $CC_QCFG;"
fi
CC_ABW=$(cc_abweichend "$CC_QCFG" "$CC_NEU/config")
[ -z "$CC_ABW" ] || { CC_OK=0; CC_GRUND="$CC_GRUND nicht in der Sicherung: $CC_ABW;"; }

# Das Protokoll wird nicht gesichert: purge_installation loescht
# log/plugins/<ordner>/ beim Upgrade nicht, nur beim Deinstallieren
# (Regeln/06). Bis 1.3.9 spielte postupgrade.sh die Sicherung zurueck und
# ueberschrieb damit jede Zeile, die waehrend der Installation dazukam (in
# WSL nachgestellt am 17.09.2026).

echo "<INFO> Backing up existing backup archives"
if [ -d "$CC_QFILES" ]; then
    cp -a "$CC_QFILES/." "$CC_NEU/files/" 2>/dev/null \
        || { CC_OK=0; CC_GRUND="$CC_GRUND Sicherungsarchive: cp Rueckgabewert $?;"; }
fi
CC_ABW=$(cc_abweichend "$CC_QFILES" "$CC_NEU/files")
[ -z "$CC_ABW" ] || { CC_OK=0; CC_GRUND="$CC_GRUND nicht in der Sicherung: $CC_ABW;"; }

# Eine Sicherung MIT Aktionstoken wird nie durch eine OHNE ersetzt. Nach
# purge_installation kopiert der Installer die mitgelieferte Vorgabe nach
# config/plugins/<ordner>/; bricht er danach ab, ist ihre Kopie vollstaendig
# und heil - und traegt nichts mehr vom Anwender (Fall d3).
if [ "$CC_OK" = 1 ] && ! cc_cfg_inhalt "$CC_NEU/config/chromecast-4lox-ng.cfg" \
   && cc_cfg_inhalt "$SICHER/config/chromecast-4lox-ng.cfg"; then
    CC_OK=0
    CC_GRUND="$CC_GRUND die Einstellungen tragen kein vollstaendiges Aktionstoken, die vorhandene Sicherung schon;"
fi

if [ "$CC_OK" = 1 ]; then
    rm -rf "$SICHER.alt" 2>/dev/null
    if [ -e "$SICHER" ] && ! mv -T "$SICHER" "$SICHER.alt" 2>/dev/null; then
        rm -rf "$CC_NEU" 2>/dev/null
        echo "<WARNING> Die bisherige Sicherung liess sich nicht beiseitelegen; sie bleibt"
        echo "<WARNING> unangetastet: $SICHER"
    elif mv -T "$CC_NEU" "$SICHER" 2>/dev/null; then
        rm -rf "$SICHER.alt" 2>/dev/null
        echo "<OK> Einstellungen und Sicherungsarchive gesichert: $SICHER"
    else
        [ -e "$SICHER.alt" ] && mv -T "$SICHER.alt" "$SICHER" 2>/dev/null
        rm -rf "$CC_NEU" 2>/dev/null
        echo "<WARNING> Die neue Sicherung liess sich nicht an ihren Platz bringen."
        echo "<WARNING> Platz und Rechte in $LBHOMEDIR/data/plugins pruefen."
    fi
else
    rm -rf "$CC_NEU" 2>/dev/null
    echo "<WARNING> Die Einstellungen wurden NICHT neu gesichert:$CC_GRUND"
    if [ -d "$SICHER" ]; then
        echo "<WARNING> Die bisherige Sicherung bleibt unangetastet: $SICHER"
    fi
fi

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
NETZ_ZWEIT="$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.chromecast-4lox-ng.cfg"
# Nach INHALT entscheiden, nicht nach Groesse (cc_cfg_inhalt oben). Bis
# 1.3.10 stand hier "[ -s ]": eine abgeschnittene Datei und die
# mitgelieferte Vorgabe verdraengten die heile Zweitschrift (Faelle c1-c3),
# und die Erfolgsmeldung kam auch ohne Kopie.
if cc_cfg_inhalt "$NETZ_CFG/chromecast-4lox-ng.cfg"; then
    # Erst eine Nebendatei, dann umbenennen: "cp -p" auf die Zweitschrift
    # kappte sie, bevor die neue stand (Fall c5: nach einem Abbruch beim
    # Schreiben blieb eine Zweitschrift mit 0 Byte).
    if cp -p "$NETZ_CFG/chromecast-4lox-ng.cfg" "$NETZ_ZWEIT.neu" 2>/dev/null \
       && chmod 0600 "$NETZ_ZWEIT.neu" 2>/dev/null \
       && cmp -s "$NETZ_CFG/chromecast-4lox-ng.cfg" "$NETZ_ZWEIT.neu" \
       && mv -f "$NETZ_ZWEIT.neu" "$NETZ_ZWEIT" 2>/dev/null; then
        echo "<INFO> Zweitschrift der Einstellungen angelegt."
    else
        rm -f "$NETZ_ZWEIT.neu" 2>/dev/null
        echo "<WARNING> Die Zweitschrift der Einstellungen liess sich nicht anlegen;"
        echo "<WARNING> eine vorhandene bleibt unveraendert: $NETZ_ZWEIT"
    fi
elif [ -f "$NETZ_ZWEIT" ]; then
    echo "<WARNING> Die Einstellungen fehlen oder tragen kein vollstaendiges Aktionstoken -"
    echo "<WARNING> die vorhandene Zweitschrift bleibt unveraendert: $NETZ_ZWEIT"
else
    echo "<INFO> Keine Einstellungen mit Aktionstoken vorhanden - keine Zweitschrift angelegt."
fi

exit 0
