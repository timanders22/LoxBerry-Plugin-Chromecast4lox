#!/bin/sh

# Bash script which is executed in case of an update (if this plugin is already
# installed on the system). This script is executed as very last step (*AFTER*
# postinstall) and can be for example used to save back or convert saved
# userfiles from /tmp back to the system. Use with caution and remember, that
# all systems may be different!
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
ARGV6=$6 # Full path to temporary installation folder


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

# Zurueckspielen - aber nur, wenn im Quellordner ueberhaupt etwas liegt.
#
# 'cp -r ordner/*' bei leerem Ordner laesst die Shell das Sternchen woertlich
# stehen, und cp meldet "cannot stat '.../*': No such file or directory".
# Das bricht das Skript zwar nicht ab (kein set -e), aber im
# Installationsprotokoll steht eine Fehlermeldung, die niemand deuten kann -
# und die den Blick auf echte Fehler verstellt.
#
# Rueckgabewert 0 heisst: nichts zu tun, oder jede Datei steht byteweise am
# Ziel. Davon haengt ab, ob die Sicherung unten weggeraeumt wird.
zurueck() {
    quelle=$1
    ziel=$2
    zweck=$3
    if [ ! -d "$quelle" ]; then
        echo "<INFO> $zweck: nichts gesichert (Ordner fehlt) - uebersprungen."
        return 0
    fi
    if [ -z "$(ls -A "$quelle" 2>/dev/null)" ]; then
        echo "<INFO> $zweck: nichts gesichert (Ordner leer) - uebersprungen."
        return 0
    fi
    mkdir -p "$ziel" 2>/dev/null
    if cp -v -r "$quelle"/. "$ziel"/ && [ -z "$(cc_abweichend "$quelle" "$ziel")" ]; then
        echo "<OK> $zweck zurueckgespielt."
        return 0
    fi
    echo "<WARNING> $zweck liess sich nicht vollstaendig zurueckspielen."
    return 1
}

# Die Sicherung liegt seit dem 10.08.2026 unter data/ statt unter /tmp.
#
# Nebenbei behoben: die alten Pfade trugen ein zusaetzliches /$PDIR am Ende,
# weil 'cp -r quelle/ ziel' das Quellverzeichnis MIT anlegt. Jetzt sichert
# preupgrade mit 'cp -a quelle/. ziel/' den Inhalt - ohne die Zwischenebene.
SICHER="$LBHOMEDIR/data/plugins/$PDIR.upgrade_sicherung"

CC_ZURUECK_OK=1
zurueck "$SICHER/config" "$LBHOMEDIR/config/plugins/$PDIR" "Konfiguration" || CC_ZURUECK_OK=0
# Das Protokoll nicht: es uebersteht das Upgrade an Ort und Stelle (siehe
# preupgrade.sh). Zurueckkopiert ueberschrieb es die neuen Zeilen.
zurueck "$SICHER/files" "$LBHOMEDIR/webfrontend/html/plugins/$PDIR/files" "Sicherungsarchive" || CC_ZURUECK_OK=0

# 1.3.0: zwei Schluessel heissen anders - themenpraefix -> mqtt_topic,
# mqtt -> mqtt_ein. Die zurueckgespielte Konfiguration traegt noch die alten
# Namen; gelesen werden nur die neuen, und ein fehlender Schluessel faellt
# still auf die Vorgabe zurueck. Wirkung ohne diese Uebernahme: ein geaendertes
# Praefix ginge verloren, und ein abgeschaltetes MQTT (mqtt=0) waere nach dem
# Upgrade wieder EIN, weil mqtt_ein fehlt und '1' vorgegeben ist.
#
# Nur setzen, wenn der NEUE Schluessel fehlt - damit ist der Lauf wiederholbar
# und ueberschreibt nie eine bereits umgestellte Konfiguration. Das Muster ist
# an ^ verankert: ohne den Anker traefe 'mqtt=' auch 'mqtt_topic='.
#
# Diese Uebernahme darf weg, sobald keine Anlage mehr von 1.2.x kommt.
CCCFG="$LBHOMEDIR/config/plugins/$PDIR/chromecast-4lox-ng.cfg"
if [ -f "$CCCFG" ]; then
    for paar in "themenpraefix mqtt_topic" "mqtt mqtt_ein"; do
        alt=${paar%% *}; neu=${paar##* }
        if ! grep -q "^${neu}=" "$CCCFG" && grep -q "^${alt}=" "$CCCFG"; then
            sed -i "s/^${alt}=/${neu}=/" "$CCCFG" \
                && echo "<OK> Konfiguration: ${alt} heisst jetzt ${neu}."
        fi
    done
fi

# Eigentuemer nur nachsehen und sagen. Dieses Skript laeuft als Benutzer
# loxberry, NICHT als root: plugininstall.pl ruft es mit
# "sudo -n -u loxberry" auf (Regeln/06). Bis 1.3.9 stand hier ein
# "chown -R loxberry:loxberry" mit dem Kommentar "laeuft als root" - als
# loxberry aendert das keinen Eigentuemer, und die Meldung "Eigentuemer
# auf loxberry gesetzt" kam trotzdem.
CC_WER_UID=$(id -u 2>/dev/null)
CC_FREMD=$(find "$LBHOMEDIR/config/plugins/$PDIR" "$LBHOMEDIR/log/plugins/$PDIR" \
                "$LBHOMEDIR/data/plugins/$PDIR" "$LBHOMEDIR/webfrontend/html/plugins/$PDIR/files" \
                ! -uid "$CC_WER_UID" 2>/dev/null | head -3)
if [ -n "$CC_FREMD" ]; then
    echo "<INFO> Diese Dateien gehoeren nicht $(id -un 2>/dev/null):"
    echo "$CC_FREMD" | sed 's/^/<INFO>   /'
    echo "<INFO> Der Dienst und die Oberflaeche laufen als loxberry und koennten sie nicht schreiben."
fi

# Weggeraeumt wird die Sicherung erst, wenn beides nachweislich zurueck ist.
# Bis 1.3.10 fiel sie ohne Bedingung - auch wenn das Zurueckspielen
# gescheitert war (in WSL gemessen 18.09.2026, Fall p1: volle Platte beim
# Zurueckspielen, danach war die Sicherung weg und die Einstellungen die
# Vorgabe). Bleibt sie liegen, haelt preupgrade.sh sie beim naechsten Update
# fest (die Vorgabe traegt kein Aktionstoken), und postupgrade.sh spielt sie
# dann zurueck.
if [ "$CC_ZURUECK_OK" = 1 ]; then
    echo "<INFO> Remove backup folder"
    rm -rf "$SICHER"
else
    echo "<WARNING> Die Sicherung bleibt liegen, weil nicht alles zurueckgespielt wurde:"
    echo "<WARNING>   $SICHER"
    echo "<WARNING> Die Einstellungen lassen sich von dort von Hand zurueckkopieren;"
    echo "<WARNING> ein erneutes Update spielt sie ebenfalls zurueck."
fi

# --- Chromecast 4 Lox NG ---------------------------------------------------
# Ausfuehrbar machen. Ohne das startet der Daemon beim Systemstart nicht.
chmod 755 "$PBIN"/chromecast4lox_ng-server.py "$PBIN"/cc_discover.py 2>/dev/null

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

echo "<INFO> Naechster Schritt: Reiter Test -> Chromecasts im Netz suchen,"
echo "<INFO> dann die gefundenen Namen im Reiter Einstellungen eintragen."

# Exit with Status 0
exit 0
