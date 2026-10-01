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
    if (in_array($k, array('enabled', 'mqtt_ein', 'udp', 'tts_fortsetzen', 'gruppen',
                           'beschleunigung', 'sprechen_ein'), true)) {
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
        $aus[$k] = isset($cfg[$k]) ? (string) $cfg[$k] : (string) $v;
    }
    return $aus;
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
            'haken' => array('enabled', 'udp', 'tts_fortsetzen', 'beschleunigung', 'gruppen'),
        ),
        'mqtt' => array(
            'text'  => array('mqtt_topic'),
            'haken' => array('mqtt_ein'),
        ),
        // Ansage-3: 'nur_markierung' wird rot umrandet, reist aber nie mit -
        // das Sprechtoken kommt nicht zurueck ins Formular.
        'sprechen' => array(
            'text'  => array('sprechen_geraet', 'sprechen_stunde'),
            'haken' => array('sprechen_ein', 'sprechtoken_loeschen'),
            'nur_markierung' => array('sprechtoken'),
        ),
    );
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
        // Der Hinweistext traegt die ausfuehrliche Erklaerung (seit 1.3.13,
        // O16); bis 1.3.12 war er in allen 23 Befehlen leer.
        $o .= 'HintText="' . cc_x(isset($c['hint']) ? $c['hint'] : '') . '"';
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

/**
 * Wie viele Ein- und Ausgaenge legt die Vorlage je Geraet an? Aus dem Code
 * gerechnet, nicht getippt (seit 1.3.13, O14; bis 1.3.12 stand im Reiter
 * "16 Eingaenge", die Vorlage legte 9 an). Rueckgabe:
 * array(eingaenge je Geraet, ausgaenge je Geraet, ausgelassene Textthemen,
 *       Dienst-Eingaenge).
 */
function cc_vorlage_zahlen()
{
    $ein = 0;
    $text = array();
    foreach (cc_themen()['geraet'] as $e) {
        if ($e['art'] === 'text') {
            $text[] = $e['schluessel'];
        } else {
            $ein++;
        }
    }
    $dienst = 0;
    foreach (cc_themen()['dienst'] as $e) {
        if ($e['art'] !== 'text') {
            $dienst++;
        }
    }
    return array($ein, count(cc_vorlagen_befehle()), $text, $dienst);
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
                'comment' => cc_zeichen_kuerzen(cc_thema_kurz('server_' . $e['schluessel']), 40),
                'check'   => ' ',
                'analog'  => $e['art'] === 'analog',
                'min'     => $e['min'], 'max' => $e['max'],
                'unit'    => cc_vorlage_einheit($e),
                'hint'    => cc_hinweis_text('server_' . $e['schluessel']),
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
                    'comment' => cc_kommentar($g, cc_thema_kurz($e['schluessel'])),
                    'check'   => ' ',
                    'analog'  => $e['art'] === 'analog',
                    'min'     => $e['min'], 'max' => $e['max'],
                    'unit'    => cc_vorlage_einheit($e),
                    'hint'    => cc_hinweis_text($e['schluessel']),
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
            foreach (cc_vorlagen_befehle() as $e) {
                $b = $e['schluessel'];
                $analog = cc_befehl_analog($b);
                $cmds[] = array(
                    'title'   => $praefix . '_' . $t . '_' . $b,
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
 * Einheit eines Vorlageneingangs (seit 1.3.13, O16): mit Einheit
 * "<v.1> %", ohne Einheit bei einem Zahlenwert "<v>" - bis 1.3.12 standen
 * favorit, server_zaehler, server_geraete und server_verluste ohne Unit da.
 */
function cc_vorlage_einheit($e)
{
    if ($e['einheit'] !== '') {
        return '<v.1> ' . $e['einheit'];
    }
    return $e['art'] === 'analog' ? '<v>' : '';
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
     * Werkseinstellung) - das Sprechtoken fehlt in jeder Sicherung. */
    foreach (cc_sprechen_schluessel() as $sk) {
        if (!array_key_exists($sk, $daten)) {
            $neu[$sk] = (string) cc_cfg($bisher, $sk, isset($neu[$sk]) ? $neu[$sk] : '');
        }
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
        if (in_array($fk, cc_sprechen_schluessel(), true)) {
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
    return array($mangel ? null : $neu, $mangel, $anzahl);
}

/** Ansage-3: die Schluessel der Sprachausgabe (in Sicherungen bis 1.3.15 nicht dabei). */
function cc_sprechen_schluessel()
{
    return array('sprechen_ein', 'sprechen_geraet', 'sprechen_stunde', 'sprechtoken');
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
define('CC_SPRECHEN_FENSTER', 30);

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
    // dringend: angenommen wie bei Alexa-NG, ohne Wirkung - die Ruhezeit
    // dieses Plugins sperrt keine Ansage, sie begrenzt nur die Lautstaerke.
    if (isset($par['dringend']) && !in_array($par['dringend'], array('0', '1'), true)) {
        $f['GRUND'] = 'DRINGEND';
        return $ende(400, $f);
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
        $frei();
        return $ende(200, array('OK' => 1, 'GERAETE' => 0, 'UNVERAENDERT' => $unveraendert,
                                'GRUND' => 'UNVERAENDERT'));
    }

    // ---- Auftrag an den Dienst; hoechstens CC_SPRECHEN_FRIST warten ----
    $id = bin2hex(random_bytes(10));
    $auftrag = $ordner . '/' . $id . '.auftrag';
    $antwort = $ordner . '/' . $id . '.antwort';
    $js = json_encode(array('id' => $id, 'zeit' => time(), 'geraete' => $rest, 'text' => $text,
                            'laut' => $laut, 'quelle' => (string) $quelle,
                            'wer' => cc_zeichen_kuerzen((string) $wer, 46)),
                      JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($js === false || !cc_datei_schreiben($auftrag, $js, 0600)) {
        $frei();
        $f['GRUND'] = 'SCHREIBFEHLER';
        return $ende(503, $f);
    }
    $a = null;
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
            // Zurueckgezogen: der Dienst hat ihn nie gesehen, gesprochen wird nichts.
            $frei();
            $f['GRUND'] = 'DIENST_ANTWORTET_NICHT';
            return $ende(503, $f);
        }
        // Schon abgeholt - die Antwort kommt gleich.
        $a = $warten(1.5);
        if ($a === null) {
            $frei();
            $f['GRUND'] = 'DIENST_ANTWORTET_NICHT';
            $f['UNKLAR'] = 1;
            return $ende(503, $f);
        }
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
