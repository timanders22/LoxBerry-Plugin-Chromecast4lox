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
        // Der Rueckfall auf den vorgesehenen Ordnernamen wird NUR genommen,
        // wenn dort noch nichts liegt oder schon die EIGENE
        // Konfigurationsdatei steht.
        //
        // Grund: zwei Plugins duerfen denselben FOLDER beanspruchen. LoxBerry
        // bildet die Kennung aus Autorenname, E-Mail und Plugin-Name und
        // haelt sie dann fuer verschiedene Plugins - der zweite bekommt "01"
        // an den Ordner. Ein harter Rueckfall zeigte dann in das Verzeichnis
        // des FREMDEN Plugins und legte dort eine Konfiguration an, die
        // niemand liest.
        foreach (array(basename(dirname(__DIR__)), 'chromecast-4lox-ng') as $cand) {
            $ort = $home . '/config/plugins/' . $cand;
            if (!is_dir($ort)) {
                continue;
            }
            $eigene = $ort . '/' . $cand . '.cfg';
            $inhalt = @scandir($ort);
            $leer = !is_array($inhalt)
                || count(array_diff($inhalt, array('.', '..'))) === 0;
            if ($leer || is_file($eigene)) {
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

/**
 * Die Vorgabewerte - aus bin/cc_vorgaben.json, die auch der Dienst liest.
 *
 * Bis 1.3.0 stand hier eine eigene Liste, im Dienst eine zweite und in der
 * ausgelieferten Konfiguration eine dritte: 28, 26 und 27 Schluessel. Die
 * Werte widersprachen sich nicht, aber nichts hielt sie zusammen - und
 * 'beschleunigung' fehlte in der ausgelieferten Datei ganz.
 *
 * Fail closed: laesst sich die Datei nicht lesen, kommt eine LEERE Liste
 * zurueck. cc_config_write() schreibt dann nicht, und cc_config_read()
 * liefert nur, was in der Konfiguration steht. Jede Aufrufstelle von
 * cc_cfg() nennt ihre eigene Vorgabe - die Oberflaeche zeigt also weiter
 * richtige Werte an, statt eine geratene Liste ueber die Einstellungen des
 * Anwenders zu schreiben.
 */
function cc_defaults()
{
    static $v = null;
    if ($v !== null) {
        return $v;
    }
    $v = array();
    $orte = array(cc_paths()['bindir'] . '/cc_vorgaben.json',
                  dirname(dirname(__DIR__)) . '/bin/cc_vorgaben.json');
    foreach ($orte as $pfad) {
        if (!is_file($pfad)) {
            continue;
        }
        $d = json_decode((string) @file_get_contents($pfad), true);
        if (is_array($d) && !empty($d['vorgaben']) && is_array($d['vorgaben'])) {
            $v = array_map('strval', $d['vorgaben']);
            break;
        }
    }
    return $v;
}

/**
 * Nur, was WIRKLICH in der Datei steht - ohne Vorgaben.
 *
 * cc_config_read() mischt die Vorgaben unter; auf dessen Ergebnis ist
 * "fehlt" von "steht auf dem Vorgabewert" nicht zu unterscheiden. Genau
 * diesen Unterschied brauchen cc_cfg_vervollstaendigen() und die
 * Pruefzeile im Reiter Test.
 */
function cc_config_roh()
{
    $out = array();
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

/**
 * Fehlende Schluessel EINMAL in die Datei schreiben.
 *
 * Ergaenzen hiesse: beim Lesen tritt fuer einen fehlenden Schluessel seine
 * Vorgabe ein, die Datei bleibt lueckenhaft, und "fehlt" ist von "steht auf
 * dem Vorgabewert" nicht mehr zu unterscheiden.
 *
 * Geprueft wird mit array_key_exists(), NICHT mit isset(): isset() haelt
 * einen leeren Wert fuer nicht vorhanden, und die Haelfte dieser Vorgaben
 * IST leer - eine bewusst geleerte Angabe wuerde bei jedem Lauf
 * zurueckgeschrieben.
 *
 * Geschrieben wird nur ueber eine heile Datei. Gibt es sie noch gar nicht,
 * legt sie cc_aktionstoken() beim ersten Aufruf vollstaendig an.
 *
 * Rueckgabe: welche Schluessel gefehlt haben.
 */
function cc_cfg_vervollstaendigen(&$cfg)
{
    $vorgaben = cc_defaults();
    if (!$vorgaben) {
        return array();
    }
    $roh = cc_config_roh();
    $fehlten = array();
    foreach ($vorgaben as $k => $v) {
        if (!array_key_exists($k, $roh)) {
            $fehlten[] = $k;
            if (!array_key_exists($k, $cfg)) {
                $cfg[$k] = $v;
            }
        }
    }
    if ($fehlten && cc_config_zustand() === 'ok') {
        cc_config_write($cfg);
    }
    return $fehlten;
}

/** Die Ausgabewege der Ansage. Dieselben wie im Abfahrtsassistenten,
 *  plus 'chromecast' - denn ein Chromecast nimmt keine TTS-Adresse
 *  entgegen, sondern spielt eine Audiodatei ab. */
function cc_tts_modi()
{
    return array(
        'chromecast'  => 'TTS.M_CHROMECAST',
        'lokal'       => 'TTS.M_LOKAL',
        'musicserver' => 'TTS.M_MUSICSERVER',
        'ms4h'        => 'TTS.M_MS4H',
        'audioserver' => 'TTS.M_AUDIOSERVER',
        'custom'      => 'TTS.M_CUSTOM',
    );
}

/**
 * Zustand der Konfigurationsdatei - jeder Fall bekommt seinen Namen.
 *
 * 'fehlt'    Es gibt sie nicht. Das ist der Zustand einer frischen
 *            Installation, kein Fehler: es gelten die Vorgabewerte.
 * 'leer'     Sie ist da und hat 0 Byte.
 * 'unlesbar' Sie ist da und laesst sich nicht lesen (Rechte, Datentraeger).
 * 'ok'       Sie ist da und lesbar.
 *
 * Der Unterschied ist nicht kosmetisch: bis 1.2.12 gab cc_config_read() in
 * allen vier Faellen dieselbe Werkseinstellung zurueck, und cc_config_write()
 * haette sie danach ueber die unlesbare Datei geschrieben. Geraeteliste,
 * Themenpraefix und die ganze Ansageeinrichtung waeren fort gewesen, ohne
 * dass irgendwo etwas gestanden haette.
 */
function cc_config_zustand()
{
    $file = cc_paths()['config'];
    if (!is_file($file)) {
        return 'fehlt';
    }
    if (filesize($file) === 0) {
        return 'leer';
    }
    return @file_get_contents($file) === false ? 'unlesbar' : 'ok';
}

/**
 * Konfiguration lesen: Vorgaben, darueber der Dateiinhalt.
 *
 * EINE Leseschleife - sie steht in cc_config_roh(). Zwei Schleifen, die
 * dasselbe Format auslegen, laufen frueher oder spaeter auseinander.
 */
function cc_config_read()
{
    return array_merge(cc_defaults(), cc_config_roh());
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
    // Fail closed: ueber eine vorhandene, aber unlesbare Datei wird NICHT
    // geschrieben. cc_config_read() liefert in diesem Fall die reine
    // Werkseinstellung - sie hier zurueckzuschreiben hiesse, die
    // Einstellungen des Anwenders durch Vorgabewerte zu ersetzen.
    if (cc_config_zustand() === 'unlesbar') {
        return false;
    }
    // Und nicht ohne Vorgabeliste: cc_defaults() bestimmt, WELCHE
    // Schluessel geschrieben werden. Ist sie leer - weil sich
    // bin/cc_vorgaben.json nicht lesen laesst -, entstuende eine Datei
    // ohne einen einzigen Schluessel, und die Einstellungen des Anwenders
    // waeren fort.
    if (!cc_defaults()) {
        return false;
    }
    // is_dir() VOR mkdir(): der Klammeraffe ist stumm, aber nicht
    // folgenlos - ein gesetzter Fehlerbehandler sieht die Warnung
    // trotzdem, und dann steht sie in der Seite.
    if (!is_dir(dirname($file))) {
        @mkdir(dirname($file), 0775, true);
    }
    // KEIN Zeitstempel im Inhalt. Er aenderte sich bei jedem Speichern,
    // und damit meldete jeder Vorher-Nachher-Vergleich einen Unterschied,
    // der keiner ist - mqtt_probe.py hat genau das beanstandet. Wann die
    // Datei geschrieben wurde, sagt ihr Zeitstempel im Dateisystem.
    $txt = "; Chromecast 4 Lox NG\n; Wird von der Plugin-Oberflaeche geschrieben.\n\n[CONFIG]\n";
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

/**
 * Das Aktionstoken. Entsteht beim ersten Aufruf der Oberflaeche.
 *
 * Es wird NICHT angelegt, solange die Konfiguration nicht heil ist: sonst
 * schriebe die Oberflaeche ein Token in eine Datei, die sie gleich darauf
 * nicht mehr lesen kann, und der Wachposten wiese jedes Formular ab.
 */
function cc_aktionstoken()
{
    static $wert = null;
    if ($wert !== null) {
        return $wert;
    }
    $cfg = cc_config_read();
    $t = trim((string) cc_cfg($cfg, 'aktionstoken', ''));
    if ($t !== '') {
        $wert = $t;
        return $wert;
    }
    // Weder ueber eine unlesbare noch ueber eine LEERE Datei schreiben.
    // Eine leere Datei ist genau der Zustand, den postinstall.sh aus der
    // Zweitschrift wiederherstellt; wer die Werkseinstellung darueber
    // schreibt, macht die Zweitschrift wertlos.
    if (in_array(cc_config_zustand(), array('unlesbar', 'leer'), true)) {
        $wert = '';
        return $wert;
    }
    $cfg['aktionstoken'] = bin2hex(random_bytes(16));
    $wert = cc_config_write($cfg) ? $cfg['aktionstoken'] : '';
    return $wert;
}

/**
 * Das Merkmal, das jedes Formular mitfuehrt.
 *
 * Abgeleitet, nicht gespeichert: es gibt damit keinen zweiten Wert, der
 * verlorengehen oder auseinanderlaufen kann.
 *
 * Fail closed: ohne Aktionstoken gibt es kein Merkmal. Ein aus dem
 * Leerstring abgeleiteter Wert waere fuer jeden ausrechenbar und damit kein
 * Schutz, sondern die Behauptung eines Schutzes.
 */
function cc_formtoken()
{
    $t = cc_aktionstoken();
    return $t === '' ? '' : hash_hmac('sha256', 'formular-v1', $t);
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
 * Die Favoritenliste - muss dasselbe liefern wie favoriten() im Dienst.
 * Rueckgabe: Liste von array(name, adresse).
 */
function cc_favoriten($cfg)
{
    $aus = array();
    foreach (preg_split('/[\r\n]+/', (string) cc_cfg($cfg, 'favoriten', '')) as $z) {
        $z = trim($z);
        if ($z === '' || $z[0] === '#' || strpos($z, '=') === false) {
            continue;
        }
        list($name, $adresse) = array_map('trim', explode('=', $z, 2));
        if ($adresse === '') {
            continue;
        }
        $aus[] = array($name !== '' ? $name : $adresse, $adresse);
    }
    return $aus;
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

function cc_themen()
{
    static $j = null;
    if ($j !== null) {
        return $j;
    }
    // Die Datei liegt bei bin/, weil der Dienst sie ebenfalls liest. Im
    // entpackten Archiv liegt bin/ zwei Ebenen ueber dieser Datei.
    foreach (array(cc_paths()['bindir'] . '/cc_themen.json',
                   dirname(dirname(__DIR__)) . '/bin/cc_themen.json') as $pfad) {
        if (!is_file($pfad)) {
            continue;
        }
        $d = json_decode((string) @file_get_contents($pfad), true);
        if (is_array($d) && !empty($d['geraet'])) {
            $j = $d;
            return $j;
        }
    }
    // Fail closed: lieber eine leere Liste als eine geratene. Der Reiter Test
    // meldet das; eine erfundene Liste erzeugte eine Vorlage, die auf Themen
    // lauscht, die der Dienst nie sendet.
    $j = array('fassung' => 0, 'geraet' => array(), 'dienst' => array(),
               'befehle' => array());
    return $j;
}

/**
 * Kurze Beschriftung eines Themas - die wandert als Comment in die
 * Loxone-Vorlage und wird dort zum ANZEIGENAMEN der Kachel. Deshalb kurz:
 * was ueber etwa 40 Zeichen liegt, ist ein Satz und kein Name.
 */
function cc_thema_kurz($schluessel)
{
    $t = cc_t('THEMA.' . strtoupper($schluessel));
    return $t === 'THEMA.' . strtoupper($schluessel) ? $schluessel : $t;
}

/** Ausfuehrliche Erklaerung eines Themas - nur fuer die Oberflaeche. */
function cc_thema_lang($schluessel)
{
    $t = cc_t('THEMA_LANG.' . strtoupper($schluessel));
    return $t === 'THEMA_LANG.' . strtoupper($schluessel)
        ? cc_thema_kurz($schluessel) : $t;
}

/** Angaben zu einem Thema (art, einheit, min, max, retain) oder null. */
function cc_thema_info($bereich, $schluessel)
{
    foreach (cc_themen()[$bereich] as $e) {
        if ($e['schluessel'] === $schluessel) {
            return $e;
        }
    }
    return null;
}

/**
 * Alle Zustandsthemen eines Geraets, mit Erklaerung.
 * Rueckgabe wie bisher: schluessel => array(Erklaerung, Art).
 */
function cc_status_themen()
{
    $out = array();
    foreach (cc_themen()['geraet'] as $e) {
        $out[$e['schluessel']] = array(cc_thema_lang($e['schluessel']), $e['art']);
    }
    return $out;
}

/** Die Themen des Dienstes selbst, unter <praefix>/server/. */
function cc_dienst_themen()
{
    $out = array();
    foreach (cc_themen()['dienst'] as $e) {
        $out[$e['schluessel']] = array(cc_thema_lang('server_' . $e['schluessel']),
                                       $e['art']);
    }
    return $out;
}

/** Alle Befehle, mit Erklaerung. */
function cc_befehle()
{
    $out = array();
    foreach (cc_themen()['befehle'] as $e) {
        $out[$e['schluessel']] = cc_thema_lang('cmd_' . $e['schluessel']);
    }
    return $out;
}

/** Ist dieser Befehl analog (traegt also einen Wert)? */
function cc_befehl_analog($schluessel)
{
    $e = cc_thema_info('befehle', $schluessel);
    return $e !== null && $e['art'] === 'analog';
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

/**
 * Autostart UND Fassung des MQTT-Gateways - aus EINEM Dateizugriff.
 *
 * Der Hausstandard verlangt im Reiter "Einbindung in Loxone" den Satz
 * "Ohne diesen Eintrag kommt am Miniserver nichts an". Er gilt nur fuer
 * Gateway V1. Gemessen am LoxBerry-Kern
 * (webfrontend/htmlauth/system/mqtt-gateway.cgi, Zweig master):
 *
 *     $gatewayversion = $generaljson->{Mqtt}->{Gatewayversion} // 1;
 *     $template->param("GATEWAY_V2", $gatewayversion == 2 ? 1 : 0);
 *     $template->param("FORM_DISABLE_BUTTONS", 1) if $gatewayversion == 2;
 *
 * Unter V2 schaltet der Kern auf der Abonnement-Seite die Knoepfe ab - von
 * Hand eintragen kann man dort nichts mehr.
 *
 * Rueckgabe: null, wenn sich nichts lesen laesst. Sonst
 *   'autostart' => bool
 *   'fassung'   => int, 0 = nicht lesbar. NICHT auf 1 vorbelegen:
 *                  "unbekannt" und "Fassung 1" sind verschiedene Aussagen,
 *                  und die Oberflaeche behandelt sie verschieden.
 */
function cc_mqtt_gateway_info()
{
    static $wert = null;
    static $gelesen = false;
    if ($gelesen) {
        return $wert;
    }
    $gelesen = true;
    $home = cc_paths()['home'];
    if ($home === '') {
        return $wert;
    }
    $d = @json_decode((string) @file_get_contents(
        $home . '/config/system/general.json'), true);
    if (!is_array($d) || !isset($d['Mqtt']) || !is_array($d['Mqtt'])) {
        return $wert;
    }
    $auto = isset($d['Mqtt']['Gatewayautostart']) ? $d['Mqtt']['Gatewayautostart'] : '';
    $wert = array(
        // Wortlaut der Autostart-Warnung: siehe REGELN_2,
        // "Gateway-Autostart: ein Schluessel, ein Wortlaut".
        'autostart' => in_array((string) $auto, array('1', 'true'), true),
        'fassung'   => isset($d['Mqtt']['Gatewayversion'])
            ? (int) $d['Mqtt']['Gatewayversion'] : 0,
    );
    return $wert;
}

/** Die Fassung allein - 0 heisst "nicht lesbar", nicht "Fassung 1". */
function cc_gateway_fassung()
{
    $g = cc_mqtt_gateway_info();
    return $g === null ? 0 : (int) $g['fassung'];
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

/**
 * Den Schalter setzen, den der Waechter liest.
 *
 * Ohne ihn waere "Dienst anhalten" nach spaetestens fuenf Minuten
 * wirkungslos gewesen: der Waechter haette den Dienst wieder angeworfen.
 */
function cc_dienst_schalter($an)
{
    $cfg = cc_config_read();
    $cfg['enabled'] = $an ? '1' : '0';
    return cc_config_write($cfg);
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

/**
 * Geraete im Netz suchen, maschinenlesbar.
 *
 * Rueckgabe: array(liste, fehlertext). Eine leere Liste mit leerem
 * Fehlertext heisst "nichts gefunden" - das ist etwas anderes als "die
 * Suche ist gescheitert", und beides bekommt seinen eigenen Satz.
 */
function cc_suche($ohne_gruppen = false)
{
    $skript = cc_paths()['bindir'] . '/cc_discover.py';
    if (!is_file($skript)) {
        return array(array(), 'cc_discover.py: ' . $skript);
    }
    $roh = array();
    $rc = 0;
    @exec('timeout 30 python3 ' . escapeshellarg($skript) . ' --json'
          . ($ohne_gruppen ? ' --ohne-gruppen' : '') . ' 2>&1', $roh, $rc);
    $text = trim(implode("\n", $roh));
    // Den Rueckgabewert auswerten, nicht nur die Ausgabe: eine leere
    // Ausgabe bei einem Abbruch ist kein "nichts gefunden", sondern das
    // Gegenteil.
    if ($rc !== 0) {
        return array(array(), $text !== '' ? $text : ('Rueckgabewert ' . $rc));
    }
    $d = json_decode($text, true);
    if (!is_array($d)) {
        return array(array(), $text);
    }
    return array($d, '');
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
    $o .= 'HintText="' . cc_x(isset($kopf['hint']) ? $kopf['hint'] : '') . '" ';
    $o .= 'Title="' . cc_x($kopf['title']) . '" ';
    $o .= 'Comment="' . cc_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . cc_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'PollingTime="' . cc_x(isset($kopf['polling']) ? $kopf['polling'] : '60') . '"';
    $o .= '>' . $crlf;
    // Das <Info>-Element steht als ERSTES Kindelement. templateType
    // unterscheidet die Bauformen: 1 = UDP-Eingang, 2 = HTTP-Eingang,
    // 3 = Ausgang.
    $o .= "\t" . '<Info templateType="2" minVersion="17010727"/>' . $crlf;
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
        // Echte Grenzen statt +-2147483647: Loxone zieht daraus die
        // Reglergrenzen und die Plausibilitaetspruefung.
        $o .= 'MinVal="' . cc_x(isset($c['min']) ? $c['min'] : 0) . '" ';
        $o .= 'MaxVal="' . cc_x(isset($c['max']) ? $c['max'] : 100) . '" ';
        // Ohne Unit steht am Eingang eine nackte Zahl, und die Einheit findet
        // nur, wer den Kommentar aufklappt.
        $o .= 'Unit="' . cc_x(isset($c['unit']) ? $c['unit'] : '') . '" ';
        $o .= 'HintText=""';
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
    $o .= 'HintText="' . cc_x(isset($kopf['hint']) ? $kopf['hint'] : '') . '" ';
    $o .= 'Title="' . cc_x($kopf['title']) . '" ';
    $o .= 'Comment="' . cc_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . cc_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'CmdInit="" ';
    $o .= 'CloseAfterSend="true" ';
    $o .= 'CmdSep=""';
    $o .= '>' . $crlf;
    $o .= "\t" . '<Info templateType="3" minVersion="17010727"/>' . $crlf;
    foreach ($cmds as $c) {
        $analog = isset($c['analog']) && $c['analog'];
        $o .= "\t" . '<VirtualOutCmd ';
        $o .= 'Title="' . cc_x($c['title']) . '" ';
        $o .= 'Comment="' . cc_x(isset($c['comment']) ? $c['comment'] : '') . '" ';
        $o .= 'CmdOnMethod="GET" ';
        $o .= 'CmdOffMethod="GET" ';
        $o .= 'CmdOn="' . cc_x(isset($c['on']) ? $c['on'] : '') . '" ';
        $o .= 'CmdOnHTTP="" ';
        $o .= 'CmdOnPost="" ';
        $o .= 'CmdOff="' . cc_x(isset($c['off']) ? $c['off'] : '') . '" ';
        $o .= 'CmdOffHTTP="" ';
        $o .= 'CmdOffPost="" ';
        $o .= 'CmdAnswer="" ';
        $o .= 'Analog="' . ($analog ? 'true' : 'false') . '" ';
        $o .= 'Repeat="0" ';
        $o .= 'RepeatRate="0" ';
        // Ein ANALOGER Ausgangsbefehl traegt vier Attribute mehr als ein
        // digitaler - gemessen an einer Ausfuhr aus Loxone Config.
        if ($analog) {
            $o .= 'SourceValLow="' . cc_x(isset($c['min']) ? $c['min'] : 0) . '" ';
            $o .= 'DestValLow="' . cc_x(isset($c['min']) ? $c['min'] : 0) . '" ';
            $o .= 'SourceValHigh="' . cc_x(isset($c['max']) ? $c['max'] : 100) . '" ';
            $o .= 'DestValHigh="' . cc_x(isset($c['max']) ? $c['max'] : 100) . '" ';
        }
        $o .= 'HintText=""';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualOut>' . $crlf;
    return $o;
}

/**
 * Das Sammelziel im Themenpfad. Muss zeichengleich zu SAMMELZIEL im Dienst
 * sein - deshalb steht es hier als EINE Zeichenkette und nicht verstreut.
 */
function cc_sammelziel()
{
    return 'alle';
}

/**
 * Vorlage erzeugen.
 * $art: mqtt_in | mqtt_out | udp_out
 * Rueckgabe: array(dateiname, inhalt)
 */
function cc_vorlage($art, $cfg, $geraete)
{
    $praefix = cc_cfg($cfg, 'mqtt_topic', 'chromecast4lox');
    $ip = cc_localip();
    $fuss = 'Erzeugt vom LoxBerry-Plugin Chromecast 4 Lox NG (' . date('d.m.Y') . ')';

    if ($art === 'mqtt_in') {
        $cmds = array();
        // Zuerst der Dienst selbst - Lebenszeichen und Zaehler. Textthemen
        // bleiben auch hier draussen.
        foreach (cc_themen()['dienst'] as $e) {
            if ($e['art'] === 'text') {
                continue;
            }
            $cmds[] = array(
                'title'   => $praefix . '_server_' . $e['schluessel'],
                'comment' => cc_thema_kurz('server_' . $e['schluessel']),
                'check'   => ' ',
                'analog'  => $e['art'] === 'analog',
                'min'     => $e['min'], 'max' => $e['max'],
                'unit'    => $e['einheit'] === '' ? '' : '<v.1> ' . $e['einheit'],
            );
        }
        foreach ($geraete as $g) {
            $t = cc_thema($g);
            foreach (cc_themen()['geraet'] as $e) {
                // Textthemen bekommen KEINEN virtuellen Eingang: das
                // nachgebaute Format ist nur fuer Zahlenwerte belegt, und ein
                // Textthema mit Analog=true zeigt in Loxone dauerhaft 0. Das
                // MQTT-Gateway legt sie beim ersten Empfang selbst an.
                if ($e['art'] === 'text') {
                    continue;
                }
                $cmds[] = array(
                    'title'   => $praefix . '_' . $t . '_' . $e['schluessel'],
                    'comment' => $g . ' - ' . cc_thema_kurz($e['schluessel']),
                    'check'   => ' ',
                    'analog'  => $e['art'] === 'analog',
                    'min'     => $e['min'], 'max' => $e['max'],
                    'unit'    => $e['einheit'] === '' ? '' : '<v.1> ' . $e['einheit'],
                );
            }
        }
        return array('VI_Chromecast4Lox_MQTT.xml', cc_xml_virtual_in_http(array(
            'title'   => 'Chromecast 4 Lox',
            'address' => 'http://localhost',
            'polling' => '604800',
            'comment' => $fuss,
        ), $cmds));
    }

    if ($art === 'mqtt_out') {
        $port = cc_mqtt_udpinport();
        $cmds = array();
        // Das Sammelziel zuerst: ein Befehl darauf erreicht ALLE
        // eingetragenen Geraete. Fuer Ansagen ist das der bequeme Weg; fuer
        // Musik bleibt die Google-Lautsprechergruppe richtig, denn nur sie
        // spielt synchron.
        foreach (array_merge(array(cc_sammelziel()), $geraete) as $g) {
            $t = cc_thema($g);
            foreach (cc_themen()['befehle'] as $e) {
                $b = $e['schluessel'];
                $analog = cc_befehl_analog($b);
                $cmds[] = array(
                    'title'   => $praefix . '_' . $t . '_' . $b,
                    'comment' => $g . ' - ' . cc_thema_kurz('cmd_' . $b),
                    'on'      => $praefix . '/' . $t . '/cmd/' . $b . ' '
                                 . ($analog ? '<v.0>' : '1'),
                    'analog'  => $analog,
                    'min'     => $e['min'], 'max' => $e['max'],
                );
            }
        }
        return array('VQ_Chromecast4Lox_MQTT.xml', cc_xml_virtual_out(array(
            'title'   => 'Chromecast 4 Lox',
            'address' => '/dev/udp/' . $ip . '/' . $port,
            'comment' => $fuss,
        ), $cmds));
    }

    if ($art === 'udp_out') {
        $port = (int) cc_cfg($cfg, 'udp_port', '7090');
        $cmds = array();
        foreach (array_merge(array(cc_sammelziel()), $geraete) as $g) {
            $t = cc_thema($g);
            foreach (cc_themen()['befehle'] as $e) {
                $b = $e['schluessel'];
                $analog = cc_befehl_analog($b);
                $cmds[] = array(
                    'title'   => $t . '_' . $b,
                    'comment' => $g . ' - ' . cc_thema_kurz('cmd_' . $b),
                    'on'      => $t . '/' . strtoupper($b)
                                 . ($analog ? ' <v.0>' : '') . ';',
                    'analog'  => $analog,
                    'min'     => $e['min'], 'max' => $e['max'],
                );
            }
        }
        return array('VQ_Chromecast4Lox_UDP.xml', cc_xml_virtual_out(array(
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
