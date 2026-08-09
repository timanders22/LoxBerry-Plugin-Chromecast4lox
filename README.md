# LoxBerry-Plugin Chromecast 4 Lox NG

Steuert Google-Chromecast-Geräte vom Loxone Miniserver aus und meldet ihren
Zustand zurück — Lautstärke, Wiedergabe, Titel, Interpret, Laufzeit. Der Weg
zum Miniserver ist MQTT.

## Fassung 1.2.1 — eigene Kennung, gegen PHP 7.4 *und* 8.1 gemessen

Diese Fassung bekommt eine **eigene Kennung** und heißt ab jetzt
**Chromecast 4 Lox NG**, Ordner `chromecast-4lox-ng`. Warum, und was das für
eine bestehende Installation bedeutet, steht unten unter
[Herkunft und Pflege](#herkunft-und-pflege) und vollständig in `NOTICE`.

### Gegen PHP 7.4 *und* 8.1 gemessen

Die Oberflaeche wurde unter **PHP 7.4.3 und PHP 8.1.2** tatsaechlich gerendert
(Attrappe des LoxBerry-SDK, `error_reporting=E_ALL`, `display_errors=1`), nicht
nur mit `php -l` geprueft. Ergebnis: **zeichengleiche Ausgabe, keine Meldung
unter keiner der beiden Fassungen.** Auch die frueher beanstandeten Stellen
sind sauber — kein `each()`, kein vertauschtes `implode()`, keine
Zeichenketten-Indizes.

### Ein zusammengeklebter Sprachschluessel

`TEXT.T184` enthielt zwei unzusammenhaengende Saetze in einem Wert:

    T184 = "&middot; neueste Zeile zuerst  Noch keine Protokolldatei vorhanden. …"

Das ist die Handschrift eines automatischen Uebersetzungslaufs, der ueber eine
PHP-Grenze hinweg zusammengefasst hat (erkennbar am doppelten Leerraum). Der
Schluessel war dadurch nirgends einsetzbar — und **beide Saetze standen im
`index.php` noch auf Deutsch**. Jetzt in `TEXT.NEUESTE_ZEILE` und
`TEXT.KEIN_PROTOKOLL` getrennt und angeschlossen.

Dazu vier tote Schluessel entfernt (`ALLGEMEIN.JA`, `.NEIN`, `.SPEICHERN`,
`REITER.MQTT`). Beide Sprachdateien: 219 Schluessel, deckungsgleich, keiner
fehlt, keiner unbenutzt.

**Nicht angetastet:** die fuenf `TTS.M_*`. Mein Zaehler meldete sie als
unbenutzt, sie werden aber ueber eine Nachschlagetabelle in `cc_lib.php`
ausgewaehlt (`'chromecast' => 'TTS.M_CHROMECAST'` und so weiter). Dieselbe
Falle hatte schon einmal beinahe zugeschlagen.

## Neu in 1.2.0

**Die Versionsnummer stand an vier Stellen verschieden** — Ordner 1.0.0 (und
doppelt verschachtelt), `plugin.cfg` und `release.cfg` 1.1.0, `prerelease.cfg`
1.0.0 samt Tag `v1.0.0`, und der Dienst schrieb `VERSION = "1.0.0"` in jede
Protokollzeile. Jetzt überall 1.2.0, Verschachtelung aufgelöst.

### Behobene Fehler

- **`pkill -f chromecast-4lox-server.py`** in `postroot.sh` — mit Bindestrich,
  die Datei heißt ohne. Alte Prozesse wurden also nie beendet. Ersetzt durch
  ein gezieltes `kill` über eine PID-Datei, die `daemon/daemon` jetzt
  mitschreibt; `pkill -f` träfe auch einen Editor, der die Datei offen hat.
- **`$ARGV3`, `$ARGV4`, `$TEMPDIR`** in sechs Shell-Skripten: im Kopf heißen
  sie `$PDIR`, `$PVERSION`, `$PTEMPDIR`. Die Zeilen im Installationsprotokoll
  blieben leer — 27 Stellen.
- **`cp -r ordner/*` bei leerem Ordner** hinterließ eine Fehlermeldung im
  Installationsprotokoll, die nichts bedeutet. Jetzt wird vorher geprüft.
- **`chown` nach dem Update.** Das Skript läuft als root; die zurückgespielte
  Konfiguration gehörte danach root, und die Oberfläche als `loxberry` konnte
  sie nicht mehr schreiben.
- **`int(float(wert))` bei Lautstärkebefehlen.** Kommt aus Loxone `up` statt
  einer Zahl, warf `float()` einen `ValueError` — den fing der große
  `except`-Block, und der ruft `trennen()` auf. Ein Tippfehler in der Nutzlast
  trennte also den Lautsprecher. Jetzt eine eigene Prüfung mit Protokollzeile.
- **MQTT: der erste Verbindungsversuch.** Schlug `connect()` fehl — beim
  Systemstart der Regelfall, weil das Gateway noch nicht läuft —, kehrte
  `start()` zurück ohne `loop_start()`, ließ `self.client` aber gesetzt. Jedes
  spätere `publish()` verschwand für die gesamte Laufzeit spurlos. Jetzt
  `connect_async()`, ausgewerteter Rückgabewert und **vollständige Neumeldung
  nach jeder Wiederverbindung** — sonst bleiben die retained-Themen nach einem
  Broker-Neustart leer.
- **Zwei Schreiber auf einer Logdatei.** Python legte einen `FileHandler` auf
  dieselbe Datei, in die das Startskript ohnehin `stdout` umleitet. Jetzt nur
  noch `stdout`; das Rotieren gehört LoxBerry.
- **`iconv` für die MQTT-Themen.** Das Ergebnis hängt an der Locale des
  Webservers; mit `//IGNORE` fällt ein unbekanntes Zeichen weg, während Python
  einen Unterstrich daraus macht. Aus `Ræv` würde dann in PHP `Rv` und in
  Python `R_v` — die Loxone-Vorlage lauschte auf ein Thema, das der Dienst nie
  sendet. Ersetzt durch eine feste Umschrifttabelle, die genau das tut, was
  `unicodedata.normalize('NFKD', …)` im Dienst tut.
- **`ob_clean()` vor dem Vorlagen-Download**, `pgrep -a -f` statt
  `ps -C python3` im Reiter Test, und der Rückgabewert von `timeout` wird
  ausgewertet.

### Neue Funktionen

- **Ansage (TTS).** Der Befehl `tts` nimmt den Text entgegen und spricht ihn.
  Der Bauplan der Adresse ist **Zeichen für Zeichen der aus dem
  Abfahrtsassistenten 1.5.0** — dieselben Modi, dieselben Feldnamen, dieselben
  Platzhalter; nachgemessen über alle Modi, null Abweichungen. Dazu der Modus
  `chromecast`, den es dort nicht geben kann: ein Chromecast nimmt keine
  TTS-Adresse entgegen, sondern spielt eine Audiodatei. Lange Texte werden an
  Satzgrenzen geteilt, weil Google Translate nach 200 Zeichen abschneidet —
  ohne Teilung fiele der Rest der Ansage weg, ohne jede Meldung. Wer nicht von
  Google abhängen will, trägt unter „Eigene URL-Vorlage" die Adresse einer
  lokalen Sprachausgabe ein.
- **Lautsprechergruppen.** Eine Google-Gruppe meldet sich im Netz wie ein
  eigenes Gerät; ihr Name gehört einfach in die Geräteliste. Der Gewinn ist
  nicht Bequemlichkeit: eine Gruppe spielt **synchron**, was sich mit
  Einzelbefehlen nicht nachbauen ließe. Art und Gruppenkennung stehen jetzt
  auch als MQTT-Themen (`type`, `group`) zur Verfügung.
- **Fortsetzen nach der Ansage.** Vor einer Ansage werden Adresse, Position
  und Lautstärke gemerkt und danach wiederhergestellt — aber nur, wenn es
  etwas zu merken gibt. Läuft Spotify oder YouTube, gibt es keine Adresse, die
  sich zurückspielen ließe; dann wird ausdrücklich **nichts behauptet**,
  sondern nur die Lautstärke wiederhergestellt. Ein pausiertes Medium kommt
  pausiert zurück, kein laufendes wird angehalten.

### Sprachdateien

Die 184 Schlüssel des Abschnitts `[TEXT]` hießen wie ganze Sätze
(`SO_LAUTEN_WIE_IN_DER_GOOGLE_HOME_A`). Das ist nicht nur unhandlich: ändert
sich der Satz, passt der Schlüssel nicht mehr, und die englische Übersetzung
hängt still am alten Text. Sie heißen jetzt `T001` bis `T184`. Der längste
Schlüsselname im ganzen Plugin ist damit 14 Zeichen lang.

Die gemeldete Stelle `GERTE_2 = "Geräte"` mit hartem Umlaut gibt es nicht —
dort steht `Ger&auml;te`. In beiden Sprachdateien kommt kein einziges
Nicht-ASCII-Zeichen vor.

## Herkunft und Pflege

Grundlage ist das Plugin von **Aleš Berka (Aleq)**, Version 0.2.31, Apache-Lizenz 2.0
([aleq.eu/chromecast4lox](http://aleq.eu/chromecast4lox/) ·
[LoxBerry-Wiki](https://wiki.loxberry.de/plugins/chromecast_4_lox/start)).

Das Original wurde zuletzt 2022 angefasst und ist nicht mehr betreut. Diese
Fortführung wird hier gepflegt. **Die Urheberschaft bleibt bei Aleš Berka** —
sie ist in `NOTICE`, in der Hilfeseite und in den Köpfen der Quelldateien
genannt, und der Lizenztext in `LICENSE` ist unverändert. Genau das verlangt
die Apache-Lizenz.

### Warum die Kennung trotzdem wechselt

Bis 1.1.0 stand im `[AUTHOR]`-Block der `plugin.cfg` weiterhin der
Originalautor mit seiner privaten Mailadresse. Das war ein Missverständnis, und
zwar ein zweifaches:

- **Diese beiden Felder sind keine Urheberangabe.** LoxBerry bildet daraus
  zusammen mit `[PLUGIN] NAME` die Kennzahl, unter der es Installation und
  Updates führt. Die Lizenz verlangt eine Nennung — sie verlangt nicht, dass
  ausgerechnet das Kennungsfeld eines Paketverwalters fremd bleibt.
- **Die Mischform ist widersprüchlich.** Die Kennung zeigte auf das fremde
  Plugin, `AUTOMATIC_UPDATES` weiter unten aber auf dieses Repository. Und
  Fehlerberichte zu einer weitgehend neu geschriebenen Fassung wären bei einem
  Autor gelandet, der mit ihr nichts zu tun hat — mit seiner privaten
  Mailadresse als Anlaufstelle.

Seit 1.2.1 heißt das Plugin deshalb **Chromecast 4 Lox NG**, Ordner
`chromecast-4lox-ng`, mit einer eigenen Projektkennung. Mit umbenannt wurden
der Dienst (`bin/chromecast4lox_ng-server.py`) und die Konfigurationsdatei —
sonst hätten sich Original und Fortführung bei paralleler Installation
gegenseitig die Prozesse abgeschossen und in dieselben Dateien geschrieben.
Die Einzelheiten stehen in `NOTICE`.

**Nicht umbenannt** wurden das MQTT-Themenpräfix `chromecast4lox`, der
UDP-Port 7090 und die Titel der erzeugten Loxone-Vorlagen. Das sind Namen auf
der Loxone-Seite; wer sie ändert, muss jeden Baustein im Miniserver nachziehen.
Beide Werte sind in der Oberfläche einstellbar — wer Original und Fortführung
tatsächlich gleichzeitig betreibt, ändert sie dort.

### Was das für eine bestehende Installation heißt

Für LoxBerry ist dies ab 1.2.1 ein **anderes Plugin**. Ein vorhandener Stand
1.1.0 bekommt dieses Update deshalb *nicht* angeboten und bleibt stehen. Der
Weg ist: `Chromecast 4 Lox NG` neu installieren, die Einstellungen einmal
übertragen, danach das alte Plugin deinstallieren. Die Loxone-Seite bleibt
davon unberührt, weil Themenpräfix und UDP-Port gleich bleiben.

## Version 1.0.0 — LoxBerry 4 und Hausstandard

### Warum die Originalfassung auf LoxBerry 4 nicht lief

- **Der Dienst war Python 2.** `#!/usr/bin/env python2`, `import ConfigParser`.
  Auf Debian Bookworm und Trixie — und damit auf LoxBerry 4 — gibt es kein
  Python 2 mehr. Der Dienst startete gar nicht.
- **Die Steuerung lief über eine Go-Binärdatei** (`cast` 0.1.0, Projekt
  barnybug/cast). Mitgeliefert waren 32-Bit-ARM, i386 und amd64 — **kein
  arm64**. Auf einem 64-Bit-Raspberry-Pi-OS ließ sie sich nicht ausführen.
- **`implode($output, "<br>")`** in `discover_exec.php` und `status_exec.php`:
  die vertauschte Argumentreihenfolge ist seit PHP 7.4 abgekündigt und in
  **PHP 8 entfernt**. Auf LoxBerry 3.0.0 bis 4.0 (PHP 7.4) ist es erst eine
  Abkündigung; mit Debian 13 („Trixie“) und PHP 8 werfen beide Seiten einen
  Fatal Error.
- **Die Laufzeitprüfung suchte nach dem Wort `python2`** in der Prozessliste.
  Selbst mit portiertem Dienst hätte die Oberfläche „Server läuft nicht"
  gemeldet.

### Was neu ist

- **Dienst neu in Python 3** mit **pychromecast** (Debian-Paket
  `python3-pychromecast`). Dieselbe Bibliothek, die Home Assistant benutzt:
  gepflegt, läuft auf arm64 und x86. Die vier Go-Binärdateien entfallen —
  das Plugin schrumpft von 6,8 MB auf unter 100 kB.
- **Mehrere Chromecasts** statt genau einem. Im Quelltext der Originalfassung
  stand das als offener Punkt: „TODO Multiple chromecast / name support".
- **MQTT als Weg zum Miniserver**, retained. Elf Zustände je Gerät statt
  bisher zwei: `online`, `state`, `playing`, `volume`, `muted`, `app`,
  `title`, `artist`, `album`, `duration`, `position`.
- **Zwölf Befehle** statt acht, unter anderem `mute`, `next`, `prev`, `seek`,
  `volume_up`, `volume_down`.
- **Der Dienst läuft als `loxberry`, nicht als `root`.** Auch das stand im
  Quelltext als offener Punkt: „TODO do not run as root". Er braucht nur
  mDNS, MQTT und einen UDP-Port.
- **Neue Oberfläche als `index.php`** mit vier Reitern: *Einstellungen*,
  *Einbindung in Loxone*, *Test*, *Logdateien*. Vollständig auf Deutsch.
  Die alten Seiten (`config.php`, `discover.php`, `log.php`, `inc_common.php`,
  `discover_exec.php`, `status_exec.php`, `templates/main.html` und die
  englische Sprachdatei) entfallen.
- **Reiter Test** nach Hausstandard mit drei Gruppen und Legende: *Ansehen*
  (grün), *Technische Auskunft* (blaugrau), *Löst etwas aus* (orange).
  Geprüft werden der Dienst, die Python-Module, das MQTT-Gateway und die
  Geräte im Netz.
- **Loxone-Vorlagen** werden im Plugin erzeugt — Eingänge und Ausgänge, je
  Gerät ein vollständiger Satz. Die Originalfassung hatte keine
  („TODO Loxone template").
- **Der UDP-Weg bleibt erhalten**, damit bestehende Loxone-Konfigurationen
  weiterlaufen. Syntax erweitert um einen optionalen Gerätenamen:
  `<Gerät>/<BEFEHL> <Wert>;`

## MQTT-Themen

Zustände, retained, je Gerät unter `chromecast4lox/<Gerät>/`:

| Thema | Art | Bedeutung |
|---|---|---|
| `online` | digital | Gerät erreichbar |
| `state` | Text | PLAYING, PAUSED, IDLE, BUFFERING, OFFLINE |
| `playing` | digital | spielt gerade |
| `volume` | analog | 0 bis 100 |
| `muted` | digital | stumm |
| `app` | Text | laufende App |
| `title`, `artist`, `album` | Text | Metadaten |
| `duration`, `position` | analog | Sekunden |

Dazu `chromecast4lox/server/online` für den Dienst selbst.

Befehle unter `chromecast4lox/<Gerät>/cmd/<Befehl>`: `play`, `pause`, `stop`,
`quit`, `volume`, `volume_step`, `volume_up`, `volume_down`, `mute`, `next`,
`prev`, `seek`.

Der Gerätename im Thema entsteht aus dem Anzeigenamen: Umlaute umgeschrieben,
alles übrige außer Buchstaben, Ziffern, Strich und Unterstrich wird zu `_`.
Aus `Küche Lautsprecher` wird `Kueche_Lautsprecher`.

## Dateien

| Datei | Zweck |
|---|---|
| `bin/chromecast4lox_ng-server.py` | Dienst: Geräte, MQTT, UDP |
| `bin/cc_discover.py` | Gerätesuche für den Reiter Test |
| `webfrontend/htmlauth/index.php` | Oberfläche, vier Reiter |
| `webfrontend/htmlauth/cc_lib.php` | Konfiguration, Themen, Loxone-XML |
| `webfrontend/htmlauth/cc_test.php` | Aktionen des Reiters Test |
| `config/chromecast-4lox-ng.cfg` | Konfiguration im INI-Format |
| `dpkg/apt` | `python3-pychromecast`, `python3-zeroconf`, `python3-paho-mqtt` |

## Voraussetzungen

- LoxBerry ab 3.0 (`LB_MINIMUM` in `plugin.cfg`)
- MQTT-Gateway (eigenes LoxBerry-Plugin) für den MQTT-Weg
- Der LoxBerry muss im **selben Netzsegment** wie die Chromecasts liegen —
  die Suche läuft über mDNS und geht nicht über VLAN-Grenzen.

## Installation

Über die LoxBerry-Plugin-Verwaltung, entweder als Datei-Upload des ZIP-Archivs
oder direkt über die Adresse des Releases:

    https://github.com/timanders22/LoxBerry-Plugin-Chromecast4lox/archive/refs/tags/v1.2.1.zip

Auto-Update ist eingeschaltet und zeigt auf dieses Repository. In der
Plugin-Verwaltung lässt sich danach zwischen *Aus*, *Nur benachrichtigen*,
*Releases* und *Pre- und Releases* wählen.

## Lizenz

Apache-Lizenz 2.0, wie das Ausgangsprojekt. Der Lizenztext steht unverändert in
`LICENSE`, die Nennung des Originalautors und die Liste der Änderungen in
`NOTICE` (Apache-Lizenz 2.0, Abschnitt 4 b).

## Aufgeräumt

Deutlich weniger als bei den anderen Plugins — die Struktur war schon sauber
(keine verschachtelte `-master`-Kopie, keine doppelten Icons, keine
`__pycache__`-Reste).

- **`preinstall.sh` und `preroot.sh`** — beide bestanden ausschließlich aus den
  Variablenzuweisungen der LoxBerry-Vorlage; `preinstall.sh` gab sie zusätzlich
  aus. Getan wurde nichts. Entfernt. `postroot.sh` bleibt, dort wird wirklich
  gearbeitet.
- **`uninstall/uninstall` benutzte `pkill -f chromecast4lox-server`**, obwohl
  `daemon/daemon` beim Start längst eine PID-Datei unter
  `data/plugins/<Ordner>/dienst.pid` anlegt. `pkill -f` trifft jeden Prozess,
  in dessen Kommandozeile die Zeichenkette irgendwo vorkommt — beim Beenden
  schickt es das Signal an *alle* Treffer. Jetzt über die PID-Datei, mit
  argumentweiser Gegenprobe über `/proc/<pid>/cmdline`.
- **`.gitignore`** ergänzt.

### Nicht angerührt: die scheinbar verwaisten Sprachschlüssel

Eine Suche nach unbenutzten Schlüsseln meldet zehn Treffer. **Fünf davon sind
in Wahrheit in Gebrauch:** `TTS.M_CHROMECAST`, `M_MUSICSERVER`, `M_MS4H`,
`M_AUDIOSERVER` und `M_CUSTOM` werden über `cc_tts_modi()` aus `cc_lib.php`
geholt und mit `cc_t($cc_mt)` aufgerufen — eine Suche nach dem wortwörtlichen
`cc_t('TTS.M_CHROMECAST')` findet sie nicht. Wer sie löscht, bekommt im
Auswahlkasten für die Ansageart die Schlüsselnamen statt der Texte.

In beiden Sprachdateien steht jetzt ein Warnhinweis direkt darüber. Die
übrigen fünf (`ALLGEMEIN.JA/NEIN/SPEICHERN`, `REITER.MQTT`, `TEXT.T184`) sind
tatsächlich unbenutzt, kosten aber nichts und bleiben als Reserve stehen.

### Kein `webfrontend/html/`

Das ist hier richtig und keine Lücke: Loxone spricht das Plugin über **UDP
(Port 7090)** und MQTT an, nicht über HTTP. Es gibt also keinen Endpunkt, der
im unangemeldeten Bereich liegen müsste.

