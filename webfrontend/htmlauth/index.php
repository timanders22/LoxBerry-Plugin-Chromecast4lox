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
 *
 * Gruppe 6b / MQTT (1.3.24): Reiter MQTT mit den drei Haken der Befehle und EINER Themenliste, Reiter Einbindung in
 * Loxone mit Namenstabelle, Befehlen der Befehlsbruecke und Vorlage MQTT-Befehle; Fremdbefunde C11, C16, C17.
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

/* ============ Waehrend einer Aktualisierung nichts tun ============
 *
 * VOR allem anderen - insbesondere vor cc_formtoken(). Das ruft
 * cc_aktionstoken(), und das SCHREIBT die Konfigurationsdatei, wenn noch kein
 * Token darin steht. In der Upgrade-Luecke ist genau das der Fall: die Datei
 * ist die mitgelieferte Vorgabe. Ein blosser Aufruf dieser Seite hat sie also
 * angefasst, ohne dass jemand einen Knopf gedrueckt haette (in WSL gemessen
 * 17.09.2026, Fall q2: die Pruefsumme der Datei aenderte sich nach zwei
 * Seitenaufrufen).
 *
 * Was dem Anwender in dieser Zeit angezeigt wuerde, sind die Vorgabewerte -
 * kein Geraet, Themenpraefix chromecast4lox. Speichert er, nimmt die Seite
 * es an, meldet Erfolg, startet den Dienst damit, und postupgrade.sh
 * ueberschreibt es Sekunden spaeter mit der gesicherten Konfiguration
 * (Fall q2b). Deshalb: ein Hinweis, sonst nichts. Wie Intercom 2.2.11.
 */
if (cc_upgrade_laeuft()) {
    $cc_rahmen = class_exists('LBWeb', false);
    if ($cc_rahmen) {
        LBWeb::lbheader('Chromecast 4 Lox NG',
            'https://wiki.loxberry.de/plugins/chromecast_4_lox/start', 'help.html');
    }
    echo '<div style="max-width:980px;margin:0 auto;'
       . 'font-family:-apple-system,Segoe UI,Roboto,sans-serif;color:#333">' . "\n"
       . '<h2 style="color:#6dac20">Chromecast 4 Lox NG</h2>' . "\n"
       . '<div style="border-radius:8px;padding:10px 14px;margin:12px 0;'
       . 'background:#fdf3e3;border:1px solid #e0620d"><b>'
       . cc_e(cc_t('UPGRADE.T_TITEL')) . '</b> ' . cc_e(cc_t('UPGRADE.T_TEXT'))
       . '</div>' . "\n" . '</div>' . "\n";
    if ($cc_rahmen) {
        LBWeb::lbfooter();
    }
    exit;
}

$cc_saved = false;
/* Beanstandungen als LISTE (seit 1.3.13, O6). Bis 1.3.12 war $cc_error ein
 * einzelner Wert: ruhe_von und ruhe_bis ueberschrieben einander, und eine
 * Beanstandung verdeckte die andere (gemessen 30.09.2026, Oberflaechen-Befund
 * 6). Jeder Eintrag ist fertiges HTML; eingesetzte Namen sind maskiert. */
$cc_fehler = array();
$cc_hinweis = '';
$cc_gefunden = array();
$cc_suchfehler = '';
$cc_gesucht = false;
$cc_test_titel = '';
$cc_test_text = '';
/* X-2 (Verbesserungsbau 30.09.2026): welches Formular, welche Felder
 * beanstandet wurden - daraus reisen die Eingaben mit der Einmalmeldung. */
$cc_eingaben_form = '';
$cc_beanstandet = array();
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

/* Die Einmalmeldung der vorigen Anfrage - NUR beim GET (seit 1.3.13, O4;
 * Regeln/04 "Die Einmalmeldung wird NUR beim GET gelesen"). */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $cc_einmal = cc_einmal_lesen();
    if ($cc_einmal !== null) {
        $cc_saved = $cc_einmal['saved'];
        $cc_hinweis = $cc_einmal['hinweis'];
        $cc_fehler = $cc_einmal['fehler'];
        $cc_test_titel = $cc_einmal['test_titel'];
        $cc_test_text = $cc_einmal['test_text'];
        $cc_gefunden = $cc_einmal['gefunden'];
        $cc_suchfehler = $cc_einmal['suchfehler'];
        $cc_gesucht = $cc_einmal['gesucht'];
        // X-2: nach einer Beanstandung die eingetippten Werte zeigen.
        if (is_array($cc_einmal['eingaben'])) {
            cc_eingaben_setzen($cc_einmal['eingaben']);
        }
    }
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
        $cc_fehler[] = cc_t($cc_fmt === '' ? 'FEHLER.KEIN_MERKMAL' : 'FEHLER.MERKMAL');
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

/* ==================================================================
 * DIE HANDLER STEHEN VOR lbheader() - DAS IST BAUVORSCHRIFT
 * ==================================================================
 *
 * Stand der Kopf davor, war er beim Aufruf von header() schon
 * geschrieben - "Cannot modify header information", und der Knopf
 * "Einstellungen sichern" lieferte eine Seite mit angehaengtem JSON
 * statt einer Datei.
 *
 * Am PHP-CLI ist das unsichtbar: header() ist dort wirkungslos und
 * headers_sent() immer falsch. Und wer OHNE gueltiges Formularmerkmal
 * misst, wird vom Wachposten abgewiesen, bevor der Handler anlaeuft.
 * Beides hat den Fehler lange verdeckt.
 *
 * Reihenfolge: Bibliothek, Konfiguration, Wachposten, Reiterwahl,
 * ALLE Handler samt Downloads, dann erst lbheader(), dann HTML.
 * ================================================================== */
/* ============ Loxone-Vorlage herunterladen ============ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['download'])) {
    $cfg = cc_config_read();
    $geraete = cc_geraete($cfg);
    if (!$geraete) {
        $cc_fehler[] = cc_t('TEXT.T083');
        $cc_tab = 'tab-loxone';
    } else {
        list($name, $inhalt) = cc_vorlage((string) $_POST['download'], $cfg, $geraete);
        if ($name === '') {
            $cc_fehler[] = cc_t('TEXT.F_VORLAGENART');
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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['suche_geraete'])) {
    $cc_c = cc_config_read();
    list($cc_gefunden, $cc_suchfehler) =
        cc_suche(cc_cfg($cc_c, 'gruppen', '1') !== '1');
    $cc_gesucht = true;
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
            // Die Namen MASKIERT (seit 1.3.13, O7): ein Anzeigename aus dem
            // Netz oder aus einem Formular lief bis 1.3.12 roh als HTML in
            // die angemeldete Seite (gemessen 30.09.2026, Oberflaechen-Befund
            // 7: "<img src=x onerror=...>" stand wirksam in der Meldung).
            $cc_hinweis = sprintf(cc_t('SUCHE.H_UEBERNOMMEN'),
                                  count($cc_neu_dazu),
                                  cc_e(implode(', ', $cc_neu_dazu)));
            require_once __DIR__ . '/cc_test.php';
            // Und sagen, was mit dem Dienst geschah (O8).
            $cc_hinweis .= ' ' . cc_dienst_nachziehen($cc_c);
            // Gruppe 6b (1.3.24, E14): Geraetenamen, die Themen stoeren - warnen, nicht abweisen.
            foreach (cc_geraete_warnungen(cc_geraete($cc_c)) as $cc_gw_t) {
                $cc_hinweis .= '<br><b>' . cc_t('BEFEHL.W_ACHTUNG') . '</b> ' . $cc_gw_t;
            }
        } else {
            $cc_fehler[] = cc_t('TEXT.F_SCHREIBEN') . ' ' . cc_e($cc_p['config']);
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
    $cc_pr_alt = (string) cc_cfg($cc_m, 'mqtt_topic', 'chromecast4lox');
    $cc_m['mqtt_ein'] = isset($_POST['mqtt_ein']) ? '1' : '0';
    // Gruppe 6b (1.3.24, Entwurf cc 3.4): die drei Haken der Befehle - Haken sind 0 oder 1, nichts zu beanstanden.
    foreach (cc_befehle_haken() as $cc_hk) {
        $cc_m[$cc_hk] = isset($_POST[$cc_hk]) ? '1' : '0';
    }
    /* Abweisen statt umschreiben (seit 1.3.13, O5). Bis 1.3.12 machte
     * cc_thema() aus "haus/cast" still "haus_cast", aus "Küche/Wohnzimmer"
     * "Kueche_Wohnzimmer" und aus einem leeren Feld "geraet" - der Rueckfall
     * auf chromecast4lox griff nie (gemessen 30.09.2026, Oberflaechen-Befund
     * 5e). Leer heisst weiter: Rueckfall chromecast4lox. */
    $cc_pr = trim(is_string($_POST['mqtt_topic'] ?? null) ? $_POST['mqtt_topic'] : '');
    if ($cc_pr === '') {
        $cc_m['mqtt_topic'] = 'chromecast4lox';
    } else {
        list($cc_pr_gut, $cc_pr_fehler) = cc_wert_pruefen('mqtt_topic', $cc_pr, $cc_m);
        if ($cc_pr_fehler === '') {
            $cc_m['mqtt_topic'] = $cc_pr_gut;
        } else {
            // Entscheidung 16 (30.09.2026): bei einer Beanstandung wird NICHTS
            // gespeichert, auch nicht der Haken. Bis 1.3.14 wurde mqtt_ein
            // trotzdem geschrieben und der Dienst neu gestartet.
            $cc_fehler[] = cc_t('TEXT.NICHTS_GESPEICHERT');
            $cc_fehler[] = $cc_pr_fehler;
            $cc_eingaben_form = 'mqtt';
            $cc_beanstandet[] = 'mqtt_topic';
        }
    }
    if ($cc_eingaben_form !== 'mqtt') {
        if (cc_config_write($cc_m)) {
            $cc_saved = true;
            require_once __DIR__ . '/cc_test.php';
            $cc_hinweis = cc_dienst_nachziehen($cc_m);
            // Gruppe 6b (1.3.24): die Abo-Datei des Gateways V1 nachfuehren (E12) und die Befehlsbruecke gleich
            // starten bzw. anhalten (Entwurf cc 3.5) - ein neues Praefix heisst neues Abo.
            cc_abo_datei($cc_m['mqtt_topic'], true);
            $cc_bs = cc_bruecke_satz(cc_bruecke_schalten($cc_m['mqtt_topic'] !== $cc_pr_alt));
            if ($cc_bs !== '') {
                $cc_hinweis .= ' ' . $cc_bs;
            }
            if (strlen($cc_m['mqtt_topic']) > 40) {
                $cc_hinweis .= ' ' . sprintf(cc_t('BEFEHL.W_PRAEFIX_LANG'), cc_e($cc_m['mqtt_topic']));
            }
        } else {
            $cc_fehler[] = cc_t('TEXT.F_SCHREIBEN') . ' ' . cc_e($cc_p['config']);
        }
    }
    $cc_tab = 'tab-mqtt';
}

/* ============ Speichern ============ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    $neu = cc_config_read();
    $cc_bisher = $neu;

    // Eingaben nie hart filtern - nur Steuerzeichen und Anfuehrungszeichen raus.
    $saeubern = function ($s) {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F"\']+/u', '', (string) $s);
        return trim((string) $s);
    };
    /* Zahlen und Auswahlwerte: ABWEISEN und melden, nie einsetzen (seit
     * 1.3.13, O5). Bis 1.3.12 wurde ein geleertes Pflichtfeld still zu 0
     * ((int) '' liegt im Bereich 0 bis 100: Lautstaerkegrenze 0), "100.4" zu
     * 100, "abc" im Feld Ansagelautstaerke zu 0 - jedes Mal mit "Gespeichert."
     * (gemessen 30.09.2026 unter 7.4 und 8.5, Oberflaechen-Befund 5). Ein
     * abgewiesenes Feld behaelt seinen bisherigen Wert; die uebrigen werden
     * gespeichert (Regeln/05 "Beanstandungen melden, nicht das ganze Speichern
     * verhindern"). Dieselbe Pruefung nimmt das Zurueckspielen. */
    // X-2: jede Beanstandung merkt sich ihr Feld ($cc_beanstandet).
    $cc_f0 = count($cc_fehler);
    $pruefen = function ($k, $roh) use (&$neu, &$cc_fehler, $cc_bisher, &$cc_beanstandet) {
        $roh = is_string($roh) ? trim($roh) : $roh;
        if ($roh === '' && array_key_exists($k, cc_zahlfelder())) {
            $cc_fehler[] = sprintf(cc_t('PRUEF.LEER'), cc_e($k));
            $cc_beanstandet[] = $k;
            return;
        }
        list($gut, $fehler) = cc_wert_pruefen($k, $roh, $cc_bisher);
        if ($fehler === '') {
            $neu[$k] = $gut;
        } else {
            $cc_fehler[] = $fehler;
            $cc_beanstandet[] = $k;
        }
    };

    $neu['enabled']       = isset($_POST['enabled']) ? '1' : '0';
    /* Geraetenamen zeichengenau, auch mit ' und " (seit 1.3.13, O5): heraus
     * kommen nur Steuerzeichen. Bis 1.3.12 wurde aus "Anna's Box" "Annas Box",
     * und der Lautsprecher wurde nicht mehr gefunden. Ein Semikolon trennt in
     * der Datei - ein Name mit Semikolon wird abgewiesen. */
    $cc_gl = preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]+/', '',
                          is_string($_POST['geraete'] ?? null) ? $_POST['geraete'] : '');
    $cc_namen = array();
    foreach (cc_zeilen((string) $cc_gl) as $cc_zn) {
        $cc_zn = trim($cc_zn);
        if ($cc_zn !== '') {
            $cc_namen[] = $cc_zn;
        }
    }
    $cc_mit_semikolon = array_filter($cc_namen, function ($n) { return strpos($n, ';') !== false; });
    if ($cc_mit_semikolon) {
        $cc_fehler[] = sprintf(cc_t('PRUEF.SEMIKOLON'), cc_e(implode(', ', $cc_mit_semikolon)));
        $cc_beanstandet[] = 'geraete';
    } else {
        $neu['geraete'] = implode("\n", $cc_namen);
    }
    // MQTT und Themenpraefix stehen im Reiter MQTT und werden hier NICHT
    // angefasst. $neu kommt aus cc_config_read(), die Werte ueberleben
    // damit unveraendert. Stuende hier weiter isset($_POST['mqtt_ein']),
    // schaltete jedes Speichern der Einstellungen MQTT stillschweigend ab -
    // das Formular schickt den Haken ja gar nicht mit.
    $neu['udp']           = isset($_POST['udp']) ? '1' : '0';
    foreach (array('udp_port', 'intervall', 'aktualisierung', 'lautstaerke_schritt') as $cc_zk) {
        $pruefen($cc_zk, $_POST[$cc_zk] ?? '');
    }
    // Die Favoritenliste wird NICHT hart gefiltert: eine Adresse darf
    // alles enthalten, was eine Adresse enthaelt. Heraus kommen nur
    // Steuerzeichen und das Anfuehrungszeichen, das die Konfigurations-
    // datei zerlegen wuerde.
    $neu['favoriten']     = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F"]+/u',
                                         '', (string) ($_POST['favoriten'] ?? ''));

    // --- Ansage (TTS) ---
    $pruefen('tts_modus', $_POST['tts_modus'] ?? 'chromecast');
    $neu['tts_ip']          = $saeubern($_POST['tts_ip'] ?? '');
    $pruefen('tts_port', $_POST['tts_port'] ?? '');
    $neu['tts_zonen']       = $saeubern($_POST['tts_zonen'] ?? '1');
    $pruefen('tts_lautstaerke', $_POST['tts_lautstaerke'] ?? '');
    // Sprache: abgewiesen, nicht beschnitten - bis 1.3.12 wurde aus "de_DE"
    // still "dede" (O5). Leer heisst wie bisher: de.
    $cc_spr = is_string($_POST['tts_sprache'] ?? null) ? trim($_POST['tts_sprache']) : '';
    $pruefen('tts_sprache', $cc_spr !== '' ? $cc_spr : 'de');
    $neu['tts_vorlage']     = $saeubern($_POST['tts_vorlage'] ?? '');
    // Leer heisst: die aktuelle Lautstaerke beibehalten. Deshalb NICHT auf
    // eine Vorgabe zwingen - das waere eine Entscheidung, die niemand
    // getroffen hat. Sonst eine Zahl von 0 bis 100 - "abc" wird abgewiesen,
    // nicht zu 0 (O5).
    $pruefen('tts_pegel', $_POST['tts_pegel'] ?? '');
    $neu['tts_fortsetzen']  = isset($_POST['tts_fortsetzen']) ? '1' : '0';
    $neu['tts_gong']        = $saeubern($_POST['tts_gong'] ?? '');
    $neu['tts_lokal_basis'] = $saeubern($_POST['tts_lokal_basis'] ?? '');
    $pruefen('lautstaerke_max', $_POST['lautstaerke_max'] ?? '');
    $pruefen('ruhe_max', $_POST['ruhe_max'] ?? '');
    // Uhrzeiten werden ABGEWIESEN, wenn sie keine sind - nicht
    // zurechtgebogen. Leer heisst: Ruhezeit aus. Jede Beanstandung steht
    // fuer sich (O6).
    foreach (array('ruhe_von', 'ruhe_bis') as $cc_rf) {
        $pruefen($cc_rf, $_POST[$cc_rf] ?? '');
    }
    $neu['gruppen']         = isset($_POST['gruppen']) ? '1' : '0';
    $neu['beschleunigung']  = isset($_POST['beschleunigung']) ? '1' : '0';
    // B1 (1.3.22): Sperre aus Loxone annehmen - ab Werk aus.
    $neu['sperre_ein']      = isset($_POST['sperre_ein']) ? '1' : '0';

    if ($cc_beanstandet) {
        // Entscheidung 16 (30.09.2026; Regeln/04): bei einer Beanstandung wird
        // NICHTS gespeichert, auch nicht die uebrigen richtigen Felder, und
        // der Dienst wird nicht angefasst. Bis 1.3.14 wurden die uebrigen
        // gespeichert ("teilweise"). Die eingetippten Werte kommen per X-2
        // zurueck ins Formular.
        array_splice($cc_fehler, $cc_f0, 0, array(cc_t('TEXT.NICHTS_GESPEICHERT')));
        $cc_eingaben_form = 'settings';
    } elseif (cc_config_write($neu)) {
        $cc_saved = true;
        require_once __DIR__ . '/cc_test.php';
        // Der Haken entscheidet, was nach dem Speichern passiert, und die
        // Meldung sagt, was mit dem Dienst WIRKLICH geschah (O8) - bis 1.3.12
        // stand "angehalten" auch, wenn gar keiner lief.
        $cc_hinweis = cc_dienst_nachziehen($neu);
        // Gruppe 6b (1.3.24, E14): Geraetenamen, die Themen stoeren - warnen, nicht abweisen. Die Bruecke folgt dem
        // Haken "Dienst laufen lassen".
        foreach (cc_geraete_warnungen(cc_geraete($neu)) as $cc_gw_t) {
            $cc_hinweis .= '<br><b>' . cc_t('BEFEHL.W_ACHTUNG') . '</b> ' . $cc_gw_t;
        }
        $cc_bs = cc_bruecke_satz(cc_bruecke_schalten());
        if ($cc_bs !== '') {
            $cc_hinweis .= ' ' . $cc_bs;
        }
    } else {
        $cc_fehler[] = cc_t('TEXT.F_SCHREIBEN') . ' ' . cc_e($cc_p['config']);
    }
}

/* ============ Speichern: Sprachausgabe fuer andere Plugins (Ansage-3) ============
 *
 * Ein eigenes Formular mit eigenem Handler; es fasst nur sprechen_ein,
 * sprechen_geraet, sprechen_stunde und sprechtoken an. Bei einer
 * Beanstandung wird nichts gespeichert (Entscheidung 16); die eingetippten
 * Werte kommen zurueck (X-2) - das Sprechtoken nie. Der Dienst muss nicht neu
 * starten: den Schalter liest der Endpunkt bei jedem Aufruf. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_sprechen'])) {
    list($cc_sneu, $cc_sbean) = cc_sprechen_formular($_POST, cc_config_read());
    if ($cc_sbean) {
        $cc_fehler[] = cc_t('TEXT.NICHTS_GESPEICHERT');
        foreach ($cc_sbean as $cc_sk => $cc_st) {
            $cc_fehler[] = $cc_st;
            $cc_beanstandet[] = $cc_sk;
        }
        $cc_eingaben_form = 'sprechen';
    } elseif (cc_config_write($cc_sneu)) {
        $cc_saved = true;
        $cc_hinweis = cc_t($cc_sneu['sprechen_ein'] === '1' ? 'SPRECHEN.H_GESPEICHERT_AN'
                                                            : 'SPRECHEN.H_GESPEICHERT_AUS');
    } else {
        $cc_fehler[] = cc_t('TEXT.F_SCHREIBEN') . ' ' . cc_e($cc_p['config']);
    }
    $cc_tab = 'tab-settings';
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

/* ---------------- Einstellungen sichern ----------------
 *
 * Ausgegeben wird die VOLLE Konfiguration samt Aktionstoken, dazu ein
 * lesbarer Kopf aus _-Schluesseln (cc_sicherung_bauen(), seit 1.3.13, C1).
 * Das Token ist das Merkwort der Oberflaeche; ohne es stuende nach dem
 * Zurueckspielen auf einem zweiten LoxBerry ein neues, und der Hinweis am
 * Knopf sagt, dass die Datei es traegt. Bis 1.3.12 stand hier cc_cfg() ohne
 * Argumente - der Knopf lieferte einen PHP-Fehler statt einer Datei. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cc_sichern'])) {
    // X-3 (Verbesserungsbau 30.09.2026): bestuende ein gespeicherter Wert das
    // eigene Zurueckspielen nicht, sagt es der Kopf der Datei - mit den
    // NAMEN, nie den Werten. Geliefert wird trotzdem, vollstaendig.
    $cc_sich = cc_sicherung_bauen();
    $cc_altw = cc_rueckspiel_altwerte($cc_sich);
    if ($cc_altw) {
        $cc_sich = array('_warnung' => sprintf(cc_t('TEXT.SICH_ALTWERT_KOPF'),
                                                implode(', ', $cc_altw))) + $cc_sich;
    }
    $cc_js = json_encode($cc_sich,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($cc_js !== false) {
        // Fremdbefund C16 (1.3.24): den Ausgabepuffer leeren, BEVOR die Kopfzeilen gehen - wie beim Vorlagen-Download.
        // Eine Warnung davor landete sonst im JSON, und die Sicherung liesse sich nicht mehr zurueckspielen.
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="chromecast4lox_einstellungen_'
               . date('Ymd_His') . '.json"');
        echo $cc_js;
        exit;
    }
    $cc_fehler[] = cc_t('TEXT.SICH_SCHREIBFEHLER');
}

/* ---------------- Einstellungen zurueckspielen ----------------
 *
 * is_uploaded_file() ZUERST: ohne diese Pruefung liesse sich jede Datei des
 * Servers unterschieben. Dann die Groessengrenze - eine Sicherung dieses
 * Plugins ist wenige Kilobyte gross; alles darueber wird gar nicht gelesen. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cc_zurueck'])) {
    if (!isset($_FILES['cc_sicherung']) || !is_array($_FILES['cc_sicherung'])
        || !isset($_FILES['cc_sicherung']['tmp_name'])
        || !@is_uploaded_file($_FILES['cc_sicherung']['tmp_name'])) {
        $cc_fehler[] = cc_t('TEXT.SICH_KEINE_DATEI');
    } elseif ((int) $_FILES['cc_sicherung']['size'] > 262144) {
        $cc_fehler[] = cc_t('TEXT.SICH_ZU_GROSS');
    } else {
        list($cc_neu, $cc_mangel, $cc_n) = cc_sicherung_lesen(
            (string) @file_get_contents($_FILES['cc_sicherung']['tmp_name']));
        if ($cc_neu === null) {
            /* ALLE Beanstandungen, nicht nur die erste - und geaendert wird
             * nichts. */
            $cc_fehler[] = cc_t('TEXT.SICH_ABGELEHNT');
            foreach ($cc_mangel as $cc_mz) {
                $cc_fehler[] = $cc_mz;
            }
        } elseif (cc_config_write($cc_neu)) {
            $cc_saved = true;
            require_once __DIR__ . '/cc_test.php';
            // Den Dienst nachziehen und sagen, was mit ihm geschah (seit
            // 1.3.13, O3; Regeln/05 Punkt 7). Bis 1.3.12 wirkten geaenderte
            // Geraete, Port oder Praefix erst beim naechsten Neustart, und
            // enabled=0 aus der Sicherung liess den Dienst weiterlaufen.
            $cc_hinweis = sprintf(cc_t('TEXT.SICH_UEBERNOMMEN'), $cc_n) . ' '
                        . cc_dienst_nachziehen($cc_neu);
        } else {
            $cc_fehler[] = cc_t('TEXT.SICH_SCHREIBFEHLER');
        }
    }
}

/* ================= Nach jedem POST: umleiten (seit 1.3.13, O4) =================
 *
 * Regeln/04: "Jeder POST-Handler endet mit einer Umleitung; das Ergebnis
 * reist als Einmalmeldung." Bis 1.3.12 antworteten Speichern, Zurueckspielen
 * und alle Testknoepfe mit HTTP 200 ohne Location: F5 auf "Geraet ansprechen"
 * schickte ein zweites UDP-Paket, und "Dienst neu starten" oder Speichern mit
 * Haken startete bei jedem F5 neu (gemessen 30.09.2026, Oberflaechen-Befund
 * 4). Die Downloads (Vorlage, Sicherung) haben oben schon geliefert und mit
 * exit geendet. Scheitert das Schreiben der Einmalmeldung, wird wie bisher
 * direkt gerendert - lieber ohne Umleitung als ohne Meldung. Auch die
 * Abweisung durch den Wachposten reist hier mit. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cc_einmal_neu = array(
        'saved' => $cc_saved, 'hinweis' => $cc_hinweis, 'fehler' => $cc_fehler,
        'test_titel' => $cc_test_titel, 'test_text' => $cc_test_text,
        'gefunden' => is_array($cc_gefunden) ? $cc_gefunden : array(),
        'suchfehler' => (string) $cc_suchfehler, 'gesucht' => $cc_gesucht,
        'eingaben' => cc_eingaben_sammeln($cc_eingaben_form, $cc_beanstandet),
    );
    if (cc_einmal_schreiben($cc_einmal_neu)) {
        header('Location: index.php?tab=' . rawurlencode(substr($cc_tab, 4)), true, 303);
        exit;
    }
}


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
/* Wortgetreu aus VORLAGE_hausstandard.css.html (B54, 17.09.2026): jede Tabelle
   mit Eingabefeldern kommt in .sm-breit. lb-content schneidet seitlich ab; an
   BatterieBMS waren so zwei Spalten im Browser nicht erreichbar. */
.sm-breit { overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 10px 0; }
.sm-breit .sm-tbl { margin: 0; min-width: 760px; }

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
/* Ein Auswahlfeld muss man als Auswahlfeld erkennen. Nachgezogen am
   05.09.2026 nach Regeln/04; Wortlaut aus VORLAGE_hausstandard.css.html.

   Am Geraet gemessen (LoxBerry 4.0.0.15, components.css): die Rahmen-CSS
   zeichnet seit der neuen Oberflaeche selbst einen Pfeil - Regel
   ".lb-content select". Darauf kann sich eine Plugin-Oberflaeche nicht
   verlassen: die Regel gibt es erst seit dieser Fassung, und die eigene
   Feldregel loescht sie, sobald sie die Kurzform "background:" benutzt.
   Dann steht ein Auswahlfeld da, das aussieht wie ein Textfeld.

   Die Raute im SVG wird als %23 geschrieben: eine rohe Raute beendet in
   einer CSS-Adresse den Wert. */
.sm-wrap select {
    appearance: none; -webkit-appearance: none; -moz-appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='9' viewBox='0 0 14 9'%3E%3Cpath d='M1 1l6 6 6-6' fill='none' stroke='%234f7d17' stroke-width='2'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 10px center;
    padding-right: 32px; cursor: pointer; }
.sm-tbl select { padding-right: 28px; background-position: right 7px center; }

/* Nachgetragen 05.09.2026: die Klassen standen im Quelltext, aber
   nirgends im Stilblock - der Kasten stand rahmenlos da. Wortlaut
   unveraendert aus VORLAGE_hausstandard.css.html. */
.sm-hinweis { border: 1px solid #cfe3b0; background: #f2f8ea; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-warnung { border: 1px solid #f0c9a0; background: #fdf4ec; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
/* Eigene Zutat (Verbesserungsbau 30.09.2026, X-2): das beanstandete Feld
   nach einer Beanstandung rot umrandet. */
.sm-wrap input.sm-beanstandet, .sm-wrap select.sm-beanstandet, .sm-wrap textarea.sm-beanstandet {
  border: 2px solid #c62828 !important; background-color: #fff5f5; }
/* Ergaenzung (Welle Bild, Entscheidung 45): Bild der Bausteine aus dem gemeinsamen Musterprojekt. */
.sm-bild { margin: 12px 0; }
.sm-bild img { max-width: 100%; height: auto; border: 1px solid #ccc; border-radius: 4px; background: #fff; }
.sm-bild figcaption { font-size: .9em; color: #555; margin-top: 4px; }
/* Welle Bild 11 VOLL: Schritte und Hinweise je Baustein-Liste. */
.sm-schritte { margin: 6px 0 0 18px; padding: 0; }
.sm-schritte li { margin: 3px 0; }
/* Gruppe 6b (1.3.24): Kopierknopf der Namenstabelle (Reiter Einbindung, Schritt 2). */
.sm-wrap button.sm-kopie { font-size: 0.78em; padding: 1px 7px; margin-left: 6px; border: 1px solid #bbb; border-radius: 4px;
  background: #f4f4f4 !important; color: #333 !important; cursor: pointer; }
.sm-wrap details > summary { cursor: pointer; font-weight: 600; margin: 10px 0; }

</style>
<div class="sm-wrap">

<?php if ($cc_saved) { ?>
<div class="sm-alert sm-ok"><b><?php echo cc_t('TEXT.T001'); ?></b> <?= $cc_hinweis ?></div>
<?php } elseif ($cc_hinweis !== '') { ?>
<div class="sm-alert sm-info"><?= $cc_hinweis ?></div>
<?php } ?>
<?php if ($cc_fehler) { ?><div class="sm-alert sm-err"><b><?php echo cc_t('TEXT.T002'); ?></b><?php foreach ($cc_fehler as $cc_fz) { ?><br><?= $cc_fz ?><?php } ?></div><?php } ?>
<?php if (in_array($cc_konfig_zustand, array('unlesbar', 'leer', 'gekuerzt'), true)) { ?>
<div class="sm-alert sm-warn"><b><?php echo cc_t('TEXT.T002'); ?></b>
<?php echo cc_t('TEXT.W_KONFIG'); ?> <span class="sm-mono"><?= cc_e($cc_p['config']) ?></span></div>
<?php } ?>

<?php /* Kopf (Entscheidung Nr. 43, seit 1.3.18): Statusuebersicht ueber den
   Reitern, immer sichtbar. Bis 1.3.17 stand dasselbe als Fliesszeile in einem
   Meldungskasten. Nur Werte, die oben schon berechnet sind. */ ?>
<table class="sm-tbl" style="max-width:620px">
<tr><th><?= cc_e(cc_t('TEXT.KOPF_EIGENSCHAFT')) ?></th><th><?= cc_e(cc_t('TEXT.KOPF_WERT')) ?></th></tr>
<tr><td><?= cc_e(cc_t('TEXT.KOPF_DIENST')) ?></td>
    <td class="<?= $cc_pid ? 'sm-an' : 'sm-aus' ?>"><?= cc_e($cc_pid ? cc_t('TEXT.S_LAEUFT') : cc_t('TEXT.S_LAEUFT_NICHT')) ?><?= $cc_pid ? ' (PID ' . (int) $cc_pid . ')' : '' ?></td></tr>
<tr><td><?= cc_e(cc_t('TEXT.KOPF_GERAETE')) ?></td>
    <td><?= count($cc_geraete) ?></td></tr>
<tr><td><?= cc_e(cc_t('TEXT.KOPF_MQTT')) ?></td>
    <td><?= cc_e(cc_cfg($cc_cfg, 'mqtt_ein', '1') === '1' ? cc_t('TEXT.S_EIN') : cc_t('TEXT.S_AUS')) ?></td></tr>
<tr><td><?= cc_e(cc_t('TEXT.KOPF_BEFEHLE')) ?></td>
    <td><?= cc_e(cc_cfg($cc_cfg, 'befehle_mqtt_ein', '0') === '1' ? cc_t('TEXT.S_EIN') : cc_t('TEXT.S_AUS')) ?></td></tr>
<tr><td><?= cc_e(cc_t('TEXT.KOPF_UDP')) ?></td>
    <td><?= cc_cfg($cc_cfg, 'udp', '1') === '1' ? cc_e(cc_t('TEXT.S_PORT')) . ' ' . cc_e(cc_cfg($cc_cfg, 'udp_port', '7090')) : cc_e(cc_t('TEXT.S_AUS')) ?></td></tr>
<tr><td><?= cc_e(cc_t('TEXT.KOPF_LOXBERRY')) ?></td>
    <td><span class="sm-mono"><?= cc_e($cc_ip) ?></span></td></tr>
</table>

<div class="sm-tabs">
    <a class="sm-tab<?= $cc_tab === 'tab-settings' ? ' sm-active' : '' ?>" data-ziel="tab-settings" href="index.php?tab=settings"><?php echo cc_t('REITER.EINSTELLUNGEN'); ?></a>
    <a class="sm-tab<?= $cc_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" data-ziel="tab-mqtt" href="index.php?tab=mqtt"><?php echo cc_t('REITER.MQTT'); ?></a>
    <a class="sm-tab<?= $cc_tab === 'tab-loxone' ? ' sm-active' : '' ?>" data-ziel="tab-loxone" href="index.php?tab=loxone"><?php echo cc_t('REITER.LOXONE'); ?></a>
    <a class="sm-tab<?= $cc_tab === 'tab-test' ? ' sm-active' : '' ?>" data-ziel="tab-test" href="index.php?tab=test"><?php echo cc_t('REITER.TEST'); ?></a>
    <a class="sm-tab<?= $cc_tab === 'tab-log' ? ' sm-active' : '' ?>" data-ziel="tab-log" href="index.php?tab=log"><?php echo cc_t('REITER.LOG'); ?></a>
</div>

<!-- ================= Reiter: <?php echo cc_t('TEXT.T041'); ?> ================= -->
<div class="sm-seite<?= $cc_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">
<div class="sm-hinweis"><?= cc_t('TEXT.WAS_IST_DAS') ?></div>

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
<?php } elseif ($cc_gesucht) { ?>
<?php if (!$cc_gefunden) { ?>
<div class="sm-alert sm-info"><?php echo cc_t('SUCHE.H_NICHTS'); ?></div>
<?php } else { ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th style="width:6%;"></th><th><?php echo cc_t('TEXT.T015'); ?></th><th><?php echo cc_t('SUCHE.SP_MODELL'); ?></th><th><?php echo cc_t('SUCHE.SP_ADRESSE'); ?></th><th><?php echo cc_t('MQTT.SP_ART'); ?></th></tr>
<?php foreach ($cc_gefunden as $cc_gf) {
    $cc_schon = in_array($cc_gf['name'], $cc_geraete, true); ?>
<tr><td><?php if ($cc_schon) { ?><span class="sm-an">&#10003;</span><?php } else { ?><input data-role="none" type="checkbox" name="gefunden[]" value="<?= cc_e($cc_gf['name']) ?>" checked><?php } ?></td>
<td><?= cc_e($cc_gf['name']) ?></td><td><?= cc_e($cc_gf['modell']) ?></td>
<td><span class="sm-mono"><?= cc_e($cc_gf['adresse']) ?></span></td><td><?= cc_e($cc_gf['art']) ?></td></tr>
<?php } ?>
</table>
</div>
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
<label class="sm-check"><input data-role="none" type="checkbox" name="enabled" value="1"<?= cc_eingabe_an('settings', 'enabled', cc_cfg($cc_cfg, 'enabled', '1') === '1') ? ' checked' : '' ?>> <b><?php echo cc_t('TEXT.L_ENABLED'); ?></b></label>
<div class="sm-small"><?php echo cc_t('TEXT.H_ENABLED'); ?></div>

<h2><?php echo cc_t('TEXT.T008'); ?></h2>
<label><?php echo cc_t('TEXT.T009'); ?></label>
<textarea data-role="none" name="geraete"<?= cc_markierung('geraete') ?> placeholder="Wohnzimmer&#10;K&uuml;che Lautsprecher"><?= cc_e(cc_eingabe('settings', 'geraete', implode("\n", $cc_geraete))) ?></textarea>
<div class="sm-small">
<?php echo cc_t('TEXT.S_NAME_GENAU'); ?>
</div>
<?php /* b1 (Verbesserungsbau 30.09.2026): eine alte Kommaliste aus 1.3.12 ist
       seit 1.3.13 EIN Geraet. Gefragt wird, nicht geaendert - ein Name darf
       ein Komma tragen. Die Namen stehen maskiert im Kasten (O7). */
$cc_komma = array_values(array_filter($cc_geraete, function ($n) { return strpos($n, ',') !== false; }));
if ($cc_komma) { ?>
<div class="sm-warnung"><?= sprintf(cc_t('TEXT.W_KOMMA'), cc_e(implode(' | ', $cc_komma))) ?></div>
<?php } ?>

<?php if ($cc_geraete) { ?>
<table class="sm-tbl">
<tr><th style="width:45%;"><?php echo cc_t('TEXT.T015'); ?></th><th><?php echo cc_t('TEXT.T016'); ?></th></tr>
<?php foreach ($cc_geraete as $g) { ?>
<tr><td><?= cc_e($g) ?></td><td><span class="sm-mono"><?= cc_e($cc_praefix . '/' . cc_thema($g)) ?></span></td></tr>
<?php } ?>
</table>
<?php } ?>

<h2><?php echo cc_t('MQTT.H_UDP'); ?></h2>
<label class="sm-check"><input data-role="none" type="checkbox" name="udp" value="1"<?= cc_eingabe_an('settings', 'udp', cc_cfg($cc_cfg, 'udp', '1') === '1') ? ' checked' : '' ?>> <?php echo cc_t('TEXT.T021'); ?></label>
<div class="sm-small"><?php echo cc_t('TEXT.T022'); ?> <?php echo cc_t('TEXT.S_UDP_ABSENDER'); ?></div>

<div class="sm-row" style="margin-top:12px;">
<div>
<label><?php echo cc_t('TEXT.T023'); ?></label>
<input data-role="none" type="number" name="udp_port" min="1024" max="65535" value="<?= cc_e(cc_eingabe('settings', 'udp_port', cc_cfg($cc_cfg, 'udp_port', '7090'))) ?>"<?= cc_markierung('udp_port') ?>>
</div>
</div>
<div class="sm-small"><?php echo cc_t('MQTT.H_VERWEIS'); ?></div>

<h2><?php echo cc_t('TEXT.T026'); ?></h2>
<div class="sm-row">
<div>
<label><?php echo cc_t('TEXT.T027'); ?></label>
<input data-role="none" type="number" name="intervall" min="2" max="3600" value="<?= cc_e(cc_eingabe('settings', 'intervall', cc_cfg($cc_cfg, 'intervall', '10'))) ?>"<?= cc_markierung('intervall') ?>>
<div class="sm-small"><?php echo cc_t('TEXT.T028'); ?></div>
</div>
<div>
<label><?php echo cc_t('TEXT.T029'); ?></label>
<input data-role="none" type="number" name="aktualisierung" min="5" max="86400" value="<?= cc_e(cc_eingabe('settings', 'aktualisierung', cc_cfg($cc_cfg, 'aktualisierung', '60'))) ?>"<?= cc_markierung('aktualisierung') ?>>
<div class="sm-small"><?php echo cc_t('TEXT.T030'); ?></div>
</div>
<div>
<label><?php echo cc_t('TEXT.T031'); ?></label>
<input data-role="none" type="number" name="lautstaerke_schritt" min="1" max="50" value="<?= cc_e(cc_eingabe('settings', 'lautstaerke_schritt', cc_cfg($cc_cfg, 'lautstaerke_schritt', '5'))) ?>"<?= cc_markierung('lautstaerke_schritt') ?>>
<div class="sm-small"><?php echo cc_t('TEXT.S_SCHRITTWEITE_GILT'); ?></div>
</div>
</div>

<h2><?php echo cc_t('FAV.H'); ?></h2>
<label><?php echo cc_t('FAV.L'); ?></label>
<textarea data-role="none" name="favoriten"<?= cc_markierung('favoriten') ?> placeholder="<?php echo cc_t('FAV.P'); ?>"><?= cc_e(cc_eingabe('settings', 'favoriten', cc_cfg($cc_cfg, 'favoriten', ''))) ?></textarea>
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
<select data-role="none" name="tts_modus" id="tts_modus"<?= cc_markierung('tts_modus') ?> onchange="ccTtsModus()">
<?php foreach (cc_tts_modi() as $cc_mk => $cc_mt) { ?>
<option value="<?= cc_e($cc_mk) ?>"<?= cc_eingabe('settings', 'tts_modus', cc_cfg($cc_cfg, 'tts_modus', 'chromecast')) === $cc_mk ? ' selected' : '' ?>><?php echo cc_t($cc_mt); ?></option>
<?php } ?>
</select>
<div class="sm-small"><?php echo cc_t('TTS.H_MODUS'); ?></div>
</div>
<div>
<label><?php echo cc_t('TTS.L_SPRACHE'); ?></label>
<input data-role="none" type="text" name="tts_sprache" maxlength="5" value="<?= cc_e(cc_eingabe('settings', 'tts_sprache', cc_cfg($cc_cfg, 'tts_sprache', 'de'))) ?>"<?= cc_markierung('tts_sprache') ?>>
<div class="sm-small"><?php echo cc_t('TTS.H_SPRACHE'); ?></div>
</div>
<div>
<label><?php echo cc_t('TTS.L_PEGEL'); ?></label>
<input data-role="none" type="text" name="tts_pegel" value="<?= cc_e(cc_eingabe('settings', 'tts_pegel', cc_cfg($cc_cfg, 'tts_pegel', ''))) ?>"<?= cc_markierung('tts_pegel') ?> placeholder="<?php echo cc_t('TTS.P_PEGEL'); ?>">
<div class="sm-small"><?php echo cc_t('TTS.H_PEGEL'); ?></div>
</div>
</div>
<div style="margin:8px 0;">
<label style="display:inline-flex;align-items:center;gap:6px;">
<input data-role="none" type="checkbox" name="tts_fortsetzen" value="1"<?= cc_eingabe_an('settings', 'tts_fortsetzen', cc_cfg($cc_cfg, 'tts_fortsetzen', '1') === '1') ? ' checked' : '' ?>> <?php echo cc_t('TTS.L_FORTSETZEN'); ?>
</label>
<div class="sm-small"><?php echo cc_t('TTS.H_FORTSETZEN'); ?></div>
</div>
<div id="tts_fremd">
<div class="sm-row">
<div>
<label><?php echo cc_t('TTS.L_IP'); ?></label>
<input data-role="none" type="text" name="tts_ip" value="<?= cc_e(cc_eingabe('settings', 'tts_ip', cc_cfg($cc_cfg, 'tts_ip', ''))) ?>"<?= cc_markierung('tts_ip') ?> placeholder="192.168.1.50">
</div>
<div>
<label><?php echo cc_t('TTS.L_PORT'); ?></label>
<input data-role="none" type="number" name="tts_port" min="1" max="65535" value="<?= cc_e(cc_eingabe('settings', 'tts_port', cc_cfg($cc_cfg, 'tts_port', '7091'))) ?>"<?= cc_markierung('tts_port') ?>>
</div>
<div>
<label><?php echo cc_t('TTS.L_ZONEN'); ?></label>
<input data-role="none" type="text" name="tts_zonen" value="<?= cc_e(cc_eingabe('settings', 'tts_zonen', cc_cfg($cc_cfg, 'tts_zonen', '1'))) ?>"<?= cc_markierung('tts_zonen') ?> placeholder="2,4,6">
</div>
<div>
<label><?php echo cc_t('TTS.L_LAUTSTAERKE'); ?></label>
<input data-role="none" type="number" name="tts_lautstaerke" min="1" max="100" value="<?= cc_e(cc_eingabe('settings', 'tts_lautstaerke', cc_cfg($cc_cfg, 'tts_lautstaerke', '8'))) ?>"<?= cc_markierung('tts_lautstaerke') ?>>
</div>
</div>
<div id="tts_vorlage_zeile">
<label><?php echo cc_t('TTS.L_VORLAGE'); ?></label>
<input data-role="none" type="text" name="tts_vorlage" value="<?= cc_e(cc_eingabe('settings', 'tts_vorlage', cc_cfg($cc_cfg, 'tts_vorlage', ''))) ?>"<?= cc_markierung('tts_vorlage') ?> placeholder="http://{ip}:{port}/tts?text={text}&amp;zone={zones}&amp;vol={vol}">
<div class="sm-small"><?php echo cc_t('TTS.H_VORLAGE'); ?></div>
</div>
</div>
<div id="tts_hinweis_audioserver" class="sm-small" style="display:none;"><?php echo cc_t('TTS.H_AUDIOSERVER'); ?></div>

<h2><?php echo cc_t('SCHNELL.H'); ?></h2>
<label style="display:inline-flex;align-items:center;gap:6px;">
<input data-role="none" type="checkbox" name="beschleunigung" value="1"<?= cc_eingabe_an('settings', 'beschleunigung', cc_cfg($cc_cfg, 'beschleunigung', '0') === '1') ? ' checked' : '' ?>> <?php echo cc_t('SCHNELL.L'); ?>
</label>
<div class="sm-alert sm-warn"><?php echo cc_t('SCHNELL.HINWEIS'); ?></div>

<div class="sm-row" style="margin-top:12px;">
<div>
<label><?php echo cc_t('TTS.L_GONG'); ?></label>
<input data-role="none" type="text" name="tts_gong" value="<?= cc_e(cc_eingabe('settings', 'tts_gong', cc_cfg($cc_cfg, 'tts_gong', ''))) ?>"<?= cc_markierung('tts_gong') ?> placeholder="http://…/gong.mp3">
<div class="sm-small"><?php echo cc_t('TTS.H_GONG'); ?></div>
</div>
<div>
<label><?php echo cc_t('TTS.L_BASIS'); ?></label>
<input data-role="none" type="text" name="tts_lokal_basis" value="<?= cc_e(cc_eingabe('settings', 'tts_lokal_basis', cc_cfg($cc_cfg, 'tts_lokal_basis', ''))) ?>"<?= cc_markierung('tts_lokal_basis') ?> placeholder="http://<?= cc_e($cc_ip) ?><?= cc_webport() !== 80 ? ':' . (int) cc_webport() : '' ?>/plugins/<?= cc_e($cc_p['plugin']) ?>">
<div class="sm-small"><?php echo cc_t('TTS.H_BASIS'); ?></div>
</div>
</div>

<h2><?php echo cc_t('RUHE.H'); ?></h2>
<div class="sm-row">
<div>
<label><?php echo cc_t('RUHE.L_MAX'); ?></label>
<input data-role="none" type="number" name="lautstaerke_max" min="0" max="100" value="<?= cc_e(cc_eingabe('settings', 'lautstaerke_max', cc_cfg($cc_cfg, 'lautstaerke_max', '100'))) ?>"<?= cc_markierung('lautstaerke_max') ?>>
</div>
<div>
<label><?php echo cc_t('RUHE.L_VON'); ?></label>
<input data-role="none" type="text" name="ruhe_von" maxlength="5" value="<?= cc_e(cc_eingabe('settings', 'ruhe_von', cc_cfg($cc_cfg, 'ruhe_von', ''))) ?>"<?= cc_markierung('ruhe_von') ?> placeholder="22:00">
</div>
<div>
<label><?php echo cc_t('RUHE.L_BIS'); ?></label>
<input data-role="none" type="text" name="ruhe_bis" maxlength="5" value="<?= cc_e(cc_eingabe('settings', 'ruhe_bis', cc_cfg($cc_cfg, 'ruhe_bis', ''))) ?>"<?= cc_markierung('ruhe_bis') ?> placeholder="07:00">
</div>
<div>
<label><?php echo cc_t('RUHE.L_RUHEMAX'); ?></label>
<input data-role="none" type="number" name="ruhe_max" min="0" max="100" value="<?= cc_e(cc_eingabe('settings', 'ruhe_max', cc_cfg($cc_cfg, 'ruhe_max', '30'))) ?>"<?= cc_markierung('ruhe_max') ?>>
</div>
</div>
<div class="sm-small"><?php echo cc_t('RUHE.HINWEIS'); ?></div>

<?php /* B1 (1.3.22): Sperre aus Loxone - ab Werk ohne Wirkung; Zustand aus
         derselben Funktion wie die Zeile im Reiter Test. */
list(, , $cc_sperre_text) = cc_loxsperre_pruefzeile(); ?>
<h2 id="sperre"><?php echo cc_t('SPERRE.H'); ?></h2>
<label class="sm-check"><input data-role="none" type="checkbox" name="sperre_ein" value="1"<?= cc_eingabe_an('settings', 'sperre_ein', cc_cfg($cc_cfg, 'sperre_ein', '0') === '1') ? ' checked' : '' ?>> <b><?php echo cc_t('SPERRE.L_EIN'); ?></b></label>
<div class="sm-small"><?= sprintf(cc_t('SPERRE.HILFE'), cc_e($cc_praefix)) ?></div>
<div class="sm-small"><?= cc_e(sprintf(cc_t('SPERRE.JETZT'), $cc_sperre_text)) ?></div>

<h2><?php echo cc_t('TTS.H_GRUPPEN'); ?></h2>
<label style="display:inline-flex;align-items:center;gap:6px;">
<input data-role="none" type="checkbox" name="gruppen" value="1"<?= cc_eingabe_an('settings', 'gruppen', cc_cfg($cc_cfg, 'gruppen', '1') === '1') ? ' checked' : '' ?>> <?php echo cc_t('TTS.L_GRUPPEN'); ?>
</label>
<div class="sm-small"><?php echo cc_t('TTS.H_GRUPPEN_TEXT'); ?></div>

<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="save" value="1"><?php echo cc_t('TEXT.T035'); ?></button>
<div class="sm-small"><?php echo cc_t('TEXT.T036'); ?></div>
</form>

<?php /* Ansage-3 (01.10.2026): Sprachausgabe fuer andere Plugins - eigenes
       Formular, ab Werk aus. Das Sprechtoken ist ein Kennwortfeld: leer laesst es,
       wie es ist, und es steht nie im HTML dieser Seite. */
$cc_stok = (string) cc_cfg($cc_cfg, 'sprechtoken', ''); ?>
<h2 id="sprechen"><?php echo cc_t('SPRECHEN.H'); ?></h2>
<form method="post" action="index.php">
<input data-role="none" type="hidden" name="fmt" value="<?= cc_e($cc_fmt) ?>"><input data-role="none" type="hidden" name="activetab" value="tab-settings">
<div class="sm-small"><?php echo cc_t('SPRECHEN.EINLEITUNG'); ?></div>
<label class="sm-check"><input data-role="none" type="checkbox" name="sprechen_ein" value="1"<?= cc_eingabe_an('sprechen', 'sprechen_ein', cc_cfg($cc_cfg, 'sprechen_ein', '0') === '1') ? ' checked' : '' ?>> <b><?php echo cc_t('SPRECHEN.L_EIN'); ?></b></label>
<div class="sm-small"><?php echo cc_t('SPRECHEN.H_EIN'); ?></div>
<div class="sm-row" style="margin-top:8px;">
<div>
<label><?php echo cc_t('SPRECHEN.L_GERAET'); ?></label>
<input data-role="none" type="text" name="sprechen_geraet" value="<?= cc_e(cc_eingabe('sprechen', 'sprechen_geraet', cc_cfg($cc_cfg, 'sprechen_geraet', ''))) ?>"<?= cc_markierung('sprechen_geraet') ?> placeholder="<?= cc_e(cc_sammelziel()) ?>">
<div class="sm-small"><?= sprintf(cc_t('SPRECHEN.H_GERAET'), $cc_geraete ? cc_e(implode(', ', $cc_geraete)) : cc_e(cc_t('SPRECHEN.E_KEINE'))) ?></div>
</div>
<div>
<label><?php echo cc_t('SPRECHEN.L_STUNDE'); ?></label>
<input data-role="none" type="number" name="sprechen_stunde" min="10" max="240" value="<?= cc_e(cc_eingabe('sprechen', 'sprechen_stunde', cc_cfg($cc_cfg, 'sprechen_stunde', '60'))) ?>"<?= cc_markierung('sprechen_stunde') ?>>
<div class="sm-small"><?php echo cc_t('SPRECHEN.H_STUNDE'); ?></div>
</div>
</div>
<label><?php echo cc_t('SPRECHEN.L_TOKEN'); ?></label>
<input data-role="none" type="password" name="sprechtoken" id="cc_sprechtoken" value="" autocomplete="new-password"<?= cc_markierung('sprechtoken') ?> placeholder="<?= cc_e($cc_stok !== '' ? sprintf(cc_t('SPRECHEN.P_TOKEN_GESETZT'), strlen($cc_stok)) : cc_t('SPRECHEN.P_TOKEN_LEER')) ?>">
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-lesen" type="button" onclick="ccTokenWuerfeln()"><?php echo cc_t('SPRECHEN.K_WUERFELN'); ?></button>
</div>
<div class="sm-small"><?php echo cc_t('SPRECHEN.H_TOKEN'); ?></div>
<label class="sm-check"><input data-role="none" type="checkbox" name="sprechtoken_loeschen" value="1"<?= cc_eingabe_an('sprechen', 'sprechtoken_loeschen', false) ? ' checked' : '' ?>> <?php echo cc_t('SPRECHEN.L_LOESCHEN'); ?></label>
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="save_sprechen" value="1"><?php echo cc_t('TEXT.T035'); ?></button>
<div class="sm-small"><?php echo cc_t('SPRECHEN.H_SPEICHERN'); ?></div>
</form>

<h2><?= cc_t('TEXT.H_SICHERUNG') ?></h2>
<div class="sm-hinweis"><?= cc_t('TEXT.SICH_ERKLAERUNG') ?></div>
<div class="sm-warnung"><?= cc_t('TEXT.SICH_WARNUNG') ?></div>
<?php $cc_altwerte = cc_rueckspiel_altwerte(); if ($cc_altwerte) { ?>
<div class="sm-warnung"><?= sprintf(cc_t('TEXT.SICH_ALTWERT'), cc_e(implode(', ', $cc_altwerte))) ?></div>
<?php } ?>
<div class="sm-knopfreihe">
  <!-- ZWEI GETRENNTE Formulare. Das Sichern schickt einen Download und ruft
       exit auf; das Zurueckspielen braucht enctype="multipart/form-data".
       Wer beides in ein Formular legt, bekommt entweder keinen Upload oder
       einen Download, der das Speichern verschluckt. -->
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="fmt" value="<?= cc_e($cc_fmt) ?>"><input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="cc_sichern" value="1"><?= cc_t('TEXT.K_SICHERN') ?></button>
  </form>
  <form action="index.php" method="post" enctype="multipart/form-data">
    <input data-role="none" type="hidden" name="fmt" value="<?= cc_e($cc_fmt) ?>"><input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="file" name="cc_sicherung" accept=".json">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="cc_zurueck" value="1"><?= cc_t('TEXT.K_ZURUECK') ?></button>
  </form>
</div>
</div>

<!-- ================= Reiter: MQTT ================= -->
<div class="sm-seite<?= $cc_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" id="tab-mqtt">
<?php /* Gruppe 6b / MQTT (1.3.24; Entwurf cc 2.4, 3.4, gemeinsam 2.11): die drei Haken der Befehle, das Abo des Gateways
   (V1: die Datei liegt bei, E12), EINE Themenliste aus cc_mqtt_namen() statt der zwei Tabellen bis 1.3.23, der alte
   Befehlsweg zugeklappt. */
$cc_mn = cc_mqtt_namen($cc_cfg);
$cc_cmd_h = cc_cmd_hinweis($cc_cfg);
list(, $cc_abo_da) = cc_abo_datei($cc_praefix);
$cc_jn = function ($w) { return cc_t($w ? 'TEST.A_JA_KURZ' : 'TEST.A_NEIN_KURZ'); }; ?>
<form method="post" action="index.php">
<input data-role="none" type="hidden" name="fmt" value="<?= cc_e($cc_fmt) ?>"><input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo cc_t('LEGENDE.AKTION'); ?></span>
</div>
<h2><?php echo cc_t('MQTT.H_WEG'); ?></h2>
<label class="sm-check"><input data-role="none" type="checkbox" name="mqtt_ein" value="1"<?= cc_eingabe_an('mqtt', 'mqtt_ein', cc_cfg($cc_cfg, 'mqtt_ein', '1') === '1') ? ' checked' : '' ?>> <b><?php echo cc_t('TEXT.T018'); ?></b> <?php echo cc_t('TEXT.T019'); ?></label>
<div class="sm-small"><?php echo cc_t('TEXT.T020'); ?></div>

<div class="sm-row" style="margin-top:12px;">
<div>
<label><?php echo cc_t('TEXT.T024'); ?></label>
<input data-role="none" type="text" name="mqtt_topic" value="<?= cc_e(cc_eingabe('mqtt', 'mqtt_topic', $cc_praefix)) ?>"<?= cc_markierung('mqtt_topic') ?>>
<div class="sm-small"><?php echo cc_t('TEXT.T025'); ?></div>
</div>
</div>
<?php if (strlen($cc_praefix) > 40) { ?>
<div class="sm-warnung"><?= sprintf(cc_t('BEFEHL.W_PRAEFIX_LANG'), cc_e($cc_praefix)) ?></div>
<?php } ?>

<h2 id="befehle"><?php echo cc_t('BEFEHL.H'); ?></h2>
<label class="sm-check"><input data-role="none" type="checkbox" name="befehle_mqtt_ein" value="1"<?= cc_eingabe_an('mqtt', 'befehle_mqtt_ein', cc_cfg($cc_cfg, 'befehle_mqtt_ein', '0') === '1') ? ' checked' : '' ?>> <b><?php echo cc_t('BEFEHL.L_EIN'); ?></b></label>
<div class="sm-small"><?php echo cc_t('BEFEHL.H_EIN'); ?></div>
<label class="sm-check"><input data-role="none" type="checkbox" name="befehle_mqtt_steuern" value="1"<?= cc_eingabe_an('mqtt', 'befehle_mqtt_steuern', cc_cfg($cc_cfg, 'befehle_mqtt_steuern', '0') === '1') ? ' checked' : '' ?>> <b><?php echo cc_t('BEFEHL.L_STEUERN'); ?></b></label>
<div class="sm-small"><?php echo cc_t('BEFEHL.H_STEUERN'); ?></div>
<label class="sm-check"><input data-role="none" type="checkbox" name="befehle_cmd_ein" value="1"<?= cc_eingabe_an('mqtt', 'befehle_cmd_ein', cc_cfg($cc_cfg, 'befehle_cmd_ein', '0') === '1') ? ' checked' : '' ?>> <b><?= sprintf(cc_t('BEFEHL.L_CMD'), cc_e($cc_praefix)) ?></b></label>
<div class="sm-small"><?php echo cc_t('BEFEHL.H_CMD'); ?></div>
<?php if ($cc_cmd_h !== '') { ?>
<div class="sm-warnung"><?= $cc_cmd_h ?></div>
<?php } ?>
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="save_mqtt" value="1"><?php echo cc_t('TEXT.T035'); ?></button>
<div class="sm-small"><?php echo cc_t('BEFEHL.H_SPEICHERN'); ?></div>
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
/* Gateway V1: die Abo-Datei config/plugins/<ordner>/mqtt_subscriptions.cfg liegt bei (E12) - einzutragen ist nichts;
 * sie wird beim Speichern eines neuen Praefixes nachgefuehrt. V2: Datenpunkte anhaken (Liste im Reiter Einbindung,
 * Schritt 1). Ist die Fassung nicht lesbar, stehen BEIDE Saetze da. */
$cc_gwf = cc_gateway_fassung();
?>
<?php if ($cc_gwf !== 2 && $cc_gwf < 3) { ?>
<div class="sm-step"><?php echo cc_t('MQTT.ABO_PFLICHT'); ?>
<div class="sm-mono" style="background:#f4f4f4;border:1px solid #ccc;padding:8px;margin-top:6px;"><?= cc_e(cc_abo_zeile($cc_praefix)) ?></div>
<?php if (!$cc_abo_da && $cc_p['home'] !== '') { ?><div class="sm-warnung"><?php echo cc_t('MQTT.ABO_DATEI_FEHLT'); ?></div><?php } ?>
</div>
<?php } ?>
<?php if ($cc_gwf !== 1) { ?>
<div class="sm-alert sm-info"><?php echo cc_t('MQTT.ABO_V2'); ?></div>
<?php } ?>
<?php if ($cc_gwf === 0) { ?>
<div class="sm-small"><?php echo cc_t('MQTT.ABO_UNBEKANNT'); ?></div>
<?php } ?>
<div class="sm-small"><?php echo cc_t('MQTT.ABO_BEFEHLE'); ?></div>

<h2><?php echo cc_t('MQTT.H_THEMEN'); ?></h2>
<div class="sm-breit">
<table class="sm-tbl" id="cc_themenliste">
<tr><th style="width:26%;"><?php echo cc_t('TEXT.T097'); ?></th><th style="width:24%;"><?php echo cc_t('MQTT.SP_NAME'); ?></th><th style="width:12%;"><?php echo cc_t('MQTT.SP_BEREICH'); ?></th><th style="width:8%;"><?php echo cc_t('MQTT.SP_RETAIN'); ?></th><th style="width:8%;"><?php echo cc_t('MQTT.SP_HAKEN'); ?></th><th><?php echo cc_t('TEXT.T098'); ?></th></tr>
<?php foreach ($cc_mn as $cc_mz) { ?>
<tr><td><span class="sm-mono"><?= cc_e($cc_mz['voll']) ?></span></td><td><span class="sm-mono"><?= cc_e($cc_mz['name']) ?></span></td>
<td><?= cc_e($cc_mz['bereich'] !== '' ? $cc_mz['bereich'] : cc_t('MQTT.A_TEXT')) ?></td>
<td><?= cc_e($cc_jn($cc_mz['retained'])) ?></td>
<td><?= cc_e($cc_mz['haken'] ? ($cc_mz['noncached'] ? cc_t('MQTT.A_HAKEN_N') : cc_t('TEST.A_JA_KURZ')) : '–') ?></td>
<td><?= cc_e($cc_mz['bedeutung']) ?></td></tr>
<?php } ?>
</table>
</div>
<?php $cc_ret = count(array_filter($cc_mn, function ($z) { return $z['retained']; })); ?>
<div class="sm-small"><?= cc_e(sprintf(cc_t('MQTT.A_ZAEHLUNG6B'), count($cc_mn), $cc_ret, count(cc_mqtt_hakenliste($cc_cfg)), count($cc_geraete))) ?></div>
<?php if (!$cc_geraete) { ?><div class="sm-small"><?php echo cc_t('MQTT.A_OHNE_GERAETE'); ?></div><?php } ?>
<div class="sm-small"><?= sprintf(cc_t('SPERRE.MQTT_HINWEIS'), cc_e($cc_praefix)) ?></div>

<h2><?php echo cc_t('TEXT.T104'); ?></h2>
<div class="sm-small"><?= sprintf(cc_t('MQTT.BEFEHLE_KURZ'), cc_e($cc_praefix)) ?></div>
<details><summary><?php echo cc_t('MQTT.ALT_AUF'); ?></summary>
<table class="sm-tbl">
<tr><th style="width:22%;"><?php echo cc_t('TEXT.T105'); ?></th><th style="width:10%;"><?php echo cc_t('MQTT.SP_ART'); ?></th><th><?php echo cc_t('TEXT.T098'); ?></th></tr>
<?php foreach (cc_themen()['befehle'] as $cc_e1) { ?>
<tr><td><span class="sm-mono"><?= cc_e($cc_e1['schluessel']) ?></span></td>
<td><?= cc_e($cc_e1['art']) ?></td>
<td><?= cc_e(cc_thema_lang('cmd_' . $cc_e1['schluessel'])) ?></td></tr>
<?php } ?>
</table>
<div class="sm-small"><?php echo cc_t('TEXT.T165'); ?> <span class="sm-mono"><?= cc_e($cc_praefix) ?><?php echo cc_t('TEXT.T166'); ?></span><?php echo cc_t('TEXT.T167'); ?> <span class="sm-mono"><?php echo cc_t('TEXT.T168'); ?></span> <?php echo cc_t('TEXT.T169'); ?></div>
<div class="sm-alert sm-info sm-small"><?= sprintf(cc_t('MQTT.H_SAMMELZIEL'), cc_e($cc_praefix)) ?></div>
</details>

</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="sm-seite<?= $cc_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">

<h2><?php echo cc_t('TEXT.T037'); ?></h2>
<div class="sm-small"><?php echo cc_t('TEXT.T038'); ?></div>
<?php /* Gruppe 6b / MQTT (1.3.24; Entwurf cc 2.5, Bauvorschrift Gruppe 1 Nachtrag 2): MQTT ist der Weg des LoxBerry-Plugins
   Musterprojekts, fuer Werte UND Befehle. Schritt 1 das Gateway nach seiner Fassung (V1: die Abo-Datei liegt bei; V2 und
   "nicht feststellbar": die Hakenliste aus cc_mqtt_hakenliste(), server/online mit "Noncached"), Schritt 2 die
   Namenstabelle (KEINE Importvorlage fuer Werte mehr - die localhost-Vorlage entfaellt, Pruefbericht E2), Schritt 3 die
   Befehle aus der Befehlsbruecke mit der Vorlage "MQTT-Befehle" (mit Sperre, V3), Schritt 4 die Ausfallerkennung,
   Schritt 5 die Baustein-Listen (wortgleich Musterprojekt/baustein_listen.txt), Schritt 6 die Gegenprobe. Der alte Weg
   (Ausgang "Chromecast 4 Lox" mit cmd-Themen, UDP) steht darunter, zugeklappt. */
$cc_ln = cc_mqtt_namen($cc_cfg);
$cc_lh = cc_mqtt_hakenliste($cc_cfg);
$cc_lb = cc_befehle_ausgerollt($cc_geraete);
$cc_lgw = cc_gateway_fassung();
list(, $cc_labo_da) = cc_abo_datei($cc_praefix);
$cc_jn = function ($w) { return cc_t($w ? 'TEST.A_JA_KURZ' : 'TEST.A_NEIN_KURZ'); };
/* Tmc der Ausfallerkennung (E11): dreimal der Takt, mindestens 180 s - der Dienst sendet direkt am Broker (paho), nicht
 * ueber UDP (gemeinsam E10). Bis 1.3.23 mindestens 60 s. */
$cc_aender = max(180, 3 * (int) cc_cfg($cc_cfg, 'intervall', '10')); ?>
<div class="sm-step"><b>0.</b> <?php echo cc_t('LOX.S0'); ?></div>
<div class="sm-step"><b>1.</b> <?php echo cc_t('LOX.S1'); ?>
<?php if ($cc_lgw !== 2 && $cc_lgw < 3) { /* V1 oder nicht feststellbar */ ?>
<?php if ($cc_labo_da || $cc_p['home'] === '') { ?>
<div class="sm-hinweis"><?= sprintf(cc_t('LOX.S1_V1'), cc_e(cc_abo_zeile($cc_praefix))) ?></div>
<?php } else { ?>
<div class="sm-warnung"><?= sprintf(cc_t('LOX.S1_V1_FEHLT'), cc_e(cc_abo_zeile($cc_praefix))) ?></div>
<?php } ?>
<?php } ?>
<?php if ($cc_lgw !== 1) { /* V2 oder nicht feststellbar: die Hakenliste, aufgeklappt */ ?>
<div class="sm-hinweis"><?= sprintf(cc_t('LOX.S1_V2'), cc_e($cc_praefix), count($cc_lh)) ?></div>
<details open><summary><?= cc_e(sprintf(cc_t('LOX.HAKEN_AUF'), count($cc_lh))) ?></summary>
<div class="sm-breit">
<table class="sm-tbl" id="cc_hakenliste">
<tr><th style="width:5%">#</th><th style="width:34%"><?php echo cc_t('TEXT.T097'); ?></th><th style="width:30%"><?php echo cc_t('MQTT.SP_NAME'); ?></th><th style="width:10%"><?php echo cc_t('LOX.T_NONCACHED'); ?></th><th><?php echo cc_t('TEXT.T098'); ?></th></tr>
<?php foreach ($cc_lh as $cc_hi => $cc_hz) { ?>
<tr><td><?= (int) $cc_hi + 1 ?></td><td><span class="sm-mono"><?= cc_e($cc_hz['voll']) ?></span></td><td><span class="sm-mono"><?= cc_e($cc_hz['name']) ?></span></td><td><?= cc_e($cc_jn($cc_hz['noncached'])) ?></td><td><?= cc_e($cc_hz['bedeutung']) ?></td></tr>
<?php } ?>
</table>
</div>
</details>
<div class="sm-small"><?php echo cc_t('LOX.S1_NONCACHED'); ?></div>
<?php } ?>
<div class="sm-small"><?php echo cc_t('LOX.S1_RECONNECT'); ?></div>
<?php if (cc_cfg($cc_cfg, 'mqtt_ein', '1') !== '1') { ?><div class="sm-warnung"><?php echo cc_t('TEXT.T077'); ?></div><?php } ?>
</div>

<div class="sm-step"><b>2.</b> <?php echo cc_t('LOX.S2'); ?>
<?php if (!$cc_geraete) { ?>
<div class="sm-alert sm-err"><?php echo cc_t('LOX.S2_KEINE_GERAETE'); ?></div>
<?php } ?>
<div class="sm-breit">
<table class="sm-tbl" id="cc_namenstabelle">
<tr><th style="width:5%">#</th><th style="width:36%"><?php echo cc_t('MQTT.SP_NAME'); ?></th><th style="width:13%"><?php echo cc_t('LOX.T_GERAET'); ?></th><th style="width:12%"><?php echo cc_t('MQTT.SP_BEREICH'); ?></th><th style="width:9%"><?php echo cc_t('LOX.T_ART'); ?></th><th><?php echo cc_t('TEXT.T098'); ?></th></tr>
<?php $cc_ni = 0;
foreach ($cc_ln as $cc_nz) {
    if (!$cc_nz['haken']) { continue; }
    $cc_ni++; ?>
<tr><td><?= (int) $cc_ni ?></td><td><span class="sm-mono"><?= cc_e($cc_nz['name']) ?></span> <button data-role="none" type="button" class="sm-kopie" data-kopie="<?= cc_e($cc_nz['name']) ?>"><?php echo cc_t('LOX.KOPIEREN'); ?></button></td><td><?= cc_e($cc_nz['geraet'] !== '' ? $cc_nz['geraet'] . ' (' . cc_befehl_kennung(cc_thema($cc_nz['geraet'])) . ')' : cc_t('LOX.T_DIENST')) ?></td><td><?= cc_e($cc_nz['bereich']) ?></td><td><?= cc_e(cc_t($cc_nz['art'] === 'text' ? 'LOX.ART_TEXT' : 'LOX.ART_ZAHL')) ?></td><td><?= cc_e($cc_nz['bedeutung']) ?></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-small"><?php echo cc_t('LOX.S2_DIGITAL'); ?></div>
<div class="sm-small"><?php echo cc_t('LOX.S2_TEXT'); ?></div>
<div class="sm-small"><?php echo cc_t('LOX.S2_FEHLWERT'); ?></div>
<div class="sm-small"><?php echo cc_t('LOX.S2_RECHTE'); ?></div>
<div class="sm-small"><?php echo cc_t('LOX.S2_UMBENENNEN'); ?></div>
<?php $cc_gw_w = cc_geraete_warnungen($cc_geraete); if ($cc_gw_w) { ?>
<div class="sm-warnung"><?= implode('<br>', $cc_gw_w) ?></div>
<?php } ?>
<script>
(function () {
    document.querySelectorAll('.sm-kopie').forEach(function (k) {
        k.addEventListener('click', function () {
            var n = k.getAttribute('data-kopie');
            var fertig = function () { k.textContent = <?= json_encode(cc_t('LOX.KOPIERT')) ?>; };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(n).then(fertig);
            } else {
                var f = document.createElement('textarea');
                f.value = n; document.body.appendChild(f); f.select();
                try { document.execCommand('copy'); fertig(); } catch (e) {}
                document.body.removeChild(f);
            }
        });
    });
})();
</script>
</div>

<div class="sm-step" id="befehle_loxone"><b>3.</b> <?= sprintf(cc_t('LOX.S3'), cc_e($cc_praefix . '/befehl/#')) ?>
<?php if ((string) cc_cfg($cc_cfg, 'befehle_mqtt_ein', '0') !== '1' || (string) cc_cfg($cc_cfg, 'befehle_mqtt_steuern', '0') !== '1') { ?>
<div class="sm-warnung"><?php echo cc_t('LOX.S3_HAKEN'); ?></div>
<?php } ?>
<?php if ($cc_lb === null) { ?>
<div class="sm-warnung"><?php echo cc_t('LOX.S3_KEINE'); ?></div>
<?php } else { ?>
<div class="sm-breit">
<table class="sm-tbl" id="cc_befehle">
<tr><th style="width:24%"><?php echo cc_t('BEFEHL.T_TITEL'); ?></th><th style="width:34%"><?php echo cc_t('BEFEHL.T_THEMA'); ?></th><th style="width:12%"><?php echo cc_t('BEFEHL.T_WERT'); ?></th><th style="width:10%"><?php echo cc_t('BEFEHL.T_EINGREIFEND'); ?></th><th style="width:8%"><?php echo cc_t('BEFEHL.T_SPERRE'); ?></th><th><?php echo cc_t('BEFEHL.T_QUITTUNG'); ?></th></tr>
<?php foreach ($cc_lb as $cc_bz) { ?>
<tr><td><?= cc_e($cc_bz['titel']) ?><?= $cc_bz['geraet'] !== '' ? ' – ' . cc_e($cc_bz['geraet']) : '' ?></td><td><span class="sm-mono"><?= cc_e($cc_bz['thema']) ?></span></td><td><?= cc_e($cc_bz['wert']) ?></td><td><?= cc_e($cc_jn($cc_bz['eingreifend'])) ?></td><td><?= (int) $cc_bz['sperre_s'] ?> s</td><td><?= cc_e(cc_t($cc_bz['quittung'] === 'ausgefuehrt' ? 'BEFEHL.Q_AUSGEFUEHRT' : 'BEFEHL.Q_UEBERGEBEN')) ?></td></tr>
<?php } ?>
</table>
</div>
<?php } ?>
<div class="sm-small"><?php echo cc_t('LOX.S3_NICHTS'); ?></div>
<div class="sm-small"><?php echo cc_t('LOX.S3_ANSAGE'); ?></div>
<div class="sm-small"><?php echo cc_t('LOX.S3_SCHRITT'); ?></div>
<div class="sm-small"><?php echo cc_t('BEFEHL.QUITTUNG'); ?></div>
<form method="post" action="index.php">
<input data-role="none" type="hidden" name="fmt" value="<?= cc_e($cc_fmt) ?>"><input data-role="none" type="hidden" name="activetab" value="tab-loxone">
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?php echo cc_t('LEGENDE.TECHNIK_DATEI'); ?></span>
</div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-technik" type="submit" name="download" value="bruecke"><?php echo cc_t('BEFEHL.K_VORLAGE'); ?></button>
</div>
</form>
<div class="sm-small"><?= cc_e(sprintf(cc_t('LOX.S3_VORLAGE'), $cc_ip, $cc_udpin ? (int) $cc_udpin : 11884)) ?></div>
<?php if (!$cc_geraete) { ?><div class="sm-small"><?php echo cc_t('TEXT.T083'); ?></div><?php } ?>
</div>

<div class="sm-step"><b>4.</b> <?= sprintf(cc_t('LOX.S4'), cc_e(cc_gateway_name($cc_praefix, 'server/zaehler')), (int) $cc_aender, cc_e(cc_gateway_name($cc_praefix, 'server/online'))) ?></div>

<h2><?php echo cc_t('TEXT.T106'); ?></h2>
<div class="sm-small"><?php echo cc_t('TEXT.S_LOGIK_EINLEITUNG'); ?></div>
<?php /* Gruppe 6b (1.3.24): die zwei Seiten "Chromecast4lox" (44 Zeilen) und "Chromecast4lox Steuerung" (37 Zeilen) aus dem
         LoxBerry-Plugins Musterprojekt, wortgleich Musterprojekt/baustein_listen.txt (Stand 22.10.2026) - Werte als
         einfache Virtuelle Eingaenge mit Gateway-Namen, Befehle am gemeinsamen Ausgang "LoxBerry MQTT-Gateway". Jede Zelle
         ist ein Schluessel [BAUSTEIN] <Seite><Nr>_TYP|_NAME|_PARAM|_VERB (fertiges HTML), am Beispielgeraet "Wohnzimmer"
         des Musterprojekts; die Namen fuer eigene Lautsprecher und ein eigenes Praefix stehen in Schritt 2 und 3. Bis
         1.3.23 standen hier Platzhalter <G> und die Namen der Importvorlagen. */
$cc_bs_k = function ($s) { return cc_t('BAUSTEIN.' . $s); };
$cc_bs_seiten = array(
    array('kopf' => 'UE_WERTE', 'seite' => 'Chromecast4lox', 'bild' => 'einbindung_loxone.png', 'zusatz' => 'TEXT.S_ZU_6',
        'kuerzel' => 'B', 'zeilen' => 44,
        'schritte' => array(
            sprintf(cc_t('BAUSTEIN.A_S1'), cc_t('REITER.EINSTELLUNGEN'), cc_t('REITER.TEST'), cc_t('TEXT.T174')),
            sprintf(cc_t('BAUSTEIN.A_S2'), cc_t('REITER.MQTT')),
            cc_t('BAUSTEIN.A_S3'),
            sprintf(cc_t('BAUSTEIN.A_S4'), cc_t('LOX.KOPIEREN')),
            sprintf(cc_t('BAUSTEIN.A_S5'), cc_t('BEFEHL.K_VORLAGE')),
            cc_t('BAUSTEIN.A_S6'),
            cc_t('BAUSTEIN.A_S7'),
            sprintf(cc_t('BAUSTEIN.A_S8'), cc_t('TEXT.T114')),
            cc_t('BAUSTEIN.A_S9'),
        ),
        'hinweise' => array(
            cc_t('BAUSTEIN.H_MUSIK'),
            cc_t('BAUSTEIN.H_ODER'),
            cc_t('BAUSTEIN.H_KONSTANTE'),
            cc_t('BAUSTEIN.H_EN_NEGIERT'),
            cc_t('BAUSTEIN.H_RUHE'),
            cc_t('BAUSTEIN.H_RUECKMELDUNG'),
        )),
    array('kopf' => 'UE_STEUERUNG', 'seite' => 'Chromecast4lox Steuerung', 'bild' => 'einbindung_loxone_steuerung.png',
        'kuerzel' => 'S', 'zeilen' => 37,
        'schritte' => array(
            cc_t('BAUSTEIN.B_S1'),
            sprintf(cc_t('BAUSTEIN.B_S2'), cc_t('REITER.EINSTELLUNGEN'), cc_t('FAV.H'), cc_t('RUHE.H')),
            sprintf(cc_t('BAUSTEIN.B_S3'), cc_t('BEFEHL.K_VORLAGE')),
            cc_t('BAUSTEIN.B_S4'),
            cc_t('BAUSTEIN.B_S5'),
            cc_t('BAUSTEIN.B_S6'),
            cc_t('BAUSTEIN.B_S7'),
            sprintf(cc_t('BAUSTEIN.B_S8'), cc_t('TEXT.T114')),
            cc_t('BAUSTEIN.B_S9'),
        ),
        'hinweise' => array(
            cc_t('BAUSTEIN.H_REGLER'),
            cc_t('BAUSTEIN.H_ALLE'),
            cc_t('BAUSTEIN.H_RADIO'),
            sprintf(cc_t('BAUSTEIN.H_ANSAGE'), cc_t('REITER.EINSTELLUNGEN'), cc_t('TTS.L_FORTSETZEN')),
            cc_t('BAUSTEIN.H_SPERRE'),
            cc_t('BAUSTEIN.H_OHNE_APP'),
        )),
);
?>
<?php foreach ($cc_bs_seiten as $cc_bs_s) { ?>
<h3 class="sm-h3"><?= $cc_bs_k($cc_bs_s['kopf']) ?></h3>
<div class="sm-hinweis"><b><?= $cc_bs_k('SO_TITEL') ?></b>
<ol class="sm-schritte">
<?php foreach ($cc_bs_s['schritte'] as $cc_bs_x) { ?>
<li><?= $cc_bs_x ?></li>
<?php } ?>
</ol></div>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th>#</th><th><?php echo cc_t('TEXT.T111'); ?></th><th><?php echo cc_t('TEXT.T112'); ?></th><th><?php echo cc_t('TEXT.T113'); ?></th><th><?php echo cc_t('TEXT.T114'); ?></th></tr>
<?php for ($cc_bs_n = 1; $cc_bs_n <= $cc_bs_s['zeilen']; $cc_bs_n++) { $cc_bs_r = $cc_bs_s['kuerzel'] . $cc_bs_n; ?>
<tr><td><?= (int) $cc_bs_n ?></td><td><?= $cc_bs_k($cc_bs_r . '_TYP') ?></td><td><?= $cc_bs_k($cc_bs_r . '_NAME') ?></td><td><?= $cc_bs_k($cc_bs_r . '_PARAM') ?></td><td><?= $cc_bs_k($cc_bs_r . '_VERB') ?></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-warnung"><b><?= $cc_bs_k('H_TITEL') ?></b>
<ul class="sm-schritte">
<?php foreach ($cc_bs_s['hinweise'] as $cc_bs_x) { ?>
<li><?= $cc_bs_x ?></li>
<?php } ?>
</ul></div>
<?php if (isset($cc_bs_s['zusatz'])) { ?>
<div class="sm-small"><?php echo cc_t($cc_bs_s['zusatz']); ?></div>
<?php } ?>
<figure class="sm-bild">
<img src="<?= cc_e($cc_bs_s['bild']) ?>" alt="<?= cc_e(sprintf(cc_t('TEXT.BILD_ALT'), $cc_bs_s['seite'])) ?>" loading="lazy">
<figcaption><?= cc_e(sprintf(cc_t('TEXT.BILD_UNTERSCHRIFT'), $cc_bs_s['seite'])) ?></figcaption>
</figure>
<div class="sm-small"><?php echo cc_t('TEXT.MUSTERPROJEKT'); ?></div>
<?php } ?>

<div class="sm-step"><b>6.</b> <?= sprintf(cc_t('LOX.S6'), cc_e(cc_gateway_name($cc_praefix, 'server/zaehler')), (int) cc_cfg($cc_cfg, 'intervall', '10')) ?></div>

<details id="alter_weg"><summary><?php echo cc_t('LOX.ALT_TITEL'); ?></summary>
<div class="sm-small"><?php echo cc_t('LOX.ALT_TEXT'); ?></div>
<h3 class="sm-h3"><?php echo cc_t('TEXT.T070'); ?></h3>
<div class="sm-small" style="margin-bottom:8px;">
<?php echo cc_t('TEXT.T071'); ?> <b><?php echo cc_t('TEXT.T072'); ?></b>
(<span class="sm-mono"><?php echo cc_t('TEXT.T073'); ?><?= cc_e($cc_ip) ?>/<?= $cc_udpin ? (int) $cc_udpin : '&lt;Port&gt;' ?></span><?php echo cc_t('TEXT.T074'); ?>
</div>
<?php if ($cc_geraete) { ?>
<div class="sm-small"><?= cc_e(sprintf(cc_t('LOX.ALT_ZAHL'), count($cc_geraete), implode(', ', $cc_geraete), count(cc_vorlagen_befehle()))) ?> <?php echo cc_t('TEXT.S_IMPORT_NEU'); ?> <?= cc_e(sprintf(cc_t('TEXT.S_SCHRITT_VORLAGE'), cc_cfg($cc_cfg, 'lautstaerke_schritt', '5'))) ?></div>
<?php } ?>
<form method="post" action="index.php">
<input data-role="none" type="hidden" name="fmt" value="<?= cc_e($cc_fmt) ?>"><input data-role="none" type="hidden" name="activetab" value="tab-loxone">
<h3 class="sm-h3"><?php echo cc_t('TEXT.T089'); ?></h3>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-technik" type="submit" name="download" value="mqtt_out"><?php echo cc_t('TEXT.T091'); ?></button>
</div>
<h3 class="sm-h3"><?php echo cc_t('TEXT.T092'); ?></h3>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-technik" type="submit" name="download" value="udp_out"><?php echo cc_t('TEXT.T093'); ?></button>
</div>
<div class="sm-small"><?php echo cc_t('TEXT.T094'); ?> <?= cc_e(cc_cfg($cc_cfg, 'udp_port', '7090')) ?> <?php echo cc_t('TEXT.T095'); ?></div>
</form>
<div class="sm-small"><?php echo cc_t('TEXT.T165'); ?> <span class="sm-mono"><?= cc_e($cc_praefix) ?><?php echo cc_t('TEXT.T166'); ?></span><?php echo cc_t('TEXT.T167'); ?> <span class="sm-mono"><?php echo cc_t('TEXT.T168'); ?></span> <?php echo cc_t('TEXT.T169'); ?></div>
<div class="sm-small"><?php echo cc_t('MQTT.H_VERWEIS'); ?></div>
</details>

<?php /* Ansage-3: die Schnittstelle fuer andere Plugins - dieselbe wie Alexa-NG. */
$cc_s_an = cc_cfg($cc_cfg, 'sprechen_ein', '0') === '1'; ?>
<h2 id="sprechen-einbindung"><?php echo cc_t('SPRECHEN.E_H'); ?></h2>
<div class="sm-small"><?php echo cc_t('SPRECHEN.E_TEXT'); ?></div>
<div class="sm-alert <?= $cc_s_an ? 'sm-info' : 'sm-warn' ?>"><?= $cc_s_an ? cc_t('SPRECHEN.E_AN') : cc_t('SPRECHEN.E_AUS') ?></div>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th style="width:22%;"><?php echo cc_t('SPRECHEN.E_SP_WAS'); ?></th><th><?php echo cc_t('SPRECHEN.E_SP_WERT'); ?></th></tr>
<tr><td><?php echo cc_t('SPRECHEN.E_Z_ADRESSE'); ?></td><td><span class="sm-mono"><?= cc_e(cc_sprechen_adresse()) ?></span> <?php echo cc_t('SPRECHEN.E_Z_ADRESSE_TEXT'); ?></td></tr>
<tr><td><?php echo cc_t('SPRECHEN.E_Z_FELDER'); ?></td><td><?php echo cc_t('SPRECHEN.E_Z_FELDER_TEXT'); ?></td></tr>
<tr><td><?php echo cc_t('SPRECHEN.E_Z_GERAET'); ?></td><td><?= sprintf(cc_t('SPRECHEN.E_Z_GERAET_TEXT'), $cc_geraete ? cc_e(implode(', ', $cc_geraete)) : cc_e(cc_t('SPRECHEN.E_KEINE'))) ?></td></tr>
<tr><td><?php echo cc_t('SPRECHEN.E_Z_ANTWORT'); ?></td><td><?php echo cc_t('SPRECHEN.E_Z_ANTWORT_TEXT'); ?></td></tr>
<tr><td><?php echo cc_t('SPRECHEN.E_Z_CODES'); ?></td><td><?php echo cc_t('SPRECHEN.E_Z_CODES_TEXT'); ?></td></tr>
<tr><td><?php echo cc_t('SPRECHEN.E_Z_SELBSTTEST'); ?></td><td><?php echo cc_t('SPRECHEN.E_Z_SELBSTTEST_TEXT'); ?></td></tr>
<tr><td><?php echo cc_t('SPRECHEN.E_Z_STATUS'); ?></td><td><?php echo cc_t('SPRECHEN.E_Z_STATUS_TEXT'); ?></td></tr>
<tr><td><?php echo cc_t('SPRECHEN.E_Z_GRENZEN'); ?></td><td><?= cc_e(sprintf(cc_t('SPRECHEN.E_Z_GRENZEN_TEXT'), (int) cc_cfg($cc_cfg, 'sprechen_stunde', '60'))) ?></td></tr>
</table>
</div>
<div class="sm-small"><?php echo cc_t('SPRECHEN.E_ALEXA'); ?></div>
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
<?php /* Ansage-3: Testansage ueber dieselbe Funktion wie der Endpunkt. */ ?>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="fmt" value="<?= cc_e($cc_fmt) ?>"><input data-role="none" type="hidden" name="activetab" value="tab-test">
<?php if ($cc_geraete) { ?><select data-role="none" name="testgeraet" style="width:auto;margin-right:8px;">
<option value=""><?= cc_e(cc_t('SPRECHEN.O_STANDARD')) ?></option>
<?php foreach ($cc_geraete as $g) { ?><option value="<?= cc_e($g) ?>"><?= cc_e($g) ?></option><?php } ?>
<option value="<?= cc_e(cc_sammelziel()) ?>"><?= cc_e(cc_t('SPRECHEN.O_ALLE')) ?></option>
</select><?php } ?>
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="sprechen"><?php echo cc_t('SPRECHEN.K_TESTANSAGE'); ?></button></form>
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
<?php /* Gruppe 6b (1.3.24): die Befehlsbruecke schreibt in ihre eigene Datei befehle_start.log (dieselbe wie
         befehlsbruecke.sh) - in <plugin>.log schreibt allein der Dienst. */
$cc_bblog = $cc_p['logdir'] . '/befehle_start.log';
$cc_bbz = cc_log_tail($cc_bblog, 200); ?>
<h2><?php echo cc_t('BEFEHL.H_LOG'); ?></h2>
<div class="sm-small"><?php echo cc_t('TEXT.T183'); ?> <span class="sm-mono"><?= cc_e($cc_bblog) ?></span> &middot; <?php echo cc_t('TEXT.NEUESTE_ZEILE'); ?></div>
<?php if ($cc_bbz) { ?>
<div class="sm-log"><?php foreach ($cc_bbz as $z) { echo cc_e($z) . "\n"; } ?></div>
<?php } else { ?>
<div class="sm-small"><?php echo cc_t('BEFEHL.LOG_LEER'); ?></div>
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
/* Ansage-3: ein Sprechtoken im Browser wuerfeln (32 Hexzeichen). Der Server
 * zeigt es nie; hier steht es einmal sichtbar im Feld, zum Abschreiben in die
 * anderen Plugins, und wird mit "Speichern" uebernommen. */
function ccTokenWuerfeln() {
    var f = document.getElementById('cc_sprechtoken');
    if (!f || !window.crypto || !window.crypto.getRandomValues) { return; }
    var b = new Uint8Array(16), s = '', i;
    window.crypto.getRandomValues(b);
    for (i = 0; i < b.length; i++) { s += ('0' + b[i].toString(16)).slice(-2); }
    f.value = s;
    f.type = 'text';
    f.focus();
    f.select();
}
</script>
<?php
if ($cc_frame) {
    LBWeb::lbfooter();
}
