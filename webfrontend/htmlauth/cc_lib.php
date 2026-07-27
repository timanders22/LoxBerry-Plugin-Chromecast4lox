<?php
/**
 * Chromecast 4 Lox - gemeinsame Hilfsfunktionen
 *
 * Die Konfiguration bleibt im INI-Format (Abschnitt [CONFIG]), damit sie mit
 * Config_Lite und mit dem Python-Dienst gleichermassen lesbar ist.
 *
 * Eigenes Praefix "cc_", weil LBWeb::lbheader() SDK-Globale setzt und sonst
 * Namen kollidieren.
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */

if (!function_exists('cc_e')) {
    function cc_e($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}

/** Basisverzeichnisse ermitteln - funktioniert installiert wie im Archiv. */
function cc_paths()
{
    static $p = null;
    if ($p !== null) {
        return $p;
    }
    $home = getenv('LBHOMEDIR');
    if (!$home && is_dir('/opt/loxberry')) {
        $home = '/opt/loxberry';
    }
    $dir = getenv('LBPPLUGINDIR');
    if (!$dir) {
        $dir = basename(dirname(dirname(__DIR__)));
    }
    if ($home && !is_dir($home . '/config/plugins/' . $dir)) {
        foreach (array(basename(dirname(__DIR__)), 'chromecast-4lox') as $cand) {
            if (is_dir($home . '/config/plugins/' . $cand)) {
                $dir = $cand;
                break;
            }
        }
    }
    if ($home) {
        $p = array(
            'home'   => $home,
            'plugin' => $dir,
            'config' => $home . '/config/plugins/' . $dir . '/' . $dir . '.cfg',
            'bindir' => $home . '/bin/plugins/' . $dir,
            'logdir' => $home . '/log/plugins/' . $dir,
        );
    } else {
        $base = dirname(dirname(__DIR__));
        $p = array(
            'home'   => '',
            'plugin' => $dir,
            'config' => $base . '/config/chromecast-4lox.cfg',
            'bindir' => $base . '/bin',
            'logdir' => sys_get_temp_dir(),
        );
    }
    return $p;
}

/** Voreinstellungen. Gleiche Werte wie im Python-Dienst. */
function cc_defaults()
{
    return array(
        'geraete'             => '',
        'themenpraefix'       => 'chromecast4lox',
        'mqtt'                => '1',
        'udp'                 => '1',
        'udp_port'            => '7090',
        'intervall'           => '10',
        'aktualisierung'      => '60',
        'lautstaerke_schritt' => '5',
    );
}

/** Konfiguration lesen. */
function cc_config_read()
{
    $out = cc_defaults();
    $file = cc_paths()['config'];
    if (!is_file($file)) {
        return $out;
    }
    foreach (preg_split('/\R/', (string) @file_get_contents($file)) as $line) {
        $t = trim($line);
        if ($t === '' || $t[0] === ';' || $t[0] === '#' || $t[0] === '[') {
            continue;
        }
        $pos = strpos($t, '=');
        if ($pos === false) {
            continue;
        }
        $key = strtolower(trim(substr($t, 0, $pos)));
        $val = trim(substr($t, $pos + 1));
        $len = strlen($val);
        if ($len >= 2 && (($val[0] === '"' && $val[$len - 1] === '"')
            || ($val[0] === "'" && $val[$len - 1] === "'"))) {
            $val = substr($val, 1, -1);
        }
        $out[$key] = $val;
    }
    return $out;
}

/** Wert lesen, mit Vorgabe. */
function cc_cfg($cfg, $key, $default = '')
{
    return isset($cfg[$key]) && $cfg[$key] !== '' ? $cfg[$key] : $default;
}

/** Konfiguration schreiben, Format wie von Config_Lite erzeugt. */
function cc_config_write($cfg)
{
    $file = cc_paths()['config'];
    @mkdir(dirname($file), 0775, true);
    $txt = "; Chromecast 4 Lox\n; " . date('D M j H:i:s Y') . "\n\n[CONFIG]\n";
    foreach (cc_defaults() as $k => $vorgabe) {
        $v = isset($cfg[$k]) ? $cfg[$k] : $vorgabe;
        // Mehrzeilige Geraeteliste auf eine Zeile bringen - INI kennt
        // keine Fortsetzungszeilen.
        $v = str_replace(array("\r\n", "\n", "\r"), ';', (string) $v);
        $v = preg_replace('/;{2,}/', ';', $v);
        $txt .= $k . '=' . trim($v, '; ') . "\n";
    }
    $ok = @file_put_contents($file, $txt) !== false;
    if ($ok) {
        @chmod($file, 0644);
    }
    return $ok;
}

/** Geraeteliste aus der Konfiguration. */
function cc_geraete($cfg)
{
    $roh = cc_cfg($cfg, 'geraete', '');
    $teile = preg_split('/[;,\n\r]+/', $roh);
    $out = array();
    foreach ($teile as $t) {
        $t = trim($t);
        if ($t !== '') {
            $out[] = $t;
        }
    }
    return $out;
}

/**
 * Geraetenamen in ein MQTT-taugliches Thema umformen.
 * Muss genau dasselbe liefern wie thema_saeubern() im Python-Dienst,
 * sonst passen die erzeugten Loxone-Vorlagen nicht zu den Themen.
 */
function cc_thema($name)
{
    $name = str_replace(
        array('ä', 'ö', 'ü', 'Ä', 'Ö', 'Ü', 'ß'),
        array('ae', 'oe', 'ue', 'Ae', 'Oe', 'Ue', 'ss'),
        (string) $name
    );
    if (function_exists('iconv')) {
        $um = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        if ($um !== false) {
            $name = $um;
        }
    }
    $name = preg_replace('/[^A-Za-z0-9_-]+/', '_', $name);
    $name = trim($name, '_');
    return $name !== '' ? $name : 'geraet';
}

/** Alle Zustandsthemen eines Geraets, mit Erklaerung. */
function cc_status_themen()
{
    return array(
        'online'   => array('Erreichbar (1/0)', 'digital'),
        'state'    => array('Zustand: PLAYING, PAUSED, IDLE, BUFFERING, OFFLINE', 'text'),
        'playing'  => array('Spielt gerade (1/0)', 'digital'),
        'volume'   => array('Lautst&auml;rke 0 bis 100', 'analog'),
        'muted'    => array('Stumm (1/0)', 'digital'),
        'app'      => array('Name der laufenden App', 'text'),
        'title'    => array('Titel', 'text'),
        'artist'   => array('Interpret', 'text'),
        'album'    => array('Album', 'text'),
        'duration' => array('L&auml;nge in Sekunden', 'analog'),
        'position' => array('Laufzeit in Sekunden', 'analog'),
    );
}

/** Alle Befehle, mit Erklaerung. */
function cc_befehle()
{
    return array(
        'play'        => 'Wiedergabe fortsetzen. Enth&auml;lt die Nutzlast eine Adresse mit <span class="cc-mono">://</span>, wird stattdessen dieses Medium gestartet.',
        'pause'       => 'Pause.',
        'stop'        => 'Wiedergabe beenden.',
        'quit'        => 'Laufende App auf dem Ger&auml;t schlie&szlig;en.',
        'volume'      => 'Lautst&auml;rke setzen, 0 bis 100.',
        'volume_step' => 'Lautst&auml;rke &auml;ndern, z.&nbsp;B. 5 oder -5. Leer = eingestellte Schrittweite.',
        'volume_up'   => 'Lauter um die Schrittweite.',
        'volume_down' => 'Leiser um die Schrittweite.',
        'mute'        => 'Stumm: 1 ein, 0 aus.',
        'next'        => 'N&auml;chster Titel, wenn die App das unterst&uuml;tzt.',
        'prev'        => 'Vorheriger Titel.',
        'seek'        => 'An Position springen, in Sekunden.',
    );
}

/** UDP-Eingangsport des MQTT-Gateways (Relay-Weg). Beide Schreibweisen. */
function cc_mqtt_udpinport()
{
    $f = cc_paths()['home'] . '/config/system/general.json';
    if (!is_file($f)) {
        return 0;
    }
    $j = @json_decode((string) @file_get_contents($f), true);
    if (!is_array($j)) {
        return 0;
    }
    foreach (array('Mqtt', 'mqtt') as $a) {
        foreach (array('Udpinport', 'udpinport') as $k) {
            if (!empty($j[$a][$k])) {
                return (int) $j[$a][$k];
            }
        }
    }
    return 0;
}

/** Adresse des MQTT-Brokers, nur zur Anzeige, ohne Kennwort. */
function cc_mqtt_broker()
{
    $f = cc_paths()['home'] . '/config/system/general.json';
    if (!is_file($f)) {
        return '';
    }
    $j = @json_decode((string) @file_get_contents($f), true);
    if (!is_array($j)) {
        return '';
    }
    foreach (array('Mqtt', 'mqtt') as $a) {
        foreach (array('Brokerhost', 'brokerhost') as $h) {
            if (!empty($j[$a][$h])) {
                $port = 1883;
                foreach (array('Brokerport', 'brokerport') as $pk) {
                    if (!empty($j[$a][$pk])) {
                        $port = (int) $j[$a][$pk];
                    }
                }
                return $j[$a][$h] . ':' . $port;
            }
        }
    }
    return '';
}

/** Lokale IP des LoxBerry. */
function cc_localip()
{
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'get_localip')) {
        $ip = @LBSystem::get_localip();
        if ($ip) {
            return $ip;
        }
    }
    $out = array();
    @exec('hostname -I 2>/dev/null', $out);
    if ($out) {
        $teile = preg_split('/\s+/', trim($out[0]));
        if ($teile && filter_var($teile[0], FILTER_VALIDATE_IP)) {
            return $teile[0];
        }
    }
    return '127.0.0.1';
}

/** Laeuft der Dienst? Rueckgabe: PID oder 0. */
function cc_dienst_pid()
{
    $out = array();
    @exec('pgrep -o -f chromecast4lox-server 2>/dev/null', $out);
    return $out ? (int) $out[0] : 0;
}

/** Dienst starten, stoppen, neu starten. */
function cc_dienst($aktion)
{
    $p = cc_paths();
    $skript = $p['bindir'] . '/chromecast4lox-server.py';
    $meldungen = array();

    if (in_array($aktion, array('stop', 'restart'), true)) {
        @exec('pkill -f chromecast4lox-server 2>&1', $meldungen);
        sleep(1);
    }
    if (in_array($aktion, array('start', 'restart'), true)) {
        if (!is_file($skript)) {
            return 'Dienst nicht gefunden: ' . $skript;
        }
        $log = $p['logdir'] . '/' . $p['plugin'] . '.log';
        @exec('nohup ' . escapeshellarg($skript) . ' >> ' . escapeshellarg($log)
            . ' 2>&1 & echo gestartet', $meldungen);
        sleep(2);
    }
    return implode("\n", $meldungen);
}

/** Logdatei-Kandidaten. */
function cc_log_file()
{
    $p = cc_paths();
    $c = glob($p['logdir'] . '/*.log');
    if (!$c) {
        return '';
    }
    usort($c, function ($a, $b) { return filemtime($b) - filemtime($a); });
    return $c[0];
}

/** Die letzten N Zeilen einer Datei, neueste zuerst. */
function cc_log_tail($file, $max = 300)
{
    if ($file === '' || !is_file($file)) {
        return array();
    }
    $lines = preg_split('/\R/', (string) @file_get_contents($file));
    $lines = array_values(array_filter($lines, function ($l) { return trim($l) !== ''; }));
    return array_reverse(array_slice($lines, -$max));
}

/* ==================================================================
 * Loxone-Vorlagen
 *
 * Nachbau der Bausteine aus LoxBerry::LoxoneTemplateBuilder. Das Modul
 * gibt es nur in Perl; die Ausgabe hier ist Byte fuer Byte gegen das
 * Original geprueft worden - gleiche Attributreihenfolge, CRLF als
 * Zeilenende, Tabulator vor den Kindelementen.
 * ================================================================== */

function cc_x($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/** Virtueller HTTP-Eingang - traegt die MQTT-Themennamen. */
function cc_xml_virtual_in_http($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInHttp ';
    $o .= 'Title="' . cc_x($kopf['title']) . '" ';
    $o .= 'Comment="' . cc_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . cc_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'PollingTime="' . cc_x(isset($kopf['polling']) ? $kopf['polling'] : '60') . '"';
    $o .= '>' . $crlf;
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualInHttpCmd ';
        $o .= 'Title="' . cc_x($c['title']) . '" ';
        $o .= 'Comment="' . cc_x(isset($c['comment']) ? $c['comment'] : '') . '" ';
        $o .= 'Check="' . cc_x(isset($c['check']) ? $c['check'] : ' ') . '" ';
        $o .= 'Signed="true" ';
        $o .= 'Analog="' . (isset($c['analog']) && !$c['analog'] ? 'false' : 'true') . '" ';
        $o .= 'SourceValLow="0" ';
        $o .= 'DestValLow="0" ';
        $o .= 'SourceValHigh="100" ';
        $o .= 'DestValHigh="100" ';
        $o .= 'DefVal="0" ';
        $o .= 'MinVal="-2147483647" ';
        $o .= 'MaxVal="2147483647"';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return $o;
}

/** Virtueller Ausgang. */
function cc_xml_virtual_out($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualOut ';
    $o .= 'Title="' . cc_x($kopf['title']) . '" ';
    $o .= 'Comment="' . cc_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . cc_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'CmdInit="" ';
    $o .= 'CloseAfterSend="true" ';
    $o .= 'CmdSep="" ';
    $o .= '>' . $crlf;
    $id = 0;
    foreach ($cmds as $c) {
        $id++;
        $o .= "\t" . '<VirtualOutCmd ';
        $o .= 'ID="' . $id . '" ';
        $o .= 'Title="' . cc_x($c['title']) . '" ';
        $o .= 'Comment="' . cc_x(isset($c['comment']) ? $c['comment'] : '') . '" ';
        $o .= 'CmdOnMethod="GET" ';
        $o .= 'CmdOn="' . cc_x(isset($c['on']) ? $c['on'] : '') . '" ';
        $o .= 'CmdOnHTTP="" ';
        $o .= 'CmdOnPost="" ';
        $o .= 'CmdOffMethod="GET" ';
        $o .= 'CmdOff="" ';
        $o .= 'CmdOffHTTP="" ';
        $o .= 'CmdOffPost="" ';
        $o .= 'Analog="' . (isset($c['analog']) && $c['analog'] ? 'true' : 'false') . '" ';
        $o .= 'Repeat="0" ';
        $o .= 'RepeatRate="0"';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualOut>' . $crlf;
    return $o;
}

/** Virtueller UDP-Eingang - nur fuer den UDP-Rueckfallweg. */
function cc_xml_virtual_out_udp($kopf, $cmds)
{
    return cc_xml_virtual_out($kopf, $cmds);
}

/**
 * Vorlage erzeugen.
 * $art: mqtt_in | mqtt_out | udp_out
 * Rueckgabe: array(dateiname, inhalt)
 */
function cc_vorlage($art, $cfg, $geraete)
{
    $praefix = cc_cfg($cfg, 'themenpraefix', 'chromecast4lox');
    $ip = cc_localip();
    $fuss = 'Erzeugt vom LoxBerry-Plugin Chromecast 4 Lox (' . date('d.m.Y') . ')';

    if ($art === 'mqtt_in') {
        $cmds = array(array(
            'title'   => $praefix . '_server_online',
            'comment' => 'Dienst laeuft',
            'check'   => ' ',
        ));
        foreach ($geraete as $g) {
            $t = cc_thema($g);
            foreach (cc_status_themen() as $schluessel => $info) {
                $cmds[] = array(
                    'title'   => $praefix . '_' . $t . '_' . $schluessel,
                    'comment' => $g . ' - ' . strip_tags(html_entity_decode($info[0], ENT_QUOTES, 'UTF-8')),
                    'check'   => ' ',
                );
            }
        }
        return array('chromecast_mqtt_eingaenge.xml', cc_xml_virtual_in_http(array(
            'title'   => 'Chromecast 4 Lox',
            'address' => 'http://localhost',
            'polling' => '604800',
            'comment' => $fuss,
        ), $cmds));
    }

    if ($art === 'mqtt_out') {
        $port = cc_mqtt_udpinport();
        $cmds = array();
        foreach ($geraete as $g) {
            $t = cc_thema($g);
            foreach (cc_befehle() as $befehl => $erklaerung) {
                $thema = $praefix . '/' . $t . '/cmd/' . $befehl;
                $analog = in_array($befehl, array('volume', 'volume_step', 'seek', 'mute'), true);
                $cmds[] = array(
                    'title'   => $praefix . '_' . $t . '_' . $befehl,
                    'comment' => $g . ' - ' . strip_tags(html_entity_decode($erklaerung, ENT_QUOTES, 'UTF-8')),
                    'on'      => $thema . ' ' . ($analog ? '<v>' : '1'),
                    'analog'  => $analog,
                );
            }
        }
        return array('chromecast_mqtt_ausgaenge.xml', cc_xml_virtual_out(array(
            'title'   => 'Chromecast 4 Lox',
            'address' => '/dev/udp/' . $ip . '/' . $port,
            'comment' => $fuss,
        ), $cmds));
    }

    if ($art === 'udp_out') {
        $port = (int) cc_cfg($cfg, 'udp_port', '7090');
        $cmds = array();
        foreach ($geraete as $g) {
            $t = cc_thema($g);
            foreach (cc_befehle() as $befehl => $erklaerung) {
                $analog = in_array($befehl, array('volume', 'volume_step', 'seek', 'mute'), true);
                $cmds[] = array(
                    'title'   => $t . '_' . $befehl,
                    'comment' => $g . ' - ' . strip_tags(html_entity_decode($erklaerung, ENT_QUOTES, 'UTF-8')),
                    'on'      => $t . '/' . strtoupper($befehl) . ($analog ? ' <v>' : '') . ';',
                    'analog'  => $analog,
                );
            }
        }
        return array('chromecast_udp_ausgaenge.xml', cc_xml_virtual_out(array(
            'title'   => 'Chromecast 4 Lox UDP',
            'address' => '/dev/udp/' . $ip . '/' . $port,
            'comment' => $fuss,
        ), $cmds));
    }

    return array('', '');
}
