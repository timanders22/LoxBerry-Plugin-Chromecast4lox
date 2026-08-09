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


# Zurueckspielen - aber nur, wenn im Quellordner ueberhaupt etwas liegt.
#
# 'cp -r ordner/*' bei leerem Ordner laesst die Shell das Sternchen woertlich
# stehen, und cp meldet "cannot stat '.../*': No such file or directory".
# Das bricht das Skript zwar nicht ab (kein set -e), aber im
# Installationsprotokoll steht eine Fehlermeldung, die niemand deuten kann -
# und die den Blick auf echte Fehler verstellt.
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
    cp -v -r "$quelle"/. "$ziel"/ && echo "<OK> $zweck zurueckgespielt."
}

zurueck "/tmp/uploads/${PTEMPDIR}_upgrade/config/$PDIR" "$LBHOMEDIR/config/plugins/$PDIR" "Konfiguration"
zurueck "/tmp/uploads/${PTEMPDIR}_upgrade/log/$PDIR" "$LBHOMEDIR/log/plugins/$PDIR" "Protokoll"
zurueck "/tmp/uploads/${PTEMPDIR}_upgrade/files/$PDIR" "$LBHOMEDIR/webfrontend/html/plugins/$PDIR/files" "Sicherungsarchive"

# Eigentuemer richtigstellen. Dieses Skript laeuft als root; die
# zurueckgespielten Dateien gehoerten sonst root, und die Oberflaeche laeuft
# als loxberry - sie koennte die Konfiguration danach nicht mehr schreiben.
if id loxberry >/dev/null 2>&1; then
    for d in "$LBHOMEDIR/config/plugins/$PDIR" "$LBHOMEDIR/log/plugins/$PDIR" \
             "$LBHOMEDIR/data/plugins/$PDIR" "$LBHOMEDIR/webfrontend/html/plugins/$PDIR/files"; do
        [ -d "$d" ] && chown -R loxberry:loxberry "$d" 2>/dev/null
    done
    echo "<OK> Eigentuemer auf loxberry gesetzt."
fi

echo "<INFO> Remove temporary folders"
rm -rf /tmp/uploads/${PTEMPDIR}_upgrade

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
