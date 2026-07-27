# LoxBerry-Plugin Chromecast 4 Lox

Steuert Google-Chromecast-Geräte vom Loxone Miniserver aus und meldet ihren
Zustand zurück — Lautstärke, Wiedergabe, Titel, Interpret, Laufzeit. Der Weg
zum Miniserver ist MQTT.

Grundlage ist das Plugin von **Aleš Berka (Aleq)**, Version 0.2.31, Apache-Lizenz 2.0
([aleq.eu/chromecast4lox](http://aleq.eu/chromecast4lox/) ·
[LoxBerry-Wiki](https://wiki.loxberry.de/plugins/chromecast_4_lox/start)).
Die Autorenangabe in `plugin.cfg` bleibt unverändert — LoxBerry identifiziert das
Plugin darüber.

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
  **PHP 8 entfernt**. LoxBerry 4 liefert PHP 8.4 — beide Seiten hätten einen
  Fatal Error geworfen.
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
| `bin/chromecast4lox-server.py` | Dienst: Geräte, MQTT, UDP |
| `bin/cc_discover.py` | Gerätesuche für den Reiter Test |
| `webfrontend/htmlauth/index.php` | Oberfläche, vier Reiter |
| `webfrontend/htmlauth/cc_lib.php` | Konfiguration, Themen, Loxone-XML |
| `webfrontend/htmlauth/cc_test.php` | Aktionen des Reiters Test |
| `config/chromecast-4lox.cfg` | Konfiguration im INI-Format |
| `dpkg/apt` | `python3-pychromecast`, `python3-zeroconf`, `python3-paho-mqtt` |

## Voraussetzungen

- LoxBerry ab 2.0
- MQTT-Gateway (eigenes LoxBerry-Plugin) für den MQTT-Weg
- Der LoxBerry muss im **selben Netzsegment** wie die Chromecasts liegen —
  die Suche läuft über mDNS und geht nicht über VLAN-Grenzen.

## Lizenz

Apache-Lizenz 2.0, wie das Ausgangsprojekt.
