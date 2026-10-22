<?php
/**
 * bin/befehle_zuordnung.php - Zuordnungstabelle der Befehlsbruecke, Linie Chromecast 4 Lox NG.
 *
 * Gruppe 6b / MQTT (1.3.24; Bauvorschrift wz_bild/GRUPPE6B_BAUVORSCHRIFT.md, Entwurf Musterprojekt/entwurf_gruppe6_cc.txt
 * Abschnitt 3.3, ENTSCHEIDUNGEN_GRUPPE6_CHROMECAST.md; Muster vb_g6_ax_bau/bin/befehle_zuordnung.php und Govee 0.9.30).
 * Die Bruecke selbst (bin/befehlsbruecke.php und .sh) ist byte-gleich die Stammfassung Werkzeuge/gemeinsam/ - linieneigen
 * ist nur diese Datei.
 *
 * Themen: <praefix>/befehl/<geraet>/<befehl> und <praefix>/befehl/sperre. <geraet> ist der BEFEHLSNAME eines Lautsprechers
 * (cc_befehl_kennung(): sein MQTT-Thema klein, "-" wird "_", hoechstens 40 Zeichen; aus "Wohnzimmer" wird "wohnzimmer")
 * oder "alle". Die Werte-Themen behalten ihre Schreibweise (Hausherr 22.10.2026, Punkt 2).
 *
 * Regeln (Entwurf gemeinsam 2.5): die Datei gibt ein Feld zurueck, setzt keine Konfiguration und schreibt nichts. 'tun'
 * ruft cc_befehl_bruecke() in cc_lib.php: sie legt einen Auftrag (Art "befehl") in den Eingang des laufenden Python-Dienstes
 * (data/plugins/<ordner>/sprechen/), und der Dienst fuehrt ihn an DERSELBEN Stelle aus wie den alten cmd-Weg und UDP
 * (Dienst.befehl_ausfuehren): Sperre aus Loxone, die Gleichwert-Bremse X-7, Obergrenze und Ruhezeit bleiben die EINE Pruefung
 * im Dienst. Rueckgabe array(bool ok, string grund). Eine Stundengrenze gibt es hier nicht (E8): sie gehoert dem Endpunkt fuer
 * andere Plugins.
 *
 * Haken (Hausherr 22.10.2026, Punkt 1 und 3): "Befehle ueber MQTT annehmen" (befehle_mqtt_ein, ab Werk aus) schaltet die
 * Bruecke; ein angehaltenes Plugin (enabled=0) nimmt nichts an. Eingreifend - alles, was Ton erzeugt, lauter macht oder eine
 * App des Nutzers beendet: play, quit, volume, volume_step, volume_up, mute, play_favorit, tts - braucht zusaetzlich
 * "Lautsprecher ueber MQTT steuern" (befehle_mqtt_steuern, ab Werk aus). Frei: pause, stop, volume_down, next, prev, seek,
 * tts_stop und sperre. sperre_ein, Obergrenze und Ruhezeit prueft weiter der Dienst selbst.
 *
 * Quittung "uebergeben": der Dienst reiht ein, ausgefuehrt wird im Arbeitsfaden des Lautsprechers (Verbindungsaufbau bis
 * ~18 s, Ansage bis 60 s je Teil); das Ergebnis steht danach in <praefix>/<Geraet>/last_error. "sperre" ist synchron.
 */
if (!function_exists('cc_befehl_bruecke')) {
    $cc_bb_kand = array();
    if (basename(dirname(__DIR__)) === 'plugins') {
        $cc_bb_kand[] = dirname(dirname(dirname(__DIR__))) . '/webfrontend/htmlauth/plugins/' . basename(__DIR__) . '/cc_lib.php';
    }
    $cc_bb_kand[] = dirname(__DIR__) . '/webfrontend/htmlauth/cc_lib.php';
    foreach ($cc_bb_kand as $cc_bb_k) {
        if (is_file($cc_bb_k)) { require_once $cc_bb_k; break; }
    }
}
$cc_bb_taste = 'Ereignisse; zweimal Pause/Weiter binnen einer Minute ist normale Bedienung, ein Doppelklick schadet nicht '
    . '(Entwurf cc 3.3).';
$cc_bb_regler = 'Schieberegler und Schalter senden Folgen; die Bruecke legt im Lesepuffer zusammen, die Gleichwert-Bremse X-7 '
    . '(60 s, gleicher wirksamer Pegel) und die Obergrenze prueft der Dienst (Entwurf cc 3.3).';
$cc_bb_favorit = 'Radiotasten 3 - Aus - 3 binnen einer Minute muessen ankommen; 0 tut nichts (Entwurf cc 3.3).';
$cc_bb_ansage = 'Faengt Prellen der Klingel ab; ein zweites Klingeln nach 10 s soll wieder angesagt werden. Fuer Ansagen aus '
    . 'Loxone hat der Dienst keine eigene Wiederholbremse - die 30 s (CC_SPRECHEN_FENSTER) gelten nur am Endpunkt (Entwurf cc 3.3).';
$cc_bb_sperre = 'Kein Geraetekontakt; ein gleicher Wert aendert in loxsperre.json nur "zuletzt" (Entwurf cc 3.3).';
// Ein Eintrag: an den Dienst geht befehl und - fuer puls - ein fester Wert ("1"; lauter/leiser "" = Schrittweite des Plugins).
$cc_bb = function ($befehl, $art, $eingreifend, $titel, $kachel, $grund, $fest = null, array $extra = array()) {
    return array_merge(array('art' => $art, 'eingreifend' => $eingreifend, 'sperre_s' => 0, 'begruendung' => $grund,
                             'titel' => $titel, 'kachel' => $kachel, 'quittung' => 'uebergeben',
                             'tun' => function ($w, $t) use ($befehl, $fest) {
                                 return cc_befehl_bruecke((string) $t['geraet'], $befehl, $fest === null ? (string) $w : $fest);
                             }), $extra);
};
return array(
    'fassung' => 1,
    'linie'   => 'chromecast-4lox-ng',
    'plugin'  => 'Chromecast 4 Lox NG',
    'geladen' => function_exists('cc_befehl_bruecke') && function_exists('cc_config_read')
                 && function_exists('cc_befehle_einstellungen') && function_exists('cc_bb_protokoll'),
    'zweiter_haken' => 'befehle_mqtt_steuern',
    'teile' => array('geraet' => '[a-z0-9_]{1,40}'),
    'einstellungen' => function () {
        return cc_befehle_einstellungen();
    },
    'protokoll' => function ($stufe, $text) {
        cc_bb_protokoll($stufe, $text);
    },
    'befehle' => array(
        '{geraet}/play'         => $cc_bb('play', 'puls', true, 'Chromecast Wiedergabe (MQTT)', 'BEFEHL.K_PLAY', $cc_bb_taste, '1'),
        '{geraet}/pause'        => $cc_bb('pause', 'puls', false, 'Chromecast Pause (MQTT)', 'BEFEHL.K_PAUSE', $cc_bb_taste, '1'),
        '{geraet}/stop'         => $cc_bb('stop', 'puls', false, 'Chromecast Beenden (MQTT)', 'BEFEHL.K_STOP', $cc_bb_taste, '1'),
        '{geraet}/quit'         => $cc_bb('quit', 'puls', true, 'Chromecast App schließen (MQTT)', 'BEFEHL.K_QUIT', $cc_bb_taste, '1'),
        '{geraet}/volume'       => $cc_bb('volume', 'ganz', true, 'Chromecast Lautstärke (MQTT)', 'BEFEHL.K_VOLUME', $cc_bb_regler,
                                          null, array('min' => 0, 'max' => 100)),
        '{geraet}/volume_step'  => $cc_bb('volume_step', 'ganz', true, 'Chromecast Lautstärke um Schritt (MQTT)', 'BEFEHL.K_VOLUME_STEP',
                                          $cc_bb_regler, null, array('min' => -100, 'max' => 100)),
        '{geraet}/volume_up'    => $cc_bb('volume_up', 'puls', true, 'Chromecast lauter (MQTT)', 'BEFEHL.K_VOLUME_UP', $cc_bb_taste, ''),
        '{geraet}/volume_down'  => $cc_bb('volume_down', 'puls', false, 'Chromecast leiser (MQTT)', 'BEFEHL.K_VOLUME_DOWN', $cc_bb_taste, ''),
        '{geraet}/mute'         => $cc_bb('mute', 'schalter', true, 'Chromecast stumm (MQTT)', 'BEFEHL.K_MUTE', $cc_bb_regler),
        '{geraet}/next'         => $cc_bb('next', 'puls', false, 'Chromecast nächster Titel (MQTT)', 'BEFEHL.K_NEXT', $cc_bb_taste, '1'),
        '{geraet}/prev'         => $cc_bb('prev', 'puls', false, 'Chromecast voriger Titel (MQTT)', 'BEFEHL.K_PREV', $cc_bb_taste, '1'),
        '{geraet}/seek'         => $cc_bb('seek', 'ganz', false, 'Chromecast springen (MQTT)', 'BEFEHL.K_SEEK', $cc_bb_regler,
                                          null, array('min' => 0, 'max' => 86400)),
        '{geraet}/play_favorit' => $cc_bb('play_favorit', 'ganz', true, 'Chromecast Favorit (MQTT)', 'BEFEHL.K_FAVORIT', $cc_bb_favorit,
                                          null, array('min' => 0, 'max' => 99)),
        '{geraet}/tts'          => $cc_bb('tts', 'text', true, 'Chromecast Ansage (MQTT)', 'BEFEHL.K_TTS', $cc_bb_ansage,
                                          null, array('max_byte' => 4000, 'sperre_s' => 10)),
        '{geraet}/tts_stop'     => $cc_bb('tts_stop', 'puls', false, 'Chromecast Ansage abbrechen (MQTT)', 'BEFEHL.K_TTS_STOP',
                                          $cc_bb_taste, '1'),
        'sperre' => array('art' => 'schalter', 'eingreifend' => false, 'sperre_s' => 0, 'begruendung' => $cc_bb_sperre,
                          'titel' => 'Chromecast Ansagen sperren (MQTT)', 'kachel' => 'BEFEHL.K_SPERRE', 'quittung' => 'ausgefuehrt',
                          'tun' => function ($w, $t) {
                              return cc_befehl_bruecke('server', 'sperre', (string) $w);
                          }),
    ),
);
