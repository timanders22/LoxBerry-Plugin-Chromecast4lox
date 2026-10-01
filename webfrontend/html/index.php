<?php
/**
 * Chromecast 4 Lox NG - Sprachausgabe fuer andere Plugins (unangemeldeter
 * Bereich, Punkt Ansage-3, 01.10.2026)
 *
 *   ?aktion=status                        frei, lesend   Statuszeile (auch ohne aktion)
 *   ?selftest=1&token=T                   nur lokal      SELFTEST;OK=1;TOKEN=OK - prueft nur das Token
 *   aktion=sprechen&token=T&geraet=..&text=..[&laut=0-100]   nur lokal, POST empfohlen
 *
 * DIESELBE Schnittstelle wie Alexa-NG (/plugins/alexang/index.php): gleiche
 * Felder, gleiche Antwortform (KOPF;OK=1;...;GRUND=...), gleiche Codes
 * 200/400/403/404/409/429/503. Ein Verbraucher tauscht den Ordner in der
 * Adresse und die Geraetenamen. T ist das Sprechtoken aus dem Reiter
 * Einstellungen; es gibt hier nur dieses eine.
 *
 * Nur Aufrufer auf diesem LoxBerry (127.0.0.1/::1): die Schnittstelle ist
 * fuer andere Plugins; Loxone spricht dieses Plugin ueber MQTT und UDP an.
 * GET und POST, nie $_REQUEST (Cookies). Dieser Endpunkt schreibt kein
 * Protokoll (dort schreibt allein der Dienst); er schreibt erst NACH der
 * Tokenpruefung, und die Konfiguration legt er nie an.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');

function cc_ende_roh($http, $zeile)
{
    if (ob_get_level() > 0) { ob_end_clean(); }
    if (!headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        http_response_code((int) $http);
    }
    echo $zeile . "\n";
    exit;
}

ob_start();
try {
    /* Die Bibliothek liegt im angemeldeten Bereich: installiert unter
     * webfrontend/htmlauth/plugins/<ordner>/, im Archiv unter
     * webfrontend/htmlauth/. Eingebunden wird nur die Datei; ihre Pfade
     * bestimmt cc_paths() aus IHREM Ablageort. */
    $cc_lib = '';
    foreach (array(dirname(__DIR__, 3) . '/htmlauth/plugins/' . basename(__DIR__) . '/cc_lib.php',
                   dirname(__DIR__) . '/htmlauth/cc_lib.php') as $cc_kand) {
        if (is_file($cc_kand)) {
            $cc_lib = $cc_kand;
            break;
        }
    }
    if ($cc_lib === '') {
        error_log('Chromecast 4 Lox NG: cc_lib.php nicht gefunden neben ' . __DIR__);
        cc_ende_roh(500, 'CHROMECAST4LOX;OK=0;GRUND=BIBLIOTHEK_FEHLT');
    }
    require_once $cc_lib;

    /* Parameter einmal zentral: nur GET und POST, erst is_string, dann Laenge. */
    $cc_par = array();
    $cc_falsch = array();
    foreach (array('aktion', 'token', 'geraet', 'text', 'laut', 'ssml', 'dringend', 'selftest') as $cc_k) {
        $cc_w = null;
        if (isset($_POST[$cc_k])) { $cc_w = $_POST[$cc_k]; } elseif (isset($_GET[$cc_k])) { $cc_w = $_GET[$cc_k]; }
        if ($cc_w === null) { continue; }
        if (!is_string($cc_w) || strlen($cc_w) > 8000) { $cc_falsch[] = $cc_k; continue; }
        $cc_par[$cc_k] = $cc_w;
    }
    $cc_wer = isset($_SERVER['REMOTE_ADDR']) ? preg_replace('/[^0-9a-fA-F:.]/', '', (string) $_SERVER['REMOTE_ADDR']) : '';
    $cc_aktion = isset($cc_par['aktion']) ? $cc_par['aktion'] : (isset($cc_par['selftest']) ? 'selftest' : 'status');
    $cc_kopf = array('status' => 'CHROMECAST4LOX', 'selftest' => 'SELFTEST', 'sprechen' => 'SPRECHEN');
    if (!isset($cc_kopf[$cc_aktion])) {
        cc_ende_roh(400, 'CHROMECAST4LOX;OK=0;GRUND=AKTION');
    }
    $K = $cc_kopf[$cc_aktion];
    if ($cc_falsch) {
        // token[] oder text[] wird abgewiesen, nie umgewandelt (Klasse 12).
        $cc_tf = in_array('token', $cc_falsch, true);
        cc_ende_roh($cc_tf ? 403 : 400, cc_zeile($K, array('OK' => 0, 'GRUND' => $cc_tf ? 'TOKEN' : 'PARAMETER')));
    }

    /* ---------------- frei lesbar ---------------- */
    if ($cc_aktion === 'status') {
        list($cc_h, $cc_f) = cc_sprechen_status();
        cc_ende_roh($cc_h, cc_zeile($K, $cc_f));
    }

    /* ---------------- nur von diesem LoxBerry ---------------- */
    if (!cc_ist_lokal($cc_wer)) {
        cc_ende_roh(403, cc_zeile($K, array('OK' => 0, 'GRUND' => 'NUR_LOKAL')));
    }

    /* ---------------- Token (hash_equals, faellt geschlossen aus) ---------------- */
    $cc_cfg = cc_config_read();
    $cc_soll = (string) cc_cfg($cc_cfg, 'sprechtoken', '');
    if ($cc_soll === '') {
        cc_ende_roh(403, cc_zeile($K, array('OK' => 0, 'GRUND' => 'KEIN_TOKEN_EINGERICHTET')));
    }
    $cc_ist = isset($cc_par['token']) ? $cc_par['token'] : '';
    if ($cc_ist === '' || !hash_equals($cc_soll, $cc_ist)) {
        cc_ende_roh(403, cc_zeile($K, array('OK' => 0, 'GRUND' => 'TOKEN')));
    }

    /* ---------------- Selbsttest: prueft das Token, spricht nichts ---------------- */
    if ($cc_aktion === 'selftest') {
        list($cc_herz) = cc_dienst_herz($cc_cfg);
        cc_ende_roh(200, cc_zeile('SELFTEST', array('OK' => 1, 'TOKEN' => 'OK',
            'SPRECHEN' => (string) cc_cfg($cc_cfg, 'sprechen_ein', '0') === '1' ? 1 : 0,
            'DIENST' => $cc_herz ? 1 : 0, 'GRUND' => '-')));
    }

    list($cc_h, $cc_f) = cc_sprechen_ausfuehren($cc_par, 'http', $cc_wer);
    cc_ende_roh($cc_h, cc_zeile($K, $cc_f));
} catch (Throwable $cc_fehler) {
    error_log('Chromecast 4 Lox NG, Endpunkt: ' . $cc_fehler->getMessage() . ' ('
              . basename($cc_fehler->getFile()) . ':' . $cc_fehler->getLine() . ')');
    cc_ende_roh(500, 'CHROMECAST4LOX;OK=0;GRUND=FEHLER');
}
