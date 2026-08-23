<?php
/**
 * Chromecast 4 Lox NG - Admin-Oberflaeche
 * Reiter: Einstellungen | Einbindung in Loxone | Test | Logdateien
 *
 * Loest die alte Oberflaeche ab (index.php, config.php, discover.php,
 * log.php, inc_common.php, discover_exec.php, status_exec.php sowie die
 * englische Sprachdatei). Alles auf Deutsch.
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '1');

require_once __DIR__ . '/cc_lib.php';

$cc_p = cc_paths();
if ($cc_p['home']) {
    $cc_sdk = $cc_p['home'] . '/libs/phplib/loxberry_system.php';
    if (file_exists($cc_sdk)) {
        require_once $cc_sdk;
        require_once $cc_p['home'] . '/libs/phplib/loxberry_web.php';
    }
}

$cc_saved = false;
$cc_error = '';
$cc_hinweis = '';
/* Aktiver Reiter. Die Positivliste muss Zeichen fuer Zeichen zu den vier
 * id-Werten der Bereiche weiter unten passen - sonst springt die Seite nach
 * jedem Absenden auf Einstellungen zurueck, obwohl der Reiter sichtbar ist.
 * Die Reiter sind echte Verweise, deshalb zaehlt auch ?tab= aus der Adresse. */
$cc_tabliste = array('tab-settings', 'tab-mqtt', 'tab-loxone', 'tab-test', 'tab-log');
$cc_tab = 'tab-settings';
if (isset($_GET['tab']) && in_array('tab-' . (string) $_GET['tab'], $cc_tabliste, true)) {
    $cc_tab = 'tab-' . (string) $_GET['tab'];
}
if (isset($_POST['activetab']) && in_array((string) $_POST['activetab'], $cc_tabliste, true)) {
    $cc_tab = (string) $_POST['activetab'];
}

/* ============ Wachposten gegen fremde Absender ============
 *
 * EINE Pruefung, VOR allen Handlern. Ein Browser schickt die
 * HTTP-Basic-Anmeldung automatisch mit, wenn der Anwender auf einer fremden
 * Seite ein Formular abschickt, das hierher zeigt; SameSite greift dabei
 * nicht. Ohne diese Pruefung genuegte ein solcher Aufruf, um den Dienst
 * anzuhalten oder einen Lautsprecher anzusprechen.
 *
 * Der Wachposten steht am Eingang, nicht in den einzelnen Handlern: einen
 * Handler kann man beim Erweitern vergessen, den Eingang nicht.
 */
$cc_fmt = cc_formtoken();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cc_mit = (isset($_POST['fmt']) && is_string($_POST['fmt'])) ? $_POST['fmt'] : '';
    $cc_gut = $cc_fmt !== '' && hash_equals($cc_fmt, $cc_mit);
    if (!$cc_gut) {
        // Melden, nicht wortlos nichts tun: ein Formular, das schweigend
        // nichts bewirkt, schickt den Anwender auf die Suche nach einem
        // Fehler, den es nicht gibt. Die harmlose Ursache steht mit dabei.
        $cc_error = cc_t($cc_fmt === '' ? 'FEHLER.KEIN_MERKMAL' : 'FEHLER.MERKMAL');
        // $_POST leeren, damit danach KEIN Handler mehr anlaeuft, ohne dass
        // jeder einzelne davon wissen muesste. Den offenen Reiter behalten -
        // der Anwender soll die Meldung dort sehen, wo er war.
        $cc_behalten = isset($_POST['activetab']) ? $_POST['activetab'] : null;
        $_POST = array();
        if ($cc_behalten !== null) {
            $_POST['activetab'] = $cc_behalten;
        }
    }
}

/* ============ Loxone-Vorlage herunterladen ============ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['download'])) {
    $cfg = cc_config_read();
    $geraete = cc_geraete($cfg);
    if (!$geraete) {
        $cc_error = 'Es ist kein Ger&auml;t eingetragen &mdash; die Vorlage w&auml;re leer.';
        $cc_tab = 'tab-loxone';
    } else {
        list($name, $inhalt) = cc_vorlage((string) $_POST['download'], $cfg, $geraete);
        if ($name === '') {
            $cc_error = 'Unbekannte Vorlagenart.';
            $cc_tab = 'tab-loxone';
        } else {
            // Ausgabepuffer leeren, BEVOR die Kopfzeilen gehen.
            //
            // Faellt in cc_vorlage() eine PHP-Warnung an - eine fehlende
            // Kennzahl, ein Hinweis auf eine veraltete Schreibweise -, landet
            // deren Text vor dem XML im Datenstrom. Loxone Config liest die
            // Datei dann nicht mehr ein und sagt nur, sie sei ungueltig.
            // Der Anwender sucht daraufhin im XML, wo nichts zu finden ist.
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }
            header('Content-Type: application/x-download');
            header('Content-Disposition: attachment; filename="' . $name . '"');
            header('Content-Length: ' . strlen($inhalt));
            echo $inhalt;
            exit;
        }
    }
}

/* ============ Test-Aktionen ============ */
$cc_test_titel = '';
$cc_test_text = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test'])) {
    require_once __DIR__ . '/cc_test.php';
    list($cc_test_titel, $cc_test_text) = cc_test_ausfuehren(
        (string) $_POST['test'],
        isset($_POST['testgeraet']) ? (string) $_POST['testgeraet'] : ''
    );
    $cc_tab = 'tab-test';
}

/* ============ Geraete im Netz suchen und uebernehmen ============
 *
 * Der Name muss zeichengenau stimmen - das steht so in der eigenen
 * Oberflaeche. Ihn abtippen zu lassen war die haeufigste Fehlerursache
 * dieses Plugins.
 */
$cc_gefunden = array();
$cc_suchfehler = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['suche_geraete'])) {
    $cc_c = cc_config_read();
    list($cc_gefunden, $cc_suchfehler) =
        cc_suche(cc_cfg($cc_c, 'gruppen', '1') !== '1');
    $cc_tab = 'tab-settings';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['uebernehmen'])) {
    $cc_c = cc_config_read();
    $cc_vorhanden = cc_geraete($cc_c);
    $cc_neu_dazu = array();
    $cc_wahl = isset($_POST['gefunden']) && is_array($_POST['gefunden'])
        ? $_POST['gefunden'] : array();
    foreach ($cc_wahl as $cc_n) {
        if (!is_string($cc_n)) {
            continue;
        }
        // Steuerzeichen heraus, sonst nichts: der Name ist der Anzeigename
        // aus der Google-Home-App und darf Leerzeichen und Umlaute tragen.
        $cc_n = trim(preg_replace('/[\x00-\x1F\x7F]+/u', '', $cc_n));
        if ($cc_n !== '' && !in_array($cc_n, $cc_vorhanden, true)
            && !in_array($cc_n, $cc_neu_dazu, true)) {
            $cc_neu_dazu[] = $cc_n;
        }
    }
    if (!$cc_neu_dazu) {
        $cc_hinweis = cc_t('SUCHE.H_NICHTS_NEU');
        $cc_saved = true;
    } else {
        // ERGAENZEN, nicht ersetzen: wer ein Geraet von Hand eingetragen
        // hat, das gerade schlaeft, verliert es sonst.
        $cc_c['geraete'] = implode(';', array_merge($cc_vorhanden, $cc_neu_dazu));
        if (cc_config_write($cc_c)) {
            $cc_saved = true;
            $cc_hinweis = sprintf(cc_t('SUCHE.H_UEBERNOMMEN'),
                                  count($cc_neu_dazu),
                                  implode(', ', $cc_neu_dazu));
            require_once __DIR__ . '/cc_test.php';
            if (cc_cfg($cc_c, 'enabled', '1') === '1') {
                cc_dienst('restart');
            }
        } else {
            $cc_error = cc_t('TEXT.T002') . ' ' . cc_e($cc_p['config']);
        }
    }
    $cc_tab = 'tab-settings';
}

/* ============ Speichern: MQTT ============
 *
 * Ein eigener Handler mit eigenem Formularnamen. Er laedt den Bestand und
 * fasst AUSSCHLIESSLICH die MQTT-Werte an - alles uebrige ueberlebt
 * unveraendert.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_mqtt'])) {
    $cc_m = cc_config_read();
    $cc_m['mqtt_ein'] = isset($_POST['mqtt_ein']) ? '1' : '0';
    $cc_pr = cc_thema(preg_replace('/[\x00-\x1F\x7F"\']+/u', '',
                                  trim((string) ($_POST['mqtt_topic'] ?? ''))));
    $cc_m['mqtt_topic'] = $cc_pr !== '' ? $cc_pr : 'chromecast4lox';
    if (cc_config_write($cc_m)) {
        $cc_saved = true;
        require_once __DIR__ . '/cc_test.php';
        if (cc_cfg($cc_m, 'enabled', '1') === '1') {
            cc_dienst('restart');
            $cc_hinweis = cc_t(cc_dienst_pid() ? 'TEXT.H_NEUGESTARTET'
                                               : 'TEXT.H_LAEUFT_NICHT');
        } else {
            $cc_hinweis = cc_t('TEXT.H_ANGEHALTEN');
        }
    } else {
        $cc_error = cc_t('TEXT.T002') . ' ' . cc_e($cc_p['config']);
    }
    $cc_tab = 'tab-mqtt';
}

/* ============ Speichern ============ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    $neu = cc_config_read();

    // Eingaben nie hart filtern - nur Steuerzeichen und Anfuehrungszeichen raus.
    $saeubern = function ($s) {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F"\']+/u', '', (string) $s);
        return trim($s);
    };
    $zahl = function ($wert, $vorgabe, $min, $max) {
        $n = (int) $wert;
        return ($n >= $min && $n <= $max) ? (string) $n : (string) $vorgabe;
    };

    $neu['enabled']       = isset($_POST['enabled']) ? '1' : '0';
    $neu['geraete']       = $saeubern($_POST['geraete'] ?? '');
    // MQTT und Themenpraefix stehen im Reiter MQTT und werden hier NICHT
    // angefasst. $neu kommt aus cc_config_read(), die Werte ueberleben
    // damit unveraendert. Stuende hier weiter isset($_POST['mqtt_ein']),
    // schaltete jedes Speichern der Einstellungen MQTT stillschweigend ab -
    // das Formular schickt den Haken ja gar nicht mit.
    $neu['udp']           = isset($_POST['udp']) ? '1' : '0';
    $neu['udp_port']      = $zahl($_POST['udp_port'] ?? '', 7090, 1, 65535);
    $neu['intervall']     = $zahl($_POST['intervall'] ?? '', 10, 2, 3600);
    $neu['aktualisierung'] = $zahl($_POST['aktualisierung'] ?? '', 60, 5, 86400);
    $neu['lautstaerke_schritt'] = $zahl($_POST['lautstaerke_schritt'] ?? '', 5, 1, 50);
    // Die Favoritenliste wird NICHT hart gefiltert: eine Adresse darf
    // alles enthalten, was eine Adresse enthaelt. Heraus kommen nur
    // Steuerzeichen und das Anfuehrungszeichen, das die Konfigurations-
    // datei zerlegen wuerde.
    $neu['favoriten']     = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F"]+/u',
                                         '', (string) ($_POST['favoriten'] ?? ''));

    // --- Ansage (TTS) ---
    $cc_modus = (string) ($_POST['tts_modus'] ?? 'chromecast');
    $neu['tts_modus']       = array_key_exists($cc_modus, cc_tts_modi()) ? $cc_modus : 'chromecast';
    $neu['tts_ip']          = $saeubern($_POST['tts_ip'] ?? '');
    $neu['tts_port']        = $zahl($_POST['tts_port'] ?? '', 7091, 1, 65535);
    $neu['tts_zonen']       = $saeubern($_POST['tts_zonen'] ?? '1');
    $neu['tts_lautstaerke'] = $zahl($_POST['tts_lautstaerke'] ?? '', 8, 1, 100);
    $cc_spr = strtolower(preg_replace('/[^A-Za-z-]/', '', (string) ($_POST['tts_sprache'] ?? 'de')));
    $neu['tts_sprache']     = $cc_spr !== '' ? $cc_spr : 'de';
    $neu['tts_vorlage']     = $saeubern($_POST['tts_vorlage'] ?? '');
    // Leer heisst: die aktuelle Lautstaerke beibehalten. Deshalb NICHT auf
    // eine Vorgabe zwingen - das waere eine Entscheidung, die niemand
    // getroffen hat.
    $cc_pegel = trim((string) ($_POST['tts_pegel'] ?? ''));
    $neu['tts_pegel']       = $cc_pegel === '' ? '' : $zahl($cc_pegel, 40, 0, 100);
    $neu['tts_fortsetzen']  = isset($_POST['tts_fortsetzen']) ? '1' : '0';
    $neu['tts_gong']        = $saeubern($_POST['tts_gong'] ?? '');
    $neu['tts_lokal_basis'] = $saeubern($_POST['tts_lokal_basis'] ?? '');
    $neu['lautstaerke_max'] = $zahl($_POST['lautstaerke_max'] ?? '', 100, 0, 100);
    $neu['ruhe_max']        = $zahl($_POST['ruhe_max'] ?? '', 30, 0, 100);
    // Uhrzeiten werden ABGEWIESEN, wenn sie keine sind - nicht
    // zurechtgebogen. Leer heisst: Ruhezeit aus.
    foreach (array('ruhe_von', 'ruhe_bis') as $cc_rf) {
        $cc_rv = trim((string) ($_POST[$cc_rf] ?? ''));
        if ($cc_rv === '' || preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $cc_rv)) {
            $neu[$cc_rf] = $cc_rv;
        } else {
            $cc_error = cc_t('RUHE.FEHLER');
        }
    }
    $neu['gruppen']         = isset($_POST['gruppen']) ? '1' : '0';
    $neu['beschleunigung']  = isset($_POST['beschleunigung']) ? '1' : '0';

    if (cc_config_write($neu)) {
        $cc_saved = true;
        require_once __DIR__ . '/cc_test.php';
        // Der Haken entscheidet, was nach dem Speichern passiert. Ein
        // Neustart bei abgeschaltetem Dienst waere das Gegenteil dessen,
        // was der Anwender gerade eingestellt hat.
        if ($neu['enabled'] === '1') {
            cc_dienst('restart');
            $cc_hinweis = cc_t(cc_dienst_pid() ? 'TEXT.H_NEUGESTARTET'
                                               : 'TEXT.H_LAEUFT_NICHT');
        } else {
            cc_dienst('stop');
            $cc_hinweis = cc_t('TEXT.H_ANGEHALTEN');
        }
    } else {
        $cc_error = 'Die Konfigurationsdatei konnte nicht geschrieben werden: ' . cc_e($cc_p['config']);
    }
}

$cc_cfg = cc_config_read();
$cc_konfig_zustand = cc_config_zustand();
/* Fehlende Schluessel EINMAL in die Datei schreiben - nur ueber eine heile
 * Datei. Danach heisst "fehlt" nie mehr "gilt als Vorgabe". */
cc_cfg_vervollstaendigen($cc_cfg);
$cc_geraete = cc_geraete($cc_cfg);
$cc_praefix = cc_cfg($cc_cfg, 'mqtt_topic', 'chromecast4lox');
$cc_pid = cc_dienst_pid();
$cc_ip = cc_localip();
$cc_udpin = cc_mqtt_udpinport();
$cc_broker = cc_mqtt_broker();
$cc_log = cc_log_file();
$cc_zeilen = cc_log_tail($cc_log);

// WICHTIG: LBWeb::lbheader() setzt SDK-Globale - deshalb ueberall cc_-Praefix.
$cc_frame = class_exists('LBWeb', false);
if ($cc_frame) {
    LBWeb::lbheader('Chromecast 4 Lox NG', 'https://wiki.loxberry.de/plugins/chromecast_4_lox/start', 'help.html');
}
?>
<style>
.sm-wrap { max-width: 980px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap, .sm-wrap * { text-shadow: none !important; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap label { display: block; font-weight: 600; font-size: 0.88em; color: #555; margin: 10px 0 4px; }
.sm-wrap input[type=text], .sm-wrap input[type=number], .sm-wrap select, .sm-wrap textarea {
  width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 0.95em; box-sizing: border-box; }
.sm-wrap textarea { font-family: ui-monospace, monospace; min-height: 92px; }
.sm-wrap input[type=checkbox] { width: 17px; height: 17px; margin: 0 6px 0 0; vertical-align: middle; }
.sm-check { font-weight: 400 !important; font-size: 0.95em !important; color: #333 !important; }
.sm-row { display: flex; gap: 12px; flex-wrap: wrap; }
.sm-row > div { flex: 1; min-width: 190px; }
.sm-btn { background: #6dac20; color: #fff !important; border: 0; border-radius: 6px; padding: 10px 22px; font-size: 1em; cursor: pointer; margin-top: 18px; font-weight: 600; }
.sm-wrap .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button { box-shadow: none !important; }
.sm-wrap a.sm-btn, .sm-wrap a.sm-btn:visited, .sm-wrap a.sm-btn:hover { color: #fff !important; text-decoration: none; }
.sm-alert { border-radius: 8px; padding: 10px 14px; margin: 12px 0; }
.sm-ok { background: #e8f5e9; border: 1px solid #a5d6a7; }
.sm-err { background: #ffebee; border: 1px solid #ef9a9a; }
.sm-info { background: #e3f2fd; border: 1px solid #90caf9; font-size: 0.9em; }
.sm-mono { font-family: ui-monospace, monospace; background: #f5f5f5; padding: 2px 6px; border-radius: 4px; }
.sm-small { font-size: 0.82em; color: #666; margin-top: 3px; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0; padding: 9px 18px; cursor: pointer; font-size: 0.95em; color: #444 !important; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-seite { display: none; padding-top: 4px; }
.sm-seite.sm-active { display: block; }
.sm-log { background: #1e1e1e; color: #d4d4d4; font-family: ui-monospace, monospace; font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto; white-space: pre-wrap; }
.sm-step { margin: 10px 0; padding: 10px 14px; background: #fafafa; border-left: 4px solid #6dac20; border-radius: 0 8px 8px 0; }
.sm-tbl { border-collapse: collapse; margin: 8px 0; width: 100%; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ddd; padding: 6px 10px; text-align: left; font-size: 0.9em; vertical-align: top; }
.sm-tbl th { background: #f0f0f0; }

/* --- Einheitliches Kachel-Raster im Reiter Test (Hausstandard) --- */
.sm-h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.sm-knopfreihe form { margin: 0; display: flex; }
.sm-knopfreihe .sm-btn { flex: 0 0 auto; min-width: 250px; text-align: center;
    display: inline-flex; align-items: center; justify-content: center; line-height: 1.25; margin-top: 0; }
.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
/* Hintergrund UND Hover-Farbe je Gruppe, beides mit !important.
   LoxBerry bringt jQuery Mobile mit; das formatiert jedes <button> mit
   eigenem Hintergrund und eigenen Hover-Regeln. Ohne Gegenwehr steht weisse
   Schrift auf hellgrauem Grund - und beim Ueberfahren weiss auf weiss.
   Die Hover-Farben sind deshalb kein Feinschliff, sondern Pflicht. */
.sm-wrap .sm-btn.sm-b-lesen   { background: #6dac20 !important; }
.sm-wrap .sm-btn.sm-b-technik { background: #546e7a !important; }
.sm-wrap .sm-btn.sm-b-aktion  { background: #e0620d !important; }
.sm-wrap .sm-btn.sm-b-lesen:hover,   .sm-wrap .sm-btn.sm-b-lesen:focus   { background: #5c9219 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-technik:hover, .sm-wrap .sm-btn.sm-b-technik:focus { background: #435962 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-aktion:hover,  .sm-wrap .sm-btn.sm-b-aktion:focus  { background: #b84f0a !important; color: #fff !important; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }

/* Nachgetragene Definitionen (CSS-Luecken-Durchgang 13.08.2026):
   benutzt, aber nie definiert - wortgleich aus der Hausstandard-Vorlage
   bzw. der Referenzimplementierung uebernommen. */
.sm-warn { background: #fdf3e3; border: 1px solid #e0620d; }
/* Haken und Kreuz der Selbstpruefung - wortgleich aus der Hausvorlage. */
.sm-an  { color: #1a7f1a; font-weight: 700; }
.sm-aus { color: #b00000; font-weight: 700; }
.sm-unklar { color: #777; font-weight: 700; }
</style>
<div class="sm-wrap">

<?php if ($cc_saved) { ?>
<div class="sm-alert sm-ok"><b><?php echo cc_t('TEXT.T001'); ?></b> <?= $cc_hinweis ?></div>
<?php } ?>
<?php if ($cc_error !== '') { ?><div class="sm-alert sm-err"><b><?php echo cc_t('TEXT.T002'); ?></b> <?= $cc_error ?></div><?php } ?>
<?php if ($cc_konfig_zustand === 'unlesbar' || $cc_konfig_zustand === 'leer') { ?>
<div class="sm-alert sm-warn"><b><?php echo cc_t('TEXT.T002'); ?></b>
<?php echo cc_t('TEXT.W_KONFIG'); ?> <span class="sm-mono"><?= cc_e($cc_p['config']) ?></span></div>
<?php } ?>

<div class="sm-alert sm-info">
<?php echo cc_t('TEXT.T003'); ?> <b><?= $cc_pid ? 'l&auml;uft' : 'l&auml;uft nicht' ?></b><?= $cc_pid ? ' (PID ' . $cc_pid . ') ' : ' ' ?>
<?php echo cc_t('TEXT.T004'); ?> <b><?= count($cc_geraete) ?></b>
<?php echo cc_t('TEXT.T005'); ?> <b><?= cc_cfg($cc_cfg, 'mqtt_ein', '1') === '1' ? 'ein' : 'aus' ?></b>
<?php echo cc_t('TEXT.T006'); ?> <b><?= cc_cfg($cc_cfg, 'udp', '1') === '1' ? 'Port ' . cc_e(cc_cfg($cc_cfg, 'udp_port', '7090')) : 'aus' ?></b>
<?php echo cc_t('TEXT.T007'); ?> <span class="sm-mono"><?= cc_e($cc_ip) ?></span>
</div>

<div class="sm-tabs">
    <a class="sm-tab<?= $cc_tab === 'tab-settings' ? ' sm-active' : '' ?>" data-ziel="tab-settings" href="index.php?tab=settings"><?php echo cc_t('REITER.EINSTELLUNGEN'); ?></a>
    <a class="sm-tab<?= $cc_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" data-ziel="tab-mqtt" href="index.php?tab=mqtt"><?php echo cc_t('REITER.MQTT'); ?></a>
    <a class="sm-tab<?= $cc_tab === 'tab-loxone' ? ' sm-active' : '' ?>" data-ziel="tab-loxone" href="index.php?tab=loxone"><?php echo cc_t('REITER.LOXONE'); ?></a>
    <a class="sm-tab<?= $cc_tab === 'tab-test' ? ' sm-active' : '' ?>" data-ziel="tab-test" href="index.php?tab=test"><?php echo cc_t('REITER.TEST'); ?></a>
    <a class="sm-tab<?= $cc_tab === 'tab-log' ? ' sm-active' : '' ?>" data-ziel="tab-log" href="index.php?tab=log"><?php echo cc_t('REITER.LOG'); ?></a>
</div>

<!-- ================= Reiter: <?php echo cc_t('TEXT.T041'); ?> ================= -->
<div class="sm-seite<?= $cc_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">
<form method="post" action="index.php" name="suchformular">
<input data-role="none" type="hidden" name="fmt" value="<?= cc_e($cc_fmt) ?>"><input data-role="none" type="hidden" name="activetab" value="tab-settings">
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?php echo cc_t('LEGENDE.LESEN'); ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo cc_t('LEGENDE.AKTION'); ?></span>
</div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="suche_geraete" value="1"><?php echo cc_t('SUCHE.K_SUCHEN'); ?></button>
</div>
<div class="sm-small"><?php echo cc_t('SUCHE.H_SUCHEN'); ?></div>
<?php if ($cc_suchfehler !== '') { ?>
<div class="sm-alert sm-err"><b><?php echo cc_t('TEXT.T002'); ?></b> <?= cc_e($cc_suchfehler) ?></div>
<?php } elseif (isset($_POST['suche_geraete'])) { ?>
<?php if (!$cc_gefunden) { ?>
<div class="sm-alert sm-info"><?php echo cc_t('SUCHE.H_NICHTS'); ?></div>
<?php } else { ?>
<table class="sm-tbl">
<tr><th style="width:6%;"></th><th><?php echo cc_t('TEXT.T015'); ?></th><th><?php echo cc_t('SUCHE.SP_MODELL'); ?></th><th><?php echo cc_t('SUCHE.SP_ADRESSE'); ?></th><th><?php echo cc_t('MQTT.SP_ART'); ?></th></tr>
<?php foreach ($cc_gefunden as $cc_gf) {
    $cc_schon = in_array($cc_gf['name'], $cc_geraete, true); ?>
<tr><td><?php if ($cc_schon) { ?><span class="sm-an">&#10003;</span><?php } else { ?><input data-role="none" type="checkbox" name="gefunden[]" value="<?= cc_e($cc_gf['name']) ?>" checked><?php } ?></td>
<td><?= cc_e($cc_gf['name']) ?></td><td><?= cc_e($cc_gf['modell']) ?></td>
<td><span class="sm-mono"><?= cc_e($cc_gf['adresse']) ?></span></td><td><?= cc_e($cc_gf['art']) ?></td></tr>
<?php } ?>
</table>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="uebernehmen" value="1"><?php echo cc_t('SUCHE.K_UEBERNEHMEN'); ?></button>
</div>
<div class="sm-small"><?php echo cc_t('SUCHE.H_UEBERNEHMEN'); ?></div>
<?php } ?>
<?php } ?>
</form>

<form method="post" action="index.php">
<input data-role="none" type="hidden" name="fmt" value="<?= cc_e($cc_fmt) ?>"><input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2><?php echo cc_t('TEXT.H_BETRIEB'); ?></h2>
<label class="sm-check"><input data-role="none" type="checkbox" name="enabled" value="1"<?= cc_cfg($cc_cfg, 'enabled', '1') === '1' ? ' checked' : '' ?>> <b><?php echo cc_t('TEXT.L_ENABLED'); ?></b></label>
<div class="sm-small"><?php echo cc_t('TEXT.H_ENABLED'); ?></div>

<h2><?php echo cc_t('TEXT.T008'); ?></h2>
<label><?php echo cc_t('TEXT.T009'); ?></label>
<textarea data-role="none" name="geraete" placeholder="Wohnzimmer&#10;K&uuml;che Lautsprecher"><?= cc_e(implode("\n", $cc_geraete)) ?></textarea>
<div class="sm-small">
<?php echo cc_t('TEXT.S_NAME_GENAU'); ?>
</div>

<?php if ($cc_geraete) { ?>
<table class="sm-tbl">
<tr><th style="width:45%;"><?php echo cc_t('TEXT.T015'); ?></th><th><?php echo cc_t('TEXT.T016'); ?></th></tr>
<?php foreach ($cc_geraete as $g) { ?>
<tr><td><?= cc_e($g) ?></td><td><span class="sm-mono"><?= cc_e($cc_praefix . '/' . cc_thema($g)) ?></span></td></tr>
<?php } ?>
</table>
<?php } ?>

<h2><?php echo cc_t('MQTT.H_UDP'); ?></h2>
<label class="sm-check"><input data-role="none" type="checkbox" name="udp" value="1"<?= cc_cfg($cc_cfg, 'udp', '1') === '1' ? ' checked' : '' ?>> <?php echo cc_t('TEXT.T021'); ?></label>
<div class="sm-small"><?php echo cc_t('TEXT.T022'); ?></div>

<div class="sm-row" style="margin-top:12px;">
<div>
<label><?php echo cc_t('TEXT.T023'); ?></label>
<input data-role="none" type="number" name="udp_port" min="1" max="65535" value="<?= cc_e(cc_cfg($cc_cfg, 'udp_port', '7090')) ?>">
</div>
</div>
<div class="sm-small"><?php echo cc_t('MQTT.H_VERWEIS'); ?></div>

<h2><?php echo cc_t('TEXT.T026'); ?></h2>
<div class="sm-row">
<div>
<label><?php echo cc_t('TEXT.T027'); ?></label>
<input data-role="none" type="number" name="intervall" min="2" max="3600" value="<?= cc_e(cc_cfg($cc_cfg, 'intervall', '10')) ?>">
<div class="sm-small"><?php echo cc_t('TEXT.T028'); ?></div>
</div>
<div>
<label><?php echo cc_t('TEXT.T029'); ?></label>
<input data-role="none" type="number" name="aktualisierung" min="5" max="86400" value="<?= cc_e(cc_cfg($cc_cfg, 'aktualisierung', '60')) ?>">
<div class="sm-small"><?php echo cc_t('TEXT.T030'); ?></div>
</div>
<div>
<label><?php echo cc_t('TEXT.T031'); ?></label>
<input data-role="none" type="number" name="lautstaerke_schritt" min="1" max="50" value="<?= cc_e(cc_cfg($cc_cfg, 'lautstaerke_schritt', '5')) ?>">
<div class="sm-small"><?php echo cc_t('TEXT.S_SCHRITTWEITE_GILT'); ?></div>
</div>
</div>

<h2><?php echo cc_t('FAV.H'); ?></h2>
<label><?php echo cc_t('FAV.L'); ?></label>
<textarea data-role="none" name="favoriten" placeholder="<?php echo cc_t('FAV.P'); ?>"><?= cc_e(cc_cfg($cc_cfg, 'favoriten', '')) ?></textarea>
<div class="sm-small"><?php echo cc_t('FAV.HINWEIS'); ?></div>
<?php $cc_fav = cc_favoriten($cc_cfg); if ($cc_fav) { ?>
<table class="sm-tbl">
<tr><th style="width:8%;"><?php echo cc_t('FAV.SP_NR'); ?></th><th style="width:30%;"><?php echo cc_t('TEXT.T015'); ?></th><th><?php echo cc_t('FAV.SP_ADRESSE'); ?></th></tr>
<?php foreach ($cc_fav as $cc_i => $cc_f) { ?>
<tr><td><b><?= (int) $cc_i + 1 ?></b></td><td><?= cc_e($cc_f[0]) ?></td><td><span class="sm-mono"><?= cc_e($cc_f[1]) ?></span></td></tr>
<?php } ?>
</table>
<?php } ?>

<h2><?php echo cc_t('TTS.H_ANSAGE'); ?></h2>
<div class="sm-small"><?php echo cc_t('TTS.EINLEITUNG'); ?></div>
<div class="sm-row">
<div>
<label><?php echo cc_t('TTS.L_MODUS'); ?></label>
<select data-role="none" name="tts_modus" id="tts_modus" onchange="ccTtsModus()">
<?php foreach (cc_tts_modi() as $cc_mk => $cc_mt) { ?>
<option value="<?= cc_e($cc_mk) ?>"<?= cc_cfg($cc_cfg, 'tts_modus', 'chromecast') === $cc_mk ? ' selected' : '' ?>><?php echo cc_t($cc_mt); ?></option>
<?php } ?>
</select>
<div class="sm-small"><?php echo cc_t('TTS.H_MODUS'); ?></div>
</div>
<div>
<label><?php echo cc_t('TTS.L_SPRACHE'); ?></label>
<input data-role="none" type="text" name="tts_sprache" maxlength="5" value="<?= cc_e(cc_cfg($cc_cfg, 'tts_sprache', 'de')) ?>">
<div class="sm-small"><?php echo cc_t('TTS.H_SPRACHE'); ?></div>
</div>
<div>
<label><?php echo cc_t('TTS.L_PEGEL'); ?></label>
<input data-role="none" type="text" name="tts_pegel" value="<?= cc_e(cc_cfg($cc_cfg, 'tts_pegel', '')) ?>" placeholder="<?php echo cc_t('TTS.P_PEGEL'); ?>">
<div class="sm-small"><?php echo cc_t('TTS.H_PEGEL'); ?></div>
</div>
</div>
<div style="margin:8px 0;">
<label style="display:inline-flex;align-items:center;gap:6px;">
<input data-role="none" type="checkbox" name="tts_fortsetzen" value="1"<?= cc_cfg($cc_cfg, 'tts_fortsetzen', '1') === '1' ? ' checked' : '' ?>> <?php echo cc_t('TTS.L_FORTSETZEN'); ?>
</label>
<div class="sm-small"><?php echo cc_t('TTS.H_FORTSETZEN'); ?></div>
</div>
<div id="tts_fremd">
<div class="sm-row">
<div>
<label><?php echo cc_t('TTS.L_IP'); ?></label>
<input data-role="none" type="text" name="tts_ip" value="<?= cc_e(cc_cfg($cc_cfg, 'tts_ip', '')) ?>" placeholder="192.168.1.50">
</div>
<div>
<label><?php echo cc_t('TTS.L_PORT'); ?></label>
<input data-role="none" type="number" name="tts_port" min="1" max="65535" value="<?= cc_e(cc_cfg($cc_cfg, 'tts_port', '7091')) ?>">
</div>
<div>
<label><?php echo cc_t('TTS.L_ZONEN'); ?></label>
<input data-role="none" type="text" name="tts_zonen" value="<?= cc_e(cc_cfg($cc_cfg, 'tts_zonen', '1')) ?>" placeholder="2,4,6">
</div>
<div>
<label><?php echo cc_t('TTS.L_LAUTSTAERKE'); ?></label>
<input data-role="none" type="number" name="tts_lautstaerke" min="1" max="100" value="<?= cc_e(cc_cfg($cc_cfg, 'tts_lautstaerke', '8')) ?>">
</div>
</div>
<div id="tts_vorlage_zeile">
<label><?php echo cc_t('TTS.L_VORLAGE'); ?></label>
<input data-role="none" type="text" name="tts_vorlage" value="<?= cc_e(cc_cfg($cc_cfg, 'tts_vorlage', '')) ?>" placeholder="http://{ip}:{port}/tts?text={text}&amp;zone={zones}&amp;vol={vol}">
<div class="sm-small"><?php echo cc_t('TTS.H_VORLAGE'); ?></div>
</div>
</div>
<div id="tts_hinweis_audioserver" class="sm-small" style="display:none;"><?php echo cc_t('TTS.H_AUDIOSERVER'); ?></div>

<h2><?php echo cc_t('SCHNELL.H'); ?></h2>
<label style="display:inline-flex;align-items:center;gap:6px;">
<input data-role="none" type="checkbox" name="beschleunigung" value="1"<?= cc_cfg($cc_cfg, 'beschleunigung', '0') === '1' ? ' checked' : '' ?>> <?php echo cc_t('SCHNELL.L'); ?>
</label>
<div class="sm-alert sm-warn"><?php echo cc_t('SCHNELL.HINWEIS'); ?></div>

<div class="sm-row" style="margin-top:12px;">
<div>
<label><?php echo cc_t('TTS.L_GONG'); ?></label>
<input data-role="none" type="text" name="tts_gong" value="<?= cc_e(cc_cfg($cc_cfg, 'tts_gong', '')) ?>" placeholder="http://…/gong.mp3">
<div class="sm-small"><?php echo cc_t('TTS.H_GONG'); ?></div>
</div>
<div>
<label><?php echo cc_t('TTS.L_BASIS'); ?></label>
<input data-role="none" type="text" name="tts_lokal_basis" value="<?= cc_e(cc_cfg($cc_cfg, 'tts_lokal_basis', '')) ?>" placeholder="http://<?= cc_e($cc_ip) ?>/plugins/<?= cc_e($cc_p['plugin']) ?>">
<div class="sm-small"><?php echo cc_t('TTS.H_BASIS'); ?></div>
</div>
</div>

<h2><?php echo cc_t('RUHE.H'); ?></h2>
<div class="sm-row">
<div>
<label><?php echo cc_t('RUHE.L_MAX'); ?></label>
<input data-role="none" type="number" name="lautstaerke_max" min="0" max="100" value="<?= cc_e(cc_cfg($cc_cfg, 'lautstaerke_max', '100')) ?>">
</div>
<div>
<label><?php echo cc_t('RUHE.L_VON'); ?></label>
<input data-role="none" type="text" name="ruhe_von" maxlength="5" value="<?= cc_e(cc_cfg($cc_cfg, 'ruhe_von', '')) ?>" placeholder="22:00">
</div>
<div>
<label><?php echo cc_t('RUHE.L_BIS'); ?></label>
<input data-role="none" type="text" name="ruhe_bis" maxlength="5" value="<?= cc_e(cc_cfg($cc_cfg, 'ruhe_bis', '')) ?>" placeholder="07:00">
</div>
<div>
<label><?php echo cc_t('RUHE.L_RUHEMAX'); ?></label>
<input data-role="none" type="number" name="ruhe_max" min="0" max="100" value="<?= cc_e(cc_cfg($cc_cfg, 'ruhe_max', '30')) ?>">
</div>
</div>
<div class="sm-small"><?php echo cc_t('RUHE.HINWEIS'); ?></div>

<h2><?php echo cc_t('TTS.H_GRUPPEN'); ?></h2>
<label style="display:inline-flex;align-items:center;gap:6px;">
<input data-role="none" type="checkbox" name="gruppen" value="1"<?= cc_cfg($cc_cfg, 'gruppen', '1') === '1' ? ' checked' : '' ?>> <?php echo cc_t('TTS.L_GRUPPEN'); ?>
</label>
<div class="sm-small"><?php echo cc_t('TTS.H_GRUPPEN_TEXT'); ?></div>

<button data-role="none" class="sm-btn" type="submit" name="save" value="1"><?php echo cc_t('TEXT.T035'); ?></button>
<div class="sm-small"><?php echo cc_t('TEXT.T036'); ?></div>
</form>
</div>

<!-- ================= Reiter: MQTT ================= -->
<div class="sm-seite<?= $cc_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" id="tab-mqtt">

<form method="post" action="index.php">
<input data-role="none" type="hidden" name="fmt" value="<?= cc_e($cc_fmt) ?>"><input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<h2><?php echo cc_t('MQTT.H_WEG'); ?></h2>
<label class="sm-check"><input data-role="none" type="checkbox" name="mqtt_ein" value="1"<?= cc_cfg($cc_cfg, 'mqtt_ein', '1') === '1' ? ' checked' : '' ?>> <b><?php echo cc_t('TEXT.T018'); ?></b> <?php echo cc_t('TEXT.T019'); ?></label>
<div class="sm-small"><?php echo cc_t('TEXT.T020'); ?></div>

<div class="sm-row" style="margin-top:12px;">
<div>
<label><?php echo cc_t('TEXT.T024'); ?></label>
<input data-role="none" type="text" name="mqtt_topic" value="<?= cc_e($cc_praefix) ?>">
<div class="sm-small"><?php echo cc_t('TEXT.T025'); ?></div>
</div>
</div>
<button data-role="none" class="sm-btn" type="submit" name="save_mqtt" value="1"><?php echo cc_t('TEXT.T035'); ?></button>
</form>

<h2><?php echo cc_t('MQTT.H_GATEWAY'); ?></h2>
<div class="sm-small">
<?php echo cc_t('TEXT.T075'); ?> <span class="sm-mono"><?= $cc_broker !== '' ? cc_e($cc_broker) : cc_e(cc_t('MQTT.A_KEIN_GATEWAY')) ?></span>
<?php echo cc_t('TEXT.T076'); ?> <span class="sm-mono"><?= cc_e($cc_praefix) ?></span>
</div>
<?php /* Autostart und Fassung kommen aus EINER Funktion und damit aus
        einem Dateizugriff - ein Schluessel, ein Wortlaut. */
$cc_gw = cc_mqtt_gateway_info();
if ($cc_gw !== null && !$cc_gw['autostart']) { ?><div class="sm-alert sm-warn"><b>MQTT:</b> <?php echo cc_t('TEXT.W_AUTOSTART'); ?></div><?php } ?>
<div class="sm-small"><?= cc_e(sprintf(cc_t('MQTT.A_FASSUNG'), cc_gateway_fassung() > 0 ? cc_gateway_fassung() : cc_t('MQTT.A_UNBEKANNT'))) ?></div>
<?php if (cc_cfg($cc_cfg, 'mqtt_ein', '1') !== '1') { ?>
<div class="sm-alert sm-err"><?php echo cc_t('TEXT.T077'); ?></div>
<?php } ?>
<?php if (!$cc_udpin) { ?>
<div class="sm-alert sm-info"><?php echo cc_t('TEXT.S_UDPPORT_FEHLT'); ?></div>
<?php } ?>

<h2><?php echo cc_t('MQTT.H_ABO'); ?></h2>
<?php
/* Der Satz "Ohne diesen Eintrag kommt am Miniserver nichts an" gilt NUR fuer
 * Gateway V1. Unter V2 schaltet der LoxBerry-Kern auf der Abonnement-Seite
 * die Knoepfe ab - dort ist nichts einzutragen. Ist die Fassung nicht
 * lesbar, stehen BEIDE Saetze da: einen von beiden zu behaupten waere fuer
 * die Haelfte der Anlagen falsch. */
$cc_gwf = cc_gateway_fassung();
?>
<?php if ($cc_gwf >= 2) { ?>
<div class="sm-alert sm-info"><?php echo cc_t('MQTT.ABO_V2'); ?></div>
<?php } elseif ($cc_gwf === 1) { ?>
<div class="sm-step"><?php echo cc_t('MQTT.ABO_PFLICHT'); ?>
<div class="sm-mono" style="background:#f4f4f4;border:1px solid #ccc;padding:8px;margin-top:6px;"><?= cc_e($cc_praefix) ?>/#</div></div>
<?php } else { ?>
<div class="sm-step"><?php echo cc_t('MQTT.ABO_PFLICHT'); ?>
<div class="sm-mono" style="background:#f4f4f4;border:1px solid #ccc;padding:8px;margin-top:6px;"><?= cc_e($cc_praefix) ?>/#</div></div>
<div class="sm-alert sm-info"><?php echo cc_t('MQTT.ABO_V2'); ?></div>
<div class="sm-small"><?php echo cc_t('MQTT.ABO_UNBEKANNT'); ?></div>
<?php } ?>

<h2><?php echo cc_t('TEXT.T096'); ?></h2>
<?php
/* Eine Aussage ueber eine Menge wird aus der Menge GERECHNET, nicht
 * getippt. "Alle Themen sind retained" stand bei einer anderen Linie an
 * sieben Stellen und war falsch. */
$cc_alle = cc_themen();
$cc_zahl = count($cc_alle['geraet']);
$cc_ret = 0;
foreach ($cc_alle['geraet'] as $cc_e1) { if (!empty($cc_e1['retain'])) { $cc_ret++; } }
?>
<table class="sm-tbl">
<tr><th style="width:22%;"><?php echo cc_t('TEXT.T097'); ?></th><th style="width:10%;"><?php echo cc_t('MQTT.SP_ART'); ?></th><th style="width:10%;"><?php echo cc_t('MQTT.SP_RETAIN'); ?></th><th><?php echo cc_t('TEXT.T098'); ?></th></tr>
<?php foreach ($cc_alle['geraet'] as $cc_e1) { ?>
<tr><td><span class="sm-mono"><?= cc_e($cc_e1['schluessel']) ?></span></td>
<td><?= cc_e($cc_e1['art']) ?></td>
<td><?= empty($cc_e1['retain']) ? '&ndash;' : cc_e(cc_t('TEST.A_JA')) ?></td>
<td><?= cc_e(cc_thema_lang($cc_e1['schluessel'])) ?></td></tr>
<?php } ?>
</table>
<div class="sm-small"><?= cc_e(sprintf(cc_t('MQTT.A_ZAEHLUNG'), $cc_zahl, $cc_ret)) ?></div>

<div class="sm-alert sm-info sm-small"><?= sprintf(cc_t('MQTT.H_SAMMELZIEL'), cc_e($cc_praefix)) ?></div>

<h2><?php echo cc_t('MQTT.H_DIENST'); ?></h2>
<table class="sm-tbl">
<tr><th style="width:22%;"><?php echo cc_t('TEXT.T097'); ?></th><th style="width:10%;"><?php echo cc_t('MQTT.SP_ART'); ?></th><th><?php echo cc_t('TEXT.T098'); ?></th></tr>
<?php /* Ueber die Huelle, nicht ueber eine zweite Schleife: cc_dienst_themen()
        setzt Beschriftung und Art in derselben Form zusammen wie
        cc_status_themen() daneben. Zwei Stellen, die dasselbe tun, laufen
        auseinander. */ ?>
<?php foreach (cc_dienst_themen() as $cc_k1 => $cc_i1) { ?>
<tr><td><span class="sm-mono"><?= cc_e($cc_praefix . '/server/' . $cc_k1) ?></span></td>
<td><?= cc_e($cc_i1[1]) ?></td>
<td><?= cc_e($cc_i1[0]) ?></td></tr>
<?php } ?>
</table>

<h2><?php echo cc_t('TEXT.T104'); ?></h2>
<table class="sm-tbl">
<tr><th style="width:22%;"><?php echo cc_t('TEXT.T105'); ?></th><th style="width:10%;"><?php echo cc_t('MQTT.SP_ART'); ?></th><th><?php echo cc_t('TEXT.T098'); ?></th></tr>
<?php foreach ($cc_alle['befehle'] as $cc_e1) { ?>
<tr><td><span class="sm-mono"><?= cc_e($cc_e1['schluessel']) ?></span></td>
<td><?= cc_e($cc_e1['art']) ?></td>
<td><?= cc_e(cc_thema_lang('cmd_' . $cc_e1['schluessel'])) ?></td></tr>
<?php } ?>
</table>
<div class="sm-small"><?php echo cc_t('TEXT.T099'); ?>
<span class="sm-mono"><?= cc_e($cc_praefix) ?><?php echo cc_t('TEXT.T100'); ?></span><?php echo cc_t('TEXT.T101'); ?> <span class="sm-mono"><?= cc_e($cc_praefix) ?><?php echo cc_t('TEXT.T102'); ?></span> <?php echo cc_t('TEXT.T103'); ?></div>

</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="sm-seite<?= $cc_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">

<h2><?php echo cc_t('TEXT.T037'); ?></h2>
<div class="sm-small"><?php echo cc_t('TEXT.T038'); ?></div>

<div class="sm-step"><?php echo cc_t('TEXT.S_SCHRITT1'); ?></div>

<div class="sm-step"><?php echo cc_t('TEXT.S_SCHRITT2_KOPF'); ?> <?php echo cc_t('MQTT.ABO_VERWEIS'); ?></div>

<div class="sm-step"><?php echo cc_t('TEXT.S_SCHRITT3'); ?></div>

<div class="sm-step"><?php echo cc_t('TEXT.S_SCHRITT4'); ?>
<span class="sm-mono"><?= cc_e($cc_praefix) ?><?php echo cc_t('TEXT.T061'); ?></span> <?php echo cc_t('TEXT.T062'); ?></div>

<div class="sm-step"><?php echo cc_t('TEXT.S_SCHRITT5'); ?></div>

<h2><?php echo cc_t('TEXT.T070'); ?></h2>
<div class="sm-small" style="margin-bottom:8px;">
<?php echo cc_t('TEXT.T071'); ?> <b><?php echo cc_t('TEXT.T072'); ?></b>
(<span class="sm-mono"><?php echo cc_t('TEXT.T073'); ?><?= cc_e($cc_ip) ?>/<?= $cc_udpin ? (int) $cc_udpin : '&lt;Port&gt;' ?></span><?php echo cc_t('TEXT.T074'); ?>
</div>
<div class="sm-small"><?php echo cc_t('MQTT.H_VERWEIS'); ?></div>

<h2><?php echo cc_t('TEXT.T082'); ?></h2>
<?php if (!$cc_geraete) { ?>
<div class="sm-alert sm-err"><?php echo cc_t('TEXT.T083'); ?></div>
<?php } else { ?>
<div class="sm-small"><?php echo cc_t('TEXT.T084'); ?> <b><?= count($cc_geraete) ?></b> <?php echo cc_t('TEXT.T085'); ?>
<?= cc_e(implode(', ', $cc_geraete)) ?><?php echo cc_t('TEXT.T086'); ?> <?= count(cc_status_themen()) ?> <?php echo cc_t('TEXT.T087'); ?> <?= count(cc_befehle()) ?> <?php echo cc_t('TEXT.T088'); ?></div>
<?php } ?>

<form method="post" action="index.php">
<input data-role="none" type="hidden" name="fmt" value="<?= cc_e($cc_fmt) ?>"><input data-role="none" type="hidden" name="activetab" value="tab-loxone">
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?php echo cc_t('LEGENDE.TECHNIK_DATEI'); ?></span>
</div>
<h3 class="sm-h3"><?php echo cc_t('TEXT.T089'); ?></h3>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-technik" type="submit" name="download" value="mqtt_in"><?php echo cc_t('TEXT.T090'); ?></button>
<button data-role="none" class="sm-btn sm-b-technik" type="submit" name="download" value="mqtt_out"><?php echo cc_t('TEXT.T091'); ?></button>
</div>
<h3 class="sm-h3"><?php echo cc_t('TEXT.T092'); ?></h3>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-technik" type="submit" name="download" value="udp_out"><?php echo cc_t('TEXT.T093'); ?></button>
</div>
<div class="sm-small"><?php echo cc_t('TEXT.T094'); ?> <?= cc_e(cc_cfg($cc_cfg, 'udp_port', '7090')) ?> <?php echo cc_t('TEXT.T095'); ?></div>
</form>

<div class="sm-small"><?php echo cc_t('MQTT.H_VERWEIS_TABELLE'); ?></div>
<h2><?php echo cc_t('TEXT.T106'); ?></h2>
<div class="sm-small"><?php echo cc_t('TEXT.S_LOGIK_EINLEITUNG'); ?></div>
<table class="sm-tbl">
<tr><th>#</th><th><?php echo cc_t('TEXT.T111'); ?></th><th><?php echo cc_t('TEXT.T112'); ?></th><th><?php echo cc_t('TEXT.T113'); ?></th><th><?php echo cc_t('TEXT.T114'); ?></th></tr>
<tr><td>1</td><td><?php echo cc_t('TEXT.T115'); ?></td><td class="sm-mono"><?= cc_e($cc_praefix) ?><?php echo cc_t('TEXT.T116'); ?></td><td><?php echo cc_t('TEXT.T117'); ?></td><td><?php echo cc_t('TEXT.T118'); ?></td></tr>
<tr><td>2</td><td>Virtueller Eingang</td><td class="sm-mono"><?= cc_e($cc_praefix) ?><?php echo cc_t('TEXT.T119'); ?></td><td>digital</td><td><?php echo cc_t('TEXT.T120'); ?></td></tr>
<tr><td>3</td><td>Virtueller Eingang</td><td class="sm-mono"><?= cc_e($cc_praefix) ?><?php echo cc_t('TEXT.T121'); ?></td><td><?php echo cc_t('TEXT.T122'); ?></td><td>&mdash;</td></tr>
<tr><td>4</td><td>Virtueller Eingang</td><td class="sm-mono"><?= cc_e($cc_praefix) ?><?php echo cc_t('TEXT.T123'); ?></td><td>digital</td><td>&mdash;</td></tr>
<tr><td>5</td><td><?php echo cc_t('TEXT.T124'); ?></td><td class="sm-mono"><?= cc_e($cc_praefix) ?><?php echo cc_t('TEXT.T125'); ?></td><td><?php echo cc_t('TEXT.T126'); ?></td><td>&mdash;</td></tr>
<tr><td>6</td><td><?php echo cc_t('TEXT.T127'); ?></td><td>Chromecast</td><td><?php echo cc_t('TEXT.T129'); ?> <span class="sm-mono">/dev/udp/<?= cc_e($cc_ip) ?>/<?= $cc_udpin ? (int) $cc_udpin : '&lt;Port&gt;' ?></span><?php echo cc_t('TEXT.T130'); ?></td><td><?php echo cc_t('TEXT.T131'); ?></td></tr>
<tr><td>7</td><td><?php echo cc_t('TEXT.T132'); ?></td><td><?php echo cc_t('TEXT.T133'); ?></td><td><?php echo cc_t('TEXT.T134'); ?></td><td><?php echo cc_t('TEXT.T135'); ?></td></tr>
<tr><td>8</td><td><?php echo cc_t('TEXT.T136'); ?></td><td><?php echo cc_t('TEXT.T137'); ?></td><td>&mdash;</td><td><?php echo cc_t('TEXT.T138'); ?> <span class="sm-mono">play</span></td></tr>
<tr><td>9</td><td><?php echo cc_t('TEXT.T140'); ?></td><td><?php echo cc_t('TEXT.T141'); ?></td><td>&mdash;</td><td>Eingang = #7 <?php echo cc_t('TEXT.S_ZU_9'); ?></td></tr>
<tr><td>10</td><td><?php echo cc_t('TEXT.T143'); ?></td><td><?php echo cc_t('TEXT.T144'); ?></td><td>Visualisierung EIN</td><td>&rarr; Ausgangsbefehl <span class="sm-mono">volume_up</span></td></tr>
<tr><td>11</td><td>Taster</td><td><?php echo cc_t('TEXT.T146'); ?></td><td>Visualisierung EIN</td><td>&rarr; Ausgangsbefehl <span class="sm-mono">volume_down</span></td></tr>
<tr><td>12</td><td><?php echo cc_t('TEXT.T147'); ?></td><td><?php echo cc_t('TEXT.T148'); ?></td><td>&mdash;</td><td><?php echo cc_t('TEXT.T149'); ?></td></tr>
<tr><td>13</td><td><?php echo cc_t('TEXT.T150'); ?></td><td><?php echo cc_t('TEXT.T151'); ?></td><td><?php echo cc_t('TEXT.T152'); ?></td><td><?php echo cc_t('TEXT.T153'); ?></td></tr>
<tr><td>14</td><td>Status</td><td>Musik &lt;G&gt;</td><td><?php echo cc_t('TEXT.T154'); ?></td><td>v1 = #5, v2 = #3</td></tr>
</table>
<div class="sm-alert sm-info">
<b>Zu #6:</b> <?php echo cc_t('TEXT.S_ZU_6'); ?>
</div>

<div class="sm-small">
<?php echo cc_t('TEXT.T165'); ?> <span class="sm-mono"><?= cc_e($cc_praefix) ?><?php echo cc_t('TEXT.T166'); ?></span><?php echo cc_t('TEXT.T167'); ?> <span class="sm-mono"><?php echo cc_t('TEXT.T168'); ?></span> <?php echo cc_t('TEXT.T169'); ?>
</div>
</div>

<!-- ================= Reiter: Test ================= -->
<div class="sm-seite<?= $cc_tab === 'tab-test' ? ' sm-active' : '' ?>" id="tab-test">
<?php
/* Die Selbstpruefung kostet etwas: zwei Python-Aufrufe und drei erzeugte
 * Vorlagen. Auf jedem Seitenaufbau waere das eine Pruefung, fuer die niemand
 * da ist - und die Zeitschranke laege bei jedem Klick auf Speichern im Weg.
 * Sie laeuft deshalb nur, wenn dieser Reiter SERVERSEITIG der offene ist.
 * Damit das mit einem Klick erreichbar bleibt, laedt genau dieser eine
 * Reiter die Seite neu; die uebrigen schaltet das JavaScript ohne Neuladen. */
if ($cc_tab === 'tab-test') {
    require_once __DIR__ . '/cc_test.php';
    list($cc_zeilen_p, $cc_bilanz) = cc_selbstpruefung();
?>
<h2><?php echo cc_t('TEST.H_PRUEFUNG'); ?></h2>
<table class="sm-tbl">
<tr><th style="width:44%;"><?php echo cc_t('TEST.SP_FRAGE'); ?></th><th style="width:6%;"></th><th><?php echo cc_t('TEST.SP_ANTWORT'); ?></th></tr>
<?php foreach ($cc_zeilen_p as $cc_zp) { ?>
<tr><td><?= cc_e($cc_zp[0]) ?></td>
<td><?php if ($cc_zp[1] === true) { ?><span class="sm-an">&#10003;</span><?php }
          elseif ($cc_zp[1] === false) { ?><span class="sm-aus">&#10007;</span><?php }
          else { ?><span class="sm-unklar">&ndash;</span><?php } ?></td>
<td><?= cc_e($cc_zp[2]) ?></td></tr>
<?php } ?>
</table>
<div class="sm-small"><?= cc_e(sprintf(cc_t('TEST.BILANZ'), $cc_bilanz[0], $cc_bilanz[1], $cc_bilanz[2])) ?></div>
<?php if ($cc_bilanz[2] > 0) { ?>
<div class="sm-small"><?php echo cc_t('TEST.BILANZ_STRICH'); ?></div>
<?php } ?>
<?php } ?>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?php echo cc_t('LEGENDE.LESEN'); ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?php echo cc_t('LEGENDE.TECHNIK'); ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo cc_t('LEGENDE.AKTION'); ?></span>
</div>

<h3 class="sm-h3"><?php echo cc_t('TEXT.T170'); ?></h3>
<div class="sm-knopfreihe">
<form method="post" action="index.php"><input data-role="none" type="hidden" name="fmt" value="<?= cc_e($cc_fmt) ?>"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="status"><?php echo cc_t('TEXT.T171'); ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="fmt" value="<?= cc_e($cc_fmt) ?>"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="suchen"><?php echo cc_t('TEXT.T174'); ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="fmt" value="<?= cc_e($cc_fmt) ?>"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="themen"><?php echo cc_t('TEXT.T172'); ?></button></form>
</div>

<h3 class="sm-h3"><?php echo cc_t('TEXT.T173'); ?></h3>
<div class="sm-knopfreihe">
<form method="post" action="index.php"><input data-role="none" type="hidden" name="fmt" value="<?= cc_e($cc_fmt) ?>"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="konfig"><?php echo cc_t('TEXT.T184'); ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="fmt" value="<?= cc_e($cc_fmt) ?>"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="umgebung"><?php echo cc_t('TEXT.T175'); ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="fmt" value="<?= cc_e($cc_fmt) ?>"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="mqttinfo"><?php echo cc_t('TEXT.T176'); ?></button></form>
</div>

<h3 class="sm-h3"><?php echo cc_t('TEXT.T177'); ?></h3>
<div class="sm-knopfreihe">
<form method="post" action="index.php"><input data-role="none" type="hidden" name="fmt" value="<?= cc_e($cc_fmt) ?>"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="restart"><?php echo cc_t('TEXT.T178'); ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="fmt" value="<?= cc_e($cc_fmt) ?>"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="stop"><?php echo cc_t('TEXT.T179'); ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="fmt" value="<?= cc_e($cc_fmt) ?>"><input data-role="none" type="hidden" name="activetab" value="tab-test">
<?php if (count($cc_geraete) > 1) { ?><select data-role="none" name="testgeraet" style="width:auto;margin-right:8px;">
<?php foreach ($cc_geraete as $g) { ?><option value="<?= cc_e($g) ?>"><?= cc_e($g) ?></option><?php } ?>
</select><?php } ?>
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="ping"><?php echo cc_t('TEXT.T180'); ?></button></form>
</div>

<?php if ($cc_test_titel !== '') { ?>
<h2><?= cc_e($cc_test_titel) ?></h2>
<div class="sm-log"><?= cc_e($cc_test_text) ?></div>
<?php } else { ?>
<div class="sm-alert sm-info" style="margin-top:18px;"><?php echo cc_t('TEXT.T181'); ?></div>
<?php } ?>
</div>

<!-- ================= Reiter: Logdateien ================= -->
<div class="sm-seite<?= $cc_tab === 'tab-log' ? ' sm-active' : '' ?>" id="tab-log">
<h2><?php echo cc_t('TEXT.T182'); ?></h2>
<div class="sm-small">
<?php if ($cc_log !== '') { ?>
<?php echo cc_t('TEXT.T183'); ?> <span class="sm-mono"><?= cc_e($cc_log) ?></span> &middot; <?php echo cc_t('TEXT.NEUESTE_ZEILE'); ?>
<?php } else { ?>
<?php echo cc_t('TEXT.KEIN_PROTOKOLL'); ?>
<?php } ?>
</div>
<?php if ($cc_zeilen) { ?>
<div class="sm-log"><?php foreach ($cc_zeilen as $z) { echo cc_e($z) . "\n"; } ?></div>
<?php } ?>
</div>

</div>
<script>
function ccTtsModus() {
    var m = document.getElementById('tts_modus');
    if (!m) { return; }
    var wert = m.value;
    var fremd = document.getElementById('tts_fremd');
    var vorlage = document.getElementById('tts_vorlage_zeile');
    var hinweis = document.getElementById('tts_hinweis_audioserver');
    // Im Modus 'chromecast' spricht der Lautsprecher selbst - dann braucht
    // es weder Adresse noch Zone. Beim originalen Audioserver spricht das
    // Plugin gar nicht, und das steht dann auch da.
    fremd.style.display = (wert === 'chromecast' || wert === 'audioserver') ? 'none' : 'block';
    vorlage.style.display = (wert === 'ms4h' || wert === 'custom') ? 'block' : 'none';
    hinweis.style.display = (wert === 'audioserver') ? 'block' : 'none';
}

(function () {
    var tabs = document.querySelectorAll('.sm-tab');
    var start = <?= json_encode($cc_tab) ?>;
    function zeige(id) {
        var i;
        for (i = 0; i < tabs.length; i++) {
            tabs[i].classList.toggle('sm-active', tabs[i].getAttribute('data-ziel') === id);
        }
        var panes = document.querySelectorAll('.sm-seite');
        for (i = 0; i < panes.length; i++) {
            panes[i].classList.toggle('sm-active', panes[i].id === id);
        }
    }
    for (var i = 0; i < tabs.length; i++) {
        (function (t) {
            t.addEventListener('click', function (ev) {
                // Der Reiter Test laedt die Seite WIRKLICH neu: seine
                // Selbstpruefung laeuft serverseitig und nur dann, wenn er
                // der offene ist. Die uebrigen schalten ohne Neuladen um.
                if (t.getAttribute('data-ziel') === 'tab-test') { return; }
                ev.preventDefault();
                zeige(t.getAttribute('data-ziel'));
            });
        })(tabs[i]);
    }
    zeige(start);
})();
ccTtsModus();
</script>
<?php
if ($cc_frame) {
    LBWeb::lbfooter();
}
