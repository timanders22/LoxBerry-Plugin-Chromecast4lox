#!/bin/bash
# Chromecast 4 Lox NG - preinstall
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Neu in 1.3.13 (I4, Entscheidung 1 vom 29.09.2026), Bauform AudiConnect
# 0.9.22. Der Installer ruft dieses Skript bei JEDEM Einbau auf, nach dem
# Aufraeumen der alten Fassung und VOR dem Kopieren von Konfiguration,
# Cron-Datei und Oberflaeche (sbin/plugininstall.pl: preupgrade :846,
# purge :874, preinstall :877, Cron :990 - Geraet/2026-09-05/08_plugininstall.pl).
#
# Eine Aktualisierung erkennt es allein an der Marke
# data/plugins/<ordner>.upgrade_laeuft, die preupgrade.sh als Erstes anlegt
# (kein Altersvergleich). Dann tut es nichts: Zweitschrift und Sicherung
# braucht postinstall.sh bzw. postupgrade.sh.
#
# Ohne Marke ist es eine NEUINSTALLATION. Eine liegengebliebene Zweitschrift
# (config/plugins/<ordner>.backup.chromecast-4lox-ng.cfg) und eine alte
# Sicherung (data/plugins/<ordner>.upgrade_sicherung) einer frueheren
# Installation gehen nach <name>.alt, gemeldet mit genau einer <WARNING>.
# Bis 1.3.12 spielte postinstall.sh die Zweitschrift ungefragt zurueck - das
# Aktionstoken und die Geraete der frueheren Installation (in WSL gemessen
# 30.09.2026, Installer-Befund 4: "aus der Zweitschrift wiederhergestellt",
# aktionstoken=TOKEN_ALT_ZWEITSCHRIFT). Die Deinstallation raeumt die .alt ab.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-chromecast-4lox-ng}"
BASE="${ARGV5:-$LBHOMEDIR}"
# Wurzelsuche wie in den uebrigen Hakenskripten: ohne config/plugins,
# data/plugins UND config/system/general.json wird nichts angefasst.
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ] \
   || [ ! -f "$BASE/config/system/general.json" ]; then
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt ('$BASE') - nichts beiseitegelegt."
    exit 0
fi
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts beiseitegelegt."; exit 0 ;;
esac
[ -f "$BASE/data/plugins/$PFOLDER.upgrade_laeuft" ] && exit 0

BEISEITE=""
FEST=""
for ZIEL in "$BASE/config/plugins/$PFOLDER.backup.chromecast-4lox-ng.cfg" \
            "$BASE/data/plugins/$PFOLDER.upgrade_sicherung"; do
    if [ -e "$ZIEL" ] || [ -L "$ZIEL" ]; then
        rm -rf "${ZIEL:?}.alt" 2>/dev/null
        if mv -f "$ZIEL" "$ZIEL.alt" 2>/dev/null; then
            BEISEITE="$BEISEITE $ZIEL.alt"
        else
            FEST="$FEST $ZIEL"
        fi
    fi
done
A="$BASE/config/plugins/$PFOLDER.backup.chromecast-4lox-ng.cfg.alt"
[ -f "$A" ] && [ ! -L "$A" ] && chmod 600 "$A" 2>/dev/null
if [ -n "$BEISEITE" ] || [ -n "$FEST" ]; then
    T="<WARNING> Neuinstallation: Einstellungen und Aktionstoken einer frueheren Installation werden NICHT eingespielt."
    [ -n "$BEISEITE" ] && T="$T Beiseitegelegt:$BEISEITE (die Deinstallation raeumt sie ab)."
    [ -n "$FEST" ] && T="$T Nicht zu verschieben, bitte von Hand entfernen:$FEST"
    echo "$T"
fi
exit 0
