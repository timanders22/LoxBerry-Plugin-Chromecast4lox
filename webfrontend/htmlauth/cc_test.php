<?php
/**
 * Chromecast 4 Lox NG - Aktionen des Reiters Test
 *
 * Jede Funktion liefert array(Ueberschrift, Text). Der Text wird von der
 * Oberflaeche maskiert ausgegeben, hier also bewusst als Klartext erzeugt.
 */

require_once __DIR__ . '/cc_lib.php';

function cc_sh($cmd)
{
    $out = array();
    @exec($cmd . ' 2>&1', $out);
    return implode("\n", $out);
}

function cc_test_ausfuehren($was, $geraet = '')
{
    $p = cc_paths();
    $cfg = cc_config_read();
    $geraete = cc_geraete($cfg);

    switch ($was) {

        case 'status':
            $pid = cc_dienst_pid();
            $t = "Dienst:             " . ($pid ? "laeuft (PID $pid)" : 'laeuft nicht') . "\n";
            $t .= "Konfigurierte Geraete: " . (count($geraete) ? implode(', ', $geraete) : 'keine') . "\n";
            $t .= "MQTT:               " . (cc_cfg($cfg, 'mqtt', '1') === '1' ? 'ein' : 'aus') . "\n";
            $t .= "UDP-Befehle:        " . (cc_cfg($cfg, 'udp', '1') === '1'
                ? 'ein, Port ' . cc_cfg($cfg, 'udp_port', '7090') : 'aus') . "\n\n";
            if (!$pid) {
                $t .= "Der Dienst laeuft nicht. Ursache steht meistens im Protokoll\n"
                    . "(Reiter Logdateien). Mit \"Dienst neu starten\" erneut versuchen.\n\n";
            }
            if (!$geraete) {
                $t .= "Ohne Geraet in den Einstellungen tut der Dienst nichts.\n"
                    . "Mit \"Chromecasts im Netz suchen\" die genauen Namen ermitteln.\n\n";
            }
            // pgrep statt 'ps -C python3': der Parameter -C bindet an den
            // genauen Namen der ausfuehrbaren Datei. Startet LoxBerry den
            // Dienst unter python3.11 - oder laeuft er ueber das Shebang der
            // Datei selbst -, bleibt die Ausgabe leer, und im Reiter Test
            // stuende nichts, obwohl der Dienst laeuft.
            $t .= cc_sh('pgrep -a -f "[c]hromecast4lox-server" 2>/dev/null');
            $t .= "\n" . cc_sh('ps -o pid,etime,rss,args -p '
                . (int) cc_dienst_pid() . ' 2>/dev/null');
            return array('Zustand des Dienstes', trim($t) !== '' ? $t : 'Keine Angaben.');

        case 'suchen':
            $skript = $p['bindir'] . '/cc_discover.py';
            if (!is_file($skript)) {
                return array('Chromecasts im Netz', 'cc_discover.py nicht gefunden: ' . $skript);
            }
            $t = "Es wird 10 Sekunden im Netz gesucht. Chromecasts melden sich per\n"
               . "mDNS; Geraete im Ruhezustand brauchen manchmal einen Moment.\n\n";
            $t .= cc_sh('timeout 25 python3 ' . escapeshellarg($skript));
            return array('Chromecasts im Netz', $t);

        case 'themen':
            $praefix = cc_cfg($cfg, 'themenpraefix', 'chromecast4lox');
            if (!$geraete) {
                return array('MQTT-Themen', 'Es ist kein Geraet konfiguriert.');
            }
            $t = "Zustaende - kommen retained an, der Miniserver hat den Stand\n"
               . "also sofort nach einem Neustart wieder:\n\n";
            $t .= "  $praefix/server/online\n";
            foreach ($geraete as $g) {
                $th = cc_thema($g);
                $t .= "\n  " . $g . "  ->  " . $th . "\n";
                foreach (cc_status_themen() as $k => $info) {
                    $t .= sprintf("    %s/%s/%-9s %s\n", $praefix, $th, $k,
                        strip_tags(html_entity_decode($info[0], ENT_QUOTES, 'UTF-8')));
                }
            }
            $t .= "\n\nBefehle - hier veroeffentlicht der Miniserver:\n\n";
            foreach ($geraete as $g) {
                $th = cc_thema($g);
                $t .= "\n  " . $g . "\n";
                foreach (cc_befehle() as $b => $erklaerung) {
                    $t .= sprintf("    %s/%s/cmd/%-12s %s\n", $praefix, $th, $b,
                        strip_tags(html_entity_decode($erklaerung, ENT_QUOTES, 'UTF-8')));
                }
            }
            return array('MQTT-Themen', $t);

        case 'konfig':
            $t = "Datei: " . $p['config'] . "\n\n";
            if (is_file($p['config'])) {
                $t .= (string) @file_get_contents($p['config']);
            } else {
                $t .= "Datei nicht vorhanden - es gelten die Vorgabewerte:\n\n";
                foreach (cc_defaults() as $k => $v) {
                    $t .= $k . '=' . $v . "\n";
                }
            }
            return array('Konfiguration', $t);

        case 'umgebung':
            $t = "PHP:        " . PHP_VERSION . "\n";
            $t .= "LBHOMEDIR:  " . ($p['home'] !== '' ? $p['home'] : '(nicht gesetzt)') . "\n";
            $t .= "Plugin:     " . $p['plugin'] . "\n";
            $t .= "bin:        " . $p['bindir'] . "\n";
            $t .= "log:        " . $p['logdir'] . "\n\n";
            $t .= cc_sh('python3 --version');
            $t .= "\n\nBenoetigte Python-Module:\n";
            foreach (array('pychromecast', 'zeroconf', 'paho.mqtt.client') as $m) {
                $r = cc_sh('python3 -c ' . escapeshellarg('import ' . $m));
                $t .= sprintf("  %-22s %s\n", $m, $r === '' ? 'vorhanden' : 'FEHLT');
            }
            $t .= "\nVersion von pychromecast:\n  ";
            $v = cc_sh('python3 -c ' . escapeshellarg(
                'import importlib.metadata as m; print(m.version("PyChromecast"))'));
            $t .= ($v !== '' ? $v : '(nicht ermittelbar)') . "\n";
            $t .= "\nHinweis: Fehlt pychromecast, hat die Installation das Paket\n"
                . "python3-pychromecast nicht eingerichtet. Nachholen mit:\n"
                . "  sudo apt-get install -y python3-pychromecast\n";
            return array('Umgebung', $t);

        case 'mqttinfo':
            $broker = cc_mqtt_broker();
            $udp = cc_mqtt_udpinport();
            $t = "Broker:              " . ($broker !== '' ? $broker : 'nicht gefunden') . "\n";
            $t .= "UDP-Relay (UDP In):  " . ($udp ? $udp : 'nicht gesetzt') . "\n";
            $t .= "MQTT im Plugin:      " . (cc_cfg($cfg, 'mqtt', '1') === '1' ? 'ein' : 'aus') . "\n";
            $t .= "Themenpraefix:       " . cc_cfg($cfg, 'themenpraefix', 'chromecast4lox') . "\n\n";
            if ($broker === '') {
                $t .= "Ohne MQTT-Gateway kann das Plugin nichts veroeffentlichen.\n"
                    . "Das Gateway ist ein eigenes LoxBerry-Plugin und muss installiert sein.\n\n";
            }
            if (!$udp) {
                $t .= "Der UDP-Eingangsport des Gateways wird gebraucht, damit der\n"
                    . "Miniserver Befehle senden kann. Im Gateway unter \"UDP In\"\n"
                    . "einen Port setzen und die Vorlage der Ausgaenge neu erzeugen.\n\n";
            }
            $t .= "Zum Mitlesen eignet sich der MQTT Finder des Gateways;\n"
                . "dort auf " . cc_cfg($cfg, 'themenpraefix', 'chromecast4lox') . "/# achten.";
            return array('MQTT-Gateway', $t);

        case 'restart':
            $a = cc_dienst('restart');
            $pid = cc_dienst_pid();
            $t = ($a !== '' ? $a . "\n\n" : '');
            $t .= $pid ? "Dienst laeuft jetzt (PID $pid)."
                : "Der Dienst laeuft nicht. Protokoll im Reiter Logdateien pruefen.";
            return array('Dienst neu starten', $t);

        case 'stop':
            $a = cc_dienst('stop');
            $t = ($a !== '' ? $a . "\n\n" : '');
            $t .= cc_dienst_pid() ? "Es laeuft noch etwas - bitte Protokoll pruefen."
                : "Angehalten.\n\nHinweis: Beim naechsten Systemstart startet der Dienst wieder.";
            return array('Dienst anhalten', $t);

        case 'ping':
            if ($geraet === '') {
                $geraet = $geraete ? $geraete[0] : '';
            }
            if ($geraet === '') {
                return array('Testton', 'Es ist kein Geraet konfiguriert.');
            }
            $port = (int) cc_cfg($cfg, 'udp_port', '7090');
            if (cc_cfg($cfg, 'udp', '1') !== '1') {
                return array('Testton', "Der UDP-Weg ist ausgeschaltet.\n\n"
                    . "Er wird hier gebraucht, um dem Dienst von aussen einen Befehl\n"
                    . "zu schicken. Im Reiter Einstellungen einschalten oder den\n"
                    . "Befehl per MQTT senden.");
            }
            $befehl = cc_thema($geraet) . '/volume_step 0;';
            $sock = @fsockopen('udp://127.0.0.1', $port, $errno, $errstr, 2);
            if (!$sock) {
                return array('Testton', "UDP-Port $port nicht erreichbar: $errstr ($errno)");
            }
            @fwrite($sock, $befehl);
            @fclose($sock);
            return array('Befehl gesendet', "An 127.0.0.1:$port gesendet:\n\n  $befehl\n\n"
                . "Das ist eine Lautstaerkeaenderung um 0 - sie veraendert nichts,\n"
                . "zwingt den Dienst aber, das Geraet anzusprechen und den Zustand\n"
                . "neu zu melden. Ob es geklappt hat, steht im Protokoll.");
    }

    return array('Unbekannt', 'Diese Aktion gibt es nicht.');
}
