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

        // Alle Texte aus den Sprachdateien (seit 1.3.13, O9): bis 1.3.12 war
        // jede Antwort eines Testknopfs deutsch, auch auf der englischen
        // Seite (gemessen 30.09.2026, Oberflaechen-Befund 9).
        case 'status':
            $pid = cc_dienst_pid();
            $t = cc_t('AKTION.Z_DIENST') . ' ' . ($pid ? sprintf(cc_t('AKTION.LAEUFT_PID'), $pid)
                                                         : cc_t('AKTION.LAEUFT_NICHT')) . "\n";
            $t .= cc_t('AKTION.Z_GERAETE') . ' ' . (count($geraete) ? implode(', ', $geraete)
                                                                     : cc_t('AKTION.KEINE')) . "\n";
            $t .= cc_t('AKTION.Z_MQTT') . ' ' . (cc_cfg($cfg, 'mqtt_ein', '1') === '1'
                ? cc_t('TEXT.S_EIN') : cc_t('TEXT.S_AUS')) . "\n";
            $t .= cc_t('AKTION.Z_UDP') . ' ' . (cc_cfg($cfg, 'udp', '1') === '1'
                ? sprintf(cc_t('AKTION.UDP_EIN'), cc_cfg($cfg, 'udp_port', '7090'))
                : cc_t('TEXT.S_AUS')) . "\n\n";
            if (!$pid) {
                $t .= cc_t('AKTION.S_LAEUFT_NICHT') . "\n\n";
            }
            if (!$geraete) {
                $t .= cc_t('AKTION.S_OHNE_GERAET') . "\n\n";
            }
            // Zuerst die EIGENEN Prozesse, argumentweise erkannt
            // (cc_dienst_pids() in cc_lib.php). Bis 1.3.10 stand hier nur die
            // Suche nach der Zeichenkette, ohne Ueberschrift - sie laeuft ueber
            // das ganze System und trifft auch einen Editor, ein 'tail' und
            // den Dienst eines zweiten Plugin-Ordners. Was sie zeigte, las sich
            // wie "das ist dein Dienst". Sie bleibt stehen, weil sie beim
            // Suchen hilft, aber sie steht jetzt unter ihrer eigenen
            // Ueberschrift und an zweiter Stelle.
            //
            // 'ps -C python3' waere kein Ersatz: der Parameter -C bindet an den
            // genauen Namen der ausfuehrbaren Datei. Startet LoxBerry den
            // Dienst unter python3.11, bleibt die Ausgabe leer.
            $cc_eigene = cc_dienst_pids();
            $t .= cc_t('AKTION.Z_EIGENE') . ' '
                . ($cc_eigene ? implode(', ', $cc_eigene) : cc_t('AKTION.KEINE')) . "\n";
            foreach ($cc_eigene as $cc_einzeln) {
                $t .= cc_sh('ps -o pid,etime,rss,args -p '
                    . (int) $cc_einzeln . ' 2>/dev/null') . "\n";
            }
            $t .= "\n" . cc_t('AKTION.S_ALLE_PROZESSE') . "\n";
            $t .= cc_sh('pgrep -a -f "[c]hromecast4lox_ng-server" 2>/dev/null');
            return array(cc_t('TEXT.T171'), trim($t) !== '' ? $t : cc_t('AKTION.KEINE_ANGABEN'));

        case 'suchen':
            $skript = $p['bindir'] . '/cc_discover.py';
            if (!is_file($skript)) {
                return array(cc_t('AKTION.T_NETZ'), sprintf(cc_t('SUCHE.F_KEIN_SKRIPT'), $skript));
            }
            $t = cc_t('AKTION.S_SUCHE_DAUER') . "\n\n";
            $args = cc_cfg($cfg, 'gruppen', '1') === '1' ? '' : ' --ohne-gruppen';
            // "-k 5" wie in cc_suche(), und die Zeitueberschreitung wird
            // gesagt statt verschwiegen (Muster 13 der Nachlese).
            $cc_aus = array();
            $cc_rc = 0;
            @exec('timeout -k 5 25 python3 ' . escapeshellarg($skript) . $args . ' 2>&1',
                  $cc_aus, $cc_rc);
            $t .= implode("\n", $cc_aus);
            if ($cc_rc === 124 || $cc_rc === 137) {
                $t .= "\n\n" . sprintf(cc_t('SUCHE.F_ZEIT'), 25, $cc_rc);
            }
            return array(cc_t('AKTION.T_NETZ'), $t);

        case 'themen':
            $praefix = cc_cfg($cfg, 'mqtt_topic', 'chromecast4lox');
            if (!$geraete) {
                return array(cc_t('AKTION.T_THEMEN'), cc_t('AKTION.KEIN_GERAET'));
            }
            $t = cc_t('AKTION.S_THEMEN_KOPF') . "\n\n";
            $t .= "  $praefix/server/online\n";
            foreach ($geraete as $g) {
                $th = cc_thema($g);
                $t .= "\n  " . $g . "  ->  " . $th . "\n";
                foreach (cc_status_themen() as $k => $info) {
                    $t .= sprintf("    %s/%s/%-9s %s\n", $praefix, $th, $k,
                        strip_tags(html_entity_decode($info[0], ENT_QUOTES, 'UTF-8')));
                }
            }
            $t .= "\n\n" . cc_t('AKTION.S_BEFEHLE_KOPF') . "\n\n";
            foreach ($geraete as $g) {
                $th = cc_thema($g);
                $t .= "\n  " . $g . "\n";
                foreach (cc_befehle() as $b => $erklaerung) {
                    $t .= sprintf("    %s/%s/cmd/%-12s %s\n", $praefix, $th, $b,
                        strip_tags(html_entity_decode($erklaerung, ENT_QUOTES, 'UTF-8')));
                }
            }
            return array(cc_t('AKTION.T_THEMEN'), $t);

        case 'konfig':
            $t = cc_t('TEXT.T183') . ' ' . $p['config'] . "\n\n";
            if (is_file($p['config'])) {
                $t .= (string) @file_get_contents($p['config']);
            } else {
                $t .= cc_t('AKTION.S_KONFIG_FEHLT') . "\n\n";
                foreach (cc_defaults() as $k => $v) {
                    $t .= $k . '=' . $v . "\n";
                }
            }
            return array(cc_t('AKTION.T_KONFIG'), $t);

        case 'umgebung':
            $t = "PHP:        " . PHP_VERSION . "\n";
            $t .= "LBHOMEDIR:  " . ($p['home'] !== '' ? $p['home'] : cc_t('AKTION.NICHT_GESETZT')) . "\n";
            $t .= "Plugin:     " . $p['plugin'] . "\n";
            $t .= "bin:        " . $p['bindir'] . "\n";
            $t .= "log:        " . $p['logdir'] . "\n\n";
            $t .= cc_sh('python3 --version');
            $t .= "\n\n" . cc_t('AKTION.S_MODULE') . "\n";
            foreach (array('pychromecast', 'zeroconf', 'paho.mqtt.client') as $m) {
                $r = cc_sh('python3 -c ' . escapeshellarg('import ' . $m));
                $t .= sprintf("  %-22s %s\n", $m, $r === '' ? cc_t('AKTION.VORHANDEN') : cc_t('AKTION.FEHLT'));
            }
            $t .= "\n" . cc_t('AKTION.S_PYCC_FASSUNG') . "\n  ";
            $v = cc_sh('python3 -c ' . escapeshellarg(
                'import importlib.metadata as m; print(m.version("PyChromecast"))'));
            $t .= ($v !== '' ? $v : cc_t('AKTION.NICHT_ERMITTELBAR')) . "\n";
            $t .= "\n" . cc_t('AKTION.S_PYCC_FEHLT') . "\n"
                . "  sudo apt-get install -y python3-pychromecast\n";
            return array(cc_t('AKTION.T_UMGEBUNG'), $t);

        case 'mqttinfo':
            $broker = cc_mqtt_broker();
            $udp = cc_mqtt_udpinport();
            $t = cc_t('AKTION.Z_BROKER') . ' ' . ($broker !== '' ? $broker : cc_t('AKTION.NICHT_GEFUNDEN')) . "\n";
            $t .= cc_t('AKTION.Z_RELAY') . ' ' . ($udp ? $udp : cc_t('AKTION.NICHT_GESETZT')) . "\n";
            $t .= cc_t('AKTION.Z_MQTT_PLUGIN') . ' ' . (cc_cfg($cfg, 'mqtt_ein', '1') === '1'
                ? cc_t('TEXT.S_EIN') : cc_t('TEXT.S_AUS')) . "\n";
            $t .= cc_t('AKTION.Z_PRAEFIX') . ' ' . cc_cfg($cfg, 'mqtt_topic', 'chromecast4lox') . "\n\n";
            if ($broker === '') {
                $t .= cc_t('AKTION.S_OHNE_GATEWAY') . "\n\n";
            }
            if (!$udp) {
                $t .= cc_t('AKTION.S_OHNE_RELAY') . "\n\n";
            }
            $t .= sprintf(cc_t('AKTION.S_FINDER'), cc_cfg($cfg, 'mqtt_topic', 'chromecast4lox') . '/#');
            return array(cc_t('TEXT.T176'), $t);

        case 'restart':
            // Waehrend einer Aktualisierung wird nichts angefasst. Die
            // Konfiguration ist in dieser Zeit die mitgelieferte Vorgabe; ein
            // hier gestarteter Dienst liefe mit falschem Themenpraefix und
            // falschem UDP-Port, und der Schalter, den die naechste Zeile
            // setzt, wuerde vom Installer ueberschrieben. Die Oberflaeche
            // haelt schon am Eingang an - dies ist die zweite Tuer, weil
            // diese Datei auch von anderswoher eingebunden werden kann.
            if (cc_upgrade_laeuft()) {
                return array(cc_t('TEXT.T178'), cc_t('UPGRADE.T_AKTION'));
            }
            // Den Schalter mitziehen: wer neu startet, will den Dienst
            // laufen sehen - auch nach dem naechsten Waechterlauf. Scheitert
            // das Schreiben, steht es da (seit 1.3.13, C14).
            $cc_schalter = cc_dienst_schalter(true);
            $a = cc_dienst('restart');
            $pid = cc_dienst_pid();
            $t = ($a !== '' ? $a . "\n\n" : '');
            $t .= $pid ? sprintf(cc_t('AKTION.LAEUFT_JETZT'), $pid) : cc_t('AKTION.S_LAEUFT_NICHT');
            if (!$cc_schalter) {
                $t .= "\n\n" . cc_t('AKTION.SCHALTER_EIN_FEHLT');
            }
            return array(cc_t('TEXT.T178'), $t);

        case 'stop':
            if (cc_upgrade_laeuft()) {
                return array(cc_t('TEXT.T179'), cc_t('UPGRADE.T_AKTION'));
            }
            // Erst den Schalter, dann anhalten. Andersherum koennte der
            // Waechter dazwischen anlaufen und den Dienst sofort wieder
            // starten.
            //
            // Seit 1.3.13 wird der Rueckgabewert ausgewertet (C14) und das
            // Ergebnis an den Prozessen gemessen (O8). Bis 1.3.12 stand
            // "Angehalten ... Der Waechter startet ihn NICHT nach" auch dann,
            // wenn der Schalter sich nicht setzen liess - dann startete ihn
            // der Waechter nach spaetestens fuenf Minuten wieder.
            $cc_vorher = count(cc_dienst_pids());
            $cc_schalter = cc_dienst_schalter(false);
            $a = cc_dienst('stop');
            $t = ($a !== '' ? $a . "\n\n" : '');
            if (cc_dienst_pids()) {
                $t .= cc_dienst_lage_satz($cc_vorher) . ' ' . cc_t('AKTION.S_LAEUFT_NOCH');
            } else {
                $t .= cc_dienst_lage_satz($cc_vorher) . "\n\n"
                    . ($cc_schalter ? cc_t('AKTION.S_SCHALTER_AUS') : cc_t('AKTION.SCHALTER_AUS_FEHLT'));
            }
            return array(cc_t('TEXT.T179'), $t);

        case 'ping':
            if ($geraet === '') {
                $geraet = $geraete ? $geraete[0] : '';
            }
            if ($geraet === '') {
                return array(cc_t('TEXT.T180'), cc_t('AKTION.KEIN_GERAET'));
            }
            $port = (int) cc_cfg($cfg, 'udp_port', '7090');
            if (cc_cfg($cfg, 'udp', '1') !== '1') {
                return array(cc_t('TEXT.T180'), cc_t('AKTION.S_UDP_AUS'));
            }
            $befehl = cc_thema($geraet) . '/volume_step 0;';
            $sock = @fsockopen('udp://127.0.0.1', $port, $errno, $errstr, 2);
            if (!$sock) {
                return array(cc_t('TEXT.T180'), sprintf(cc_t('AKTION.UDP_NICHT_ERREICHBAR'),
                                                        $port, $errstr, $errno));
            }
            @fwrite($sock, $befehl);
            @fclose($sock);
            return array(cc_t('AKTION.T_GESENDET'), sprintf(cc_t('AKTION.GESENDET_AN'), $port)
                . "\n\n  " . $befehl . "\n\n" . cc_t('AKTION.S_PING'));
    }

    return array(cc_t('AKTION.T_UNBEKANNT'), cc_t('AKTION.UNBEKANNT'));
}

/* ==================================================================
 * Selbstpruefung - beantwortet OHNE Loxone, ob die Einrichtung traegt.
 * ================================================================== */

/** Eine Zeile. $zustand: true = Haken, false = Kreuz, null = Strich. */
function cc_pruefzeile(&$zeilen, $frage, $zustand, $antwort)
{
    $zeilen[] = array($frage, $zustand, $antwort);
}

/**
 * Alle Pruefzeilen.
 *
 * Rueckgabe: array(zeilen, array(gut, schlecht, unbekannt))
 */
function cc_selbstpruefung()
{
    $z = array();
    $p = cc_paths();
    $cfg = cc_config_read();
    $geraete = cc_geraete($cfg);

    /* --- 1. Laeuft der Dienst? -------------------------------------
     * ALLE Prozesse (seit 1.3.13, O10). Bis 1.3.12 fragte die Zeile
     * cc_dienst_pid() - eine einzige Nummer - und zeigte bei zwei Diensten
     * einen Haken (gemessen 30.09.2026 unter 7.4 und 8.5,
     * Oberflaechen-Befund 10). Mehr als einer ist ein Kreuz. */
    $pids = cc_dienst_pids();
    $pid = cc_dienst_pid();
    $an = cc_cfg($cfg, 'enabled', '1') === '1';
    if (count($pids) > 1) {
        cc_pruefzeile($z, cc_t('TEST.F_DIENST'), false,
            sprintf(cc_t('TEST.A_MEHRERE'), count($pids), implode(', ', $pids)));
    } elseif (!$an) {
        cc_pruefzeile($z, cc_t('TEST.F_DIENST'), null, cc_t('TEST.A_AUSGESCHALTET'));
    } else {
        cc_pruefzeile($z, cc_t('TEST.F_DIENST'), $pid > 0,
            $pid > 0 ? sprintf(cc_t('TEST.A_PID'), $pid) : cc_t('TEST.A_LAEUFT_NICHT'));
    }

    /* --- 2. Arbeitet er noch? --------------------------------------
     * Eine Prozessnummer beantwortet das nicht: ein Prozess kann dastehen
     * und nichts mehr tun. Ueber einen Dienst, der gar nicht laeuft, wird
     * aber auch kein Herzschlag beurteilt - das gaebe ein zweites Kreuz
     * fuer denselben Umstand.
     */
    $zdatei = $p['datadir'] . '/zustand.json';
    if (!$an || $pid <= 0) {
        cc_pruefzeile($z, cc_t('TEST.F_HERZSCHLAG'), null, cc_t('TEST.A_KEIN_DIENST'));
    } elseif (!is_file($zdatei)) {
        cc_pruefzeile($z, cc_t('TEST.F_HERZSCHLAG'), false, cc_t('TEST.A_KEIN_ZUSTAND'));
    } else {
        $zu = json_decode((string) @file_get_contents($zdatei), true);
        $zeit = is_array($zu) && isset($zu['zeit']) ? (int) $zu['zeit'] : 0;
        $alter = $zeit > 0 ? time() - $zeit : -1;
        // Die Schwelle haengt am eingestellten Takt: dreimal das Intervall,
        // mindestens eine Minute. Ein einzelner verpasster Durchgang ist
        // kein Befund.
        $grenze = max(60, 3 * (int) cc_cfg($cfg, 'intervall', '10'));
        if ($alter < 0) {
            cc_pruefzeile($z, cc_t('TEST.F_HERZSCHLAG'), null, cc_t('TEST.A_ZUSTAND_KAPUTT'));
        } else {
            cc_pruefzeile($z, cc_t('TEST.F_HERZSCHLAG'), $alter <= $grenze,
                sprintf(cc_t('TEST.A_ALTER'), $alter, $grenze,
                        is_array($zu) && isset($zu['zaehler']) ? (int) $zu['zaehler'] : 0));
        }
    }

    /* --- 3. Ist die Konfiguration heil? ----------------------------
     * Jeder Zustand, den der Code erzeugen kann, bekommt seinen Satz.
     */
    $zustand = cc_config_zustand();
    $saetze = array(
        'ok'       => array(true,  cc_t('TEST.A_KONFIG_OK')),
        'fehlt'    => array(null,  cc_t('TEST.A_KONFIG_FEHLT')),
        'leer'     => array(false, cc_t('TEST.A_KONFIG_LEER')),
        'unlesbar' => array(false, cc_t('TEST.A_KONFIG_UNLESBAR')),
    );
    $s = isset($saetze[$zustand]) ? $saetze[$zustand] : array(false, $zustand);
    cc_pruefzeile($z, cc_t('TEST.F_KONFIG'), $s[0], $s[1]);

    /* --- 3b. Ist die Konfiguration VOLLSTAENDIG? --------------------
     * Etwas anderes als "heil": eine lesbare Datei kann Schluessel
     * vermissen lassen. Gezaehlt wird gegen cc_config_roh() - also gegen
     * das, was wirklich in der Datei steht. Auf cc_config_read() waere
     * die Frage sinnlos, denn dort sind die Vorgaben schon untergemischt.
     */
    $soll = cc_defaults();
    $roh = cc_config_roh();
    if (!$soll) {
        // Ohne Sollliste beweist der Vergleich nichts - und die
        // Oberflaeche schreibt in diesem Zustand ohnehin nicht mehr.
        cc_pruefzeile($z, cc_t('TEST.F_VOLLSTAENDIG'), false,
            cc_t('TEST.A_KEINE_VORGABEN'));
    } elseif ($zustand !== 'ok') {
        cc_pruefzeile($z, cc_t('TEST.F_VOLLSTAENDIG'), null,
            cc_t('TEST.A_KEIN_VERGLEICH'));
    } else {
        $fehlen = array();
        foreach ($soll as $k => $v) {
            if (!array_key_exists($k, $roh)) {
                $fehlen[] = $k;
            }
        }
        cc_pruefzeile($z, cc_t('TEST.F_VOLLSTAENDIG'), count($fehlen) === 0,
            count($fehlen) === 0
                ? sprintf(cc_t('TEST.A_VOLLSTAENDIG'), count($soll), count($soll))
                : sprintf(cc_t('TEST.A_UNVOLLSTAENDIG'),
                          count($soll) - count($fehlen), count($soll),
                          implode(', ', $fehlen)));
    }

    /* --- 3c. Ueberstehen die Favoriten Schreiben und Lesen? ---------
     * (seit 1.3.13, C3) Bis 1.3.12 machte cc_config_write() aus jedem
     * Zeilenumbruch ein ";", und aus drei Favoriten wurde einer. Gemessen
     * wird die Kodierung, die cc_config_write() benutzt, an drei festen
     * Probezeilen UND an der eingetragenen Liste - ohne in die Datei zu
     * schreiben: kodieren, als Dateizeile zerlegen wie cc_config_roh(),
     * dekodieren, zaehlen. */
    $probe = "Eins = http://a.example/1.mp3\nZwei = http://b.example/2.mp3?x=1;y=2\nDrei = http://c.example/3.mp3";
    $cc_rund = function ($text) {
        $zeile = 'favoriten=' . cc_wert_kodieren($text);
        $teile = cc_zeilen($zeile);
        $wert = substr($teile[0], strlen('favoriten='));
        $n = strlen($wert);
        if ($n >= 2 && ($wert[0] === '"' || $wert[0] === "'") && $wert[$n - 1] === $wert[0]) {
            $wert = substr($wert, 1, -1);
        }
        return count(cc_favoriten(array('favoriten' => cc_wert_dekodieren($wert))));
    };
    $fav_soll = count(cc_favoriten($cfg));
    $fav_probe = $cc_rund($probe);
    $fav_ist = $cc_rund((string) cc_cfg($cfg, 'favoriten', ''));
    cc_pruefzeile($z, cc_t('TEST.F_FAVORITEN'), $fav_probe === 3 && $fav_ist === $fav_soll,
        sprintf(cc_t('TEST.A_FAVORITEN'), $fav_probe, 3, $fav_ist, $fav_soll));

    /* --- 4. Sind Geraete eingetragen? ------------------------------ */
    cc_pruefzeile($z, cc_t('TEST.F_GERAETE'), count($geraete) > 0,
        count($geraete) > 0 ? sprintf(cc_t('TEST.A_GERAETE'), count($geraete),
                                      implode(', ', $geraete))
                            : cc_t('TEST.A_KEINE_GERAETE'));

    /* --- 5. Sind die Python-Module da? ----------------------------- */
    $fehlend = array();
    $angesehen = 0;
    foreach (array('pychromecast', 'zeroconf', 'paho.mqtt.client') as $m) {
        $angesehen++;
        $r = cc_sh('python3 -c ' . escapeshellarg('import ' . $m));
        if (trim($r) !== '') {
            $fehlend[] = $m;
        }
    }
    $v = trim(cc_sh('python3 -c ' . escapeshellarg(
        'import importlib.metadata as m; print(m.version("PyChromecast"))')));
    cc_pruefzeile($z, cc_t('TEST.F_MODULE'), count($fehlend) === 0,
        count($fehlend) === 0
            ? sprintf(cc_t('TEST.A_MODULE_DA'), $angesehen,
                      $v !== '' && strpos($v, 'Trace') === false ? $v : '?')
            : sprintf(cc_t('TEST.A_MODULE_FEHLEN'), implode(', ', $fehlend)));

    /* --- 6. MQTT-Gateway ------------------------------------------- */
    if (cc_cfg($cfg, 'mqtt_ein', '1') !== '1') {
        cc_pruefzeile($z, cc_t('TEST.F_BROKER'), null, cc_t('TEST.A_MQTT_AUS'));
        cc_pruefzeile($z, cc_t('TEST.F_AUTOSTART'), null, cc_t('TEST.A_MQTT_AUS'));
    } else {
        $broker = cc_mqtt_broker();
        cc_pruefzeile($z, cc_t('TEST.F_BROKER'), $broker !== '',
            $broker !== '' ? $broker : cc_t('TEST.A_KEIN_BROKER'));
        // Autostart UND Fassung aus derselben Funktion - ein Schluessel,
        // ein Wortlaut, ein Dateizugriff.
        $gw = cc_mqtt_gateway_info();
        if ($gw === null) {
            cc_pruefzeile($z, cc_t('TEST.F_AUTOSTART'), null, cc_t('TEST.A_NICHT_MESSBAR'));
            cc_pruefzeile($z, cc_t('TEST.F_GWFASSUNG'), null, cc_t('TEST.A_NICHT_MESSBAR'));
        } else {
            cc_pruefzeile($z, cc_t('TEST.F_AUTOSTART'), $gw['autostart'],
                $gw['autostart'] ? cc_t('TEST.A_JA') : cc_t('TEST.A_AUTOSTART_AUS'));
            // Die Fassung ist KEIN Fehler, egal welche - deshalb ein Strich,
            // wenn sie unbekannt ist, und ein Haken, wenn sie feststeht.
            $f = (int) $gw['fassung'];
            cc_pruefzeile($z, cc_t('TEST.F_GWFASSUNG'), $f > 0 ? true : null,
                $f > 0 ? sprintf(cc_t('TEST.A_GWFASSUNG'), $f)
                       : cc_t('TEST.A_GWFASSUNG_UNBEKANNT'));
        }
    }

    /* --- 7. Themenliste gegen den Dienst ---------------------------
     * Zwei Listen in zwei Sprachen halten sich nicht von selbst gleich,
     * und ein Kommentar ist kein Nachweis.
     */
    $eigene = array_keys(cc_status_themen());
    $skript = $p['bindir'] . '/chromecast4lox_ng-server.py';
    $d = null;
    if (!$eigene) {
        // Eine leere Liste gegen eine leere Liste ist gleich - und beweist
        // nichts. Ohne diese Wache meldete die Zeile bei einer kaputten
        // bin/cc_themen.json einen Haken und "0 Zustaende".
        cc_pruefzeile($z, cc_t('TEST.F_THEMEN'), false, cc_t('TEST.A_THEMEN_LEER'));
    } elseif (!is_file($skript)) {
        cc_pruefzeile($z, cc_t('TEST.F_THEMEN'), null, cc_t('TEST.A_KEIN_SKRIPT'));
    } else {
        $roh = cc_sh('timeout -k 5 20 python3 ' . escapeshellarg($skript) . ' --themen');
        $d = json_decode(trim($roh), true);
        /* Gegen den SENDECODE (seit 1.3.13, O17): "gesendet_geraet" ist, was
         * _melden() im Dienst gegen eine Attrappe des Lautsprechers wirklich
         * veroeffentlicht. Bis 1.3.12 hielt die Zeile bin/cc_themen.json
         * gegen dieselbe Datei, vom Dienst nur wieder ausgegeben - ein Thema,
         * das der Code nicht mehr sendet, blieb gruen (Oberflaechen-Befund
         * 17). Verglichen wird als Menge, in beide Richtungen. */
        if (!is_array($d) || !isset($d['gesendet_geraet']) || !is_array($d['gesendet_geraet'])
            || !$d['gesendet_geraet']) {
            cc_pruefzeile($z, cc_t('TEST.F_THEMEN'), null, cc_t('TEST.A_NICHT_MESSBAR'));
        } else {
            $gesendet = $d['gesendet_geraet'];
            $nur_liste = array_values(array_diff($eigene, $gesendet));
            $nur_code = array_values(array_diff($gesendet, $eigene));
            $gleich = !$nur_liste && !$nur_code;
            cc_pruefzeile($z, cc_t('TEST.F_THEMEN'), $gleich,
                $gleich ? sprintf(cc_t('TEST.A_THEMEN_GLEICH'), count($eigene),
                                  count($gesendet))
                        : sprintf(cc_t('TEST.A_THEMEN_ANDERS'),
                                  implode(', ', $nur_liste), implode(', ', $nur_code)));
        }
    }

    /* --- 7b. Geht das Lebenszeichen ohne Retain hinaus? -------------
     * Gefragt wird der DIENST (--themen), nicht die Datei: die Datei sagt,
     * was gewollt ist, der Code entscheidet, was hinausgeht. Bis 1.3.8
     * gingen server/ts und server/zaehler retained hinaus, und ein Thema
     * ohne Eintrag galt als retained (Regeln/07: das Lebenszeichen nie).
     */
    $lz = isset($d) && is_array($d) && isset($d['retain_dienst']) && is_array($d['retain_dienst'])
        ? $d['retain_dienst'] : null;
    if ($lz === null || !array_key_exists('retain_unbekannt', $d)) {
        cc_pruefzeile($z, cc_t('TEST.F_LZ_RETAIN'), null, cc_t('TEST.A_NICHT_MESSBAR'));
    } else {
        $falsch = array();
        foreach (array('ts', 'zaehler') as $k) {
            if (!array_key_exists($k, $lz) || $lz[$k] !== false) {
                $falsch[] = 'server/' . $k;
            }
        }
        if ($d['retain_unbekannt'] !== false) {
            $falsch[] = cc_t('TEST.A_LZ_UNBEKANNT');
        }
        cc_pruefzeile($z, cc_t('TEST.F_LZ_RETAIN'), count($falsch) === 0,
            count($falsch) === 0 ? cc_t('TEST.A_LZ_OK')
                                 : sprintf(cc_t('TEST.A_LZ_FALSCH'), implode(', ', $falsch)));
    }

    /* --- 8. Sind die Vorlagen wohlgeformt? -------------------------
     * Eine kaputte Vorlage merkt der Anwender sonst erst in Loxone Config,
     * und dort sucht er den Fehler bei sich.
     */
    if (!$geraete) {
        cc_pruefzeile($z, cc_t('TEST.F_VORLAGE'), null, cc_t('TEST.A_KEINE_GERAETE_KURZ'));
    } else {
        $kaputt = array();
        $gesehen = 0;
        $vorher = libxml_use_internal_errors(true);
        foreach (array('mqtt_in', 'mqtt_out', 'udp_out') as $art) {
            $gesehen++;
            list($name, $inhalt) = cc_vorlage($art, $cfg, $geraete);
            if ($inhalt === '' || simplexml_load_string($inhalt) === false) {
                $kaputt[] = $art;
            }
            libxml_clear_errors();
        }
        libxml_use_internal_errors($vorher);
        cc_pruefzeile($z, cc_t('TEST.F_VORLAGE'), count($kaputt) === 0,
            count($kaputt) === 0 ? sprintf(cc_t('TEST.A_VORLAGE_OK'), $gesehen)
                                 : sprintf(cc_t('TEST.A_VORLAGE_KAPUTT'),
                                           implode(', ', $kaputt)));
    }

    /* --- 9. Reiterleiste, Bereiche und Positivliste --------------- */
    $eigen = @file_get_contents(__DIR__ . '/index.php');
    if ($eigen === false) {
        cc_pruefzeile($z, cc_t('TEST.F_REITER'), null, cc_t('TEST.A_NICHT_MESSBAR'));
        cc_pruefzeile($z, cc_t('TEST.F_MERKMAL'), null, cc_t('TEST.A_NICHT_MESSBAR'));
    } else {
        preg_match_all('/data-ziel="(tab-[a-z]+)"/', $eigen, $m1);
        // Zwischen class= und id= steht ein PHP-Block, und dessen
        // schliessendes Zeichenpaar enthaelt ein Groesserzeichen. Ein
        // Muster mit einer verneinten Zeichenklasse darauf findet deshalb
        // NULL Bereiche - so stand die Zeile im ersten Anlauf rot, obwohl
        // nichts falsch war. Ein rotes Kreuz, das nichts bedeutet, ist
        // schlimmer als keine Pruefung.
        //
        // Und der erste Erklaerkommentar dazu trug das Zeichenpaar
        // WOERTLICH - damit endete der PHP-Block mitten in der Datei.
        preg_match_all('/class="sm-seite.{0,200}?id="(tab-[a-z]+)"/s', $eigen, $m2);
        preg_match('/\$cc_tabliste = array\(([^)]*)\)/', $eigen, $m3);
        $liste = array();
        if (!empty($m3[1])) {
            preg_match_all("/'(tab-[a-z]+)'/", $m3[1], $m4);
            $liste = $m4[1];
        }
        $leiste = $m1[1];
        $bereiche = $m2[1];
        sort($leiste); sort($bereiche); sort($liste);
        $angesehen = count($leiste) + count($bereiche) + count($liste);
        if ($angesehen === 0) {
            // Eine Pruefung ohne Fundstellen ist kein Nachweis, sondern ein
            // blinder Fleck.
            cc_pruefzeile($z, cc_t('TEST.F_REITER'), null, cc_t('TEST.A_NICHTS_GEFUNDEN'));
        } else {
            $gleich = $leiste === $bereiche && $leiste === $liste;
            cc_pruefzeile($z, cc_t('TEST.F_REITER'), $gleich,
                sprintf(cc_t('TEST.A_REITER'), count($leiste), count($bereiche),
                        count($liste)));
        }

        /* --- 10. Traegt jedes Formular das Merkmal? --------------- */
        $formulare = preg_match_all('/<form\b/', $eigen);
        $marken = preg_match_all('/name="fmt"/', $eigen);
        if ($formulare === 0) {
            cc_pruefzeile($z, cc_t('TEST.F_MERKMAL'), null, cc_t('TEST.A_NICHTS_GEFUNDEN'));
        } else {
            cc_pruefzeile($z, cc_t('TEST.F_MERKMAL'), $marken >= $formulare,
                sprintf(cc_t('TEST.A_MERKMAL'), $marken, $formulare));
        }
    }

    /* --- 11. Laeuft der Waechter? ----------------------------------
     * An ALLEN Cron-Orten (seit 1.3.13, O11; Regeln/04, Raumklima 0.11.8).
     * Bis 1.3.12 nur der feste Pfad cron.05min - ein liegengebliebener
     * Eintrag in einem anderen Takt blieb unsichtbar, und die Antwort nannte
     * keinen Pfad (gemessen 30.09.2026, Oberflaechen-Befund 11). */
    $home = $p['home'];
    $wtreffer = $home !== '' ? glob($home . '/system/cron/cron.*min/' . $p['plugin']) : array();
    $wtreffer = is_array($wtreffer) ? $wtreffer : array();
    if ($home === '') {
        cc_pruefzeile($z, cc_t('TEST.F_WAECHTER'), null, cc_t('TEST.A_NICHT_MESSBAR'));
    } elseif (!$an) {
        cc_pruefzeile($z, cc_t('TEST.F_WAECHTER'), null, cc_t('TEST.A_AUSGESCHALTET'));
    } elseif (!$wtreffer) {
        cc_pruefzeile($z, cc_t('TEST.F_WAECHTER'), false, cc_t('TEST.A_KEIN_WAECHTER'));
    } else {
        $wsoll = $home . '/system/cron/cron.05min/' . $p['plugin'];
        $wrest = array_values(array_diff($wtreffer, array($wsoll)));
        $wda = in_array($wsoll, $wtreffer, true);
        cc_pruefzeile($z, cc_t('TEST.F_WAECHTER'), $wda && !$wrest,
            $wda && !$wrest ? sprintf(cc_t('TEST.A_WAECHTER_PFAD'), $wsoll)
                            : sprintf(cc_t('TEST.A_WAECHTER_REST'), implode(', ', $wtreffer)));
    }

    /* --- Bilanz ---------------------------------------------------- */
    $gut = $schlecht = $unbekannt = 0;
    foreach ($z as $zeile) {
        if ($zeile[1] === true) { $gut++; }
        elseif ($zeile[1] === false) { $schlecht++; }
        else { $unbekannt++; }
    }
    return array($z, array($gut, $schlecht, $unbekannt));
}
