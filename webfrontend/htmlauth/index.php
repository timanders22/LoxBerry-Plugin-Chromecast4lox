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
$cc_tabliste = array('tab-settings', 'tab-loxone', 'tab-test', 'tab-log');
$cc_tab = 'tab-settings';
if (isset($_GET['tab']) && in_array('tab-' . (string) $_GET['tab'], $cc_tabliste, true)) {
    $cc_tab = 'tab-' . (string) $_GET['tab'];
}
if (isset($_POST['activetab']) && in_array((string) $_POST['activetab'], $cc_tabliste, true)) {
    $cc_tab = (string) $_POST['activetab'];
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
            header('Content-Disposition: attachment; filename=' . $name);
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

    $neu['geraete']       = $saeubern($_POST['geraete'] ?? '');
    $praefix              = cc_thema($saeubern($_POST['themenpraefix'] ?? 'chromecast4lox'));
    $neu['themenpraefix'] = $praefix !== '' ? $praefix : 'chromecast4lox';
    $neu['mqtt']          = isset($_POST['mqtt']) ? '1' : '0';
    $neu['udp']           = isset($_POST['udp']) ? '1' : '0';
    $neu['udp_port']      = $zahl($_POST['udp_port'] ?? '', 7090, 1, 65535);
    $neu['intervall']     = $zahl($_POST['intervall'] ?? '', 10, 2, 3600);
    $neu['aktualisierung'] = $zahl($_POST['aktualisierung'] ?? '', 60, 5, 86400);
    $neu['lautstaerke_schritt'] = $zahl($_POST['lautstaerke_schritt'] ?? '', 5, 1, 50);

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
    $neu['gruppen']         = isset($_POST['gruppen']) ? '1' : '0';

    if (cc_config_write($neu)) {
        $cc_saved = true;
        require_once __DIR__ . '/cc_test.php';
        cc_dienst('restart');
        $cc_hinweis = cc_dienst_pid()
            ? 'Der Dienst wurde neu gestartet.'
            : 'Der Dienst l&auml;uft nicht &mdash; siehe Reiter Logdateien.';
    } else {
        $cc_error = 'Die Konfigurationsdatei konnte nicht geschrieben werden: ' . cc_e($cc_p['config']);
    }
}

$cc_cfg = cc_config_read();
$cc_geraete = cc_geraete($cc_cfg);
$cc_praefix = cc_cfg($cc_cfg, 'themenpraefix', 'chromecast4lox');
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
.sm-wrap label { dis<?php echo cc_t('TEXT.T139'); ?>: block; font-weight: 600; font-size: 0.88em; color: #555; margin: 10px 0 4px; }
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
.sm-pane { display: none; padding-top: 4px; }
.sm-pane.sm-active { display: block; }
.sm-log { background: #1e1e1e; color: #d4d4d4; font-family: ui-monospace, monospace; font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto; white-space: pre-wrap; }
.sm-step { margin: 10px 0; padding: 10px 14px; background: #fafafa; border-left: 4px solid #6dac20; border-radius: 0 8px 8px 0; }
.sm-tbl { border-collapse: collapse; margin: 8px 0; width: 100%; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ddd; padding: 6px 10px; text-align: left; font-size: 0.9em; vertical-align: top; }
.sm-tbl th { background: #f0f0f0; }

/* --- Einheitliches Kachel-Raster im Reiter <?php echo cc_t('TEXT.T043'); ?> (Hausstandard) --- */
.sm-h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.sm-knopfreihe form { margin: 0; display: flex; }
.sm-knopfreihe .sm-btn { flex: 0 0 auto; min-width: 250px; text-align: center;
    display: inline-flex; align-items: center; justify-content: center; line-height: 1.25; margin-top: 0; }
.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-btn.sm-b-lesen   { background: #6dac20; }
.sm-btn.sm-b-technik { background: #546e7a; }
.sm-btn.sm-b-aktion  { background: #e0620d; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
</style>
<div class="sm-wrap">

<?php if ($cc_saved) { ?>
<div class="sm-alert sm-ok"><b><?php echo cc_t('TEXT.T001'); ?></b> <?= $cc_hinweis ?></div>
<?php } ?>
<?php if ($cc_error !== '') { ?><div class="sm-alert sm-err"><b><?php echo cc_t('TEXT.T002'); ?></b> <?= $cc_error ?></div><?php } ?>

<div class="sm-alert sm-info">
<?php echo cc_t('TEXT.T003'); ?> <b><?= $cc_pid ? 'l&auml;uft' : 'l&auml;uft nicht' ?></b><?= $cc_pid ? ' (PID ' . $cc_pid . ') ' : ' ' ?>
<?php echo cc_t('TEXT.T004'); ?> <b><?= count($cc_geraete) ?></b>
<?php echo cc_t('TEXT.T005'); ?> <b><?= cc_cfg($cc_cfg, 'mqtt', '1') === '1' ? 'ein' : 'aus' ?></b>
<?php echo cc_t('TEXT.T006'); ?> <b><?= cc_cfg($cc_cfg, 'udp', '1') === '1' ? 'Port ' . cc_e(cc_cfg($cc_cfg, 'udp_port', '7090')) : 'aus' ?></b>
<?php echo cc_t('TEXT.T007'); ?> <span class="sm-mono"><?= cc_e($cc_ip) ?></span>
</div>

<div class="sm-tabs">
    <a class="sm-tab<?= $cc_tab === 'tab-settings' ? ' sm-active' : '' ?>" data-pane="tab-settings" href="index.php?tab=settings"><?php echo cc_t('REITER.EINSTELLUNGEN'); ?></a>
    <a class="sm-tab<?= $cc_tab === 'tab-loxone' ? ' sm-active' : '' ?>" data-pane="tab-loxone" href="index.php?tab=loxone"><?php echo cc_t('REITER.LOXONE'); ?></a>
    <a class="sm-tab<?= $cc_tab === 'tab-test' ? ' sm-active' : '' ?>" data-pane="tab-test" href="index.php?tab=test"><?php echo cc_t('REITER.TEST'); ?></a>
    <a class="sm-tab<?= $cc_tab === 'tab-log' ? ' sm-active' : '' ?>" data-pane="tab-log" href="index.php?tab=log"><?php echo cc_t('REITER.LOG'); ?></a>
</div>

<!-- ================= Reiter: <?php echo cc_t('TEXT.T041'); ?> ================= -->
<div class="sm-pane<?= $cc_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">
<form method="post" action="index.php">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2><?php echo cc_t('TEXT.T008'); ?></h2>
<label><?php echo cc_t('TEXT.T009'); ?></label>
<textarea data-role="none" name="geraete" placeholder="Wohnzimmer&#10;K&uuml;che Lautsprecher"><?= cc_e(implode("\n", $cc_geraete)) ?></textarea>
<div class="sm-small">
<?php echo cc_t('TEXT.T010'); ?> <b><?php echo cc_t('TEXT.T011'); ?></b> <?php echo cc_t('TEXT.T012'); ?> <i><?php echo cc_t('TEXT.T013'); ?></i> <?php echo cc_t('TEXT.T014'); ?>
</div>

<?php if ($cc_geraete) { ?>
<table class="sm-tbl">
<tr><th style="width:45%;"><?php echo cc_t('TEXT.T015'); ?></th><th><?php echo cc_t('TEXT.T016'); ?></th></tr>
<?php foreach ($cc_geraete as $g) { ?>
<tr><td><?= cc_e($g) ?></td><td><span class="sm-mono"><?= cc_e($cc_praefix . '/' . cc_thema($g)) ?></span></td></tr>
<?php } ?>
</table>
<?php } ?>

<h2><?php echo cc_t('TEXT.T017'); ?></h2>
<label class="sm-check"><input data-role="none" type="checkbox" name="mqtt" value="1"<?= cc_cfg($cc_cfg, 'mqtt', '1') === '1' ? ' checked' : '' ?>> <b><?php echo cc_t('TEXT.T018'); ?></b> <?php echo cc_t('TEXT.T019'); ?></label>
<div class="sm-small"><?php echo cc_t('TEXT.T020'); ?></div>

<label class="sm-check" style="margin-top:10px;"><input data-role="none" type="checkbox" name="udp" value="1"<?= cc_cfg($cc_cfg, 'udp', '1') === '1' ? ' checked' : '' ?><?php echo cc_t('TEXT.T021'); ?></label>
<div class="sm-small"><?php echo cc_t('TEXT.T022'); ?></div>

<div class="sm-row" style="margin-top:12px;">
<div>
<label><?php echo cc_t('TEXT.T023'); ?></label>
<input data-role="none" type="number" name="udp_port" min="1" max="65535" value="<?= cc_e(cc_cfg($cc_cfg, 'udp_port', '7090')) ?>">
</div>
<div>
<label><?php echo cc_t('TEXT.T024'); ?></label>
<input data-role="none" type="text" name="themenpraefix" value="<?= cc_e($cc_praefix) ?>">
<div class="sm-small"><?php echo cc_t('TEXT.T025'); ?></div>
</div>
</div>

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
<div class="sm-small"><?php echo cc_t('TEXT.T032'); ?> <span class="sm-mono"><?php echo cc_t('TEXT.T033'); ?></span> und <span class="sm-mono"><?php echo cc_t('TEXT.T034'); ?></span>.</div>
</div>
</div>

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

<h2><?php echo cc_t('TTS.H_GRUPPEN'); ?></h2>
<label style="display:inline-flex;align-items:center;gap:6px;">
<input data-role="none" type="checkbox" name="gruppen" value="1"<?= cc_cfg($cc_cfg, 'gruppen', '1') === '1' ? ' checked' : '' ?>> <?php echo cc_t('TTS.L_GRUPPEN'); ?>
</label>
<div class="sm-small"><?php echo cc_t('TTS.H_GRUPPEN_TEXT'); ?></div>

<button data-role="none" class="sm-btn" type="submit" name="save" value="1"><?php echo cc_t('TEXT.T035'); ?></button>
<div class="sm-small"><?php echo cc_t('TEXT.T036'); ?></div>
</form>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="sm-pane<?= $cc_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">

<h2><?php echo cc_t('TEXT.T037'); ?></h2>
<div class="sm-small"><?php echo cc_t('TEXT.T038'); ?></div>

<div class="sm-step"><b><?php echo cc_t('TEXT.T039'); ?></b><br><br>
<?php echo cc_t('TEXT.T040'); ?> <i>Einstellungen</i><?php echo cc_t('TEXT.T042'); ?> <i>Test</i> <?php echo cc_t('TEXT.T044'); ?>
<i><?php echo cc_t('TEXT.T128'); ?>s im Netz suchen</i>. <b><?php echo cc_t('TEXT.T045'); ?></b> <?php echo cc_t('TEXT.T046'); ?></div>

<div class="sm-step"><b><?php echo cc_t('TEXT.T047'); ?></b><br><br>
<b><?php echo cc_t('TEXT.T048'); ?></b> <?php echo cc_t('TEXT.T049'); ?>
<i><?php echo cc_t('TEXT.T050'); ?></i>:
<div class="sm-mono" style="background:#f4f4f4;border:1px solid #ccc;padding:8px;margin-top:6px;"><?= cc_e($cc_praefix) ?>/#</div></div>

<div class="sm-step"><b><?php echo cc_t('TEXT.T051'); ?></b><br><br>
<?php echo cc_t('TEXT.T052'); ?></div>

<div class="sm-step"><b><?php echo cc_t('TEXT.T053'); ?></b><br><br>
<?php echo cc_t('TEXT.T054'); ?> <i><?php echo cc_t('TEXT.T055'); ?></i> <?php echo cc_t('TEXT.T056'); ?> <i><?php echo cc_t('TEXT.T057'); ?></i> <?php echo cc_t('TEXT.T058'); ?>
<b><?php echo cc_t('TEXT.T059'); ?></b> <?php echo cc_t('TEXT.T060'); ?>
<span class="sm-mono"><?= cc_e($cc_praefix) ?><?php echo cc_t('TEXT.T061'); ?></span> <?php echo cc_t('TEXT.T062'); ?></div>

<div class="sm-step"><b><?php echo cc_t('TEXT.T063'); ?></b><br><br>
<?php echo cc_t('TEXT.T064'); ?> <i><?php echo cc_t('TEXT.T065'); ?></i><?php echo cc_t('TEXT.T066'); ?> <span class="sm-mono">v1</span> mit
<span class="sm-mono"><?php echo cc_t('TEXT.T067'); ?></span> und <span class="sm-mono">v2</span> mit
<span class="sm-mono"><?php echo cc_t('TEXT.T068'); ?></span> <?php echo cc_t('TEXT.T069'); ?></div>

<h2><?php echo cc_t('TEXT.T070'); ?></h2>
<div class="sm-small" style="margin-bottom:8px;">
<?php echo cc_t('TEXT.T071'); ?> <b><?php echo cc_t('TEXT.T072'); ?></b>
(<span class="sm-mono"><?php echo cc_t('TEXT.T073'); ?><?= cc_e($cc_ip) ?>/<?= $cc_udpin ? (int) $cc_udpin : '&lt;Port&gt;' ?></span><?php echo cc_t('TEXT.T074'); ?>
</div>
<div class="sm-small">
<?php echo cc_t('TEXT.T075'); ?> <span class="sm-mono"><?= $cc_broker !== '' ? cc_e($cc_broker) : 'MQTT-Gateway nicht gefunden' ?></span>
<?php echo cc_t('TEXT.T076'); ?> <span class="sm-mono"><?= cc_e($cc_praefix) ?></span>
</div>

<?php if (cc_cfg($cc_cfg, 'mqtt', '1') !== '1') { ?>
<div class="sm-alert sm-err"><?php echo cc_t('TEXT.T077'); ?></div>
<?php } ?>
<?php if (!$cc_udpin) { ?>
<div class="sm-alert sm-info">Der <b><?php echo cc_t('TEXT.T078'); ?></b> <?php echo cc_t('TEXT.T079'); ?> <i><?php echo cc_t('TEXT.T080'); ?></i> <?php echo cc_t('TEXT.T081'); ?></div>
<?php } ?>

<h2><?php echo cc_t('TEXT.T082'); ?></h2>
<?php if (!$cc_geraete) { ?>
<div class="sm-alert sm-err"><?php echo cc_t('TEXT.T083'); ?></div>
<?php } else { ?>
<div class="sm-small"><?php echo cc_t('TEXT.T084'); ?> <b><?= count($cc_geraete) ?></b> <?php echo cc_t('TEXT.T085'); ?>
<?= cc_e(implode(', ', $cc_geraete)) ?><?php echo cc_t('TEXT.T086'); ?> <?= count(cc_status_themen()) ?> <?php echo cc_t('TEXT.T087'); ?> <?= count(cc_befehle()) ?> <?php echo cc_t('TEXT.T088'); ?></div>
<?php } ?>

<form method="post" action="index.php">
<input data-role="none" type="hidden" name="activetab" value="tab-loxone">
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo cc_t('LEGENDE.AKTION_DATEI'); ?></span>
</div>
<h3 class="sm-h3"><?php echo cc_t('TEXT.T089'); ?></h3>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="download" value="mqtt_in"><?php echo cc_t('TEXT.T090'); ?></button>
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="download" value="mqtt_out"><?php echo cc_t('TEXT.T091'); ?></button>
</div>
<h3 class="sm-h3"><?php echo cc_t('TEXT.T092'); ?></h3>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="download" value="udp_out"><?php echo cc_t('TEXT.T093'); ?></button>
</div>
<div class="sm-small"><?php echo cc_t('TEXT.T094'); ?> <?= cc_e(cc_cfg($cc_cfg, 'udp_port', '7090')) ?> <?php echo cc_t('TEXT.T095'); ?></div>
</form>

<h2><?php echo cc_t('TEXT.T096'); ?></h2>
<table class="sm-tbl">
<tr><th style="width:26%;"><?php echo cc_t('TEXT.T097'); ?></th><th style="width:14%;">Art</th><th><?php echo cc_t('TEXT.T098'); ?></th></tr>
<?php foreach (cc_status_themen() as $k => $info) { ?>
<tr><td><span class="sm-mono"><?= cc_e($k) ?></span></td><td><?= cc_e($info[1]) ?></td><td><?= $info[0] ?></td></tr>
<?php } ?>
</table>
<div class="sm-small"><?php echo cc_t('TEXT.T099'); ?>
<span class="sm-mono"><?= cc_e($cc_praefix) ?><?php echo cc_t('TEXT.T100'); ?></span><?php echo cc_t('TEXT.T101'); ?> <span class="sm-mono"><?= cc_e($cc_praefix) ?><?php echo cc_t('TEXT.T102'); ?></span> <?php echo cc_t('TEXT.T103'); ?></div>

<h2><?php echo cc_t('TEXT.T104'); ?></h2>
<table class="sm-tbl">
<tr><th style="width:26%;"><?php echo cc_t('TEXT.T105'); ?></th><th>Bedeutung</th></tr>
<?php foreach (cc_befehle() as $b => $erklaerung) { ?>
<tr><td><span class="sm-mono"><?= cc_e($b) ?></span></td><td><?= $erklaerung ?></td></tr>
<?php } ?>
</table>
<h2><?php echo cc_t('TEXT.T106'); ?></h2>
<div class="sm-small"><?php echo cc_t('TEXT.T107'); ?> <b><?php echo cc_t('TEXT.T108'); ?></b> <?php echo cc_t('TEXT.T109'); ?> <span class="sm-mono">&lt;G&gt;</span> <?php echo cc_t('TEXT.T110'); ?></div>
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
<tr><td>9</td><td><?php echo cc_t('TEXT.T140'); ?></td><td><?php echo cc_t('TEXT.T141'); ?></td><td>&mdash;</td><td>Eingang = #7 <?php echo cc_t('TEXT.T145'); ?> <span class="sm-mono"><?php echo cc_t('TEXT.T142'); ?></span></td></tr>
<tr><td>10</td><td><?php echo cc_t('TEXT.T143'); ?></td><td><?php echo cc_t('TEXT.T144'); ?></td><td>Visualisierung EIN</td><td>&rarr; Ausgangsbefehl <span class="sm-mono">volume_up</span></td></tr>
<tr><td>11</td><td>Taster</td><td><?php echo cc_t('TEXT.T146'); ?></td><td>Visualisierung EIN</td><td>&rarr; Ausgangsbefehl <span class="sm-mono">volume_down</span></td></tr>
<tr><td>12</td><td><?php echo cc_t('TEXT.T147'); ?></td><td><?php echo cc_t('TEXT.T148'); ?></td><td>&mdash;</td><td><?php echo cc_t('TEXT.T149'); ?></td></tr>
<tr><td>13</td><td><?php echo cc_t('TEXT.T150'); ?></td><td><?php echo cc_t('TEXT.T151'); ?></td><td><?php echo cc_t('TEXT.T152'); ?></td><td><?php echo cc_t('TEXT.T153'); ?></td></tr>
<tr><td>14</td><td>Status</td><td>Musik &lt;G&gt;</td><td><?php echo cc_t('TEXT.T154'); ?></td><td>v1 = #5, v2 = #3</td></tr>
</table>
<div class="sm-alert sm-info">
<b>Zu #6:</b> <?php echo cc_t('TEXT.T155'); ?> <b><?php echo cc_t('TEXT.T156'); ?></b> <?php echo cc_t('TEXT.T157'); ?><br>
<b><?php echo cc_t('TEXT.T158'); ?></b> <?php echo cc_t('TEXT.T159'); ?><br>
<b>Zu #13:</b> <?php echo cc_t('TEXT.T160'); ?><br>
<b><?php echo cc_t('TEXT.T161'); ?></b> <?php echo cc_t('TEXT.T162'); ?> <b><?php echo cc_t('TEXT.T163'); ?></b> <?php echo cc_t('TEXT.T164'); ?>
</div>

<div class="sm-small">
<?php echo cc_t('TEXT.T165'); ?> <span class="sm-mono"><?= cc_e($cc_praefix) ?><?php echo cc_t('TEXT.T166'); ?></span><?php echo cc_t('TEXT.T167'); ?> <span class="sm-mono"><?php echo cc_t('TEXT.T168'); ?></span> <?php echo cc_t('TEXT.T169'); ?>
</div>
</div>

<!-- ================= Reiter: Test ================= -->
<div class="sm-pane<?= $cc_tab === 'tab-test' ? ' sm-active' : '' ?>" id="tab-test">

<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?php echo cc_t('LEGENDE.LESEN'); ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?php echo cc_t('LEGENDE.TECHNIK'); ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo cc_t('LEGENDE.AKTION'); ?></span>
</div>

<h3 class="sm-h3"><?php echo cc_t('TEXT.T170'); ?></h3>
<div class="sm-knopfreihe">
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="status"><?php echo cc_t('TEXT.T171'); ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="suchen"><?php echo cc_t('TEXT.T174'); ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="themen"><?php echo cc_t('TEXT.T172'); ?></button></form>
</div>

<h3 class="sm-h3"><?php echo cc_t('TEXT.T173'); ?></h3>
<div class="sm-knopfreihe">
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="konfig"><?php echo cc_t('TEXT.T174'); ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="umgebung"><?php echo cc_t('TEXT.T175'); ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="mqttinfo"><?php echo cc_t('TEXT.T176'); ?></button></form>
</div>

<h3 class="sm-h3"><?php echo cc_t('TEXT.T177'); ?></h3>
<div class="sm-knopfreihe">
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="restart"><?php echo cc_t('TEXT.T178'); ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="stop"><?php echo cc_t('TEXT.T179'); ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test">
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
<div class="sm-pane<?= $cc_tab === 'tab-log' ? ' sm-active' : '' ?>" id="tab-log">
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
            tabs[i].classList.toggle('sm-active', tabs[i].getAttribute('data-pane') === id);
        }
        var panes = document.querySelectorAll('.sm-pane');
        for (i = 0; i < panes.length; i++) {
            panes[i].classList.toggle('sm-active', panes[i].id === id);
        }
    }
    for (var i = 0; i < tabs.length; i++) {
        (function (t) {
            t.addEventListener('click', function (ev) { ev.preventDefault(); zeige(t.getAttribute('data-pane')); });
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
