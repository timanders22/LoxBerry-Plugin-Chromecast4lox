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
PFOLDER="${ARGV3:-chromecast-4lox-ng}"
# Fremdbefund C1 (1.3.24): die Wurzel wie in postinstall.sh - ${5:-}, dann LBHOMEDIR, dann vom eigenen Ablageort
# aufwaerts (cc_wurzel_suchen, wortgleich aus postinstall.sh). Bis 1.3.23 fehlte die Suche hier: ohne $5 und
# LBHOMEDIR legte preinstall.sh nichts beiseite, postinstall.sh fand die Wurzel aber doch (Pruefbericht C1; nur bei
# einem Aufruf von Hand - der Installer uebergibt $5 an beide).
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
BASE="${5:-}"
[ -n "$BASE" ] || BASE="${LBHOMEDIR:-}"
[ -n "$BASE" ] || BASE=$(cc_wurzel_suchen) || BASE=""
# Wurzelsuche wie in den uebrigen Hakenskripten: ohne config/plugins,
# data/plugins UND config/system/general.json wird nichts angefasst.
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ] \
   || [ ! -f "$BASE/config/system/general.json" ]; then
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt ('$BASE') - nichts beiseitegelegt."
    exit 0
fi
# C1 (1.3.24): auch "." und ".." abweisen (bis 1.3.23 kam "." durch - harmlos wegen der Endung, aber uneinheitlich).
case "$PFOLDER" in
    ''|.|..|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts beiseitegelegt."; exit 0 ;;
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
