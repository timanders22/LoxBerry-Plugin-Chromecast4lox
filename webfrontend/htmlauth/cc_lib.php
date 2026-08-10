<?php
/**
 * Chromecast 4 Lox NG - gemeinsame Hilfsfunktionen
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

/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins UND webfrontend enthaelt. Das trifft die uebliche
 * Installation genauso wie eine an einem anderen Ort - und es trifft auch
 * den Fall, dass das Plugin noch als entpacktes Archiv daliegt (dann findet
 * es nichts und gibt einen Leerstring zurueck, was der Aufrufer ohnehin
 * abfangen muss).
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert: zwei
 * Bibliotheken landen nie im selben Prozess, aber die Pruefung kostet nichts.
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/webfrontend')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

function cc_paths()
{
    static $p = null;
    if ($p !== null) {
        return $p;
    }
    $home = getenv('LBHOMEDIR');
    if (!$home) {
        $home = lb_wurzel_ermitteln();
    }
    $dir = getenv('LBPPLUGINDIR');
    if (!$dir) {
        $dir = basename(dirname(dirname(__DIR__)));
    }
    if ($home && !is_dir($home . '/config/plugins/' . $dir)) {
        foreach (array(basename(dirname(__DIR__)), 'chromecast-4lox-ng') as $cand) {
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
            'bindir'  => $home . '/bin/plugins/' . $dir,
            'logdir'  => $home . '/log/plugins/' . $dir,
            'datadir' => $home . '/data/plugins/' . $dir,
        );
    } else {
        $base = dirname(dirname(__DIR__));
        $p = array(
            'home'   => '',
            'plugin' => $dir,
            'config' => $base . '/config/chromecast-4lox-ng.cfg',
            'bindir'  => $base . '/bin',
            'logdir'  => sys_get_temp_dir(),
            'datadir' => sys_get_temp_dir(),
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
        // --- Ansage (TTS). Feldnamen und Bedeutung wie im
        // Abfahrtsassistenten 1.5.0 - wer dort eingerichtet hat, traegt
        // hier dasselbe ein.
        'tts_modus'           => 'chromecast',
        'tts_ip'              => '',
        'tts_port'            => '7091',
        'tts_zonen'           => '1',
        'tts_lautstaerke'     => '8',
        'tts_sprache'         => 'de',
        'tts_vorlage'         => '',
        'tts_pegel'           => '',
        'tts_fortsetzen'      => '1',
        'gruppen'             => '1',
    );
}

/** Die Ausgabewege der Ansage. Dieselben wie im Abfahrtsassistenten,
 *  plus 'chromecast' - denn ein Chromecast nimmt keine TTS-Adresse
 *  entgegen, sondern spielt eine Audiodatei ab. */
function cc_tts_modi()
{
    return array(
        'chromecast'  => 'TTS.M_CHROMECAST',
        'musicserver' => 'TTS.M_MUSICSERVER',
        'ms4h'        => 'TTS.M_MS4H',
        'audioserver' => 'TTS.M_AUDIOSERVER',
        'custom'      => 'TTS.M_CUSTOM',
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
    $txt = "; Chromecast 4 Lox NG\n; " . date('D M j H:i:s Y') . "\n\n[CONFIG]\n";
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
    // Umschrift OHNE iconv.
    //
    // Bis 1.1.0 stand hier iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE'). Das
    // Ergebnis haengt an der Locale des Webservers: unter LC_ALL=C macht
    // glibc aus einem unbekannten Zeichen ein '?', mit //IGNORE faellt es
    // ganz weg - Python dagegen laesst es stehen, und die Regex weiter
    // unten macht daraus einen Unterstrich. Aus 'Ræv' wuerde dann in PHP
    // 'Rv' und in Python 'R_v'. Die Loxone-Vorlage lauschte auf ein Thema,
    // das der Dienst nie sendet, und niemand saehe warum.
    //
    // Deshalb eine feste Tabelle: sie kennt genau die vorgezeichneten
    // lateinischen Buchstaben, deren Zerlegung auch Python vornimmt
    // (NFKD, dann Wegfall der kombinierenden Zeichen). Alles andere bleibt
    // stehen und wird - wie in Python - zu einem Unterstrich.
    $name = strtr($name, cc_umschrift());
    $name = preg_replace('/[^A-Za-z0-9_-]+/', '_', $name);
    $name = trim($name, '_');
    return $name !== '' ? $name : 'geraet';
}

/**
 * Vorgezeichnete lateinische Buchstaben auf ihren Grundbuchstaben.
 *
 * Das ist genau das, was unicodedata.normalize('NFKD', ...) plus Wegfall
 * der kombinierenden Zeichen im Python-Dienst tut - nur ohne Abhaengigkeit
 * von iconv und der Locale. Zeichen, die keine solche Zerlegung haben
 * (aeaeae, oe, Thorn, Eszett und alles Nichtlateinische), stehen bewusst
 * NICHT in der Tabelle: Python laesst sie ebenfalls stehen, und beide
 * Seiten machen daraus denselben Unterstrich.
 *
 * Die Selbstpruefung im Reiter Test haelt beide Verfahren gegeneinander -
 * an den Namen, die wirklich eingetragen sind.
 */
function cc_umschrift()
{
    static $t = null;
    if ($t !== null) {
        return $t;
    }
    $t = array(
        'À'=>'A','Á'=>'A','Â'=>'A','Ã'=>'A','Å'=>'A','Ā'=>'A','Ă'=>'A','Ą'=>'A',
        'à'=>'a','á'=>'a','â'=>'a','ã'=>'a','å'=>'a','ā'=>'a','ă'=>'a','ą'=>'a',
        'Ç'=>'C','Ć'=>'C','Ĉ'=>'C','Ċ'=>'C','Č'=>'C',
        'ç'=>'c','ć'=>'c','ĉ'=>'c','ċ'=>'c','č'=>'c',
        'Ď'=>'D','ď'=>'d',
        'È'=>'E','É'=>'E','Ê'=>'E','Ë'=>'E','Ē'=>'E','Ĕ'=>'E','Ė'=>'E','Ę'=>'E','Ě'=>'E',
        'è'=>'e','é'=>'e','ê'=>'e','ë'=>'e','ē'=>'e','ĕ'=>'e','ė'=>'e','ę'=>'e','ě'=>'e',
        'Ĝ'=>'G','Ğ'=>'G','Ġ'=>'G','Ģ'=>'G','ĝ'=>'g','ğ'=>'g','ġ'=>'g','ģ'=>'g',
        'Ĥ'=>'H','ĥ'=>'h',
        'Ì'=>'I','Í'=>'I','Î'=>'I','Ï'=>'I','Ĩ'=>'I','Ī'=>'I','Ĭ'=>'I','Į'=>'I','İ'=>'I',
        'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i','ĩ'=>'i','ī'=>'i','ĭ'=>'i','į'=>'i',
        'Ĵ'=>'J','ĵ'=>'j','Ķ'=>'K','ķ'=>'k',
        'Ĺ'=>'L','Ļ'=>'L','Ľ'=>'L','ĺ'=>'l','ļ'=>'l','ľ'=>'l',
        'Ñ'=>'N','Ń'=>'N','Ņ'=>'N','Ň'=>'N','ñ'=>'n','ń'=>'n','ņ'=>'n','ň'=>'n',
        'Ò'=>'O','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ō'=>'O','Ŏ'=>'O','Ő'=>'O',
        'ò'=>'o','ó'=>'o','ô'=>'o','õ'=>'o','ō'=>'o','ŏ'=>'o','ő'=>'o',
        'Ŕ'=>'R','Ŗ'=>'R','Ř'=>'R','ŕ'=>'r','ŗ'=>'r','ř'=>'r',
        'Ś'=>'S','Ŝ'=>'S','Ş'=>'S','Š'=>'S','ś'=>'s','ŝ'=>'s','ş'=>'s','š'=>'s',
        'Ţ'=>'T','Ť'=>'T','ţ'=>'t','ť'=>'t',
        'Ù'=>'U','Ú'=>'U','Û'=>'U','Ũ'=>'U','Ū'=>'U','Ŭ'=>'U','Ů'=>'U','Ű'=>'U','Ų'=>'U',
        'ù'=>'u','ú'=>'u','û'=>'u','ũ'=>'u','ū'=>'u','ŭ'=>'u','ů'=>'u','ű'=>'u','ų'=>'u',
        'Ŵ'=>'W','ŵ'=>'w','Ý'=>'Y','Ŷ'=>'Y','Ÿ'=>'Y','ý'=>'y','ŷ'=>'y','ÿ'=>'y',
        'Ź'=>'Z','Ż'=>'Z','Ž'=>'Z','ź'=>'z','ż'=>'z','ž'=>'z',
    );
    return $t;
}

/** Alle Zustandsthemen eines Geraets, mit Erklaerung. */
function cc_status_themen()
{
    return array(
        'online'   => array('Erreichbar (1/0)', 'digital'),
        'type'     => array('Art: cast, audio oder group', 'text'),
        'group'    => array('Lautsprechergruppe (1/0)', 'digital'),
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
        'play'        => 'Wiedergabe fortsetzen. Enth&auml;lt die Nutzlast eine Adresse mit <span class="sm-mono">://</span>, wird stattdessen dieses Medium gestartet.',
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
        'tts'         => 'Ansage sprechen. Die Nutzlast ist der Text. Danach wird '
                       . 'fortgesetzt, was vorher lief (abschaltbar).',
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

function cc_pid_datei()
{
    return cc_paths()['datadir'] . '/dienst.pid';
}

/**
 * Laeuft der Dienst? Rueckgabe: PID oder 0.
 *
 * Zuerst die PID-Datei, und die eingetragene Nummer wird gegen
 * /proc/<pid>/cmdline gehalten. Die Suche nach dem Namen bleibt als
 * Rueckfallebene fuer einen Dienst, der noch vor 1.2.0 gestartet wurde.
 *
 * Warum nicht einfach pgrep: 'pgrep -f chromecast4lox_ng-server' trifft jede
 * Befehlszeile, in der diese Zeichenkette vorkommt - ein Editor auf der
 * Datei genuegt -, und '-o' nimmt davon den AELTESTEN Treffer, also
 * womoeglich genau den fremden. Die Oberflaeche zeigte dann einen
 * laufenden Dienst, den es nicht gibt, und 'pkill -f' haette den fremden
 * Prozess erwischt.
 */
function cc_dienst_pid()
{
    $datei = cc_pid_datei();
    if (is_file($datei)) {
        $pid = (int) trim((string) @file_get_contents($datei));
        if ($pid > 0 && is_dir('/proc/' . $pid)) {
            $cmd = (string) @file_get_contents('/proc/' . $pid . '/cmdline');
            if (strpos($cmd, 'chromecast4lox_ng-server') !== false) {
                return $pid;
            }
        }
        @unlink($datei);   // zeigt ins Leere - von einem Absturz uebrig
    }
    $out = array();
    @exec('pgrep -o -f "[c]hromecast4lox_ng-server" 2>/dev/null', $out);
    $pid = $out ? (int) $out[0] : 0;
    if ($pid > 0 && is_dir('/proc/' . $pid)) {
        $cmd = (string) @file_get_contents('/proc/' . $pid . '/cmdline');
        if (strpos($cmd, 'chromecast4lox_ng-server') !== false
            && strpos($cmd, 'python') !== false) {
            return $pid;
        }
    }
    return 0;
}

/** Dienst starten, stoppen, neu starten. */
function cc_dienst($aktion)
{
    $p = cc_paths();
    $skript = $p['bindir'] . '/chromecast4lox_ng-server.py';
    $meldungen = array();

    $datei = cc_pid_datei();
    if (in_array($aktion, array('stop', 'restart'), true)) {
        $pid = cc_dienst_pid();
        if ($pid > 0) {
            @exec('kill ' . (int) $pid . ' 2>&1', $meldungen);
            // Zeit lassen: der Dienst meldet server/online=0 und die Geraete
            // offline. Hart abgeschossen bliebe im Broker ein retained '1'
            // stehen, und Loxone glaubte weiter an einen laufenden Dienst.
            for ($i = 0; $i < 20; $i++) {
                usleep(500000);
                if (cc_dienst_pid() === 0) {
                    break;
                }
            }
            if (cc_dienst_pid() === $pid) {
                @exec('kill -9 ' . (int) $pid . ' 2>&1', $meldungen);
                usleep(500000);
                $meldungen[] = 'Der Dienst reagierte nicht auf SIGTERM und wurde abgeschossen.';
            }
        } else {
            $meldungen[] = 'Es lief kein Dienst.';
        }
        @unlink($datei);
    }
    if (in_array($aktion, array('start', 'restart'), true)) {
        if (!is_file($skript)) {
            return 'Dienst nicht gefunden: ' . $skript;
        }
        $log = $p['logdir'] . '/' . $p['plugin'] . '.log';
        @mkdir(dirname($datei), 0775, true);
        @exec('nohup ' . escapeshellarg($skript) . ' >> ' . escapeshellarg($log)
            . ' 2>&1 & echo $! > ' . escapeshellarg($datei) . '; echo gestartet', $meldungen);
        for ($i = 0; $i < 16; $i++) {
            usleep(500000);
            if (cc_dienst_pid() > 0) {
                break;
            }
        }
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
    $fuss = 'Erzeugt vom LoxBerry-Plugin Chromecast 4 Lox NG (' . date('d.m.Y') . ')';

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

/* ==================================================================
 * Sprache (Pflicht: Deutsch und Englisch)
 *
 * Englisch ist die Rueckfallebene, nicht Deutsch: wer eine dritte Sprache
 * eingestellt hat, versteht eher Englisch. Deshalb muss language_en.ini
 * immer vollstaendig sein.
 * ================================================================== */

function cc_sprache()
{
    $sprache = 'de';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'lblanguage')) {
        $sprache = LBSystem::lblanguage();
    } elseif (getenv('LBLANG')) {
        $sprache = getenv('LBLANG');
    }
    $sprache = strtolower(substr((string) $sprache, 0, 2));
    return in_array($sprache, array('de', 'en'), true) ? $sprache : 'en';
}

/**
 * Text zu einem Schluessel "ABSCHNITT.SCHLUESSEL".
 *
 * Ist der Schluessel unbekannt, wird er selbst zurueckgegeben - so faellt
 * beim Durchsehen sofort auf, was noch fehlt, statt dass die Seite leer
 * bleibt.
 */
function cc_t($schluessel)
{
    static $texte = null;
    if ($texte === null) {
        // Installiert liegen die Dateien unter
        // <home>/templates/plugins/<ordner>/lang/ - der Ordnername ergibt
        // sich aus dem Ablageort dieser Datei.
        $home = getenv('LBHOMEDIR');
        if (!$home || !is_dir($home)) {
            foreach (array(lb_wurzel_ermitteln(), '/home/loxberry/loxberry') as $k) {
                if (is_dir($k)) { $home = $k; break; }
            }
        }
        $ordner = basename(dirname(__FILE__));
        $pfad = $home . '/templates/plugins/' . $ordner . '/lang';
        if (!is_dir($pfad)) {
            // Nicht installiert (Entwicklung): neben dem Plugin nachsehen.
            $pfad = dirname(dirname(dirname(__FILE__))) . '/templates/lang';
        }
        $texte = @parse_ini_file($pfad . '/language_' . cc_sprache() . '.ini',
                                 true, INI_SCANNER_RAW);
        if (!is_array($texte)) { $texte = array(); }
        $rueck = @parse_ini_file($pfad . '/language_en.ini', true, INI_SCANNER_RAW);
        if (is_array($rueck)) { $texte = array_replace_recursive($rueck, $texte); }
        // parse_ini_file mit INI_SCANNER_RAW liefert die Werte samt der
        // Anfuehrungszeichen zurueck, in die sie in der Datei stehen muessen.
        // Die gehoeren nicht in die Ausgabe.
        foreach ($texte as $ab => $paare) {
            if (!is_array($paare)) { continue; }
            foreach ($paare as $s => $w) {
                $texte[$ab][$s] = trim((string) $w, '"');
            }
        }
    }
    list($a, $s) = array_pad(explode('.', $schluessel, 2), 2, '');
    return isset($texte[$a][$s]) ? $texte[$a][$s] : $schluessel;
}
