# Audioserver NG – LoxBerry-Plugin

Eine schlanke Hülle um **sonn core**, die Nachbildung des Loxone Audioservers. sonn core läuft unverändert als
Docker-Container auf dem LoxBerry, mit eigener Adresse im Heimnetz und fester MAC. Die Loxone App bedient die Zonen
**nativ** (Audio-Player-Baustein), die Ausgabe geht je Raum an einen Google-Cast-Lautsprecher.

Version 0.9.0 · Lizenz MIT · LoxBerry ab 3.0.0 · BETA

**Stand 0.9.0:** erste Fassung, **BETA**. Gegen Attrappen geprüft. Probe mit sonn core 4.0.0-beta.22 am Gerät
(30.10.2026, sonn von Hand gestartet): Kopplung, Radio, Pause/Lautstärke und Klingel ohne spürbare Verzögerung
gemessen. Gerätetest des Plugins am 31.10./01.11.2026 im Haus (Raspberry Pi 4, zwei Google-Cast-Zonen): Einrichten mit einem
Knopf, Kopplung, Radio, Lautstärke, Pause, Durchsage aus der App, Zustandswerte über das MQTT-Gateway V2 und das sichere
Neuanlegen des Containers bestanden; Klingel und Wecker siehe „Grenzen“.

---

## Neu in 0.9.0

Erste Fassung. Den Umfang beschreibt der Abschnitt „Was das Plugin tut“. Eingerichtet wird mit **einem Knopf**:
Kasten „Audioserver einrichten“ oben im Reiter Einstellungen, Ja-Haken setzen, klicken (siehe „Einrichtung in Kürze“).

## Voraussetzungen
- **LoxBerry ab 3.0.0** (das Plugin liest die Einstellungen des MQTT-Gateways aus `config/system/general.json`).
- **64-Bit-System:** arm64 (zum Beispiel Raspberry Pi 4 mit 64-Bit-LoxBerry) oder x86_64 – sonn core gibt es nur für
  diese beiden Architekturen. Der Container darf ab Werk bis 1024 MB Speicher und 2 CPU-Kerne belegen (Reiter
  Einstellungen); das Gerät braucht neben LoxBerry entsprechend Platz. Auf dem Raspberry Pi setzt der Kernel meist
  keine Speichergrenze für Container durch (`cgroup_disable=memory`): der Container läuft trotzdem, der Reiter Test
  zeigt das als Hinweis ⓘ statt als Fehler; die CPU-Grenze wirkt.
- **Docker wird vorausgesetzt und nicht mitinstalliert.** Docker zum Beispiel mit dem Plugin **Docker NG** einrichten.
  Der Benutzer `loxberry` muss Docker erreichen (Gruppe `docker`). Die Installation prüft beides und warnt, wenn etwas
  fehlt; ohne Docker lässt sich kein Container anlegen.
- **macvlan-Netz:** sonn bekommt eine eigene Adresse im Heimnetz. Dafür eine freie Adresse außerhalb des DHCP-Bereichs
  des Routers. Ein vorhandenes macvlan-Netz an derselben Schnittstelle wird mitbenutzt, sonst legt das Plugin eines an.
  Am besten eine Kabelverbindung: macvlan über WLAN funktioniert meist nicht (allgemeine Docker-Eigenschaft, hier
  nicht gemessen).
- **MQTT-Gateway V2** von LoxBerry für die Zustandswerte.
- Je Raum ein **Google-Cast-Lautsprecher** als Ausgang; in **Loxone Config** das Objekt *Audioserver*.

## Was das Plugin tut
- **Container verwalten:** Abbild nur in einer festgelegten Fassung (Digest, `bin/asn_abbild.json`), eigener Name und
  eigene Labels, Neustart-Regel `unless-stopped`, Speicher- und CPU-Grenze. Anlegen, starten, stoppen, neu starten.
  **Neu anlegen ist sicher** (auch bei Update und Rückweg): der bisherige Container wird angehalten und beiseitegestellt;
  erst wenn der neue läuft, wird der alte entfernt. Scheitert der neue, kommt der alte unverändert zurück. Zwei
  Container mit derselben Adresse und MAC laufen dabei nie gleichzeitig.
- **Update nur nach Ja:** neuer Digest und Haken „Release-Hinweise gelesen, Ja“. Den Digest muss man nicht abtippen:
  „Verfügbare Fassungen auf ghcr.io anzeigen“ listet Tags und Digests (nur lesend), „Übernehmen“ trägt einen davon ins
  Feld ein. Der neue Container bekommt eine **Kopie** der Daten; der alte Datenordner bleibt. **Rückweg** auf die
  vorherige Fassung mit einem Knopf.
- **Alte Abbilder aufräumen:** „Abbilder ansehen“ zeigt alle Abbilder von sonn core auf dem LoxBerry und den Platz,
  der frei würde. Nach Ja entfernt das Plugin nur Abbilder dieses Repos, die weder die aktuelle noch die vorherige
  Fassung sind und die kein Container benutzt – mit `docker image rm`, nie mit `-f`.
- **Netz:** sonn hängt **nur** in einem macvlan-Netz (eigene Adresse im Heimnetz). Ein vorhandenes macvlan-Netz an
  derselben Schnittstelle wird mitbenutzt (und nie verändert), sonst legt das Plugin ein eigenes an.
- **Feste IP und MAC** aus der Oberfläche. Aus der MAC folgt die **Seriennummer**, die Loxone Config für das
  Audioserver-Objekt braucht. Die sonn-Verwaltung öffnet sich mit einem Klick (neues Fenster).
- **Musikordner** über die Speicherauswahl von LoxBerry (USB, Netzfreigabe), **nur lesend** als `/musik`.
- **Zustand über das MQTT-Gateway V2:** Lebenszeichen, sonn erreichbar, Gesundheit, Kopplung, Zahl der Zonen, je Zone
  Zustand, Lautstärke, Titel, Interpret, Quelle, Name, Ausgang und **verbunden** (1 Lautsprecher verbunden, 0 nicht
  verbunden oder kein Ausgang eingerichtet, **-1 keine Aussage**). sonn meldet die Verbindung nur bei
  Squeezelite-Ausgängen; bei Google Cast steht dort -1. (Früher `-` – ein Virtueller Eingang las das als 0, also
  fälschlich „nicht verbunden“.) Den **Ausgang** einer Google-Cast-Zone nennt sonn nur in seiner Konfiguration – das
  Plugin liest von dort allein den Namen des Lautsprechers. Hat ein Radiosender keinen Namen, steht als **Quelle** der
  Dienst, bei TuneIn mit Sender (etwa „TuneIn (radioparadise)“).
- **Healthcheck und Reiter Test** melden Zonen ohne verbundenen Lautsprecher und Zonen mit Wiedergabefehler.
- **Protokoll von sonn:** Reiter Logdateien, „Letzte 200 Zeilen von sonn zeigen“ (`docker logs`, nur lesend,
  Zeitgrenze 15 s). Kennwörter, Token, Seriennummern, IP- und MAC-Adressen werden vorher unkenntlich gemacht.
  Schreibt der minütliche Takt etwas auf die Fehlerausgabe (`takt.err`), zeigt derselbe Reiter es an.
- **Befehlsbrücke** (zwei Haken, ab Werk aus): `audioserverng/befehl/neustart` und `audioserverng/befehl/bibliothek`.
- **Selbstprüfung** im Reiter Test, unter anderem: hängt der Container nur im macvlan-Netz, meldet sonn dieselbe MAC
  und Adresse wie eingestellt, ist der Miniserver gekoppelt.

## Grenzen
- **Keine synchrone Mehrraum-Wiedergabe über Google Cast.** sonn hält mehrere Cast-Lautsprecher nicht im Takt; der
  Versatz ist hörbar und wächst nach einer Klingel-Unterbrechung (Probe 30.10.2026).
- **Klingel und Wecker über sonn (beta.22):** danach läuft die vorherige Wiedergabe nicht (dauerhaft) weiter; ein
  zweiter Alarm kann die Cast-Ausgabe bis zum Neustart des Containers stören. Der Weckton von sonn ist ein kurzer
  Gong. Durchsage aus der App geht, die Wiedergabe läuft danach weiter (am Gerät gemessen 31.10.2026, an sonn
  gemeldet). Für die Klingel deshalb **Chromecast4lox** verwenden.
- **Lautsprecher ohne Strom:** War ein Google-Cast-Lautsprecher nicht erreichbar (z. B. stromlos), meldet sonn bei
  jedem Play sofort „Playback unavailable“ – auch wenn er wieder an ist; sonn versucht es nicht neu (am Gerät gemessen,
  31.10.2026). Abhilfe: Lautsprecher einschalten, dann „Container neu starten“ (alle Zonen brechen kurz ab). Der Reiter
  Test sagt das bei einem Wiedergabefehler dazu. Das Plugin startet sonn deshalb **nicht** selbst neu.
- **Google-Lautsprechergruppen sind in sonn nicht als Ausgang wählbar.** Mehrraum und Klingel über Google-Gruppen
  macht das Plugin **Chromecast4lox**. Hier gilt: eine Zone je Raum.
- sonn core ist eine **Beta** mit einem Hauptentwickler. Jede Fassung von Loxone oder sonn kann die Kopplung kurz
  brechen – deshalb die festgelegte Fassung und der Rückweg. **Vor jedem Update die Release-Hinweise von sonn auf
  Brüche lesen.** Beispiel beta.18: nach einer frischen Konfiguration muss Loxone in sonn ausdrücklich eingeschaltet
  werden, und Favoriten in Loxone Config, die auf alte Audiopfade zeigen, werden ungültig.
- **sonn kann sein Paket auf ghcr.io umbenennen** (schon einmal geschehen: `lox-audioserver` → `sonn-audio/core`;
  ghcr.io leitet nicht um). Ein festgelegter Digest bleibt im alten Paket ziehbar, bekommt dort aber nie wieder ein
  Update, und die Fassungsliste zeigt dann keine neuen Fassungen. Vor einem Update deshalb in den Release-Hinweisen
  prüfen, ob das Repository gewechselt hat.
- **Kein Einhängen von SMB-Freigaben in der sonn-Verwaltung.** Dazu bräuchte der Container Zusatzrechte
  (`SYS_ADMIN`, `DAC_READ_SEARCH`), die das Plugin nicht vergibt. Musik kommt über die Speicherauswahl von LoxBerry.
- **LoxBerry-Text2Speech als Sprachanbieter in sonn ist voraussichtlich nicht erreichbar:** ein Container im
  macvlan-Netz erreicht seinen eigenen LoxBerry nicht (nicht gemessen; Prüfpunkt im Gerätetest). In sonn Google oder
  OpenAI als Sprachanbieter wählen.
- Kein „Loxone Radio“ (geschützte Schnittstelle von Loxone); Sender über TuneIn, radio-browser oder eigene Sender.
- Ein Container im macvlan-Netz ist vom LoxBerry selbst aus nicht erreichbar. Das Plugin fragt sonn deshalb mit
  `docker exec` im Container ab. sonns eigenes MQTT erreicht den Broker des LoxBerry aus demselben Grund nicht.
- **Die Daten von sonn gehören root** (der Container läuft als root). Wer den Ordner `data/plugins/audioserverng.sonn`
  von Hand löschen will, braucht `sudo`; als Benutzer loxberry geht es nicht.
- In der Probe war eine Sprachausgabe nach längerer Ruhe einmal abgeschnitten (offen).

## Datensicherung
Die Datensicherung von LoxBerry (System → Sicherung, `sbin/clone_sd.pl`) klont die ganze Speicherkarte: sie kopiert
die Wurzelpartition vollständig mit `tar` (nur die Auslagerungsdatei ausgenommen, Besitzer und Rechte bleiben). Der
Datenordner von sonn liegt auf dieser Partition und ist damit **in der Sicherung enthalten**, ebenso die geladenen
Abbilder. Der Klon läuft im laufenden Betrieb; wer eine sicher stimmige Kopie der sonn-Bibliothek will, stoppt sonn
vorher im Reiter Einstellungen. Ein Musikordner auf USB oder einer Netzfreigabe gehört nicht zur Sicherung.

## Einrichtung in Kürze
1. Reiter **Einstellungen**, ganz oben der Kasten **„Audioserver einrichten“**. Er zeigt, was das Plugin vorschlägt:
   Netz, Adresse von sonn, MAC-Kennung (gekürzt) und Musikordner – jeweils mit „woher“. Haken **„Ja, mit diesen Werten
   einrichten“** setzen und **„Audioserver einrichten“** klicken. Das Plugin speichert die Werte, schaltet die
   Verwaltung ein und legt den Container an (beim ersten Mal einige Minuten: sonn wird geladen).
   - **Mit Probe-Container** `sonn-probe`: Netz, Adresse und MAC kommen aus der Probe (die MAC eines gestoppten
     Containers liest das Plugin aus der `config.json` der Probe – Docker zeigt sie nicht); die Daten werden kopiert,
     die Probe bleibt gestoppt stehen.
   - **Ohne Probe:** vorhandenes macvlan-Netz an der Schnittstelle mit dem Standardweg, sonst ein eigenes; eine freie
     Adresse am oberen Ende des Heimnetzes (bei /24 ab .240 abwärts; frei = keine Antwort auf ping/ARP, keinem
     Docker-Container zugewiesen, nicht LoxBerry, nicht Router). **Grenze:** den DHCP-Bereich des Routers kann das
     Plugin nicht lesen, und ein ausgeschaltetes Gerät antwortet nicht – die Adresse danach im Router für sonn
     festhalten. MAC: fest aus der Maschinenkennung des LoxBerry berechnet (`02:…`, lokal verwaltet) – gleicher LoxBerry,
     gleiche Seriennummer. Musikordner: ein Ordner Musik/Music/MP3/Audio auf einem USB-Speicher, sonst keiner.
   - Wer lieber von Hand einstellt: Bereich **„Erweitert“** (zugeklappt) mit allen technischen Feldern und „Werte der
     Probe übernehmen“.
2. Danach zeigt der Reiter die Liste **„So geht es weiter“**; jeder Schritt mit Zustand (✔ erledigt / offen) und Link
   bzw. Kopierknopf: ① sonn-Verwaltung öffnen, Konto anlegen, unter *Zones* „Loxone integrated“ · ② Seriennummer
   kopieren, in **Loxone Config** einen *Audioserver* mit dieser Seriennummer und der Adresse als Host anlegen, in den
   Miniserver laden · ③ in sonn je Raum eine Zone mit Lautsprecher (Ausgang „Google Cast“) · ④ Musikordner in sonn als
   Bibliothek `/musik` hinzufügen · ⑤ Reiter **Test** ansehen. Gemessen werden Konto und Loxone-Schalter, Kopplung,
   Zonen mit Lautsprecher und der Gesamtzustand; ob die Bibliothek eingetragen ist, kann das Plugin nicht sehen.
3. Reiter **MQTT:** die Datenpunkte der Hakenliste im Gateway V2 anhaken.

## Hilfe und Musterprojekt
Der Reiter „Einbindung in Loxone“ zeigt drei Bilder aus dem LoxBerry-Plugins Musterprojekt: die Seiten „Audioserver NG“
(Dienstwerte, Lebenszeichen, Status, Befehlstasten Neustart und Bibliothek), „Audioserver NG Werte“ (je Zone 8 Werte mit
Status) und „Audioserver NG Zonen“ (Audio-Player Wohnzimmer und Küche mit Klingel, Wecker und Durchsage).
Die Hilfe steht im Plugin (Hilfe der LoxBerry-Kopfzeile) und im LoxBerry-Wiki auf der Seite
[Audioserver NG](https://wiki.loxberry.de/plugins/audioserverng/start). Eine Seite für Audioserver NG im
[LoxBerry-Plugins Musterprojekt](https://github.com/timanders22/LoxBerry-Plugins-Musterprojekt) folgt.

## Datenschutz
Ins Internet geht das Plugin nur auf Knopfdruck und nur zu ghcr.io: beim Laden eines Abbilds (Anlegen, Update) und
beim Anzeigen der verfügbaren Fassungen. Für die Liste holt es anonym, ohne Konto, einen Lese-Token und fragt die
Tag-Liste und je Tag den Digest ab; das Ergebnis bleibt 6 Stunden zwischengespeichert. Daten des Hauses werden dabei
nicht gesendet. Das sonn-Konto (nur für „Bibliothek einlesen“) steht in der Konfiguration (0600) und geht nur über die
Standardeingabe in den Container, nie auf eine Befehlszeile.

## Lizenz
MIT (siehe `LICENSE`). Hinweise zu sonn core und Audioserver4Home in `NOTICE`.
