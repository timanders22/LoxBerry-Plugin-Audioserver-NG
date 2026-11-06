# Audioserver NG – LoxBerry-Plugin

Eine schlanke Hülle um **sonn core**, die Nachbildung des Loxone Audioservers. sonn core läuft ab Werk unverändert als
Docker-Container auf dem LoxBerry, mit eigener Adresse im Heimnetz und fester MAC. Die Loxone App bedient die Zonen
**nativ** (Audio-Player-Baustein), die Ausgabe geht je Raum an einen Google-Cast-Lautsprecher.

**Welche Lautsprecher:** Google-Cast-Geräte – Google Home / Google Home Speaker, Nest Audio, Nest Mini, Chromecast
(auch Chromecast Audio) und Fernseher mit Chromecast built-in. sonn core kennt weitere Ausgänge (AirPlay, Sonos,
DLNA/UPnP, Snapcast, Sendspin, Squeezelite, Music-Assistant-Player); mit diesem Plugin geprüft ist nur Google Cast.
**Amazon Echo wird nicht unterstützt** – sonn core 4.0.0-beta.22 hat keinen Ausgang für Echo/Alexa (Bluetooth nur als
Eingang). Für Ansagen und Radio auf Echo-Geräten gibt es das Plugin [Alexa NG](https://wiki.loxberry.de/plugins/alexang/start).

Version 0.9.7 · Lizenz MIT · LoxBerry ab 3.0.0 · BETA

**Stand 0.9.3:** vierte Fassung (0.9.0 bis 0.9.2 vom 01.11.2026), **BETA**. Gegen Attrappen geprüft. Probe mit sonn core 4.0.0-beta.22 am Gerät
(30.10.2026, sonn von Hand gestartet): Kopplung, Radio, Pause/Lautstärke und Klingel ohne spürbare Verzögerung
gemessen. Gerätetest des Plugins am 31.10./01.11.2026 im Haus (Raspberry Pi 4, zwei Google-Cast-Zonen): Einrichten mit einem
Knopf, Kopplung, Radio, Lautstärke, Pause, Durchsage aus der App, Zustandswerte über das MQTT-Gateway V2 und das sichere
Neuanlegen des Containers bestanden; Klingel und Wecker siehe „Grenzen“.

---

## Neu in 0.9.7

- Gemeinsames Sprachmodul 1.4.2: Wird eine Ansage bewusst nicht gesprochen (gerade eben schon gesagt, gesperrt oder Ruhezeit), steht das im Protokoll jetzt so – und nicht mehr als Fehler.

## Neu in 0.9.6

- **Update:** Scheitert die Sicherung oder die Marke, bricht das Update ab und die bisherige Fassung bleibt.
- **Sprechtoken:** Ein von Hand eingetragenes Alexa- oder Google-Token wird nicht mehr vom Token des Nachbar-Plugins überschrieben.
- **Abbild-Update:** Der alte Container wird erst entfernt, wenn der neue auf die Gesundheitsabfrage von sonn antwortet und mindestens 30 Sekunden ohne Neustart läuft (höchstens gut zwei Minuten Warten). Sonst kommt der alte zurück.
- **Meldungen:** Texte mit Apostroph oder `&` stehen im Reiter wieder richtig da, nicht als `&#039;`.
- **Formular:** Ein beanstandeter Tafel- oder HomePod-Name bleibt zur Korrektur im Feld stehen.

## Neu in 0.9.5

- Vor einem Download wird jeder offene Ausgabepuffer geleert: PHP-Warnungen oder Reste landen nicht mehr in der heruntergeladenen Datei.

## Neu in 0.9.4

**Ab dieser Fassung sind die Sprachansagen ab Werk an, auch nach einem Update, wenn Sie die Ausgabeart nie gespeichert haben.** Eine gespeicherte Einstellung bleibt, wie sie ist, auch „aus“. **Ausschalten:** Reiter *Einstellungen*, Abschnitt *Sprachansagen*, Ausgabeart **„aus“** wählen und speichern; das bleibt auch nach jedem weiteren Update aus.

* **Was angesagt wird:** sonn antwortet seit 10 Minuten nicht, und wieder da (nur nach einer angesagten Störung); der Rücksprung auf den alten Container ist fehlgeschlagen; je ein eigener Haken, nachts schweigt alles davon. Audioserver NG bleibt kein Sprachziel für andere Plugins und sagt nie über sich selbst an.
* **Wohin:** ab Werk zugleich Loxone Music Server (braucht seine Adresse), Google-Lautsprecher über Chromecast4lox (braucht dort ein Standardgerät oder hier den Namen) und Amazon Echo über Alexa-NG. Ein Ziel, das nicht bereitsteht, wird ohne Fehler übergangen; der Abschnitt *Sprachansagen* sagt, was ihm fehlt. Das Sprechtoken der beiden Plugins müssen Sie nicht abschreiben: Es wird übernommen, in der Konfiguration gespeichert und nachgezogen; ein von Hand eingetragenes bleibt.
* **Wann nicht:** Nachtruhe 22 bis 7 Uhr (einstellbar, wird nicht nachgeholt; Dringendes spricht trotzdem), dieselbe Ansage höchstens einmal in 30 Minuten (einstellbar) und solange Loxone das retained MQTT-Thema `haus/ansage/ruhe` auf 1 setzt (das gilt auch für Dringendes). Die Testansage spricht immer. Ausgabeart, Ziele und Regeln reisen in der Sicherung mit, das Sprechtoken nie.
* **Der Dienst wartet nicht auf Lautsprecher:** Gesprochen wird in einem eigenen Vorgang.


## Neu in 0.9.3

- **Vorgabesender von TuneIn ausblenden** (Reiter Einstellungen, Abschnitt „Radio: Vorgabesender von TuneIn“, ab Werk aus):
  Ohne TuneIn-Benutzer zeigt sonn drei fest eingebaute Sender (BBC Radio 1, NPO Radio 2, KEXP), die sich in sonn nicht
  löschen lassen. Mit dem Haken ändert das Plugin dafür eine Zeile in `tuneinProvider.js` im Container. Das ist der einzige
  Eingriff in eine Datei von sonn. Weil die Änderung ein Neuanlegen des Containers nicht übersteht, prüft das Plugin jede
  Minute und holt sie nach, sobald keine Zone spielt; sonn startet dabei einmal neu. Die Datei wird vorher gesichert, neben
  der Datei im Container und im Datenordner des Plugins (`sicherung_tunein`).
- **Neue Fassung von sonn:** Steht die Zeile nicht mehr genau so da, ersetzt das Plugin nichts, startet nichts neu und meldet
  das im Reiter Einstellungen, im Reiter Test und im Protokoll. Antwortet sonn nach einer Änderung nicht, spielt das Plugin
  die Sicherung zurück und versucht es bei dieser Datei nicht wieder von selbst.
- **Custom Streams aus der Favoritenliste:** Beim Übernehmen legt das Plugin `radio`-Einträge zusätzlich als Custom Stream in
  sonn an, wenn das sonn-Konto eingetragen ist. Dann zeigen App und Zone den Sendernamen, und der Sender steht auch ohne
  Favorit unter Radio → Custom Streams. Vorhandene Adressen bleiben, wie sie sind; gelöscht und umbenannt wird nichts. Ohne
  Konto oder bei falschem Konto laufen die Favoriten trotzdem durch, mit dem Satz „Custom Streams nicht angelegt: Konto fehlt/falsch“.
- **Reiter Test zeigt je Zone die Startlautstärke**, die aus Loxone kommt (Audio Player, Parameter Standardlautstärke), und
  rät unter 20 zum Erhöhen in Loxone Config. Das Plugin setzt sie nicht selbst: Der Miniserver überschreibt sie bei jedem Hochladen.
- **Die drei Beispielsender** kommen von sonn; BBC Radio 1 liefert dort keine Musik mehr. Eigene Sender gehören unter Custom
  Streams oder in die Favoritenliste.
- Pflege: doppeltes Wort im Protokoll der Befehlsbrücke berichtigt; ein nicht schreibbares `cron.err` verhindert den Minutenlauf
  nicht mehr; ein Kindprozess, der SIGTERM übergeht, hält keinen Aufruf mehr auf.

Gegen Attrappen und gegen die echte Datei aus sonn core 4.0.0-beta.22 geprüft; das Umstellen im laufenden Container dieser
Fassung ist noch nicht am Gerät gemessen.

## Neu in 0.9.2

- **Favoritenliste im Plugin** (Reiter Einstellungen, Abschnitt „Favoriten“): je Zeile `Nr = Art | Name | Quelle`,
  dieselbe Form wie die Quellenliste von Chromecast 4 Lox NG. **Die Nummer ist die Favoriten-Nummer in Loxone:** Nr. 3
  spielt, wenn am Eingang *Fav* des Audio Players eine 3 ankommt (`playZoneFav/3`), und erscheint in der Loxone App
  unter „Meine Favoriten“ der Zone. Arten: `radio` (Stream-Adresse http/https, ohne TuneIn), `liste` (Ordner im
  Musikordner oder eine Wiedergabeliste von sonn), `datei` (Datei im Musikordner oder http-Adresse).
- **„In sonn übernehmen“** (Ja-Haken, Hintergrundvorgang) **ersetzt** die Favoriten der gewählten Zonen (leer = alle) –
  ausschließlich über die öffentliche Schnittstelle `/api/v1` von sonn, keine Datei von sonn wird angefasst. Die
  bisherigen Favoriten legt das Plugin vorher als Kopie im Datenordner ab. Lücken in den Nummern bleiben frei (das
  Plugin legt dafür kurz einen Platzhalter an und entfernt ihn wieder); jede vergebene Nummer wird nachgeprüft.
- Werkszustand: Liste leer. Ein Beispiel steht in der Hilfe.
- **Musikordner berichtigt (Gerätetest 01.11.2026):** sonn core 4.0.0-beta.22 lässt in der Verwaltung keinen Ordner
  angeben; seine Bibliothek liest nur die *Lokale Bibliothek* (`data/music/local`) und Freigaben, die sonn selbst
  einhängt. Der bisherige Ort `/musik` war für sonn deshalb unsichtbar (0 Titel). Jetzt hängt das Plugin den
  Musikordner **nur lesend** als Ordner **`LoxBerry-Musik`** in die Lokale Bibliothek
  (`/app/data/music/local/LoxBerry-Musik`). **Der Musikordner erscheint in sonn unter Inhalte → Bibliothek → Lokale
  Bibliothek im Ordner `LoxBerry-Musik`; danach „Neu scannen“ bzw. im Plugin „Bibliothek neu einlesen“.** Keine
  Netzfreigabe in sonn anlegen. Der Upload von sonn („Audio hier ablegen“) geht daneben weiter. Ein bestehender
  Container bekommt den neuen Ort mit **„Container anlegen oder angleichen“** (der Datenordner bleibt, nichts wird
  kopiert). Datenkopien (Wechsel von der Probe, Update) kopieren nie Musik mit: sie laufen in einem Hilfscontainer,
  der nur die Datenordner sieht, und brechen ab, wenn der Einhängepunkt nicht leer ist. Favoriten `liste`/`datei`
  werden jetzt dort gesucht. „So geht es weiter“ ④ und der Reiter **Test** messen die Titel der Bibliothek.

## Neu in 0.9.1

- **Neues Plugin-Symbol** (Entwurf des Hausherrn): Server mit Notenanzeige und Equalizer unter Funkwellen, im
  grünen Rund des Hausstandards. Geändert sind `icons/icon.svg` und `icon_64/128/256/512.png`.
- **Hinweis auf das Musterprojekt berichtigt:** Die Seiten „Audioserver NG“, „Audioserver NG Werte“ und
  „Audioserver NG Zonen“ liegen seit 31.10.2026 im LoxBerry-Plugins Musterprojekt (siehe „Hilfe und Musterprojekt“);
  0.9.0 nannte sie noch als „folgt“.

Sonst unverändert gegenüber 0.9.0.

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
  vorherige Fassung mit einem Knopf. Die Musik wird nie mitkopiert: die Kopie sieht nur den Datenordner, in dem der
  Einhängepunkt `music/local/LoxBerry-Musik` ein leerer Ordner ist.
- **Alte Abbilder aufräumen:** „Abbilder ansehen“ zeigt alle Abbilder von sonn core auf dem LoxBerry und den Platz,
  der frei würde. Nach Ja entfernt das Plugin nur Abbilder dieses Repos, die weder die aktuelle noch die vorherige
  Fassung sind und die kein Container benutzt – mit `docker image rm`, nie mit `-f`.
- **Netz:** sonn hängt **nur** in einem macvlan-Netz (eigene Adresse im Heimnetz). Ein vorhandenes macvlan-Netz an
  derselben Schnittstelle wird mitbenutzt (und nie verändert), sonst legt das Plugin ein eigenes an.
- **Feste IP und MAC** aus der Oberfläche. Aus der MAC folgt die **Seriennummer**, die Loxone Config für das
  Audioserver-Objekt braucht. Die sonn-Verwaltung öffnet sich mit einem Klick (neues Fenster).
- **Musikordner** über die Speicherauswahl von LoxBerry (USB, Netzfreigabe), **nur lesend** in der Lokalen Bibliothek
  von sonn als Ordner `LoxBerry-Musik` (Inhalte → Bibliothek → Lokale Bibliothek); danach „Neu scannen“ bzw. im
  Plugin „Bibliothek neu einlesen“. Uploads in sonn bleiben daneben möglich.
- **Favoritenliste** (ab 0.9.2): Nr = Favoriten-Nummer in Loxone (Eingang *Fav*, `playZoneFav/<Nr>`); Radio per freier
  Adresse, Ordner, Wiedergabeliste oder Datei der Bibliothek. Übernehmen nur auf Knopfdruck mit Ja-Haken; es ersetzt
  die Favoriten der Zonen in sonn (vorher Kopie im Datenordner). Ergebnis je Zone mit Nummern und Namen im Abschnitt.
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

## Welches Audio-Plugin wofür?

Drei Plugins bringen Ton auf Lautsprecher. Sie schließen sich nicht aus und lassen sich kombinieren.

| | [Chromecast 4 Lox NG](https://wiki.loxberry.de/plugins/chromecast4loxng/start) | [Alexa NG](https://wiki.loxberry.de/plugins/alexang/start) | Audioserver NG |
|---|---|---|---|
| **Lautsprecher-Art** | Google Cast: Google Home / Nest (Mini, Audio), Chromecast, Fernseher mit Chromecast, Google-Lautsprechergruppen | Amazon Echo (auch Mehrraum-Musikgruppen der Alexa-App) | Google Cast: ein Lautsprecher je Raum; keine Google-Lautsprechergruppen, kein Echo |
| **Bedienung in der Loxone App** | keine native Audio-Zone; Radiotasten, Regler und Statustexte über MQTT | keine native Audio-Zone; Befehle über MQTT oder HTTP | **ja**: native Audio-Zonen (Baustein Audio-Player) wie mit dem Loxone Audioserver |
| **Musik/Radio** | Radio-Adressen (auch `.m3u`/`.pls`), Dateien und Listen der Medienablage, Favoriten | Radio über TuneIn oder Amazon nach Sendername; kein eigener Stream, keine eigene Datei | Radio (TuneIn, radio-browser, eigene Sender), eigene Bibliothek; kein Loxone Radio; Favoritenliste im Plugin (ab 0.9.2) |
| **Ansagen/TTS** | ja; an Gerät, Gruppe oder alle; Sprachausgabe für andere Plugins | ja (der Echo spricht); an Gerät, Gruppe oder alle; Sprachausgabe für andere Plugins | Durchsage aus der Loxone App (Sprachanbieter in sonn: Google oder OpenAI); keine Sprachausgabe für andere Plugins |
| **Klingel** | ja: Gong an eine Google-Lautsprechergruppe (synchron, etwa 1 s), eigener Gong, danach weiterspielen | nur als Ansage (z. B. Türklingel-Ansage) | Klingel der App geht, aber in sonn beta.22 läuft die Musik danach nicht dauerhaft weiter – besser Chromecast 4 Lox NG |
| **Mehrraum synchron** | ja, über eine Google-Lautsprechergruppe; „alle“ spielt parallel | über eine Mehrraum-Musikgruppe der Alexa-App als Ganzes (nicht am Gerät gemessen) | nein: über Google Cast hörbarer Versatz |
| **Gruppen** | Google-Lautsprechergruppen als Gerät oder Zone, Mitglieder abfragbar | eigene Gruppen (jedes Gerät einzeln) und Amazon-Gruppen | eine Zone je Raum; Zonen in der Loxone App gruppierbar, über Google Cast aber nicht im Takt |
| **Eigene Musiksammlung** | ja: Medienablage (USB, Netzfreigabe) als Datei oder Liste auf einer Radiotaste; kein Durchsuchen in der Loxone App | nein | ja: Musikordner (USB, Netzfreigabe) als Bibliothek, Durchsuchen in der Loxone App |
| **Voraussetzungen** | LoxBerry ab 3.0; Docker: nein; MQTT-Gateway, Geräte im selben Netz | LoxBerry ab 3.0; Docker: nur für die wahlweise Lampen-Nachbildung; Amazon-Konto (einmal anmelden) | LoxBerry ab 3.0; Docker: ja, 64-Bit-System; MQTT-Gateway V2, freie Adresse im Heimnetz; Audioserver-Objekt in Loxone Config |
| **Am besten für …** | Klingel und Ansagen auf Google-/Nest-Lautsprechern, synchrone Musik über Google-Gruppen, einfache Radio- und Dateitasten | Ansagen und Radio auf Echo-Geräten; Alexa schaltet freigegebene Loxone-Lampen | Musik je Raum aus der Loxone App wie mit einem echten Audioserver |

**Kombinieren:** Audioserver NG für die Audio-Zonen der Loxone App je Raum, Chromecast 4 Lox NG für die Klingel,
synchrone Musik über eine Google-Gruppe und die Ansagen anderer Plugins, Alexa NG für alles, was auf Echo-Geräten
klingen soll. Andere Plugins mit dem Sprachmodul 1.2.1 (z. B. [Spotpreis aWATTar](https://wiki.loxberry.de/plugins/spotpreis_awattar/start))
sprechen auf Wunsch Google-Lautsprecher und Echo-Geräte gleichzeitig an.

## Audioserver NG und Audioserver4Home im Vergleich

Beide starten dasselbe sonn core, die Loxone App bedient in beiden die Zonen nativ. Die Unterschiede liegen in der
Hülle (Stand 01.11.2026: Audioserver NG 0.9.1, Audioserver4Home 3.3.0 nach dessen Wiki-Seite und Quelltext; die
Favoritenliste von 0.9.2 ändert daran nichts). Zeilen mit **✔ gleich** sind bei beiden wortgleich; in den übrigen
Zeilen steht in beiden Spalten dieselbe Satzform, der Unterschied ist fett. Es sind getrennte Plugins mit eigenem
Ordner (`audioserverng` gegen `audioserver4home`) – keine Fortführung, kein Update des anderen, kein Code übernommen
(siehe `NOTICE`). Besser nicht beide gleichzeitig am selben Miniserver als Audioserver betreiben (beide melden sich
als Audioserver; nicht gemessen).

| | Audioserver NG | [Audioserver4Home](https://wiki.loxberry.de/plugins/audioserver4home/start) |
|---|---|---|
| **sonn core** | ✔ gleich: startet sonn core als Docker-Container auf dem LoxBerry | ✔ gleich: startet sonn core als Docker-Container auf dem LoxBerry |
| **Bedienung in der Loxone App** | ✔ gleich: native Audio-Zonen (Baustein Audio-Player am Audioserver-Objekt) | ✔ gleich: native Audio-Zonen (Baustein Audio-Player am Audioserver-Objekt) |
| **Kopplung** | ✔ gleich: in der sonn-Verwaltung; in Loxone Config ein Audioserver-Objekt mit Seriennummer und Adresse von sonn | ✔ gleich: in der sonn-Verwaltung; in Loxone Config ein Audioserver-Objekt mit Seriennummer und Adresse von sonn |
| **Ausgabearten** | ✔ gleich: die von sonn (AirPlay, Snapcast, Sendspin, Google Cast, Sonos, Squeezelite …) | ✔ gleich: die von sonn (AirPlay, Snapcast, Sendspin, Google Cast, Sonos, Squeezelite …) |
| **Zonen gruppieren** | ✔ gleich: Zonen gruppierbar wie in sonn | ✔ gleich: Zonen gruppierbar wie in sonn |
| **Netz** | Netzart: **macvlan-Netz**<br>Adresse von sonn: **eigene Adresse und feste MAC**<br>Ports 7090, 7091, 7095: **an der eigenen Adresse, keine Portkonflikte am LoxBerry**<br>zusätzliche Adresse: **nötig (frei im Heimnetz)**<br>LoxBerry im WLAN: **macvlan über WLAN meist nicht möglich (nicht gemessen), am besten Kabel** | Netzart: **Host-Netz**<br>Adresse von sonn: **Adresse des LoxBerry**<br>Ports 7090, 7091, 7095: **am LoxBerry**<br>zusätzliche Adresse: **nicht nötig**<br>LoxBerry im WLAN: **möglich, keine macvlan-Einschränkung** |
| **Seriennummer in Loxone** | Seriennummer aus: **fester MAC aus dem Plugin**<br>nach Umzug auf einen anderen LoxBerry: **bleibt gleich** | Seriennummer aus: **MAC des LoxBerry**<br>nach Umzug auf einen anderen LoxBerry: **ändert sich** |
| **sonn-Fassung** | Fassung: **festgelegt (Digest)**<br>neue Fassung: **nur nach Ja** | Fassung: **frei wählbar aus allen Fassungen, dort empfohlen „beta-latest“ (rollend)**<br>neue Fassung: **bei jedem Start neu geholt (bei „beta-latest“)** |
| **Update und Rückweg** | Fassung wechseln: **nur nach Ja (Haken „Release-Hinweise gelesen, Ja“)**<br>Datenkopie je Fassung: **ja**<br>Rückweg-Knopf auf die vorherige Fassung: **ja**<br>alte Abbilder: **nach Ja aufräumen (Knopf)** | Fassung wechseln: **in der Auswahlliste**<br>Datenkopie je Fassung: **nein**<br>Rückweg-Knopf auf die vorherige Fassung: **nein**<br>alte Abbilder: **automatisch aufgeräumt** |
| **Docker** | Docker: **wird vorausgesetzt** (z. B. [Docker NG](https://wiki.loxberry.de/plugins/docker_ng_timanders22/start)) | Docker: **installiert das Plugin selbst** |
| **Ausgabe im Plugin** | mit dem Plugin geprüft: **Google Cast**<br>Squeezelite mit Soundkarte auf dem LoxBerry selbst: **voraussichtlich nicht** – der Container erreicht seinen LoxBerry nicht (nicht gemessen) | mit dem Plugin geprüft: **keine Angabe**<br>Squeezelite mit Soundkarte auf dem LoxBerry selbst: **ja** |
| **Gleichtakt in Gruppen** | nicht im Takt: **über Google Cast** (Probe 30.10.2026); **Google-Lautsprechergruppen in sonn nicht wählbar** | nicht im Takt: **gemischte Gruppen, z. B. Sonos mit Squeezelite** (laut dessen Wiki) |
| **Spielerübersicht** | Spielerübersicht: **keine**; Zonenwerte über MQTT und im Reiter Test | Spielerübersicht: **Webseite mit Kacheln und Cover je Zone (auch für Displays)**; Cover-Abruf über `cover.php` |
| **Zustand in Loxone** | Werte über: **MQTT-Gateway V2 wie die übrigen Plugins** (Hakenliste, Reiter Test zählt nach)<br>Steuern per MQTT: **nein, nur über die App** | Werte über: **sonns eigenes MQTT (`sonn/…`), in sonn einzuschalten**<br>Steuern per MQTT: **ja (Lautstärke, Wiedergabe …)** |
| **Einrichtung** | sonn starten: **ein Knopf mit Vorschlägen (Netz, Adresse, MAC, Musikordner)**, danach Liste „So geht es weiter“ | sonn starten: **im Reiter Audioserver**, danach Kopplung im Assistenten der sonn-Verwaltung |
| **Musik** | Musikordner über die Speicherauswahl von LoxBerry (USB, Netzfreigabe), nur lesend: **ja**<br>Netzfreigabe direkt in sonn einhängen: **nein** | Netzfreigabe direkt in sonn einhängen: **ja** |
| **Besser geeignet, wenn …** | Seriennummer: **soll fest bleiben**<br>Updates: **nur nach eigenem Ja, mit Rückweg**<br>Werte: **über das Gateway V2 wie bei den übrigen Plugins**<br>Lautsprecher: **Google Cast** | Player: **am LoxBerry selbst (Soundkarte, Squeezelite)**<br>Spielerübersicht: **mit Cover auf einem Display**<br>Zonen: **per MQTT steuern**<br>LoxBerry: **nur im WLAN** |

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
- **Musikordner nur lesend – zwei Folgen (aus dem Quelltext von sonn beta.22, nicht gemessen):** eingebettete Cover
  kann sonn nicht als `cover.jpg` in den Albumordner schreiben; es nimmt dann eine vorhandene `cover.jpg`/`.png`/`.webp`
  des Ordners, sonst zeigt es kein Cover. Ist die SSD beim Scan nicht erreichbar, nimmt sonn ihre Titel bis zum nächsten
  „Neu scannen“ aus der Bibliothek; die Dateien selbst bleiben unberührt. Verweise (Symlinks) im Musikordner liest sonn nicht.
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
- **Favoritenliste (0.9.2):** „In sonn übernehmen“ ersetzt die Favoriten der Zone – auch die in der Loxone App
  angelegten (Kopie im Datenordner). Wer danach Favoriten in der App ändert, ändert deren Nummern nicht; ein erneutes
  Übernehmen überschreibt sie aber. `liste` und `datei` brauchen den eingelesenen Musikordner (Lokale Bibliothek,
  Ordner `LoxBerry-Musik`; der Pfad zählt ab dem Musikordner); fehlt ein Ordner, wird diese Nummer übersprungen und bleibt frei. sonn nennt eine Datei mit Tags nach
  ihrem Titel – bei `datei` dann den Titel angeben, wie sonn ihn zeigt, oder den Ordner als `liste` nehmen. Ob
  sonn eine Kennung frei setzen lässt, sagt seine öffentliche Schnittstelle nicht: das Plugin legt die Favoriten
  der Reihe nach an und prüft jede Nummer; vergibt eine künftige sonn-Fassung die Nummern anders, bricht es mit einer
  Meldung ab.

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
   Miniserver laden · ③ in sonn je Raum eine Zone mit Lautsprecher (Ausgang „Google Cast“) · ④ Musik: der Musikordner
   erscheint in sonn unter Inhalte → Bibliothek → Lokale Bibliothek im Ordner `LoxBerry-Musik`; danach „Neu scannen“
   bzw. im Plugin „Bibliothek neu einlesen“ (keine Netzfreigabe in sonn anlegen) · ⑤ Reiter **Test** ansehen.
   Gemessen werden Konto und Loxone-Schalter, Kopplung, Zonen mit Lautsprecher, die Titel der Lokalen Bibliothek
   (④ erledigt, sobald es mehr als 0 sind) und der Gesamtzustand.
3. Reiter **MQTT:** die Datenpunkte der Hakenliste im Gateway V2 anhaken.
4. Wahlweise **Favoriten** (Reiter Einstellungen, Abschnitt „Favoriten“): Liste eintragen, speichern, Haken setzen,
   „In sonn übernehmen“. In Loxone Config kommt die Nummer an den Eingang *Fav* des Audio Players (z. B. ein
   Baustein, der beim Tastendruck den Wert 3 ausgibt) – dann spielt Nr. 3 der Liste in dieser Zone.

## Hilfe und Musterprojekt
Der Reiter „Einbindung in Loxone“ zeigt drei Bilder aus dem LoxBerry-Plugins Musterprojekt: die Seiten „Audioserver NG“
(Dienstwerte, Lebenszeichen, Status, Befehlstasten Neustart und Bibliothek), „Audioserver NG Werte“ (je Zone 8 Werte mit
Status) und „Audioserver NG Zonen“ (Audio-Player Wohnzimmer und Küche mit Klingel, Wecker und Durchsage).
Die drei Seiten liegen seit 31.10.2026 im
[LoxBerry-Plugins Musterprojekt](https://github.com/timanders22/LoxBerry-Plugins-Musterprojekt) und lassen sich dort
fertig verdrahtet ansehen und in das eigene Projekt kopieren. Die Hilfe steht im Plugin (Hilfe der LoxBerry-Kopfzeile)
und im LoxBerry-Wiki auf der Seite [Audioserver NG](https://wiki.loxberry.de/plugins/audioserverng/start).

## Datenschutz
Ins Internet geht das Plugin nur auf Knopfdruck und nur zu ghcr.io: beim Laden eines Abbilds (Anlegen, Update) und
beim Anzeigen der verfügbaren Fassungen. Für die Liste holt es anonym, ohne Konto, einen Lese-Token und fragt die
Tag-Liste und je Tag den Digest ab; das Ergebnis bleibt 6 Stunden zwischengespeichert. Daten des Hauses werden dabei
nicht gesendet. Das sonn-Konto (für „Bibliothek einlesen“ und Custom Streams) steht in der Konfiguration (0600) und geht nur über die
Standardeingabe in den Container, nie auf eine Befehlszeile.

## Lizenz
MIT (siehe `LICENSE`). Hinweise zu sonn core und Audioserver4Home in `NOTICE`.
