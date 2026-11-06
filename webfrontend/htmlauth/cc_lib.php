<?php
require_once __DIR__ . '/sprachausgabe.php';     // Stamm, fuer die Vorgaben der Sprachansagen schon beim ersten Lesen der Konfiguration
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
 * config/plugins, webfrontend UND config/system/general.json traegt. Das
 * trifft die uebliche Installation genauso wie eine an einem anderen Ort -
 * und es trifft auch den Fall, dass das Plugin noch als entpacktes Archiv
 * daliegt (dann findet es nichts und gibt einen Leerstring zurueck, was der
 * Aufrufer ohnehin abfangen muss).
 *
 * Die dritte Bedingung ist seit 1.3.10 dabei und stammt aus Regeln/06: auf
 * einem Pruefrechner liegen unter C:\ aus alten Prueflaeufen ein
 * config/plugins und ein webfrontend, und die Suche erklaerte das Laufwerk
 * zur LoxBerry-Wurzel. Ein LoxBerry hat immer config/system/general.json;
 * ein solcher Rest hat sie nie. Gemessen 17.09.2026 (Fall q6): ohne die
 * Bedingung lieferte die Suche den Attrappenordner, mit ihr den Leerstring.
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert: zwei
 * Bibliotheken landen nie im selben Prozess, aber die Pruefung kostet nichts.
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            // Die Laufwerkswurzel selbst ist nie das Heimverzeichnis eines
            // LoxBerry; bis 1.3.11 fragte die Suche dort "//config/plugins"
            // (seit 1.3.12; in WSL gemessen 25.09.2026, Fall H4).
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            if (is_dir($d . '/config/plugins') && is_dir($d . '/webfrontend')
                && is_file($d . '/config/system/general.json')) {
                return $d;
            }
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
    /* Die Pfade DER ANLAGE gelten nur, wenn diese Bibliothek dort installiert
     * liegt (<Wurzel>/webfrontend/htmlauth/plugins/<ordner>, physisch
     * verglichen) oder der Aufrufer Wurzel UND Ordner ausdruecklich nennt
     * ($LBHOMEDIR und $LBPPLUGINDIR - so arbeiten die Pruefwerkzeuge mit
     * ihrer Attrappe). Sonst ist das ein ausgepacktes Archiv oder ein
     * Pruefordner: alles bleibt in dessen eigenem Ordner, und cc_dienst()
     * startet und beendet nichts (Muster 3 der Nachlese; Bauart
     * Spotpreis-Tibber 0.9.19 tb_paths()).
     *
     * Bis 1.3.11 nahm ein Archiv unterhalb einer echten Wurzel - mit
     * $LBHOMEDIR allein, wie es am Geraet in /etc/environment steht - deren
     * Konfiguration unter dem festen Namen chromecast-4lox-ng, und
     * "Dienst neu starten" startete den Dienst DER ANLAGE (in WSL gemessen
     * 25.09.2026, Pruefung-Chromecast4lox-1.3.12, Faelle H1/H2). Der Ordner
     * einer Installation ist der Name des eigenen Ablageorts; die fruehere
     * Ableitung zwei Ebenen hoeher traf dort "htmlauth" und fiel auf den
     * festen Namen zurueck. */
    $home = rtrim((string) getenv('LBHOMEDIR'), '/');
    if ($home !== '' && !is_file($home . '/config/system/general.json')) {
        $home = '';
    }
    if ($home === '') {
        $home = lb_wurzel_ermitteln();
    }
    $lbp = basename(rtrim((string) getenv('LBPPLUGINDIR'), '/'));
    $lbp_gilt = ($lbp !== '' && !in_array($lbp,
        array('.', '/', 'bin', 'html', 'htmlauth', 'plugins', 'webfrontend'), true));
    $gefunden = $home;
    $dir = '';
    if ($home !== '') {
        $soll = @realpath($home . '/webfrontend/htmlauth/plugins/' . basename(__DIR__));
        $ist = @realpath(__DIR__);
        $installiert = ($soll !== false && $ist !== false && $soll === $ist);
        $ausdruecklich = $lbp_gilt && $home === rtrim((string) getenv('LBHOMEDIR'), '/');
        if ($installiert) {
            $dir = basename(__DIR__);
        } elseif ($ausdruecklich) {
            $dir = $lbp;
        } else {
            $home = '';
        }
    }
    if ($home !== '') {
        $p = array(
            'home'   => $home,
            'plugin' => $dir,
            'config' => $home . '/config/plugins/' . $dir . '/' . $dir . '.cfg',
            'bindir'  => $home . '/bin/plugins/' . $dir,
            'logdir'  => $home . '/log/plugins/' . $dir,
            'datadir' => $home . '/data/plugins/' . $dir,
            'archiv'  => '',
        );
    } else {
        // Neben dem Plugin arbeiten, nie in /tmp: dort raeumte cc_dienst_pid()
        // bis 1.3.11 eine fremde /tmp/dienst.pid weg.
        $base = dirname(dirname(__DIR__));
        $p = array(
            'home'   => '',
            'plugin' => $lbp_gilt ? $lbp : 'chromecast-4lox-ng',
            'config' => $base . '/config/chromecast-4lox-ng.cfg',
            'bindir'  => $base . '/bin',
            'logdir'  => $base . '/log',
            'datadir' => $base . '/data',
            // Die gefundene Wurzel, wenn diese Datei NICHT darin liegt.
            'archiv'  => $gefunden,
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
/**
 * Kodierung der Werte in der Datei (seit 1.3.13, C3) - dieselbe wie
 * wert_kodieren()/wert_dekodieren() im Dienst.
 *
 * Die Datei ist zeilenorientiert. Bis 1.3.12 ersetzte cc_config_write() in
 * JEDEM Wert den Zeilenumbruch durch ";" - auch in den Favoriten, die
 * Oberflaeche und Dienst nur am Umbruch trennen: aus drei Favoriten wurde
 * einer mit kaputter Adresse (gemessen 30.09.2026 unter PHP 7.4/8.5 und im
 * Dienst, Code-Befund 3). Jetzt: Rueckstrich wird doppelt, ein Umbruch wird
 * zur Folge Rueckstrich-n. Eine andere Folge hinter einem Rueckstrich bleibt
 * beim Lesen stehen - eine alte Datei liest sich also unveraendert.
 */
function cc_wert_kodieren($v)
{
    $v = str_replace(array("\r\n", "\r"), "\n", (string) $v);
    $v = str_replace(array('\\', "\n"), array('\\\\', '\\n'), $v);
    // Ein Wert, der mit einem Anfuehrungszeichen beginnt oder endet, bekommt
    // ein Paar darum: beide Leser nehmen genau EIN umschliessendes Paar ab
    // (O5: Geraetenamen zeichengenau, auch mit ').
    $n = strlen($v);
    if ($n > 0 && (strpos('"\'', $v[0]) !== false || strpos('"\'', $v[$n - 1]) !== false)) {
        $v = '"' . $v . '"';
    }
    return $v;
}

function cc_wert_dekodieren($v)
{
    return preg_replace_callback('/\\\\(.)/s', function ($m) {
        if ($m[1] === 'n') { return "\n"; }
        if ($m[1] === '\\') { return '\\'; }
        return $m[0];
    }, (string) $v);
}

/**
 * Zeilen einer Datei - an CRLF, LF und CR getrennt, sonst an nichts
 * (seit 1.3.13, C4). "\R" ohne u trennt auch am Byte 0x85 (NEL), und das
 * steckt in UTF-8 mitten in "Å" (C3 85) oder "х" (D1 85): aus "Åsa Küche"
 * wurde eine Zeile "\xC3", und das naechste Speichern loeschte den Namen
 * (gemessen 30.09.2026, Code-Befund 4). "\R" MIT u scheitert an einem
 * einzigen ungueltigen Byte ganz (preg_split gibt false) - dann laese sich
 * die Konfiguration gar nicht. Die ausgeschriebene Liste traegt beides.
 */
function cc_zeilen($text)
{
    return preg_split('/\r\n|\n|\r/', (string) $text);
}

function cc_config_roh()
{
    $out = array();
    $file = cc_paths()['config'];
    if (!is_file($file)) {
        return $out;
    }
    foreach (cc_zeilen((string) @file_get_contents($file)) as $line) {
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
        $out[$key] = cc_wert_dekodieren($val);
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
    $roh = @file_get_contents($file);
    if ($roh === false) {
        return 'unlesbar';
    }
    // Gekuerzt (seit 1.3.13, C9): jeder Schreiber dieser Linie legt [CONFIG]
    // an und endet mit einem Zeilenende. Fehlt eines davon, ist die Datei
    // mitten im Schreiben abgebrochen. Sie zu vervollstaendigen hiesse, die
    // fehlenden Werte mit der Werkseinstellung zu fuellen - und ohne
    // aktionstoken wuerfelte cc_aktionstoken() ein neues (in WSL gemessen
    // 30.09.2026, ulimit -f 1, Code-Befund 9: Lautstaerkegrenze 100, Ruhezeit
    // aus, Token leer). Dieselbe Frage stellt der Dienst.
    if (substr($roh, -1) !== "\n" || !preg_match('/^\s*\[CONFIG\]\s*$/m', $roh)) {
        return 'gekuerzt';
    }
    return 'ok';
}

/**
 * Ein Argument fuer die Schale anfuehren, Byte fuer Byte: fuer Themen, Werte und Texte, nicht fuer Pfade.
 * Bis 1.3.26 stand hier escapeshellarg(). Es verwirft unter PHP 7.4 jedes Byte, das im LC_CTYPE kein gueltiges Zeichen ist; Apache startet mit LANG=C, eine
 * Befehlsbruecke ohne LANG: aus "Kueche" mit Umlaut wurde "Kche", ohne Fehler (am Geraet gemessen 01.11.2026). setlocale() fehlt, wo keine UTF-8-Locale
 * erzeugt ist, und aendert basename() und PCRE. Einfache Anfuehrungszeichen sind fuer sh und dash byte-erhaltend; ein Nullbyte kann kein Argument tragen.
 */
function cc_shell_arg($s)
{
    return "'" . str_replace("'", "'\\''", str_replace("\0", '', (string) $s)) . "'";
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
    if (in_array(cc_config_zustand(), array('unlesbar', 'gekuerzt'), true)) {
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
        $v = isset($cfg[$k]) ? (string) $cfg[$k] : (string) $vorgabe;
        if ($k === 'geraete') {
            // Die Geraeteliste steht wie bisher mit ";" auf einer Zeile -
            // jeder Name fuer sich getrimmt, leere fallen weg.
            $v = implode(';', cc_geraete(array('geraete' => $v)));
        }
        // Jeder Wert kodiert (C3), nicht mehr jeder Umbruch zu ";".
        $txt .= $k . '=' . cc_wert_kodieren($v) . "\n";
    }
    return cc_datei_schreiben($file, $txt, 0600);
}

/**
 * Eine Datei unteilbar schreiben (seit 1.3.13, C9/C10; Regeln/03
 * "Atomares Schreiben"): Nebendatei <ziel>.tmp.<pid>, Rechte VOR dem Inhalt,
 * Laenge gegen strlen(), dann rename(). Nur zwei Zustaende: alte oder neue
 * Datei.
 *
 * Bis 1.3.12 schrieb cc_config_write() mit file_put_contents() direkt auf die
 * Konfiguration und setzte danach 0644. Bei vollem Datentraeger (ulimit -f 1)
 * blieb eine auf 1024 Byte gekuerzte Datei mit 10 von 28 Schluesseln stehen,
 * und die Datei mit dem Aktionstoken war fuer jeden lokalen Benutzer lesbar
 * (in WSL gemessen 30.09.2026, Code-Befunde 9 und 10, Installer-Befund 5).
 */
function cc_datei_schreiben($ziel, $inhalt, $modus)
{
    $tmp = $ziel . '.tmp.' . getmypid();
    $fh = @fopen($tmp, 'c');
    if ($fh === false) {
        return false;
    }
    @chmod($tmp, $modus);
    $ok = @ftruncate($fh, 0);
    $n = $ok ? @fwrite($fh, $inhalt) : false;
    $ok = $ok && $n === strlen($inhalt) && @fflush($fh);
    @fclose($fh);
    clearstatcache(true, $tmp);
    if (!$ok || @filesize($tmp) !== strlen($inhalt) || !@rename($tmp, $ziel)) {
        @unlink($tmp);
        return false;
    }
    return true;
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
    if (in_array(cc_config_zustand(), array('unlesbar', 'leer', 'gekuerzt'), true)) {
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

/**
 * Geraeteliste aus der Konfiguration. Semikolon und Zeilenumbruch trennen -
 * ein Komma seit 1.3.13 NICHT mehr (O5): das Feld heisst "einer je Zeile",
 * und "Bad, oben" wurde bis 1.3.12 still zu zwei Geraeten (gemessen
 * 30.09.2026, Oberflaechen-Befund 5f). geraeteliste() im Dienst trennt gleich.
 */
function cc_geraete($cfg)
{
    $roh = (string) cc_cfg($cfg, 'geraete', '');
    $teile = preg_split('/[;\n\r]+/', $roh);
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
 * Taugt ein Themenpraefix fuer die Oberflaeche? (seit 1.3.13, O5)
 *
 * Genau die Zeichen, die die Oberflaeche seit jeher speichert (cc_thema()
 * lieferte nie etwas anderes): Buchstaben, Ziffern, _ und -, 1 bis 64. Bis
 * 1.3.12 wurde eine abweichende Eingabe still umgeschrieben ("haus/cast" ->
 * "haus_cast", leer -> "geraet") und als "Gespeichert." gemeldet; jetzt wird
 * sie abgewiesen. Ein Schraegstrich bleibt draussen: das MQTT-Gateway macht
 * daraus einen Unterstrich, und die Titel der Eingangsvorlage passten nicht
 * mehr (Regeln/07).
 */
function cc_praefix_taugt($p)
{
    return is_string($p) && preg_match('/^[A-Za-z0-9_-]{1,64}\z/', $p) === 1;
}

/**
 * Das Aktionstoken aus einer Sicherung (seit 1.3.13, C2; Regeln/05 "Das
 * Positivmuster fuer ein Token"): Zeichenkette aus [A-Za-z0-9_.-], bis 64.
 * Leer heisst "keins gesichert" - dann gilt das geltende. "Array" ist die
 * Spur einer Liste, die ein (string) umgewandelt hat, und wird abgewiesen:
 * mit ihr waere das Formularmerkmal hash_hmac('sha256','formular-v1','Array')
 * fuer jeden ausrechenbar (gemessen 30.09.2026, Code-Befund 2).
 */
function cc_token_taugt($t)
{
    return is_string($t) && strcasecmp($t, 'Array') !== 0
        && preg_match('/^[A-Za-z0-9_.\-]{1,64}\z/', $t) === 1;
}

/**
 * Die Zahlenfelder mit ihren Grenzen - EINE Liste fuer das Formular, die
 * Sicherung und den Reiter Test (seit 1.3.13, C2/O5). Die Grenzen sind die
 * des Formulars; udp_port beginnt bei 1024, denn der Dienst laeuft als
 * loxberry und kann darunter nicht binden.
 */
function cc_zahlfelder()
{
    return array(
        'udp_port'            => array(1024, 65535),
        'intervall'           => array(2, 3600),
        'aktualisierung'      => array(5, 86400),
        'lautstaerke_schritt' => array(1, 50),
        'tts_port'            => array(1, 65535),
        'tts_lautstaerke'     => array(1, 100),
        'lautstaerke_max'     => array(0, 100),
        'ruhe_max'            => array(0, 100),
        // Ansage-3: hoechstens so viele Ansagen je Stunde ueber den Endpunkt.
        'sprechen_stunde'     => array(10, 240),
    );
}

/**
 * Einen Wert pruefen - wie beim Speichern (seit 1.3.13, C2/O2/O5).
 *
 * Rueckgabe: array(Wert als Zeichenkette, '') oder array(null, Beanstandung).
 * $bisher ist die geltende Konfiguration: ein leeres Token und das geltende
 * Praefix werden an ihr gemessen. Bis 1.3.12 uebernahm cc_sicherung_lesen()
 * jeden Wert ungeprueft ($neu[$k] = $w): Token als Liste wurde "Array",
 * intervall "inf", udp_port 99999, mqtt_topic "a/#" (gemessen 30.09.2026
 * unter PHP 7.4 und 8.5, Code-Befund 2, Oberflaechen-Befund 2, MQTT M9).
 */
function cc_wert_pruefen($k, $w, $bisher)
{
    if ($k === 'ansage_block') { return array(is_string($w) ? $w : '', ''); }     // 1.3.27: eigene Pruefung
    $name = cc_e($k);
    if (is_array($w) || is_object($w) || is_bool($w) || $w === null) {
        return array(null, sprintf(cc_t('PRUEF.KEIN_TEXT'), $name));
    }
    // Das Token ist eine ZEICHENKETTE; eine Zahl in der Sicherung stammt
    // nicht aus diesem Plugin (Code-Befund 2: 12345 wurde angenommen).
    if ($k === 'aktionstoken' && !is_string($w)) {
        return array(null, cc_t('PRUEF.TOKEN'));
    }
    if (is_float($w)) {
        if (!is_finite($w) || floor($w) !== $w) {
            return array(null, sprintf(cc_t('PRUEF.KEINE_GANZZAHL'), $name));
        }
        $w = (string) (int) $w;
    }
    $s = (string) $w;
    // Audio Stufe 1 (1.3.25): eigene Pruefung und eigene Laengen (die Quellenliste darf laenger sein).
    if (in_array($k, cc_audio_schluessel(), true)) {
        return cc_audio_pruefen($k, $s, $bisher);
    }
    if (strlen($s) > 4096) {
        return array(null, sprintf(cc_t('PRUEF.ZU_LANG'), $name));
    }
    $zahlen = cc_zahlfelder();
    if ($k === 'aktionstoken') {
        if ($s === '') {
            return array((string) cc_cfg($bisher, 'aktionstoken', ''), '');
        }
        return cc_token_taugt($s) ? array($s, '') : array(null, cc_t('PRUEF.TOKEN'));
    }
    if (in_array($k, array_merge(array('enabled', 'mqtt_ein', 'udp', 'tts_fortsetzen', 'gruppen',
                           'beschleunigung', 'sprechen_ein', 'sperre_ein'), cc_befehle_haken()), true)) {
        return ($s === '0' || $s === '1') ? array($s, '')
            : array(null, sprintf(cc_t('PRUEF.SCHALTER'), $name));
    }
    if (isset($zahlen[$k])) {
        list($u, $o) = $zahlen[$k];
        if (!preg_match('/^-?[0-9]{1,15}\z/', $s)) {
            return array(null, sprintf(cc_t('PRUEF.KEINE_GANZZAHL'), $name));
        }
        $n = (int) $s;
        return ($n >= $u && $n <= $o) ? array((string) $n, '')
            : array(null, sprintf(cc_t('PRUEF.BEREICH'), $name, $u, $o));
    }
    if ($k === 'tts_pegel') {
        if ($s === '') {
            return array('', '');
        }
        return (preg_match('/^[0-9]{1,3}\z/', $s) && (int) $s <= 100) ? array((string) (int) $s, '')
            : array(null, sprintf(cc_t('PRUEF.BEREICH_LEER'), $name, 0, 100));
    }
    if ($k === 'tts_modus') {
        return array_key_exists($s, cc_tts_modi()) ? array($s, '')
            : array(null, sprintf(cc_t('PRUEF.MODUS'), $name));
    }
    if ($k === 'tts_sprache') {
        return preg_match('/^[A-Za-z]{2,3}([_-][A-Za-z0-9]{1,8}){0,2}\z/', $s) ? array($s, '')
            : array(null, cc_t('PRUEF.SPRACHE'));
    }
    if ($k === 'ruhe_von' || $k === 'ruhe_bis') {
        return ($s === '' || preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]\z/', $s)) ? array($s, '')
            : array(null, cc_t('RUHE.FEHLER'));
    }
    /* Ansage-3: das Sprechtoken steht nie in einer Sicherung. Eine Datei mit
     * einem (nicht leeren) Sprechtoken stammt nicht aus diesem Plugin und wird
     * abgewiesen; leer heisst: das geltende bleibt. Im Formular ist es ein
     * Kennwortfeld (cc_sprechen_formular()). */
    if ($k === 'sprechtoken') {
        return $s === '' ? array((string) cc_cfg($bisher, 'sprechtoken', ''), '')
                         : array(null, cc_t('PRUEF.SPRECHTOKEN'));
    }
    if ($k === 'sprechen_geraet') {
        if (strlen($s) > 400) {
            return array(null, cc_t('SPRECHEN.F_GERAET_LANG'));
        }
        return (!preg_match('/[\x00-\x1F\x7F]/', $s) && preg_match('//u', $s) === 1)
            ? array($s, '') : array(null, sprintf(cc_t('PRUEF.STEUERZEICHEN'), $name));
    }
    if ($k === 'mqtt_topic') {
        // Das geltende Praefix bleibt zulaessig, auch wenn es aus einer
        // aelteren Fassung stammt und enger nicht passt.
        if (cc_praefix_taugt($s) || ($s !== '' && $s === (string) cc_cfg($bisher, 'mqtt_topic', ''))) {
            return array($s, '');
        }
        return array(null, cc_t('PRUEF.PRAEFIX'));
    }
    // Freie Texte: kein Steuerzeichen - ausser dem Zeilenumbruch in
    // Favoriten und Geraeteliste, die je Zeile einen Eintrag tragen.
    $erlaubt_umbruch = in_array($k, array('favoriten', 'geraete'), true);
    $muster = $erlaubt_umbruch ? '/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/';
    if (preg_match($muster, $s)) {
        return array(null, sprintf(cc_t('PRUEF.STEUERZEICHEN'), $name));
    }
    return array($s, '');
}

/**
 * Die Sicherungsdatei (seit 1.3.13, C1/O1): ein lesbarer Kopf aus
 * _-Schluesseln, dann ALLE Schluessel aus den Vorgaben mit dem Wert der
 * Datei. Bis 1.3.12 rief der Knopf cc_cfg() ohne Argumente auf und lieferte
 * einen PHP-Fehler statt einer Datei - seit mindestens 1.3.4 (gemessen
 * 30.09.2026 unter 7.4 und 8.5, Code- und Oberflaechen-Befund 1).
 */
/** Die Sprachansagen aus dem Feld der Hauptkonfiguration: was fehlt, kommt aus den Vorgaben (ab Werk EIN). */
function cc_ansage_block_lesen()
{
    $roh = (function () { $c = cc_config_read(); return isset($c['ansage_block']) ? (string) $c['ansage_block'] : ''; })();
    $b = $roh !== '' ? json_decode((string) base64_decode($roh), true) : null;
    return (is_array($b) ? $b : array()) + cc_ansage_vorgaben();
}

/** Zurueck in das Feld der Hauptkonfiguration. */
function cc_ansage_block_schreiben(array $cfg)
{
    $x = array();
    foreach (cc_ansage_schluessel() as $k) { if (array_key_exists($k, $cfg)) { $x[$k] = $cfg[$k]; } }
    $b = base64_encode((string) json_encode($x));
    $c = cc_config_read(); $c['ansage_block'] = $b; return cc_config_write($c);
}

/** Ein Ziel, das diese Linie selbst ist, faellt heraus: Wer abgemeldet ist, kann es nicht ansagen. */
function cc_ansage_ohne_selbst(array $t)
{
    if (isset($t['ziele']) && is_array($t['ziele'])) {
        $t['ziele'] = array_values(array_filter($t['ziele'], function ($z) { return !is_array($z) || !isset($z['art']) || $z['art'] !== 'cc4lox'; }));
    }
    if (isset($t['mode']) && $t['mode'] === 'cc4lox') { $t['mode'] = 'aus'; }
    /* Auch eine Adressvorlage, die auf dieses Plugin zeigt, waere eine Schleife. */
    $ccs = function ($s) { return is_string($s) && stripos($s, 'plugins/' . cc_paths()['plugin']) !== false; };
    if (isset($t['mode'], $t['template']) && $t['mode'] === 'custom' && $ccs($t['template'])) { $t['mode'] = 'aus'; }
    if (isset($t['ziele']) && is_array($t['ziele'])) {
        $t['ziele'] = array_values(array_filter($t['ziele'], function ($z) use ($ccs) { return !is_array($z) || !isset($z['wo']) || !$ccs($z['wo']); }));
    }
    return $t;
}

/** Die Schluessel der Sprachansagen in der Hauptkonfiguration. */
function cc_ansage_schluessel()
{
    return array('tts', 'ansage_regeln', 'ansage_haken', 'ansage_token_herkunft', 'ansage_aus_datei');
}

/** Die Vorgaben: nie gesetzt = ab Werk EIN. Ein gespeicherter Block bleibt, auch 'aus'. */
function cc_ansage_vorgaben()
{
    return array('tts' => cc_ansage_ohne_selbst(ansage_werk_block()), 'ansage_regeln' => ansage_regeln_vorgaben(), 'ansage_haken' => array('geraet' => 0), 'ansage_token_herkunft' => array(), 'ansage_aus_datei' => 0);
}

/** Wertpruefung fuer die Sprachansagen: null = nicht meine Schluessel, sonst tauglich ja/nein. */
function cc_ansage_wert($k, $w)
{
    if (!in_array((string) $k, cc_ansage_schluessel(), true)) { return null; }
    $g = '';
    switch ((string) $k) {
        case 'tts':
            return is_array($w) && ansage_wert_pruefen($w, $g, cc_ansage_modi()) !== null;
        case 'ansage_regeln':
            return is_array($w) && ansage_regeln_wert($w, $g) !== null;
        case 'ansage_haken':
            if (!is_array($w)) { return false; }
            foreach ($w as $a => $v) {
                if (!array_key_exists($a, array('geraet' => 0)) || !in_array($v, array(0, 1, '0', '1'), true)) { return false; }
            }
            return true;
        case 'ansage_token_herkunft':
            return is_array($w);
        case 'ansage_aus_datei':
            return in_array($w, array(0, 1, '0', '1'), true);
    }
    return false;
}

/** Fuer die Sicherung: nie ein Sprechtoken, nie der Herkunftsvermerk. Ausgabeart, Ziele, Regeln und Haken reisen mit. */
function cc_ansage_fuer_sicherung(array $c)
{
    unset($c['ansage_token_herkunft'], $c['ansage_aus_datei']);
    if (isset($c['tts']) && is_array($c['tts'])) { $c['tts'] = ansage_sicherung_bereinigen($c['tts']); }
    return $c;
}

/**
 * Vor dem Zurueckspielen. Eine Datei mit Sprechtoken wird abgewiesen (der Block wird unbrauchbar gemacht, die Wertpruefung nennt ihn). Die geltenden Token und der Vermerk bleiben.
 * Fehlt ein Schluessel (Sicherung einer aelteren Fassung), gilt der geltende Wert: Ein bewusstes AUS geht beim Zurueckspielen nicht verloren.
 */
function cc_ansage_sicherung_vor($roh)
{
    $d = json_decode((string) $roh, true);
    if (!is_array($d)) { return $roh; }
    $inhalt = false;
    foreach (array_keys($d) as $k) { if ((string) $k !== '' && ((string) $k)[0] !== '_') { $inhalt = true; } }
    if (!$inhalt) { return $roh; }
    $j = cc_ansage_cfg();
    unset($d['ansage_token_herkunft'], $d['ansage_aus_datei']);
    $jt = isset($j['tts']) && is_array($j['tts']) ? $j['tts'] : array();
    if (array_key_exists('tts', $d)) {
        $g = '';
        $p = is_array($d['tts']) ? ansage_wert_pruefen($d['tts'], $g, cc_ansage_modi()) : null;
        if (ansage_sicherung_mangel($d['tts']) || $p === null) {
            $d['tts'] = 'ABGEWIESEN';
        } else {
            list($tv) = ansage_vervollstaendigen($p, 'aus');
            $d['tts'] = ansage_sicherung_tokens_behalten($tv, $jt);
        }
    } else {
        $d['tts'] = cc_tts($j);
    }
    foreach (array_diff(cc_ansage_schluessel(), array('tts', 'ansage_token_herkunft', 'ansage_aus_datei')) as $k) {
        if (!array_key_exists($k, $d) && array_key_exists($k, $j)) { $d[$k] = $j[$k]; }
    }
    $d['ansage_token_herkunft'] = isset($j['ansage_token_herkunft']) && is_array($j['ansage_token_herkunft']) ? $j['ansage_token_herkunft'] : array();
    $d['ansage_aus_datei'] = 1;
    return (string) json_encode($d);
}

/**
 * Die Hauptkonfiguration. Liegt eine ansage.json aus einer Probe vom 01.11., wird sie EINMAL uebernommen (Vermerk ansage_aus_datei) und bleibt liegen.
 */
function cc_ansage_cfg()
{
    $c = cc_ansage_block_lesen();
    if (empty($c['ansage_aus_datei'])) {
        $f = rtrim(dirname(cc_paths()['config']), '/') . '/ansage.json';
        if (is_file($f)) {
            $d = json_decode((string) @file_get_contents($f), true);
            if (is_array($d)) {
                foreach (array('tts', 'ansage_regeln', 'ansage_haken', 'ansage_token_herkunft') as $k) {
                    if (array_key_exists($k, $d) && cc_ansage_wert($k, $d[$k]) === true) { $c[$k] = $d[$k]; }
                }
            }
            $c['ansage_aus_datei'] = 1;
            cc_ansage_cfg_schreiben($c);
        }
    }
    return $c;
}

function cc_ansage_cfg_schreiben(array $cfg)
{
    return cc_ansage_block_schreiben($cfg);
}

/** Die Ausgabearten dieser Linie. */
function cc_ansage_modi()
{
    return array_values(array_diff(ansage_modi(), array('audioserver', 'cc4lox')));
}

/** Der Block tts: gespeichert bleibt, auch 'aus'; ungueltig gilt als 'aus'. */
function cc_tts($cfg = null)
{
    if ($cfg === null) { $cfg = cc_ansage_cfg(); }
    return cc_ansage_ohne_selbst(ansage_block_oder_aus(isset($cfg['tts']) ? $cfg['tts'] : cc_ansage_ohne_selbst(ansage_werk_block()), cc_ansage_modi()));
}

/** Der Kontext des Moduls. */
function cc_ansage_k()
{
    $d = cc_paths()['datadir'];
    return array(
        'port' => ansage_webport(ansage_lbhome() . '/config/system/general.json'),
        'home' => ansage_lbhome(),
        'kopf' => array('User-Agent: LoxBerry Chromecast4lox'),
        'ordner' => @is_dir($d) ? $d : '',
        't' => function ($s) { return cc_t($s); },
        'werk' => ansage_werk_modus(),
    );
}

/** Die Anlaesse: Name => Beschriftung. */
function cc_ansage_anlaesse()
{
    return array('geraet' => cc_t('ANSAGEPLUGIN.L_GERAET'));
}

/** Ist der Haken dieses Anlasses gesetzt? Fehlt er, gilt die Vorgabe der Linie. */
function cc_ansage_anlass_an($a, $cfg = null)
{
    if ($cfg === null) { $cfg = cc_ansage_cfg(); }
    $h = (isset($cfg['ansage_haken']) && is_array($cfg['ansage_haken'])) ? $cfg['ansage_haken'] : array();
    $v = array('geraet' => 0);
    return !empty(array_key_exists($a, $h) ? $h[$a] : (isset($v[$a]) ? $v[$a] : 0));
}

/** Die Eingaben nach einer Beanstandung bis zur naechsten Seite aufheben, nie ein Sprechtoken. */
function cc_ansage_eingaben($setzen = null)
{
    $f = cc_paths()['datadir'] . '/ansage_eingaben.json';
    if (is_array($setzen)) {
        if (!is_dir(dirname($f))) { @mkdir(dirname($f), 0775, true); }
        @file_put_contents($f, (string) json_encode($setzen));
        @chmod($f, 0600);
        return $setzen;
    }
    $d = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
    if (is_array($d) && $d) { @file_put_contents($f, '{}'); return $d; }
    return array();
}

/** Das Formular lesen und speichern: array(gelungen, Fehlertexte, Hinweise). */
function cc_ansage_post(array $post)
{
    $cfg = cc_ansage_cfg();
    $k = cc_ansage_k();
    $m = array();
    $b = array();
    $h = array();
    $neu = ansage_formular_lesen($post, cc_tts($cfg), $m, $b, array('modi' => cc_ansage_modi(), 'ziele' => true, 'token_vor_ort' => true, 'aus_gewinnt' => true), $k, $h);
    list($r, $hk) = ansage_regeln_formular($post, cc_ansage_regeln($cfg), cc_ansage_anlaesse(), $m, $b, $k);
    if ($m) {
        $t = array();
        foreach ($m as $x) { $t[] = $x['text']; }
        $e = array('_bean' => array_values($b));
        foreach ($post as $n => $w) {
            if (strncmp((string) $n, 'tts_', 4) === 0 || strncmp((string) $n, 'ansage_', 7) === 0) {
                if ((string) $n !== 'tts_alexa_token' && (string) $n !== 'tts_google_token') { $e[$n] = $w; }
            }
        }
        cc_ansage_eingaben($e);
        return array(false, $t, array());
    }
    $cfg['tts'] = $neu;
    // 1.3.30: ein eingetipptes oder geloeschtes Sprechtoken ist nicht mehr vom Nachbarn; bis 1.3.29 ueberschrieb es der naechste Abgleich.
    $cfg['ansage_token_herkunft'] = ansage_token_herkunft_formular($post, isset($cfg['ansage_token_herkunft']) ? $cfg['ansage_token_herkunft'] : array());
    $cfg['ansage_regeln'] = $r;
    $cfg['ansage_haken'] = $hk;
    $t = array();
    foreach ($h as $x) { $t[] = $x['text']; }
    return array(cc_ansage_cfg_schreiben($cfg), array(), $t);
}

/** Der Abschnitt im Reiter Einstellungen, eigenes Formular. $felder: die versteckten Felder (Formularmerkmal, Reiter). Nach einer Beanstandung traegt er das Eingetippte. */
function cc_ansage_form_html($felder)
{
    $cfg = cc_ansage_cfg();
    $k = cc_ansage_k();
    $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
    $ein = cc_ansage_eingaben();
    $bean = isset($ein['_bean']) && is_array($ein['_bean']) ? $ein['_bean'] : array();
    $h = array(
        'w' => function ($n, $g) use ($ein) { return (isset($ein[$n]) && is_string($ein[$n])) ? $ein[$n] : $g; },
        'm' => function ($n) use ($bean) { return in_array($n, $bean, true) ? ' class="sm-beanstandet"' : ''; },
        'c' => function ($n, $g) use ($ein) { return $ein ? !empty($ein[$n]) : (bool) $g; },
    );
    $o = array();
    $o[] = '<h2>' . $e(cc_t('ANSAGEPLUGIN.H')) . '</h2>';
    $o[] = '<form action="index.php" method="post" autocomplete="off">' . $felder;
    $o[] = ansage_formular_html(cc_tts($cfg), $h + array('modi' => cc_ansage_modi(), 'ziele' => true, 'ziele_post' => isset($ein['tts_ziel_art']) ? $ein : null), $k);
    $o[] = ansage_regeln_html(cc_ansage_regeln($cfg), isset($cfg['ansage_haken']) && is_array($cfg['ansage_haken']) ? $cfg['ansage_haken'] : array('geraet' => 0), cc_ansage_anlaesse(), $h + array('token_vor_ort' => true), $k);
    $o[] = '<div class="sm-knopfreihe"><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="ansage_speichern" value="1">' . $e(cc_t('ANSAGEPLUGIN.K_SPEICHERN')) . '</button>';
    $o[] = '<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="ansage_test" value="1">' . $e(cc_t('ANSAGEPLUGIN.K_TEST')) . '</button></div>';
    $o[] = '</form>';
    $o[] = cc_ansage_lage_html($cfg);
    return implode("\n", $o) . "\n";
}

/** Die Testansage (der Knopf spricht immer). Rueckgabe: array(gesendet, Grund). */
function cc_ansage_test()
{
    $k = cc_ansage_k();
    cc_ansage_token_pflegen();
    $r = ansage_testansage(cc_ansage_vor_ort(), $k);
    cc_ansage_zeile('Testansage: ' . ansage_kurz($r));
    return array($r['stand'] === 1, $r['stand'] === 1 ? '' : ansage_kennung_text($r['kennung'], $k));
}

/* ---------------- Sprachansagen: Regeln aus dem Stamm (1.3.27, Welle S2, Stamm sprachausgabe.php 1.3.2) ----------------
 *
 * Nachtruhe, Sperre und Schalter "Ansagen aus" stehen im Stamm. Ab Werk EIN: ansage_werk_block() in den Vorgaben, also nur fuer einen
 * nie gespeicherten Block; ein gespeicherter bleibt, auch 'aus'. Ein Minutenlauf und ein Dienst sprechen nie selbst: Sie legen den Anlass
 * in <Daten>/ansage_warte.json, ein abgekoppelter Sender (php -r, eigene Sperre) spricht und wartet auf die Lautsprecher.
 */

/** So alt darf ein wartender Anlass werden (nach einem Update oder Hang wird nichts Aufgestautes angesagt). */
if (!defined('CC_ANSAGE_HOECHSTALTER')) { define('CC_ANSAGE_HOECHSTALTER', 600); }

/** Die Regeln: gespeichert und gueltig, sonst aus einer alten Einstellung dieser Linie, sonst die Vorgabe (mit Nachtruhe). */
function cc_ansage_regeln($cfg = null)
{
    if ($cfg === null) { $cfg = cc_ansage_cfg(); }
    $g = '';
    if (!empty($cfg['ansage_regeln'])) {
        $r = ansage_regeln_wert($cfg['ansage_regeln'], $g);
        return $r === null ? ansage_regeln_vorgaben() : $r;
    }
    $r = ansage_regeln_vorgaben();
    $p = ansage_regeln_wert($r, $g);
    return $p === null ? ansage_regeln_vorgaben() : $p;
}

/** Die Warteliste unter einer Dateisperre aendern: $fn(Liste) -> array(neue Liste, Ergebnis). */
function cc_ansage_warte($fn)
{
    $d = cc_paths()['datadir'];
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
    $f = $d . '/ansage_warte.json';
    $h = @fopen($f, 'c+');
    if ($h === false) { return null; }
    if (!flock($h, LOCK_EX)) { fclose($h); return null; }
    $alt = json_decode((string) stream_get_contents($h), true);
    list($neu, $erg) = call_user_func($fn, is_array($alt) ? $alt : array());
    rewind($h);
    ftruncate($h, 0);
    fwrite($h, (string) json_encode($neu));
    fflush($h);
    flock($h, LOCK_UN);
    fclose($h);
    @chmod($f, 0600);
    return $erg;
}

/**
 * Anlaesse hinlegen und den Sender starten; wartet auf nichts. $l: Eintraege fuer ansage_regel_sprechen() (schluessel, text, dringend,
 * sperre_min, nur_nach). Die Schale schliesst vor dem exec jede Deskriptor-Nummer ueber 2, sonst erbte der Sender die Sperren des Rufers.
 */
function cc_ansage_legen(array $l, $jetzt = null)
{
    if (!$l) { return 'nichts'; }
    $jetzt = $jetzt === null ? time() : (int) $jetzt;
    cc_ansage_warte(function ($alt) use ($l, $jetzt) {
        foreach ($l as $e) { $alt[] = $e + array('zeit' => $jetzt); }
        return array(array_slice($alt, -50), null);
    });
    $php = (defined('PHP_BINARY') && PHP_BINARY !== '' && is_executable(PHP_BINARY) && stripos(basename(PHP_BINARY), 'php') === 0 && stripos(basename(PHP_BINARY), 'fpm') === false) ? PHP_BINARY : 'php';
    $befehl = 'for f in /proc/$$/fd/*; do n=${f##*/}; [ "$n" -gt 2 ] 2>/dev/null && eval "exec $n>&-"; done; '
            . 'exec ' . escapeshellarg($php) . ' -r ' . escapeshellarg('chdir(dirname($argv[1])); $_SERVER["SCRIPT_FILENAME"] = $_SERVER["PHP_SELF"] = $argv[1]; require $argv[1]; cc_ansage_senden();') . ' -- ' . escapeshellarg(__FILE__);
    $aus = array();
    $rc = 1;
    @exec('bash -c ' . escapeshellarg($befehl) . ' </dev/null >/dev/null 2>&1 &', $aus, $rc);
    return $rc === 0 ? 'gestartet' : 'kein_start';
}

/** Das Sprechtoken aus Alexa-NG und Chromecast4lox uebernehmen (Stamm 1.3.1). Schreibt NUR bei einer Aenderung, nennt nie das Token. */
function cc_ansage_token_pflegen($cfg = null)
{
    if ($cfg === null) { $cfg = cc_ansage_cfg(); }
    list($neu, $her2, $g) = ansage_token_abgleich(cc_tts($cfg), isset($cfg['ansage_token_herkunft']) ? $cfg['ansage_token_herkunft'] : array(), cc_ansage_k());
    if (!$g) { return false; }
    $cfg = cc_ansage_cfg();
    $cfg['tts'] = $neu;
    $cfg['ansage_token_herkunft'] = $her2;
    if (!cc_ansage_cfg_schreiben($cfg)) { return false; }
    cc_ansage_zeile('Ansage: Sprechtoken uebernommen (' . implode(', ', $g) . ').');
    return true;
}

/** Der Block fuer Testansage und Pruefzeile: uebernommene Token aufgeloest. Nur im Speicher. */
function cc_ansage_vor_ort($cfg = null)
{
    if ($cfg === null) { $cfg = cc_ansage_cfg(); }
    return ansage_tafel_einreihen(ansage_token_fuer_lauf(cc_tts($cfg), isset($cfg['ansage_token_herkunft']) ? $cfg['ansage_token_herkunft'] : array(), cc_ansage_k()), cc_ansage_regeln($cfg), cc_ansage_k(), true);     // Stamm 1.4.0: die Tafel, wenn sie bereitsteht
}

/** Der Block fuer die Pruefzeile: nur die eingerichteten Ziele; ist keines eingerichtet, sagt die Zeile "aus" statt eines Kreuzes. */
function cc_ansage_pruefblock($cfg = null)
{
    if ($cfg === null) { $cfg = cc_ansage_cfg(); }
    $t = cc_tts($cfg);
    if ($t['mode'] === 'aus') { return $t; }
    list($b) = ansage_ziele_bereit(cc_ansage_vor_ort($cfg), cc_ansage_k());
    if ($b === null) { $t['mode'] = 'aus'; return $t; }
    return $b;
}

/** Die Lage im Reiter Test: Ziele, Nachtruhe, Schalter. Nur im geoeffneten Reiter (der Schalter fragt den Broker). */
function cc_ansage_lage_html($cfg = null)
{
    if ($cfg === null) { $cfg = cc_ansage_cfg(); }
    $k = cc_ansage_k();
    $o = array();
    foreach (ansage_regel_lage(cc_tts($cfg), cc_ansage_regeln($cfg), $k, true) as $z) {
        $o[] = '<div class="sm-hilfe">' . htmlspecialchars((string) $z[1], ENT_QUOTES, 'UTF-8') . '</div>';
    }
    return implode("\n", $o) . "\n";
}

/** Die Felder der Regeln fuer die Einmalmeldung (X-2). */
function cc_ansage_regel_felder(array $anlaesse = array())
{
    return ansage_regeln_x2_felder($anlaesse);
}

/**
 * Im Vorgang sprechen, wo der Rufer das Ergebnis braucht (Antwort an Loxone, Befehl): Regeln aus dem Stamm mit "dringend, ohne Sperre" - es zaehlt nur der Schalter.
 * Schalter gesetzt: stand -1, Kennung SCHALTER. Nichts bereit oder Original-Audioserver: wie bisher ansage_sprechen(), damit die Antwort sagt, was fehlt.
 */
function cc_ansage_direkt($text, array $e = array(), $tts = null)
{
    $conf = cc_ansage_cfg();
    $k = cc_ansage_k();
    $t = $tts === null ? cc_tts($conf) : $tts;
    $a = ansage_regel_sprechen(array($e + array('schluessel' => 'direkt', 'text' => (string) $text, 'dringend' => true, 'sperre_min' => 0)), $t, cc_ansage_regeln($conf), $k);
    if ($a['r'] !== null) { return $a['r']; }
    if ($a['regel'] === 'SCHALTER') {
        return array('stand' => -1, 'kennung' => 'SCHALTER', 'art' => isset($t['mode']) ? (string) $t['mode'] : '-', 'http' => 0, 'zeile' => '', 'zeichen' => 0);
    }
    return ansage_sprechen((string) $text, $t, $k);
}

/**
 * Der Sender: eigene Sperre, nimmt die Warteliste und spricht nach den Regeln. "aus" und "kein Ziel eingerichtet" hinterlassen keine Zeile;
 * ein gescheitertes Ziel genau eine je Aenderung (ab Werk EIN darf kein Protokoll vollaufen). Text und Token stehen nie im Protokoll.
 */
function cc_ansage_senden($jetzt = null)
{
    $d = cc_paths()['datadir'];
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
    $fh = @fopen($d . '/ansage_senden.lock', 'c');
    if ($fh === false || !flock($fh, LOCK_EX | LOCK_NB)) { return 'laeuft'; }
    $erg = 'nichts';
    $l = cc_ansage_warte(function ($alt) { return array(array(), $alt); });
    $jetzt = $jetzt === null ? time() : (int) $jetzt;
    $frisch = array();
    foreach (is_array($l) ? $l : array() as $e) {
        if (is_array($e) && isset($e['zeit']) && $jetzt - (int) $e['zeit'] <= CC_ANSAGE_HOECHSTALTER) { $frisch[] = $e; }
    }
    if ($frisch) {
        $conf = cc_ansage_cfg();
        cc_ansage_token_pflegen($conf);
        $conf = cc_ansage_cfg();
        $a = ansage_regel_sprechen($frisch, cc_tts($conf), cc_ansage_regeln($conf), cc_ansage_k());
        $erg = $a['regel'] !== '' ? $a['regel'] : 'gesprochen';
        if ($a['r'] !== null) {
            $merk = $d . '/ansage_lage.txt';
            $lage = $a['r']['stand'] === 1 ? 'gesprochen' : ansage_kurz($a['r']);
            if ($a['r']['stand'] === 1 || (string) @file_get_contents($merk) !== $lage) { cc_ansage_zeile('Ansage: ' . ansage_regel_kurz($a)); }
            @file_put_contents($merk, $lage);
        } elseif ($a['regel'] !== 'AUS' && $a['regel'] !== 'KEIN_ZIEL') {
            cc_ansage_zeile('Ansage entfaellt: ' . ansage_regel_kurz($a));
        }
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    return $erg;
}

/** Eine Protokollzeile der Sprachansagen. */
function cc_ansage_zeile($t)
{
    $f = cc_log_file();
    if ($f !== '' && is_dir(dirname($f))) { @file_put_contents($f, date('Y-m-d H:i:s') . ' Oberflaeche: ' . $t . "\n", FILE_APPEND | LOCK_EX); }
}

/** Aus bin/cc_ansage.php: weg oder wieder. Der Name steht nur im Satz, nie im Protokoll. "wieder" spricht nur nach einem gesprochenen "weg". */
function cc_ansage_geraet($anlass, $name)
{
    if (!cc_ansage_anlass_an('geraet')) { return 'aus'; }
    $name = trim((string) preg_replace('/[^\p{L}\p{N} .,:!?\-]/u', ' ', (string) $name));
    $s = substr(md5($name), 0, 8);
    $e = array('schluessel' => $anlass . '|' . $s, 'text' => sprintf(cc_t($anlass === 'weg' ? 'ANSAGEPLUGIN.SATZ_WEG' : 'ANSAGEPLUGIN.SATZ_WIEDER'), $name));
    if ($anlass !== 'weg') { $e['nur_nach'] = 'weg|' . $s; }
    cc_ansage_token_pflegen();
    return cc_ansage_legen(array($e));
}

function cc_sicherung_bauen()
{
    $aus = array(
        '_hinweis' => 'Sicherung der Einstellungen von Chromecast 4 Lox NG. Enthaelt das '
                    . 'Aktionstoken (Merkwort der Oberflaeche) - vertraulich behandeln. '
                    . 'Das Sprechtoken steht nicht darin; beim Zurueckspielen bleibt das geltende.',
        '_plugin'  => 'Chromecast 4 Lox NG',
        '_stand'   => date('Y-m-d H:i:s'),
    );
    $cfg = cc_config_read();
    foreach (cc_defaults() as $k => $v) {
        if ($k === 'sprechtoken') {
            continue;   // Ansage-3: nie in der Sicherung
        }
        if ($k === 'ablage_schluessel' || $k === 'ansage_block') {
            continue;   // Audio Stufe 1: wie das Sprechtoken nie in der Sicherung; 1.3.27: der Block ebenso, seine Felder kommen ohne Token
        }
        $aus[$k] = isset($cfg[$k]) ? (string) $cfg[$k] : (string) $v;
    }
    return cc_ansage_fuer_sicherung(cc_ansage_cfg()) + $aus;
}

/**
 * X-3 (Verbesserungsbau 30.09.2026): Welche gespeicherten Werte bestuenden
 * das eigene Zurueckspielen nicht? Die Sicherung wird gebaut und durch
 * cc_sicherung_lesen() geschickt - dieselbe Pruefung wie beim Zurueckspielen.
 * Rueckgabe: Liste der NAMEN, nie der Werte; leer = die Sicherung liesse sich
 * zurueckspielen. Der Name traegt bewusst kein "sicherung" (siehe
 * Werkzeuge/sicherung_pruefen.py: es nimmt die erste Funktion *_sicherung*
 * mit json_encode fuer die Ausfuhr).
 */
function cc_rueckspiel_altwerte($ausfuhr = null)
{
    $s = is_array($ausfuhr) ? $ausfuhr : cc_sicherung_bauen();
    $js = json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($js === false) {
        // Nicht kodierbar: der Knopf meldet das selbst (TEXT.SICH_SCHREIBFEHLER).
        return array();
    }
    $namen = array();
    list($neu) = cc_sicherung_lesen($js, $namen);
    if ($neu !== null) {
        return array();
    }
    return $namen ? array_values(array_unique($namen)) : array('?');
}

/**
 * Die Einmalmeldung (seit 1.3.13, O4; Bauform BLE-Scanner NG 1.3.20,
 * Regeln/04 "Jeder POST-Handler endet mit einer Umleitung" samt Nachtrag
 * Raumklima 0.11.8). data/plugins/<ordner>/einmalmeldung.json, 0600, nur beim
 * GET gelesen, vor der Anzeige geloescht, aelter als 120 s verworfen. Sie
 * traegt fertige Meldungstexte und Suchergebnisse - keine Zugangsdaten.
 */
function cc_einmal_datei()
{
    return cc_paths()['datadir'] . '/einmalmeldung.json';
}

function cc_einmal_schreiben($daten)
{
    $d = cc_paths()['datadir'];
    if (!is_dir($d) && !@mkdir($d, 0775, true)) {
        return false;
    }
    $daten['zeit'] = time();
    $js = json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                              | JSON_INVALID_UTF8_SUBSTITUTE);
    return $js !== false && cc_datei_schreiben(cc_einmal_datei(), $js, 0600);
}

/* ==================================================================
 * Eingaben nach einer Beanstandung (Verbesserungsbau 30.09.2026, X-2;
 * Regeln/04 "Nach einer Beanstandung stehen die eingetippten Werte wieder
 * im Formular")
 *
 * Nur nach einer Beanstandung, nur das eine Formular und nur seine Felder.
 * Geheimnisse fuehren die beiden Formulare nicht (das Aktionstoken steht in
 * keinem Feld). Die Einmalmeldung bleibt 0600 (a2).
 * ================================================================== */

/** Die Felder je Formular: array(text => [...], haken => [...]). */
function cc_eingabe_felder($form)
{
    $felder = array(
        'settings' => array(
            'text'  => array('geraete', 'udp_port', 'intervall', 'aktualisierung',
                             'lautstaerke_schritt', 'favoriten', 'tts_modus', 'tts_sprache',
                             'tts_pegel', 'tts_ip', 'tts_port', 'tts_zonen', 'tts_lautstaerke',
                             'tts_vorlage', 'tts_gong', 'tts_lokal_basis', 'lautstaerke_max',
                             'ruhe_von', 'ruhe_bis', 'ruhe_max'),
            'haken' => array('enabled', 'udp', 'tts_fortsetzen', 'beschleunigung', 'gruppen',
                             'sperre_ein'),
        ),
        'mqtt' => array(
            'text'  => array('mqtt_topic'),
            // Gruppe 6b (1.3.24): die drei Haken der Befehle stehen im Formular des Reiters MQTT.
            'haken' => array('mqtt_ein', 'befehle_mqtt_ein', 'befehle_mqtt_steuern', 'befehle_cmd_ein'),
        ),
        // Ansage-3: 'nur_markierung' wird rot umrandet, reist aber nie mit -
        // das Sprechtoken kommt nicht zurueck ins Formular.
        'sprechen' => array(
            'text'  => array('sprechen_geraet', 'sprechen_stunde'),
            'haken' => array('sprechen_ein', 'sprechtoken_loeschen'),
            'nur_markierung' => array('sprechtoken'),
        ),
    );
    // Audio Stufe 1 (1.3.25): das Formular des Reiters Audio.
    $felder['audio'] = array('text' => array('zonen', 'quellen', 'ablage_pfad', 'klingel_ziel', 'klingel_rueckfall',
                                             'klingel_gong', 'klingel_pegel', 'klingel_text'), 'haken' => array());
    return isset($felder[$form]) ? $felder[$form] : null;
}

/**
 * Die eingetippten Werte eines Formulars aus $_POST, fuer die Einmalmeldung.
 * Ein Wert, der kein gueltiges UTF-8 ist oder laenger als 4096 Byte (die
 * Grenze von cc_wert_pruefen()), reist nicht mit - sonst scheiterte
 * json_encode und mit ihm die Umleitung; das Feld zeigt dann den
 * gespeicherten Stand.
 */
function cc_eingaben_sammeln($form, $beanstandet)
{
    $f = cc_eingabe_felder($form);
    if ($f === null || !$beanstandet) {
        return null;
    }
    $werte = array();
    foreach ($f['text'] as $feld) {
        if (isset($_POST[$feld]) && is_string($_POST[$feld]) && strlen($_POST[$feld]) <= 4096
            && preg_match('//u', $_POST[$feld]) === 1) {
            $werte[$feld] = $_POST[$feld];
        }
    }
    foreach ($f['haken'] as $feld) {
        $werte[$feld] = isset($_POST[$feld]) ? '1' : '';
    }
    return array('form' => $form, 'werte' => $werte,
                 'beanstandet' => array_values(array_unique(array_map('strval', $beanstandet))));
}

/** Die Eingaben aus der Einmalmeldung annehmen (nur bekannte Felder, nur Text). */
function cc_eingaben_setzen($roh = null)
{
    static $ein = array('form' => '', 'werte' => array(), 'beanstandet' => array());
    if ($roh === null) {
        return $ein;
    }
    if (!is_array($roh) || !isset($roh['form']) || !is_string($roh['form'])
        || cc_eingabe_felder($roh['form']) === null) {
        return $ein;
    }
    $f = cc_eingabe_felder($roh['form']);
    $erlaubt = array_merge($f['text'], $f['haken']);
    $markierbar = array_merge($erlaubt, isset($f['nur_markierung']) ? $f['nur_markierung'] : array());
    $werte = array();
    if (isset($roh['werte']) && is_array($roh['werte'])) {
        foreach ($roh['werte'] as $k => $v) {
            if (in_array((string) $k, $erlaubt, true) && is_string($v)) {
                $werte[(string) $k] = $v;
            }
        }
    }
    $bean = array();
    if (isset($roh['beanstandet']) && is_array($roh['beanstandet'])) {
        foreach ($roh['beanstandet'] as $b) {
            if (is_string($b) && in_array($b, $markierbar, true)) {
                $bean[] = $b;
            }
        }
    }
    if ($bean) {
        $ein = array('form' => $roh['form'], 'werte' => $werte, 'beanstandet' => $bean);
    }
    return $ein;
}

/** Wert eines Textfelds: die Eingabe nach einer Beanstandung, sonst der gespeicherte. */
function cc_eingabe($form, $feld, $gespeichert)
{
    $ein = cc_eingaben_setzen();
    if ($ein['form'] === $form && array_key_exists($feld, $ein['werte'])) {
        return $ein['werte'][$feld];
    }
    return $gespeichert;
}

/** Haken: nach einer Beanstandung der abgeschickte Stand, sonst der gespeicherte. */
function cc_eingabe_an($form, $feld, $gespeichert)
{
    $ein = cc_eingaben_setzen();
    if ($ein['form'] === $form && array_key_exists($feld, $ein['werte'])) {
        return $ein['werte'][$feld] === '1';
    }
    return (bool) $gespeichert;
}

/** Das beanstandete Feld wird rot umrandet (Klasse sm-beanstandet). */
function cc_markierung($feld)
{
    $ein = cc_eingaben_setzen();
    return in_array($feld, $ein['beanstandet'], true)
        ? ' class="sm-beanstandet" aria-invalid="true"' : '';
}

function cc_einmal_lesen()
{
    $f = cc_einmal_datei();
    if (!is_file($f)) {
        return null;
    }
    $d = json_decode((string) @file_get_contents($f), true);
    @unlink($f);
    if (!is_array($d) || !isset($d['zeit']) || abs(time() - (int) $d['zeit']) > 120) {
        return null;
    }
    $aus = array('fehler' => array());
    if (isset($d['fehler']) && is_array($d['fehler'])) {
        foreach ($d['fehler'] as $m) {
            if (is_string($m)) { $aus['fehler'][] = $m; }
        }
    }
    foreach (array('hinweis', 'test_titel', 'test_text', 'suchfehler', 'tab') as $k) {
        $aus[$k] = isset($d[$k]) && is_string($d[$k]) ? $d[$k] : '';
    }
    $aus['saved'] = !empty($d['saved']);
    $aus['gesucht'] = !empty($d['gesucht']);
    // X-2: die eingetippten Werte nach einer Beanstandung (geprueft wird in
    // cc_eingaben_setzen()).
    $aus['eingaben'] = isset($d['eingaben']) && is_array($d['eingaben']) ? $d['eingaben'] : null;
    $aus['gefunden'] = array();
    if (isset($d['gefunden']) && is_array($d['gefunden'])) {
        foreach ($d['gefunden'] as $g) {
            if (is_array($g) && isset($g['name']) && is_string($g['name'])) {
                $aus['gefunden'][] = array(
                    'name'    => $g['name'],
                    'modell'  => isset($g['modell']) && is_string($g['modell']) ? $g['modell'] : '',
                    'adresse' => isset($g['adresse']) && is_string($g['adresse']) ? $g['adresse'] : '',
                    'art'     => isset($g['art']) && is_string($g['art']) ? $g['art'] : '',
                );
            }
        }
    }
    return $aus;
}

/**
 * Was ist mit dem Dienst nach einer Handlung? (seit 1.3.13, O3/O8/I2)
 * Gemessen an den Prozessen, nicht am Rueckgabewert von kill oder nohup.
 * $vorher: Zahl der Prozesse vor der Handlung.
 */
function cc_dienst_lage_satz($vorher)
{
    $jetzt = cc_dienst_pids();
    if (count($jetzt) > 1) {
        return sprintf(cc_t('DIENST.MEHRERE_LAUFEN'), count($jetzt), implode(', ', $jetzt));
    }
    if ($jetzt) {
        return sprintf(cc_t('DIENST.LAEUFT_PID'), $jetzt[0]);
    }
    return $vorher > 0 ? cc_t('DIENST.ANGEHALTEN') : cc_t('DIENST.LIEF_NICHT_SATZ');
}

/**
 * Den Dienst nach einer Einstellung nachziehen und sagen, was geschah
 * (seit 1.3.13, O3; Regeln/05 Punkt 7): enabled=1 -> neu starten, sonst
 * anhalten, was laeuft. Rueckgabe: ein Satz.
 */
function cc_dienst_nachziehen($cfg)
{
    $vorher = count(cc_dienst_pids());
    if (cc_cfg($cfg, 'enabled', '1') === '1') {
        cc_dienst('restart');
        return cc_dienst_pid() > 0 ? cc_t('TEXT.H_NEUGESTARTET') : cc_t('TEXT.H_LAEUFT_NICHT');
    }
    if ($vorher > 0) {
        cc_dienst('stop');
    }
    return cc_t('DIENST.AUS_SCHALTER') . ' ' . cc_dienst_lage_satz($vorher);
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

/* Gruppe 6b (1.3.24): cc_dienst_themen() entfaellt - der Reiter MQTT zeigt EINE Themenliste aus cc_mqtt_namen(). */

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
    // Ohne Wurzel gibt es nichts zu lesen - nicht "/config/system/..." fragen.
    if (cc_paths()['home'] === '') {
        return 0;
    }
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
    if (cc_paths()['home'] === '') {
        return '';
    }
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

/* ------------------------------------------------------------------
 * Die Marke "Aktualisierung laeuft"
 * ------------------------------------------------------------------
 *
 * Zwischen dem Kopieren der neuen Dateien und postinstall.sh liegt beim
 * Upgrade fast eine Minute (Regeln/06, am Geraet an der Einspeisebremse
 * gemessen). In dieser Zeit ist die Konfigurationsdatei die mitgelieferte
 * Vorgabe: kein Geraet, Themenpraefix chromecast4lox, UDP-Port 7090. Die
 * Oberflaeche zeigte dem Anwender bis 1.3.10 genau diese Vorgabe als seine
 * Einstellungen an, nahm Eingaben entgegen und startete den Dienst damit -
 * und der Installer warf beides zwei Sekunden spaeter weg (in WSL gemessen
 * 17.09.2026, Fall q2b: die Eingabe "InDerLuecke" war nach postupgrade.sh
 * fort, der Dienst lief mit der Vorgabe-Konfiguration).
 *
 * preupgrade.sh legt die Marke als Erstes an, postroot.sh entfernt sie nach
 * dem Dienststart. Sie liegt NEBEN dem Datenordner, weil purge_installation
 * den Ordner selbst loescht. Aelter als eine Stunde oder unlesbar gilt sie
 * nicht: eine abgebrochene Installation darf die Seite nicht fuer immer
 * stilllegen.
 */
function cc_upgrade_marke()
{
    $d = cc_paths()['datadir'];
    return dirname($d) . '/' . basename($d) . '.upgrade_laeuft';
}

function cc_upgrade_laeuft()
{
    $f = cc_upgrade_marke();
    $roh = @is_file($f) ? @file_get_contents($f) : false;
    if ($roh === false) {
        return false;
    }
    $roh = trim((string) $roh);
    if (!preg_match('/^[0-9]{1,12}$/', $roh)) {
        return false;
    }
    $alter = time() - (int) $roh;
    // Ein paar Minuten "Zukunft" sind eine nachgestellte Uhr, keine Luege.
    return $alter > -300 && $alter < 3600;
}

/**
 * Die Benutzernummern, deren Prozesse als eigener Dienst gelten.
 *
 * Der Dienst laeuft als loxberry - daemon/daemon steigt dazu ab. Die Nummer
 * kommt aus /etc/passwd und nicht aus posix_getpwnam(): die posix-Erweiterung
 * ist auf einem LoxBerry nicht zugesichert, und eine Oberflaeche darf sich
 * auf keine Erweiterung verlassen, die nicht garantiert geladen ist
 * (Regeln/02). Dazu die eigene Nummer: was diese Seite selbst gestartet hat,
 * gehoert ihr.
 */
function cc_dienst_uids()
{
    static $u = null;
    if ($u !== null) {
        return $u;
    }
    $u = array();
    $eigen = @getmyuid();
    if ($eigen !== false) {
        $u[] = (int) $eigen;
    }
    // is_readable() VOR dem Lesen, nicht nur ein @ davor: der Pruefstand
    // rendert die Oberflaeche unter Windows, dort gibt es weder /etc/passwd
    // noch /proc, und ein eigener Fehlerbehandler sieht die Meldung auch
    // hinter dem @. Ein Fehlalarm bei jedem Lauf ist eine abgeschaltete
    // Pruefung (Regeln/02).
    $zeilen = is_readable('/etc/passwd')
        ? @file('/etc/passwd', FILE_IGNORE_NEW_LINES) : false;
    if (is_array($zeilen)) {
        foreach ($zeilen as $z) {
            $f = explode(':', $z);
            if (isset($f[2]) && $f[0] === 'loxberry') {
                $u[] = (int) $f[2];
                break;
            }
        }
    }
    $u = array_values(array_unique($u));
    return $u;
}

/**
 * ALLE Prozesse dieses Dienstes - argumentweise erkannt.
 *
 * Ein Treffer hat GENAU zwei Argumente: einen python-Interpreter und den
 * vollen Dienstpfad DIESES Plugins. Dazu gehoert er loxberry oder dem
 * Benutzer, unter dem diese Seite laeuft. Dieselbe Regel wie in
 * postroot.sh, preupgrade.sh, uninstall, daemon und cron.05min.
 *
 * Bis 1.3.10 stand hier als Rueckfallebene ein pgrep-Aufruf mit dem
 * Dateinamen als Muster und dem Schalter fuer den aeltesten Treffer. Das
 * sucht SYSTEMWEIT nach einer Zeichenkette und trifft damit auch den Dienst
 * eines zweiten Plugin-Ordners, einen Editor auf der Datei oder ein 'tail' -
 * und nimmt davon ausgerechnet den aeltesten. Der Aufruf ist hier
 * absichtlich nicht abgeschrieben: ein Kommentar darf nicht die
 * Zeichenfolge enthalten, nach der ein Werkzeug den Bestand absucht
 * (Regeln/02). In WSL gemessen (17.09.2026, Fall q1): ohne eigene
 * PID-Datei meldete cc_dienst_pid() den Dienst des NACHBARORDNERS, und
 * cc_dienst('stop') hat ihn beendet. Ausserdem sah die Oberflaeche immer nur
 * EINEN Prozess: lief nach einem Update ein zweiter, blieb er stehen.
 *
 * Rueckgabe: aufsteigend sortierte Liste von Prozessnummern.
 */
function cc_dienst_pids()
{
    $p = cc_paths();
    $skript = $p['bindir'] . '/chromecast4lox_ng-server.py';
    $uids = cc_dienst_uids();
    $aus = array();
    // Ohne /proc gibt es hier nichts zu sehen - und die Frage danach wird
    // gestellt, bevor opendir() sie mit einer Warnung beantwortet (siehe
    // cc_dienst_uids()).
    if (!is_dir('/proc')) {
        return $aus;
    }
    $d = @opendir('/proc');
    if ($d === false) {
        return $aus;
    }
    while (($e = readdir($d)) !== false) {
        if (!preg_match('/^[0-9]+$/', $e)) {
            continue;
        }
        $roh = @file_get_contents('/proc/' . $e . '/cmdline');
        if ($roh === false || $roh === '') {
            continue;
        }
        $args = explode("\0", rtrim($roh, "\0"));
        if (count($args) !== 2 || $args[1] !== $skript) {
            continue;
        }
        $i = basename($args[0]);
        if ($i !== 'python' && $i !== 'python3' && strpos($i, 'python3.') !== 0) {
            continue;
        }
        $besitzer = @fileowner('/proc/' . $e);
        if ($besitzer === false || !in_array((int) $besitzer, $uids, true)) {
            continue;
        }
        $aus[] = (int) $e;
    }
    closedir($d);
    sort($aus);
    return $aus;
}

/**
 * Laeuft der Dienst? Rueckgabe: PID oder 0.
 *
 * Die PID-Datei bleibt die erste Frage - steht ihre Nummer unter den
 * Treffern, ist sie die Antwort. Sonst gilt der erste Treffer; eine
 * PID-Datei, die ins Leere zeigt, wird abgeraeumt.
 */
function cc_dienst_pid()
{
    $pids = cc_dienst_pids();
    $datei = cc_pid_datei();
    if (!$pids) {
        if (is_file($datei)) {
            @unlink($datei);   // zeigt ins Leere - von einem Absturz uebrig
        }
        return 0;
    }
    if (is_file($datei)) {
        $eingetragen = (int) trim((string) @file_get_contents($datei));
        if (in_array($eingetragen, $pids, true)) {
            return $eingetragen;
        }
    }
    return $pids[0];
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
    // Aus einem Archiv wird nichts gestartet und nichts beendet (Muster 3).
    if ($p['home'] === '') {
        return cc_t('DIENST.ARCHIV');
    }
    $skript = $p['bindir'] . '/chromecast4lox_ng-server.py';
    $meldungen = array();

    $datei = cc_pid_datei();
    if (in_array($aktion, array('stop', 'restart'), true)) {
        // ALLE eigenen Prozesse, nicht einer. Bis 1.3.10 wurde genau die eine
        // Nummer aus cc_dienst_pid() beendet; lief nach einem Update ein
        // zweiter Dienst, blieb er stehen und hielt den UDP-Port (in WSL
        // gemessen 17.09.2026, Fall q1: nach "stop" lief noch einer).
        $pids = cc_dienst_pids();
        if ($pids) {
            foreach ($pids as $pid) {
                @exec('kill ' . (int) $pid . ' 2>&1', $meldungen);
            }
            // Zeit lassen: auf SIGTERM meldet der Dienst server/online=0,
            // die Geraete als Platzhalter offline und tts_active 0 (seit
            // 1.3.13, C11 - bis 1.3.12 hatte er keinen Behandler dafuer, und
            // dieser Satz stimmte nicht). Hart abgeschossen bliebe im Broker
            // ein retained '1' stehen, und Loxone glaubte weiter an einen
            // laufenden Dienst.
            for ($i = 0; $i < 20; $i++) {
                usleep(500000);
                if (!cc_dienst_pids()) {
                    break;
                }
            }
            $rest = cc_dienst_pids();
            if ($rest) {
                foreach ($rest as $pid) {
                    @exec('kill -9 ' . (int) $pid . ' 2>&1', $meldungen);
                }
                usleep(500000);
                $meldungen[] = cc_t('DIENST.KILL9');
            }
            if (count($pids) > 1) {
                $meldungen[] = sprintf(cc_t('DIENST.MEHRERE'), count($pids));
            }
        } else {
            $meldungen[] = cc_t('DIENST.LIEF_NICHT');
        }
        @unlink($datei);
    }
    if (in_array($aktion, array('start', 'restart'), true)) {
        if (!is_file($skript)) {
            return sprintf(cc_t('DIENST.NICHT_GEFUNDEN'), $skript);
        }
        // In die Startdatei, nicht in das Protokoll (seit 1.3.13, C12): dort
        // schreibt allein der Dienst selbst. Hier landet nur, was vor seinem
        // Protokoll scheitert.
        $log = $p['logdir'] . '/' . $p['plugin'] . '_start.log';
        if (!is_dir(dirname($datei))) {
            @mkdir(dirname($datei), 0775, true);
        }
        if (!is_dir($p['logdir'])) {
            @mkdir($p['logdir'], 0775, true);
        }
        @exec('nohup ' . escapeshellarg($skript) . ' >> ' . escapeshellarg($log)
            . ' 2>&1 & echo $! > ' . escapeshellarg($datei), $meldungen);
        $pid = 0;
        for ($i = 0; $i < 16; $i++) {
            usleep(500000);
            $pid = cc_dienst_pid();
            if ($pid > 0) {
                break;
            }
        }
        // "gestartet" nur nach Messung (seit 1.3.13, I2). Bis 1.3.12 stand
        // hier ein festes "echo gestartet" - auch wenn die Schale die
        // Protokolldatei nicht anlegen durfte und kein Dienst lief (in WSL
        // gemessen 30.09.2026, Installer-Befund 2).
        $meldungen[] = $pid > 0 ? sprintf(cc_t('DIENST.GESTARTET'), $pid)
                                : sprintf(cc_t('DIENST.NICHT_ANGELAUFEN'), $log);
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
        return array(array(), sprintf(cc_t('SUCHE.F_KEIN_SKRIPT'), $skript));
    }
    $roh = array();
    $rc = 0;
    // "-k 5": ein Python, das SIGTERM nicht beachtet, hielt die Seite sonst
    // beliebig lange fest (Muster 13 der Nachlese; in WSL gemessen
    // 25.09.2026, Fall H5: bis zum aeusseren Abbruch nach 85 s).
    @exec('timeout -k 5 30 python3 ' . escapeshellarg($skript) . ' --json'
          . ($ohne_gruppen ? ' --ohne-gruppen' : '') . ' 2>&1', $roh, $rc);
    $text = trim(implode("\n", $roh));
    if ($rc === 124 || $rc === 137) {
        return array(array(), sprintf(cc_t('SUCHE.F_ZEIT'), 30, $rc)
            . ($text !== '' ? "\n" . $text : ''));
    }
    // Den Rueckgabewert auswerten, nicht nur die Ausgabe: eine leere
    // Ausgabe bei einem Abbruch ist kein "nichts gefunden", sondern das
    // Gegenteil.
    if ($rc !== 0) {
        return array(array(), $text !== '' ? $text : sprintf(cc_t('SUCHE.F_RC'), $rc));
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
    // Das Protokoll des Dienstes zuerst (seit 1.3.13, C12): daneben liegt die
    // Startdatei <plugin>_start.log, und die juengere war nicht immer die
    // richtige. Fehlt das Protokoll, zeigt die Seite die juengste Datei.
    $haupt = $p['logdir'] . '/' . $p['plugin'] . '.log';
    if (is_file($haupt)) {
        return $haupt;
    }
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
    $lines = cc_zeilen((string) @file_get_contents($file));
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

/* Gruppe 6b (1.3.24): cc_xml_virtual_in_http() entfaellt mit der Eingangsvorlage mqtt_in
 * (Pruefbericht E2) - Gateway-Werte sind einfache Virtuelle Eingaenge (Namenstabelle). */

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
        $o .= 'HintText="' . cc_x(isset($c['hint']) ? $c['hint'] : '') . '"';
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
 * Kommentar eines Vorlagenbefehls - hoechstens 40 Zeichen (seit 1.3.13, O16).
 * Loxone Config macht den Kommentar zum ANZEIGENAMEN der Kachel (Regeln/07).
 * Bis 1.3.12 stand "<Geraet> - <Beschriftung>" ungekuerzt da; mit
 * "Küche Lautsprecher Obergeschoss" waren 5 von 23 Eingaengen und 11 von 45
 * Ausgaengen laenger als 40 Zeichen (gemessen 30.09.2026, Oberflaechen-Befund
 * 16). Gekuerzt wird der Geraetename, die Beschriftung bleibt ganz.
 */
function cc_kommentar($geraet, $kurz)
{
    $grenze = 40;
    $voll = $geraet . ' - ' . $kurz;
    if (cc_zeichenzahl($voll) <= $grenze) {
        return $voll;
    }
    $platz = $grenze - cc_zeichenzahl(' - ' . $kurz) - 1;
    if ($platz < 1) {
        return cc_zeichen_kuerzen($kurz, $grenze);
    }
    return cc_zeichen_kuerzen($geraet, $platz) . "\u{2026}" . ' - ' . $kurz;
}

/** Zeichen, nicht Byte - ohne mbstring (auf dem LoxBerry nicht zugesichert). */
function cc_zeichenzahl($s)
{
    $n = @preg_match_all('/./us', (string) $s);
    return $n === false ? strlen((string) $s) : $n;
}

function cc_zeichen_kuerzen($s, $n)
{
    if (@preg_match('/^.{0,' . (int) $n . '}/us', (string) $s, $m) === 1) {
        return $m[0];
    }
    return substr((string) $s, 0, (int) $n);
}

/** Hinweistext eines Themas - die ausfuehrliche Erklaerung ohne Auszeichnung. */
function cc_hinweis_text($schluessel)
{
    return trim(html_entity_decode(strip_tags(cc_thema_lang($schluessel)), ENT_QUOTES, 'UTF-8'));
}

/**
 * Welche Befehle kommen in die Ausgangsvorlagen? (seit 1.3.13, M3)
 * Ein Textbefehl (tts) nicht: ein virtueller Ausgang schickt dort einen
 * festen Wert, und aus "tts 1" wurde eine Ansage "eins" (gemessen 30.09.2026,
 * Pruefung MQTT M3). Den Text bekommt man ueber einen Ausgang mit eigenem
 * Befehlstext oder ueber MQTT.
 */
function cc_vorlagen_befehle()
{
    $aus = array();
    foreach (cc_themen()['befehle'] as $e) {
        if ($e['art'] !== 'text') {
            $aus[] = $e;
        }
    }
    return $aus;
}

/* Gruppe 6b (1.3.24): cc_vorlage_zahlen() entfaellt - der Reiter Einbindung nennt die Werte in der Namenstabelle
 * (cc_mqtt_namen()) und die Befehle aus der Befehlsbruecke. */

/**
 * Vorlage erzeugen.
 * $art: bruecke | mqtt_out | udp_out (seit 1.3.24; die Eingangsvorlage mqtt_in mit http://localhost entfaellt -
 * Pruefbericht E2, R2_078: je Wert ein einfacher Virtueller Eingang nach der Namenstabelle)
 * Rueckgabe: array(dateiname, inhalt)
 */
function cc_vorlage($art, $cfg, $geraete)
{
    $praefix = cc_cfg($cfg, 'mqtt_topic', 'chromecast4lox');
    $ip = cc_localip();
    $fuss = 'Erzeugt vom LoxBerry-Plugin Chromecast 4 Lox NG (' . date('d.m.Y') . ')';

    if ($art === 'bruecke') {
        /* Gruppe 6b (1.3.24, Entwurf cc 3.7): eigener Virtueller Ausgang "Chromecast 4 Lox NG MQTT-Befehle" an den
         * UDP-Eingang des MQTT-Gateways; je Geraet und "alle" die 14 Nicht-Text-Befehle der Befehlsbruecke
         * (publish <praefix>/befehl/<Befehlsname>/<befehl>, nie retain), dazu die Sperre (V3: EIN sperre 1, AUS
         * sperre 0). Titel "Chromecast: <Geraet> <Kurz>" wie im Musterprojekt - kein Titel heisst wie ein
         * Gateway-Eingang (Doppelpunkt, Leerzeichen; Pruefbericht E1). lauter/leiser senden 1: die Schrittweite
         * stellt das Plugin ein, die Vorlage muss danach nicht neu erzeugt werden (E6). Kein tts: ein fester
         * Ansagetext gehoert von Hand in den Ausgang (Reiter Einbindung, Schritt 3). */
        $port = cc_mqtt_udpinport();
        $b = $praefix . '/befehl/';
        $ziele = array();
        foreach ($geraete as $g) {
            $ziele[] = array($g, cc_befehl_kennung(cc_thema($g)));
        }
        $ziele[] = array(cc_sammelziel(), cc_sammelziel());
        $cmds = array();
        foreach ($ziele as $z) {
            foreach (cc_vorlagen_befehle() as $e) {
                $bf = $e['schluessel'];
                $analog = cc_befehl_analog($bf);
                $cmds[] = array(
                    'title'   => 'Chromecast: ' . $z[0] . ' ' . cc_befehl_titel_kurz($bf),
                    'comment' => cc_kommentar($z[0], cc_thema_kurz('cmd_' . $bf)),
                    'on'      => 'publish ' . $b . $z[1] . '/' . $bf . ' ' . ($analog ? '<v.0>' : '1'),
                    'analog'  => $analog,
                    'min'     => $e['min'], 'max' => $e['max'],
                    'hint'    => cc_hinweis_text('cmd_' . $bf),
                );
            }
        }
        $cmds[] = array(
            'title'   => 'Chromecast: Ansagen sperren',
            'comment' => cc_zeichen_kuerzen(cc_t('BEFEHL.C_SPERRE'), 40),
            'on'      => 'publish ' . $b . 'sperre 1',
            'off'     => 'publish ' . $b . 'sperre 0',
            'analog'  => false,
            'hint'    => cc_t('BEFEHL.H_SPERRE_VORLAGE'),
        );
        return array('VQ_Chromecast4Lox_Befehle.xml', cc_xml_virtual_out(array(
            'title'   => 'Chromecast 4 Lox NG MQTT-Befehle',
            'address' => '/dev/udp/' . $ip . '/' . ($port > 0 ? $port : 11884),
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
        // Gruppe 6b (1.3.24): der alte Weg <praefix>/<Geraet>/cmd/<befehl> fuer bestehende Anlagen (Haken
        // befehle_cmd_ein). Titel "Chromecast alt: <Geraet> <Kurz>" statt "<praefix>_<thema>_<befehl>" - bis 1.3.23
        // hiess der Ausgang volume genau wie der Gateway-Eingang (Pruefbericht E1, Schleifengefahr unter V1).
        // Die Befehle sind unveraendert.
        foreach (array_merge(array(cc_sammelziel()), $geraete) as $g) {
            $t = cc_thema($g);
            foreach (cc_vorlagen_befehle() as $e) {
                $b = $e['schluessel'];
                $analog = cc_befehl_analog($b);
                $cmds[] = array(
                    'title'   => 'Chromecast alt: ' . $g . ' ' . cc_befehl_titel_kurz($b),
                    'comment' => cc_kommentar($g, cc_thema_kurz('cmd_' . $b)),
                    'on'      => $praefix . '/' . $t . '/cmd/' . $b . ' '
                                 . ($analog ? '<v.0>' : cc_vorlage_festwert($b, $cfg)),
                    'analog'  => $analog,
                    'min'     => $e['min'], 'max' => $e['max'],
                    'hint'    => cc_hinweis_text('cmd_' . $b),
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
            foreach (cc_vorlagen_befehle() as $e) {
                $b = $e['schluessel'];
                $analog = cc_befehl_analog($b);
                $fest = cc_vorlage_festwert($b, $cfg);
                $cmds[] = array(
                    'title'   => $t . '_' . $b,
                    'comment' => cc_kommentar($g, cc_thema_kurz('cmd_' . $b)),
                    'on'      => $t . '/' . strtoupper($b)
                                 . ($analog ? ' <v.0>' : ($fest !== '1' ? ' ' . $fest : '')) . ';',
                    'analog'  => $analog,
                    'min'     => $e['min'], 'max' => $e['max'],
                    'hint'    => cc_hinweis_text('cmd_' . $b),
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

/**
 * Der feste Wert eines digitalen Ausgangsbefehls (seit 1.3.13, M3).
 * volume_up/volume_down tragen die eingestellte Schrittweite - so, wie es die
 * Hilfe sagt. Bis 1.3.12 schickte die Vorlage "1", und der Dienst las das als
 * Schritt von 1 %: die Einstellung lautstaerke_schritt wirkte ueber MQTT nie
 * (gemessen 30.09.2026, Pruefung MQTT M3). Wer die Schrittweite aendert, legt
 * die Ausgangsvorlage neu an; der Reiter sagt es.
 */
function cc_vorlage_festwert($befehl, $cfg)
{
    if ($befehl === 'volume_up' || $befehl === 'volume_down') {
        list($w, $f) = cc_wert_pruefen('lautstaerke_schritt',
            cc_cfg($cfg, 'lautstaerke_schritt', '5'), $cfg);
        return $f === '' ? $w : '5';
    }
    return '1';
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
        //
        // Die Wurzel kommt aus cc_paths() (seit 1.3.12). Bis 1.3.11 stand hier
        // ein fester Systempfad als Rueckfall, und ohne Wurzel wurde
        // "/templates/plugins/..." gefragt - ein Pfad ab "/" (Muster 1 und 2
        // der Nachlese; in WSL gemessen 25.09.2026, Fall H4).
        $cc_w = cc_paths();
        $pfad = $cc_w['home'] !== ''
            ? $cc_w['home'] . '/templates/plugins/' . $cc_w['plugin'] . '/lang' : '';
        if ($pfad === '' || !is_dir($pfad)) {
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


/**
 * Eine Sicherungsdatei einlesen - und dabei NICHTS durchgehen lassen.
 *
 * Die sieben Punkte aus REGELN_2, und der wichtigste ist der dritte: eine
 * halb gueltige Datei ueberschreibt GAR NICHTS. Wer eine Sicherung
 * zurueckspielt, will entweder den ganzen Stand oder gar keinen - eine zur
 * Haelfte uebernommene Konfiguration ist schlimmer als die alte, und man
 * sieht es ihr nicht an.
 *
 * Unbekannte Schluessel sind eine Beanstandung, kein stiller Verlust: sie
 * stammen aus einer anderen Fassung oder einem anderen Plugin.
 *
 * Rueckgabe: array(Konfiguration|null, Beanstandungen[], uebernommene Werte).
 */
function cc_sicherung_lesen($roh, &$namen = null)
{
    $roh = cc_ansage_sicherung_vor($roh);     // 1.3.27: Token und Vermerk bleiben die geltenden
    $cc_ab = array();
    $mangel = array();
    // X-3 (Verbesserungsbau 30.09.2026): die Namen der beanstandeten
    // Schluessel, nie ihre Werte.
    $namen = array();
    $daten = json_decode((string) $roh, true);
    if (!is_array($daten)) {
        return array(null, array(cc_t('TEXT.SICH_KEIN_JSON')), 0);
    }
    $neu = cc_defaults();
    $bekannt = array_keys($neu);
    $bisher = cc_config_read();
    $anzahl = 0;
    foreach ($daten as $k => $w) {
        // Der lesbare Kopf (_hinweis, _stand, ...) wird UEBERGANGEN, nicht
        // beanstandet (seit 1.3.13, O3; Regeln/05). Bis 1.3.12 wurde eine
        // Sicherung mit Kopf als "Unbekannte Einstellung" abgewiesen.
        if (is_string($k) && $k !== '' && $k[0] === '_') {
            continue;
        }
        if ($k === 'ansage_block') {     // 1.3.27: der Block mit den Sprechtoken kommt nie aus einer Datei
            $mangel[] = sprintf(cc_t('TEXT.SICH_FREMD'), 'ansage_block');
            continue;
        }
        if (cc_ansage_wert($k, $w) !== null) {
            if (cc_ansage_wert($k, $w) === true) { $cc_ab[$k] = $w; } else { $mangel[] = cc_t('ANSAGEPLUGIN.M_FEHL'); $namen[] = (string) $k; }
            continue;
        }
        if (!in_array($k, $bekannt, true)) {
            $mangel[] = sprintf(cc_t('TEXT.SICH_FREMD'),
                                 htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8'));
            $namen[] = (string) $k;
            continue;
        }
        // Jeder WERT wird geprueft wie beim Speichern (seit 1.3.13, C2).
        list($gut, $fehler) = cc_wert_pruefen($k, $w, $bisher);
        if ($fehler !== '') {
            $mangel[] = $fehler;
            $namen[] = (string) $k;
            continue;
        }
        $neu[$k] = $gut;
        $anzahl++;
    }
    if ($anzahl === 0) {
        $mangel[] = cc_t('TEXT.SICH_LEER');
    }
    /* FEHLENDE Schluessel sind eine Beanstandung, kein stiller Rueckfall.
     *
     * Bis hierher war die Vorgabenliste der Ausgangspunkt, und nur was in
     * der Datei stand wurde darueber geschrieben. Eine Datei mit einem
     * einzigen Schluessel lief damit ohne Beanstandung durch, wurde
     * gespeichert, und alle uebrigen Einstellungen fielen auf Werk
     * zurueck - quittiert mit "1 Wert uebernommen".
     *
     * Gemessen an VolkswagenID 0.9.11 am 03.09.2026 unter PHP 7.4 und 8.4:
     * dort fiel dabei auch das Aktionstoken auf '', und jede im Miniserver
     * eingetragene Adresse war stumm ungueltig. Am 07.09.2026 ueber den
     * Bestand ausgerollt (30 Linien).
     *
     * Der Hausstandard sagt: eine halb gueltige Datei aendert gar nichts.
     * Verglichen wird gegen die VORGABEN, nicht gegen $bekannt: was
     * ausserhalb der Konfigurationsdatei liegt - Zugangsdaten in einer
     * eigenen Datei - faellt nicht auf Werk zurueck und darf hier fehlen. */
    /* Ansage-3: die vier Schluessel der Sprachausgabe kennt eine Sicherung
     * bis 1.3.15 nicht. Fehlen sie, bleibt der GELTENDE Wert (nicht die
     * Werkseinstellung) - das Sprechtoken fehlt in jeder Sicherung. Dasselbe
     * gilt fuer sperre_ein (B1, 1.3.22), den Sicherungen bis 1.3.21 nicht kennen. */
    foreach (cc_sicherung_spaet() as $sk) {
        if (!array_key_exists($sk, $daten)) {
            $neu[$sk] = (string) cc_cfg($bisher, $sk, isset($neu[$sk]) ? $neu[$sk] : '');
        }
    }
    /* Gruppe 6b (1.3.24, Entwurf cc 3.4, Bauart WOLF wi_lib.php): eine Sicherung bis 1.3.23 stammt aus einer Fassung
     * mit dem alten Befehlsweg - fehlt befehle_cmd_ein, gilt 1, damit ihr Loxone-Projekt weiter schaltet. Fehlen
     * befehle_mqtt_ein und befehle_mqtt_steuern, bleibt (oben) der geltende Wert. */
    if (!array_key_exists('befehle_cmd_ein', $daten)) {
        $neu['befehle_cmd_ein'] = '1';
    }
    // Das Standardgeraet muss zur Geraeteliste DER SICHERUNG passen.
    if (!in_array('sprechen_geraet', $namen, true) && (string) $neu['sprechen_geraet'] !== '') {
        list($cc_sh) = cc_sprechen_ziele($neu['sprechen_geraet'], cc_geraete($neu));
        if ($cc_sh !== 200) {
            $mangel[] = sprintf(cc_t('SPRECHEN.F_GERAET'), cc_e($neu['sprechen_geraet']));
            $namen[] = 'sprechen_geraet';
        }
    }
    $fehlend = array();
    foreach (array_keys(cc_defaults()) as $fk) {
        if (in_array($fk, cc_sicherung_spaet(), true)) {
            continue;
        }
        if (!is_array($daten) || !array_key_exists($fk, $daten)) {
            $fehlend[] = $fk;
        }
    }
    if ($fehlend) {
        $namen = array_merge($namen, $fehlend);
        $mangel[] = sprintf(cc_t('TEXT.SICH_FEHLEND'), count($fehlend),
            htmlspecialchars(implode(', ', $fehlend), ENT_QUOTES, 'UTF-8'));
    }
    if ($cc_ab) { $neu['ansage_block'] = base64_encode((string) json_encode($cc_ab)); }     // 1.3.27
    return array($mangel ? null : $neu, $mangel, $anzahl);
}

/** Ansage-3: die Schluessel der Sprachausgabe (in Sicherungen bis 1.3.15 nicht dabei). */
function cc_sprechen_schluessel()
{
    return array('sprechen_ein', 'sprechen_geraet', 'sprechen_stunde', 'sprechtoken');
}

/** Schluessel, die aeltere Sicherungen nicht kennen: die der Sprachausgabe (bis
 *  1.3.15), sperre_ein (B1, bis 1.3.21) und die drei Haken der Befehle (bis 1.3.23).
 *  Fehlen sie, gilt der GELTENDE Wert - fuer befehle_cmd_ein 1 (cc_sicherung_lesen). */
function cc_sicherung_spaet()
{
    return array_merge(cc_sprechen_schluessel(), array('sperre_ein', 'ansage_block'), cc_befehle_haken(), cc_audio_schluessel());
}

/* ==================================================================
 * Sprachausgabe fuer andere Plugins (Punkt Ansage-3, 01.10.2026)
 *
 * Endpunkt webfrontend/html/index.php mit DERSELBEN Schnittstelle wie
 * Alexa-NG (/plugins/alexang/index.php): POST aktion=sprechen, token, geraet,
 * text, laut; ?selftest=1&token=...; ?aktion=status. Ein Verbraucher-Plugin,
 * das Alexa-NG anspricht, tauscht nur den Ordner in der Adresse und die
 * Geraetenamen. Ab Werk aus (sprechen_ein=0), eigenes Sprechtoken,
 * nur Aufrufer auf diesem LoxBerry (127.0.0.1/::1).
 *
 * Uebergabe an den Dienst ueber eine Auftragsdatei in
 * data/plugins/<ordner>/sprechen/ (siehe SprechEingang im Dienst). Der
 * Endpunkt wartet hoechstens CC_SPRECHEN_FRIST Sekunden auf die Antwort des
 * Dienstes und meldet dann ehrlich: OK=1 heisst "vom laufenden Dienst bei
 * GERAETE verbundenen Lautsprechern eingereiht" (GRUND=EINGEREIHT) - die
 * Wiedergabe selbst dauert 3 bis 60 s und wird nicht abgewartet; sonst liefe
 * jede Ansage in die 10-s-Frist der Verbraucher (abfahrt_alexa_rufen). Kommt
 * die Antwort nicht rechtzeitig, zieht der Endpunkt den Auftrag zurueck,
 * bevor er 503 meldet - es wird dann nichts gesprochen.
 * ================================================================== */

define('CC_SPRECHEN_FRIST', 5.0);
/* 1.3.31 (Befund Hausherr 05.11.2026, Testansagen): bis 1.3.30 30 s, und ein Treffer antwortete 200 OK=1 GRUND=UNVERAENDERT
 * nach 4-6 ms, ohne den Dienst zu fragen - der Verbraucher (sprachausgabe.php, ansage_ng_bewerten) meldete "ok", Google
 * sprach nicht. Eine gewollte Wiederholung (Klingel zweimal, Testansage mehrerer Plugins nacheinander) verschwand still.
 * Jetzt nur noch gegen eine echte Doppelausloesung (5 s) und ehrlich: 429 OK=0 GRUND=DOPPELT. */
define('CC_SPRECHEN_FENSTER', 5);

function cc_sprechen_ordner()
{
    return cc_paths()['datadir'] . '/sprechen';
}

/** Sprechtoken: 16 bis 128 Zeichen aus Buchstaben, Ziffern, _ und - (die
 *  Verbraucher pruefen 8 bis 128 derselben Zeichen; "Array" ist die Spur einer
 *  umgewandelten Liste, Klasse 12). */
function cc_sprechtoken_taugt($t)
{
    return is_string($t) && strcasecmp($t, 'Array') !== 0
        && preg_match('/^[A-Za-z0-9_\-]{16,128}\z/', $t) === 1;
}

/** Antwortzeile wie Alexa-NG: KOPF;FELD=WERT;... - Werte ohne ; = und Umbruch. */
function cc_zeile($kopf, array $felder)
{
    $z = $kopf;
    foreach ($felder as $k => $v) {
        $z .= ';' . $k . '=' . preg_replace('/[;=\x00-\x1F\x7F]/', '_', (string) $v);
    }
    return $z;
}

/** Nur Aufrufer auf diesem LoxBerry. */
function cc_ist_lokal($adresse)
{
    return in_array((string) $adresse, array('127.0.0.1', '::1', '::ffff:127.0.0.1'), true);
}

/** Der Port des LoxBerry-Webservers aus general.json (Rueckfall 80). */
function cc_webport()
{
    static $port = null;
    if ($port !== null) {
        return $port;
    }
    $port = 80;
    $home = cc_paths()['home'];
    if ($home !== '' && is_file($home . '/config/system/general.json')) {
        $g = json_decode((string) @file_get_contents($home . '/config/system/general.json'), true);
        foreach (array('Webserver', 'WEBSERVER') as $ab) {
            if (is_array($g) && isset($g[$ab]['Port']) && (int) $g[$ab]['Port'] > 0) {
                $port = (int) $g[$ab]['Port'];
                break;
            }
        }
    }
    return $port;
}

/** Die Adresse fuer andere Plugins auf diesem LoxBerry. */
function cc_sprechen_adresse()
{
    return 'http://127.0.0.1:' . cc_webport() . '/plugins/' . cc_paths()['plugin'] . '/index.php';
}

/**
 * Arbeitet der Dienst? Am Herzschlag in zustand.json gemessen, nicht an einer
 * Prozessnummer (dreimal das Intervall, mindestens 60 s - wie der Reiter Test).
 * Rueckgabe array(arbeitet, alter in s oder -1, Zustand oder null).
 */
function cc_dienst_herz(array $cfg)
{
    $f = cc_paths()['datadir'] . '/zustand.json';
    $zu = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
    if (!is_array($zu) || !isset($zu['zeit'])) {
        return array(false, -1, null);
    }
    $alter = time() - (int) $zu['zeit'];
    $grenze = max(60, 3 * (int) cc_cfg($cfg, 'intervall', '10'));
    return array($alter >= -300 && $alter <= $grenze, max(0, $alter), $zu);
}

/**
 * Die Ziele aus dem Feld geraet. Kommaliste aus Geraetenamen, wie sie in der
 * Geraeteliste stehen (auch das MQTT-Thema, Gross/klein egal - wie
 * geraet_finden() im Dienst), "gruppe:<name>" (Schreibweise von Alexa-NG; eine
 * Google-Lautsprechergruppe steht hier wie ein Geraet in der Liste) und
 * "alle". Ein Name, der selbst ein Komma traegt, wird zuerst als Ganzes
 * gesucht. Nie ein Rueckfall auf "alle".
 * Rueckgabe array(http, grund, namen, unbekannter Name).
 */
function cc_sprechen_ziele($param, array $geraete)
{
    $finden = function ($k) use ($geraete) {
        foreach ($geraete as $g) {
            if ($k === $g || $k === cc_thema($g)) {
                return $g;
            }
        }
        $kl = strtolower($k);
        foreach ($geraete as $g) {
            if ($kl === strtolower($g) || $kl === strtolower(cc_thema($g))) {
                return $g;
            }
        }
        // Audio Stufe 1 (S6): eine Zone als zone_<n> - gemeint ist ihr Ziel, wenn es eingetragen ist.
        $nr = cc_zone_nummer($kl);
        if ($nr > 0) {
            foreach (cc_zonen(cc_config_read()) as $zn) {
                if ($zn['nr'] === $nr && in_array($zn['ziel'], $geraete, true)) {
                    return $zn['ziel'];
                }
            }
        }
        return null;
    };
    $ganz = $finden(trim((string) $param));
    if ($ganz !== null) {
        return array(200, '', array($ganz), '');
    }
    $aus = array();
    foreach (explode(',', (string) $param) as $roh) {
        $roh = trim($roh);
        if ($roh === '') {
            return array(400, 'GERAET', array(), '');
        }
        $g = $finden($roh);
        if ($g === null && in_array(strtolower($roh), array(cc_sammelziel(), 'all', '*'), true)) {
            if (!$geraete) {
                return array(404, 'KEINE_GERAETE', array(), '');
            }
            foreach ($geraete as $gg) {
                if (!in_array($gg, $aus, true)) {
                    $aus[] = $gg;
                }
            }
            continue;
        }
        $gruppe = false;
        if ($g === null && stripos($roh, 'gruppe:') === 0) {
            $gruppe = true;
            $roh = trim(substr($roh, 7));
            $g = $roh === '' ? null : $finden($roh);
        }
        if ($g === null) {
            return array(404, $gruppe ? 'GRUPPE_UNBEKANNT' : 'GERAET_UNBEKANNT', array(),
                         cc_zeichen_kuerzen($roh, 60));
        }
        if (!in_array($g, $aus, true)) {
            $aus[] = $g;
        }
    }
    return array(200, '', $aus, '');
}

/** Eine kleine Datei unter data/ lesen (JSON), sonst null. */
function cc_sprechen_json($name)
{
    $f = cc_paths()['datadir'] . '/' . $name;
    if (!is_file($f)) {
        return null;
    }
    $d = json_decode((string) @file_get_contents($f), true);
    return is_array($d) ? $d : null;
}

/** Die Bremse: Stundenliste und Gleichtext-Fenster je Geraet. */
function cc_sprechen_bremse_lesen()
{
    $b = cc_sprechen_json('sprechen_bremse.json');
    if (!is_array($b)) {
        $b = array();
    }
    $jetzt = time();
    $stunde = (isset($b['stunde']) && is_array($b['stunde'])) ? $b['stunde'] : array();
    $b['stunde'] = array_values(array_filter($stunde, function ($t) use ($jetzt) {
        return is_int($t) && $jetzt - $t < 3600 && $t <= $jetzt + 300;
    }));
    $fenster = array();
    if (isset($b['fenster']) && is_array($b['fenster'])) {
        foreach ($b['fenster'] as $k => $e) {
            if (is_string($k) && is_array($e) && isset($e['h'], $e['t']) && is_string($e['h'])
                && is_int($e['t']) && $jetzt - $e['t'] < 3600) {
                $fenster[$k] = array('h' => $e['h'], 't' => $e['t']);
            }
        }
    }
    $b['fenster'] = $fenster;
    return $b;
}

/**
 * Eine Ansage annehmen und an den Dienst uebergeben - EINE Funktion fuer den
 * Endpunkt und den Knopf "Testansage" im Reiter Test (Regeln/03: Trockenlauf
 * und Ernstfall in derselben Funktion). Die Tokenpruefung macht der Aufrufer;
 * erst danach wird geschrieben. Der Text kommt in keine Antwort, keine
 * Protokollzeile und keine Merkdatei - nur seine Laenge.
 * $par: geraet, text, laut, ssml, dringend (Zeichenketten, auf is_string geprueft).
 * Rueckgabe array(http, felder); felder beginnt mit OK und endet mit GRUND.
 */
function cc_sprechen_ausfuehren(array $par, $quelle, $wer)
{
    $cfg = cc_config_read();
    $ziele = array();
    $laenge = 0;
    $ende = function ($http, array $f) use ($quelle, $wer, &$ziele, &$laenge) {
        if (!isset($f['GRUND'])) {
            $f['GRUND'] = '-';
        }
        $d = cc_paths()['datadir'];
        if (is_dir($d) || @mkdir($d, 0775, true)) {
            $js = json_encode(array('zeit' => time(), 'quelle' => (string) $quelle,
                'wer' => cc_zeichen_kuerzen((string) $wer, 46), 'http' => (int) $http,
                'ok' => (int) $f['OK'], 'grund' => (string) $f['GRUND'], 'geraete' => $ziele,
                'laenge' => (int) $laenge), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($js !== false) {
                cc_datei_schreiben($d . '/sprechen_letzte.json', $js, 0600);
            }
        }
        return array((int) $http, $f);
    };
    $f = array('OK' => 0);

    if (cc_upgrade_laeuft()) {
        $f['GRUND'] = 'AKTUALISIERUNG';
        return $ende(503, $f);
    }
    if (in_array(cc_config_zustand(), array('unlesbar', 'leer', 'gekuerzt'), true)) {
        $f['GRUND'] = 'KONFIGURATION';
        return $ende(503, $f);
    }
    if ((string) cc_cfg($cfg, 'sprechen_ein', '0') !== '1') {
        $f['GRUND'] = 'SPRECHEN_AUS';
        return $ende(409, $f);
    }

    // ---- Parameter pruefen: abweisen, nie zurechtbiegen (wie Alexa-NG) ----
    if (!isset($par['text'])) {
        $f['GRUND'] = 'TEXT_FEHLT';
        return $ende(400, $f);
    }
    if (isset($par['ssml']) && !in_array($par['ssml'], array('0', '1'), true)) {
        $f['GRUND'] = 'SSML';
        return $ende(400, $f);
    }
    if (isset($par['ssml']) && $par['ssml'] === '1') {
        // SSML kennt ein Google-Lautsprecher ueber diesen Weg nicht.
        $f['GRUND'] = 'SSML';
        return $ende(400, $f);
    }
    $text = (string) $par['text'];
    if (preg_match('//u', $text) !== 1 || preg_match('/[\x00-\x1F\x7F]/', $text)) {
        $f['GRUND'] = 'TEXT';
        return $ende(400, $f);
    }
    $text = trim($text);
    if ($text === '' || $text === '0') {
        // Statusbaustein-Falle (wie Alexa-NG): nichts sprechen, kein Fehler.
        return $ende(200, array('OK' => 1, 'UEBERSPRUNGEN' => 1, 'GRUND' => 'TEXT_NULL'));
    }
    $laenge = cc_zeichenzahl($text);
    if ($laenge > 1000) {
        $f['GRUND'] = 'TEXT';
        return $ende(400, $f);
    }
    if (strpbrk($text, '<>') !== false) {
        $f['GRUND'] = 'SSML_OHNE_SCHALTER';
        return $ende(400, $f);
    }
    $laut = null;
    if (isset($par['laut']) && $par['laut'] !== '') {
        if (!preg_match('/^[0-9]{1,3}\z/', $par['laut']) || (int) $par['laut'] > 100) {
            $f['GRUND'] = 'LAUT';
            return $ende(400, $f);
        }
        $laut = (int) $par['laut'];
    }
    // dringend: 0 oder 1 wie bei Alexa-NG. Seit 1.3.22 (B1) uebergeht
    // dringend=1 die Sperre aus Loxone; die Ruhezeit dieses Plugins sperrt
    // weiterhin keine Ansage, sie begrenzt nur die Lautstaerke.
    if (isset($par['dringend']) && !in_array($par['dringend'], array('0', '1'), true)) {
        $f['GRUND'] = 'DRINGEND';
        return $ende(400, $f);
    }
    if (cc_loxsperre_wirkt($cfg) && !(isset($par['dringend']) && $par['dringend'] === '1')) {
        // 1.3.31: eine gewollte Sperre ist kein Fehler, aber auch kein "gesendet" (bis 1.3.30: 200 OK=1).
        return $ende(409, array('OK' => 0, 'UEBERSPRUNGEN' => 1, 'GRUND' => 'GESPERRT'));
    }
    $gp = (isset($par['geraet']) && $par['geraet'] !== '') ? $par['geraet']
        : (string) cc_cfg($cfg, 'sprechen_geraet', '');
    if ($gp === '') {
        $f['GRUND'] = 'KEIN_GERAET';
        return $ende(400, $f);
    }
    if (strlen($gp) > 400 || preg_match('/[\x00-\x1F\x7F]/', $gp) || preg_match('//u', $gp) !== 1) {
        $f['GRUND'] = 'GERAET';
        return $ende(400, $f);
    }
    if ((string) cc_cfg($cfg, 'tts_modus', 'chromecast') === 'audioserver') {
        $f['GRUND'] = 'TTS_MODUS';
        return $ende(409, $f);
    }
    list($h, $g, $namen, $unbek) = cc_sprechen_ziele($gp, cc_geraete($cfg));
    if ($h !== 200) {
        $f['GRUND'] = $g;
        if ($unbek !== '') {
            $f['NAME'] = $unbek;
        }
        return $ende($h, $f);
    }
    $ziele = $namen;

    // ---- laeuft der Dienst, und kennt er den Eingang? ----
    list($herz, , $zu) = cc_dienst_herz($cfg);
    if (!$herz) {
        $f['GRUND'] = 'DIENST_LAEUFT_NICHT';
        return $ende(503, $f);
    }
    if (empty($zu['sprechen_eingang'])) {
        $f['GRUND'] = 'DIENST_OHNE_SPRECHEN';
        return $ende(503, $f);
    }

    // ---- Bremse, unter einer Sperre (zwei gleiche Aufrufe zugleich) ----
    $ordner = cc_sprechen_ordner();
    if (!is_dir($ordner) && !@mkdir($ordner, 0700, true) && !is_dir($ordner)) {
        $f['GRUND'] = 'ORDNER';
        return $ende(503, $f);
    }
    $sp = @fopen(cc_paths()['datadir'] . '/sprechen.lock', 'c');
    $gesperrt = false;
    if ($sp !== false) {
        $bis = microtime(true) + 3.0;
        do {
            if (flock($sp, LOCK_EX | LOCK_NB)) {
                $gesperrt = true;
                break;
            }
            usleep(100000);
        } while (microtime(true) < $bis);
    }
    if (!$gesperrt) {
        if ($sp !== false) {
            fclose($sp);
        }
        $f['GRUND'] = 'BESCHAEFTIGT';
        return $ende(503, $f);
    }
    $frei = function () use ($sp) {
        flock($sp, LOCK_UN);
        fclose($sp);
    };
    $b = cc_sprechen_bremse_lesen();
    $grenze = (int) cc_cfg($cfg, 'sprechen_stunde', '60');
    if ($grenze < 10 || $grenze > 240) {
        $grenze = 60;
    }
    if (count($b['stunde']) >= $grenze) {
        $frei();
        $f['GRUND'] = 'STUNDENGRENZE';
        return $ende(429, $f);
    }
    $hash = hash('sha256', $text . '|' . ($laut === null ? '' : $laut));
    $jetzt = time();
    $rest = array();
    $unveraendert = 0;
    foreach ($ziele as $z) {
        $e = isset($b['fenster'][$z]) ? $b['fenster'][$z] : null;
        if (is_array($e) && hash_equals($e['h'], $hash) && $jetzt - $e['t'] < CC_SPRECHEN_FENSTER) {
            $unveraendert++;
            continue;
        }
        $rest[] = $z;
    }
    if (!$rest) {
        // 1.3.31: nichts ging ans Geraet - also kein OK=1 (bis 1.3.30: 200 OK=1 GRUND=UNVERAENDERT, gemeldet als gesendet).
        $frei();
        return $ende(429, array('OK' => 0, 'GERAETE' => 0, 'UNVERAENDERT' => $unveraendert,
                                'FENSTER' => CC_SPRECHEN_FENSTER, 'GRUND' => 'DOPPELT'));
    }

    // ---- Auftrag an den Dienst; hoechstens CC_SPRECHEN_FRIST warten ----
    // Gruppe 6b (1.3.24): ueber cc_auftrag_senden() - dieselbe Stelle wie Lautstaerke und Befehl.
    list($a, $agr, $unklar) = cc_auftrag_senden(array('zeit' => time(), 'geraete' => $rest, 'text' => $text,
        'laut' => $laut, 'quelle' => (string) $quelle, 'wer' => cc_zeichen_kuerzen((string) $wer, 46)));
    if ($a === null) {
        // Zurueckgezogen oder nicht schreibbar: gesprochen wird nichts (UNKLAR: schon abgeholt, Antwort fehlt).
        $frei();
        $f['GRUND'] = $agr;
        if ($unklar) {
            $f['UNKLAR'] = 1;
        }
        return $ende(503, $f);
    }
    $eingereiht = (isset($a['eingereiht']) && is_array($a['eingereiht'])) ? $a['eingereiht'] : array();
    $offline = isset($a['nicht_verbunden']) ? (int) $a['nicht_verbunden'] : 0;
    $agrund = (isset($a['grund']) && is_string($a['grund'])) ? $a['grund'] : 'ANTWORT_KAPUTT';
    if (!isset($a['eingereiht'])) {
        $agrund = 'ANTWORT_KAPUTT';
    }
    if ($eingereiht) {
        $b['stunde'][] = time();
        foreach ($eingereiht as $z) {
            if (is_string($z)) {
                $b['fenster'][$z] = array('h' => $hash, 't' => time());
            }
        }
        $bjs = json_encode($b, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($bjs !== false) {
            cc_datei_schreiben(cc_paths()['datadir'] . '/sprechen_bremse.json', $bjs, 0600);
        }
    }
    $frei();
    if ($agrund !== '') {
        $f['GRUND'] = $agrund;
        return $ende(503, $f);
    }
    if (!$eingereiht) {
        if (!empty($a['unbekannt']) && $offline === 0) {
            // Die Geraeteliste hat sich eben geaendert; der Dienst kennt den Namen nicht mehr.
            $f['GRUND'] = 'GERAET_UNBEKANNT';
            return $ende(404, $f);
        }
        $f['GRUND'] = 'GERAETE_OFFLINE';
        $f['OFFLINE'] = $offline;
        return $ende(503, $f);
    }
    return $ende(200, array('OK' => 1, 'GERAETE' => count($eingereiht), 'UNVERAENDERT' => $unveraendert,
                            'OFFLINE' => $offline, 'GRUND' => 'EINGEREIHT'));
}

/** Statuszeile, ohne Token lesbar - verraet weder Token noch Text. */
function cc_sprechen_status()
{
    $cfg = cc_config_read();
    list($herz, $alter, $zu) = cc_dienst_herz($cfg);
    $e = cc_sprechen_json('sprechen_ergebnis.json');
    $f = array(
        'OK'         => $herz ? 1 : 0,
        'SPRECHEN'   => (string) cc_cfg($cfg, 'sprechen_ein', '0') === '1' ? 1 : 0,
        'DIENST'     => $herz ? 1 : 0,
        'ALTER'      => $alter,
        'GERAETE'    => count(cc_geraete($cfg)),
        'ERREICHBAR' => (is_array($zu) && isset($zu['geraete_erreichbar'])) ? (int) $zu['geraete_erreichbar'] : -1,
        'LETZTE_OK'  => (is_array($e) && isset($e['ok'])) ? (int) $e['ok'] : -1,
        'LETZTE_ALTER' => (is_array($e) && isset($e['zeit'])) ? max(0, time() - (int) $e['zeit']) : -1,
        'GRUND'      => $herz ? '-' : 'DIENST_LAEUFT_NICHT',
    );
    return array(200, $f);
}

/** Klartext zu einem GRUND fuer den Reiter Test (Sprachdatei, Rueckfall der GRUND selbst). */
function cc_sprechen_grund_text($grund)
{
    $s = 'SPRECHEN.G_' . preg_replace('/[^A-Z_]/', '', strtoupper((string) $grund));
    $t = cc_t($s);
    return $t === $s ? (string) $grund : $t;
}

/**
 * Die Felder des Formulars "Sprachausgabe fuer andere Plugins" pruefen
 * (Entscheidungen 16 und 19: eine Beanstandung speichert nichts; Leerraum am
 * Rand faellt still weg). Das Sprechtoken ist ein Kennwortfeld: leer laesst
 * es, wie es ist; der Haken loescht es. Es reist nie zurueck ins Formular.
 * Rueckgabe array(neue Konfiguration, Beanstandungen Feld => Text).
 */
function cc_sprechen_formular(array $post, array $cfg)
{
    $neu = $cfg;
    $bean = array();
    $neu['sprechen_ein'] = isset($post['sprechen_ein']) ? '1' : '0';

    $roh = array_key_exists('sprechen_geraet', $post) ? $post['sprechen_geraet'] : '';
    list($gut, $fehler) = cc_wert_pruefen('sprechen_geraet', is_string($roh) ? trim($roh) : $roh, $cfg);
    if ($fehler !== '') {
        $bean['sprechen_geraet'] = $fehler;
    } else {
        if ($gut !== '') {
            list($h, , , $name) = cc_sprechen_ziele($gut, cc_geraete($cfg));
            if ($h !== 200) {
                $bean['sprechen_geraet'] = sprintf(cc_t('SPRECHEN.F_GERAET'),
                                                   cc_e($name !== '' ? $name : $gut));
            }
        }
        if (!isset($bean['sprechen_geraet'])) {
            $neu['sprechen_geraet'] = $gut;
        }
    }

    $roh = array_key_exists('sprechen_stunde', $post) ? $post['sprechen_stunde'] : '';
    $roh = is_string($roh) ? trim($roh) : $roh;
    if ($roh === '') {
        $bean['sprechen_stunde'] = sprintf(cc_t('PRUEF.LEER'), 'sprechen_stunde');
    } else {
        list($gut, $fehler) = cc_wert_pruefen('sprechen_stunde', $roh, $cfg);
        if ($fehler !== '') {
            $bean['sprechen_stunde'] = $fehler;
        } else {
            $neu['sprechen_stunde'] = $gut;
        }
    }

    $loeschen = isset($post['sprechtoken_loeschen']);
    $tok = array_key_exists('sprechtoken', $post) ? $post['sprechtoken'] : '';
    if (!is_string($tok)) {
        $bean['sprechtoken'] = cc_t('SPRECHEN.F_TOKEN');
    } else {
        $tok = trim($tok);
        if ($loeschen && $tok !== '') {
            $bean['sprechtoken'] = cc_t('SPRECHEN.F_TOKEN_UND_LOESCHEN');
        } elseif ($loeschen) {
            $neu['sprechtoken'] = '';
        } elseif ($tok !== '') {
            if (!cc_sprechtoken_taugt($tok)) {
                $bean['sprechtoken'] = cc_t('SPRECHEN.F_TOKEN');
            } elseif ((string) cc_cfg($cfg, 'aktionstoken', '') !== ''
                      && hash_equals((string) cc_cfg($cfg, 'aktionstoken', ''), $tok)) {
                $bean['sprechtoken'] = cc_t('SPRECHEN.F_TOKEN_GLEICH');
            } else {
                $neu['sprechtoken'] = $tok;
            }
        }
    }
    if ($neu['sprechen_ein'] === '1' && (string) cc_cfg($neu, 'sprechtoken', '') === ''
        && !isset($bean['sprechtoken'])) {
        $bean['sprechtoken'] = cc_t('SPRECHEN.F_EIN_OHNE_TOKEN');
    }
    return array($neu, $bean);
}

/**
 * Zeile im Reiter Test: Traegt die Sprachausgabe fuer andere Plugins?
 * Gemessen wird, was sich ohne Ansage messen laesst (Schalter, Token,
 * Standardgeraet, Herzschlag und Eingang des Dienstes); die letzte Ansage
 * steht dabei. Rueckgabe array(Frage, true|false|null, Antwort).
 */
function cc_sprechen_pruefzeile()
{
    $cfg = cc_config_read();
    $frage = cc_t('SPRECHEN.F_PRUEF');
    if ((string) cc_cfg($cfg, 'sprechen_ein', '0') !== '1') {
        return array($frage, null, cc_t('SPRECHEN.A_AUS'));
    }
    $tok = (string) cc_cfg($cfg, 'sprechtoken', '');
    if ($tok === '') {
        return array($frage, false, cc_t('SPRECHEN.A_KEIN_TOKEN'));
    }
    if (!cc_sprechtoken_taugt($tok)) {
        return array($frage, false, cc_t('SPRECHEN.A_TOKEN_FORM'));
    }
    if (hash_equals((string) cc_cfg($cfg, 'aktionstoken', ''), $tok)) {
        return array($frage, false, cc_t('SPRECHEN.A_TOKEN_GLEICH'));
    }
    $std = (string) cc_cfg($cfg, 'sprechen_geraet', '');
    if ($std !== '') {
        list($h, , , $n) = cc_sprechen_ziele($std, cc_geraete($cfg));
        if ($h !== 200) {
            return array($frage, false, sprintf(cc_t('SPRECHEN.A_STD_UNBEKANNT'), $n !== '' ? $n : $std));
        }
    }
    list($herz, , $zu) = cc_dienst_herz($cfg);
    if (!$herz) {
        return array($frage, false, cc_t('SPRECHEN.A_DIENST'));
    }
    if (empty($zu['sprechen_eingang'])) {
        return array($frage, false, cc_t('SPRECHEN.A_EINGANG'));
    }
    $text = sprintf(cc_t('SPRECHEN.A_BEREIT'), strlen($tok),
                    $std !== '' ? $std : cc_t('SPRECHEN.A_OHNE_STD'), cc_sprechen_adresse());
    $zustand = true;
    $e = cc_sprechen_json('sprechen_ergebnis.json');
    if (is_array($e) && isset($e['zeit'], $e['ok'])) {
        $alter = max(0, time() - (int) $e['zeit']);
        $geraet = isset($e['geraet']) && is_string($e['geraet']) ? $e['geraet'] : '?';
        if ((int) $e['ok'] === 1) {
            $text .= ' ' . sprintf(cc_t('SPRECHEN.A_LETZTE_OK'), $geraet, $alter);
        } else {
            $text .= ' ' . sprintf(cc_t('SPRECHEN.A_LETZTE_FEHL'), $geraet, $alter,
                                   isset($e['meldung']) && is_string($e['meldung']) ? $e['meldung'] : '?');
            // Die Einrichtung traegt, die letzte Wiedergabe scheiterte - ein
            // Haken waere hier eine Behauptung, ein Kreuz auch (die Ursache
            // kann vorbei sein). "Testansage" prueft es.
            $zustand = null;
        }
    }
    $l = cc_sprechen_json('sprechen_letzte.json');
    if (is_array($l) && isset($l['zeit'], $l['http'], $l['grund']) && (int) $l['ok'] !== 1) {
        $text .= ' ' . sprintf(cc_t('SPRECHEN.A_LETZTE_ABWEISUNG'), (int) $l['http'],
                               (string) $l['grund'], max(0, time() - (int) $l['zeit']));
    }
    return array($frage, $zustand, $text);
}

/* ==================================================================
 * Sperre aus Loxone (B1, 1.3.22; Muster Alexa-NG 1.0.4, ax_loxsperre_*)
 *
 * Loxone haelt Ansagen an (Abwesend, Gaeste, Schlafen): MQTT
 * <praefix>/server/cmd/sperre bzw. UDP server/SPERRE mit 1 oder 0 - das nimmt
 * der Dienst an (loxsperre_setzen() im Dienst) -, oder am Endpunkt
 * aktion=sperre&wert=1|0 mit dem Sprechtoken, nur von diesem LoxBerry. Es gibt
 * an dieser Schnittstelle ein Token (README "Unterschiede zu Alexa-NG"); das
 * Aktionstoken bleibt das Merkwort der Oberflaeche. Ab Werk ohne Wirkung
 * (sperre_ein=0). Gesperrt werden Ansagen; dringend=1 am Endpunkt geht durch.
 * Musik, Lautstaerke und alle uebrigen Befehle bleiben frei. Der Zustand liegt
 * in data/plugins/<ordner>/loxsperre.json (0644, keine Geheimnisse); kein Wert
 * heisst "offen" (nach einem Update ist der Datenordner leer).
 * ================================================================== */

function cc_loxsperre_datei()
{
    return cc_paths()['datadir'] . '/loxsperre.json';
}

/** Der zuletzt gesetzte Zustand: bekannt, gesperrt (0/1), seit, zuletzt, quelle. */
function cc_loxsperre_lesen()
{
    $aus = array('bekannt' => false, 'gesperrt' => 0, 'seit' => 0, 'zuletzt' => 0, 'quelle' => '');
    $f = cc_loxsperre_datei();
    if (!is_file($f)) {
        return $aus;
    }
    $d = json_decode((string) @file_get_contents($f), true);
    if (is_array($d) && isset($d['gesperrt']) && ($d['gesperrt'] === 0 || $d['gesperrt'] === 1)) {
        $aus['bekannt'] = true;
        $aus['gesperrt'] = $d['gesperrt'];
        $aus['seit'] = (isset($d['seit']) && is_int($d['seit'])) ? $d['seit'] : 0;
        $aus['zuletzt'] = (isset($d['zuletzt']) && is_int($d['zuletzt'])) ? $d['zuletzt'] : 0;
        $aus['quelle'] = (isset($d['quelle']) && is_string($d['quelle']))
            ? (string) preg_replace('/[^A-Za-z0-9_.:\-]/', '', substr($d['quelle'], 0, 60)) : '';
    }
    return $aus;
}

/** Wirkt die Sperre jetzt? Nur mit Haken und einem gesetzten Wert 1. */
function cc_loxsperre_wirkt(array $cfg)
{
    if ((string) cc_cfg($cfg, 'sperre_ein', '0') !== '1') {
        return false;
    }
    $l = cc_loxsperre_lesen();
    return $l['bekannt'] && $l['gesperrt'] === 1;
}

/**
 * Die Sperre setzen (Endpunkt aktion=sperre). $wert '0' oder '1' - alles
 * andere wird abgewiesen, nie umgedeutet. Die Tokenpruefung macht der
 * Aufrufer; erst danach wird geschrieben. Rueckgabe array(http, felder).
 */
function cc_loxsperre_setzen($wert, $quelle)
{
    $cfg = cc_config_read();
    $f = array('OK' => 0);
    if (cc_upgrade_laeuft()) {
        $f['GRUND'] = 'AKTUALISIERUNG';
        return array(503, $f);
    }
    if (in_array(cc_config_zustand(), array('unlesbar', 'leer', 'gekuerzt'), true)) {
        $f['GRUND'] = 'KONFIGURATION';
        return array(503, $f);
    }
    if ((string) cc_cfg($cfg, 'sperre_ein', '0') !== '1') {
        $f['GRUND'] = 'SPERRE_AUS';
        return array(409, $f);
    }
    if (!is_string($wert) || ($wert !== '0' && $wert !== '1')) {
        $f['GRUND'] = 'WERT_SPERRE';
        return array(400, $f);
    }
    $alt = cc_loxsperre_lesen();
    $neu = (int) $wert;
    $gleich = $alt['bekannt'] && $alt['gesperrt'] === $neu;
    $d = array('gesperrt' => $neu, 'seit' => $gleich ? $alt['seit'] : time(), 'zuletzt' => time(),
               'quelle' => (string) preg_replace('/[^A-Za-z0-9_.:\-]/', '', substr((string) $quelle, 0, 60)));
    $dir = cc_paths()['datadir'];
    $js = json_encode($d, JSON_UNESCAPED_SLASHES);
    if ((!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir))
        || $js === false || !cc_datei_schreiben(cc_loxsperre_datei(), $js, 0644)) {
        $f['GRUND'] = 'SPEICHERN';
        return array(503, $f);
    }
    return array(200, array('OK' => 1, 'GESPERRT' => $neu, 'UNVERAENDERT' => $gleich ? 1 : 0, 'GRUND' => '-'));
}

/**
 * Zeile fuer Einstellungen und Reiter Test: Zustand der Sperre aus Loxone.
 * Rueckgabe array(Frage, true|null, Antwort) - ein Kreuz gibt es nicht: aus,
 * unbekannt und gesperrt sind Zustaende, kein Fehler.
 */
function cc_loxsperre_pruefzeile()
{
    $cfg = cc_config_read();
    $frage = cc_t('SPERRE.F_PRUEF');
    if ((string) cc_cfg($cfg, 'sperre_ein', '0') !== '1') {
        return array($frage, null, cc_t('SPERRE.A_AUS'));
    }
    $l = cc_loxsperre_lesen();
    if (!$l['bekannt']) {
        return array($frage, null, cc_t('SPERRE.A_UNBEKANNT'));
    }
    $seit = $l['seit'] > 0 ? date('d.m.Y H:i:s', $l['seit']) : '-';
    $von = $l['quelle'] !== '' ? $l['quelle'] : '-';
    if ($l['gesperrt'] === 1) {
        return array($frage, null, sprintf(cc_t('SPERRE.A_GESPERRT'), $seit, $von));
    }
    return array($frage, true, sprintf(cc_t('SPERRE.A_OFFEN'), $seit, $von));
}

/* ==================================================================
 * HTTP-Gleichstand mit Alexa-NG (B2, 1.3.22): aktion=lautstaerke und
 * aktion=geraete am Endpunkt webfrontend/html/index.php - dieselben
 * Pruefungen wie aktion=sprechen: nur von diesem LoxBerry, Sprechtoken,
 * Schalter sprechen_ein, dieselbe Stundengrenze (wie Alexa-NG zaehlt eine
 * Lautstaerke dort mit), derselbe Auftragsweg zum Dienst.
 * ================================================================== */

/**
 * Lautstaerke setzen: geraet wie bei sprechen (leer = Standardgeraet, nie ein
 * Rueckfall auf alle), wert 0 bis 100. Der Dienst reiht "volume" ein - mit
 * Obergrenze, Ruhezeit und der Gleichwert-Bremse X-7 (UNVERAENDERT).
 * Rueckgabe array(http, felder); felder beginnt mit OK und endet mit GRUND.
 */
function cc_lautstaerke_ausfuehren(array $par, $quelle, $wer)
{
    $cfg = cc_config_read();
    $f = array('OK' => 0);
    if (cc_upgrade_laeuft()) {
        $f['GRUND'] = 'AKTUALISIERUNG';
        return array(503, $f);
    }
    if (in_array(cc_config_zustand(), array('unlesbar', 'leer', 'gekuerzt'), true)) {
        $f['GRUND'] = 'KONFIGURATION';
        return array(503, $f);
    }
    if ((string) cc_cfg($cfg, 'sprechen_ein', '0') !== '1') {
        $f['GRUND'] = 'SPRECHEN_AUS';
        return array(409, $f);
    }
    if (!isset($par['wert']) || !preg_match('/^[0-9]{1,3}\z/', $par['wert']) || (int) $par['wert'] > 100) {
        $f['GRUND'] = 'WERT';
        return array(400, $f);
    }
    $wert = (int) $par['wert'];
    $gp = (isset($par['geraet']) && $par['geraet'] !== '') ? $par['geraet']
        : (string) cc_cfg($cfg, 'sprechen_geraet', '');
    if ($gp === '') {
        $f['GRUND'] = 'KEIN_GERAET';
        return array(400, $f);
    }
    if (strlen($gp) > 400 || preg_match('/[\x00-\x1F\x7F]/', $gp) || preg_match('//u', $gp) !== 1) {
        $f['GRUND'] = 'GERAET';
        return array(400, $f);
    }
    list($h, $g, $ziele, $unbek) = cc_sprechen_ziele($gp, cc_geraete($cfg));
    if ($h !== 200) {
        $f['GRUND'] = $g;
        if ($unbek !== '') {
            $f['NAME'] = $unbek;
        }
        return array($h, $f);
    }
    list($herz, , $zu) = cc_dienst_herz($cfg);
    if (!$herz) {
        $f['GRUND'] = 'DIENST_LAEUFT_NICHT';
        return array(503, $f);
    }
    if (empty($zu['lautstaerke_eingang'])) {
        $f['GRUND'] = 'DIENST_OHNE_LAUTSTAERKE';
        return array(503, $f);
    }
    $ordner = cc_sprechen_ordner();
    if (!is_dir($ordner) && !@mkdir($ordner, 0700, true) && !is_dir($ordner)) {
        $f['GRUND'] = 'ORDNER';
        return array(503, $f);
    }
    // Dieselbe Sperre und dieselbe Stundenliste wie aktion=sprechen.
    $sp = @fopen(cc_paths()['datadir'] . '/sprechen.lock', 'c');
    $gesperrt = false;
    if ($sp !== false) {
        $bis = microtime(true) + 3.0;
        do {
            if (flock($sp, LOCK_EX | LOCK_NB)) {
                $gesperrt = true;
                break;
            }
            usleep(100000);
        } while (microtime(true) < $bis);
    }
    if (!$gesperrt) {
        if ($sp !== false) {
            fclose($sp);
        }
        $f['GRUND'] = 'BESCHAEFTIGT';
        return array(503, $f);
    }
    $frei = function () use ($sp) {
        flock($sp, LOCK_UN);
        fclose($sp);
    };
    $b = cc_sprechen_bremse_lesen();
    $grenze = (int) cc_cfg($cfg, 'sprechen_stunde', '60');
    if ($grenze < 10 || $grenze > 240) {
        $grenze = 60;
    }
    if (count($b['stunde']) >= $grenze) {
        $frei();
        $f['GRUND'] = 'STUNDENGRENZE';
        return array(429, $f);
    }
    // Gruppe 6b (1.3.24): ueber cc_auftrag_senden() - dieselbe Stelle wie Ansage und Befehl.
    list($a, $agr, $unklar) = cc_auftrag_senden(array('art' => 'lautstaerke', 'zeit' => time(), 'geraete' => $ziele,
        'wert' => $wert, 'quelle' => (string) $quelle, 'wer' => cc_zeichen_kuerzen((string) $wer, 46)));
    if ($a === null) {
        // Zurueckgezogen oder nicht schreibbar: gesetzt wird nichts.
        $frei();
        $f['GRUND'] = $agr;
        if ($unklar) {
            $f['UNKLAR'] = 1;
        }
        return array(503, $f);
    }
    $eingereiht = (isset($a['eingereiht']) && is_array($a['eingereiht'])) ? $a['eingereiht'] : array();
    $gleich = (isset($a['unveraendert']) && is_array($a['unveraendert'])) ? count($a['unveraendert']) : 0;
    $offline = isset($a['nicht_verbunden']) ? (int) $a['nicht_verbunden'] : 0;
    $agrund = (isset($a['grund']) && is_string($a['grund'])) ? $a['grund'] : 'ANTWORT_KAPUTT';
    if (!isset($a['eingereiht'])) {
        $agrund = 'ANTWORT_KAPUTT';
    }
    if ($eingereiht) {
        $b['stunde'][] = time();
        $bjs = json_encode($b, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($bjs !== false) {
            cc_datei_schreiben(cc_paths()['datadir'] . '/sprechen_bremse.json', $bjs, 0600);
        }
    }
    $frei();
    if ($agrund !== '') {
        $f['GRUND'] = $agrund;
        return array(503, $f);
    }
    if (!$eingereiht && $gleich === 0) {
        if (!empty($a['unbekannt']) && $offline === 0) {
            $f['GRUND'] = 'GERAET_UNBEKANNT';
            return array(404, $f);
        }
        $f['GRUND'] = 'GERAETE_OFFLINE';
        $f['OFFLINE'] = $offline;
        return array(503, $f);
    }
    $aus = array('OK' => 1, 'GERAETE' => count($eingereiht), 'UNVERAENDERT' => $gleich, 'OFFLINE' => $offline);
    // Die Obergrenze (mit Ruhezeit) deckelt den Wert - die Antwort sagt es.
    if (isset($a['grenze']) && is_int($a['grenze']) && $wert > $a['grenze']) {
        $aus['GRENZE'] = $a['grenze'];
    }
    $aus['GRUND'] = $eingereiht ? 'EINGEREIHT' : 'UNVERAENDERT';
    return array(200, $aus);
}

/**
 * Die Geraeteliste: eine Zeile je eingetragenem Geraet in der Reihenfolge der
 * Einstellungen, GERAET;NAME=..;THEMA=..;ONLINE=0|1|-1;LAUT=0-100|-1;STATE=..
 * Stand aus zustand.json des laufenden Dienstes; keine Adresse, kein Token.
 * -1 und - heissen: der Dienst kennt den Wert (noch) nicht.
 * Rueckgabe array(http, zeilen).
 */
function cc_geraete_antwort()
{
    $cfg = cc_config_read();
    if ((string) cc_cfg($cfg, 'sprechen_ein', '0') !== '1') {
        return array(409, array(cc_zeile('GERAET', array('OK' => 0, 'GRUND' => 'SPRECHEN_AUS'))));
    }
    $namen = cc_geraete($cfg);
    if (!$namen) {
        return array(200, array(cc_zeile('GERAET', array('OK' => 1, 'ANZAHL' => 0, 'GRUND' => '-'))));
    }
    list($herz, , $zu) = cc_dienst_herz($cfg);
    if (!$herz) {
        return array(503, array(cc_zeile('GERAET', array('OK' => 0, 'GRUND' => 'DIENST_LAEUFT_NICHT'))));
    }
    if (!isset($zu['geraete_stand']) || !is_array($zu['geraete_stand'])) {
        return array(503, array(cc_zeile('GERAET', array('OK' => 0, 'GRUND' => 'DIENST_OHNE_GERAETELISTE'))));
    }
    $stand = array();
    foreach ($zu['geraete_stand'] as $e) {
        if (is_array($e) && isset($e['name']) && is_string($e['name'])) {
            $stand[$e['name']] = $e;
        }
    }
    $zeilen = array();
    foreach ($namen as $n) {
        $e = isset($stand[$n]) ? $stand[$n] : array();
        $zeilen[] = cc_zeile('GERAET', array(
            'NAME'   => $n,
            'THEMA'  => cc_thema($n),
            'ONLINE' => (isset($e['online']) && is_int($e['online'])) ? $e['online'] : -1,
            'LAUT'   => (isset($e['laut']) && is_int($e['laut'])) ? $e['laut'] : -1,
            'STATE'  => (isset($e['state']) && is_string($e['state']) && $e['state'] !== '')
                        ? cc_zeichen_kuerzen($e['state'], 20) : '-',
        ));
    }
    return array(200, $zeilen);
}

/* ==================================================================
 * Gruppe 6b / MQTT (1.3.24): Namenstabelle, Befehlsbruecke, alter Befehlsweg
 *
 * Grundlage: Musterprojekt/entwurf_gruppe6_cc.txt (E1-E20), ENTSCHEIDUNGEN_GRUPPE6_CHROMECAST.md,
 * Bauvorschrift wz_bild/GRUPPE6B_BAUVORSCHRIFT.md. Werte gehen wie bisher ueber paho direkt an den Broker;
 * in Loxone ist je Wert ein einfacher Virtueller Eingang anzulegen, Name genau so, wie das MQTT-Gateway ihn
 * vergibt (cc_gateway_name()). Die Namen kommen aus EINER Funktion (cc_mqtt_namen()) fuer Reiter MQTT,
 * Namenstabelle, V2-Hakenliste und Selbstpruefung. Befehle laufen ueber die gemeinsame Befehlsbruecke
 * (bin/befehlsbruecke.php, Stammfassung; Zuordnung bin/befehle_zuordnung.php) und von dort ueber den
 * Auftragseingang an den Python-Dienst (cc_befehl_bruecke()). Der alte Weg <praefix>/<Geraet>/cmd/... bleibt
 * hinter dem Haken befehle_cmd_ein (Update an, Neuinstallation aus).
 * ================================================================== */

/** Die drei Haken der Befehle (Entwurf cc 3.4) - EINE Liste fuer Formular, Pruefung und Sicherung. */
function cc_befehle_haken()
{
    return array('befehle_mqtt_ein', 'befehle_mqtt_steuern', 'befehle_cmd_ein');
}

/**
 * Der Befehlsname eines Geraets fuer die Befehlsbruecke (Entwurf cc E2): das MQTT-Thema (cc_thema) klein, "-" wird
 * "_", hoechstens 40 Zeichen - die Bruecke erlaubt je Ebene nur [a-z0-9_]{1,40}. Muss genau dasselbe liefern wie
 * befehl_kennung() im Dienst; der Reiter Test haelt beide gegeneinander (--themen).
 */
function cc_befehl_kennung($thema)
{
    $k = substr((string) preg_replace('/[^a-z0-9_]/', '_', str_replace('-', '_', strtolower((string) $thema))), 0, 40);
    return $k !== '' ? $k : 'geraet';
}

/** Der Name, unter dem das MQTT-Gateway ein Thema als Virtuellen Eingang anlegt: / % und Leerzeichen werden _ (Regeln/07). */
function cc_gateway_name($praefix, $thema)
{
    return str_replace(array('/', '%', ' '), '_', (string) $praefix . '/' . (string) $thema);
}

/** Bereich eines Themas fuer die Namenstabelle, mit Fehlwert (E9): "-1 … 100 %", "0 … 1", Text "". */
function cc_mqtt_bereich($e)
{
    if ($e['art'] === 'text') {
        return '';
    }
    $b = $e['min'] . ' … ' . $e['max'];
    return isset($e['einheit']) && $e['einheit'] !== '' ? $b . ' ' . $e['einheit'] : $b;
}

/**
 * Die Namenstabelle (Entwurf cc 2.4, Bauart ax_mqtt_namen): je Zeile thema (ohne Praefix), name (Gateway), voll
 * (Thema mit Praefix), bedeutung (Text ohne HTML), bereich, art (zahl|text), retained, haken (V2-Haken = im
 * Musterprojekt), noncached, geraet (Anzeigename oder ''). Dienst: online (N), zaehler, geraete, verluste mit
 * Haken; ts, fassung und status/befehle (von der Bruecke) ohne. Je Geraet 16 Themen, 15 mit Haken (type ohne).
 * Befehls- und Quittungsthemen sind keine Eingaenge.
 */
function cc_mqtt_namen($cfg = null)
{
    if ($cfg === null) {
        $cfg = cc_config_read();
    }
    $p = (string) cc_cfg($cfg, 'mqtt_topic', 'chromecast4lox');
    $aus = array();
    $zeile = function ($thema, $e, $lang, $haken, $nc, $geraet) use (&$aus, $p) {
        $aus[] = array('thema' => $thema, 'name' => cc_gateway_name($p, $thema), 'voll' => $p . '/' . $thema,
                       'bedeutung' => cc_hinweis_text($lang), 'bereich' => cc_mqtt_bereich($e),
                       'art' => $e['art'] === 'text' ? 'text' : 'zahl', 'retained' => !empty($e['retain']),
                       'haken' => $haken, 'noncached' => $nc, 'geraet' => $geraet);
    };
    $mit = array('online', 'zaehler', 'geraete', 'verluste', 'quellen', 'ablage_ok');
    foreach (cc_themen()['dienst'] as $e) {
        $k = $e['schluessel'];
        $zeile('server/' . $k, $e, 'server_' . $k, in_array($k, $mit, true),
               in_array($k, array('online', 'quellen', 'ablage_ok'), true), '');
    }
    $zeile('status/befehle', array('art' => 'analog', 'min' => -1, 'max' => 1, 'einheit' => '', 'retain' => false),
           'status_befehle', false, false, '');
    foreach (cc_geraete($cfg) as $g) {
        $t = cc_thema($g);
        foreach (cc_themen()['geraet'] as $e) {
            $zeile($t . '/' . $e['schluessel'], $e, $e['schluessel'], $e['schluessel'] !== 'type', false, $g);
        }
    }
    // Audio Stufe 1 (1.3.25): je Zone die Spiegel aus bin/cc_themen.json (Liste zone); Noncached nur online.
    $cc_zt = isset(cc_themen()['zone']) && is_array(cc_themen()['zone']) ? cc_themen()['zone'] : array();
    foreach (cc_zonen($cfg) as $zn) {
        foreach ($cc_zt as $e) {
            $zeile('zone/' . (int) $zn['nr'] . '/' . $e['schluessel'], $e, $e['schluessel'], true,
                   $e['schluessel'] === 'online', sprintf(cc_t('AUDIO.ZONE_N'), (int) $zn['nr'], $zn['name']));
        }
    }
    return $aus;
}

/** Hakenliste fuer Gateway V2 (Entwurf cc 2.4, E10): nur die Zeilen mit Haken; Noncached nur server/online. */
function cc_mqtt_hakenliste($cfg = null)
{
    return array_values(array_filter(cc_mqtt_namen($cfg), function ($z) { return $z['haken']; }));
}

/* ---------------- Gateway V2: Selbsttest der Haken (Nachtwelle N7, 1.3.25) ---------------- */

/**
 * Die Abos des MQTT-Gateways V2 aus config/system/subscriptions.json (Subscriptions[].Id je Thema, Noncached):
 * array(Thema => Noncached ja/nein), null = nicht lesbar. Form wie MGiSmart 1.1.28 mg_v2_abos() und Govee 0.9.30
 * gv_mqtt_v2_haken_lage(); die Datei liest cc_v2_haken_lage().
 */
function cc_v2_abos($daten)
{
    if (!is_array($daten)) {
        return null;
    }
    $liste = array();
    foreach (array('Subscriptions', 'subscriptions') as $s) {
        if (isset($daten[$s]) && is_array($daten[$s])) {
            $liste = $daten[$s];
            break;
        }
    }
    $abo = array();
    foreach ($liste as $e) {
        if (!is_array($e)) {
            continue;
        }
        $id = '';
        foreach (array('Id', 'id', 'Topic', 'topic') as $k) {
            if (isset($e[$k]) && is_string($e[$k])) {
                $id = $e[$k];
                break;
            }
        }
        if ($id === '') {
            continue;
        }
        $nc = false;
        foreach (array('Noncached', 'noncached') as $k) {
            if (isset($e[$k])) {
                $nc = ($e[$k] === true || in_array(strtolower(trim((string) $e[$k])), array('1', 'true', 'yes', 'on'), true));
            }
        }
        $abo[$id] = $nc;
    }
    return $abo;
}

/**
 * Soll gegen Ist, rein (liest nichts): array(1|0, fehlt[], noncached_fehlt[], noncached_zu_viel[], angehakt, soll).
 * Soll je Zeile array('thema' => volles Thema, 'name' => Gateway-Name, 'noncached' => bool). Noncached wird in
 * beide Richtungen verglichen: fehlt es, reicht das Gateway einen gleichen Wert nicht weiter (nach einem Neustart
 * des Miniservers bleibt der Eingang dann leer); steht es zu viel, geht jeder Wert erneut an den Miniserver.
 */
function cc_v2_haken_abgleich(array $soll, array $abo)
{
    $fehlt = array();
    $nc_fehlt = array();
    $nc_zuviel = array();
    $da = 0;
    foreach ($soll as $e) {
        if (!array_key_exists($e['thema'], $abo)) {
            $fehlt[] = $e['name'];
            continue;
        }
        $da++;
        if ($e['noncached'] && !$abo[$e['thema']]) {
            $nc_fehlt[] = $e['name'];
        } elseif (!$e['noncached'] && $abo[$e['thema']]) {
            $nc_zuviel[] = $e['name'];
        }
    }
    return array(($fehlt || $nc_fehlt || $nc_zuviel) ? 0 : 1, $fehlt, $nc_fehlt, $nc_zuviel, $da, count($soll));
}

/**
 * Das Soll fuer den Selbsttest: cc_mqtt_hakenliste() - DIESELBE Funktion wie die Hakenliste im Reiter Einbindung in
 * Loxone (Regeln/07), samt der Textthemen je Geraet (Texteingaenge der Musterseite). Noncached: server/online (das
 * Lebenszeichen-ok dieser Linie).
 */
function cc_v2_haken_soll($cfg)
{
    $aus = array();
    foreach (cc_mqtt_hakenliste($cfg) as $z) {
        $aus[] = array('thema' => $z['voll'], 'name' => $z['name'], 'noncached' => (bool) $z['noncached']);
    }
    return $aus;
}

/**
 * Selbsttestzeile "Gateway V2: sind alle Datenpunkte der Hakenliste angehakt?" (Nachtwelle N7, Vorschrift Punkt 4):
 * array(1|0|2, Text ohne Markup). 1 alle Haken da und Noncached wie in der Hakenliste; 0 es fehlt ein Haken oder
 * Noncached stimmt nicht (mit Namen); 2 trifft nicht zu (MQTT dieses Plugins aus, Gateway V1) oder nicht
 * feststellbar (Fassung 0, keine Wurzel, subscriptions.json nicht lesbar, Hakenliste leer). Liest nur.
 */
function cc_v2_haken_lage($cfg = null)
{
    if ($cfg === null) {
        $cfg = cc_config_read();
    }
    if (cc_cfg($cfg, 'mqtt_ein', '1') !== '1') {
        return array(2, cc_t('TEST.V2_AUS'));
    }
    $fassung = cc_gateway_fassung();
    $home = (string) cc_paths()['home'];
    if ($fassung === 0 || $home === '') {
        return array(2, cc_t('TEST.V2_UNKLAR'));
    }
    if ($fassung < 2) {
        return array(2, cc_t('TEST.V2_V1'));
    }
    $abo = cc_v2_abos(@json_decode((string) @file_get_contents($home . '/config/system/subscriptions.json'), true));
    if ($abo === null) {
        return array(2, cc_t('TEST.V2_UNKLAR'));
    }
    $liste = cc_v2_haken_soll($cfg);
    if (!$liste) {
        return array(2, cc_t('TEST.V2_LEER'));   // ueber eine leere Hakenliste wird nicht geurteilt
    }
    list($ok, $fehlt, $nc_fehlt, $nc_zuviel, $da, $soll) = cc_v2_haken_abgleich($liste, $abo);
    $txt = sprintf(cc_t('TEST.V2_ZAHL'), $da, $soll);
    if ($fehlt) {
        $txt .= ' ' . sprintf(cc_t('TEST.V2_FEHLT'), implode(', ', array_slice($fehlt, 0, 12))
            . (count($fehlt) > 12 ? ' …' : ''));
    }
    if ($nc_fehlt) {
        $txt .= ' ' . sprintf(cc_t('TEST.V2_NC_FEHLT'), implode(', ', $nc_fehlt));
    }
    if ($nc_zuviel) {
        $txt .= ' ' . sprintf(cc_t('TEST.V2_NC_ZUVIEL'), implode(', ', $nc_zuviel));
    }
    return array($ok, $txt);
}

/** Die Abo-Zeile fuer Gateway V1 (E12): <praefix>/#. */
function cc_abo_zeile($praefix)
{
    return trim((string) $praefix, '/') . '/#';
}

/**
 * Die Abo-Datei des Gateways V1 (config/plugins/<ordner>/mqtt_subscriptions.cfg, E12) nachfuehren - beim Speichern
 * im Reiter MQTT nach einem Praefixwechsel. Nur in einer Anlage (nicht im Archiv). Rueckgabe array(Pfad, steht drin).
 */
function cc_abo_datei($praefix, $schreiben = false)
{
    $p = cc_paths();
    $pfad = dirname($p['config']) . '/mqtt_subscriptions.cfg';
    $soll = cc_abo_zeile($praefix);
    if ($p['home'] === '') {
        return array($pfad, false);
    }
    $roh = is_readable($pfad) ? (string) @file_get_contents($pfad) : '';
    $da = in_array($soll, array_map('trim', cc_zeilen($roh)), true);
    if ($schreiben && !$da && is_dir(dirname($pfad))) {
        $da = cc_datei_schreiben($pfad, $soll . "\n", 0644);
    }
    return array($pfad, $da);
}

/**
 * Warnungen zur Geraeteliste (Entwurf cc 2.7, E14) - nicht abweisen, bestehende Listen bleiben speicherbar: ein
 * Thema "server" sendet mitten in die Dienstthemen, eines namens "befehl" in den Abo-Bereich der Bruecke; zwei
 * Geraete mit demselben Befehlsnamen waeren ueber MQTT nicht zu unterscheiden. Rueckgabe Liste fertiger Saetze
 * (Namen maskiert).
 */
function cc_geraete_warnungen(array $geraete)
{
    $aus = array();
    $kennung = array();
    foreach ($geraete as $g) {
        $t = strtolower(cc_thema($g));
        if ($t === 'server' || $t === 'befehl' || $t === 'zone') {
            $aus[] = sprintf(cc_t('BEFEHL.W_NAME_RESERVIERT'), cc_e($g), cc_e($t));
        }
        $kennung[cc_befehl_kennung(cc_thema($g))][] = $g;
    }
    foreach ($kennung as $k => $namen) {
        if (count($namen) > 1) {
            $aus[] = sprintf(cc_t('BEFEHL.W_NAME_DOPPELT'), cc_e(implode(', ', $namen)), cc_e($k));
        }
    }
    return $aus;
}

/** Protokoll der Befehlsbruecke: an log/plugins/<ordner>/befehle_start.log (dieselbe Datei wie befehlsbruecke.sh). */
function cc_bb_protokoll($stufe, $text)
{
    $d = cc_paths()['logdir'];
    $zeile = date('Y-m-d H:i:s') . ' ' . str_pad((string) $stufe, 7) . ' ' . (string) $text . "\n";
    if ((is_dir($d) || @mkdir($d, 0775, true))
        && @file_put_contents($d . '/befehle_start.log', $zeile, FILE_APPEND | LOCK_EX) !== false) {
        return;
    }
    if (defined('STDERR')) {
        fwrite(STDERR, $zeile);
    }
}

/**
 * Einstellungen fuer die Zuordnung (Entwurf cc 3.3): Praefix = mqtt_topic; annehmen = "Befehle ueber MQTT annehmen"
 * UND Plugin nicht angehalten (enabled != 0) - die Bruecke endet sonst mit "abgelehnt: ausgeschaltet"; eingreifend =
 * zweiter Haken "Lautsprecher ueber MQTT steuern". Liest nur, schreibt nie (die Bruecke laeuft auch waehrend der
 * Upgrade-Luecke als Waechterlauf).
 */
function cc_befehle_einstellungen()
{
    $c = cc_config_read();
    return array('praefix' => (string) cc_cfg($c, 'mqtt_topic', 'chromecast4lox'),
                 'annehmen' => (string) cc_cfg($c, 'befehle_mqtt_ein', '0') === '1'
                               && (string) cc_cfg($c, 'enabled', '1') !== '0',
                 'eingreifend' => (string) cc_cfg($c, 'befehle_mqtt_steuern', '0') === '1',
                 'mqtt_ein' => (string) cc_cfg($c, 'mqtt_ein', '1') === '1');
}

/**
 * Einen Auftrag an den Dienst geben und auf seine Antwort warten (data/plugins/<ordner>/sprechen/, siehe SprechEingang
 * im Dienst) - EINE Stelle fuer Ansage, Lautstaerke und Befehl (Entwurf cc 3.3; bis 1.3.23 stand das Schreiben,
 * Warten und Zurueckziehen zweimal wortgleich da). Hoechstens CC_SPRECHEN_FRIST s; kommt keine Antwort, wird der
 * Auftrag zurueckgezogen - dann fuehrt der Dienst nichts aus. War er schon abgeholt, noch 1,5 s.
 * $felder ohne id (die id steht vorn). Rueckgabe array(Antwort|null, Grund, unklar).
 */
function cc_auftrag_senden(array $felder)
{
    $ordner = cc_sprechen_ordner();
    $id = bin2hex(random_bytes(10));
    $auftrag = $ordner . '/' . $id . '.auftrag';
    $antwort = $ordner . '/' . $id . '.antwort';
    $js = json_encode(array('id' => $id) + $felder, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($js === false || !cc_datei_schreiben($auftrag, $js, 0600)) {
        return array(null, 'SCHREIBFEHLER', false);
    }
    $warten = function ($sekunden) use ($antwort) {
        $bis = microtime(true) + $sekunden;
        while (microtime(true) < $bis) {
            usleep(50000);
            clearstatcache(true, $antwort);
            if (is_file($antwort)) {
                $d = json_decode((string) @file_get_contents($antwort), true);
                @unlink($antwort);
                return is_array($d) ? $d : array();
            }
        }
        return null;
    };
    $a = $warten(CC_SPRECHEN_FRIST);
    if ($a === null) {
        if (@unlink($auftrag)) {
            // Zurueckgezogen: der Dienst hat ihn nie gesehen, ausgefuehrt wird nichts.
            return array(null, 'DIENST_ANTWORTET_NICHT', false);
        }
        // Schon abgeholt - die Antwort kommt gleich.
        $a = $warten(1.5);
        if ($a === null) {
            return array(null, 'DIENST_ANTWORTET_NICHT', true);
        }
    }
    return array($a, '', false);
}

/**
 * Ein Befehl der Befehlsbruecke (tun() in bin/befehle_zuordnung.php) an den laufenden Dienst (Entwurf cc 3.3, E1):
 * Auftrag der Art "befehl" ueber denselben Eingang wie Ansage und Lautstaerke; der Dienst fuehrt ihn in
 * befehl_ausfuehren() aus - Sperre, X-7, Obergrenze und Ruhezeit pruefen dort, nicht hier. Vorher nur:
 * AKTUALISIERUNG, KONFIGURATION, DIENST_LAEUFT_NICHT (Herzschlag), DIENST_OHNE_BEFEHLE (ein Dienst bis 1.3.23 in der
 * Update-Luecke kennt die Art nicht). Kein sprechen_ein, keine Stundengrenze (E8): beides gehoert zum Endpunkt fuer
 * andere Plugins. $geraet: Befehlsname, "alle" oder "server" (sperre). Rueckgabe array(bool ok, string grund):
 * ok heisst eingereiht (bei mindestens einem Lautsprecher), uebersprungen (GESPERRT, TEXT_NULL) oder Sperre gesetzt.
 */
function cc_befehl_bruecke($geraet, $befehl, $wert)
{
    $cfg = cc_config_read();
    if (cc_upgrade_laeuft()) {
        return array(false, 'AKTUALISIERUNG');
    }
    if (in_array(cc_config_zustand(), array('unlesbar', 'leer', 'gekuerzt'), true)) {
        return array(false, 'KONFIGURATION');
    }
    list($herz, , $zu) = cc_dienst_herz($cfg);
    if (!$herz) {
        return array(false, 'DIENST_LAEUFT_NICHT');
    }
    if (empty($zu['befehl_eingang'])) {
        return array(false, 'DIENST_OHNE_BEFEHLE');
    }
    $ordner = cc_sprechen_ordner();
    if (!is_dir($ordner) && !@mkdir($ordner, 0700, true) && !is_dir($ordner)) {
        return array(false, 'ORDNER');
    }
    list($a, $grund) = cc_auftrag_senden(array('art' => 'befehl', 'zeit' => time(), 'geraet' => (string) $geraet,
        'befehl' => (string) $befehl, 'wert' => (string) $wert, 'quelle' => 'mqtt', 'wer' => 'befehlsbruecke'));
    if ($a === null) {
        return array(false, $grund);
    }
    if (!isset($a['eingereiht']) || !is_array($a['eingereiht']) || !isset($a['grund']) || !is_string($a['grund'])) {
        return array(false, 'ANTWORT_KAPUTT');
    }
    if ($a['grund'] !== '') {
        return array(false, $a['grund']);
    }
    if ((isset($a['uebersprungen']) && is_string($a['uebersprungen']) && $a['uebersprungen'] !== '')
        || !empty($a['sperre']) || $a['eingereiht']) {
        return array(true, '');
    }
    return array(false, 'GERAET_UNBEKANNT');
}

/** Wo liegt bin/befehlsbruecke.php? Installiert unter bin/plugins/<ordner>, im Archiv neben webfrontend. */
function cc_bruecke_datei()
{
    foreach (array(cc_paths()['bindir'] . '/befehlsbruecke.php', dirname(dirname(__DIR__)) . '/bin/befehlsbruecke.php') as $k) {
        if (is_file($k)) {
            return $k;
        }
    }
    return '';
}

/** Die Bruecke als Bibliothek laden (BB_NUR_LADEN: kein Hauptteil). */
function cc_bruecke_laden()
{
    if (function_exists('bb_waechter')) {
        return true;
    }
    $f = cc_bruecke_datei();
    if ($f === '') {
        return false;
    }
    if (!defined('BB_NUR_LADEN')) {
        define('BB_NUR_LADEN', true);
    }
    require_once $f;
    return function_exists('bb_waechter');
}

/**
 * Direktstart bzw. -stopp der Bruecke beim Speichern im Reiter MQTT und bei "Dienst neu starten/anhalten" (Entwurf cc
 * 3.5, Bauart ax_bruecke_schalten): befehlsbruecke.sh start startet nur nach --soll, nie als root, nie waehrend eines
 * Updates, nie doppelt; der Waechter in cron.05min startet nach einem Start hoechstens alle 600 s neu. $neustart:
 * Praefix gewechselt (stop, dann start). Rueckgabe array(''|'ein'|'aus'|'start_fehl'|'stop_fehl', Ausgabe).
 */
function cc_bruecke_schalten($neustart = false)
{
    if (DIRECTORY_SEPARATOR === '\\' || cc_paths()['home'] === '' || !cc_bruecke_laden()) {
        return array('', '');
    }
    $p = bb_pfade();
    if (!$p) {
        return array('', '');
    }
    $e = cc_befehle_einstellungen();
    $laeuft = (bool) bb_dienste();
    if ($e['annehmen']) {
        if ($laeuft && !$neustart) {
            return array('', '');
        }
        if ($laeuft) {
            bb_sh($p, 'stop');
        }
        list($rc, $aus) = bb_sh($p, 'start');
        return array(($rc === 0 && bb_dienste()) ? 'ein' : 'start_fehl', $aus);
    }
    if (!$laeuft) {
        return array('', '');
    }
    list($rc, $aus) = bb_sh($p, 'stop');
    return array(($rc === 0 && !bb_dienste()) ? 'aus' : 'stop_fehl', $aus);
}

/** Ein Satz zum Ergebnis von cc_bruecke_schalten() fuer die Meldung nach dem Speichern ('' = nichts zu sagen). */
function cc_bruecke_satz(array $r)
{
    $a = cc_e($r[1] !== '' ? $r[1] : '-');
    if ($r[0] === 'ein') {
        return sprintf(cc_t('BEFEHL.H_GESTARTET'), $a);
    }
    if ($r[0] === 'aus') {
        return sprintf(cc_t('BEFEHL.H_ANGEHALTEN'), $a);
    }
    if ($r[0] === 'start_fehl') {
        return sprintf(cc_t('BEFEHL.H_START_FEHL'), $a);
    }
    if ($r[0] === 'stop_fehl') {
        return sprintf(cc_t('BEFEHL.H_STOP_FEHL'), $a);
    }
    return '';
}

/** Die Befehlsliste der Bruecke (bb_liste(): EINE Quelle fuer Reiter und Vorlage). Rueckgabe Feld oder null. */
function cc_bb_liste()
{
    if (!cc_bruecke_laden()) {
        return null;
    }
    list($z) = bb_zuordnung_laden();
    if ($z === null) {
        return null;
    }
    list($f) = bb_zuordnung_pruefen($z);
    if ($f) {
        return null;
    }
    return bb_liste($z, bb_einstellungen($z));
}

/**
 * Die Befehle fuer Reiter und Vorlage ausgerollt (Entwurf cc 2.5 S3): je Geraet und "alle" jede Zeile der Bruecke
 * mit dem Befehlsnamen statt {geraet}, dazu die Sperre. Rueckgabe Liste array(geraet, befehl, titel, thema, wert,
 * eingreifend, sperre_s, quittung) oder null.
 */
function cc_befehle_ausgerollt(array $geraete)
{
    $l = cc_bb_liste();
    if ($l === null) {
        return null;
    }
    $aus = array();
    $ziele = array();
    foreach ($geraete as $g) {
        $ziele[] = array($g, cc_befehl_kennung(cc_thema($g)));
    }
    $ziele[] = array(cc_t('BEFEHL.ALLE'), cc_sammelziel());
    foreach ($l['befehle'] as $b) {
        $wert = $b['art'];
        if ($b['art'] === 'ganz') {
            $wert = $b['min'] . ' … ' . $b['max'];
        } elseif ($b['art'] === 'schalter') {
            $wert = '0 / 1';
        } elseif ($b['art'] === 'puls') {
            $wert = '1';
        } elseif ($b['art'] === 'text') {
            $wert = cc_t('BEFEHL.WERT_TEXT');
        }
        $teile = $b['muster'] ? $ziele : array(array('', ''));
        foreach ($teile as $zz) {
            $aus[] = array('geraet' => $zz[0], 'befehl' => $b['name'], 'titel' => $b['titel'],
                           'thema' => str_replace('{geraet}', $zz[1], $b['thema']), 'wert' => $wert,
                           'eingreifend' => $b['eingreifend'], 'sperre_s' => $b['sperre_s'], 'quittung' => $b['quittung']);
        }
    }
    return $aus;
}

/** Die feste deutsche Kurzbezeichnung eines Befehls fuer die Titel der Vorlage - wie im Musterprojekt (Titel fest). */
function cc_befehl_titel_kurz($b)
{
    $t = array('play' => 'Wiedergabe', 'pause' => 'Pause', 'stop' => 'beenden', 'quit' => 'App schließen',
               'volume' => 'Lautstärke', 'volume_step' => 'Lautstärke um Schritt', 'volume_up' => 'lauter',
               'volume_down' => 'leiser', 'mute' => 'stumm', 'next' => 'nächster Titel', 'prev' => 'voriger Titel',
               'seek' => 'springen', 'play_favorit' => 'Favorit', 'tts' => 'Ansage', 'tts_stop' => 'Ansage abbrechen');
    return isset($t[$b]) ? $t[$b] : $b;
}

/**
 * Alle Vorlagen, die der Reiter "Einbindung in Loxone" erzeugt - EINE Liste fuer die Knoepfe und die Selbstpruefung
 * (Entwurf cc 3.7): Kuerzel => array(Dateiname, Inhalt). Seit 1.3.24: MQTT-Befehle (Bruecke, mit Sperre - V3) und fuer
 * bestehende Anlagen der alte MQTT-Ausgang und der UDP-Ausgang. Die Eingangsvorlage mit http://localhost gibt es
 * nicht mehr (Pruefbericht E2, R2_078): je Wert ein einfacher Virtueller Eingang (Namenstabelle).
 */
function cc_vorlagen_liste($cfg, array $geraete)
{
    $aus = array();
    foreach (array('bruecke', 'mqtt_out', 'udp_out') as $art) {
        $aus[$art] = cc_vorlage($art, $cfg, $geraete);
    }
    return $aus;
}

/**
 * Pruefzeile "Vorlagen" (Entwurf cc 3.7): jede erzeugbare Vorlage wohlgeformt, und kein Ausgangstitel heisst wie ein
 * Gateway-Eingang aus cc_mqtt_namen() (Pruefbericht E1: sonst droht unter Gateway V1 die Schleife). $zusatz:
 * weitere Titel (Gegenprobe). Rueckgabe array(true|false|null, Text ohne HTML).
 */
function cc_pruef_vorlagen($cfg, array $geraete, array $zusatz = array())
{
    if (!$geraete) {
        return array(null, cc_t('TEST.A_KEINE_GERAETE_KURZ'));
    }
    $kaputt = array();
    $titel = $zusatz;
    $vorher = libxml_use_internal_errors(true);
    $liste = cc_vorlagen_liste($cfg, $geraete);
    foreach ($liste as $art => $v) {
        $x = $v[1] === '' ? false : simplexml_load_string($v[1]);
        if ($x === false) {
            $kaputt[] = $art;
        } else {
            foreach ($x->xpath('//VirtualOutCmd') as $c) {
                $titel[] = (string) $c['Title'];
            }
        }
        libxml_clear_errors();
    }
    libxml_use_internal_errors($vorher);
    $namen = array();
    foreach (cc_mqtt_namen($cfg) as $z) {
        $namen[$z['name']] = 1;
    }
    $gleich = array_values(array_unique(array_filter($titel, function ($t) use ($namen) { return isset($namen[$t]); })));
    if ($kaputt) {
        return array(false, sprintf(cc_t('TEST.A_VORLAGE_KAPUTT'), implode(', ', $kaputt)));
    }
    if ($gleich) {
        return array(false, sprintf(cc_t('TEST.A_VORLAGE_NAMENSGLEICH'), implode(', ', $gleich)));
    }
    return array(true, sprintf(cc_t('TEST.A_VORLAGE_OK'), count($liste), implode(', ', array_keys($liste)),
                               count($titel) - count($zusatz)));
}

/**
 * Pruefzeile "Namenstabelle" (Entwurf cc 2.4): jeder Name der Tabelle ist ein Thema, das der Dienst sendet - gemessen
 * am Sendecode (--themen: gesendet_geraet und dienst), der Name entsteht wie im Gateway; und jedes gesendete Thema
 * eines eingetragenen Geraets steht in der Tabelle. Die Gegenseite wird OHNE cc_gateway_name() gebildet. $namen:
 * Liste array(thema, name) fuer die Gegenprobe (ein verdrehter Name muss rot werden). Rueckgabe array(bool, Text).
 */
function cc_pruef_namen($cfg, array $dienst, $namen = null)
{
    $p = (string) cc_cfg($cfg, 'mqtt_topic', 'chromecast4lox');
    if ($namen === null) {
        $namen = array();
        foreach (cc_mqtt_namen($cfg) as $z) {
            $namen[] = array($z['thema'], $z['name']);
        }
    }
    $gesendet = isset($dienst['gesendet_geraet']) && is_array($dienst['gesendet_geraet']) ? $dienst['gesendet_geraet'] : array();
    $diensttheme = isset($dienst['dienst']) && is_array($dienst['dienst']) ? $dienst['dienst'] : array();
    $gesendet_zone = isset($dienst['gesendet_zone']) && is_array($dienst['gesendet_zone']) ? $dienst['gesendet_zone'] : array();
    $geraete = array();
    foreach (cc_geraete($cfg) as $g) {
        $geraete[cc_thema($g)] = 1;
    }
    $falsch = array();
    $gesehen = array();
    foreach ($namen as $z) {
        $teile = explode('/', (string) $z[0]);
        $ok = (count($teile) === 2 && (($teile[0] === 'server' && in_array($teile[1], $diensttheme, true))
            || ($z[0] === 'status/befehle')
            || (isset($geraete[$teile[0]]) && in_array($teile[1], $gesendet, true))))
            // Audio Stufe 1: Zonenspiegel, gemessen am Sendecode (--themen: gesendet_zone).
            || (count($teile) === 3 && $teile[0] === 'zone' && preg_match('/^[0-9]{1,2}\z/', $teile[1]) === 1
                && in_array($teile[2], $gesendet_zone, true));
        if (!$ok || $z[1] !== strtr($p . '/' . $z[0], array('/' => '_', '%' => '_', ' ' => '_'))) {
            $falsch[] = $z[1];
            continue;
        }
        $gesehen[$z[0]] = 1;
    }
    $fehlt = array();
    foreach (array_keys($geraete) as $t) {
        foreach ($gesendet as $k) {
            if (!isset($gesehen[$t . '/' . $k])) {
                $fehlt[] = $t . '/' . $k;
            }
        }
    }
    foreach (cc_zonen($cfg) as $zn) {
        foreach ($gesendet_zone as $k) {
            if (!isset($gesehen['zone/' . (int) $zn['nr'] . '/' . $k])) {
                $fehlt[] = 'zone/' . (int) $zn['nr'] . '/' . $k;
            }
        }
    }
    if (!$namen) {
        return array(false, cc_t('TEST.A_NAMEN_LEER'));
    }
    if ($falsch || $fehlt) {
        return array(false, sprintf(cc_t('TEST.A_NAMEN_FEHL'), $falsch ? implode(', ', $falsch) : '-',
                                    $fehlt ? implode(', ', $fehlt) : '-'));
    }
    return array(true, sprintf(cc_t('TEST.A_NAMEN_OK'), count($namen), count($geraete)));
}

/**
 * Pruefzeile "Thema und Befehlsname" (Entwurf cc 3.3): cc_thema()/cc_befehl_kennung() gegen thema_saeubern()/
 * befehl_kennung() im Dienst - an festen Proben und den eingetragenen Namen (--themen <namen>). Rueckgabe array(bool|null, Text).
 */
function cc_pruef_kennung($dienst)
{
    if (!is_array($dienst) || !isset($dienst['kennung']) || !is_array($dienst['kennung']) || !$dienst['kennung']) {
        return array(null, cc_t('TEST.A_NICHT_MESSBAR'));
    }
    $anders = array();
    foreach ($dienst['kennung'] as $k) {
        if (!is_array($k) || !isset($k['name'], $k['thema'], $k['befehl'])) {
            $anders[] = '?';
            continue;
        }
        $t = cc_thema($k['name']);
        if ($t !== $k['thema'] || cc_befehl_kennung($t) !== $k['befehl']) {
            $anders[] = $k['name'] . ' (PHP ' . $t . '/' . cc_befehl_kennung($t) . ', Dienst ' . $k['thema'] . '/' . $k['befehl'] . ')';
        }
    }
    return $anders ? array(false, sprintf(cc_t('TEST.A_KENNUNG_ANDERS'), implode('; ', $anders)))
                   : array(true, sprintf(cc_t('TEST.A_KENNUNG_GLEICH'), count($dienst['kennung'])));
}

/**
 * Zeile "Laeuft die Befehlsbruecke?" (Entwurf gemeinsam 2.11, Bauart ax_pruef_bruecke): array(true|false|null, Text).
 * Gemessen wird das Erzeugnis (befehle/zustand.json: ts, abo, Zaehler) und ob ein Dienst argumentweise laeuft - wie
 * der Waechter. Aus (Haken) ist ein Strich, kein Kreuz. Dazu die letzte Quittung.
 */
function cc_pruef_bruecke($cfg)
{
    if (!cc_bruecke_laden()) {
        return array(false, cc_t('BEFEHL.P_FEHLT'));
    }
    list($z, $fehler) = bb_zuordnung_laden();
    if ($z === null) {
        return array(false, sprintf(cc_t('BEFEHL.P_DEFEKT'), $fehler));
    }
    list($f) = bb_zuordnung_pruefen($z);
    if ($f) {
        return array(false, sprintf(cc_t('BEFEHL.P_DEFEKT'), $f[0]));
    }
    if ((string) cc_cfg($cfg, 'befehle_mqtt_ein', '0') !== '1') {
        return array(null, cc_t('BEFEHL.P_AUS'));
    }
    if ((string) cc_cfg($cfg, 'enabled', '1') === '0') {
        return array(null, cc_t('BEFEHL.P_PLUGIN_AUS'));
    }
    $pr = (string) cc_cfg($cfg, 'mqtt_topic', 'chromecast4lox');
    if (!bb_praefix_gueltig($pr)) {
        return array(false, sprintf(cc_t('BEFEHL.P_PRAEFIX'), $pr));
    }
    if (DIRECTORY_SEPARATOR === '\\' || cc_paths()['home'] === '' || !bb_pfade()) {
        return array(null, cc_t('BEFEHL.P_ARCHIV'));
    }
    $zu = bb_zustand_lesen();
    $alter = ($zu && isset($zu['ts'])) ? time() - (int) $zu['ts'] : null;
    if (!bb_dienste()) {
        return array(false, cc_t('BEFEHL.P_STEHT'));
    }
    if ($alter === null || $alter > BB_WACHE_GRENZE) {
        return array(false, sprintf(cc_t('BEFEHL.P_HAENGT'), (int) $alter));
    }
    if (empty($zu['abo'])) {
        return array(false, sprintf(cc_t('BEFEHL.P_OHNE_ABO'), isset($zu['fehler']) ? (string) $zu['fehler'] : ''));
    }
    $zl = (isset($zu['zaehler']) && is_array($zu['zaehler'])) ? $zu['zaehler'] : array();
    $t = sprintf(cc_t('BEFEHL.P_LAEUFT'), date('d.m.Y H:i', isset($zu['start']) ? (int) $zu['start'] : time()),
                 isset($zl['angenommen']) ? (int) $zl['angenommen'] : 0, isset($zl['abgelehnt']) ? (int) $zl['abgelehnt'] : 0,
                 isset($zl['verworfen']) ? (int) $zl['verworfen'] : 0);
    if (!empty($zu['letzter']) && is_array($zu['letzter'])) {
        $t .= ' ' . sprintf(cc_t('BEFEHL.P_LETZTER'), (string) $zu['letzter']['name'],
                            (string) $zu['letzter']['ergebnis'], max(0, time() - (int) $zu['letzter']['zeit']));
    }
    if (!empty($zl['verworfen'])) {
        $t .= ' ' . cc_t('BEFEHL.P_VERWORFEN');
    }
    return array(true, $t);
}

/** Die Zeilen von "befehlsbruecke.php --pruefen" (bb_pruefen()) als eine Pruefzeile: array(true|false|null, Text). */
function cc_pruef_bruecke_einrichtung()
{
    if (!cc_bruecke_laden()) {
        return array(null, cc_t('BEFEHL.P_FEHLT'));
    }
    ob_start();
    bb_pruefen();
    $aus = array_values(array_filter(explode("\n", (string) ob_get_clean()), 'strlen'));
    if (!$aus) {
        return array(null, '-');
    }
    $fehl = preg_grep('/^\[FEHL\]/', $aus);
    return array($fehl ? false : true, implode(' | ', $fehl ? $fehl : array($aus[0], end($aus))));
}

/**
 * Hinweis zum alten Befehlsweg im Reiter MQTT (Entwurf cc 3.4): steht befehle_cmd_ein, nennt er, wann zuletzt ein
 * Befehl ueber <praefix>/<Geraet>/cmd/ kam (zustand.json des Dienstes). Rueckgabe Text (HTML) oder ''.
 */
function cc_cmd_hinweis($cfg)
{
    if ((string) cc_cfg($cfg, 'befehle_cmd_ein', '0') !== '1') {
        return '';
    }
    $zu = cc_sprechen_json('zustand.json');
    $zl = (is_array($zu) && isset($zu['cmd_zuletzt']) && (int) $zu['cmd_zuletzt'] > 0)
        ? date('d.m.Y H:i', (int) $zu['cmd_zuletzt']) : cc_t('BEFEHL.CMD_NIE');
    return sprintf(cc_t('BEFEHL.CMD_HINWEIS'), cc_e($zl));
}

/* ==================================================================
 * Audio Stufe 1 (1.3.25, Musterprojekt/ENTSCHEIDUNGEN_AUDIO_STUFE1.md, Hausherr 30.10.2026: "Ich folge deinen
 * Empfehlungen")
 *
 * Zonen 1-24 statt Geraetenamen (E1), Quellenliste 1-99 als Radiotasten (S3), Medienablage SSD/NAS ueber einen
 * Verweis im eigenen Webordner, den Apache ausliefert (E2), Klingel auf eine Google-Gruppe mit Rueckfall parallel
 * (E3-E5), Wiedergabelisten aus Ordner oder M3U (E7). Die Lesefunktionen hier muessen dasselbe liefern wie
 * zonen()/quellen()/ablage_relativ() im Dienst; der Selbsttest des Dienstes (--selbsttest) und die Probe des Baus
 * halten beide Seiten gegeneinander. Der Dienst richtet den Verweis selbst ein (beim Start und nach jeder Aenderung);
 * die Oberflaeche prueft und schreibt nur die Einstellungen.
 * ================================================================== */

define('CC_ZONEN_MAX', 24);
define('CC_QUELLEN_MAX', 99);

/** Die Schluessel von Audio Stufe 1 - EINE Liste fuer Formular, Pruefung und Sicherung. */
function cc_audio_schluessel()
{
    return array('zonen', 'quellen', 'ablage_pfad', 'ablage_schluessel', 'klingel_ziel', 'klingel_rueckfall',
                 'klingel_gong', 'klingel_pegel', 'klingel_text');
}

/** Ziffern im Bereich, sonst null (kein Runden). */
function cc_audio_zahl($t, $unten, $oben)
{
    $t = trim((string) $t);
    if (!preg_match('/^[0-9]{1,3}\z/', $t)) {
        return null;
    }
    $n = (int) $t;
    return ($n >= $unten && $n <= $oben) ? $n : null;
}

/**
 * Zonentabelle lesen: je Zeile "Nr = Ziel" oder "Nr = Anzeigename | Ziel". Rueckgabe array(Liste nach Nummer mit
 * nr/name/ziel, Beanstandungen[] als fertiger Text). Wie zonen() im Dienst - der uebergeht, was hier beanstandet wird.
 */
function cc_zonen_lesen($text)
{
    $aus = array();
    $fehler = array();
    foreach (preg_split('/[\r\n]+/', (string) $text) as $z) {
        $z = trim($z);
        if ($z === '' || $z[0] === '#') {
            continue;
        }
        if (strpos($z, '=') === false) {
            $fehler[] = sprintf(cc_t('AUDIO.F_ZONE_FORM'), cc_e(cc_zeichen_kuerzen($z, 60)));
            continue;
        }
        list($nr_t, $rest) = explode('=', $z, 2);
        $nr = cc_audio_zahl($nr_t, 1, CC_ZONEN_MAX);
        if ($nr === null) {
            $fehler[] = sprintf(cc_t('AUDIO.F_ZONE_NR'), cc_e(cc_zeichen_kuerzen(trim($nr_t), 10)), CC_ZONEN_MAX);
            continue;
        }
        if (strpos($rest, '|') !== false) {
            list($name, $ziel) = explode('|', $rest, 2);
        } else {
            $name = $rest;
            $ziel = $rest;
        }
        $name = trim($name);
        $ziel = trim($ziel);
        if ($ziel === '' || strpos($ziel, ';') !== false) {
            $fehler[] = sprintf(cc_t('AUDIO.F_ZONE_ZIEL'), $nr);
            continue;
        }
        if (isset($aus[$nr])) {
            $fehler[] = sprintf(cc_t('AUDIO.F_ZONE_DOPPELT'), $nr);
            continue;
        }
        $aus[$nr] = array('nr' => $nr, 'name' => $name !== '' ? $name : $ziel, 'ziel' => $ziel);
    }
    ksort($aus);
    return array(array_values($aus), $fehler);
}

function cc_zonen($cfg)
{
    list($l) = cc_zonen_lesen(cc_cfg($cfg, 'zonen', ''));
    return $l;
}

/** Ein Pfad in der Medienablage: relativ, "/" getrennt, ohne ".." - wie ablage_relativ() im Dienst. null = abgewiesen. */
function cc_ablage_relativ($s)
{
    $t = str_replace('\\', '/', trim((string) $s));
    if ($t === '' || $t[0] === '/' || preg_match('/[\x00-\x1F\x7F]/', $t) || preg_match('/^[A-Za-z]:/', $t)) {
        return null;
    }
    $teile = array();
    foreach (explode('/', $t) as $x) {
        if ($x === '' || $x === '.') {
            continue;
        }
        if ($x === '..') {
            return null;
        }
        $teile[] = $x;
    }
    return $teile ? implode('/', $teile) : null;
}

/**
 * Quellenliste lesen: je Zeile "Nr = Art | Name | Adresse", Art radio (http/https), liste (Ordner oder M3U in der
 * Medienablage) oder datei (Datei der Ablage oder http/https). Rueckgabe array(Liste nr => array, Beanstandungen[]).
 */
function cc_quellen_lesen($text)
{
    $aus = array();
    $fehler = array();
    foreach (preg_split('/[\r\n]+/', (string) $text) as $z) {
        $z = trim($z);
        if ($z === '' || $z[0] === '#') {
            continue;
        }
        $teile = array();
        $nr = null;
        if (strpos($z, '=') !== false) {
            list($nr_t, $rest) = explode('=', $z, 2);
            $nr = cc_audio_zahl($nr_t, 1, CC_QUELLEN_MAX);
            $teile = array_map('trim', explode('|', $rest, 3));
        }
        if ($nr === null || count($teile) !== 3 || !in_array(strtolower($teile[0]), array('radio', 'liste', 'datei'), true)
            || $teile[2] === '') {
            $fehler[] = sprintf(cc_t('AUDIO.F_QUELLE_FORM'), cc_e(cc_zeichen_kuerzen($z, 60)));
            continue;
        }
        $art = strtolower($teile[0]);
        $adr = $teile[2];
        $web = preg_match('#^https?://#i', $adr) === 1;
        if (($art === 'radio' && !$web) || ($art === 'liste' && cc_ablage_relativ($adr) === null)
            || ($art === 'datei' && !$web && cc_ablage_relativ($adr) === null)) {
            $fehler[] = sprintf(cc_t('AUDIO.F_QUELLE_ADRESSE'), $nr);
            continue;
        }
        if (isset($aus[$nr])) {
            $fehler[] = sprintf(cc_t('AUDIO.F_QUELLE_DOPPELT'), $nr);
            continue;
        }
        $aus[$nr] = array('nr' => $nr, 'art' => $art, 'name' => $teile[1] !== '' ? $teile[1] : $adr, 'adresse' => $adr);
    }
    ksort($aus);
    return array($aus, $fehler);
}

/** Die geltende Quellenliste: die eigene, sonst die Favoriten als Radioquellen 1 bis n (Nummern bleiben, S3). */
function cc_quellen($cfg)
{
    $t = (string) cc_cfg($cfg, 'quellen', '');
    if (trim($t) !== '') {
        list($l) = cc_quellen_lesen($t);
        return $l;
    }
    $aus = array();
    foreach (cc_favoriten($cfg) as $i => $f) {
        if ($i + 1 > CC_QUELLEN_MAX) {
            break;
        }
        $aus[$i + 1] = array('nr' => $i + 1, 'art' => 'radio', 'name' => $f[0], 'adresse' => $f[1]);
    }
    return $aus;
}

/** Die Favoriten als Text der Quellenliste - zum Vorbelegen des Feldes, solange die Quellenliste leer ist. */
function cc_quellen_aus_favoriten($cfg)
{
    $z = array();
    foreach (cc_quellen(array('favoriten' => cc_cfg($cfg, 'favoriten', ''), 'quellen' => '')) as $q) {
        $z[] = $q['nr'] . ' = radio | ' . $q['name'] . ' | ' . $q['adresse'];
    }
    return implode("\n", $z);
}

/** Wo eine Medienablage liegen darf (wie ablage_wurzeln() im Dienst): LoxBerry-Speicher, /media, /mnt. */
function cc_ablage_wurzeln()
{
    $orte = array('/media', '/mnt');
    $home = (string) cc_paths()['home'];
    if ($home !== '') {
        array_unshift($orte, $home . '/system/storage');
    }
    return $orte;
}

/** Der eingetragene Pfad der Medienablage - Form (immer) und Lage ($lage: Ordner vorhanden). '' = in Ordnung. */
function cc_ablage_pfad_pruefen($s, $lage)
{
    $s = trim((string) $s);
    if ($s === '') {
        return '';
    }
    // Schraegstriche vereinheitlicht: dieselbe Regel am Geraet (Linux) und im Pruefstand (Windows, C:/...).
    $n = str_replace('\\', '/', $s);
    if (!($n[0] === '/' || preg_match('#^[A-Za-z]:/#', $n)) || preg_match('/[\x00-\x1F\x7F]/', $n)
        || strpos($n . '/', '/../') !== false || strlen($n) > 400) {
        return cc_t('AUDIO.F_ABLAGE_FORM');
    }
    $echt = $n;
    if ($lage) {
        $r = @realpath($s);
        if ($r === false || !@is_dir($r)) {
            return cc_t('AUDIO.F_ABLAGE_FEHLT');
        }
        $echt = $r;
    }
    foreach (cc_ablage_wurzeln() as $w) {
        $wr = $lage ? @realpath($w) : $w;
        if ($wr === false || $wr === '') {
            continue;
        }
        $wr = rtrim(str_replace('\\', '/', $wr), '/');
        $e = str_replace('\\', '/', $echt);
        if (strpos($e, $wr . '/') === 0 && strlen($e) > strlen($wr) + 1) {
            return '';
        }
    }
    return cc_t('AUDIO.F_ABLAGE_ORT');
}

/**
 * Nachfassung 4 (1.3.25, 30.10.2026, Wunsch Hausherr): die Medienablage auswaehlen statt tippen - mit der
 * Speicherauswahl von LoxBerry (libs/phplib/loxberry_storage.php, get_storage_html(); am Geraet nachgelesen,
 * LoxBerry 4.0.0.15). Sie holt per POST action=init von /admin/system/ajax/ajax-storage-handler.cgi ein Stueck HTML:
 * Auswahl <formid>-select (USB, Netzfreigaben, "Benutzerdefinierter Pfad"), Feld <formid>-folder (Unterverzeichnis),
 * verstecktes Feld <formid> mit dem ganzen Pfad; die Speicher fragt dessen Skript erst im Browser ab (get-storage).
 * Kein type_local: der Datenordner des Plugins ist keine erlaubte Ablage (cc_ablage_wurzeln()). Ein Pfad, der zu keinem
 * Speicher passt, waehlt das Widget selbst als "Benutzerdefinierter Pfad" vor (Uebergang alter Pfade).
 * Rueckgabe das HTML oder '' (Bibliothek fehlt, Abruf gescheitert, unerwartete Antwort) - dann bleibt nur das Feld.
 */
function cc_ablage_wahl_html($pfad)
{
    $home = (string) cc_paths()['home'];
    $lib = $home !== '' ? $home . '/libs/phplib/loxberry_storage.php' : '';
    if ($lib === '' || !is_file($lib)) {
        return '';
    }
    require_once $lib;
    if (!function_exists('get_storage_html')) {
        return '';
    }
    $pfad = (string) $pfad;
    $alt = ini_get('default_socket_timeout');
    ini_set('default_socket_timeout', '5');
    $html = @get_storage_html(array(
        'formid' => 'cc_ablage_wahl',
        'currentpath' => cc_ablage_wahl_tauglich($pfad) ? $pfad : '',
        'label' => cc_t('AUDIO.L_ABLAGE_WAHL'),
        'type_usb' => 1, 'type_net' => 1, 'type_custom' => 1, 'custom_folder' => 1, 'show_browse' => 1));
    ini_set('default_socket_timeout', (string) $alt);
    return (is_string($html) && strpos($html, 'id="cc_ablage_wahl"') !== false) ? $html : '';
}

/** Darf der Pfad als currentpath in die Speicherauswahl? Das Widget setzt ihn UNGEPRUEFT in HTML und JavaScript
 * ein - also nur gueltiges UTF-8 ohne " \ < > & ` und ohne Steuerzeichen. Sonst bleibt die Auswahl leer und das
 * Feld "Pfad von Hand" offen. */
function cc_ablage_wahl_tauglich($p)
{
    $p = (string) $p;
    return $p === '' || (preg_match('//u', $p) === 1 && !preg_match('/["\\\\<>&`\x00-\x1F\x7F]/', $p));
}

/** Namen einer Liste "a;b" oder je Zeile. */
function cc_audio_namen($text)
{
    $aus = array();
    foreach (preg_split('/[;\r\n]+/', (string) $text) as $n) {
        $n = trim($n);
        if ($n !== '' && !in_array($n, $aus, true)) {
            $aus[] = $n;
        }
    }
    return $aus;
}

/**
 * Einen Wert von Audio Stufe 1 pruefen (aus cc_wert_pruefen(), auch beim Zurueckspielen einer Sicherung). Hier nur
 * die FORM; ob Zonenziele und Klingelziele in der Geraeteliste stehen, prueft cc_audio_formular() beim Speichern.
 * Rueckgabe wie cc_wert_pruefen(): array(Wert, '') oder array(null, Beanstandung).
 */
function cc_audio_pruefen($k, $s, $bisher)
{
    $name = cc_e($k);
    $grenze = $k === 'quellen' ? 32768 : ($k === 'zonen' ? 8192 : 1024);
    if (strlen($s) > $grenze || preg_match('//u', $s) !== 1) {
        return array(null, sprintf(cc_t('PRUEF.ZU_LANG'), $name));
    }
    if ($k === 'zonen' || $k === 'quellen' || $k === 'klingel_rueckfall') {
        if (preg_match('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/', $s)) {
            return array(null, sprintf(cc_t('PRUEF.STEUERZEICHEN'), $name));
        }
        if ($k === 'zonen') {
            list(, $f) = cc_zonen_lesen($s);
        } elseif ($k === 'quellen') {
            list(, $f) = cc_quellen_lesen($s);
        } else {
            $f = array();
        }
        return $f ? array(null, implode('<br>', $f)) : array($s, '');
    }
    if (preg_match('/[\x00-\x1F\x7F]/', $s)) {
        return array(null, sprintf(cc_t('PRUEF.STEUERZEICHEN'), $name));
    }
    if ($k === 'ablage_schluessel') {
        // Wie das Sprechtoken: nie in einer Sicherung; leer heisst "der geltende bleibt".
        return $s === '' ? array((string) cc_cfg($bisher, 'ablage_schluessel', ''), '')
                         : array(null, cc_t('AUDIO.F_SCHLUESSEL'));
    }
    if ($k === 'ablage_pfad') {
        $f = cc_ablage_pfad_pruefen($s, false);
        return $f === '' ? array(trim($s), '') : array(null, $f);
    }
    if ($k === 'klingel_pegel') {
        if ($s === '') {
            return array('', '');
        }
        return (preg_match('/^[0-9]{1,3}\z/', $s) && (int) $s <= 100) ? array((string) (int) $s, '')
            : array(null, sprintf(cc_t('PRUEF.BEREICH_LEER'), $name, 0, 100));
    }
    if ($k === 'klingel_gong') {
        $s = trim($s);
        if ($s === '' || preg_match('#^https?://\S+\z#i', $s) || cc_ablage_relativ($s) !== null) {
            return array($s === '' ? '' : (preg_match('#^https?://#i', $s) ? $s : cc_ablage_relativ($s)), '');
        }
        return array(null, cc_t('AUDIO.F_GONG'));
    }
    if ($k === 'klingel_text') {
        // Zeichen, nicht Byte - ohne mbstring (am LoxBerry nicht zugesagt).
        return preg_match_all('/./us', $s) <= 1000 ? array(trim($s), '') : array(null, sprintf(cc_t('PRUEF.ZU_LANG'), $name));
    }
    // klingel_ziel: ein Name oder zone_<n>
    return array(trim($s), '');
}

/** Befehlsname einer Zone (fuer die Befehlsbruecke): zone_<n>. */
function cc_zone_kennung($nr)
{
    return 'zone_' . (int) $nr;
}

/** Zonennummer aus zone_<n> - sonst 0. */
function cc_zone_nummer($k)
{
    return preg_match('/^zone_([0-9]{1,2})\z/', strtolower(trim((string) $k)), $m) && (int) $m[1] >= 1
        && (int) $m[1] <= CC_ZONEN_MAX ? (int) $m[1] : 0;
}

/**
 * Das Formular des Reiters Audio (eigenes Formular, eigener Handler, Bauart cc_sprechen_formular()): array($neu,
 * $bean) - $bean Feld => Beanstandung. Bei einer Beanstandung wird nichts gespeichert (Entscheidung 16). Fasst nur
 * die Schluessel von cc_audio_schluessel() an. Querpruefungen: jedes Zonenziel, das Klingelziel und der Rueckfall
 * stehen in der Geraeteliste (oder sind eine Zone); Listen- und Ablagequellen und ein Gong aus der Ablage brauchen
 * eine Medienablage; ist der Pfad gesetzt, muss der Ordner da sein. Den Pfadschluessel wuerfelt die Oberflaeche,
 * sobald ein Pfad steht (24 Zeichen hex, random_bytes) - er wechselt nur mit dem Pfad.
 */
function cc_audio_formular(array $post, array $cfg)
{
    $neu = $cfg;
    $bean = array();
    $geraete = cc_geraete($cfg);
    foreach (array('zonen', 'quellen', 'ablage_pfad', 'klingel_ziel', 'klingel_rueckfall', 'klingel_gong',
                   'klingel_pegel', 'klingel_text') as $k) {
        $roh = isset($post[$k]) ? $post[$k] : '';
        if (!is_string($roh)) {
            $bean[$k] = sprintf(cc_t('PRUEF.KEIN_TEXT'), cc_e($k));
            continue;
        }
        $roh = str_replace(array("\r\n", "\r"), "\n", $roh);
        list($gut, $f) = cc_audio_pruefen($k, trim($roh), $cfg);
        if ($f !== '') {
            $bean[$k] = $f;
        } else {
            $neu[$k] = $gut;
        }
    }
    if (!isset($bean['zonen'])) {
        foreach (cc_zonen($neu) as $z) {
            if (!in_array($z['ziel'], $geraete, true)) {
                $bean['zonen'] = sprintf(cc_t('AUDIO.F_ZONE_UNBEKANNT'), $z['nr'], cc_e($z['ziel']));
                break;
            }
        }
    }
    if (!isset($bean['klingel_ziel']) && (string) $neu['klingel_ziel'] !== '') {
        $kz = (string) $neu['klingel_ziel'];
        $nr = cc_zone_nummer($kz);
        $zonen_nr = array();
        foreach (cc_zonen($neu) as $z) {
            $zonen_nr[] = $z['nr'];
        }
        if ($nr > 0 ? !in_array($nr, $zonen_nr, true) : !in_array($kz, $geraete, true)) {
            $bean['klingel_ziel'] = sprintf(cc_t('AUDIO.F_KLINGEL_ZIEL'), cc_e($kz));
        }
    }
    if (!isset($bean['klingel_rueckfall'])) {
        foreach (cc_audio_namen($neu['klingel_rueckfall']) as $n) {
            if (strtolower($n) !== 'alle' && !in_array($n, $geraete, true)) {
                $bean['klingel_rueckfall'] = sprintf(cc_t('AUDIO.F_KLINGEL_ZIEL'), cc_e($n));
                break;
            }
        }
    }
    $pfad = (string) cc_cfg($neu, 'ablage_pfad', '');
    if (!isset($bean['ablage_pfad']) && $pfad !== '') {
        $f = cc_ablage_pfad_pruefen($pfad, true);
        if ($f !== '') {
            $bean['ablage_pfad'] = $f;
        }
    }
    if ($pfad === '' && !isset($bean['quellen'])) {
        foreach (cc_quellen($neu) as $q) {
            if ($q['art'] === 'liste' || ($q['art'] === 'datei' && !preg_match('#^https?://#i', $q['adresse']))) {
                $bean['quellen'] = sprintf(cc_t('AUDIO.F_OHNE_ABLAGE'), $q['nr']);
                break;
            }
        }
    }
    $gong = (string) cc_cfg($neu, 'klingel_gong', '');
    if ($pfad === '' && $gong !== '' && !preg_match('#^https?://#i', $gong) && !isset($bean['klingel_gong'])) {
        $bean['klingel_gong'] = cc_t('AUDIO.F_GONG_OHNE_ABLAGE');
    }
    if (!$bean) {
        $alt_pfad = (string) cc_cfg($cfg, 'ablage_pfad', '');
        $sch = (string) cc_cfg($cfg, 'ablage_schluessel', '');
        if ($pfad === '') {
            $neu['ablage_schluessel'] = '';
        } elseif ($pfad !== $alt_pfad || !preg_match('/^[0-9a-f]{24,64}\z/', $sch)) {
            $neu['ablage_schluessel'] = bin2hex(random_bytes(12));
        }
    }
    return array($neu, $bean);
}

/** Was der Dienst zur Audio-Stufe meldet (zustand.json): Ablage, Zonen, Quellen, sichtbare Geraete. null = keiner. */
function cc_audio_zustand()
{
    $zu = cc_sprechen_json('zustand.json');
    if (!is_array($zu) || !isset($zu['audio']) || !is_array($zu['audio'])) {
        return null;
    }
    return $zu['audio'];
}

/**
 * Nachfassung 3 (1.3.25, O1): Zusatz fuer die Spalte Zustand im Reiter Audio - bei einer Gruppen-Zone ihre
 * Mitglieder, bei einem Lautsprecher die Gruppen, in denen er Mitglied ist. Aus zustand.json (audio.zonen[],
 * mitglieder/mitglied_von, nur Namen). Leer, wenn nichts zu sagen ist (auch bei einem Dienst ohne diese Felder).
 * Rueckgabe Text ohne HTML (der Aufrufer maskiert).
 */
function cc_zone_mitglieder_text($zs)
{
    if (!is_array($zs)) {
        return '';
    }
    if (!empty($zs['gruppe'])) {
        if (!array_key_exists('mitglieder', $zs)) {
            return '';
        }
        if (!is_array($zs['mitglieder'])) {
            return ' · ' . cc_t('AUDIO.MITGLIEDER_OFFEN');
        }
        $n = array_values(array_filter(array_map('strval', $zs['mitglieder']), 'strlen'));
        return ' · ' . ($n ? sprintf(cc_t('AUDIO.MITGLIEDER'), implode(', ', $n)) : cc_t('AUDIO.MITGLIEDER_KEINE'));
    }
    if (isset($zs['mitglied_von']) && is_array($zs['mitglied_von']) && $zs['mitglied_von']) {
        return ' · ' . sprintf(cc_t('AUDIO.MITGLIED_VON'), implode(', ', array_map('strval', $zs['mitglied_von'])));
    }
    return '';
}

/**
 * 1.3.26 (Befund 5): Grund der Medienablage zur Anzeige. Der Dienst schreibt ihn deutsch in zustand.json; haengt
 * die Netzfreigabe ("Stale file handle"), steht der Hinweis zur Abhilfe in der Sprache der Oberflaeche da.
 */
function cc_ablage_grund_text($grund)
{
    $g = (string) $grund;
    if (stripos($g, 'Stale file handle') === false) {
        return $g;
    }
    // Pfad der autofs-Datei aus dem LoxBerry-Verzeichnis (config/plugins/<ordner>/<datei> -> vier Ebenen hoch).
    return sprintf(cc_t('AUDIO.T_ABLAGE_ESTALE'), dirname(cc_paths()['config'], 4) . '/system/autofs/auto.smb');
}

/**
 * Pruefzeile "Audio: Zonen, Quellen, Medienablage" fuer den Reiter Test: array(true|false|null, Text). Keine Zonen
 * und keine Ablage = Strich (nicht eingerichtet); Beanstandung in der gespeicherten Liste oder eine eingetragene,
 * aber nicht freigegebene Ablage = rot, mit Grund.
 */
function cc_audio_pruefzeile($cfg)
{
    list($zl, $zf) = cc_zonen_lesen(cc_cfg($cfg, 'zonen', ''));
    $qt = (string) cc_cfg($cfg, 'quellen', '');
    list(, $qf) = trim($qt) === '' ? array(array(), array()) : cc_quellen_lesen($qt);
    $ql = cc_quellen($cfg);
    $pfad = (string) cc_cfg($cfg, 'ablage_pfad', '');
    if ($zf || $qf) {
        return array(false, strip_tags(implode(' ', array_merge($zf, $qf))));
    }
    $teile = array(sprintf(cc_t('AUDIO.T_ZAHLEN'), count($zl), count($ql)));
    $ok = null;
    if ($pfad !== '') {
        $a = cc_audio_zustand();
        if ($a === null || !isset($a['ablage']) || !is_array($a['ablage'])) {
            $teile[] = cc_t('AUDIO.T_ABLAGE_UNKLAR');
        } elseif (cc_audio_wird_geprueft($a)) {
            $teile[] = cc_t('AUDIO.T_WIRD_GEPRUEFT');
        } elseif (!empty($a['ablage']['ok'])) {
            $teile[] = sprintf(cc_t('AUDIO.T_ABLAGE_OK'), (int) $a['ablage']['dateien']);
            $ok = true;
        } else {
            $teile[] = sprintf(cc_t('AUDIO.T_ABLAGE_NEIN'),
                               cc_ablage_grund_text(isset($a['ablage']['grund']) ? (string) $a['ablage']['grund'] : '?'));
            $ok = false;
        }
    } elseif ($zl || $ql) {
        $ok = true;
    }
    // Nachfassung 7: Quellen und Klingel-Gong, deren Datei oder Ordner in der Ablage fehlt, als Warnung (Kreuz) - die
    // Quelle spielt nicht, der Gong faellt auf den eigenen bzw. den Standard-Gong zurueck.
    $fehlt = $ok === null ? array() : cc_ablage_fehlende($cfg);
    if ($fehlt) {
        $teile[] = cc_t('AUDIO.T_WARNUNG') . ' ' . implode(' ', $fehlt);
        $ok = false;
    }
    return array($ok, implode(' ', $teile));
}

/* ==================================================================
 * Nachfassung 7 (30.10.2026): Klingel-Gong unabhaengig von der Medienablage
 * ================================================================== */

if (!defined('CC_KLANG_MAX')) {
    define('CC_KLANG_MAX', 1048576);
}

/** Ordner des eigenen Gongs: config/plugins/<ordner>/klaenge - die Sicherung beim Update nimmt ihn mit. */
function cc_klang_ordner()
{
    return dirname(cc_paths()['config']) . '/klaenge';
}

/** Passt der Anfang des Inhalts zur Endung (mp3/ogg/wav)? Dieselbe Regel wie klang_art() im Dienst. */
function cc_klang_art($kopf, $endung)
{
    $k = (string) $kopf;
    if ($endung === 'wav') {
        return substr($k, 0, 4) === 'RIFF' && substr($k, 8, 4) === 'WAVE';
    }
    if ($endung === 'ogg') {
        return substr($k, 0, 4) === 'OggS';
    }
    if ($endung === 'mp3') {
        return substr($k, 0, 3) === 'ID3' || (strlen($k) >= 2 && ord($k[0]) === 0xFF && (ord($k[1]) & 0xE0) === 0xE0);
    }
    return false;
}

/** Der eigene Gong: array(name, endung, groesse, tauglich) oder null. */
function cc_klang_eigen()
{
    $o = cc_klang_ordner();
    foreach (array('mp3', 'ogg', 'wav') as $e) {
        $f = $o . '/eigener_gong.' . $e;
        if (is_file($f) && !is_link($f)) {
            $g = (int) @filesize($f);
            $kopf = (string) @file_get_contents($f, false, null, 0, 12);
            return array('name' => basename($f), 'endung' => $e, 'groesse' => $g,
                         'tauglich' => $g > 0 && $g <= CC_KLANG_MAX && cc_klang_art($kopf, $e));
        }
    }
    return null;
}

/**
 * Einen eigenen Gong uebernehmen ($_FILES-Eintrag): nur .mp3/.ogg/.wav, hoechstens CC_KLANG_MAX Byte, Inhalt passend
 * zur Endung; gespeichert als eigener_gong.<endung> (erst vollstaendig, dann umbenannt), ein Gong der anderen Endungen
 * faellt weg. Rueckgabe '' oder die Beanstandung. $hochgeladen: nur fuer Proben (sonst is_uploaded_file).
 */
function cc_klang_hochladen($datei, $hochgeladen = null)
{
    $pruef = $hochgeladen !== null ? $hochgeladen : 'is_uploaded_file';
    if (!is_array($datei) || !isset($datei['tmp_name'], $datei['name'], $datei['error'], $datei['size'])) {
        return cc_t('AUDIO.F_KLANG_KEINE');
    }
    $fehler = (int) $datei['error'];
    if ($fehler === UPLOAD_ERR_INI_SIZE || $fehler === UPLOAD_ERR_FORM_SIZE) {
        return cc_t('AUDIO.F_KLANG_GROSS');
    }
    if ($fehler !== UPLOAD_ERR_OK || !is_string($datei['tmp_name']) || !@call_user_func($pruef, $datei['tmp_name'])) {
        return cc_t('AUDIO.F_KLANG_KEINE');
    }
    $g = (int) $datei['size'];
    if ($g <= 0 || $g > CC_KLANG_MAX) {
        return cc_t('AUDIO.F_KLANG_GROSS');
    }
    $e = strtolower(pathinfo((string) $datei['name'], PATHINFO_EXTENSION));
    if (!in_array($e, array('mp3', 'ogg', 'wav'), true)) {
        return cc_t('AUDIO.F_KLANG_ART');
    }
    $daten = (string) @file_get_contents($datei['tmp_name'], false, null, 0, CC_KLANG_MAX + 1);
    if (strlen($daten) !== $g) {
        return cc_t('AUDIO.F_KLANG_GROSS');
    }
    if (!cc_klang_art(substr($daten, 0, 12), $e)) {
        return cc_t('AUDIO.F_KLANG_INHALT');
    }
    $o = cc_klang_ordner();
    if (is_link($o) || (!is_dir($o) && !@mkdir($o, 0755, true))) {
        return cc_t('AUDIO.F_KLANG_SCHREIBEN');
    }
    $ziel = $o . '/eigener_gong.' . $e;
    $neu = $ziel . '.neu';
    if (@file_put_contents($neu, $daten) !== $g || !@chmod($neu, 0644) || !@rename($neu, $ziel)) {
        @unlink($neu);
        return cc_t('AUDIO.F_KLANG_SCHREIBEN');
    }
    foreach (array('mp3', 'ogg', 'wav') as $x) {
        if ($x !== $e && is_file($o . '/eigener_gong.' . $x)) {
            @unlink($o . '/eigener_gong.' . $x);
        }
    }
    return '';
}

/** Den eigenen Gong entfernen (Knopf im Reiter Audio). Rueckgabe: Zahl der entfernten Dateien. */
function cc_klang_entfernen()
{
    $n = 0;
    foreach (array('mp3', 'ogg', 'wav') as $x) {
        $f = cc_klang_ordner() . '/eigener_gong.' . $x;
        if (is_file($f) && @unlink($f)) {
            $n++;
        }
    }
    return $n;
}

/** Gibt es $adresse in der eingetragenen Ablage (Datei, bei Art liste auch Ordner oder M3U)? Nur innerhalb. */
function cc_ablage_da($cfg, $adresse, $art = 'datei')
{
    $pfad = (string) cc_cfg($cfg, 'ablage_pfad', '');
    $rel = cc_ablage_relativ($adresse);
    if ($pfad === '' || $rel === null) {
        return false;
    }
    $basis = @realpath($pfad);
    $r = $basis === false ? false : @realpath($basis . '/' . $rel);
    if ($r === false || strpos($r, rtrim($basis, '/\\') . DIRECTORY_SEPARATOR) !== 0) {
        return false;
    }
    if ($art === 'liste') {
        return is_dir($r) || (is_file($r) && preg_match('/\.m3u8?\z/i', $r));
    }
    return is_file($r);
}

/**
 * Quellen und Klingel-Gong, deren Datei bzw. Ordner in der eingetragenen Medienablage fehlt (Texte fuer die
 * Selbstpruefung). Leer ohne Ablage oder wenn ihr Ordner nicht da ist - das meldet die Zeile schon selbst.
 */
function cc_ablage_fehlende($cfg)
{
    $pfad = (string) cc_cfg($cfg, 'ablage_pfad', '');
    if ($pfad === '' || !is_dir($pfad)) {
        return array();
    }
    $aus = array();
    foreach (cc_quellen($cfg) as $q) {
        if ($q['art'] === 'radio' || preg_match('#^https?://#i', $q['adresse'])) {
            continue;
        }
        if (!cc_ablage_da($cfg, $q['adresse'], $q['art'])) {
            $aus[] = sprintf(cc_t('AUDIO.T_QUELLE_FEHLT'), (int) $q['nr'], $q['art'], (string) $q['adresse']);
        }
    }
    $g = trim((string) cc_cfg($cfg, 'klingel_gong', ''));
    if ($g !== '' && !preg_match('#^https?://#i', $g) && !cc_ablage_da($cfg, $g)) {
        $ke = cc_klang_eigen();
        $aus[] = sprintf(cc_t('AUDIO.T_GONG_FEHLT'), $g,
                         cc_t($ke !== null && $ke['tauglich'] ? 'AUDIO.J_EIGEN' : 'AUDIO.J_STANDARD'));
    }
    return $aus;
}

/**
 * Welcher Gong jetzt klingt (wie klingel_gong() im Dienst): array(Text, gut). gut false = das Feld nennt eine Datei,
 * die in der Ablage fehlt (bzw. die Ablage ist nicht freigegeben) - es klingt der Rueckfall.
 */
function cc_klang_jetzt($cfg)
{
    $ke = cc_klang_eigen();
    $eigen = $ke !== null && $ke['tauglich'];
    $rueck = cc_t($eigen ? 'AUDIO.J_EIGEN' : 'AUDIO.J_STANDARD');
    $g = trim((string) cc_cfg($cfg, 'klingel_gong', ''));
    $wort = 'AUDIO.J_ADRESSE';
    if ($g === '' && !$eigen) {
        $g = trim((string) cc_cfg($cfg, 'tts_gong', ''));
        $wort = 'AUDIO.J_ANSAGE';
    }
    if ($g === '') {
        return array($rueck, true);
    }
    if (preg_match('#^https?://#i', $g)) {
        return array(cc_t($wort), true);
    }
    $a = cc_audio_zustand();
    $frei = !is_array($a) || !isset($a['ablage']['ok']) || !empty($a['ablage']['ok']);
    if ($frei && cc_ablage_da($cfg, $g)) {
        return array(sprintf(cc_t('AUDIO.J_ABLAGE'), $g), true);
    }
    return array(sprintf(cc_t('AUDIO.J_FEHLT'), $g, $rueck), false);
}

/**
 * Hat der Dienst die gespeicherte Konfiguration schon uebernommen? zustand.json traegt seit Nachfassung 7 den Stand
 * (audio.cfg_zeit = mtime der Datei, die er gelesen hat). Weicht er ab, ist der gezeigte Ablagezustand von vorher -
 * die Oberflaeche sagt "wird geprueft ...". Ein Dienst ohne das Feld: false (wie bisher).
 */
function cc_audio_wird_geprueft($a)
{
    if (!is_array($a) || !isset($a['cfg_zeit'])) {
        return false;
    }
    clearstatcache();
    $m = @filemtime(cc_paths()['config']);
    return $m !== false && (int) $m !== (int) $a['cfg_zeit'];
}
