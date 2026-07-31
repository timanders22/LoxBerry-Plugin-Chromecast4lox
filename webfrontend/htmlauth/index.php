<?php
/**
 * Chromecast 4 Lox - Admin-Oberflaeche (v1.0.0)
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
$cc_tab = preg_match('/^tab-(settings|loxone|test|log)$/', (string) (isset($_POST['activetab']) ? $_POST['activetab'] : ''))
    ? $_POST['activetab'] : 'tab-settings';

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
    LBWeb::lbheader('Chromecast 4 Lox', 'https://wiki.loxberry.de/plugins/chromecast_4_lox/start', 'help.html');
}
?>
<style>
.cc-wrap { max-width: 980px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.cc-wrap, .cc-wrap * { text-shadow: none !important; }
.cc-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.cc-wrap label { display: block; font-weight: 600; font-size: 0.88em; color: #555; margin: 10px 0 4px; }
.cc-wrap input[type=text], .cc-wrap input[type=number], .cc-wrap select, .cc-wrap textarea {
  width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 0.95em; box-sizing: border-box; }
.cc-wrap textarea { font-family: ui-monospace, monospace; min-height: 92px; }
.cc-wrap input[type=checkbox] { width: 17px; height: 17px; margin: 0 6px 0 0; vertical-align: middle; }
.cc-check { font-weight: 400 !important; font-size: 0.95em !important; color: #333 !important; }
.cc-row { display: flex; gap: 12px; flex-wrap: wrap; }
.cc-row > div { flex: 1; min-width: 190px; }
.cc-btn { background: #6dac20; color: #fff !important; border: 0; border-radius: 6px; padding: 10px 22px; font-size: 1em; cursor: pointer; margin-top: 18px; font-weight: 600; }
.cc-wrap .cc-btn, .cc-wrap a.cc-btn, .cc-wrap button { box-shadow: none !important; }
.cc-wrap a.cc-btn, .cc-wrap a.cc-btn:visited, .cc-wrap a.cc-btn:hover { color: #fff !important; text-decoration: none; }
.cc-alert { border-radius: 8px; padding: 10px 14px; margin: 12px 0; }
.cc-ok { background: #e8f5e9; border: 1px solid #a5d6a7; }
.cc-err { background: #ffebee; border: 1px solid #ef9a9a; }
.cc-info { background: #e3f2fd; border: 1px solid #90caf9; font-size: 0.9em; }
.cc-mono { font-family: ui-monospace, monospace; background: #f5f5f5; padding: 2px 6px; border-radius: 4px; }
.cc-small { font-size: 0.82em; color: #666; margin-top: 3px; }
.cc-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.cc-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0; padding: 9px 18px; cursor: pointer; font-size: 0.95em; color: #444 !important; }
.cc-tab.cc-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.cc-pane { display: none; padding-top: 4px; }
.cc-pane.cc-active { display: block; }
.cc-log { background: #1e1e1e; color: #d4d4d4; font-family: ui-monospace, monospace; font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto; white-space: pre-wrap; }
.cc-step { margin: 10px 0; padding: 10px 14px; background: #fafafa; border-left: 4px solid #6dac20; border-radius: 0 8px 8px 0; }
.cc-tbl { border-collapse: collapse; margin: 8px 0; width: 100%; }
.cc-tbl th, .cc-tbl td { border: 1px solid #ddd; padding: 6px 10px; text-align: left; font-size: 0.9em; vertical-align: top; }
.cc-tbl th { background: #f0f0f0; }

/* --- Einheitliches Kachel-Raster im Reiter Test (Hausstandard) --- */
.cc-h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.cc-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.cc-knopfreihe form { margin: 0; display: flex; }
.cc-knopfreihe .cc-btn { flex: 0 0 auto; min-width: 250px; text-align: center;
    display: inline-flex; align-items: center; justify-content: center; line-height: 1.25; margin-top: 0; }
.cc-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.cc-legende span { display: inline-flex; align-items: center; gap: 6px; }
.cc-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.cc-btn.cc-b-lesen   { background: #6dac20; }
.cc-btn.cc-b-technik { background: #546e7a; }
.cc-btn.cc-b-aktion  { background: #e0620d; }
.cc-punkt.cc-b-lesen   { background: #6dac20; }
.cc-punkt.cc-b-technik { background: #546e7a; }
.cc-punkt.cc-b-aktion  { background: #e0620d; }
</style>
<div class="cc-wrap">

<?php if ($cc_saved) { ?>
<div class="cc-alert cc-ok"><b>Gespeichert.</b> <?= $cc_hinweis ?></div>
<?php } ?>
<?php if ($cc_error !== '') { ?><div class="cc-alert cc-err"><b>Fehler:</b> <?= $cc_error ?></div><?php } ?>

<div class="cc-alert cc-info">
Dienst: <b><?= $cc_pid ? 'l&auml;uft' : 'l&auml;uft nicht' ?></b><?= $cc_pid ? ' (PID ' . $cc_pid . ') ' : ' ' ?>
&middot; Ger&auml;te: <b><?= count($cc_geraete) ?></b>
&middot; MQTT: <b><?= cc_cfg($cc_cfg, 'mqtt', '1') === '1' ? 'ein' : 'aus' ?></b>
&middot; UDP-Befehle: <b><?= cc_cfg($cc_cfg, 'udp', '1') === '1' ? 'Port ' . cc_e(cc_cfg($cc_cfg, 'udp_port', '7090')) : 'aus' ?></b>
&middot; LoxBerry: <span class="cc-mono"><?= cc_e($cc_ip) ?></span>
</div>

<div class="cc-tabs">
    <div class="cc-tab" data-pane="tab-settings">Einstellungen</div>
    <div class="cc-tab" data-pane="tab-loxone">Einbindung in Loxone</div>
    <div class="cc-tab" data-pane="tab-test">Test</div>
    <div class="cc-tab" data-pane="tab-log">Logdateien</div>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="cc-pane" id="tab-settings">
<form method="post" action="index.php">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2>Ger&auml;te</h2>
<label>Chromecasts &mdash; einer je Zeile</label>
<textarea data-role="none" name="geraete" placeholder="Wohnzimmer&#10;K&uuml;che Lautsprecher"><?= cc_e(implode("\n", $cc_geraete)) ?></textarea>
<div class="cc-small">
Der Name muss <b>genau</b> so lauten wie in der Google-Home-App. Im Reiter Test
findet <i>Chromecasts im Netz suchen</i> die richtigen Schreibweisen.
Aus dem Namen wird das MQTT-Thema gebildet &mdash; Umlaute und Leerzeichen werden dabei ersetzt.
</div>

<?php if ($cc_geraete) { ?>
<table class="cc-tbl">
<tr><th style="width:45%;">Name</th><th>MQTT-Thema</th></tr>
<?php foreach ($cc_geraete as $g) { ?>
<tr><td><?= cc_e($g) ?></td><td><span class="cc-mono"><?= cc_e($cc_praefix . '/' . cc_thema($g)) ?></span></td></tr>
<?php } ?>
</table>
<?php } ?>

<h2>Weg zum Miniserver</h2>
<label class="cc-check"><input data-role="none" type="checkbox" name="mqtt" value="1"<?= cc_cfg($cc_cfg, 'mqtt', '1') === '1' ? ' checked' : '' ?>> <b>MQTT</b> &mdash; empfohlen</label>
<div class="cc-small">Zust&auml;nde gehen retained an den Broker, Befehle kommen von dort zur&uuml;ck. Nach einem Neustart des Miniservers steht der letzte Stand sofort wieder da.</div>

<label class="cc-check" style="margin-top:10px;"><input data-role="none" type="checkbox" name="udp" value="1"<?= cc_cfg($cc_cfg, 'udp', '1') === '1' ? ' checked' : '' ?>> UDP-Befehle annehmen</label>
<div class="cc-small">Der Weg der Originalfassung. Bleibt erhalten, damit bestehende Loxone-Konfigurationen weiterlaufen &mdash; und weil sich damit im Reiter Test bequem ein Befehl absetzen l&auml;sst.</div>

<div class="cc-row" style="margin-top:12px;">
<div>
<label>UDP-Port</label>
<input data-role="none" type="number" name="udp_port" min="1" max="65535" value="<?= cc_e(cc_cfg($cc_cfg, 'udp_port', '7090')) ?>">
</div>
<div>
<label>MQTT-Themenpr&auml;fix</label>
<input data-role="none" type="text" name="themenpraefix" value="<?= cc_e($cc_praefix) ?>">
<div class="cc-small">Nur &auml;ndern, wenn es kollidiert. Danach die Loxone-Vorlagen neu erzeugen.</div>
</div>
</div>

<h2>Abfrage</h2>
<div class="cc-row">
<div>
<label>Abfrageintervall in Sekunden</label>
<input data-role="none" type="number" name="intervall" min="2" max="3600" value="<?= cc_e(cc_cfg($cc_cfg, 'intervall', '10')) ?>">
<div class="cc-small">Wie oft der Zustand gelesen wird. Ge&auml;nderte Werte gehen sofort raus.</div>
</div>
<div>
<label>Vollst&auml;ndige Meldung alle &hellip; Sekunden</label>
<input data-role="none" type="number" name="aktualisierung" min="5" max="86400" value="<?= cc_e(cc_cfg($cc_cfg, 'aktualisierung', '60')) ?>">
<div class="cc-small">Zwischendurch werden nur ge&auml;nderte Werte gesendet. In diesem Abstand geht alles einmal komplett raus.</div>
</div>
<div>
<label>Schrittweite Lautst&auml;rke</label>
<input data-role="none" type="number" name="lautstaerke_schritt" min="1" max="50" value="<?= cc_e(cc_cfg($cc_cfg, 'lautstaerke_schritt', '5')) ?>">
<div class="cc-small">Gilt f&uuml;r <span class="cc-mono">volume_up</span> und <span class="cc-mono">volume_down</span>.</div>
</div>
</div>

<button data-role="none" class="cc-btn" type="submit" name="save" value="1">Speichern</button>
<div class="cc-small">Beim Speichern wird der Dienst neu gestartet.</div>
</form>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="cc-pane" id="tab-loxone">

<h2>Einbindung in Loxone &mdash; Schritt f&uuml;r Schritt</h2>
<div class="cc-small">Zwei Richtungen: das Plugin meldet den Zustand jedes Lautsprechers per MQTT an
den Miniserver (Schritt&nbsp;1 bis&nbsp;4), und der Miniserver schickt umgekehrt Befehle &uuml;ber
einen virtuellen Ausgang zur&uuml;ck (Schritt&nbsp;5).</div>

<div class="cc-step"><b>Schritt 1: Ger&auml;te eintragen</b><br><br>
Im Reiter <i>Einstellungen</i>. Die genauen Namen liefert im Reiter <i>Test</i> die Schaltfl&auml;che
<i>Chromecasts im Netz suchen</i>. <b>Der Name muss zeichengenau stimmen</b> &mdash; das Plugin
findet das Ger&auml;t nur &uuml;ber seinen Anzeigenamen aus der Google-Home-App.</div>

<div class="cc-step"><b>Schritt 2: Abo im MQTT-Gateway eintragen</b><br><br>
<b>Ohne diesen Eintrag kommt am Miniserver nichts an</b> &mdash; einzutragen unter
<i>System-Einstellungen &rarr; MQTT Gateway &rarr; Abonnements</i>:
<div class="cc-mono" style="background:#f4f4f4;border:1px solid #ccc;padding:8px;margin-top:6px;"><?= cc_e($cc_praefix) ?>/#</div></div>

<div class="cc-step"><b>Schritt 3: Vorlagen herunterladen</b><br><br>
Unten stehen zwei Dateien bereit &mdash; einmal die Eing&auml;nge, einmal die Ausg&auml;nge.</div>

<div class="cc-step"><b>Schritt 4: In Loxone Config einlesen</b><br><br>
Rechtsklick auf den Miniserver &rarr; <i>Vorlage einf&uuml;gen</i> &rarr; heruntergeladene Datei
w&auml;hlen. Das f&uuml;r beide Dateien. Die Eingangsvorlage legt nur die Namen an; die Werte liefert
das Gateway. Unter <i>Incoming overview</i> erscheinen die Themen, sobald der Dienst l&auml;uft.
<b>Von Hand angelegt</b> hei&szlig;t ein Eingang
<span class="cc-mono"><?= cc_e($cc_praefix) ?>_&lt;Ger&auml;t&gt;_playing</span> &mdash; das Gateway
ersetzt jeden Schr&auml;gstrich durch einen Unterstrich.</div>

<div class="cc-step"><b>Schritt 5: Kachel und Bedienung in der App</b><br><br>
Einen <i>Status</i>-Baustein anlegen, <span class="cc-mono">v1</span> mit
<span class="cc-mono">title</span> und <span class="cc-mono">v2</span> mit
<span class="cc-mono">volume</span> verbinden. Zum Bedienen gen&uuml;gen zwei Taster f&uuml;r lauter
und leiser sowie einer f&uuml;r Pause &mdash; sie h&auml;ngen an den virtuellen Ausgangsbefehlen aus
der Ausgangsvorlage. Aufbau in Schritt&nbsp;6.</div>

<h2>Wie die Befehle laufen</h2>
<div class="cc-small" style="margin-bottom:8px;">
Der Miniserver schickt einen virtuellen Ausgang an den <b>UDP-Eingang des MQTT-Gateways</b>
(<span class="cc-mono">/dev/udp/<?= cc_e($cc_ip) ?>/<?= $cc_udpin ? (int) $cc_udpin : '&lt;Port&gt;' ?></span>).
Das Gateway macht daraus eine MQTT-Nachricht, und dieses Plugin h&ouml;rt darauf.
Das ist der im LoxBerry-Wiki vorgesehene Weg und braucht keinen zus&auml;tzlichen Port.
</div>
<div class="cc-small">
Broker: <span class="cc-mono"><?= $cc_broker !== '' ? cc_e($cc_broker) : 'MQTT-Gateway nicht gefunden' ?></span>
&middot; Themenpr&auml;fix: <span class="cc-mono"><?= cc_e($cc_praefix) ?></span>
</div>

<?php if (cc_cfg($cc_cfg, 'mqtt', '1') !== '1') { ?>
<div class="cc-alert cc-err">MQTT ist im Reiter Einstellungen ausgeschaltet &mdash; die MQTT-Vorlagen liefern dann nichts.</div>
<?php } ?>
<?php if (!$cc_udpin) { ?>
<div class="cc-alert cc-info">Der <b>UDP-Eingangsport</b> des MQTT-Gateways ist nicht auffindbar.
Die Ausgangsvorlage tr&auml;gt dann Port 0 ein. Im Gateway unter <i>UDP In</i> einen Port setzen und die Vorlage neu erzeugen.</div>
<?php } ?>

<h2>Vorlagen</h2>
<?php if (!$cc_geraete) { ?>
<div class="cc-alert cc-err">Es ist kein Ger&auml;t eingetragen &mdash; die Vorlagen w&auml;ren leer.</div>
<?php } else { ?>
<div class="cc-small">F&uuml;r <b><?= count($cc_geraete) ?></b> Ger&auml;t(e):
<?= cc_e(implode(', ', $cc_geraete)) ?>.
Je Ger&auml;t <?= count(cc_status_themen()) ?> Eing&auml;nge und <?= count(cc_befehle()) ?> Ausg&auml;nge.</div>
<?php } ?>

<form method="post" action="index.php">
<input data-role="none" type="hidden" name="activetab" value="tab-loxone">
<div class="cc-legende">
<span><i class="cc-punkt cc-b-aktion"></i> L&ouml;st etwas aus &mdash; erzeugt eine Datei</span>
</div>
<h3 class="cc-h3">MQTT (empfohlen)</h3>
<div class="cc-knopfreihe">
<button data-role="none" class="cc-btn cc-b-aktion" type="submit" name="download" value="mqtt_in">Vorlage: Eing&auml;nge</button>
<button data-role="none" class="cc-btn cc-b-aktion" type="submit" name="download" value="mqtt_out">Vorlage: Ausg&auml;nge</button>
</div>
<h3 class="cc-h3">UDP (Weg der Originalfassung)</h3>
<div class="cc-knopfreihe">
<button data-role="none" class="cc-btn cc-b-aktion" type="submit" name="download" value="udp_out">Vorlage: Ausg&auml;nge &uuml;ber UDP</button>
</div>
<div class="cc-small">Schickt die Befehle direkt an Port <?= cc_e(cc_cfg($cc_cfg, 'udp_port', '7090')) ?> dieses Plugins, ohne Umweg &uuml;ber das Gateway. Nur n&ouml;tig, wenn kein MQTT-Gateway da ist.</div>
</form>

<h2>Zust&auml;nde je Ger&auml;t</h2>
<table class="cc-tbl">
<tr><th style="width:26%;">Thema</th><th style="width:14%;">Art</th><th>Bedeutung</th></tr>
<?php foreach (cc_status_themen() as $k => $info) { ?>
<tr><td><span class="cc-mono"><?= cc_e($k) ?></span></td><td><?= cc_e($info[1]) ?></td><td><?= $info[0] ?></td></tr>
<?php } ?>
</table>
<div class="cc-small">Vollst&auml;ndig lautet ein Thema
<span class="cc-mono"><?= cc_e($cc_praefix) ?>/&lt;Ger&auml;t&gt;/&lt;Zustand&gt;</span>,
dazu <span class="cc-mono"><?= cc_e($cc_praefix) ?>/server/online</span> f&uuml;r den Dienst selbst.</div>

<h2>Befehle je Ger&auml;t</h2>
<table class="cc-tbl">
<tr><th style="width:26%;">Befehl</th><th>Bedeutung</th></tr>
<?php foreach (cc_befehle() as $b => $erklaerung) { ?>
<tr><td><span class="cc-mono"><?= cc_e($b) ?></span></td><td><?= $erklaerung ?></td></tr>
<?php } ?>
</table>
<h2>Schritt 6: Komplette Baustein-Liste zum 1:1-Nachbauen</h2>
<div class="cc-small">So sieht die vollst&auml;ndige Logik auf der Programmierseite aus (jede Zeile =
ein Baustein), am Beispiel <b>eines</b> Lautsprechers. <span class="cc-mono">&lt;G&gt;</span> steht
f&uuml;r seinen Namen. Alle Bausteine findet man in Loxone Config &uuml;ber die Baustein-Suche (F5):</div>
<table class="cc-tbl">
<tr><th>#</th><th>Baustein (Typ)</th><th>Name (Vorschlag)</th><th>Parameter</th><th>Eing&auml;nge verbinden mit</th></tr>
<tr><td>1</td><td>Virtueller Eingang</td><td class="cc-mono"><?= cc_e($cc_praefix) ?>_&lt;G&gt;_online</td><td>digital</td><td>&mdash; (kommt &uuml;ber das Gateway)</td></tr>
<tr><td>2</td><td>Virtueller Eingang</td><td class="cc-mono"><?= cc_e($cc_praefix) ?>_&lt;G&gt;_playing</td><td>digital</td><td>&mdash;</td></tr>
<tr><td>3</td><td>Virtueller Eingang</td><td class="cc-mono"><?= cc_e($cc_praefix) ?>_&lt;G&gt;_volume</td><td>analog, 0 bis 100</td><td>&mdash;</td></tr>
<tr><td>4</td><td>Virtueller Eingang</td><td class="cc-mono"><?= cc_e($cc_praefix) ?>_&lt;G&gt;_muted</td><td>digital</td><td>&mdash;</td></tr>
<tr><td>5</td><td>Virtueller Eingang (Text)</td><td class="cc-mono"><?= cc_e($cc_praefix) ?>_&lt;G&gt;_title</td><td>Text</td><td>&mdash;</td></tr>
<tr><td>6</td><td>Virtueller Ausgang + Befehle</td><td>Chromecast</td><td>Adresse <span class="cc-mono">/dev/udp/<?= cc_e($cc_ip) ?>/<?= $cc_udpin ? (int) $cc_udpin : '&lt;Port&gt;' ?></span>, Befehle aus der Ausgangsvorlage</td><td>&mdash; (wird von #8 bis #11 angesto&szlig;en)</td></tr>
<tr><td>7</td><td>Merker (remanent, Visu)</td><td>Musik &lt;G&gt;</td><td>Visualisierung EIN</td><td>&mdash; (Bedienung in der App)</td></tr>
<tr><td>8</td><td>Flankenerkennung (steigend)</td><td>Musik an</td><td>&mdash;</td><td>Eingang = #7 &rarr; Ausgangsbefehl <span class="cc-mono">play</span></td></tr>
<tr><td>9</td><td>Flankenerkennung (fallend)</td><td>Musik aus</td><td>&mdash;</td><td>Eingang = #7 &rarr; Ausgangsbefehl <span class="cc-mono">stop</span></td></tr>
<tr><td>10</td><td>Taster</td><td>Lauter</td><td>Visualisierung EIN</td><td>&rarr; Ausgangsbefehl <span class="cc-mono">volume_up</span></td></tr>
<tr><td>11</td><td>Taster</td><td>Leiser</td><td>Visualisierung EIN</td><td>&rarr; Ausgangsbefehl <span class="cc-mono">volume_down</span></td></tr>
<tr><td>12</td><td>NICHT</td><td>Lautsprecher nicht erreichbar</td><td>&mdash;</td><td>Eingang = #1</td></tr>
<tr><td>13</td><td>Einschaltverz&ouml;gerung</td><td>Ausfall best&auml;tigt</td><td>1800&nbsp;s</td><td>Eingang = #12 &rarr; Benachrichtigung</td></tr>
<tr><td>14</td><td>Status</td><td>Musik &lt;G&gt;</td><td>Statustext siehe Schritt&nbsp;5, Visualisierung EIN</td><td>v1 = #5, v2 = #3</td></tr>
</table>
<div class="cc-alert cc-info">
<b>Zu #6:</b> der virtuelle Ausgang geht <b>nicht</b> an den LoxBerry, sondern an den UDP-Eingang des
MQTT-Gateways. Das Gateway macht daraus eine MQTT-Nachricht, auf die dieses Plugin h&ouml;rt. Das ist
der im LoxBerry-Wiki vorgesehene Weg und braucht keinen zus&auml;tzlichen Port.<br>
<b>Zu #8 und #9:</b> ein virtueller Ausgangsbefehl feuert bei der Flanke 0&rarr;1, nicht dauerhaft.
Ein Merker allein w&uuml;rde beim Ausschalten nichts ausl&ouml;sen &mdash; deshalb die zwei
Flankenbausteine.<br>
<b>Zu #13:</b> Chromecasts gehen von selbst in den Ruhezustand und melden sich dann als nicht
erreichbar. Eine halbe Stunde Verz&ouml;gerung verhindert Meldungen &uuml;ber einen Normalzustand.<br>
<b>Zu #1 bis #5:</b> die Namen enthalten den Ger&auml;tenamen. <b>Wird das Ger&auml;t in der
Google-Home-App umbenannt, &auml;ndern sich alle Themen</b> &mdash; dann hier den Namen nachziehen
und die Vorlagen neu erzeugen.
</div>

<div class="cc-small">
Als MQTT-Thema: <span class="cc-mono"><?= cc_e($cc_praefix) ?>/&lt;Ger&auml;t&gt;/cmd/&lt;Befehl&gt;</span>.
&Uuml;ber den UDP-Weg als Text: <span class="cc-mono">&lt;Ger&auml;t&gt;/&lt;BEFEHL&gt; &lt;Wert&gt;;</span> &mdash;
ohne Ger&auml;tenamen gilt der Befehl f&uuml;r das erste Ger&auml;t in der Liste.
</div>
</div>

<!-- ================= Reiter: Test ================= -->
<div class="cc-pane" id="tab-test">

<div class="cc-legende">
<span><i class="cc-punkt cc-b-lesen"></i> Ansehen &mdash; fragt nur ab, ver&auml;ndert nichts</span>
<span><i class="cc-punkt cc-b-technik"></i> Technische Auskunft &mdash; f&uuml;r die Fehlersuche</span>
<span><i class="cc-punkt cc-b-aktion"></i> L&ouml;st etwas aus &mdash; sendet oder ver&auml;ndert</span>
</div>

<h3 class="cc-h3">Ansehen</h3>
<div class="cc-knopfreihe">
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="cc-btn cc-b-lesen" type="submit" name="test" value="status">Zustand des Dienstes</button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="cc-btn cc-b-lesen" type="submit" name="test" value="suchen">Chromecasts im Netz suchen</button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="cc-btn cc-b-lesen" type="submit" name="test" value="themen">MQTT-Themen anzeigen</button></form>
</div>

<h3 class="cc-h3">Technische Auskunft</h3>
<div class="cc-knopfreihe">
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="cc-btn cc-b-technik" type="submit" name="test" value="konfig">Konfiguration anzeigen</button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="cc-btn cc-b-technik" type="submit" name="test" value="umgebung">Umgebung und Python-Module</button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="cc-btn cc-b-technik" type="submit" name="test" value="mqttinfo">MQTT-Gateway</button></form>
</div>

<h3 class="cc-h3">L&ouml;st etwas aus</h3>
<div class="cc-knopfreihe">
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="cc-btn cc-b-aktion" type="submit" name="test" value="restart">Dienst neu starten</button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="cc-btn cc-b-aktion" type="submit" name="test" value="stop">Dienst anhalten</button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test">
<?php if (count($cc_geraete) > 1) { ?><select data-role="none" name="testgeraet" style="width:auto;margin-right:8px;">
<?php foreach ($cc_geraete as $g) { ?><option value="<?= cc_e($g) ?>"><?= cc_e($g) ?></option><?php } ?>
</select><?php } ?>
<button data-role="none" class="cc-btn cc-b-aktion" type="submit" name="test" value="ping">Ger&auml;t ansprechen</button></form>
</div>

<?php if ($cc_test_titel !== '') { ?>
<h2><?= cc_e($cc_test_titel) ?></h2>
<div class="cc-log"><?= cc_e($cc_test_text) ?></div>
<?php } else { ?>
<div class="cc-alert cc-info" style="margin-top:18px;">Noch nichts abgefragt. Die Ausgabe erscheint hier.</div>
<?php } ?>
</div>

<!-- ================= Reiter: Logdateien ================= -->
<div class="cc-pane" id="tab-log">
<h2>Protokoll</h2>
<div class="cc-small">
<?php if ($cc_log !== '') { ?>
Datei: <span class="cc-mono"><?= cc_e($cc_log) ?></span> &middot; neueste Zeile zuerst
<?php } else { ?>
Noch keine Protokolldatei vorhanden. Sie entsteht, sobald der Dienst das erste Mal l&auml;uft.
<?php } ?>
</div>
<?php if ($cc_zeilen) { ?>
<div class="cc-log"><?php foreach ($cc_zeilen as $z) { echo cc_e($z) . "\n"; } ?></div>
<?php } ?>
</div>

</div>
<script>
(function () {
    var tabs = document.querySelectorAll('.cc-tab');
    var start = <?= json_encode($cc_tab) ?>;
    function zeige(id) {
        var i;
        for (i = 0; i < tabs.length; i++) {
            tabs[i].classList.toggle('cc-active', tabs[i].getAttribute('data-pane') === id);
        }
        var panes = document.querySelectorAll('.cc-pane');
        for (i = 0; i < panes.length; i++) {
            panes[i].classList.toggle('cc-active', panes[i].id === id);
        }
    }
    for (var i = 0; i < tabs.length; i++) {
        (function (t) {
            t.addEventListener('click', function () { zeige(t.getAttribute('data-pane')); });
        })(tabs[i]);
    }
    zeige(start);
})();
</script>
<?php
if ($cc_frame) {
    LBWeb::lbfooter();
}
