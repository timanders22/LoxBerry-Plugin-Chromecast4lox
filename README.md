# LoxBerry-Plugin Chromecast 4 Lox NG

Steuert Google-Cast-Geräte vom Loxone Miniserver aus und meldet ihren
Zustand zurück — Lautstärke, Wiedergabe, Titel, Interpret, Laufzeit. Der Weg
zum Miniserver ist MQTT. Auf Wunsch spricht es Ansagen für andere Plugins.

**Unterstützte Geräte** — alles, was Google Cast versteht, nicht nur der
Chromecast-Stick:

* **Chromecast** (alle Generationen, auch Chromecast mit Google TV)
* **Google Home / Nest Speaker** (Google Home, Home Mini, Nest Mini, Nest Audio,
  Nest Hub) und Lautsprechergruppen aus der Google-Home-App
* **Google TV Streamer (4K)** und Fernseher mit **Chromecast built-in**
* **Google Home Speaker**: Googles Gerätespezifikationen nennen bei ihm kein Google Cast; ob er
  sich über dieses Plugin ansprechen lässt, ist nicht gemessen. Als Matter-Hub ist er von Google
  belegt (Abschnitt „Google ↔ Loxone“).

An echten Geräten im Haus des Autors ist das noch nicht gemessen;
Rückmeldungen mit Modellangabe sind willkommen.

Die Bausteine der beiden Baustein-Listen aus dem Reiter *Einbindung in Loxone* stehen fertig verbunden
auf den Seiten „Chromecast4lox“ und „Chromecast4lox Steuerung“ im
[LoxBerry-Plugins Musterprojekt](https://github.com/timanders22/LoxBerry-Plugins-Musterprojekt),
einer gemeinsamen Projektdatei mit allen Plugin-Seiten und Vorlagen.

## Neu in 1.3.28

* **Ansagen auch auf den offenen Dashboard-Tafeln, ab Werk an.** Mit Dashboard ab 0.9.40 und einer offenen Tafel kommt jede Ansage dieses Plugins auch dort an: Gong, Sprache und der Text groß. Ohne Dashboard oder ohne offene Tafel wird das Ziel ohne Fehler übergangen. Ausschalten: Reiter *Einstellungen*, Haken „Auch auf offenen Dashboard-Tafeln ansagen“; ein gespeichertes Aus bleibt. Es gelten dieselben Regeln wie für die Lautsprecher.
* **Nachgetragen:** Ein Lautsprecher, der nicht antwortet, erzeugt nur noch eine Warnung je Stunde (vorher gemessen 146 je Stunde). Sie nennt ihn als „zuletzt gesehen“: Das ist die gemerkte Liste, nicht das Netz von jetzt.
* **Gemeinsame Sprachausgabe 1.4.0.** Einstellungen und ein gespeichertes Aus bleiben, wie sie sind; am Gerät ist nichts einzurichten.

## Neu in 1.3.27

**Ab dieser Fassung sind die Sprachansagen ab Werk an, auch nach einem Update, wenn Sie die Ausgabeart nie gespeichert haben.** Eine gespeicherte Einstellung bleibt, wie sie ist, auch „aus“. **Ausschalten:** Reiter *Einstellungen*, Abschnitt *Sprachansagen*, Ausgabeart **„aus“** wählen und speichern; das bleibt auch nach jedem weiteren Update aus.

* **Was angesagt wird:** ein Lautsprecher, der schon da war, antwortet seit 30 Minuten nicht, und er ist wieder da. Der Haken steht ab Werk aus, weil Fernseher abends aus sind. Angesagt wird über den Music Server oder ein Echo, nie über Chromecast4lox selbst.
* **Wohin:** ab Werk zugleich Loxone Music Server (braucht seine Adresse) und Amazon Echo über Alexa-NG, nie über dieses Plugin selbst. Ein Ziel, das nicht bereitsteht, wird ohne Fehler übergangen; der Abschnitt *Sprachansagen* sagt, was ihm fehlt. Das Sprechtoken der beiden Plugins müssen Sie nicht abschreiben: Es wird übernommen, in der Konfiguration gespeichert und nachgezogen; ein von Hand eingetragenes bleibt.
* **Wann nicht:** Nachtruhe 22 bis 7 Uhr (einstellbar, wird nicht nachgeholt; Dringendes spricht trotzdem), dieselbe Ansage höchstens einmal in 30 Minuten (einstellbar) und solange Loxone das retained MQTT-Thema `haus/ansage/ruhe` auf 1 setzt (das gilt auch für Dringendes). Die Testansage spricht immer. Ausgabeart, Ziele und Regeln reisen in der Sicherung mit, das Sprechtoken nie.
* **Der Minutenlauf wartet nicht auf Lautsprecher:** Gesprochen wird in einem eigenen Vorgang.
* **Berichtigt:** Der Leser der Haus-Themen hat jetzt auch beim Lesen eines Pakets eine feste Frist.


## Neu in 1.3.26

### Klingel synchron bei laufender Musik, neu angelegte Gruppen, hängende Netzfreigabe

Nachbesserung aus dem Hörtest vom 01.11.2026 an zwei Google Home Speakern mit der Google-Gruppe „Klingel“. Gemessen an
Attrappen (Lautsprecher, Gruppe, Netzfreigabe) unter PHP 7.4, 8.4 und 8.5 und Python; am Gerät ist 1.3.26 noch nicht
gemessen.

* **Gong synchron, auch wenn ein Mitglied vorher spielt:** Lief im Gästezimmer Musik, kam der Gong der Gruppe dort
  versetzt; ohne Musik war er synchron. Jetzt hält der Dienst vor dem Gong jedes Mitglied der Zielgruppe an, das gerade
  etwas Fortsetzbares spielt (Stopp an alle zugleich), und wartet, bis es „IDLE“ meldet – aus der Statusmeldung des
  Lautsprechers, höchstens 1,2 s. Danach geht der Gong an die Gruppe, und die Musik wird wie bisher fortgesetzt
  („Wiedergabeliste bei Titel X, N s wieder aufgenommen“). Ein Lautsprecher, der nicht Mitglied ist, spielt weiter;
  eine App ohne Adresse (Spotify, YouTube) wird nicht angehalten, weil sie sich nicht fortsetzen ließe. Das gilt nur
  mit dem Haken „Nach der Ansage fortsetzen, was vorher lief“. Die Klingel wird dadurch um die gemessene Wartezeit später (im Protokoll
  „vor dem Gong angehalten: … – IDLE nach 0.xx s“), geschätzt 0,1–0,5 s, höchstens 1,2 s.
* **Gelöschte und neu angelegte Gruppe wird wiedergefunden:** Eine in Google Home neu angelegte Gruppe hat eine neue
  Kennung und einen neuen Port; das Plugin verband sich weiter mit der alten und meldete bei jeder Klingel „is
  connecting…“, bis der Dienst neu startete. Jetzt verwirft es eine Verbindung, die 60 s lang nicht zustande kommt oder
  an der zwei Sendeversuche hintereinander scheitern, und sucht das Gerät bzw. die Gruppe über den Namen neu. Eine
  Klingel sucht in diesem Fall sofort einmal neu (nur in der laufenden Suche, höchstens 2 s) und versucht es erneut,
  bevor der Rückfall greift. Dazu: Meldete sich die alte Gruppe aus dem Netz ab, strich die laufende Suche bisher auch
  den Eintrag der neuen gleichen Namens – jetzt zählt die Kennung.
* **Rückfall an alle zuletzt bekannten Mitglieder:** Die Mitglieder jeder Gruppe stehen in `data/…/mitglieder.json`
  (seit 1.3.25); der Rückfall geht an alle, auch an die gerade nicht verbundenen. Im Hörtest stammte die gemerkte
  Liste noch von der alten Gruppe (nur Gästezimmer) – mit der Neusuche meldet die neue Gruppe ihre Mitglieder selbst.
* **Lesbare Fehlertexte:** statt „Chromecast {self.host}:{self.port} is connecting…“ (so steht der Text in
  pychromecast 9.4.0) heißt es „Gerät/Gruppe 'Klingel' noch nicht verbunden“; Adressen stehen nicht in der Meldung.
* **Hängende Netzfreigabe erkennen:** Nach dem Ab- und Anstecken der SSD an der FRITZ!Box meldete der Webserver
  „Stale file handle“ (HTTP 403), der Lautsprecher spielte nur den Verbindungston, und das Plugin meldete „gestartet“.
  Jetzt liest der Dienst beim Einrichten, mit der Wache (alle 15 min) und beim Start einer Listen- oder Dateiquelle
  eine Datei der Ablage. Scheitert das, steht im Protokoll und im Reiter *Test*: „Netzfreigabe hängt (Stale file
  handle) – am LoxBerry neu einhängen; bei FRITZ!Box Option noserverino in …/system/autofs/auto.smb und danach
  autofs neu starten“ (mit dem vollen Pfad im LoxBerry-Verzeichnis); `server/ablage_ok` wird 0. Ist die Freigabe wieder lesbar, gibt der Dienst die Ablage
  von selbst wieder frei. Nach dem Start einer Listen- oder Dateiquelle wartet er bis zu 8 s auf „PLAYING“ und meldet
  sonst „Wiedergabe kam nicht zustande“ statt „gestartet“.
* **Hilfe:** die Befehle einer Zone stehen jetzt vollständig in der Hilfe – der nächste Titel heißt `next`
  (nicht „weiter“), der vorige `prev`.

**English:** the doorbell now stops playing members of the target group before the chime (STOP, waits for IDLE from
the status message, at most 1.2 s) and resumes them afterwards; a group deleted and re-created in Google Home is found
again (connection dropped after 60 s or two failed sends, the doorbell re-searches once at once, at most 2 s); the
fallback rings all last known members; error texts no longer show `{self.host}:{self.port}`; a hanging network share
(“stale file handle”) is reported on the *Test* tab with the remedy, `server/ablage_ok` becomes 0, and a file or list
source reports “Wiedergabe kam nicht zustande” (playback did not start) instead of “gestartet”. The help lists all zone commands (`next`, not “weiter”).

## Neu in 1.3.25

### Audio Stufe 1: Zonen, Radiotasten, Medienablage, Klingel

Chromecast 4 Lox NG übernimmt erste Aufgaben des klassischen Loxone Music Servers – ohne ihn abzuschalten. Bedient
wird aus Loxone über MQTT (Befehlsbrücke, MQTT-Gateway V2) mit Radiotasten, Reglern, Tastern und Statustexten; eine
Musikoberfläche der Loxone App (Durchsuchen, Cover) gibt es erst in Stufe 2. Gemessen an Attrappen (Lautsprecher,
Gruppe, Medienablage) unter PHP 7.4, 8.4 und 8.5 und Python, und am 30.10.2026 im Haus an zwei Google Home
Speakern mit einer Google-Gruppe: Weiter in einer Liste, Radio nach der Klingel, Klingel an die Gruppe ohne
Rückfall (etwa 1 s, wenn die Cast-App schon läuft, etwa 2,5 s beim Kaltstart), Fortsetzen der Mitglieder nach
der Gruppen-Klingel; die Mitglieder der Gruppe ließen sich abfragen (pychromecast 9.4.0).

* **Zonen (neuer Reiter *Audio*):** je Zeile `Nr = Ziel` oder `Nr = Anzeigename | Ziel`, Nummer 1 bis 24, Ziel ist
  ein Lautsprecher oder eine Google-Lautsprechergruppe aus der Geräteliste. Werte unter
  `chromecast4lox/zone/<Nr>/…` (`state`, `playing`, `volume`, `muted`, `title`, `artist`, `album`, `online`,
  `art` 0 Gerät/1 Gruppe, `quelle`, `quelle_name`, `mitglied_von`), Befehle unter `chromecast4lox/befehl/zone_<Nr>/…` – über die
  Nummer, nicht über den Google-Namen. Das Plugin merkt sich die Kennung des Geräts beim ersten Fund; wird es in
  Google Home umbenannt, verbindet es weiter und meldet den neuen Namen. Die bisherigen Gerätethemen bleiben.
* **Gruppen und Mitglieder:** jede eingetragene Google-Gruppe fragt der Dienst beim Verbinden nach ihren
  Mitgliedern (Multizone, eine reine Statusabfrage) und übernimmt jede Änderung, die sie meldet; gemerkt unter
  `data/` für den nächsten Start. `zone/<Nr>/mitglied_von` nennt die Gruppen, in denen das Ziel der Zone Mitglied
  ist (mit `;` getrennt, retained; `-` = in keiner oder noch nichts gemeldet, bei einer Gruppen-Zone immer `-`).
  Der Reiter *Audio* zeigt bei einer Gruppen-Zone ihre Mitglieder, bei einem Lautsprecher seine Gruppen.
* **Quellen als Radiotasten:** eine gemeinsame Liste `Nr = Art | Name | Adresse` (1 bis 99). `radio`: Stream-Adresse,
  `.m3u`/`.pls` werden aufgelöst, der Inhaltstyp kommt aus der Antwort (AAC und HLS spielen damit). `liste`: Ordner
  (alle Audiodateien alphabetisch) oder M3U-Datei in der Medienablage, als Cast-Warteschlange – danach wirken
  `next`/`prev` (aus der Liste des Plugins; `next` nach dem letzten Titel hält an, `prev` auf dem ersten beginnt
  ihn neu), Titel und Interpret stehen in `title`/`artist`. `datei`: eine Datei der Ablage oder eine
  http-Adresse. Befehl `zone_<Nr>/quelle` mit der Nummer, `0` hält an. Solange die Liste leer ist, gelten die
  Favoriten als Radioquellen mit ihren Nummern. Abspielbar aus der Ablage sind `.mp3`, `.m4a`, `.aac`,
  `.flac`, `.ogg`/`.oga`/`.opus`, `.wav`, `.webm` und `.mp4` – bei Videos nur der Ton (AAC, Inhaltstyp
  `audio/mp4`); die Zählung „n Audiodateien“ nimmt `.mp4` mit, andere Dateien übergeht der Dienst.
* **Medienablage SSD/NAS:** ein Ordner, den LoxBerry selbst einhängt (USB-Datenträger oder Netzfreigabe). Der
  Dienst legt im eigenen Webordner einen Verweis `m_<Pfadschlüssel>` an; ausgeliefert wird direkt vom Webserver,
  mit Spulen, ohne PHP im Datenweg. Die Lautsprecher können sich nicht anmelden – der Ordner ist im Heimnetz ohne
  Anmeldung erreichbar, geschützt nur durch den zufälligen Pfadschlüssel. Liegt darin eine Datei, die der Webserver
  ausführen könnte (`.php`, `.cgi`, `.sh` …), wird er nicht freigegeben. Eine Verzeichnisliste gibt es nicht: der
  Dienst legt dafür im eigenen Webordner eine `.htaccess` an (nur einzelne Dateien abrufbar, versteckte Pfade
  nicht). Ein ausgehängter Datenträger meldet sich
  in `last_error` der Zone und in `server/ablage_ok`.
  **Ablage auswählen:** der Reiter *Audio* bietet mit der Speicherauswahl von LoxBerry an, was LoxBerry
  eingehängt hat – USB-Datenträger und Netzwerkfreigaben – samt *Unterverzeichnis*; *Benutzerdefinierter Pfad*
  nimmt einen anderen Ordner unter `/media` oder `/mnt` (ein bisheriger Pfad bleibt so stehen), das freie Feld
  steht unter *Erweitert*. **Speicher an der FRITZ!Box:** dort *Heimnetz → USB / Speicher → Speicher (NAS)* aktiv
  und unter *System → FRITZ!Box-Benutzer* ein Benutzer mit „Zugang zu NAS-Inhalten“; im LoxBerry unter
  *Netzwerkfreigaben* Server `192.168.178.1` bzw. `fritz.box`, SMB, Benutzer und Kennwort. LoxBerry hängt die
  Freigabe bei Bedarf selbst ein (autofs) und gibt dem Webserver Leserecht. Die Durchsicht geht nach dem
  Dateinamen, nicht nach Rechten (auf einer Netzfreigabe tragen alle Dateien dieselben): `.php`, `.cgi`, `.sh` …
  auch in Großschreibung und `.cgi` mitten im Namen (`lied.cgi.mp3`); ein Verweis, der aus der Ablage
  hinauszeigt, verhindert die Freigabe ebenfalls.
  **Zweite Sperre:** unabhängig von dieser Durchsicht, die nur beim Einrichten läuft, liefert der Webserver unter
  `m_<Pfadschlüssel>/` nie ein Programm aus. Die `.htaccess` des Dienstes antwortet auf `.php` (auch `.php5` …),
  `.phtml`, `.phar`, `.cgi`, `.pl`, `.py`, `.sh` und `.shtml` mit 404 – auch groß geschrieben und mitten im Namen
  (`lied.cgi.mp3`) –, gibt dort jede andere Datei nur als Datei heraus (kein PHP, kein CGI) und schaltet im
  Webordner des Plugins die Inhaltsaushandlung ab. Eine Audiodatei mit einem solchen Namensteil spielt das Plugin
  deshalb nicht. Grenze: eine `.htaccess`, die später jemand in die Ablage legt, liest der Webserver mit und kann
  diese Regeln aushebeln. **Empfehlung:** für die Netzwerkfreigabe einen FRITZ!Box-Benutzer **nur mit Leserecht**
  nehmen (dann kann über den LoxBerry niemand in die Ablage schreiben) und als Ablage einen eigenen
  Musik-Unterordner, nicht die ganze Platte.
  **Wache:** der Dienst sieht die freigegebene Ablage alle 15 Minuten erneut durch (eigener Faden, nicht während
  einer Klingel oder Ansage, höchstens 120 s und 50 000 Einträge – reicht das nicht, bleibt die Freigabe, wie sie
  ist, und das Protokoll sagt es). Findet er eine `.ht*`-Datei, eine Programm-Endung (dieselbe Liste, auch mitten
  im Namen – `lied.php.mp3` sperrt die Ablage, bis die Datei umbenannt ist) oder einen Verweis nach außen, zieht er
  die Freigabe sofort zurück: Verweis fort, `server/ablage_ok` 0, Zustand „unsichere Datei gefunden: <Pfad in der
  Ablage>“, Protokollzeile. Ist die Datei fort, gibt der nächste Durchgang die Ablage wieder frei. Dieselbe
  Namensregel gilt beim Einrichten. Zwischen zwei Durchgängen schützt die zweite Sperre.
  **Dateisystem eines externen Laufwerks:** am LoxBerry ist ext4 am besten (Rechte, Stabilität); exFAT ist gut, wenn
  die Platte auch am Windows-PC befüllt wird (Debian 13 kann exFAT im Kernel); NTFS geht (Kernel-Treiber ntfs3), aber
  nur als Notlösung (keine Linux-Rechte, nach unsauberem Abziehen eventuell nur lesbar); FAT32 nicht (4-GB-Grenze,
  Umlaute). Ein Laufwerk an der FRITZ!Box: exFAT oder NTFS, im LoxBerry als Netzwerkfreigabe (SMB) einbinden. Strom:
  der Pi 4 gibt über USB zusammen etwa 1,2 A ab – ein NVMe-Gehäuse ggf. nur mit aktivem USB-Hub. Der Reiter *Audio*
  zeigt das erkannte Dateisystem der Ablage.
* **Klingel auf viele Lautsprecher:** Befehl `chromecast4lox/befehl/klingel` (Standardziel) oder
  `…/befehl/zone_<Nr>/klingel`, je als Puls. Hauptweg ist eine Google-Lautsprechergruppe (synchron); antwortet sie
  nicht (Anlauf bis 8 s, auch wenn die Cast-App erst starten muss), geht die Klingel parallel an die Rückfallliste
  (leer = die Mitglieder der Klingel-Gruppe, sobald die Gruppe sie gemeldet hat, sonst alle Lautsprecher, nie
  Fernseher oder Gruppen). Eigener Gong (Datei der Ablage oder Adresse), eigener Pegel,
  optional eine Ansage danach. Google spielt beim Start jeder Wiedergabe einen kurzen eigenen Ton („Dip“) – das
  Plugin kann ihn nicht abschalten. Ein eigener Gong sollte mit etwa 0,3–0,5 s Stille beginnen und weich
  einblenden, sonst knackt es. In der Ruhezeit gilt deren Obergrenze – die Klingel wird leiser, nicht stumm; wer
  nachts nichts will, setzt die Sperre aus Loxone. Ein zweites Klingeln binnen 10 s legt die Brücke zusammen.
  **Gong unabhängig von der Musik:** ein Dateiname im Feld *Gong* gilt relativ zur Medienablage. Fehlt die
  Datei dort (etwa nach einem Wechsel der Ablage vom USB-Datenträger zur FRITZ!Box), klingt der eigene Gong
  (Reiter *Audio*, hochgeladen: MP3/OGG/WAV bis 1 MB, liegt bei den Einstellungen und übersteht ein Update) oder
  der mitgelieferte Standard-Gong (0,4 s Stille, weich eingeblendeter Zweiklang, vom Plugin selbst erzeugt,
  ausgeliefert unter `/plugins/<ordner>/klang/`). Die Klingel bleibt nie stumm; das Protokoll nennt den
  Rückfall, der Reiter *Test* warnt bei Quellen und Gong mit fehlender Datei.
* **Ansagen auch an eine Zone:** `zone_<Nr>/tts` über MQTT und `zone_<Nr>` als Gerät am Sprech-Endpunkt.
* **Im Netz sichtbare, nicht eingetragene Cast-Geräte** nennt der Reiter *Audio*; wird ein eingetragenes Gerät
  nicht gefunden, stehen die sichtbaren im Protokoll.
* **Neue Dienstthemen:** `server/quellen` (Zahl der gültigen Quellen) und `server/ablage_ok`, beide nie retained, im
  Gateway V2 mit Noncached; ebenso `zone/<Nr>/online`.
* **Lautstärke:** gesetzt wird in ganzen Prozent und unverändert gesendet. Die Protokollzeile von pychromecast
  9.4.0 (`Receiver:setting volume to %.1f`) zeigt nur eine Nachkommastelle – für 25 % steht dort „0.2“; der
  gesendete Wert ist 0,25 (im Quelltext von `controllers/receiver.py` nachgelesen, 30.10.2026). Am selben Tag
  meldeten beide Google Home Speaker im Haus 45 %. Zehnerschritte gibt es nur bei `volume_up`/`volume_down` von
  pychromecast ohne eigenen Schritt – dieses Plugin nimmt dafür seine eigene Schrittweite.
* **Standard-Sprachausgabe** bleibt Google-Übersetzer (inoffiziell, braucht Internet, der Text geht an Google); die
  Oberfläche sagt das jetzt; `lokal` (espeak-ng) ist der Rückfall.
* **Grenzen:** synchron spielt nur eine Google-Gruppe, `alle` ist parallel; eine Gruppe unterbricht, was ihre
  Mitglieder spielen – mit „danach fortsetzen“ setzt der Dienst danach jeden Lautsprecher fort, der vorher spielte
  und durch die Gruppe verstummt ist (Radio neu, Liste und Datei an der Stelle); die Klingel über Cast braucht etwa
  1 s, wenn die Cast-App schon läuft, und etwa 2,5 s beim Kaltstart (gemessen 30.10.2026); Loxone Radio ist nicht
  erreichbar (geschützte Schnittstelle); eine Liste lädt höchstens 200 Titel.

Dazu aus der Nachtwelle (29.10.2026):

* **Befehlsbrücke 1.0.2** (gemeinsame Stammfassung, bisher 1.0.0): `stop` legt als root nichts mehr an, die
  Startausgabe wird gekappt, eine leere oder ungültige Uhr startet die Brücke bei liegender Aktualisierungsmarke
  nicht, der Befehlsordner ist nur für das Plugin lesbar (0700). Themen, Quittungen und Haken bleiben, wie sie
  waren. Die Vorlage der MQTT-Befehle baut dieses Plugin selbst; sie hatte schon Windows-Zeilenenden (CRLF).
* **Reiter Test, neue Zeile „Gateway V2: sind alle Datenpunkte der Hakenliste angehakt?“** Unter MQTT-Gateway V2
  liest das Plugin `config/system/subscriptions.json` und nennt jeden Datenpunkt der Hakenliste (Reiter
  Einbindung in Loxone), der dort nicht angehakt ist, und jeden, bei dem „Noncached“ nicht stimmt (`server/online` braucht es, die übrigen nicht).
  Unter Gateway V1 und bei ausgeschalteter MQTT-Veröffentlichung heißt die Zeile „trifft nicht zu“.
* **Kopf, Zeile MQTT:** Groß steht weiter der Schalter dieses Plugins, klein darunter jetzt der Autostart des
  MQTT-Gateways (ein/aus, „nicht feststellbar“ ohne MQTT-Abschnitt in der LoxBerry-Konfiguration) – wie in
  den übrigen Plugins.
* **Hilfe-Verweis** oben rechts zeigt auf die eigene Seite im LoxBerry-Wiki (`plugins/chromecast4loxng/start`)
  statt auf die Seite des ursprünglichen Plugins „Chromecast 4 Lox“.
* Die Ausgabeart „Chromecast“ der gemeinsamen Sprachausgabe anderer Plugins (Sprech-Endpunkt) ist unverändert.

## Audio stage 1 (English summary, 1.3.25)

* **Zones** (new *Audio* tab): one line per zone, `No = target` or `No = display name | target`, number 1 to 24,
  target = a speaker or Google speaker group from the device list. Values under `chromecast4lox/zone/<No>/…`,
  commands under `chromecast4lox/befehl/zone_<No>/…`. Renaming a device in Google Home does not break the wiring:
  the plugin remembers the device ID on first contact. `zone/<No>/mitglied_von` names the Google groups the zone
  target is a member of (separated by `;`, retained; `-` = none or not reported yet): the service asks every
  entered group for its members when it connects and takes over every change; the *Audio* tab shows them.
* **Sources as radio buttons:** one shared list `No = kind | name | address` (1 to 99): `radio` (stream URL, .m3u
  and .pls resolved, content type from the response), `liste` (folder or M3U in the media store, loaded as a Cast
  queue; next/previous follow the plugin's own list - next after the last title stops, previous on the first
  restarts it), `datei` (a file of the store or an http URL). Command `zone_<No>/quelle` with the
  number, `0` stops. While the list is empty, the favourites count as radio sources with their numbers.
  Playable from the store: `.mp3`, `.m4a`, `.aac`, `.flac`, `.ogg`/`.oga`/`.opus`, `.wav`, `.webm` and `.mp4`
  – for videos only the sound (AAC, content type `audio/mp4`); the count “n audio files” includes `.mp4`,
  other files are skipped by the service.
* **Media store (SSD/NAS):** a folder LoxBerry mounts itself (USB drive or network share). The service links it into
  its own web folder under a random path key; the web server delivers the files directly (seeking supported). Cast
  devices cannot log in, so the folder is reachable on the home network without login, protected only by the key.
  A folder containing files the web server could execute (`.php`, `.cgi`, `.sh` …) is not released. There is no
  directory listing: the service writes a `.htaccess` into its own web folder (single files only, no hidden paths).
  **Choosing the store:** the *Audio* tab offers, with the LoxBerry storage selection, what LoxBerry has mounted
  (USB drives, network shares) plus a *Subfolder*; *Custom path* takes another folder below `/media` or `/mnt`
  (a previous path stays like that), the free field is under *Advanced*. **Storage on a FRITZ!Box:** enable
  *Home Network → USB / Storage → Storage (NAS)* and create a user with “Access to NAS contents”; on the LoxBerry
  add the server `192.168.178.1` or `fritz.box` under *Network shares* (SMB, user, password). The check goes by
  file name, not permissions (all files on a share carry the same): `.php`, `.cgi`, `.sh` … in any case and
  `.cgi` inside a name (`song.cgi.mp3`); a link pointing out of the store also prevents the release.
  **Second lock:** independently of this check, which only runs when the store is set up, the web server never
  delivers a program below `m_<path key>/`. The service's `.htaccess` answers `.php` (also `.php5` …), `.phtml`,
  `.phar`, `.cgi`, `.pl`, `.py`, `.sh` and `.shtml` with 404 – in any case and also inside a name
  (`song.cgi.mp3`) –, serves every other file there as a plain file only (no PHP, no CGI) and switches off content
  negotiation in the plugin's web folder. An audio file with such a name part is therefore not played. Limit: a
  `.htaccess` that someone later puts into the store is read by the web server too and can override these rules.
  **Recommendation:** use a FRITZ!Box user with **read access only** for the network share (then nobody can write
  into the store through the LoxBerry) and a dedicated music subfolder as the store, not the whole drive.
  **Watch:** the service checks the released store again every 15 minutes (own thread, not during a doorbell or
  announcement, at most 120 s and 50,000 entries – if that is not enough, the release stays as it is and the log
  says so). If it finds a `.ht*` file, a program extension (same list, also inside a name – `song.php.mp3` blocks
  the store until it is renamed) or a link pointing outside, it withdraws the release at once: link removed,
  `server/ablage_ok` 0, state “unsichere Datei gefunden: <path in the store>” (unsafe file found), a log line.
  Once the file is gone, the next check releases the store again. The same name rule applies when setting up.
  Between two checks the second lock protects.
  **File system of an external drive:** ext4 is best on the LoxBerry (permissions, stability); exFAT is good if the
  drive is also filled on a Windows PC (Debian 13 supports exFAT in the kernel); NTFS works (ntfs3) but only as a
  stopgap (no Linux permissions, possibly read-only after unsafe removal); not FAT32 (4 GB limit, umlauts). A drive on
  a FRITZ!Box: exFAT or NTFS, attached as an SMB network share. Power: the Pi 4 supplies about 1.2 A in total over
  USB – an NVMe enclosure may need a powered hub. The *Audio* tab shows the detected file system of the store.
* **Doorbell:** `chromecast4lox/befehl/klingel` (default target) or `…/befehl/zone_<No>/klingel` as a pulse. Main
  path: a Google speaker group (in sync); if it does not start within 8 s (app start included), the fallback list
  plays in parallel (empty = the members of the doorbell group once reported, otherwise all speakers, never TVs
  or groups). Own chime,
  own volume, optional announcement afterwards; quiet hours lower the volume instead of muting. Google plays a short
  sound of its own when playback starts (the “dip”) – the plugin cannot switch it off. Start your own chime with
  about 0.3–0.5 s of silence and fade it in softly, otherwise it clicks.
  **Chime independent of the music:** a file name in the field *Chime* is relative to the media store. If the
  file is missing there (e.g. after switching the store from a USB drive to the FRITZ!Box), your own chime plays
  (tab *Audio*, uploaded: MP3/OGG/WAV up to 1 MB, kept with the settings, survives an update) or the supplied
  standard chime (0.4 s of silence, a softly faded-in two-tone generated by the plugin, served under
  `/plugins/<folder>/klang/`). The doorbell is never silent; the log names the fallback, the tab *Test* warns
  about sources and a chime with a missing file.
* **Volume:** set in whole percent and sent unchanged. The pychromecast 9.4.0 log line shows one decimal only
  (`setting volume to 0.2` for 25 %); the value sent is 0.25. Both Google Home Speakers in the house reported 45 %.
* **Limits:** no music interface in the Loxone app yet (stage 2); only a Google group plays in sync; a group
  interrupts its members – with “resume” on, every speaker that was playing and went silent because of the group is
  resumed afterwards; the doorbell via Cast takes about 1 s with the Cast app running and about 2.5 s from a
  cold start (measured 30.10.2026); Loxone Radio is not reachable; at most 200 tracks per list. Tested with mock
  devices and on 30.10.2026 with two Google Home Speakers and a Google group.

## Neu in 1.3.24

Umstellung auf das MQTT-Gateway für Werte **und** Befehle, wie im
[LoxBerry-Plugins Musterprojekt](https://github.com/timanders22/LoxBerry-Plugins-Musterprojekt). Gemessen an
Attrappen für Lautsprecher, Broker und Dienst unter PHP 7.4, 8.4 und 8.5 und Windows-Python; nicht am Gerät,
nicht an einem echten Lautsprecher.

* **Befehle über die gemeinsame Befehlsbrücke:** `chromecast4lox/befehl/<Befehlsname>/<Befehl>` und
  `chromecast4lox/befehl/sperre`. Der Befehlsname ist das Gerätethema klein, `-` wird `_` (`Wohnzimmer` →
  `wohnzimmer`); `alle` erreicht alle Lautsprecher. Jede Nachricht bekommt eine Quittung
  (`…/quittung`, nie retained); ausgeführt wird im Dienst an derselben Stelle wie bisher – Sperre aus
  Loxone, Gleichwert-Bremse, Obergrenze und Ruhezeit gelten unverändert. Ab Werk aus: Reiter *MQTT*, Haken
  „Befehle über MQTT annehmen“; alles Hörbare (Wiedergabe, Lautstärke, lauter, stumm, Favorit, Ansage, App
  schließen) braucht zusätzlich „Lautsprecher über MQTT steuern“. Über die Brücke setzt `play` nur fort;
  `volume_up`/`volume_down` sind Pulse mit der Schrittweite des Plugins – die Vorlage muss nach einer
  Änderung nicht mehr neu erzeugt werden. Neue Vorlage „MQTT-Befehle“ mit der Sperre.
* **Bestehende Anlagen merken nichts:** der alte Weg `chromecast4lox/<Gerät>/cmd/<Befehl>` steht hinter
  dem Haken „Alten Befehlsweg annehmen“ – nach einem Update an, bei einer Neuinstallation aus. Ebenso
  bleibt der UDP-Befehlseingang bei einem Update, wie er war; bei einer Neuinstallation ist er ab Werk aus.
* **Werte als einfache Virtuelle Eingänge:** die Eingangsvorlage mit `http://localhost` entfällt; der Reiter
  *Einbindung in Loxone* zeigt eine Namenstabelle (Name genau so, wie das Gateway ihn vergibt) und für
  Gateway V2 die Hakenliste (19 Haken im Musterprojekt, „Noncached“ nur `server/online`). Für Gateway V1
  liegt die Abo-Datei bei. **`volume`, `duration` und `favorit` melden `-1`**, wenn der Wert unbekannt ist
  bzw. das Gerät aus der Liste genommen wurde (vorher 0 bzw. „-“) – Prüfungen auf „= 0“ in eigenen
  Projekten bitte ansehen. Die Ausfallerkennung rechnet mit Tmc mindestens 180 s.
* **Warnung bei Gerätenamen** „server“ und „befehl“ und bei zwei Geräten mit demselben Befehlsnamen.
* **Korrekturen aus einer Fremdprüfung:** Konfiguration und Zweitschrift nach jedem Update 0600; der
  Arbeitsfaden eines entfernten Lautsprechers endet; die örtliche Ansage nennt den Port des Webservers;
  die Selbstprüfung der Formulare prüft je Formular; Sicherungs-Download ohne fremde Ausgabe davor;
  UDP-Port im Formular ab 1024; ein einheitliches Muster für `enabled` in Dienststart, Wächter und
  Installation; die Wurzelsuche in preinstall wie in postinstall; die Prüfsumme der mitgelieferten
  Konfiguration in postinstall ist wieder aktuell (seit 1.3.22 veraltet).
* Die Baustein-Listen im Reiter *Einbindung in Loxone* folgen dem umgestellten Musterprojekt (Befehle am
  gemeinsamen Ausgang „LoxBerry MQTT-Gateway“, neu „Ansagen sperren“ auf der Seite „Chromecast4lox
  Steuerung“); beide Bilder sind neu aus dem angeordneten Musterprojekt.

## Neu in 1.3.23

Einbindung in Loxone: Eingang En der Analogwertvalidierung nicht negieren (Hinweis und Bild); ohne diese Korrektur löste die Ausfallmeldung nie aus.
Config legt den Eingang En negiert an – die Konstante 1 kam dann als 0 an, und „Dienst zählt nicht mehr“ (#16 der Seite „Chromecast4lox“) prüfte
die Zeit Tmc nicht. Wer die Seite schon gebaut hat: Haken bei ‚En‘ entfernen (Eigenschaftsfenster, Parameter). Das Bild zeigt den Eingang
jetzt ohne Negation. Plugin-Verhalten unverändert.

## Neu in 1.3.22

Gleichstand mit Alexa-NG für die Richtung Loxone → Google (Bauschritte B1 bis B3 aus dem Entwurf
vom 15.10.2026). Gemessen an Attrappen für
Lautsprecher und Dienst unter PHP 7.4, 8.4 und 8.5 und Windows-Python; nicht am Gerät, nicht an einem
echten Lautsprecher.

* **Sperre aus Loxone** (ab Werk aus, Haken im Reiter *Einstellungen*): Loxone hält Ansagen an, etwa bei
  Abwesend, Gäste oder Schlafen – MQTT `chromecast4lox/server/cmd/sperre` mit `1` bzw. `0`, UDP
  `server/SPERRE 1;`, oder für andere Plugins `aktion=sperre&wert=1|0` mit dem Sprechtoken. Gesperrt
  werden Ansagen aus Loxone und über die Sprachausgabe für andere Plugins übersprungen
  (`200 UEBERSPRUNGEN=1;GRUND=GESPERRT`); `dringend=1` geht durch und hat damit erstmals eine Wirkung.
  Musik, Lautstärke und alle übrigen Befehle bleiben frei. Der Reiter *Test* zeigt den Zustand.
* **Lautstärke und Geräteliste über HTTP** wie bei Alexa-NG: `aktion=lautstaerke&geraet=…&wert=0-100`
  und `aktion=geraete` – nur von diesem LoxBerry, mit dem Sprechtoken, nur mit eingeschalteter
  Sprachausgabe, dieselbe Stundengrenze. Die Lautstärke geht über denselben Weg wie `cmd/volume` aus
  Loxone: Obergrenze, Ruhezeit und die Gleichwert-Bremse (`UNVERAENDERT`) gelten.
* **Neuer Abschnitt „Google ↔ Loxone“** in Hilfe und README: was dieses Plugin für Google leistet und was
  nur über die Matter-Bridge in Matter2Lox und Google-Home-Automationen geht.
* Der **Google Home Speaker** ist genannt: als Matter-Hub von Google belegt; ob er sich per Google Cast
  ansprechen lässt, ist nicht gemessen.
* Vorlagen, Baustein-Listen und Musterprojekt sind unverändert.

## Neu in 1.3.21

Vollausstattung aus dem [LoxBerry-Plugins Musterprojekt](https://github.com/timanders22/LoxBerry-Plugins-Musterprojekt): Der Reiter „Einbindung in
Loxone“ zeigt zwei Baustein-Listen – Werte, Meldungen und Wiedergabe (mit Titel, Interpret und App über
Virtuelle Texteingänge) sowie Steuerung, Ansage und Automatik (Lautstärke, Favoriten, Befehle an alle
Lautsprecher, Türklingel-Ansage, alles aus beim Verlassen des Hauses) –, je mit Bild und
Schritt-für-Schritt-Anleitung (44 und 35 Zeilen statt 19).

## Neu in 1.3.20

Reiter „Einbindung in Loxone“ zeigt ein Bild der Bausteine aus dem gemeinsamen Musterprojekt und
verlinkt die Projektdatei; die Baustein-Liste ist die dort in Loxone Config gebaute.

* Unter der Baustein-Liste (Schritt 6) steht das Bild der Seite „Chromecast4lox“ aus dem
  [LoxBerry-Plugins Musterprojekt](https://github.com/timanders22/LoxBerry-Plugins-Musterprojekt); das Bild liegt im Plugin,
  nachgeladen wird nichts. Config kürzt lange Bausteinnamen, die vollen Namen stehen in der Tabelle.
  Das Musterprojekt zeigt das Beispielgerät „Wohnzimmer“, die Liste den Platzhalter `<G>`.
* **Baustein-Liste neu (19 statt 17 Zeilen):** eine Zeile = ein Baustein, nur die Hauptvariante, so wie
  im Musterprojekt gebaut und verbunden. Die Eingänge `<G>_playing`, `<G>_volume`, `server_online` und
  `server_zaehler` kommen aus der Eingangsvorlage „Chromecast 4 Lox“, die Befehle
  `chromecast4lox_<G>_play`, `_stop`, `_volume_up` und `_volume_down` aus der Ausgangsvorlage
  „Chromecast 4 Lox“ (MQTT, über den UDP-Eingang des MQTT-Gateways). Ohne MQTT-Gateway bleibt die
  Vorlage „Chromecast 4 Lox UDP“ der Weg. „Musik <G>“ ist ein virtueller Eingang (Schalter), eine
  Flankenerkennung schickt play und stop. Die Konstante 1 an En der Analogwertvalidierung hat eine
  eigene Zeile; beide Störungen laufen über ein ODER in eine Benachrichtigung. Unter der Tabelle drei
  neue Hinweise, die Erläuterung auf die neuen Nummern.
* Gerendert unter PHP 7.4, 8.4 und 8.5, nicht am Gerät angesehen.

## Neu in 1.3.19

Baustein-Liste in der Schreibweise des Leitungswerkzeugs.

* **Baustein-Liste (Reiter Einbindung in Loxone):** Der Folgebaustein steht jetzt in der Spalte
  „Baustein (Typ)“, etwa `Flankenerkennung (steigend) → Ausgangsbefehl play` und
  `Einschaltverzögerung → Benachrichtigung`; die Spalte „Eingänge verbinden mit“ nennt nur noch
  die Quelle (`Eingang = #7`, `Eingang = #12`). Gleiche Bausteine, gleiche Verbindungen.
* **Ausfallerkennung (#17): Analogwertvalidierung statt „Änderungsüberwachung“** – diesen Baustein
  gibt es in Loxone Config nicht. Parameter Tmc wie die bisherige Schwelle (dreifacher Takt, mindestens
  60 s), Min 0, Max 999; der Zähler `<G>_server_zaehler` (#16) an V, an En eine Konstante 1; der
  Ausgang E (error) geht an die Benachrichtigung. Beendet sich der Dienst, sendet er -1 und löst damit
  ebenfalls aus.
* Gerendert unter PHP 7.4, 8.4 und 8.5, nicht am Gerät angesehen.

## Neu in 1.3.18

Kopf wie alle Hausplugins: Statusübersicht über den Reitern, Zusammenfassung oben im ersten Reiter.

* **Statusübersicht über den Reitern** als Tabelle: Dienst (mit PID), Zahl der Geräte, MQTT, UDP-Befehle,
  Adresse des LoxBerry. Dieselben Werte standen bisher als eine Zeile in einem blauen Hinweiskasten.
* **Zusammenfassung** des Plugins in einem grünen Kasten oben im Reiter Einstellungen.
* Nur Oberfläche; gerendert unter PHP 7.4, 8.4 und 8.5, nicht am Gerät angesehen.

## Neu in 1.3.17

Gleiche Lautstärke wird nicht wiederholt (Nachzug B: X-7, Entscheidung 19).
Gemessen am Dienst mit einem Attrappen-Lautsprecher unter Windows-Python 3.14 und WSL-Python 3.12; nicht am Gerät, nicht an einem echten Lautsprecher.

* **Gleiche Lautstärke wird nicht wiederholt.** `volume` bzw. `set_volume` mit demselben wirksamen Pegel wie vor weniger als 60 s geht nicht erneut an den Lautsprecher. Der wirksame Pegel ist der Wert nach Obergrenze und Ruhezeit. Das Protokoll zeigt das mit `UNVERAENDERT=1`, `last_error` bleibt leer. Damit entfällt das wiederholte Senden aus einem Loxone-Analogausgang.
* **Vergleich je Lautsprecher:** Verglichen wird mit dem zuletzt gesendeten Pegel. Auch `volume_step`, `volume_up` und `volume_down` merken ihren Pegel; die Folge „40, lauter, 40“ setzt beim zweiten Mal also wieder 40. Nach einer neuen Verbindung zum Lautsprecher und nach einer Ansage mit eigener Lautstärke wird wieder gesendet.
* **Nicht gebremst** werden Schritte, lauter/leiser, `mute`, die Tasten (`play`, `pause`, `stop`, `next` …), Favoriten und Ansagen.
* Eine Änderung der Lautstärke in der Google-App sieht die Bremse nicht. Derselbe Wert aus Loxone geht dann erst nach 60 s wieder hinaus.

## Neu in 1.3.16

Nur Beschreibung, kein geänderter Code.

* Die Einleitung nennt jetzt alle unterstützten Google-Cast-Geräte: **Chromecast**, **Google Home / Nest Speaker** (Home, Home Mini, Nest Mini, Nest Audio, Nest Hub, Lautsprechergruppen) sowie **Google TV Streamer (4K)** und Fernseher mit **Chromecast built-in**. Das Plugin war schon bisher nicht auf den Chromecast-Stick beschränkt; an echten Geräten im Haus des Autors ist das weiterhin nicht gemessen.

## Neu in 1.3.15

Neu: Sprachausgabe für andere Plugins (Punkt Ansage-3 der Verbesserungsliste). Gemessen an
Attrappen für Lautsprecher, Dienst und Broker unter PHP 7.4, 8.3 und 8.5 sowie mit dem unveränderten
Alexa-NG-Anschluss des Abfahrts-Assistenten als Verbraucher; nicht am Gerät, nicht an echten Lautsprechern.

* **Sprech-Endpunkt** `http://127.0.0.1/plugins/chromecast-4lox-ng/index.php` mit `aktion=sprechen` –
  dieselbe Schnittstelle wie Alexa NG. So können andere Plugins (Sprachsteuerung, Abfahrts-Assistent,
  Abfuhrkalender, FerienFeiertage, Octopus …) Ansagen auf Google-/Nest-Lautsprechern und Chromecasts
  ausgeben. **Ab Werk aus**; einschalten im Reiter Einstellungen, Abschnitt „Sprachausgabe für andere
  Plugins“.
* Ziel: ein Gerät, eine Kommaliste, `gruppe:<Name>` oder `alle`; ohne Angabe das Standardgerät. Ein
  unbekannter Name ergibt 404 – nie einen Rückfall auf „alle“. `laut=` gilt nur für die Ansage, danach
  wird die vorige Lautstärke wiederhergestellt.
* Das **Sprechtoken** wird wie ein Kennwort behandelt: nie in einer Adresse, nie im Protokoll (nur die
  Textlänge), nicht in „Einstellungen sichern“. Angenommen wird nur von diesem LoxBerry selbst.
* `OK=1` heißt: der Dienst hat die Ansage bei den verbundenen Lautsprechern eingereiht. Antwortet der
  Dienst nicht binnen 5 s, wird nichts gesprochen (503) – auch nicht später nach einem Neustart.
* Ruhezeit und Lautstärke-Obergrenze gelten wie bei Ansagen aus Loxone; höchstens 60 Ansagen je
  Stunde (einstellbar), derselbe Text an dasselbe Ziel binnen 30 s wird nicht wiederholt.
* Reiter Test: Knopf „Testansage“ und eine Prüfzeile zur Sprachausgabe.

## Neu in 1.3.14

Verbesserungen aus dem Durchgang vom 30.09.2026 (Verbesserungsliste
`Pruefung-Durchgang-2026-09-29/VERBESSERUNGEN_OFFEN.md`). Gemessen an Attrappen
für Lautsprecher und Broker unter PHP 7.4, 8.3 und 8.5; nicht am Gerät.

* **Zurückbehaltene Befehle unter `cmd/` werden beim Verbinden gelöscht** und
  nachgelesen, höchstens dreimal je Verbindung; was stehen bleibt, zeigt der
  Reiter Test. Bis 1.3.13 konnte ein alter retained Befehl nach jedem Neustart
  erneut ausgeführt werden.
* **Eine leere MQTT-Nachricht auf `cmd/` wird nie als Befehl ausgeführt.** Löscht
  ein anderes Werkzeug ein retained Befehlsthema, lief bisher z. B. `volume_up`
  los. Über MQTT braucht jeder Befehl jetzt einen Wert (z. B. `1`); die
  Loxone-Vorlage sendet ohnehin nie leer. UDP bleibt unverändert.
* Hinweis beim Übernehmen einer alten Kommaliste („einer je Zeile?“).
* JSON-Dateien ohne Geheimnisse haben die Rechte 0644; Konfiguration und
  Einmalmeldung bleiben 0600.
* **Bei einer Beanstandung wird nichts gespeichert** (bis 1.3.13 wurden die
  übrigen Felder übernommen); die eingetippten Werte stehen wieder im Formular.
  „Einstellungen sichern“ warnt bei Werten, die das Zurückspielen nicht bestünden.
* Ansagen aus anderen Plugins: über MQTT `chromecast4lox/<gerät>/cmd/tts` mit dem
  Text als Nutzlast (nicht retained); `alle` spricht jeden Lautsprecher an.

## Neu in 1.3.13

Durchgang vom 30.09.2026 mit vier Prüfern (Code, Oberfläche, Installer, MQTT).
Gemessen an Attrappen; ein echter Chromecast war nicht angeschlossen. Befunde
mit Datei:Zeile: `Pruefung-Durchgang-2026-09-29/Chromecast4lox_BEFUNDE_UND_VERBESSERUNGEN.md`.

**„Einstellungen sichern“ funktioniert zum ersten Mal.** Der Knopf endete seit
mindestens 1.3.4 in einem PHP-Fehler. Die Sicherung trägt jetzt einen lesbaren
Kopf. Beim Zurückspielen wird jeder Wert geprüft wie beim Speichern; bis 1.3.12
machte eine Sicherung mit einer Liste als Token den Formularschutz für jeden
ausrechenbar.

**Befehle von außen**

* Der UDP-Eingang nimmt Befehle nur noch von den Miniservern (aus der
  LoxBerry-Konfiguration) und vom LoxBerry selbst an. Andere Absender werden
  verworfen und gebremst protokolliert. Bis 1.3.12 konnte jedes Gerät im
  Heimnetz alle Lautsprecher steuern.
* `volume inf` oder `seek 1e400` trennen die Verbindung zum Lautsprecher nicht
  mehr; Werte außerhalb 0–100 werden abgewiesen und gemeldet, nicht still
  gekappt. `volume_down` mit negativem Wert umgeht die Lautstärkegrenze nicht
  mehr.
* Ein retained Befehl unter `cmd/` wird nicht mehr bei jedem Verbinden
  ausgeführt (bis 1.3.12 wiederholte sich so eine Ansage nach jedem Neustart).

**Dienst**

* Beim Systemstart liefen bis zu drei Dienste (daemon plus zwei Wächterläufe,
  wenn cron nach einem Uhrsprung Minuten nachholt). Jetzt sperrt eine
  Startsperre, und ein zweiter Dienst beendet sich sofort.
* Das Protokoll gehört nicht mehr root: Beim Start mit ausgeschaltetem Dienst
  legte `daemon` es als root an, danach ließ sich der Dienst bis zum nächsten
  Neustart nicht mehr starten, und die Oberfläche meldete trotzdem „gestartet“.
* Der Dienst schreibt sein Protokoll selbst; nach dem Leeren der Ramdisk geht
  nichts mehr ins Leere. Beim Anhalten meldet er sich ab (`server/online 0`,
  Geräte offline, `tts_active 0`).

**Einstellungen**

* Favoriten und Gerätenamen überstehen das Speichern: aus drei Favoriten wurde
  bis 1.3.12 einer, und Namen wie „Åsa Küche“ gingen verloren. Ein Komma trennt
  keine Geräte mehr – eine alte Liste mit Kommas wird zu einem Gerät und ist
  einmal neu einzutragen.
* Die Konfiguration hat die Rechte 0600 und wird so geschrieben, dass ein voller
  Datenträger sie nicht kürzt.

**MQTT**

* Ein entferntes Gerät bekommt einmal `-` und wird dann abgeräumt.
* `online 0` eines ausgefallenen Geräts und `server/online 1` gehen mit jeder
  Vollmeldung erneut hinaus.
* Ein altes Präfix wird bei Start und Deinstallation mit abgeräumt.

**Installation:** Eine Neuinstallation spielt keine alte Zweitschrift mehr ein
(`preinstall.sh`, `.alt`); eine kaputte Konfiguration überschreibt beim Update
nicht mehr die heile.

## Neu in 1.3.12

Nachlese nach den Hausregeln vom 18./19.09.2026 (MQTT-Retain) und den
Prüfmustern vom 24./25.09.2026. Gemessen in WSL, nicht am Gerät
(`Pruefung-Chromecast4lox-1.3.12`, 41 Fälle).

- **Was der Dienst über sich selbst sagt, bleibt nicht mehr im Broker
  stehen.** `<gerät>/online` (aus dem eigenen Verbindungsversuch),
  `server/geraete` und `server/verluste` gehen flüchtig hinaus. Stirbt der
  Dienst, stand bisher weiter `online 1` im Broker. Der alte Wert wird beim
  Verbinden einmal abgeräumt, wie seit 1.3.9 für `ts` und `zaehler`. Ob der
  Dienst lebt, sagt `server/online`: retained, mit Letztem Willen `0` auf
  demselben Thema — auch nach einer Neuverbindung geht `1` wieder hinaus.
- **Ein nicht erreichbares Gerät überschreibt seinen letzten Stand nicht
  mehr.** `state OFFLINE` und `playing 0` gehen dann flüchtig hinaus: Loxone
  sieht sie wie bisher, im Broker bleibt der letzte Stand des Geräts.
  Meldet sich das Gerät zurück, geht sein Stand retained hinaus, auch wenn
  er gleich lautet wie der Platzhalter.
- **Die Deinstallation räumt die zurückbehaltenen Themen ab** —
  `server/online` samt der übrigen retained Themen dieser Linie, auch die
  eines Geräts, das nicht mehr in den Einstellungen steht — und liest beim
  Broker nach, ob sie wirklich weg sind. Befehlsthemen (`<gerät>/cmd/…`)
  und fremde Themen unter demselben Präfix bleiben. Verweigert der Broker
  die Anmeldung oder das Lesen, steht das im Installationsprotokoll, statt
  „nichts gefunden". Hängt der Schritt, bricht er nach 60 s ab und sagt es.
- **Ein geändertes Themenpräfix** räumt das alte Präfix ebenso ab. Grenze:
  was unter einem Präfix liegt, das vor 1.3.12 gewechselt wurde, räumt
  niemand mehr ab — im MQTT Finder des Gateways von Hand löschen.
- **Aus einem ausgepackten Archiv** arbeitet weder der Dienst noch die
  Oberfläche mit der Anlage, es sei denn, `LBHOMEDIR` und `LBPPLUGINDIR`
  sind beide gesetzt. Bisher nahm ein Archiv unterhalb einer Installation
  deren Konfiguration und Broker, und „Dienst neu starten" startete den
  Dienst der Anlage. Ohne gefundene Wurzel fragt die Oberfläche keine Pfade
  ab `/` mehr ab.
- **Die Installationsskripte prüfen die LoxBerry-Wurzel**
  (`config/system/general.json`) und tun ohne sie nichts, statt gegen
  Pfade ab `/` zu laufen und Erfolg zu melden.
- **Kleinere Punkte:** Die Upgrade-Marke gilt auch mit einem Zeitpunkt bis
  300 s in der Zukunft (nachgestellte Uhr). Die Gerätesuche bricht ein
  hängendes Python nach 30 s wirklich ab und sagt es. `postinstall.sh`
  spielt eine Zweitschrift ohne Aktionstoken nicht mehr zurück. Die
  Anleitung zur Ersteinrichtung erscheint nur noch, wenn keine Geräte
  eingerichtet sind.

## Neu in 1.3.11

- **Ein abgebrochenes Update kostet die Sicherung nicht mehr.**
  `preupgrade.sh` löschte die vorhandene Sicherung
  (`data/plugins/<ordner>.upgrade_sicherung`), bevor die neue stand. Brach
  ein Update nach dem Abräumen des Plugins ab und wurde erneut angestoßen,
  gab es nichts mehr zu sichern — die Einstellungen und die
  Sicherungsarchive waren fort (in WSL nachgestellt, nicht am Gerät: 14 von
  14 Dateien). Jetzt entsteht die neue Sicherung daneben, wird Datei für
  Datei geprüft und ersetzt die alte erst dann. Eine Sicherung mit
  Aktionstoken wird nie durch eine ohne ersetzt.
- **Die Zweitschrift der Einstellungen wird nach Inhalt beurteilt.**
  Bisher genügte, dass die Datei nicht leer war: eine abgeschnittene
  Einstellungsdatei und die mitgelieferte Vorgabe überschrieben die heile
  Zweitschrift. Jetzt zählt eine Datei nur, wenn sie `[CONFIG]` und ein
  vollständiges Aktionstoken trägt. Geschrieben wird die Zweitschrift über
  eine Nebendatei, damit ein Abbruch beim Schreiben sie nicht leert.
- **`postinstall.sh` holt die Einstellungen auch aus einer abgeschnittenen
  Datei zurück**, wenn die Zweitschrift ein Aktionstoken trägt und die
  Datei nicht. Der verdrängte Stand bleibt als `….cfg.kaputt` (nur für
  loxberry lesbar) liegen.
- **`postupgrade.sh` räumt die Sicherung erst weg, wenn alles
  zurückgespielt ist.** Scheiterte das Zurückspielen (etwa bei voller
  Karte), war die Sicherung bisher trotzdem fort.

## Neu in 1.3.10

- **Nach einem Update läuft genau ein Dienst.** Bis 1.3.9 lief der alte
  Dienst durch das Update weiter, ohne dass ihn jemand sah: seine
  PID-Datei löscht der Installer mit dem Datenordner. Startete der Wächter
  in dieser Zeit einen zweiten, beendete `postroot.sh` nur einen, und zwei
  Dienste liefen nebeneinander auf demselben UDP-Port — bis zum Neustart
  des LoxBerry, auch nach einer Deinstallation (in WSL nachgestellt, nicht
  am Gerät). Jetzt hält `preupgrade.sh` den Dienst an, und `postroot.sh` und
  die Deinstallation beenden jeden Prozess dieses Dienstes: genau ein
  Python-Interpreter mit dem Dienstpfad dieses Plugins, nur Benutzer
  loxberry.
- **Der Wächter startet während eines Updates nichts.** `preupgrade.sh`
  legt `data/plugins/<ordner>.upgrade_laeuft` an, `cron.05min` startet
  nicht, solange die Marke höchstens eine Stunde alt ist, `postroot.sh`
  entfernt sie nach dem Start. Bricht ein Update ab, gilt die Marke nach
  einer Stunde nicht mehr.
- **Das Protokoll behält die Zeilen aus dem Update.** Es wird nicht mehr
  gesichert und zurückkopiert; der Installer lässt `log/` beim Update
  ohnehin stehen, und das Zurückkopieren überschrieb die neuen Zeilen.
- **Die Deinstallation räumt die Zweitschrift der Einstellungen weg**
  (`config/plugins/<ordner>.backup.chromecast-4lox-ng.cfg`). Sie trägt das
  Aktionstoken, und `postinstall.sh` spielt sie bei einer Neuinstallation
  zurück, solange die Konfiguration der Vorgabe gleicht.
- **Themenpräfix, MQTT-Schalter und UDP-Port wirken auch ohne Neustart.**
  Ändert sich die Konfigurationsdatei, baut der Dienst MQTT-Verbindung und
  UDP-Empfang neu auf; bisher übernahm er nur Geräte und Takt.
- `postinstall.sh` findet seine Ordner auch bei ausgeräumter Umgebung;
  `postupgrade.sh` meldet nicht mehr, Eigentümer gesetzt zu haben (es läuft
  als loxberry und konnte das nie).
- **Die Oberfläche fasst während einer Aktualisierung nichts an.** Zwischen
  den neuen Dateien und `postinstall.sh` ist die Konfigurationsdatei die
  mitgelieferte Vorgabe. Bis dahin zeigte die Seite diese Vorgabe als Ihre
  Einstellungen an, nahm Eingaben an, meldete Erfolg und startete den Dienst
  damit — und der Installer warf beides Sekunden später weg. Jetzt zeigt sie
  in dieser Zeit einen Hinweis, auf Deutsch und auf Englisch, und die Knöpfe
  im Reiter Test weisen ebenso ab.
- **Die Oberfläche erkennt ihren eigenen Dienst.** Fehlte die PID-Datei,
  suchte sie mit `pgrep -o -f` im ganzen System nach einer Zeichenkette und
  fand dabei auch den Dienst eines zweiten Plugin-Ordners: „Dienst anhalten"
  beendete den fremden. Jetzt gilt dieselbe argumentweise Erkennung wie in
  den Installationsskripten — genau zwei Argumente, ein Python-Interpreter
  und der volle Dienstpfad dieses Plugins, Benutzer `loxberry`. Und es werden
  **alle** eigenen Prozesse behandelt, nicht der erste.
- **Der Wächter findet einen Dienst ohne PID-Datei.** Nach einem Update gibt
  es sie nicht mehr; bisher startete er deshalb einen zweiten Dienst. Jetzt
  sucht er zusätzlich argumentweise, trägt die PID-Datei nach und meldet es.
- **Ein angehaltener Dienst bleibt angehalten.** `daemon/daemon` startete
  beim Hochfahren und aus `postroot.sh` bedingungslos — auch bei
  `enabled=0`, auch während einer Aktualisierung, auch wenn schon einer lief.
  Jetzt prüft es alle drei Punkte, und `postroot.sh` sagt im
  Installationsprotokoll, warum der Dienst aus bleibt.
- **Der Wächter lief nicht, wenn der Protokollordner fehlte.** Er liegt auf
  der Ramdisk; fehlte er, scheiterte die Umlenkung der Fehlerausgabe, und das
  Skript beendete sich, bevor eine Zeile gelaufen war. Der Ordner wird jetzt
  vorher angelegt.
- **Die Deinstallation löscht nichts an einem Ort, der kein LoxBerry ist.**
  Die Wurzelsuche verlangt jetzt `config/system/general.json`; ohne sie wird
  gewarnt und gesagt, was von Hand nachzusehen ist. Dieselbe Bedingung gilt
  für die Wurzelsuche der Oberfläche.
- **Was der Systemstart anlegt, gehört `loxberry`.** `daemon/daemon` und der
  Wächter legten Protokoll- und Datenordner im Wurzelzweig als `root` an; der
  Dienst hätte darin nicht schreiben können.
- **Ohne lesbare Uhr gilt die Aktualisierungsmarke.** Antwortete `date` nicht,
  wurde das Alter der Marke negativ, die Prüfung fiel durch, und Wächter oder
  `daemon` starteten mitten in der Aktualisierung. Ein Schutz fällt jetzt
  geschlossen aus.
- **Der Reiter Test trennt die eigenen Prozesse von der Suche nach dem
  Namen.** Die Liste darunter läuft über das ganze System und kann fremde
  Prozesse enthalten; das steht jetzt darüber.

## Neu in 1.3.9

- **Das Lebenszeichen geht nicht mehr retained hinaus.** `server/ts` und
  `server/zaehler` blieben bis 1.3.8 im Broker zurückbehalten. Nach dem Tod
  des Dienstes bekam jeder neu verbundene Teilnehmer einen Zählerstand, der
  wie Betrieb aussah. Jetzt gilt der Hausstandard: Zustände retained, das
  Lebenszeichen nie. Die alten Werte räumt der Dienst bei jeder Verbindung
  zum Broker ab. Ob der Dienst beendet ist, sagt weiter `server/online`
  (retained, mit Testament).
- Ein Thema ohne Eintrag in `bin/cc_themen.json` geht nicht retained hinaus
  (bis 1.3.8 umgekehrt).
- Der Reiter MQTT zeigt die Spalte *Retained* auch für die Themen des
  Dienstes; der Reiter Test fragt den Dienst selbst, ob das Lebenszeichen
  ohne Retain hinausgeht.

## Neu in 1.3.8

- **Tabellen mit Eingabefeldern rollen seitlich, statt abgeschnitten zu werden.**
  Die Oberfläche von LoxBerry schneidet breite Inhalte ab, ohne dass die Seite
  seitlich rollt; an BatterieBMS waren so zwei Einstellungen je Speicher nicht
  erreichbar. Diese Tabellen stehen jetzt im Rollbehälter der Hausform.

## Fassung 1.3.7 — der Wächter startet den Dienst wirklich nach

Ein Befund, am Gerät gemessen, kein neues Merkmal.

### Der Wächter hat seit 1.3.0 nie einen Dienst nachgestartet

`cron/cron.05min` startete den Dienst mit `su loxberry -c …`. LoxBerry ruft
jede Datei unter `cron.05min` aber **schon als `loxberry`** auf
(`/etc/cron.d/lbdefaults`), und `su` auf denselben Benutzer verlangt trotzdem
Rootrechte. Am Gerät gemessen am 06.09.2026:

    su loxberry -c "echo x"   →   su: Authentication failure   (Rückgabewert 1)

Damit war der Wächter wirkungslos: Starb der Dienst, blieb er tot, und in
Loxone sah alles normal aus — virtuelle Eingänge behalten ihren letzten
Wert. Aufgefallen ist es nur deshalb nicht, weil der Dienst nicht gestorben
ist.

Der Benutzer wird jetzt **gemessen** statt angenommen. Der `su`-Weg bleibt
für den Fall, dass wirklich `root` aufruft — beim Systemstart über
`daemon/daemon` ist das so, und dort war er immer richtig. Läuft der Wächter
als jemand drittes, sagt er es und startet nichts.

### Und er meldet keinen Erfolg mehr, den er nicht geprüft hat

`nohup … &` endet **immer** mit 0 — das sagt, dass die Shell abgezweigt hat,
nicht dass das Programm lebt. Die alte Fassung leitete genau daraus ihre
Erfolgsmeldung ab. Jetzt wird nach dem Start gewartet, die Prozessnummer
gelesen, `kill -0` geprüft und `/proc/<pid>/cmdline` gegengelesen; erst dann
steht „nachgestartet" im Protokoll.

### Die Rückrufe von `paho-mqtt` sind fassungsfest

Der Dienst legte `paho-mqtt` auf `CallbackAPIVersion.VERSION1` fest. Am
Gerät gemessen (`paho-mqtt` 2.1.0, echter Broker): das schreibt bei **jedem**
Dienststart die Zeile *„Callback API version 1 is deprecated"* in die
Protokolldatei.

Ein bloßer Wechsel auf VERSION2 hätte allerdings einen neuen Fehler
eingebaut, denn die beiden Fassungen übergeben nicht dasselbe:

| Rückruf | VERSION1 | VERSION2 |
|---|---|---|
| `on_connect` | `flags` (dict), `rc` (int) | `ConnectFlags`, `ReasonCode`, `Properties` |
| `on_disconnect` | `rc` | `DisconnectFlags`, `ReasonCode`, `Properties` |

`_on_disconnect` hätte unter VERSION2 also die Flags für den Trenngrund
gehalten und `DisconnectFlags(is_disconnect_packet_from_server=False)` ins
Protokoll geschrieben. Beide Rückrufe suchen den Grund deshalb jetzt, statt
ihn abzuzählen; danach erst wird die Fassung gewechselt — abgetastet, nicht
angenommen: VERSION2, sonst VERSION1, sonst ohne Argument (paho 1.x kennt
die Aufzählung nicht).

### Die Fehlerausgabe geht nicht mehr ins Leere

Der Cron des LoxBerry ruft jede Datei als `($f … > /dev/null 2>&1 &)` auf.
Alles, was der Wächter auf die Fehlerausgabe schrieb, war damit fort. Er
schreibt sie jetzt nach `cron.err` im Protokollordner des Plugins — der
Reiter *Logdateien* zeigt sie.

## Neu in 1.3.6

- **Das Auswahlfeld zeichnet seinen Pfeil selbst.** Bis 1.3.5 kam er von der
  Oberfläche des LoxBerry. Am 05.09.2026 am Gerät gemessen (LoxBerry 4.0.0.15,
  `system/css/components.css`): deren Regel `.lb-content select`
  gibt es erst seit der neuen Oberfläche, und jede eigene Feldregel mit der
  Kurzform `background:` löscht sie wieder. Darauf soll sich eine
  Plugin-Oberfläche nicht verlassen (`Regeln/04`). Sonst ist an dieser
  Fassung nichts geändert.

## Fassung 1.3.1 — eine Quelle für die Vorgabewerte

Ein Nachtrag zu 1.3.0, kein neues Merkmal. Er räumt drei Dinge auf, die eine
Durchsicht des veröffentlichten Standes gefunden hat.

### Die Vorgabewerte standen dreimal da

Die Oberfläche führte 28 Schlüssel, der Dienst 26, die mitgelieferte
Konfiguration 27 — `beschleunigung` fehlte dort ganz. Die Werte
widersprachen sich nicht, aber nichts hielt sie zusammen. Jetzt liest jede
Seite **`bin/cc_vorgaben.json`**, so wie beide Seiten schon die Themen aus
`bin/cc_themen.json` lesen. Lässt sich die Datei nicht lesen, wird nicht
geschrieben — eine geratene Liste über die Einstellungen des Anwenders zu
schreiben wäre schlimmer als gar nichts zu tun.

### Vervollständigen statt ergänzen

Fehlte ein Schlüssel, trat beim Lesen seine Vorgabe ein — die Datei blieb
lückenhaft, und *fehlt* war von *steht auf dem Vorgabewert* nicht mehr zu
unterscheiden. Jetzt schreibt das Plugin einen fehlenden Schlüssel **einmal**
mit seiner Vorgabe in die Datei, beim Laden der Oberfläche und beim
Dienststart. Vorhandene Werte werden dabei nicht angefasst, auch bewusst
geleerte nicht.

Im Reiter Test steht dazu eine neue Zeile: *Ist die Konfiguration
vollständig?* — mit der Zahl und, wenn etwas fehlt, den Namen.

### Was belegt ist und was erprobt ist, steht jetzt auseinander

Die beiden ab Werk ausgeschalteten Wege — **ereignisgesteuertes Melden** und
die **örtliche Ansage** — tragen die Kennzeichnung *(nicht am Gerät erprobt)*
jetzt an jedem Bedienelement, nicht nur im Hinweiskasten. Und die örtliche
Ansage meldet **„abgesetzt, Ergebnis unbekannt"** statt eines Erfolgs, wenn
der Lautsprecher den Auftrag zwar angenommen, aber nie zu spielen begonnen
hat. Genau so sieht es aus, wenn er die Grundadresse nicht erreicht — ein
„OK" an dieser Stelle wäre eine Behauptung gewesen.

Der bisherige Weg über Google bleibt unverändert.

## Fassung 1.3.0 — Wächter, Herzschlag, Selbstprüfung, eigener Reiter MQTT

Die größte Überarbeitung seit 1.0.0. Sie hat drei Wurzeln: eine zeilenweise
Durchsicht des ganzen Plugins, den Oberflächen-Hausstandard, und eine Reihe
von Funktionen, die im Betrieb gefehlt haben.

### Was jetzt erkennbar ist, wenn etwas nicht läuft

- **Ein Wächter** (`cron/cron.05min`) startet den Dienst alle fünf Minuten
  nach. Bis 1.2.12 lief er nach einem Absturz bis zum nächsten Neustart des
  Rechners nicht wieder an — und in Loxone sah das aus wie ein stiller
  Lautsprecher, weil virtuelle Eingänge ihren letzten Wert behalten. Der
  Haken *Dienst laufen lassen* schaltet ihn ab; die Knöpfe im Reiter Test
  ziehen ihn mit.
- **Ein Herzschlag.** `server/ts`, `server/zaehler`, `server/geraete`,
  `server/verluste` und `server/fassung` gehen bei jedem Durchgang hinaus,
  am Doppelt-senden-Filter vorbei. Denselben Stand schreibt der Dienst in
  eine Datei unter `data/` — daran erkennt der Reiter Test, ob er noch
  *arbeitet*. Eine Prozessnummer beantwortet das nicht.
- **Eine Selbstprüfung im Reiter Test**, zwölf Zeilen mit Haken, Kreuz oder
  Strich. Ein Strich heißt nicht „in Ordnung", sondern „hier wurde nichts
  gemessen".

### Neue Funktionen

- **Favoriten.** Je Zeile `Name = Adresse`, gestartet mit dem **analogen**
  Ausgangsbefehl `play_favorit` — ein Taster mit dem Wert 2 startet den
  zweiten Eintrag. Das schließt eine Lücke: mit `play` allein ließ sich aus
  Loxone kein Medium starten, weil die erzeugte Vorlage dort die Ziffer 1
  sendet.
- **Ein Sammelziel `alle`.** `<Präfix>/alle/cmd/tts` spricht auf allen
  eingetragenen Geräten. Für Musik bleibt eine Google-Lautsprechergruppe der
  richtige Weg: nur sie spielt synchron.
- **Ansage ohne Google.** Der Modus *Örtlich erzeugt* lässt `espeak-ng` eine
  Datei schreiben und liefert sie selbst aus. Die bisher benutzte Adresse bei
  Google ist nirgends zugesichert und im November 2020 schon einmal
  gebrochen.
- **Ein Klang vor der Ansage**, eine **Lautstärke-Obergrenze** und eine
  **Ruhezeit** mit eigener Grenze. Alle drei ab Werk aus.
- **`tts_stop`** bricht eine laufende Ansage ab und leert die Warteschlange.
- **Rückmeldung nach Loxone:** `last_error`, `tts_active` und `favorit`.
  Bisher stand eine Fehlermeldung nur im Protokoll.
- **Geräte suchen und übernehmen** im Reiter Einstellungen, mit einem Klick
  statt zeichengenauem Abtippen.

### Aufbau

- **Ein eigener Reiter MQTT.** Haken, Themenpräfix, Zustand des Gateways, das
  einzutragende Abo und die vollständige Thementabelle stehen jetzt an einer
  Stelle. Der Reiter Einstellungen trägt kein MQTT mehr.
- **Das MQTT-Gateway hat zwei Fassungen**, und der Satz „Ohne diesen Eintrag
  kommt am Miniserver nichts an" gilt nur für die erste. Er hängt jetzt an
  `Mqtt.Gatewayversion`; unter Fassung 2 steht dort, dass nichts einzutragen
  ist. Lässt sich die Fassung nicht lesen, stehen **beide** Sätze da.
- **Alle Themen kommen aus einer Datei** (`bin/cc_themen.json`), die der
  Dienst und die Oberfläche gemeinsam lesen. Der Reiter Test hält beide
  Seiten gegeneinander.
- **Ein Merkmal an jedem Formular** und ein Wachposten vor allen Handlern.
  Ohne ihn genügte ein Formular auf einer fremden Seite, um den Dienst
  anzuhalten.
- **Die Loxone-Vorlage ist vollständig:** `HintText`, `<Info templateType>`,
  Einheiten, echte Grenzen, `<v.0>` statt `<v>`, Dateinamen mit `VI_`/`VQ_`.
  Textthemen bekommen **keinen** virtuellen Eingang mehr — sie zeigten in
  Loxone dauerhaft 0. Der längste Kachelname war 120 Zeichen lang und ist
  jetzt kürzer als 40.

### Umbenannte Konfigurationsschlüssel

`themenpraefix` heißt jetzt **`mqtt_topic`**, `mqtt` heißt **`mqtt_ein`** —
die Namen, die auch die übrigen Plugins dieser Reihe führen. Der **Wert**
bleibt `chromecast4lox`; auf der Loxone-Seite ist nichts nachzuziehen.

### Zwei Dinge sind ab Werk aus

Sie sind gegen Attrappen und Primärquellen geprüft, aber **nicht an echter
Hardware**: die **ereignisgesteuerte Meldung** mit dauerhafter Netzsuche und
die **örtliche Ansage**. Der bisherige Abfragetakt bleibt in beiden Fällen
vollständig erhalten — schlägt der schnelle Weg fehl, steht es im Protokoll
und es läuft weiter wie bisher.

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

## Sprachausgabe für andere Plugins

Andere Plugins auf **diesem** LoxBerry können über Chromecast 4 Lox NG eine
Ansage auf Google-Lautsprechern abgeben (Google Home, Nest Mini,
Lautsprechergruppen, alle). Die Schnittstelle ist **dieselbe wie bei
[Alexa-NG](https://github.com/timanders22/LoxBerry-Plugin-Alexa-NG)**: gleiche
Felder, gleiche Antwortzeile, gleiche Codes. Ein Plugin, das Alexa-NG schon
anspricht, ändert nur den Ordner in der Adresse, die Gerätenamen und das Token.

**Ab Werk aus.** Einschalten im Reiter *Einstellungen*, Abschnitt
*Sprachausgabe für andere Plugins*: Haken setzen, ein **Sprechtoken**
eintragen (oder mit *Zufälliges Token einsetzen* würfeln und abschreiben),
optional ein Standardgerät und die Stundengrenze. Das Update ändert an einer
eingerichteten Anlage nichts.

### Aufruf

`POST http://127.0.0.1:<Port>/plugins/chromecast-4lox-ng/index.php`
(der Port steht in `config/system/general.json` unter `Webserver` → `Port`,
meist 80). Angenommen wird nur von diesem LoxBerry selbst (`127.0.0.1`/`::1`);
von anderswo kommt `403 GRUND=NUR_LOKAL`. Loxone spricht das Plugin weiter
über MQTT und UDP an.

| Feld | Inhalt |
|---|---|
| `aktion` | `sprechen` |
| `token` | das Sprechtoken (16–128 Zeichen aus `A–Z a–z 0–9 _ -`) – im Körper der Anfrage, nie in der Adresse |
| `geraet` | Gerätename wie in der Geräteliste (auch das MQTT-Thema, Groß/klein egal), Kommaliste, `gruppe:<Name>` oder `alle`; leer = Standardgerät |
| `text` | 1–1000 Zeichen UTF-8, ohne Steuerzeichen und ohne `<` `>`; `text=0` oder leer → `200 UEBERSPRUNGEN=1` |
| `laut` | optional 0–100, gilt nur für diese Ansage (danach gilt wieder die vorherige Lautstärke) |
| `dringend` | optional `0`/`1`; `1` übergeht die Sperre aus Loxone (seit 1.3.22) |

Dazu `?selftest=1&token=…` (prüft nur das Token, spricht nichts:
`SELFTEST;OK=1;TOKEN=OK;SPRECHEN=1;DIENST=1`) und `?aktion=status` (ohne
Token, lesend: `CHROMECAST4LOX;OK=1;SPRECHEN=1;DIENST=1;ALTER=…;GERAETE=…;ERREICHBAR=…;LETZTE_OK=…;LETZTE_ALTER=…`).
GET wird wie bei Alexa-NG angenommen; dann steht das Token aber in der Adresse
und im Zugriffsprotokoll des Webservers – deshalb POST.

### Antwort

Eine Zeile `text/plain`, jede Antwort nennt `GRUND`:

| HTTP | Beispiel | Bedeutung |
|---|---|---|
| 200 | `SPRECHEN;OK=1;GERAETE=2;UNVERAENDERT=0;OFFLINE=0;GRUND=EINGEREIHT` | der laufende Dienst hat die Ansage bei 2 verbundenen Lautsprechern eingereiht |
| 200 | `SPRECHEN;OK=1;GERAETE=0;UNVERAENDERT=1;GRUND=UNVERAENDERT` | derselbe Text ging in den letzten 30 s schon an dieses Gerät |
| 200 | `SPRECHEN;OK=1;UEBERSPRUNGEN=1;GRUND=TEXT_NULL` | leerer Text oder `0` |
| 200 | `SPRECHEN;OK=1;UEBERSPRUNGEN=1;GRUND=GESPERRT` | die Sperre aus Loxone ist gesetzt (ohne `dringend=1`) |
| 400 | `GRUND=TEXT`, `TEXT_FEHLT`, `LAUT`, `GERAET`, `KEIN_GERAET`, `SSML`, `SSML_OHNE_SCHALTER`, `DRINGEND`, `PARAMETER`, `AKTION` | Feld falsch |
| 403 | `GRUND=TOKEN`, `KEIN_TOKEN_EINGERICHTET`, `NUR_LOKAL` | Token falsch/fehlt, nicht von diesem LoxBerry |
| 404 | `GRUND=GERAET_UNBEKANNT;NAME=Bad`, `GRUPPE_UNBEKANNT`, `KEINE_GERAETE` | Gerät unbekannt – **nie** ein Rückfall auf „alle“ |
| 409 | `GRUND=SPRECHEN_AUS`, `TTS_MODUS` | ausgeschaltet; Ansagemodus `audioserver` |
| 429 | `GRUND=STUNDENGRENZE` | mehr als 60 Ansagen in der letzten Stunde (10–240 einstellbar) |
| 503 | `GRUND=DIENST_LAEUFT_NICHT`, `DIENST_ANTWORTET_NICHT`, `GERAETE_OFFLINE;OFFLINE=n`, `BESCHAEFTIGT`, `AKTUALISIERUNG`, `KONFIGURATION` | Dienst weg, kein Ziel verbunden |

**Was `OK=1` heißt.** Der Endpunkt legt die Ansage als Auftragsdatei in
`data/plugins/<Ordner>/sprechen/` und wartet höchstens 5 s, bis der laufende
Dienst sie angenommen und bei jedem **verbundenen** Ziel-Lautsprecher in dessen
Warteschlange gelegt hat (`GRUND=EINGEREIHT`). Ein nicht verbundener
Lautsprecher wird ausgelassen und gezählt (`OFFLINE=n`); ist es keiner, kommt
`503 GERAETE_OFFLINE`. Die Wiedergabe selbst (Klang, 3–60 s Ansage,
Wiederaufnahme) wird **nicht** abgewartet – sonst liefe jede längere Ansage in
die 10-s-Frist der aufrufenden Plugins. Ihr Ergebnis steht danach in der
Selbstprüfung (Reiter *Test*) und im Protokoll. Antwortet der Dienst nicht
binnen 5 s, zieht der Endpunkt den Auftrag zurück, bevor er
`503 DIENST_ANTWORTET_NICHT` meldet: dann wird auch später nichts gesprochen.

**Abgespielt wird über denselben Weg wie `cmd/tts` aus Loxone** –
Ansagemodus, Ruhezeit und Lautstärke-Obergrenze, Klang vorab, Fortsetzen und
die Warteschlange je Gerät gelten unverändert. `laut` wird durch die
Obergrenze und die Ruhezeit gedeckelt.

### Lautstärke, Geräteliste und Sperre (seit 1.3.22)

Mit demselben Sprechtoken, nur von diesem LoxBerry, und – bis auf die Sperre – nur mit
eingeschalteter Sprachausgabe (sonst `409 GRUND=SPRECHEN_AUS`):

| Aufruf | Antwort |
|---|---|
| `aktion=lautstaerke&geraet=…&wert=0-100` | `LAUTSTAERKE;OK=1;GERAETE=1;UNVERAENDERT=0;OFFLINE=0;GRUND=EINGEREIHT`; derselbe wirksame Pegel wie vor weniger als 60 s: `GERAETE=0;UNVERAENDERT=1;GRUND=UNVERAENDERT`; über der Obergrenze (mit Ruhezeit) zusätzlich `GRENZE=<n>`; `wert` fehlt oder liegt nicht in 0–100: `400 GRUND=WERT` |
| `aktion=geraete` | eine Zeile je eingetragenem Gerät, `GERAET;NAME=Wohnzimmer;THEMA=Wohnzimmer;ONLINE=1;LAUT=35;STATE=PLAYING` – ohne Adressen; `-1` bzw. `-` heißt: der Dienst kennt den Wert noch nicht; ohne Gerät `GERAET;OK=1;ANZAHL=0;GRUND=-` |
| `aktion=sperre&wert=1` bzw. `wert=0` | `SPERRE;OK=1;GESPERRT=1;UNVERAENDERT=0;GRUND=-`; ohne Haken `409 GRUND=SPERRE_AUS` (Abschnitt „Sperre aus Loxone“) |

`geraet` wie bei `sprechen` (leer = Standardgerät, nie ein Rückfall auf „alle“). Die Lautstärke geht
über denselben Weg wie `cmd/volume` aus Loxone (Auftragsdatei, Antwort binnen 5 s, Obergrenze,
Ruhezeit, Gleichwert-Bremse) und zählt zur selben Stundengrenze wie die Ansagen. Ein nicht
verbundener Lautsprecher wird ausgelassen und gezählt (`OFFLINE=n`).

### Unterschiede zu Alexa-NG

- **Adresse** (`/plugins/chromecast-4lox-ng/index.php` statt `/plugins/alexang/index.php`)
  und **Gerätenamen** (die Namen der Geräteliste dieses Plugins).
- Es gibt **ein** Token (Sprechtoken), auch für Lautstärke, Geräteliste und Sperre; ein
  Aktionstoken für die Schnittstelle gibt es nicht.
- `ssml=1` wird mit `400 SSML` abgewiesen. `dringend=1` übergeht seit 1.3.22 die Sperre aus
  Loxone; die Ruhezeit dieses Plugins sperrt weiterhin keine Ansage, sie begrenzt nur
  die Lautstärke.
- Kein Mindestabstand zwischen zwei Ansagen (`429 BREMSE` gibt es nicht): jedes
  Gerät spricht seine Warteschlange nacheinander ab.
- Nur Aufrufer auf diesem LoxBerry.

### Sicherheit

Das Sprechtoken wird **wie ein Kennwort** behandelt: es steht nie in einer
Adresse dieses Plugins, nie im Protokoll und nie in der Sicherung, und es
kommt nie zurück ins Formular (Kennwortfeld; leer lassen behält es, ein Haken
löscht es). Es ist vom Aktionstoken der Oberfläche getrennt. Eine Sicherung,
die ein Sprechtoken trägt, wird abgewiesen; beim Zurückspielen bleibt das
geltende. Der Ansagetext steht in keiner Antwort, keiner Merkdatei und keiner
Protokollzeile – nur seine Länge.

### Beispiel (PHP, wie die Ausgabeart „Alexa-NG“ der anderen Plugins)

```php
$ch = curl_init('http://127.0.0.1:' . $port . '/plugins/chromecast-4lox-ng/index.php');
curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query(array('aktion' => 'sprechen', 'token' => $token,
                                                 'geraet' => 'Wohnzimmer', 'text' => $text)),
    CURLOPT_NOPROXY => '127.0.0.1', CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 10,
));
$antwort = (string) curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$gesendet = ($code === 200 && strpos($antwort, 'SPRECHEN;OK=1') === 0);
```

## Sperre aus Loxone

Ab Werk aus (Reiter **Einstellungen**, Haken „Sperre aus Loxone annehmen“). Loxone hält damit Ansagen
an, etwa bei Abwesend, Gäste oder Schlafen:

| Weg | Sperren | Aufheben |
|---|---|---|
| MQTT über die Befehlsbrücke (seit 1.3.24, Vorlage „MQTT-Befehle“) | `publish chromecast4lox/befehl/sperre 1` | `publish chromecast4lox/befehl/sperre 0` |
| MQTT, alter Befehlsweg (Haken „Alten Befehlsweg annehmen“) | `chromecast4lox/server/cmd/sperre 1` | `chromecast4lox/server/cmd/sperre 0` |
| UDP an dieses Plugin (Port aus den Einstellungen, ab Werk 7090) | `server/SPERRE 1;` | `server/SPERRE 0;` |
| andere Plugins auf diesem LoxBerry | `aktion=sperre&wert=1` mit dem Sprechtoken | `aktion=sperre&wert=0` |

* Gesperrt werden **Ansagen** übersprungen: `tts` aus Loxone (Protokoll „Ansage aus Loxone …
  übersprungen“) und `aktion=sprechen` (`200 SPRECHEN;OK=1;UEBERSPRUNGEN=1;GRUND=GESPERRT`).
  `dringend=1` am Endpunkt geht durch. Über MQTT und UDP gibt es kein `dringend`; wer aus Loxone trotz
  Sperre sprechen will, hebt sie vorher auf.
* Musik, Lautstärke, `tts_stop` und alle übrigen Befehle bleiben frei – wie bei Alexa-NG.
* Ohne Haken wird kein Wert angenommen (Endpunkt `409 GRUND=SPERRE_AUS`, Dienst: Protokollzeile), und
  ein früher gesetzter Wert wirkt nicht. Ein anderer Wert als `0`/`1` wird abgewiesen
  (`400 GRUND=WERT_SPERRE`).
* Antwort am Endpunkt: `SPERRE;OK=1;GESPERRT=1;UNVERAENDERT=0;GRUND=-`.
* Der letzte Wert steht in `data/plugins/<Ordner>/loxsperre.json`. Nach einem Update ist er weg; dann gilt
  „offen“, bis Loxone ihn neu setzt. Einstellungen und Reiter *Test* zeigen den Zustand.
* Ein zurückbehaltener (retained) Befehl `server/cmd/sperre` wird wie jeder Befehl verworfen und am Broker
  abgeräumt. Ein MQTT-Zustandsthema für die Sperre gibt es nicht: Loxone kennt den Wert, den es selbst
  gesetzt hat, und Vorlagen und Musterprojekt bleiben unverändert.
* Die Ruhezeit sperrt weiterhin nicht, sie begrenzt nur die Lautstärke.

## Google ↔ Loxone

Stand 15.10.2026. Was hier „nicht gemessen“ heißt, ist an keinem echten Gerät erprobt.

**Loxone → Google – dieses Plugin.** Ansagen auf einem Gerät, einer Google-Lautsprechergruppe oder
allen, mit eigener Lautstärke; Lautstärke, Wiedergabe, Favoriten (Radio-Adressen), Stopp und der
Zustand mit Titel und Interpret über MQTT oder UDP; die Sprachausgabe für andere Plugins mit derselben
Schnittstelle wie Alexa-NG (dazu Lautstärke und Geräteliste); die Sperre aus Loxone. Geräte: Chromecast,
Google Home und Nest, Fernseher mit Chromecast built-in. An einem echten Google-Gerät ist das Plugin
bisher nicht gemessen.

**Google Home Speaker.** In Googles Gerätespezifikationen (Google Home-Hilfe, „Google Nest and Home
device specifications“) steht beim Google Home Speaker keine Angabe zu Google Cast, anders als bei Nest
Audio oder Nest Mini. Ob er sich über dieses Plugin als Ansageziel ansprechen lässt, ist **nicht
gemessen**. Als **Matter-Hub** ist er belegt: der Google Store nennt in den technischen Daten
„Kann mit Google Home als Hub für Matter verwendet werden“
([technische Daten](https://store.google.com/de/product/google_home_speaker_specs?hl=de)), und die
Google Home-Hilfe führt ihn unter den Matter-Hubs
([Prepare your smart home for Matter](https://support.google.com/googlehome/answer/12391458)).

**Google → Loxone und Google-Routinen – nicht mit diesem Plugin.** Das gehört zur Matter-Bridge in
**Matter2Lox**. Sie ist in Arbeit; die Kopplung an Google ist noch nicht gemessen. Für eine Bridge mit
Test-Herstellerkennung verlangt Google ein Projekt in der *Google Home Developer Console* (VID `0xFFF1`,
PID `0x8000`), und wer koppelt, muss Mitglied dieses Projekts sein. Den Matter-Hub stellt ein
Google-Gerät im Haus – nach der Google Home-Hilfe unter anderem Nest Audio, Nest Mini, Nest Hub, Google TV
Streamer und der Google Home Speaker.

Was danach Google-Home-Automationen leisten sollen (**nicht gemessen**):

* **Routine oder Durchsage aus Loxone:** Loxone legt einen Schalter der Bridge um; eine Automation in der
  Google-Home-App startet daraufhin eine Durchsage an die Google-Lautsprecher, einen Sprachbefehl oder
  eine Routine.
* **Satz → Loxone:** ein eigener Satz („Hey Google, Abendlicht“) löst eine Automation aus, die einen
  Schalter der Bridge umlegt – den sieht Loxone.

**Nicht vorgesehen.** Chromecasts und Google-Lautsprecher als Matter-Mediengerät: Alexa und Apple Home
bedienen diesen Gerätetyp nicht, und Google kennt die Geräte ohnehin. Eine Anbindung an die Google-Cloud
(Assistant SDK) gibt es nicht. Für Alexa- oder Apple-Haushalte lassen sich die Loxone-Bausteine „Musik“
und Lautstärke als Steckdose über die Bridge freigeben (ab Werk nichts freigegeben, nicht gemessen); für
Google nicht freigeben, sonst stünde jeder Lautsprecher doppelt in der Google-Home-App.

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

Dazu `chromecast4lox/server/online` für den Dienst selbst (Letzter Wille), das Lebenszeichen
`server/zaehler`, `server/ts`, `server/geraete`, `server/verluste` und – von der Befehlsbrücke –
`status/befehle` (1 läuft, 0 aus, -1 gestört; nie retained). `volume` meldet `-1`, wenn der
Lautsprecher keine Lautstärke meldet; `volume`, `duration` und `favorit` eines aus der Liste genommenen
Geräts gehen einmal mit `-1` hinaus (seit 1.3.24). In Loxone ist jeder Wert ein einfacher Virtueller
Eingang mit dem Namen, den das MQTT-Gateway vergibt – die Namenstabelle steht im Reiter *Einbindung in
Loxone*.

**Befehle seit 1.3.24** über die gemeinsame Befehlsbrücke unter
`chromecast4lox/befehl/<Befehlsname>/<Befehl>` (Befehlsname = Gerätethema klein, `-` wird `_`, z. B.
`wohnzimmer`; `alle` für alle) und `chromecast4lox/befehl/sperre`: `play` (nur Fortsetzen), `pause`,
`stop`, `quit`, `volume`, `volume_step`, `volume_up`, `volume_down` (Puls, Schrittweite des Plugins),
`mute`, `next`, `prev`, `seek`, `play_favorit`, `tts`, `tts_stop`. Quittung unter
`…/<Befehl>/quittung`, nie retained. Haken im Reiter *MQTT*: „Befehle über MQTT annehmen“ und für alles
Hörbare zusätzlich „Lautsprecher über MQTT steuern“ (beide ab Werk aus).

**Alter Befehlsweg** (bestehende Anlagen, Haken „Alten Befehlsweg annehmen“: nach einem Update an,
bei einer Neuinstallation aus) unter `chromecast4lox/<Gerät>/cmd/<Befehl>` mit denselben Befehlen und
`chromecast4lox/server/cmd/sperre`. Über MQTT braucht jeder Befehl einen Wert (z. B. `1`); eine
leere Nachricht wird nie ausgeführt – so sieht die Löschung eines retained
Themas aus. Die Ausgangsvorlage sendet immer einen Wert.

`volume` (absolute Lautstärke) mit demselben wirksamen Pegel – nach
Obergrenze und Ruhezeit – wie vor weniger als 60 s wird nicht erneut an den
Lautsprecher gesendet; das Protokoll nennt es mit `UNVERAENDERT=1`.
`volume_step`, `volume_up`, `volume_down`, `mute` und die Tasten gehen immer
hinaus.

Der Gerätename im Thema entsteht aus dem Anzeigenamen: Umlaute umgeschrieben,
alles übrige außer Buchstaben, Ziffern, Strich und Unterstrich wird zu `_`.
Aus `Küche Lautsprecher` wird `Kueche_Lautsprecher`.

**Zonen (seit 1.3.25, Audio Stufe 1)** unter `chromecast4lox/zone/<Nr>/`: `online` (nie retained, Gateway V2 mit
Noncached), `state`, `playing`, `volume`, `muted`, `art` (0 Gerät, 1 Gruppe), `quelle` (Nummer, 0 nach Stopp) –
retained –, dazu `title`, `artist`, `album`, `quelle_name` (Text, nie retained) und `mitglied_von` (Text,
retained: Gruppen, in denen das Ziel Mitglied ist, mit `;` getrennt; `-` = in keiner). Befehle über die Brücke:
`zone_<Nr>/quelle` (0 bis 99), `zone_<Nr>/klingel`, `zone_<Nr>/tts` und alle Gerätebefehle; `klingel` allein für
das Standardziel. Dienstthemen `server/quellen` und `server/ablage_ok` (nie retained, Noncached).

## Dateien

| Datei | Zweck |
|---|---|
| `bin/chromecast4lox_ng-server.py` | Dienst: Geräte, MQTT, UDP |
| `bin/cc_discover.py` | Gerätesuche für den Reiter Test |
| `webfrontend/htmlauth/index.php` | Oberfläche, vier Reiter |
| `webfrontend/htmlauth/cc_lib.php` | Konfiguration, Themen, Loxone-XML |
| `webfrontend/htmlauth/cc_test.php` | Aktionen des Reiters Test |
| `webfrontend/html/index.php` | Sprachausgabe für andere Plugins (Endpunkt, ab Werk aus) |
| `bin/befehlsbruecke.php`, `bin/befehlsbruecke.sh` | gemeinsame Befehlsbrücke (Stammfassung, unverändert) |
| `bin/befehle_zuordnung.php` | Befehle dieses Plugins für die Brücke |
| `config/chromecast-4lox-ng.cfg` | Konfiguration im INI-Format |
| `config/mqtt_subscriptions.cfg` | Abo für das MQTT-Gateway V1 (`chromecast4lox/#`) |
| `dpkg/apt` | `python3-pychromecast`, `python3-zeroconf`, `python3-paho-mqtt`, `mosquitto-clients`, `espeak-ng` |

## Voraussetzungen

- LoxBerry ab 3.0 (`LB_MINIMUM` in `plugin.cfg`)
- MQTT-Gateway (eigenes LoxBerry-Plugin) für den MQTT-Weg
- Der LoxBerry muss im **selben Netzsegment** wie die Chromecasts liegen —
  die Suche läuft über mDNS und geht nicht über VLAN-Grenzen.

## Installation

Über die LoxBerry-Plugin-Verwaltung, entweder als Datei-Upload des ZIP-Archivs
oder direkt über die Adresse des Releases:

    https://github.com/timanders22/LoxBerry-Plugin-Chromecast4lox/releases/latest

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

### `webfrontend/html/` nur für andere Plugins

Loxone spricht das Plugin über **UDP (Port 7090)** und MQTT an, nicht über
HTTP. Im unangemeldeten Bereich liegt nur der Endpunkt der Sprachausgabe für
andere Plugins (`webfrontend/html/index.php`, ab Werk aus, nur von diesem
LoxBerry aus) und, im Ansagemodus `lokal`, der Ordner `ansage/` mit den
erzeugten Sprachdateien.

