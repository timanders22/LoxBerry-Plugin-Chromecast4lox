<?php
/**
 * Chromecast4lox - Bruecke vom Python-Dienst zur gemeinsamen Sprachausgabe (seit 1.3.27).
 * Aufruf: php cc_ansage.php <weg|wieder> <geraetename> <pluginordner>. Legt nur hin und kehrt sofort zurueck; es spricht ein eigener Vorgang, nie ueber dieses Plugin.
 * Ausgabe: eine ASCII-Zeile, nie ein Text oder Token. Rueckgabe 0 gelegt oder nichts zu tun, 1 nicht ausfuehrbar.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur Kommandozeile.\n");
}
$cc_a = isset($argv[1]) ? (string) $argv[1] : '';
$cc_n = isset($argv[2]) ? (string) $argv[2] : '';
$cc_o = isset($argv[3]) ? (string) $argv[3] : '';
if (!in_array($cc_a, array('weg', 'wieder'), true) || $cc_n === '' || preg_match('/^[A-Za-z0-9_-]{1,64}\z/', $cc_o) !== 1) {
    echo "ANSAGE;STAND=0;KENNUNG=AUFRUF\n";
    exit(1);
}
// 1.3.32: als LoxBerry-Wurzel gilt nur ein Ordner mit config/plugins, data/plugins und config/system/general.json.
// Bis 1.3.31 genuegte config/plugins, und der Rueckfall (drei Ebenen ueber bin/plugins/<ordner>) galt ungeprueft.
$cc_wurzel = function ($h) {
    return is_string($h) && $h !== '' && is_dir($h . '/config/plugins') && is_dir($h . '/data/plugins')
        && is_file($h . '/config/system/general.json');
};
$cc_h = getenv('LBHOMEDIR');
if (!$cc_wurzel($cc_h)) {
    $cc_h = dirname(dirname(dirname(__DIR__)));
    if (!$cc_wurzel($cc_h)) {
        echo "ANSAGE;STAND=0;KENNUNG=WURZEL\n";
        exit(1);
    }
}
$cc_lib = $cc_h . '/webfrontend/htmlauth/plugins/' . $cc_o . '/cc_lib.php';
if (!is_file($cc_lib)) {
    echo "ANSAGE;STAND=0;KENNUNG=MODUL_FEHLT\n";
    exit(1);
}
putenv('LBHOMEDIR=' . $cc_h);
putenv('LBPPLUGINDIR=' . $cc_o);
require_once $cc_lib;
echo 'ANSAGE;STAND=1;KENNUNG=' . strtoupper((string) cc_ansage_geraet($cc_a, $cc_n)) . "\n";
exit(0);
