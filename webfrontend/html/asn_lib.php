<?php
/**
 * Audioserver NG - gemeinsame Bibliothek (Kuerzel asn, Ordner audioserverng).
 *
 * Liegt unter webfrontend/html/, weil der Endpunkt (webfrontend/html/index.php) sie ebenso braucht wie die
 * Oberflaeche, der Takt (bin/asn_takt.php), der Hintergrundvorgang (bin/asn_vorgang.php), die Befehlsbruecke
 * (bin/befehle_zuordnung.php) und bin/healthcheck (Regeln/03).
 *
 * ------------------------------------------------------------------
 * Was das Plugin tut (Audio Stufe 2, Weg F; Bauvorschrift Musterprojekt/BAUVORSCHRIFT_AUDIO_STUFE2.md)
 * ------------------------------------------------------------------
 * Eine schlanke Huelle um sonn core (ghcr.io/sonn-audio/core, Nachbildung des Loxone Audioservers, unveraendert als
 * Docker-Container): Container mit festem Abbild (Digest), eigener Heimnetz-Adresse (macvlan) und fester MAC verwalten,
 * Musikordner nur lesend einhaengen, Zustand ueber das MQTT-Gateway V2 melden, Neustart und "Bibliothek einlesen" ueber
 * die Befehlsbruecke 1.0.2. Die Musikbedienung selbst laeuft nativ ueber die Loxone App und den Audio-Player-Baustein.
 *
 * Lehre aus der Probe 30.10.2026: der Container haengt NUR im macvlan-Netz. Mit zusaetzlichem bridge-Netz meldete sich
 * sonn mit Bridge-Adresse und -MAC, und der Miniserver fand ihn nicht. Ein macvlan-Kind erreicht seinen eigenen Wirt
 * nicht - deshalb fragt das Plugin sonn ueber "docker exec" (bin/asn_abfrage.js im Container, oeffentliche API auf
 * 127.0.0.1:7090) statt ueber das Netz, und sonns eigenes MQTT erreicht den Broker des LoxBerry nicht.
 *
 * Grenzen (README, Hilfe, Reiter Einstellungen): keine synchrone Mehrraum-Wiedergabe ueber Google Cast; Google-Gruppen
 * sind in sonn nicht als Ausgang waehlbar - Mehrraum und Klingel ueber Google-Gruppen macht Chromecast4lox.
 *
 * Grundsaetze (Bauart Alexa NG 1.0.8 ax_hue_docker_*, MGiSmart mg_gw_*):
 *   - Nur EIGENES wird angefasst: Container und eigenes Netz tragen die Labels de.loxberry.plugin.folder=<ordner> und
 *     de.loxberry.plugin.name=<ordner>-sonn. Ein mitgenutztes Netz wird nie geaendert oder entfernt. Der Probe-Container
 *     (sonn-probe, Label de.loxberry.probe=audio-stufe2) wird nur auf ausdrueckliches Ja entfernt.
 *   - Jeder Aufruf argumentweise (proc_open mit Liste, keine Schale), mit timeout. Docker wird nur gefragt, wenn die
 *     Verwaltung eingeschaltet ist oder ein eigener Container angelegt wurde - ab Werk nie.
 *   - Zugangsdaten (sonn-Konto) gehen nur ueber stdin in den Container, nie auf eine Befehlszeile.
 *
 * Praefix 'asn_', weil LBWeb::lbheader() SDK-Globale setzt. PHP 7.4, 8.4, 8.5 (LoxBerry 3.x/4.x).
 */

if (!function_exists('asn_e')) {
    function asn_e($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}

/* Feste Groessen an EINER Stelle (Regeln/04). */
define('ASN_PRAEFIX', 'audioserverng');      // MQTT-Praefix = Ordnername (Hausform), nicht einstellbar
define('ASN_ZONEN_MAX', 24);                  // so viele Zonen kennt der Loxone Audioserver (sonn-Doku)
define('ASN_PROBE_NAME', 'sonn-probe');       // Container der Probe vom 30.10.2026 (von Hand gestartet)
define('ASN_PROBE_LABEL', 'de.loxberry.probe');
define('ASN_PROBE_WERT', 'audio-stufe2');
define('ASN_VOLL_S', 1800);                   // voller MQTT-Satz alle 30 min, sonst nur Aenderungen
define('ASN_ABFRAGE_S', 20);                  // Obergrenze einer Abfrage per docker exec
define('ASN_FAVORITEN_S', 240);              // 0.9.2: Obergrenze fuer das Uebernehmen der Favoriten (alle Zonen)
define('ASN_FAV_MAX', 99);                   // 0.9.2: Favoriten-Nummern 1 bis 99 (= Eingang Fav in Loxone)
define('ASN_ZIEHEN_S', 900);                  // Obergrenze fuer docker pull (rund 330 MB gepackt)
define('ASN_KOPIE_S', 600);                   // Obergrenze fuer das Kopieren eines Datenordners
define('ASN_TAKT_GRENZE', 180);               // Takt-Lebenszeichen gilt bis 3 min
define('ASN_RAM_WARNUNG_MB', 500);            // Abbruchbedingung der Probe: unter 500 MB verfuegbar
define('ASN_LOG_MAX', 262144);
define('ASN_LOG_REST', 300);
define('ASN_TOKEN_ZEICHEN', 'abcdefghijkmnpqrstuvwxyz23456789');
define('ASN_TOKEN_LAENGE', 24);
// 0.9.2 Nachzug Musikordner (Geraetebefund 01.11.2026 03:15): sonn core 4.0.0-beta.22 kennt keinen frei waehlbaren
// Bibliotheksordner. Gescannt werden nur data/music/local (Lokale Bibliothek) und data/music/nas/<id> (Freigaben, die
// sonn selbst einhaengt) - localLibraryProvider.js rescan()/scanStorage(). Ein Ordner unter /musik war deshalb nie
// sichtbar. Der Musikordner kommt jetzt nur lesend als Unterordner der Lokalen Bibliothek in den Container. Der Name ist
// fest: sonn legt hochgeladene Titel als <Kuenstler>/<Album> an (resolveTagBasedSubdir, toSafeFolderName); ein Kuenstler
// "LoxBerry-Musik" ist nicht zu erwarten, und vor dem Anlegen prueft asn_musik_punkt_pruefen(), dass dort nichts liegt.
define('ASN_MUSIK_NAME', 'LoxBerry-Musik');
define('ASN_MUSIK_ZIEL', '/app/data/music/local/' . ASN_MUSIK_NAME);
define('ASN_MUSIK_ALT', '/musik');            // Einhaengepunkt bis 0.9.2 (Probe, aeltere eigene Container) - nur noch lesen

/* ==================================================================
 * Fassung, Wurzel, Pfade (Bauart Xiaomi Licht 0.9.3 xl_paths())
 * ================================================================== */

/** Die Fassung wird GELESEN: Plugin-Datenbank ueber den Ordnernamen, ersatzweise plugin.cfg, sonst leer. */
function asn_fassung()
{
    static $f = null;
    if ($f !== null) {
        return $f;
    }
    $f = '';
    $p = asn_paths();
    if ($p['home'] !== '') {
        $db = $p['home'] . '/data/system/plugindatabase.json';
        $d = is_file($db) ? json_decode((string) @file_get_contents($db), true) : null;
        if (is_array($d) && isset($d['plugins']) && is_array($d['plugins'])) {
            foreach ($d['plugins'] as $e) {
                if (is_array($e) && isset($e['folder'], $e['version']) && $e['folder'] === $p['plugin'] && is_scalar($e['version'])) {
                    $f = trim((string) $e['version']);
                    break;
                }
            }
        }
    }
    if ($f === '') {
        $k = dirname(dirname(__DIR__)) . '/plugin.cfg';
        $roh = is_readable($k) ? (string) @file_get_contents($k) : '';
        if (preg_match('/^\s*VERSION\s*=\s*([0-9][0-9A-Za-z.\-]*)\s*$/m', $roh, $m)) {
            $f = $m[1];
        }
    }
    return $f;
}

if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/webfrontend') && is_file($d . '/config/system/general.json')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) {
                break;
            }
            $d = $eltern;
        }
        return '';
    }
}

function asn_lbhome()
{
    $h = getenv('LBHOMEDIR');
    if ($h && is_dir($h . '/config/plugins') && is_dir($h . '/data/plugins')) {
        return rtrim($h, '/');
    }
    return lb_wurzel_ermitteln();
}

/**
 * Pfade der Anlage. Die Pfade DER ANLAGE gelten nur, wenn diese Bibliothek dort installiert liegt oder LBHOMEDIR UND
 * LBPPLUGINDIR ausdruecklich gesetzt sind (Pruefwerkzeuge). Sonst Archivmodus: alles im eigenen Ordner.
 *
 * NEBEN dem Datenordner (ueberstehen purge_installation beim Upgrade, Regeln/03): 'merk' (was angelegt wurde),
 * 'marke' (Upgrade-Marke) und 'sonn' (Ordner fuer die Daten von sonn: je Abbild ein Unterordner daten_<12 Hex>, dazu
 * musik/ als Vorgabe-Musikordner). Der Ordner 'sonn' gehoert nach dem ersten Start teilweise root (der Container laeuft
 * als root, wie die Probe); die Deinstallation laesst ihn stehen.
 */
function asn_paths()
{
    static $p = null;
    if ($p !== null) {
        return $p;
    }
    $home = asn_lbhome();
    $dir = basename(dirname(__FILE__));
    $lbp = basename(rtrim((string) getenv('LBPPLUGINDIR'), '/'));
    $lbp_gilt = ($lbp !== '' && !in_array($lbp, array('.', '..', '/', 'html', 'htmlauth', 'bin', 'plugins', 'webfrontend'), true));
    if ($lbp_gilt) {
        $dir = $lbp;
    } elseif (in_array($dir, array('', '.', '/', 'html', 'bin', 'plugins'), true)) {
        $dir = 'audioserverng';
    }
    $gefunden = $home;
    if ($home !== '') {
        $soll = @realpath($home . '/webfrontend/html/plugins/' . basename(__DIR__));
        $ist = @realpath(__DIR__);
        $installiert = ($soll !== false && $ist !== false && $soll === $ist);
        $ausdruecklich = $lbp_gilt && $home === rtrim((string) getenv('LBHOMEDIR'), '/');
        if (!$installiert && !$ausdruecklich) {
            $home = '';
        }
    }
    if ($home !== '') {
        $neben = $home . '/data/plugins/' . $dir;
        $p = array(
            'home'      => $home,
            'archiv'    => '',
            'plugin'    => $dir,
            'configdir' => $home . '/config/plugins/' . $dir,
            'config'    => $home . '/config/plugins/' . $dir . '/audioserverng.json',
            'sicherung' => $home . '/config/plugins/' . $dir . '.backup.json',
            'datadir'   => $neben,
            'bindir'    => $home . '/bin/plugins/' . $dir,
            'htmlauth'  => $home . '/webfrontend/htmlauth/plugins/' . $dir,
            'logdir'    => $home . '/log/plugins/' . $dir,
            'log'       => $home . '/log/plugins/' . $dir . '/asn.log',
            'merk'      => $neben . '.container.json',
            'marke'     => $neben . '.upgrade_laeuft',
            'sonn'      => $neben . '.sonn',
        );
    } else {
        $basis = dirname(dirname(__DIR__));
        $p = array(
            'home'      => '',
            'archiv'    => $gefunden,
            'plugin'    => $dir,
            'configdir' => $basis . '/config',
            'config'    => $basis . '/config/audioserverng.json',
            'sicherung' => $basis . '/config/audioserverng.backup.json',
            'datadir'   => $basis . '/data',
            'bindir'    => $basis . '/bin',
            'htmlauth'  => $basis . '/webfrontend/htmlauth',
            'logdir'    => $basis . '/log',
            'log'       => $basis . '/log/asn.log',
            'merk'      => $basis . '/data/container.json',
            'marke'     => $basis . '/data/upgrade_laeuft',
            'sonn'      => $basis . '/data/sonn',
        );
    }
    $p['zustand'] = $p['datadir'] . '/zustand.json';
    $p['gesendet'] = $p['datadir'] . '/gesendet.json';
    $p['vorgang'] = $p['datadir'] . '/vorgang.json';
    $p['vorgang_sperre'] = $p['datadir'] . '/vorgang.pid';
    $p['takt_sperre'] = $p['datadir'] . '/takt.pid';
    $p['abbild'] = $p['bindir'] . '/asn_abbild.json';
    $p['abfrage'] = $p['bindir'] . '/asn_abfrage.js';
    return $p;
}

/** Fuer Takt, Vorgang und healthcheck: ohne Wurzel oder aus einem ausgepackten Archiv heraus nichts tun. */
function asn_keine_wurzel_abbruch($programm)
{
    $p = asn_paths();
    if ($p['home'] !== '') {
        return;
    }
    fwrite(STDERR, $programm . ': Diese Datei liegt nicht in einer LoxBerry-Installation (ausgepacktes Archiv oder'
        . ' Pruefordner). Es wurde nichts abgefragt, nichts gesendet und nichts geschrieben.' . "\n"
        . 'Abhilfe: LBHOMEDIR und LBPPLUGINDIR ausdruecklich setzen.' . "\n");
    exit(1);
}

/* ==================================================================
 * Abbild (eine Quelle: bin/asn_abbild.json)
 * ================================================================== */

/** Das festgelegte Abbild aus bin/asn_abbild.json: array(repo, digest, tag, datum) - leer, wenn die Datei fehlt. */
function asn_abbild_vorgabe()
{
    static $a = null;
    if ($a !== null) {
        return $a;
    }
    $a = array('repo' => '', 'digest' => '', 'tag' => '', 'datum' => '');
    $p = asn_paths();
    $kand = array($p['abbild'], dirname(dirname(__DIR__)) . '/bin/asn_abbild.json');
    foreach ($kand as $k) {
        $d = is_file($k) ? json_decode((string) @file_get_contents($k), true) : null;
        if (is_array($d) && isset($d['repo'], $d['digest']) && asn_digest_gueltig($d['digest'])
            && preg_match('#^[a-z0-9.\-]+(/[a-z0-9._\-]+)+\z#', (string) $d['repo'])) {
            $a = array('repo' => $d['repo'], 'digest' => $d['digest'], 'tag' => isset($d['tag']) ? (string) $d['tag'] : '',
                       'datum' => isset($d['datum']) ? (string) $d['datum'] : '');
            break;
        }
    }
    return $a;
}

function asn_digest_gueltig($d)
{
    return is_string($d) && preg_match('/^sha256:[0-9a-f]{64}\z/', $d) === 1;
}

/** Der Digest, mit dem der Container laufen soll: der eingestellte (nach einem Update mit Ja) oder die Vorgabe. */
function asn_abbild_digest(array $cfg)
{
    return asn_digest_gueltig($cfg['abbild']) ? $cfg['abbild'] : asn_abbild_vorgabe()['digest'];
}

/** repo@digest fuer docker - oder '' (dann ist nichts anzulegen). */
function asn_abbild_ref($digest)
{
    $v = asn_abbild_vorgabe();
    return ($v['repo'] !== '' && asn_digest_gueltig($digest)) ? $v['repo'] . '@' . $digest : '';
}

function asn_kurzdigest($d)
{
    return asn_digest_gueltig($d) ? substr($d, 7, 12) : '';
}

/* ==================================================================
 * Konfiguration
 * ================================================================== */

/** Vorgaben - EINE Liste fuer Oberflaeche, Takt, Vorgang, Sicherung und Selbstpruefung. */
function asn_vorgaben()
{
    return array(
        'container_ein'    => 0,          // Container-Verwaltung (ab Werk aus: Docker wird nicht gefragt)
        'schnittstelle'    => 'eth0',     // Netzwerkschnittstelle des LoxBerry, an der das macvlan-Netz haengt
        'netz_art'         => 'mitnutzen',// mitnutzen | eigen (Frage F2)
        'netz_name'        => '',         // bei "mitnutzen": Name eines vorhandenen macvlan-Netzes
        'ip'               => '',         // eigene Heimnetz-Adresse von sonn
        'mac'              => '',         // feste MAC (daraus die Seriennummer in Config)
        'hostname'         => 'sonn',
        'speicher_mb'      => 1024,       // --memory (Frage F8)
        'cpus'             => 2,          // --cpus
        'musik_pfad'       => '',         // Musikordner, nur lesend als ASN_MUSIK_ZIEL (Lokale Bibliothek von sonn)
        'abbild'           => '',         // Digest nach einem Update mit Ja ('' = Vorgabe aus bin/asn_abbild.json)
        'abbild_vorher'    => '',         // Digest vor dem letzten Update (Rueckweg)
        'datenordner'      => '',         // Unterordner von <ordner>.sonn/ mit den Daten des laufenden Abbilds
        'datenordner_vorher' => '',       // Datenordner vor dem letzten Update (Rueckweg)
        'mqtt_ein'         => 1,          // Werte ueber das MQTT-Gateway V2 senden
        'befehle_mqtt_ein' => 0,          // erster Haken der Befehlsbruecke (ab Werk aus)
        'steuerung_ein'    => 0,          // zweiter Haken: Neustart aus Loxone (ab Werk aus)
        'endpunkt_ein'     => 0,          // HTTP-Lesezweig (ab Werk aus)
        'aktionstoken'     => '',
        'wartezeit'        => 6,          // so lange wartet ein Befehl der Bruecke (Werk der Bruecke)
        'sonn_benutzer'    => '',         // sonn-Konto nur fuer "Bibliothek einlesen" (Frage F5)
        'sonn_kennwort'    => '',
        'zonen_hoechste'   => 0,          // hoechste je gemeldete Zonennummer (Abraeumen bei der Deinstallation)
        'favoriten'        => '',         // 0.9.2: je Zeile "Nr = radio|liste|datei | Name | Quelle" (ab Werk leer)
        'favoriten_zonen'  => '',         // 0.9.2: Zonen fuer die Favoriten, z. B. "1,2"; leer = alle Zonen von sonn
        'tunein_ausblenden' => 0,         // 0.9.3: Vorgabesender von TuneIn in sonn ausblenden (ab Werk aus)
    );
}

/** Schluessel, die in einer Sicherung fehlen duerfen (dann gilt der geltende Wert). */
function asn_spaet_schluessel()
{
    return array('befehle_mqtt_ein', 'steuerung_ein', 'endpunkt_ein', 'zonen_hoechste', 'favoriten', 'favoriten_zonen', 'tunein_ausblenden');
}

function asn_json_lesen($pfad)
{
    if (!is_file($pfad)) {
        return array();
    }
    $d = json_decode((string) @file_get_contents($pfad), true);
    return is_array($d) ? $d : array();
}

/** Unteilbar schreiben: Nebendatei, Rechte VOR dem Inhalt, zurueckgelesen, dann umbenennen. */
function asn_json_schreiben($pfad, $daten, $rechte = null)
{
    $ordner = dirname($pfad);
    if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) {
        return false;
    }
    $json = json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    $tmp = $pfad . '.tmp.' . getmypid();
    $fh = @fopen($tmp, 'c');
    if ($fh === false) {
        return false;
    }
    if ($rechte !== null) {
        @chmod($tmp, $rechte);
    }
    $ok = ftruncate($fh, 0) && @fwrite($fh, $json) === strlen($json);
    $ok = @fflush($fh) && $ok;
    $ok = @fclose($fh) && $ok;
    if ($ok) {
        clearstatcache(true, $tmp);
        $ok = ((string) @file_get_contents($tmp) === $json);
    }
    if (!$ok || !@rename($tmp, $pfad)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function asn_config_lage($setzen = null)
{
    static $lage = 'ok';
    if ($setzen !== null) {
        $lage = $setzen;
    }
    return $lage;
}

/** Konfiguration lesen; $heilen = false legt nichts an (Endpunkt, Bruecke, healthcheck, Takt). */
function asn_config($heilen = true)
{
    $p = asn_paths();
    $roh = is_file($p['config']) ? trim((string) @file_get_contents($p['config'])) : '';
    $daten = null;
    $lage = 'ok';
    if ($roh !== '') {
        $daten = json_decode($roh, true);
        if (!is_array($daten)) {
            $lage = 'kaputt';
            $daten = null;
            if ($heilen) {
                @rename($p['config'], $p['config'] . '.kaputt');
                asn_log_gebremst('cfg_kaputt', 'Die Konfiguration war kein gueltiges JSON. Sie liegt jetzt als '
                    . basename($p['config']) . '.kaputt daneben; weitergearbeitet wird mit der Zweitschrift.');
            }
        } elseif (!$daten) {
            $lage = 'leer';
            $daten = null;
        }
    } else {
        $lage = 'leer';
    }
    if ($daten === null) {
        $z = asn_json_lesen($p['sicherung']);
        if ($z) {
            $daten = $z;
            $lage = 'zweitschrift';
            if ($heilen) {
                @mkdir($p['configdir'], 0775, true);
                asn_json_schreiben($p['config'], $z, 0600);
                asn_log_gebremst('cfg_geheilt', 'Die Konfiguration wurde aus der Zweitschrift wiederhergestellt.');
            }
        }
    }
    asn_config_lage($lage);
    return array_merge(asn_vorgaben(), is_array($daten) ? $daten : array());
}

function asn_config_speichern($cfg)
{
    $p = asn_paths();
    $neu = array();
    foreach (array_keys(asn_vorgaben()) as $k) {
        $neu[$k] = isset($cfg[$k]) ? $cfg[$k] : asn_vorgaben()[$k];
    }
    if (!asn_json_schreiben($p['config'], $neu, 0600)) {
        return false;
    }
    if (asn_config_lage() === 'kaputt') {
        return true;
    }
    if (!asn_json_schreiben($p['sicherung'], $neu, 0600)) {
        asn_log_gebremst('zweitschrift', 'Die Zweitschrift ' . basename($p['sicherung'])
            . ' liess sich nicht schreiben; die Konfiguration selbst ist gespeichert.');
    }
    return true;
}

function asn_token_erzeugen($laenge = ASN_TOKEN_LAENGE)
{
    $z = ASN_TOKEN_ZEICHEN;
    $t = '';
    for ($i = 0; $i < $laenge; $i++) {
        $t .= $z[random_int(0, strlen($z) - 1)];
    }
    return $t;
}

function asn_token_gueltig($t)
{
    return is_string($t) && preg_match('/^[' . ASN_TOKEN_ZEICHEN . ']{' . ASN_TOKEN_LAENGE . '}\z/', $t) === 1;
}

/** Das Aktionstoken; fehlt es, wird es erzeugt und gespeichert (nur aus der Oberflaeche). */
function asn_token()
{
    $cfg = asn_config();
    if (trim((string) $cfg['aktionstoken']) === '') {
        $cfg['aktionstoken'] = asn_token_erzeugen();
        asn_config_speichern($cfg);
    } elseif (array_diff_key(asn_vorgaben(), asn_json_lesen(asn_paths()['config']))) {
        // 0.9.3: nach einem Update fehlen neue Schluessel; die Konfiguration wird beim Oeffnen der Seite vervollstaendigt (sonst ist die Zeile im Reiter Test rot, bis jemand speichert).
        asn_config_speichern($cfg);
    }
    return (string) $cfg['aktionstoken'];
}

/** Das Merkmal jedes Formulars; ohne Aktionstoken leer - dann weist die Pruefung ab (fail closed). */
function asn_formtoken($cfg = null)
{
    if ($cfg === null) {
        $cfg = asn_config();
    }
    $t = trim((string) (isset($cfg['aktionstoken']) ? $cfg['aktionstoken'] : ''));
    return $t === '' ? '' : hash_hmac('sha256', 'formular-v1', $t);
}

/* ==================================================================
 * Sprache
 * ================================================================== */

function asn_sprache()
{
    $s = 'de';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'lblanguage')) {
        $s = LBSystem::lblanguage();
    } elseif (getenv('LBLANG')) {
        $s = getenv('LBLANG');
    }
    $s = strtolower(substr((string) $s, 0, 2));
    return in_array($s, array('de', 'en'), true) ? $s : 'en';
}

function asn_t($schluessel)
{
    static $texte = null;
    if ($texte === null) {
        $home = asn_paths()['home'];
        $pfad = $home !== '' ? $home . '/templates/plugins/' . asn_paths()['plugin'] . '/lang' : '';
        if ($pfad === '' || !is_dir($pfad)) {
            $pfad = dirname(dirname(__DIR__)) . '/templates/lang';
        }
        $texte = @parse_ini_file($pfad . '/language_' . asn_sprache() . '.ini', true, INI_SCANNER_RAW);
        if (!is_array($texte)) {
            $texte = array();
        }
        $rueck = @parse_ini_file($pfad . '/language_en.ini', true, INI_SCANNER_RAW);
        if (is_array($rueck)) {
            $texte = array_replace_recursive($rueck, $texte);
        }
        foreach ($texte as $ab => $paare) {
            if (!is_array($paare)) {
                continue;
            }
            foreach ($paare as $s => $w) {
                $texte[$ab][$s] = trim((string) $w, '"');
            }
        }
    }
    $teile = array_pad(explode('.', (string) $schluessel, 2), 2, '');
    return isset($texte[$teile[0]][$teile[1]]) ? $texte[$teile[0]][$teile[1]] : (string) $schluessel;
}

function asn_klartext($schluessel)
{
    return html_entity_decode(strip_tags(asn_t($schluessel)), ENT_QUOTES, 'UTF-8');
}

/** Der Text zu einem Grund 'WORT|a|b' aus dem Abschnitt GRUND (Platzhalter %s der Reihe nach). */
function asn_grund_text($grund)
{
    $teile = explode('|', (string) $grund);
    $wort = preg_replace('/[^A-Z0-9_]/', '', strtoupper((string) array_shift($teile)));
    if ($wort === '') {
        return '';
    }
    $t = asn_t('GRUND.' . $wort);
    if ($t === 'GRUND.' . $wort) {
        return $wort . ($teile ? ' (' . implode(', ', $teile) . ')' : '');
    }
    $n = substr_count($t, '%s');
    $teile = array_pad(array_slice($teile, 0, $n), $n, '?');
    return $n ? vsprintf($t, $teile) : $t;
}

/* ==================================================================
 * Protokoll (log/plugins/<ordner>/asn.log; Ramdisk - Kappung ist Pflicht)
 * ================================================================== */

function asn_log($text)
{
    $p = asn_paths();
    if (!is_dir($p['logdir'])) {
        @mkdir($p['logdir'], 0775, true);
    }
    clearstatcache(true, $p['log']);
    if (is_file($p['log']) && filesize($p['log']) > ASN_LOG_MAX) {
        $rest = array_slice(file($p['log'], FILE_IGNORE_NEW_LINES) ?: array(), -ASN_LOG_REST);
        @file_put_contents($p['log'], implode("\n", $rest) . "\n");
    }
    @file_put_contents($p['log'], '[' . date('Y-m-d H:i:s') . '] ' . asn_geheim_weg($text) . "\n", FILE_APPEND);
}

function asn_log_gebremst($schluessel, $text, $sekunden = 3600)
{
    $d = asn_paths()['datadir'];
    if (!is_dir($d)) {
        asn_log($text);
        return;
    }
    $f = $d . '/.meld_' . preg_replace('/[^a-z0-9_]/i', '', $schluessel);
    $letzte = is_file($f) ? (int) @file_get_contents($f) : 0;
    if (time() - $letzte >= $sekunden) {
        @file_put_contents($f, (string) time());
        asn_log($text);
    }
}

/** Geheimnisse (Aktionstoken, sonn-Kennwort) aus einem Text entfernen. */
function asn_geheim_weg($t)
{
    $cfg = asn_config(false);
    $weg = array();
    foreach (array('aktionstoken', 'sonn_kennwort') as $k) {
        if (isset($cfg[$k]) && is_string($cfg[$k]) && strlen($cfg[$k]) >= 4) {
            $weg[] = $cfg[$k];
        }
    }
    return $weg ? str_replace($weg, '***', (string) $t) : (string) $t;
}

function asn_log_ende($datei, $anzahl = 400, $block = 8192)
{
    if (!is_file($datei)) {
        return array();
    }
    $fp = @fopen($datei, 'rb');
    if ($fp === false) {
        return array();
    }
    fseek($fp, 0, SEEK_END);
    $pos = ftell($fp);
    $puffer = '';
    $zeilen = array();
    while ($pos > 0 && count($zeilen) <= $anzahl) {
        $lese = (int) min($block, $pos);
        $pos -= $lese;
        fseek($fp, $pos, SEEK_SET);
        $puffer = fread($fp, $lese) . $puffer;
        $zeilen = explode("\n", $puffer);
    }
    fclose($fp);
    $zeilen = array_values(array_filter(array_map('rtrim', $zeilen), 'strlen'));
    return array_slice(array_reverse($zeilen), 0, $anzahl);
}

/** Fremde Ausgabe fuer Protokoll und Oberflaeche: eine Zeile, ohne Steuerzeichen, gekuerzt. */
function asn_kurztext($s, $max = 200)
{
    $s = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) $s)));
    if (strlen($s) > $max) {
        $s = substr($s, 0, $max - 3);
        if (preg_match('//u', $s) !== 1) {
            $s = (string) preg_replace('/[\x80-\xFF]+\z/', '', $s);
        }
        $s .= '...';
    }
    return $s;
}

function asn_letzte_zeile($s)
{
    $z = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) $s)), 'strlen'));
    return $z ? asn_kurztext($z[count($z) - 1], 200) : '';
}

/* ==================================================================
 * Befehle ausfuehren (argumentweise) und Docker
 * ================================================================== */

/**
 * Auf ein Kind warten, ohne dass proc_close() haengen kann; Rueckgabe sein Rueckgabewert, 124 nach Ablauf der Frist.
 * Hoechstens $warten Sekunden ungestoert, dann SIGTERM, hoechstens $frist Sekunden, dann SIGKILL, erst dann proc_close(). Bis 0.9.2 stand hier proc_close()
 * ohne Frist: am Geraet hing mosquitto_sub am 01.11.2026 nach SIGTERM 5 Stunden fest, und der Aufrufer stand mit. proc_get_status() liefert den Rueckgabewert
 * nur beim ersten Aufruf nach dem Ende, proc_close() danach -1 (PHP unter 8.3), darum kommt er von hier und nicht von proc_close().
 */
function asn_proc_abwarten($pr, $warten, $frist = 2.0)
{
    $stufe = 0;
    $bis = microtime(true) + max(0.0, (float) $warten);
    $schlaf = 2000;
    while (true) {
        $s = @proc_get_status($pr);
        if (!is_array($s) || empty($s['running'])) {
            $rc = is_array($s) && isset($s['exitcode']) ? (int) $s['exitcode'] : -1;
            if (is_array($s) && !empty($s['signaled'])) { $rc = 128 + (int) $s['termsig']; }
            @proc_close($pr);
            return $stufe > 0 ? 124 : $rc;
        }
        if (microtime(true) >= $bis) {
            if ($stufe === 1) {
                @proc_terminate($pr, 9);
                @proc_close($pr);
                return 124;
            }
            @proc_terminate($pr, 15);
            $stufe = 1;
            $bis = microtime(true) + (float) $frist;
        }
        usleep($schlaf);
        $schlaf = min(50000, $schlaf * 2);
    }
}

/**
 * Einen Befehl argumentweise ausfuehren (proc_open mit Liste - keine Schale), begrenzt durch timeout. $stdin wird
 * hineingeschrieben (Zugangsdaten nie auf die Befehlszeile). Rueckgabe array(rc, stdout, stderr); rc 124 Zeitablauf,
 * 127 nicht ausfuehrbar. Unter Windows nur fuer Pruefstaende mit Attrappe (kein timeout-Programm).
 */
function asn_aufruf(array $cmd, $sekunden, $stdin = null)
{
    if (!function_exists('proc_open')) {
        return array(127, '', 'proc_open fehlt');
    }
    $win = DIRECTORY_SEPARATOR === '\\';
    /* -k 5 (bis 0.9.2 ohne): uebergeht das Kind SIGTERM, wartet timeout sonst ewig, und ein hartes Ende von timeout liesse das Kind als Waise zurueck. */
    $voll = $win ? array() : array('timeout', '-k', '5', (string) max(1, (int) $sekunden));
    foreach ($cmd as $a) {
        $voll[] = (string) $a;
    }
    $desk = array(0 => $stdin === null ? array('file', $win ? 'NUL' : '/dev/null', 'r') : array('pipe', 'r'),
                  1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
    $pipes = array();
    $proc = @proc_open($voll, $desk, $pipes);
    if (!is_resource($proc)) {
        return array(127, '', 'proc_open gescheitert');
    }
    if ($stdin !== null) {
        @fwrite($pipes[0], (string) $stdin);
        @fclose($pipes[0]);
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $aus = array(1 => '', 2 => '');
    $offen = array(1 => $pipes[1], 2 => $pipes[2]);
    $ende = time() + (int) $sekunden + 15;
    while ($offen && time() < $ende) {
        $lesen = array_values($offen);
        $w = null;
        $e = null;
        if (@stream_select($lesen, $w, $e, 1) === false) {
            break;
        }
        foreach ($offen as $n => $h) {
            $t = fread($h, 65536);
            if ($t !== false && $t !== '' && strlen($aus[$n]) < 4194304) {
                $aus[$n] .= $t;
            }
            if (feof($h)) {
                fclose($h);
                unset($offen[$n]);
            }
        }
    }
    foreach ($offen as $h) {
        fclose($h);
    }
    $rc = asn_proc_abwarten($proc, max(0, $ende - time()));
    /* 137: timeout -k hat zugeschlagen, auch das ist fuer den Aufrufer Zeitablauf. */
    return array((int) $rc === 137 ? 124 : (int) $rc, $aus[1], $aus[2]);
}

/** Das docker-Programm als Feld (Pruefstand: ASN_DOCKER_ATTRAPPE = PHP-Skript), oder array(). */
function asn_docker_bin()
{
    static $bin = null;
    if ($bin !== null) {
        return $bin;
    }
    $bin = array();
    $att = (string) getenv('ASN_DOCKER_ATTRAPPE');
    if ($att !== '' && is_file($att)) {
        $bin = array(PHP_BINARY, $att);
        return $bin;
    }
    if (DIRECTORY_SEPARATOR !== '\\') {
        foreach (array('/usr/bin/docker', '/usr/local/bin/docker', '/bin/docker') as $k) {
            if (is_file($k) && is_executable($k)) {
                $bin = array($k);
                break;
            }
        }
    }
    return $bin;
}

function asn_docker(array $args, $sekunden, $stdin = null)
{
    $bin = asn_docker_bin();
    if (!$bin) {
        return array(127, '', 'docker fehlt');
    }
    return asn_aufruf(array_merge($bin, $args), $sekunden, $stdin);
}

function asn_docker_json($out)
{
    $d = json_decode((string) $out, true);
    return (is_array($d) && isset($d[0]) && is_array($d[0])) ? $d[0] : null;
}

/** Ist Docker erreichbar? array(lage, grund, fassung): ok | fehlt | kein_zugriff | dienst_aus | haengt | fehler. */
function asn_docker_lage($sekunden = 10)
{
    if (!asn_docker_bin()) {
        return array('fehlt', 'DOCKER_FEHLT', '');
    }
    list($rc, $out, $err) = asn_docker(array('info', '--format', '{{.ServerVersion}}'), $sekunden);
    $fassung = trim($out);
    if ($rc === 0 && preg_match('/^[0-9A-Za-z.+\-~]{1,40}\z/', $fassung)) {
        return array('ok', '', $fassung);
    }
    if ($rc === 124) {
        return array('haengt', 'DOCKER_HAENGT|' . (int) $sekunden, '');
    }
    $t = strtolower($err . ' ' . $out);
    if (strpos($t, 'permission denied') !== false) {
        return array('kein_zugriff', 'DOCKER_KEIN_ZUGRIFF', '');
    }
    if (strpos($t, 'cannot connect') !== false || strpos($t, 'daemon running') !== false) {
        return array('dienst_aus', 'DOCKER_DIENST_AUS', '');
    }
    return array('fehler', 'DOCKER_FEHLER|' . (int) $rc, '');
}

/**
 * Kann dieser Kernel eine Speichergrenze fuer Container durchsetzen? true | false | null (nicht feststellbar).
 * Bevorzugt "docker info" Feld MemoryLimit (Engine-API SystemInfo: "Indicates if the host has memory limit support
 * enabled"); ServerVersion muss mitkommen - ohne Zugriff auf den Dienst setzt die Docker-Befehlszeile die Vorlage mit
 * leeren Werten ein und meldet MemoryLimit faelschlich als false (am LoxBerry gemessen 31.10.2026). Hilfsweise
 * cgroup v2: memory fehlt in cgroup.controllers (Raspberry Pi mit cgroup_disable=memory). Docker schreibt dazu nur
 * Warnungen auf stderr (rc 0) - die zaehlen nirgends als Fehler.
 */
function asn_speichergrenze_moeglich($sekunden = 10, $controllers = '/sys/fs/cgroup/cgroup.controllers')
{
    if (asn_docker_bin()) {
        list($rc, $out) = asn_docker(array('info', '--format', '{{.ServerVersion}}|{{json .MemoryLimit}}'), $sekunden);
        if ($rc === 0 && preg_match('/^([0-9A-Za-z.+\-~]{1,40})\|(true|false)\s*\z/', trim($out), $m)) {
            return $m[2] === 'true';
        }
    }
    $t = is_file($controllers) ? trim((string) @file_get_contents($controllers)) : '';
    if ($t === '') {
        return null;
    }
    return in_array('memory', preg_split('/\s+/', $t), true);
}

/** Die Namen dieses Plugins: Container, eigenes Netz, Label. */
function asn_namen()
{
    $o = strtolower(asn_paths()['plugin']);
    return array('container' => 'lb-' . $o . '-sonn', 'netz' => 'lb-' . $o . '-macvlan', 'label' => $o . '-sonn');
}

/** Traegt dieses Docker-Objekt (inspect eines Containers oder Netzes) BEIDE eigenen Labels? */
function asn_eigen($info)
{
    $l = asn_labels($info);
    return isset($l['de.loxberry.plugin.folder'], $l['de.loxberry.plugin.name'])
        && $l['de.loxberry.plugin.folder'] === asn_paths()['plugin'] && $l['de.loxberry.plugin.name'] === asn_namen()['label'];
}

function asn_labels($info)
{
    if (is_array($info) && isset($info['Config']['Labels']) && is_array($info['Config']['Labels'])) {
        return $info['Config']['Labels'];
    }
    if (is_array($info) && isset($info['Labels']) && is_array($info['Labels'])) {
        return $info['Labels'];
    }
    return array();
}

/** Kurzfassung eines Containers aus docker inspect (fuer eigenen Container und Probe gleich). */
function asn_container_kurz(array $info)
{
    $s = (isset($info['State']) && is_array($info['State'])) ? $info['State'] : array();
    $hc = (isset($info['HostConfig']) && is_array($info['HostConfig'])) ? $info['HostConfig'] : array();
    $netze = array();
    if (isset($info['NetworkSettings']['Networks']) && is_array($info['NetworkSettings']['Networks'])) {
        foreach ($info['NetworkSettings']['Networks'] as $n => $w) {
            $ip = (is_array($w) && isset($w['IPAddress']) && asn_ipv4_zahl($w['IPAddress']) !== null) ? $w['IPAddress'] : '';
            if ($ip === '' && is_array($w) && isset($w['IPAMConfig']['IPv4Address']) && asn_ipv4_zahl($w['IPAMConfig']['IPv4Address']) !== null) {
                $ip = $w['IPAMConfig']['IPv4Address'];
            }
            $mac = (is_array($w) && isset($w['MacAddress']) && asn_mac_gueltig($w['MacAddress'])) ? strtolower($w['MacAddress']) : '';
            $netze[(string) $n] = array('ip' => $ip, 'mac' => $mac);
        }
    }
    $mounts = array();
    foreach ((isset($info['Mounts']) && is_array($info['Mounts'])) ? $info['Mounts'] : array() as $m) {
        if (is_array($m) && isset($m['Destination'], $m['Source']) && is_string($m['Destination'])) {
            $mounts[$m['Destination']] = array('quelle' => (string) $m['Source'], 'rw' => !empty($m['RW']));
        }
    }
    $start = isset($s['StartedAt']) && is_string($s['StartedAt']) ? strtotime($s['StartedAt']) : false;
    $l = asn_labels($info);
    return array(
        'id'        => (isset($info['Id']) && is_string($info['Id'])) ? substr($info['Id'], 0, 12) : '',
        'laeuft'    => !empty($s['Running']) && empty($s['Restarting']),
        'status'    => (isset($s['Status']) && is_string($s['Status'])) ? asn_kurztext($s['Status'], 40) : '',
        'fehler'    => (isset($s['Error']) && is_string($s['Error'])) ? asn_kurztext($s['Error'], 160) : '',
        'neustarts' => isset($info['RestartCount']) ? (int) $info['RestartCount'] : 0,
        'seit'      => ($start !== false && $start > 0) ? max(0, time() - $start) : -1,
        'def'       => isset($l['de.loxberry.plugin.def']) && is_string($l['de.loxberry.plugin.def']) ? $l['de.loxberry.plugin.def'] : '',
        'probe'     => isset($l[ASN_PROBE_LABEL]) && $l[ASN_PROBE_LABEL] === ASN_PROBE_WERT,
        'bild'      => (isset($info['Config']['Image']) && is_string($info['Config']['Image'])) ? $info['Config']['Image'] : '',
        'bild_id'   => (isset($info['Image']) && is_string($info['Image'])) ? $info['Image'] : '',
        'netze'     => $netze,
        'mounts'    => $mounts,
        'speicher'  => isset($hc['Memory']) ? (int) $hc['Memory'] : 0,
        'nanocpus'  => isset($hc['NanoCpus']) ? (int) $hc['NanoCpus'] : 0,
        'neustart_regel' => (isset($hc['RestartPolicy']['Name']) && is_string($hc['RestartPolicy']['Name'])) ? $hc['RestartPolicy']['Name'] : '',
    );
}

/**
 * Ein Container nach Name. Rueckgabe array(gefunden, info|null): gefunden null = Docker nicht zu fragen, false = gibt
 * es nicht, true = da (info = rohes inspect).
 */
function asn_inspect_container($name, $sekunden = 15)
{
    list($rc, $out, $err) = asn_docker(array('inspect', '--type', 'container', $name), $sekunden);
    if ($rc !== 0) {
        return (stripos($err . $out, 'no such') !== false) ? array(false, null) : array(null, null);
    }
    $info = asn_docker_json($out);
    return $info === null ? array(null, null) : array(true, $info);
}

/** Der eigene Container: array(gefunden, eigen, kurz). */
function asn_container($sekunden = 15)
{
    list($da, $info) = asn_inspect_container(asn_namen()['container'], $sekunden);
    if (!$da) {
        return array($da, false, null);
    }
    return array(true, asn_eigen($info), asn_container_kurz($info));
}

/** Der Probe-Container (nur mit Name UND Label): array(gefunden, kurz). gefunden null = nicht feststellbar. */
function asn_probe($sekunden = 15)
{
    list($da, $info) = asn_inspect_container(ASN_PROBE_NAME, $sekunden);
    if (!$da) {
        return array($da, null);
    }
    $k = asn_container_kurz($info);
    return $k['probe'] ? array(true, $k) : array(false, null);
}

/* ---------------- Merker "angelegt" NEBEN dem Datenordner ---------------- */

function asn_merk()
{
    $d = asn_json_lesen(asn_paths()['merk']);
    return array(
        'angelegt' => (isset($d['angelegt']) && $d['angelegt'] === 1) ? 1 : 0,
        'id'       => (isset($d['id']) && is_string($d['id']) && preg_match('/^[0-9a-f]{12,64}\z/', $d['id'])) ? $d['id'] : '',
        'def'      => (isset($d['def']) && is_string($d['def']) && preg_match('/^[0-9a-f]{16}\z/', $d['def'])) ? $d['def'] : '',
        'netz'     => (isset($d['netz']) && is_string($d['netz']) && asn_netzname_gueltig($d['netz'])) ? $d['netz'] : '',
        'netz_eigen' => (isset($d['netz_eigen']) && $d['netz_eigen'] === 1) ? 1 : 0,
        'zeit'     => (isset($d['zeit']) && is_int($d['zeit'])) ? $d['zeit'] : 0,
    );
}

function asn_merk_schreiben(array $m)
{
    $m['zeit'] = time();
    return asn_json_schreiben(asn_paths()['merk'], $m, 0600);
}

/* ==================================================================
 * Netz: Schnittstelle, eigene IP, MAC, macvlan-Netze (Bauart Alexa NG ax_hue_netz/ax_hue_ip_pruefen)
 * ================================================================== */

function asn_ipv4_zahl($s)
{
    if (!is_string($s) || !preg_match('/^(25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9])(\.(25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9])){3}\z/', $s)) {
        return null;
    }
    $t = explode('.', $s);
    return (((int) $t[0] * 256 + (int) $t[1]) * 256 + (int) $t[2]) * 256 + (int) $t[3];
}

function asn_ipv4_text($z)
{
    return implode('.', array(($z >> 24) & 255, ($z >> 16) & 255, ($z >> 8) & 255, $z & 255));
}

/** MAC in der Form aa:bb:cc:dd:ee:ff (klein oder gross). */
function asn_mac_gueltig($m)
{
    return is_string($m) && preg_match('/^[0-9a-fA-F]{2}(:[0-9a-fA-F]{2}){5}\z/', $m) === 1;
}

/** Beanstandung einer MAC: '' gut; MAC_FORM, MAC_MULTICAST, MAC_NULL. Nicht lokal verwaltet = Hinweis (asn_mac_lokal). */
function asn_mac_pruefen($m)
{
    if (!asn_mac_gueltig($m)) {
        return 'MAC_FORM';
    }
    $b = hexdec(substr($m, 0, 2));
    if ($b & 1) {
        return 'MAC_MULTICAST';
    }
    if (strtolower($m) === '00:00:00:00:00:00') {
        return 'MAC_NULL';
    }
    return '';
}

function asn_mac_lokal($m)
{
    return asn_mac_gueltig($m) && (hexdec(substr($m, 0, 2)) & 2) === 2;
}

/** Eine neue lokal verwaltete Unicast-MAC (zweites Bit gesetzt, erstes Bit frei). */
function asn_mac_erzeugen()
{
    $b = random_bytes(6);
    $o = array();
    for ($i = 0; $i < 6; $i++) {
        $o[] = sprintf('%02x', ord($b[$i]));
    }
    $o[0] = sprintf('%02x', (hexdec($o[0]) & 0xFC) | 0x02);
    return implode(':', $o);
}

/** Die Seriennummer, wie sie aus der MAC folgt (12 Hex, gross; sonn-Issue #334: Seriennummer aus der MAC). */
function asn_seriennummer($mac)
{
    return asn_mac_gueltig($mac) ? strtoupper(str_replace(':', '', $mac)) : '';
}

function asn_netzname_gueltig($n)
{
    return is_string($n) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.\-]{0,63}\z/', $n) === 1;
}

function asn_schnittstelle_gueltig($s)
{
    return is_string($s) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.\-]{0,14}\z/', $s) === 1;
}

/** Die Netzwerkschnittstellen fuer die Auswahl (ohne lo, Docker- und Tunnel-Schnittstellen). */
function asn_schnittstellen()
{
    $att = asn_netz_attrappe();
    if ($att !== null) {
        return array($att['schnittstelle']);
    }
    if (DIRECTORY_SEPARATOR === '\\') {
        return array();
    }
    list($rc, $out, ) = asn_aufruf(array('ip', '-o', 'link', 'show'), 5);
    $aus = array();
    if ($rc !== 0) {
        return $aus;
    }
    foreach (preg_split('/\r?\n/', $out) as $z) {
        if (!preg_match('/^\d+:\s+([A-Za-z0-9][A-Za-z0-9_.\-]{0,14})(@[^:\s]+)?:/', $z, $m)) {
            continue;
        }
        if ($m[1] === 'lo' || preg_match('/^(docker|veth|br-|virbr|tun|tap|wg|dummy)/', $m[1])) {
            continue;
        }
        $aus[] = $m[1];
    }
    return array_values(array_unique($aus));
}

/** Pruefstand: ASN_NETZ_ATTRAPPE = JSON {schnittstelle, ip, praefix, gateway, belegt: [..]} - sonst null. */
function asn_netz_attrappe()
{
    $j = (string) getenv('ASN_NETZ_ATTRAPPE');
    if ($j === '') {
        return null;
    }
    $d = json_decode($j, true);
    if (!is_array($d) || !isset($d['schnittstelle'], $d['ip'], $d['praefix'], $d['gateway'])) {
        return null;
    }
    $d['belegt'] = isset($d['belegt']) && is_array($d['belegt']) ? $d['belegt'] : array();
    return $d;
}

/**
 * Das IPv4-Netz einer Schnittstelle: array(ok, grund, netz) mit netz = array(schnittstelle, ip, praefix, netzadresse,
 * broadcast, gateway). Nur /16 bis /30 mit Router an DERSELBEN Schnittstelle gilt - sonst faellt der Schutz geschlossen aus.
 */
function asn_netz_lesen($schnittstelle)
{
    $leer = array('schnittstelle' => (string) $schnittstelle, 'ip' => '', 'praefix' => 0, 'netzadresse' => '', 'broadcast' => '', 'gateway' => '');
    if (!asn_schnittstelle_gueltig($schnittstelle)) {
        return array(false, 'SCHNITTSTELLE_FORM', $leer);
    }
    $att = asn_netz_attrappe();
    if ($att !== null) {
        if ($att['schnittstelle'] !== $schnittstelle) {
            return array(false, 'SCHNITTSTELLE_FEHLT|' . $schnittstelle, $leer);
        }
        $ip = (string) $att['ip'];
        $pf = (int) $att['praefix'];
        $gw = (string) $att['gateway'];
    } else {
        if (DIRECTORY_SEPARATOR === '\\') {
            return array(false, 'NETZ_LESEN', $leer);
        }
        list($rc, $out, ) = asn_aufruf(array('ip', '-4', '-o', 'addr', 'show', 'dev', $schnittstelle), 5);
        if ($rc === 127 || $rc === 124) {
            return array(false, 'NETZ_LESEN', $leer);
        }
        if ($rc !== 0) {
            return array(false, 'SCHNITTSTELLE_FEHLT|' . $schnittstelle, $leer);
        }
        if (!preg_match('#\binet ([0-9.]{7,15})/([0-9]{1,2})\b#', $out, $m) || asn_ipv4_zahl($m[1]) === null) {
            return array(false, 'SCHNITTSTELLE_OHNE_IP|' . $schnittstelle, $leer);
        }
        $ip = $m[1];
        $pf = (int) $m[2];
        $gw = '';
        list($rc2, $out2, ) = asn_aufruf(array('ip', '-4', 'route', 'show', 'default'), 5);
        if ($rc2 === 0) {
            foreach (preg_split('/\r?\n/', $out2) as $z) {
                if (preg_match('#^default via ([0-9.]{7,15}) dev (\S+)#', trim($z), $mm) && $mm[2] === $schnittstelle) {
                    $gw = $mm[1];
                    break;
                }
            }
        }
    }
    if ($pf < 16 || $pf > 30) {
        return array(false, 'NETZ_GROESSE|' . $pf, $leer);
    }
    $maske = (0xFFFFFFFF << (32 - $pf)) & 0xFFFFFFFF;
    $netz = asn_ipv4_zahl($ip) & $maske;
    $bc = $netz | (~$maske & 0xFFFFFFFF);
    if ($gw === '' || asn_ipv4_zahl($gw) === null || (asn_ipv4_zahl($gw) & $maske) !== $netz) {
        return array(false, 'GATEWAY_FEHLT|' . $schnittstelle, $leer);
    }
    return array(true, '', array('schnittstelle' => $schnittstelle, 'ip' => $ip, 'praefix' => $pf,
                                 'netzadresse' => asn_ipv4_text($netz), 'broadcast' => asn_ipv4_text($bc), 'gateway' => $gw));
}

/** Benutzt schon ein Geraet diese Adresse? Erst ping (1 s), dann der ARP-Eintrag. */
function asn_ip_belegt($ip, $schnittstelle)
{
    $att = asn_netz_attrappe();
    if ($att !== null) {
        return in_array($ip, $att['belegt'], true);
    }
    list($rc, , ) = asn_aufruf(array('ping', '-c', '1', '-W', '1', $ip), 4);
    if ($rc === 0) {
        return true;
    }
    list($rc2, $out2, ) = asn_aufruf(array('ip', 'neigh', 'show', $ip, 'dev', $schnittstelle), 3);
    return $rc2 === 0 && preg_match('/\blladdr\s+[0-9a-fA-F:]{17}\b.*\b(REACHABLE|STALE|DELAY|PROBE|PERMANENT)\b/', $out2) === 1;
}

/** Die eigene IP pruefen (beanstanden, nie aendern). '' = gut. $belegt: zusaetzlich, ob schon ein Geraet sie benutzt. */
function asn_ip_pruefen($ip, $schnittstelle, $belegt = true)
{
    if (!is_string($ip) || $ip === '') {
        return 'IP_PFLICHT';
    }
    $z = asn_ipv4_zahl($ip);
    if ($z === null) {
        return 'IP_FORM';
    }
    list($ok, $g, $n) = asn_netz_lesen($schnittstelle);
    if (!$ok) {
        return $g;
    }
    $maske = (0xFFFFFFFF << (32 - $n['praefix'])) & 0xFFFFFFFF;
    if (($z & $maske) !== asn_ipv4_zahl($n['netzadresse'])) {
        return 'IP_NETZ|' . $n['netzadresse'] . '/' . $n['praefix'] . '|' . $n['schnittstelle'];
    }
    if ($ip === $n['netzadresse']) {
        return 'IP_NETZADRESSE';
    }
    if ($ip === $n['broadcast']) {
        return 'IP_BROADCAST';
    }
    if ($ip === $n['ip']) {
        return 'IP_LOXBERRY';
    }
    if ($ip === $n['gateway']) {
        return 'IP_GATEWAY';
    }
    if ($belegt && asn_ip_belegt($ip, $n['schnittstelle'])) {
        return 'IP_BELEGT|' . $ip;
    }
    return '';
}

/**
 * Alle macvlan-Netze von Docker: Name => array(parent, subnetz, gateway, eigen, plugin (Label folder), container
 * (Namen)). Rueckgabe array(ok, netze).
 */
function asn_macvlan_netze()
{
    list($rc, $out, ) = asn_docker(array('network', 'ls', '--filter', 'driver=macvlan', '--format', '{{.Name}}'), 15);
    if ($rc !== 0) {
        return array(false, array());
    }
    $aus = array();
    foreach (preg_split('/\r?\n/', trim($out)) as $n) {
        $n = trim($n);
        if (!asn_netzname_gueltig($n)) {
            continue;
        }
        list($rc2, $o2, ) = asn_docker(array('network', 'inspect', $n), 15);
        $d = $rc2 === 0 ? asn_docker_json($o2) : null;
        if (!is_array($d) || !isset($d['Driver']) || $d['Driver'] !== 'macvlan') {
            continue;
        }
        $c0 = (isset($d['IPAM']['Config'][0]) && is_array($d['IPAM']['Config'][0])) ? $d['IPAM']['Config'][0] : array();
        $l = asn_labels($d);
        $cs = array();
        foreach ((isset($d['Containers']) && is_array($d['Containers'])) ? $d['Containers'] : array() as $c) {
            if (is_array($c) && isset($c['Name']) && is_string($c['Name'])) {
                $cs[] = $c['Name'];
            }
        }
        $aus[$n] = array(
            'parent'    => isset($d['Options']['parent']) && is_string($d['Options']['parent']) ? $d['Options']['parent'] : '',
            'subnetz'   => isset($c0['Subnet']) && is_string($c0['Subnet']) ? $c0['Subnet'] : '',
            'gateway'   => isset($c0['Gateway']) && is_string($c0['Gateway']) ? $c0['Gateway'] : '',
            'eigen'     => asn_eigen($d),
            'plugin'    => isset($l['de.loxberry.plugin.folder']) && is_string($l['de.loxberry.plugin.folder']) ? $l['de.loxberry.plugin.folder'] : '',
            'container' => $cs,
        );
    }
    return array(true, $aus);
}

/** Liegt die IP im Teilnetz "a.b.c.d/n"? */
function asn_ip_im_teilnetz($ip, $teilnetz)
{
    if (!is_string($teilnetz) || !preg_match('#^([0-9.]{7,15})/([0-9]{1,2})\z#', $teilnetz, $m)) {
        return false;
    }
    $z = asn_ipv4_zahl($ip);
    $n = asn_ipv4_zahl($m[1]);
    $pf = (int) $m[2];
    if ($z === null || $n === null || $pf < 8 || $pf > 30) {
        return false;
    }
    $maske = (0xFFFFFFFF << (32 - $pf)) & 0xFFFFFFFF;
    return ($z & $maske) === ($n & $maske);
}

/** Die macvlan-Netze, die fuer "mitnutzen" in Frage kommen: an der Schnittstelle, Teilnetz enthaelt die IP. */
function asn_netze_passend(array $netze, $schnittstelle, $ip)
{
    $aus = array();
    foreach ($netze as $n => $d) {
        if ($d['parent'] === $schnittstelle && ($ip === '' || asn_ip_im_teilnetz($ip, $d['subnetz']))) {
            $aus[$n] = $d;
        }
    }
    return $aus;
}

/* ==================================================================
 * Musikordner (Speicherauswahl von LoxBerry; Bauart Chromecast4lox 1.3.25 Nachfassung 4)
 * ================================================================== */

/** Wo ein Musikordner liegen darf: LoxBerry-Speicher, /media, /mnt und der eigene Ordner <ordner>.sonn/musik. */
function asn_musik_wurzeln()
{
    $orte = array('/media', '/mnt');
    $p = asn_paths();
    if ($p['home'] !== '') {
        array_unshift($orte, $p['home'] . '/system/storage');
    }
    $orte[] = $p['sonn'] . '/musik';
    return $orte;
}

/** Den Musikordner pruefen - Form (immer) und Lage ($lage: Ordner vorhanden). '' = gut, sonst Grund. */
function asn_musik_pruefen($s, $lage)
{
    $s = trim((string) $s);
    if ($s === '') {
        return '';
    }
    $n = str_replace('\\', '/', $s);
    if (!($n[0] === '/' || preg_match('#^[A-Za-z]:/#', $n)) || preg_match('/[\x00-\x1F\x7F,"\'`]/', $n)
        || strpos($n . '/', '/../') !== false || strlen($n) > 400) {
        return 'MUSIK_FORM';
    }
    $echt = $n;
    if ($lage) {
        @scandir($s);     // eine Netzfreigabe haengt autofs erst bei Zugriff ein
        $r = @realpath($s);
        if ($r === false || !@is_dir($r)) {
            return 'MUSIK_FEHLT';
        }
        $echt = str_replace('\\', '/', $r);
    }
    foreach (asn_musik_wurzeln() as $w) {
        $wr = $lage ? @realpath($w) : $w;
        if ($wr === false || $wr === '') {
            continue;
        }
        $wr = rtrim(str_replace('\\', '/', $wr), '/');
        if ((strpos($echt, $wr . '/') === 0 && strlen($echt) > strlen($wr) + 1) || $echt === $wr) {
            return '';
        }
    }
    return 'MUSIK_ORT';
}

/** Speicherauswahl von LoxBerry (libs/phplib/loxberry_storage.php, get_storage_html()) - oder ''. */
function asn_musik_wahl_html($pfad)
{
    $home = (string) asn_paths()['home'];
    $lib = $home !== '' ? $home . '/libs/phplib/loxberry_storage.php' : '';
    if ($lib === '' || !is_file($lib)) {
        return '';
    }
    require_once $lib;
    if (!function_exists('get_storage_html')) {
        return '';
    }
    $alt = ini_get('default_socket_timeout');
    ini_set('default_socket_timeout', '5');
    $html = @get_storage_html(array(
        'formid' => 'asn_musik_wahl',
        'currentpath' => asn_musik_wahl_tauglich($pfad) ? (string) $pfad : '',
        'label' => asn_t('EINST.L_MUSIK_WAHL'),
        'type_usb' => 1, 'type_net' => 1, 'type_custom' => 1, 'custom_folder' => 1, 'show_browse' => 1));
    ini_set('default_socket_timeout', (string) $alt);
    return (is_string($html) && strpos($html, 'id="asn_musik_wahl"') !== false) ? $html : '';
}

/** Das Widget setzt currentpath UNGEPRUEFT ein - also nur UTF-8 ohne " \ < > & ` und Steuerzeichen. */
function asn_musik_wahl_tauglich($p)
{
    $p = (string) $p;
    return $p === '' || (preg_match('//u', $p) === 1 && !preg_match('/["\\\\<>&`\x00-\x1F\x7F]/', $p));
}

/* ==================================================================
 * Sollwerte des Containers
 * ================================================================== */

/** Den Datenordner (Unterordner von <ordner>.sonn) aus einem Namen - nur daten_<12 Hex>. */
function asn_datenordner_gueltig($n)
{
    return is_string($n) && preg_match('/^daten_[0-9a-f]{12}\z/', $n) === 1;
}

function asn_datenordner_pfad($n)
{
    return asn_paths()['sonn'] . '/' . $n;
}

/**
 * Die Einstellungen des Containers pruefen (Formular und Sicherung). Rueckgabe Liste der Gruende (leer = gut).
 * $lage: auch Netz, Belegung und Musikordner am Geraet pruefen.
 */
function asn_container_pruefen(array $cfg, $lage)
{
    $g = array();
    if (!asn_schnittstelle_gueltig($cfg['schnittstelle'])) {
        $g[] = 'SCHNITTSTELLE_FORM';
    }
    if (!in_array($cfg['netz_art'], array('mitnutzen', 'eigen'), true)) {
        $g[] = 'NETZ_ART';
    }
    if ($cfg['netz_art'] === 'mitnutzen' && !asn_netzname_gueltig($cfg['netz_name'])) {
        $g[] = 'NETZ_NAME';
    }
    if ($cfg['netz_art'] === 'mitnutzen' && $cfg['netz_name'] === asn_namen()['netz']) {
        $g[] = 'NETZ_NAME_EIGEN';
    }
    if ((string) $cfg['ip'] === '') {
        $g[] = 'IP_PFLICHT';
    } elseif (asn_ipv4_zahl($cfg['ip']) === null) {
        $g[] = 'IP_FORM';
    } elseif ($lage) {
        $gi = asn_ip_pruefen($cfg['ip'], $cfg['schnittstelle'], false);
        if ($gi !== '') {
            $g[] = $gi;
        }
    }
    $gm = asn_mac_pruefen($cfg['mac']);
    if ($gm !== '') {
        $g[] = $gm;
    }
    if (!is_string($cfg['hostname']) || !preg_match('/^[a-z0-9]([a-z0-9\-]{0,30}[a-z0-9])?\z/', $cfg['hostname'])) {
        $g[] = 'HOSTNAME_FORM';
    }
    if (!is_int($cfg['speicher_mb']) || $cfg['speicher_mb'] < 256 || $cfg['speicher_mb'] > 8192) {
        $g[] = 'SPEICHER_BEREICH|256|8192';
    }
    if (!is_int($cfg['cpus']) || $cfg['cpus'] < 1 || $cfg['cpus'] > 8) {
        $g[] = 'CPUS_BEREICH|1|8';
    }
    $gmu = asn_musik_pruefen($cfg['musik_pfad'], $lage);
    if ($gmu !== '') {
        $g[] = $gmu;
    }
    if (asn_abbild_ref(asn_abbild_digest($cfg)) === '') {
        $g[] = 'ABBILD_VORGABE';
    }
    return $g;
}

/**
 * Was angelegt sein soll. Rueckgabe array(ok, grund, def). def['hash'] steht als Label am Container; weicht er ab,
 * legt der Vorgang neu an. Der Datenordner wird hier NICHT angelegt.
 */
function asn_soll(array $cfg)
{
    $g = asn_container_pruefen($cfg, false);
    if ($g) {
        return array(false, $g[0], null);
    }
    $nm = asn_namen();
    $digest = asn_abbild_digest($cfg);
    $daten = asn_datenordner_gueltig($cfg['datenordner']) ? $cfg['datenordner'] : 'daten_' . asn_kurzdigest($digest);
    $def = array(
        'container' => $nm['container'],
        'netz'      => $cfg['netz_art'] === 'eigen' ? $nm['netz'] : $cfg['netz_name'],
        'netz_eigen' => $cfg['netz_art'] === 'eigen' ? 1 : 0,
        'schnittstelle' => $cfg['schnittstelle'],
        'ip'        => $cfg['ip'],
        'mac'       => strtolower($cfg['mac']),
        'hostname'  => $cfg['hostname'],
        'speicher'  => (int) $cfg['speicher_mb'],
        'cpus'      => (int) $cfg['cpus'],
        'bild'      => asn_abbild_ref($digest),
        'digest'    => $digest,
        'datenname' => $daten,
        'daten'     => asn_datenordner_pfad($daten),
        'musik'     => trim((string) $cfg['musik_pfad']),
        // 0.9.2 Nachzug: Teil des Soll-Hashes - ein Container mit dem alten Einhaengepunkt /musik weicht damit ab und
        // wird mit "Container anlegen oder angleichen" neu angelegt (Datenordner bleibt, keine Kopie).
        'musik_ziel' => ASN_MUSIK_ZIEL,
    );
    $def['hash'] = substr(sha1((string) json_encode($def)), 0, 16);
    return array(true, '', $def);
}

/** Die docker-create-Argumente (eine Stelle; der Selbsttest prueft sie: nur ein Netz, Musik nur lesend). */
function asn_create_args(array $def)
{
    $p = asn_paths();
    $a = array('create', '--name', $def['container'],
        '--label', 'de.loxberry.plugin.folder=' . $p['plugin'], '--label', 'de.loxberry.plugin.name=' . asn_namen()['label'],
        '--label', 'de.loxberry.plugin.def=' . $def['hash'],
        '--restart', 'unless-stopped',
        '--network', $def['netz'], '--ip', $def['ip'], '--mac-address', $def['mac'],
        '--hostname', $def['hostname'],
        '--memory', $def['speicher'] . 'm', '--cpus', (string) $def['cpus'],
        '--log-opt', 'max-size=5m', '--log-opt', 'max-file=2',
        '-v', $def['daten'] . ':/app/data');
    if ($def['musik'] !== '') {
        // 0.9.2 Nachzug: verschachtelt UNTER dem Datenordner (/app/data ist selbst ein Bind) - Docker haengt nach der
        // Tiefe des Ziels ein (erst /app/data, dann dieser), und den leeren Einhaengepunkt legt es im Datenordner auf dem
        // LoxBerry an. Der Bind lebt nur im Namensraum des Containers (/app/data rprivate, Musik rslave = nur vom
        // LoxBerry herein): auf dem LoxBerry und in jedem Hilfscontainer der Datenkopie ist der Ordner leer.
        $a[] = '--mount';
        $a[] = 'type=bind,source=' . $def['musik'] . ',target=' . ASN_MUSIK_ZIEL . ',readonly,bind-propagation=rslave';
    }
    $a[] = $def['bild'];
    return $a;
}

/** Der Einhaengepunkt des Musikordners im Datenordner AUF DEM LOXBERRY (dort immer leer, s. asn_create_args()). */
function asn_musik_punkt($daten)
{
    return rtrim((string) $daten, '/') . '/music/local/' . ASN_MUSIK_NAME;
}

/**
 * Der Einhaengepunkt im Datenordner muss fehlen oder ein LEERER, echter Ordner auf demselben Dateisystem wie
 * music/local sein. Sonst ('' = gut): MUSIK_PUNKT_BELEGT|name (dort liegen Dateien - etwa ein Upload in sonn, den der
 * Musikordner verdecken wuerde, oder ein Rest), MUSIK_PUNKT_FREMD|name (Verweis, Datei oder ein auf dem LoxBerry
 * eingehaengtes Dateisystem). Gilt vor dem Anlegen und vor/nach jeder Datenkopie: so kopiert keine Kopie je Musik mit.
 */
function asn_musik_punkt_pruefen($daten)
{
    $pkt = asn_musik_punkt($daten);
    if (!@file_exists($pkt) && !@is_link($pkt)) {
        return '';
    }
    if (@is_link($pkt) || !@is_dir($pkt)) {
        return 'MUSIK_PUNKT_FREMD|' . ASN_MUSIK_NAME;
    }
    $s1 = @stat($pkt);
    $s2 = @stat(dirname($pkt));
    if (!is_array($s1) || !is_array($s2) || $s1['dev'] !== $s2['dev']) {
        return 'MUSIK_PUNKT_FREMD|' . ASN_MUSIK_NAME;
    }
    $inhalt = @scandir($pkt);
    if (!is_array($inhalt)) {
        return 'MUSIK_PUNKT_FREMD|' . ASN_MUSIK_NAME;
    }
    if (count(array_diff($inhalt, array('.', '..'))) > 0) {
        return 'MUSIK_PUNKT_BELEGT|' . ASN_MUSIK_NAME;
    }
    return '';
}

/** Der Musik-Bind eines Containers aus asn_container()['mounts']: zuerst der neue Ort, sonst der alte /musik. null = keiner. */
function asn_musik_mount(array $mounts)
{
    if (isset($mounts[ASN_MUSIK_ZIEL])) {
        return $mounts[ASN_MUSIK_ZIEL] + array('ziel' => ASN_MUSIK_ZIEL);
    }
    if (isset($mounts[ASN_MUSIK_ALT])) {
        return $mounts[ASN_MUSIK_ALT] + array('ziel' => ASN_MUSIK_ALT);
    }
    return null;
}

/* ==================================================================
 * Abfrage von sonn per docker exec (bin/asn_abfrage.js im Container)
 * ================================================================== */

/**
 * sonn im Container fragen. $auftrag 'stand' (oeffentliche API, Werte aus config.json ohne Geheimnisse) oder 'rescan'
 * (Anmeldung mit dem sonn-Konto, nur ueber stdin). Rueckgabe array(ok, grund, daten).
 */
function asn_sonn_fragen($container, $auftrag, array $cfg = array())
{
    $p = asn_paths();
    $skript = is_file($p['abfrage']) ? (string) @file_get_contents($p['abfrage']) : '';
    if ($skript === '') {
        $k = dirname(dirname(__DIR__)) . '/bin/asn_abfrage.js';
        $skript = is_file($k) ? (string) @file_get_contents($k) : '';
    }
    if ($skript === '') {
        return array(false, 'ABFRAGE_FEHLT', null);
    }
    $ein = array('auftrag' => $auftrag, 'ordner' => ASN_MUSIK_NAME);   // 0.9.2 Nachzug: Ordner in der Lokalen Bibliothek
    if ($auftrag === 'rescan') {
        if ((string) $cfg['sonn_benutzer'] === '' || (string) $cfg['sonn_kennwort'] === '') {
            return array(false, 'KONTO_FEHLT', null);
        }
        $ein['benutzer'] = (string) $cfg['sonn_benutzer'];
        $ein['kennwort'] = (string) $cfg['sonn_kennwort'];
    }
    $frist = ASN_ABFRAGE_S;
    if ($auftrag === 'favoriten') {
        list($liste, $f1) = asn_favoriten_lesen(isset($cfg['favoriten']) ? $cfg['favoriten'] : '');
        list($zonen, $f2) = asn_favoriten_zonen_lesen(isset($cfg['favoriten_zonen']) ? $cfg['favoriten_zonen'] : '');
        if ($f1 || $f2) {
            return array(false, 'FAV_EINGABE', null);
        }
        if (!$liste) {
            return array(false, 'FAV_LEER', null);
        }
        $ein['zonen'] = $zonen;
        // 0.9.3: das sonn-Konto fuer die Custom Streams (nur ueber die Standardeingabe, wie bei rescan); ohne Konto bleibt das Feld leer.
        if (isset($cfg['sonn_benutzer'], $cfg['sonn_kennwort']) && (string) $cfg['sonn_benutzer'] !== '' && (string) $cfg['sonn_kennwort'] !== '') {
            $ein['benutzer'] = (string) $cfg['sonn_benutzer'];
            $ein['kennwort'] = (string) $cfg['sonn_kennwort'];
        }
        $ein['eintraege'] = array();
        foreach ($liste as $e) {
            $ein['eintraege'][] = array('nr' => $e['nr'], 'art' => $e['art'], 'name' => $e['name'], 'quelle' => $e['quelle']);
        }
        $frist = ASN_FAVORITEN_S;
    }
    if ($auftrag === 'tunein') {
        $ein['ziel'] = isset($cfg['ziel']) ? (string) $cfg['ziel'] : 'lesen';
    }
    // Fremdpruefung 31.10.2026, T3-4: laesst sich die Eingabe nicht als JSON schreiben (ungueltiges UTF-8 im Kennwort),
    // geht gar nichts in den Container - bisher kam dort "" an, und die Abfrage lief als "stand" mit ok:true.
    $ein_json = json_encode($ein);
    if (!is_string($ein_json)) {
        return array(false, 'SONN_FEHLER', array('text' => 'Eingabe nicht als JSON darstellbar'));
    }
    list($rc, $out, $err) = asn_docker(array('exec', '-i', $container, 'node', '-e', $skript), $frist, $ein_json);
    if ($rc === 124) {
        return array(false, 'ABFRAGE_ZEIT|' . $frist, null);
    }
    $z = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) $out)), 'strlen'));
    $d = $z ? json_decode($z[count($z) - 1], true) : null;
    if (!is_array($d) || !isset($d['ok'])) {
        return array(false, 'ABFRAGE_ANTWORT|' . (int) $rc, array('text' => asn_kurztext(asn_letzte_zeile($err . "\n" . $out), 160)));
    }
    if ($auftrag === 'rescan' && !empty($d['ok']) && !isset($d['code'])) {
        // T3-4: ein "ok" ohne HTTP-Code ist keine Antwort auf das Einlesen (sondern ein Stand) - nicht als gelungen werten.
        return array(false, 'SONN_FEHLER', array('text' => 'Antwort passt nicht zum Auftrag'));
    }
    if ($auftrag === 'favoriten' && empty($d['favoriten'])) {
        // 0.9.2: eine Antwort ohne das Merkmal "favoriten" stammt nicht aus dem Favoriten-Zweig (alte Abfrage).
        return array(false, 'SONN_FEHLER', array('text' => 'Antwort passt nicht zum Auftrag'));
    }
    if ($auftrag === 'tunein' && empty($d['tunein_auftrag'])) {
        return array(false, 'SONN_FEHLER', array('text' => 'Antwort passt nicht zum Auftrag'));
    }
    return array(!empty($d['ok']), isset($d['grund']) && is_string($d['grund']) ? $d['grund'] : '', $d);
}

/** Zustandszahl einer Zone: 2 spielt, 1 Pause, 0 aus/gestoppt, -1 unbekannt. */
function asn_zustand_zahl($s)
{
    $s = strtolower(trim((string) $s));
    if (in_array($s, array('play', 'playing'), true)) {
        return 2;
    }
    if (in_array($s, array('pause', 'paused'), true)) {
        return 1;
    }
    if (in_array($s, array('stop', 'stopped', 'off', 'idle'), true)) {
        return 0;
    }
    return -1;
}

/**
 * Die Zonen aus der Antwort normieren: nr => array(name, zustand, lautstaerke, titel, interpret, quelle, ausgang,
 * verbunden, fehler). verbunden: 1/0 aus output.device.connected (sonn meldet es nur fuer Squeezelite), 0 bei
 * output = null (kein Ausgang eingerichtet), -1 ohne Aussage (Google Cast, aeltere Antwort ohne output). Nachfassung 3:
 * -1 statt '-' - ein Virtueller Eingang liest '-' als 0 und meldete faelschlich "nicht verbunden".
 */
function asn_zonen_normieren($liste)
{
    $aus = array();
    foreach (is_array($liste) ? $liste : array() as $z) {
        if (!is_array($z) || !isset($z['id']) || !is_numeric($z['id'])) {
            continue;
        }
        $nr = (int) $z['id'];
        if ($nr < 1 || $nr > ASN_ZONEN_MAX) {
            continue;
        }
        $txt = function ($v) {
            $v = is_scalar($v) ? asn_kurztext((string) $v, 120) : '';
            return $v === '' ? '-' : str_replace(array('"', ';'), array("'", ','), $v);
        };
        $vol = isset($z['volume']) && is_numeric($z['volume']) ? (int) round((float) $z['volume']) : -1;
        $grenze = function ($k) use ($z) {
            $w = isset($z['volumeLimits'][$k]) && is_numeric($z['volumeLimits'][$k]) ? (int) round((float) $z['volumeLimits'][$k]) : -1;
            return ($w >= 0 && $w <= 100) ? $w : -1;
        };
        $aus[$nr] = array(
            'name'        => $txt(isset($z['name']) ? $z['name'] : ''),
            'zustand'     => asn_zustand_zahl(isset($z['state']) ? $z['state'] : ''),
            'lautstaerke' => ($vol >= 0 && $vol <= 100) ? $vol : -1,
            'titel'       => $txt(isset($z['track']['title']) ? $z['track']['title'] : ''),
            'interpret'   => $txt(isset($z['track']['artist']) ? $z['track']['artist'] : ''),
            'quelle'      => $txt(isset($z['source']['name']) ? $z['source']['name'] : ''),
            'ausgang'     => $txt(isset($z['output']['device']['name']) ? $z['output']['device']['name'] : ''),
            'verbunden'   => !array_key_exists('output', $z) ? -1 : ($z['output'] === null ? 0
                : ((isset($z['output']['device']['connected']) && is_bool($z['output']['device']['connected']))
                    ? ($z['output']['device']['connected'] ? 1 : 0) : -1)),
            'fehler'      => !empty($z['error']) ? 1 : 0,
            // 0.9.3: nur fuer den Reiter Test, geht nicht ueber MQTT (asn_mqtt_paare nennt seine Felder einzeln).
            'start'       => $grenze('default'),
            'hoechst'     => $grenze('max'),
        );
    }
    ksort($aus);
    return $aus;
}

/* ==================================================================
 * Zustand (data/plugins/<ordner>/zustand.json, vom Takt geschrieben)
 * ================================================================== */

function asn_zustand()
{
    $d = asn_json_lesen(asn_paths()['zustand']);
    return array(
        'ts'       => isset($d['ts']) && is_int($d['ts']) ? $d['ts'] : 0,
        'zaehler'  => isset($d['zaehler']) && is_int($d['zaehler']) ? $d['zaehler'] : 0,
        'aktiv'    => !empty($d['aktiv']),
        'docker'   => isset($d['docker']) && is_string($d['docker']) ? $d['docker'] : '',
        'container'=> isset($d['container']) && is_array($d['container']) ? $d['container'] : null,
        'sonn'     => isset($d['sonn']) && is_array($d['sonn']) ? $d['sonn'] : null,
        'grund'    => isset($d['grund']) && is_string($d['grund']) ? $d['grund'] : '',
        'zonen'    => isset($d['zonen']) && is_array($d['zonen']) ? $d['zonen'] : array(),
        'ok'       => !empty($d['ok']),
    );
}

function asn_takt_alter()
{
    $z = asn_zustand();
    return $z['ts'] > 0 ? max(0, time() - $z['ts']) : -1;
}

/* ==================================================================
 * MQTT-Gateway V2 (Werte ueber den UDP-Eingang; Bauart Xiaomi Licht xl_mqtt_*)
 * ================================================================== */

/**
 * Die Themenliste - EINE Stelle fuer Takt, Namenstabelle, Hakenliste, Reiter MQTT und Pruefzeile. t: Thema (zoneN/ = je
 * Zone), r: retained, v2: Haken, nc: Noncached, b: Bereich, f: Fehlwert.
 */
function asn_mqtt_themen()
{
    return array(
        array('t' => 'status/zaehler',   'k' => 'MQTT.T_ZAEHLER',   'b' => "0\u{2026}999",   'f' => '',   'r' => false, 'v2' => true,  'nc' => true),
        array('t' => 'status/ok',        'k' => 'MQTT.T_OK',        'b' => '0/1',            'f' => '',   'r' => false, 'v2' => true,  'nc' => true),
        array('t' => 'status/ts',        'k' => 'MQTT.T_TS',        'b' => 'Unix s',         'f' => '',   'r' => false, 'v2' => false, 'nc' => false),
        array('t' => 'server/online',    'k' => 'MQTT.T_ONLINE',    'b' => '0/1',            'f' => '',   'r' => false, 'v2' => true,  'nc' => true),
        array('t' => 'server/gesund',    'k' => 'MQTT.T_GESUND',    'b' => '0/1/2',          'f' => '-1', 'r' => false, 'v2' => true,  'nc' => false),
        array('t' => 'server/gekoppelt', 'k' => 'MQTT.T_GEKOPPELT', 'b' => '0/1',            'f' => '-1', 'r' => false, 'v2' => true,  'nc' => false),
        array('t' => 'server/zonen',     'k' => 'MQTT.T_ZONEN',     'b' => "0\u{2026}24",    'f' => '-1', 'r' => false, 'v2' => true,  'nc' => false),
        array('t' => 'server/fassung',   'k' => 'MQTT.T_FASSUNG',   'b' => 'Text',           'f' => '-',  'r' => false, 'v2' => true,  'nc' => false),
        array('t' => 'zoneN/zustand',    'k' => 'MQTT.T_ZUSTAND',   'b' => '0/1/2',          'f' => '-1', 'r' => true,  'v2' => true,  'nc' => false),
        array('t' => 'zoneN/lautstaerke','k' => 'MQTT.T_LAUT',      'b' => "0\u{2026}100",   'f' => '-1', 'r' => true,  'v2' => true,  'nc' => false),
        array('t' => 'zoneN/titel',      'k' => 'MQTT.T_TITEL',     'b' => 'Text',           'f' => '-',  'r' => true,  'v2' => true,  'nc' => false),
        array('t' => 'zoneN/interpret',  'k' => 'MQTT.T_INTERPRET', 'b' => 'Text',           'f' => '-',  'r' => true,  'v2' => true,  'nc' => false),
        array('t' => 'zoneN/quelle',     'k' => 'MQTT.T_QUELLE',    'b' => 'Text',           'f' => '-',  'r' => true,  'v2' => true,  'nc' => false),
        array('t' => 'zoneN/name',       'k' => 'MQTT.T_NAME',      'b' => 'Text',           'f' => '-',  'r' => true,  'v2' => true,  'nc' => false),
        array('t' => 'zoneN/ausgang',    'k' => 'MQTT.T_AUSGANG',   'b' => 'Text',           'f' => '-',  'r' => true,  'v2' => true,  'nc' => false),
        array('t' => 'zoneN/verbunden',  'k' => 'ZONEN.T_VERBUNDEN', 'b' => '-1/0/1',        'f' => '-1', 'r' => true,  'v2' => true,  'nc' => false),
        array('t' => 'status/befehle',   'k' => 'MQTT.T_BEFEHLE',   'b' => "-1\u{2026}1",    'f' => '',   'r' => false, 'v2' => true,  'nc' => false),
    );
}

function asn_gateway_name($thema)
{
    return str_replace(array('/', '%', ' '), '_', ASN_PRAEFIX . '/' . (string) $thema);
}

function asn_mqtt_retain($thema)
{
    $basis = preg_replace('#^zone([0-9]+|N)/#', 'zoneN/', (string) $thema);
    foreach (asn_mqtt_themen() as $z) {
        if ($z['t'] === $basis) {
            return $z['r'];
        }
    }
    return false;
}

/**
 * Die Paare einer Runde - EINE Stelle fuer Takt und Pruefzeile. $lage: online (0/1), gesund (-1..2), gekoppelt
 * (-1/0/1), fassung (Text), zonen (normiert). Ohne Antwort von sonn: Zonen behalten ihre Nummern mit Fehlwerten.
 */
function asn_mqtt_paare(array $lage, array $kopf, $jetzt)
{
    $paare = array(
        'status/zaehler'   => (int) $kopf['zaehler'],
        'status/ok'        => (int) $kopf['ok'],
        'status/ts'        => (int) $jetzt,
        'server/online'    => !empty($lage['online']) ? 1 : 0,
        'server/gesund'    => isset($lage['gesund']) ? (int) $lage['gesund'] : -1,
        'server/gekoppelt' => isset($lage['gekoppelt']) ? (int) $lage['gekoppelt'] : -1,
        'server/zonen'     => isset($lage['zonen']) && is_array($lage['zonen']) && !empty($lage['online']) ? count($lage['zonen']) : -1,
        'server/fassung'   => isset($lage['fassung']) && $lage['fassung'] !== '' ? (string) $lage['fassung'] : '-',
    );
    $felder = array('zustand', 'lautstaerke', 'titel', 'interpret', 'quelle', 'name', 'ausgang', 'verbunden');
    foreach ((isset($lage['zonen']) && is_array($lage['zonen'])) ? $lage['zonen'] : array() as $nr => $w) {
        foreach ($felder as $f) {
            $leer = in_array($f, array('zustand', 'lautstaerke', 'verbunden'), true) ? -1 : '-';
            $paare['zone' . (int) $nr . '/' . $f] = (!empty($lage['online']) && isset($w[$f])) ? $w[$f] : $leer;
            if ($f === 'verbunden' && !in_array((string) $paare['zone' . (int) $nr . '/' . $f], array('1', '0'), true)) {
                $paare['zone' . (int) $nr . '/' . $f] = -1;    // auch ein alter Zustand mit '-' geht als -1 hinaus
            }
        }
    }
    return $paare;
}

function asn_mqtt_zustand()
{
    $p = asn_paths();
    $leer = array('gefunden' => 0, 'autostart' => 0, 'fassung' => 0, 'udpport' => 0);
    if ($p['home'] === '') {
        return $leer;
    }
    $gen = asn_json_lesen($p['home'] . '/config/system/general.json');
    $m = array();
    if (isset($gen['Mqtt']) && is_array($gen['Mqtt'])) {
        $m = $gen['Mqtt'];
    } elseif (isset($gen['mqtt']) && is_array($gen['mqtt'])) {
        $m = $gen['mqtt'];
    }
    if (!$m) {
        return $leer;
    }
    $hol = function ($gross, $klein) use ($m) {
        return isset($m[$gross]) ? $m[$gross] : (isset($m[$klein]) ? $m[$klein] : '');
    };
    return array(
        'gefunden'  => 1,
        'autostart' => in_array((string) $hol('Gatewayautostart', 'gatewayautostart'), array('1', 'true'), true) ? 1 : 0,
        'fassung'   => (int) $hol('Gatewayversion', 'gatewayversion'),
        'udpport'   => (int) $hol('Udpinport', 'udpinport'),
    );
}

/**
 * Paare ueber den UDP-Eingang des Gateways senden - "retain" fuer Zonenzustaende, "publish" fuer Dienstaussagen; ab dem
 * zweiten Datagramm 5 ms Pause. $leeren: Themen mit leerer Nutzlast (Abraeumen). Rueckgabe Zahl oder -1.
 */
function asn_mqtt_senden(array $paare, array $leeren = array(), $port = null)
{
    if ($port === null) {
        $port = (int) asn_mqtt_zustand()['udpport'];
    }
    if ($port <= 0) {
        asn_log_gebremst('mqtt_kein_port', 'MQTT: kein UDP-Eingangsport in der general.json gefunden - nichts gesendet.');
        return -1;
    }
    $en = 0;
    $es = '';
    $s = @stream_socket_client('udp://127.0.0.1:' . (int) $port, $en, $es, 2);
    if ($s === false) {
        asn_log_gebremst('mqtt_socket', 'MQTT: der UDP-Eingang des Gateways liess sich nicht oeffnen.');
        return -1;
    }
    $n = 0;
    $raus = function ($msg) use ($s, &$n) {
        if ($n > 0) {
            usleep(5000);
        }
        @fwrite($s, $msg);
        $n++;
    };
    foreach ($leeren as $t) {
        $raus('retain ' . ASN_PRAEFIX . '/' . $t . ' ');
    }
    foreach ($paare as $t => $v) {
        if ($v === null || $v === '') {
            continue;
        }
        $raus((asn_mqtt_retain($t) ? 'retain ' : 'publish ') . ASN_PRAEFIX . '/' . $t . ' ' . (string) $v);
    }
    fclose($s);
    return $n;
}

/** Die Namenstabelle (Reiter Einbindung): Dienstthemen einmal, je Zone die Zonenthemen. */
function asn_mqtt_namen(array $nummern)
{
    $aus = array();
    foreach (asn_mqtt_themen() as $z) {
        if (strpos($z['t'], 'zoneN/') === 0) {
            continue;
        }
        $aus[] = array('thema' => $z['t'], 'name' => asn_gateway_name($z['t']), 'bedeutung' => asn_t($z['k']), 'bereich' => $z['b'],
                       'fehlwert' => $z['f'], 'retain' => $z['r'], 'v2' => $z['v2'], 'noncached' => $z['nc']);
    }
    foreach ($nummern as $nr) {
        foreach (asn_mqtt_themen() as $z) {
            if (strpos($z['t'], 'zoneN/') !== 0) {
                continue;
            }
            $t = 'zone' . (int) $nr . '/' . substr($z['t'], 6);
            $aus[] = array('thema' => $t, 'name' => asn_gateway_name($t), 'bedeutung' => asn_t($z['k']), 'bereich' => $z['b'],
                           'fehlwert' => $z['f'], 'retain' => $z['r'], 'v2' => $z['v2'], 'noncached' => $z['nc']);
        }
    }
    return $aus;
}

/** Die Zonennummern fuer Tabellen: die zuletzt gemeldeten, sonst Zone 1 als Muster. */
function asn_zonen_nummern()
{
    $z = array_keys(asn_zustand()['zonen']);
    $z = array_values(array_filter(array_map('intval', $z), function ($n) {
        return $n >= 1 && $n <= ASN_ZONEN_MAX;
    }));
    sort($z);
    return $z ? $z : array(1);
}

/** Die V2-Hakenliste: array(thema, name, bedeutung, noncached). */
function asn_mqtt_hakenliste($nummern = null)
{
    $aus = array();
    foreach (asn_mqtt_namen(is_array($nummern) ? $nummern : asn_zonen_nummern()) as $z) {
        if ($z['v2']) {
            $aus[] = array(ASN_PRAEFIX . '/' . $z['thema'], $z['name'], $z['bedeutung'], $z['noncached']);
        }
    }
    return $aus;
}

/** Stehen unter Gateway V2 die Haken? array(1|0|2, gesetzt, soll, fehlende Noncached, art). */
function asn_mqtt_v2_haken_lage()
{
    $m = asn_mqtt_zustand();
    $p = asn_paths();
    $soll = asn_mqtt_hakenliste();
    if ((int) $m['fassung'] === 1) {
        return array(2, 0, count($soll), array(), 'v1');
    }
    $sf = $p['home'] !== '' ? $p['home'] . '/config/system/subscriptions.json' : '';
    $d = ($sf !== '' && is_file($sf)) ? json_decode((string) @file_get_contents($sf), true) : null;
    if (!is_array($d)) {
        return array(2, 0, count($soll), array(), 'unklar');
    }
    $liste = array();
    foreach (array('Subscriptions', 'subscriptions') as $s) {
        if (isset($d[$s]) && is_array($d[$s])) {
            $liste = $d[$s];
            break;
        }
    }
    $abo = array();
    foreach ($liste as $e) {
        if (!is_array($e)) {
            continue;
        }
        $id = '';
        foreach (array('Id', 'id', 'Topic', 'topic') as $k) {
            if (isset($e[$k]) && is_string($e[$k])) {
                $id = $e[$k];
                break;
            }
        }
        if ($id === '') {
            continue;
        }
        $nc = false;
        foreach (array('Noncached', 'noncached') as $k) {
            if (isset($e[$k])) {
                $nc = ($e[$k] === true || in_array(strtolower(trim((string) $e[$k])), array('1', 'true', 'yes', 'on'), true));
            }
        }
        $abo[$id] = $nc;
    }
    $gesetzt = 0;
    $nc_fehlt = array();
    foreach ($soll as $z) {
        if (array_key_exists($z[0], $abo)) {
            $gesetzt++;
            if ($z[3] && !$abo[$z[0]]) {
                $nc_fehlt[] = $z[0];
            }
        } elseif ($z[3]) {
            $nc_fehlt[] = $z[0];
        }
    }
    return array(($gesetzt === count($soll) && !$nc_fehlt) ? 1 : 0, $gesetzt, count($soll), $nc_fehlt, 'v2');
}

/** Pruefzeile "Themenliste gegen Sendecode": Namen der Tabelle = Namen, unter denen gesendet wird (+ status/befehle). */
function asn_mqtt_namen_probe()
{
    $lage = array('online' => 1, 'gesund' => 2, 'gekoppelt' => 1, 'fassung' => 'x',
                  'zonen' => asn_zonen_normieren(array(array('id' => 1, 'name' => 'a', 'state' => 'playing', 'volume' => 20))));
    $gesendet = array_keys(asn_mqtt_paare($lage, array('zaehler' => 0, 'ok' => 1), time()));
    $gesendet[] = 'status/befehle';
    $soll = array_map('asn_gateway_name', $gesendet);
    $ist = array();
    foreach (asn_mqtt_namen(array(1)) as $z) {
        $ist[] = $z['name'];
    }
    $a = array_values(array_diff($ist, $soll));
    $b = array_values(array_diff($soll, $ist));
    return array(!$a && !$b, count($ist), $a, $b);
}

function asn_abo_datei()
{
    $p = asn_paths();
    $pfad = $p['configdir'] . '/mqtt_subscriptions.cfg';
    $roh = is_readable($pfad) ? (string) @file_get_contents($pfad) : '';
    return array($pfad, in_array(ASN_PRAEFIX . '/#', array_map('trim', preg_split('/\r?\n/', $roh)), true));
}

/** Aus der Deinstallation: die zurueckbehaltenen Zonenthemen DIESER Linie mit leerer Nutzlast abraeumen. */
function asn_mqtt_abraeumen()
{
    $cfg = asn_config(false);
    $z = asn_mqtt_zustand();
    if (!$z['udpport']) {
        return array('<INFO> MQTT: kein UDP-Eingangsport des Gateways - zurueckbehaltene Themen unter ' . ASN_PRAEFIX . '/ nicht abgeraeumt.');
    }
    $nummern = array();
    for ($i = 1; $i <= min(ASN_ZONEN_MAX, (int) $cfg['zonen_hoechste']); $i++) {
        $nummern[$i] = true;
    }
    foreach (array_keys(asn_zustand()['zonen']) as $n) {
        if ((int) $n >= 1 && (int) $n <= ASN_ZONEN_MAX) {
            $nummern[(int) $n] = true;
        }
    }
    ksort($nummern);
    $themen = array();
    foreach (array_keys($nummern) as $n) {
        foreach (asn_mqtt_themen() as $t) {
            if ($t['r'] && strpos($t['t'], 'zoneN/') === 0) {
                $themen[] = 'zone' . $n . '/' . substr($t['t'], 6);
            }
        }
    }
    if (!$themen) {
        return array('<INFO> MQTT: keine zurueckbehaltenen Zonenthemen bekannt - nichts abzuraeumen.');
    }
    $n1 = asn_mqtt_senden(array(), $themen, (int) $z['udpport']);
    usleep(1000000);
    $n2 = asn_mqtt_senden(array(), $themen, (int) $z['udpport']);
    if ($n1 < 0 || $n2 < 0) {
        return array('<WARNING> MQTT: der UDP-Eingang des Gateways war nicht erreichbar - Themen unter ' . ASN_PRAEFIX . '/ nicht abgeraeumt.');
    }
    return array('<INFO> MQTT: ' . count($themen) . ' zurueckbehaltene Themen unter ' . ASN_PRAEFIX . '/ mit leerer Nutzlast gesendet (zwei Runden).');
}

/* ==================================================================
 * Nachfassung 1 (31.10.2026, Vergleich mit Audioserver4Home): Protokoll von sonn, Zonen ohne Lautsprecher, alte
 * Abbilder aufraeumen, verfuegbare Fassungen auf ghcr.io, Adresse der sonn-Verwaltung
 * ================================================================== */

define('ASN_SONNLOG_ZEILEN', 200);            // so viele Zeilen von "docker logs" zeigt der Reiter Logdateien
define('ASN_SONNLOG_S', 15);                  // Obergrenze fuer docker logs
define('ASN_RM_S', 120);                      // Obergrenze je "docker image rm"
define('ASN_REGISTRY_S', 6);                  // Obergrenze je Anfrage an die Registry
define('ASN_REGISTRY_GESAMT_S', 30);          // Obergrenze fuer alle Anfragen einer Abfrage zusammen
define('ASN_REGISTRY_TAGS_MAX', 20);          // hoechstens so viele Tags (Digest je Tag = eine Anfrage)
define('ASN_FASSUNGEN_ALTER', 21600);         // Zwischenspeicher der Fassungsliste: 6 h
define('ASN_FASSUNGEN_NEU_S', 60);            // "neu laden" fragt fruehestens nach 60 s wieder

/**
 * Geheimnisfilter fuer fremde Texte (Protokoll von sonn): Kennwoerter, Token, Seriennummern, MAC- und IP-Adressen,
 * E-Mail-Adressen und Zugangsdaten in Adressen werden unkenntlich. Ob sonn Geheimnisse ins Protokoll schreibt, ist
 * nicht geprueft - deshalb filtert das Plugin selbst (Vergleich Audioserver4Home, Punkt 1).
 */
function asn_geheim_filter($t)
{
    $t = asn_geheim_weg((string) $t);
    $t = (string) preg_replace('/\x1B\[[0-9;?]*[A-Za-z]/', '', $t);
    $t = (string) preg_replace('#\b([a-z][a-z0-9+.\-]*://)[^/\s:@]+:[^/\s@]+@#i', '$1***:***@', $t);
    $t = (string) preg_replace('/\beyJ[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]{4,}\.[A-Za-z0-9_\-]*/', '***', $t);
    $t = (string) preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=\-]+/i', '$1 ***', $t);
    $t = (string) preg_replace('/((?<![A-Za-z0-9])["\']?(?:password|passwort|kennwort|passwd|pass|pwd|secret|token|access_?token|refresh_?token|api_?key|apikey|authorization|cookie|session|serial|serialnumber|serial_?nr|seriennummer|macid|mac_?id|mac|mac_?address)["\']?\s*[:=]\s*)("[^"]*"|\'[^\']*\'|[^\s,;}&\]\)]+)/i', '$1***', $t);
    $t = (string) preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '***@***', $t);
    $t = (string) preg_replace('/\b[0-9A-Fa-f]{2}(?:[:\-][0-9A-Fa-f]{2}){5}\b/', 'xx:xx:xx:xx:xx:xx', $t);
    $t = (string) preg_replace('/\b[0-9A-Fa-f]{24,}\b/', '<hex>', $t);
    $t = (string) preg_replace('/\b[0-9A-Fa-f]{12}\b/', '<seriennr>', $t);
    $t = (string) preg_replace_callback('/(?<![\d.])(?:\d{1,3}\.){3}\d{1,3}(?!\d)(?!\.\d)/', function ($m) {
        return in_array($m[0], array('127.0.0.1', '0.0.0.0'), true) ? $m[0] : 'x.x.x.x';
    }, $t);
    $t = (string) preg_replace_callback('/(?<![\w:.])(?:[0-9A-Fa-f]{0,4}:){2,7}[0-9A-Fa-f]{0,4}(?![\w:])/', function ($m) {
        $s = $m[0];
        if ($s === '::1' || !preg_match('/[0-9A-Fa-f]/', $s)) {
            return $s;
        }
        return (strpos($s, '::') !== false || substr_count($s, ':') >= 5) ? 'x:x::x' : $s;
    }, $t);
    return $t;
}

/**
 * Die letzten Zeilen des Protokolls von sonn ("docker logs", nur lesend). Gelesen wird der eigene Container, sonst
 * der Probe-Container (nur mit Name UND Label). Beide Stroeme (stdout, stderr) werden ueber den Zeitstempel von
 * docker in eine Reihenfolge gebracht. Rueckgabe array(ok, grund, zeilen, containername).
 */
function asn_sonn_protokoll($anzahl = ASN_SONNLOG_ZEILEN)
{
    $anzahl = max(1, min(1000, (int) $anzahl));
    if (!asn_docker_bin()) {
        return array(false, 'DOCKER_FEHLT', array(), '');
    }
    list($da, $eigen) = asn_container(10);
    $name = '';
    if ($da === true && $eigen) {
        $name = asn_namen()['container'];
    } elseif ($da !== null) {
        list($pda) = asn_probe(10);
        if ($pda === true) {
            $name = ASN_PROBE_NAME;
        }
    }
    if ($name === '') {
        if ($da === null) {
            return array(false, 'DOCKER_FEHLER|0', array(), '');
        }
        return array(false, $da === true ? 'CONTAINER_FREMD|' . asn_namen()['container'] : 'CONTAINER_FEHLT', array(), '');
    }
    list($rc, $out, $err) = asn_docker(array('logs', '--tail', (string) $anzahl, '--timestamps', $name), ASN_SONNLOG_S);
    if ($rc === 124) {
        return array(false, 'SONNLOG_ZEIT|' . ASN_SONNLOG_S, array(), $name);
    }
    if ($rc !== 0) {
        return array(false, 'SONNLOG_FEHLER|' . (int) $rc, array(), $name);
    }
    $zeilen = array();
    foreach (array($out, $err) as $strom) {
        foreach (preg_split('/\r?\n/', (string) $strom) as $z) {
            $z = rtrim($z);
            if ($z !== '') {
                $zeilen[] = $z;
            }
        }
    }
    sort($zeilen, SORT_STRING);   // docker setzt den Zeitstempel (RFC 3339, feste Breite) an den Zeilenanfang
    $aus = array();
    foreach (array_slice($zeilen, -$anzahl) as $z) {
        $ts = '';
        if (preg_match('/^(\d{4}-\d\d-\d\d)T(\d\d:\d\d:\d\d)\S*\s(.*)\z/s', $z, $m)) {
            $ts = $m[1] . ' ' . $m[2] . ' ';
            $z = $m[3];
        }
        $aus[] = $ts . asn_kurztext(asn_geheim_filter($z), 400);
    }
    return array(true, '', $aus, $name);
}

/**
 * Zonen ohne verbundenen Lautsprecher und Zonen mit Fehler (aus dem Takt). Rueckgabe array(ohne, fehler) - je eine
 * Liste "Nr Name". Eine Zone ohne Aussage von sonn (Google Cast meldet keine Verbindung) zaehlt nicht als "ohne".
 */
function asn_zonen_maengel(array $zonen)
{
    $ohne = array();
    $fehler = array();
    foreach ($zonen as $nr => $w) {
        if (!is_array($w)) {
            continue;
        }
        $n = (int) $nr . ' ' . (isset($w['name']) ? (string) $w['name'] : '-');
        if (isset($w['verbunden']) && (string) $w['verbunden'] === '0') {
            $ohne[] = $n;
        }
        if (!empty($w['fehler'])) {
            $fehler[] = $n;
        }
    }
    return array($ohne, $fehler);
}

/** Text der Zonenmaengel fuer Healthcheck und Reiter Test ('' = keine). */
function asn_zonen_maengel_text(array $zonen)
{
    list($ohne, $fehler) = asn_zonen_maengel($zonen);
    $t = array();
    if ($ohne) {
        $t[] = sprintf(asn_klartext('ZONEN.OHNE'), implode('; ', $ohne));
    }
    if ($fehler) {
        $t[] = sprintf(asn_klartext('ZONEN.FEHLER'), implode('; ', $fehler));
    }
    return implode(' ', $t);
}

/* ---------------- Alte Abbilder dieses Repos (nur eigene, nur unbenutzte, nie mit -f) ---------------- */

/**
 * Die Abbilder des festgelegten Repos. Rueckgabe array(ok, grund, liste, frei). Je Abbild: id (12 Hex), refs (alle
 * Namen), groesse (Byte), erstellt (Datum), art: aktuell | vorher | benutzt | fremd | entfernbar. Behalten werden der
 * eingestellte Digest (aktuell), der vorherige (Rueckweg), jedes Abbild, das ein Container benutzt (laufend oder
 * gestoppt), und jedes, das auch einen fremden Namen traegt. frei = Summe der entfernbaren (hoechstens so viel wird
 * frei; gemeinsame Schichten bleiben, wenn ein anderes Abbild sie braucht).
 */
function asn_abbilder_lage()
{
    $repo = asn_abbild_vorgabe()['repo'];
    if ($repo === '') {
        return array(false, 'ABBILD_FEHLT', array(), 0);
    }
    list($dl, $dg) = asn_docker_lage();
    if ($dl !== 'ok') {
        return array(false, $dg, array(), 0);
    }
    list($rc, $out) = asn_docker(array('image', 'ls', '--no-trunc', '--format', '{{.ID}}', $repo), 20);
    if ($rc !== 0) {
        return array(false, 'DOCKER_FEHLER|' . (int) $rc, array(), 0);
    }
    $ids = array();
    foreach (preg_split('/\r?\n/', (string) $out) as $x) {
        $x = trim($x);
        if (preg_match('/^sha256:[0-9a-f]{64}\z/', $x)) {
            $ids[$x] = true;
        }
    }
    if (!$ids) {
        return array(true, '', array(), 0);
    }
    list($rc, $out) = asn_docker(array_merge(array('image', 'inspect'), array_keys($ids)), 20);
    $info = json_decode((string) $out, true);
    if ($rc !== 0 || !is_array($info)) {
        return array(false, 'DOCKER_FEHLER|' . (int) $rc, array(), 0);
    }
    list($rc, $out) = asn_docker(array('ps', '-a', '--no-trunc', '--format', '{{.ID}}'), 20);
    if ($rc !== 0) {
        return array(false, 'DOCKER_FEHLER|' . (int) $rc, array(), 0);
    }
    $cids = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) $out)), 'strlen'));
    $benutzt = array();
    if ($cids) {
        list($rc, $out) = asn_docker(array_merge(array('container', 'inspect', '--format', '{{.Image}}'), $cids), 20);
        if ($rc !== 0) {
            return array(false, 'DOCKER_FEHLER|' . (int) $rc, array(), 0);
        }
        foreach (preg_split('/\r?\n/', (string) $out) as $x) {
            if (trim($x) !== '') {
                $benutzt[trim($x)] = true;
            }
        }
    }
    $cfg = asn_config(false);
    $halten = array(asn_abbild_digest($cfg) => 'aktuell');
    if (asn_digest_gueltig($cfg['abbild_vorher']) && !isset($halten[$cfg['abbild_vorher']])) {
        $halten[$cfg['abbild_vorher']] = 'vorher';
    }
    $liste = array();
    $frei = 0;
    foreach ($info as $b) {
        if (!is_array($b) || !isset($b['Id']) || !is_string($b['Id']) || !preg_match('/^sha256:[0-9a-f]{64}\z/', $b['Id'])) {
            continue;
        }
        $refs = array();
        foreach (array('RepoTags', 'RepoDigests') as $k) {
            foreach ((isset($b[$k]) && is_array($b[$k])) ? $b[$k] : array() as $r) {
                if (is_string($r) && $r !== '') {
                    $refs[] = $r;
                }
            }
        }
        $fremd = !$refs;
        $art = '';
        foreach ($refs as $r) {
            if (strpos($r, $repo . '@') === 0) {
                $d = substr($r, strlen($repo) + 1);
                if (isset($halten[$d]) && $art !== 'aktuell') {
                    $art = $halten[$d];
                }
            } elseif (strpos($r, $repo . ':') !== 0) {
                $fremd = true;
            }
        }
        if ($art === '') {
            $art = isset($benutzt[$b['Id']]) ? 'benutzt' : ($fremd ? 'fremd' : 'entfernbar');
        }
        $gr = isset($b['Size']) && is_numeric($b['Size']) ? (int) $b['Size'] : 0;
        if ($art === 'entfernbar') {
            $frei += $gr;
        }
        $liste[] = array('id' => substr($b['Id'], 7, 12), 'refs' => $refs, 'groesse' => $gr, 'art' => $art,
                         'erstellt' => isset($b['Created']) && is_string($b['Created']) ? substr($b['Created'], 0, 10) : '');
    }
    usort($liste, function ($a, $b) {
        return strcmp($b['erstellt'], $a['erstellt']);
    });
    return array(true, '', $liste, $frei);
}

/** Groesse fuer Menschen (MB mit einer Nachkommastelle). */
function asn_mb($byte)
{
    return number_format(max(0, (int) $byte) / 1048576, 1, ',', '.') . ' MB';
}

/**
 * Die entfernbaren Abbilder entfernen - jeden Namen einzeln mit "docker image rm" OHNE -f; Docker lehnt ab, wenn ein
 * Container das Abbild doch braucht. Rueckgabe array(ok, grund, entfernt, abgelehnt, frei).
 */
function asn_abbilder_aufraeumen()
{
    $v = asn_vorgang();
    if (in_array($v['zustand'], array('gestartet', 'laeuft'), true)) {
        return array(false, 'VORGANG_LAEUFT|' . $v['vorgang'], 0, 0, 0);    // ein Update zieht gerade ein Abbild
    }
    list($ok, $g, $liste) = asn_abbilder_lage();
    if (!$ok) {
        return array(false, $g, 0, 0, 0);
    }
    $repo = asn_abbild_vorgabe()['repo'];
    $weg = 0;
    $nein = 0;
    $frei = 0;
    foreach ($liste as $b) {
        if ($b['art'] !== 'entfernbar') {
            continue;
        }
        $alle = true;
        foreach ($b['refs'] as $r) {
            if (strpos($r, $repo . '@') !== 0 && strpos($r, $repo . ':') !== 0) {
                $alle = false;     // kann nach asn_abbilder_lage() nicht vorkommen - trotzdem nie Fremdes anfassen
                continue;
            }
            list($rc, , $err) = asn_docker(array('image', 'rm', $r), ASN_RM_S);
            if ($rc !== 0) {
                $alle = false;
                asn_log('Abbilder: ' . $b['id'] . ' nicht entfernt - ' . asn_kurztext(asn_letzte_zeile($err), 160));
                break;
            }
        }
        if ($alle) {
            $weg++;
            $frei += $b['groesse'];
            asn_log('Abbilder: ' . $b['id'] . ' entfernt (' . asn_mb($b['groesse']) . ').');
        } else {
            $nein++;
        }
    }
    return array(true, '', $weg, $nein, $frei);
}

/* ---------------- Verfuegbare Fassungen auf ghcr.io (nur lesend, anonym, Zwischenspeicher) ---------------- */

/** Die Registry: ghcr.io - im Pruefstand eine Attrappe auf 127.0.0.1 (ASN_REGISTRY_ATTRAPPE). */
function asn_registry_basis()
{
    $a = (string) getenv('ASN_REGISTRY_ATTRAPPE');
    if ($a !== '' && preg_match('#^http://127\.0\.0\.1:[0-9]{2,5}\z#', $a)) {
        return $a;
    }
    return 'https://ghcr.io';
}

/**
 * Eine lesende Anfrage an die Registry (curl). Rueckgabe array(code, rumpf, kopf, grund) - kopf mit kleinen
 * Schluesseln. Kein $http_response_header (unter PHP 8.5 veraltet). Der Pull-Token steht nur im Anfragekopf.
 */
function asn_registry_holen($url, array $kopf, $nur_kopf, $sekunden)
{
    if (!function_exists('curl_init')) {
        return array(0, '', array(), 'REGISTRY_CURL');
    }
    $h = curl_init($url);
    if ($h === false) {
        return array(0, '', array(), 'REGISTRY_CURL');
    }
    $kz = array();
    curl_setopt_array($h, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_NOBODY         => (bool) $nur_kopf,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => max(1, min(5, (int) $sekunden)),
        CURLOPT_TIMEOUT        => max(1, (int) $sekunden),
        CURLOPT_HTTPHEADER     => $kopf,
        CURLOPT_USERAGENT      => 'LoxBerry-Plugin-Audioserver-NG',
        CURLOPT_HEADERFUNCTION => function ($c, $z) use (&$kz) {
            $t = explode(':', $z, 2);
            if (count($t) === 2) {
                $kz[strtolower(trim($t[0]))] = trim($t[1]);
            }
            return strlen($z);
        },
    ));
    $rumpf = curl_exec($h);
    $code = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
    $nr = curl_errno($h);
    if (PHP_VERSION_ID < 80000) {
        curl_close($h);
    }
    if ($nr !== 0) {
        return array(0, '', array(), $nr === 28 ? 'REGISTRY_ZEIT|' . (int) $sekunden : 'REGISTRY_NETZ|' . $nr);
    }
    return array($code, is_string($rumpf) ? substr($rumpf, 0, 262144) : '', $kz, '');
}

/** Tags ordnen: Kanaele (ohne Ziffernfolge, z. B. beta, beta-latest) zuerst, dann Fassungen absteigend. */
function asn_tags_ordnen(array $tags)
{
    $kanal = array();
    $fass = array();
    foreach ($tags as $t) {
        if (preg_match('/^[0-9]+\.[0-9]+/', $t)) {
            $fass[] = $t;
        } else {
            $kanal[] = $t;
        }
    }
    sort($kanal, SORT_STRING);
    usort($fass, function ($a, $b) {
        return version_compare($b, $a);
    });
    return array_merge($kanal, $fass);
}

/**
 * Die verfuegbaren Fassungen des festgelegten Repos auf ghcr.io: Tag -> Digest (anonymer Pull-Token, Tag-Liste,
 * je Tag ein HEAD auf das Manifest). Nur lesend; der Zwischenspeicher (data/plugins/<ordner>/fassungen.json) gilt 6 h,
 * "neu laden" fragt fruehestens nach 60 s wieder. Rueckgabe array(ok, grund, liste, zeit, aus_speicher).
 */
function asn_fassungen($neu = false)
{
    $p = asn_paths();
    $repo = asn_abbild_vorgabe()['repo'];
    $datei = $p['datadir'] . '/fassungen.json';
    $alt = asn_json_lesen($datei);
    $gilt = isset($alt['repo'], $alt['zeit'], $alt['liste']) && $alt['repo'] === $repo && is_array($alt['liste']) && is_int($alt['zeit']);
    $alter = $gilt ? time() - $alt['zeit'] : -1;
    if ($gilt && $alter >= 0 && ($alter < ASN_FASSUNGEN_NEU_S || (!$neu && $alter < ASN_FASSUNGEN_ALTER))) {
        return array(true, '', $alt['liste'], $alt['zeit'], true);
    }
    if (!preg_match('#^ghcr\.io/([a-z0-9._\-]+(?:/[a-z0-9._\-]+)+)\z#', $repo, $m)) {
        return array(false, 'REGISTRY_NICHT_GHCR', array(), 0, false);
    }
    $name = $m[1];
    $basis = asn_registry_basis();
    $ende = time() + ASN_REGISTRY_GESAMT_S;
    list($c, $r, , $g) = asn_registry_holen($basis . '/token?scope=' . rawurlencode('repository:' . $name . ':pull') . '&service=ghcr.io',
        array('Accept: application/json'), false, ASN_REGISTRY_S);
    if ($g !== '') {
        return array(false, $g, array(), 0, false);
    }
    $d = json_decode($r, true);
    $tok = (is_array($d) && isset($d['token']) && is_string($d['token'])) ? $d['token'] : '';
    if ($c !== 200 || $tok === '' || preg_match('/[\r\n]/', $tok)) {
        return array(false, 'REGISTRY_ANTWORT|' . $c, array(), 0, false);
    }
    $auth = 'Authorization: Bearer ' . $tok;
    list($c, $r, , $g) = asn_registry_holen($basis . '/v2/' . $name . '/tags/list?n=1000', array($auth, 'Accept: application/json'),
        false, max(1, min(ASN_REGISTRY_S, $ende - time())));
    if ($g !== '') {
        return array(false, $g, array(), 0, false);
    }
    $d = json_decode($r, true);
    if ($c !== 200 || !is_array($d) || !isset($d['tags']) || !is_array($d['tags'])) {
        return array(false, 'REGISTRY_ANTWORT|' . $c, array(), 0, false);
    }
    $tags = array();
    foreach ($d['tags'] as $t) {
        if (is_string($t) && preg_match('/^[A-Za-z0-9_][A-Za-z0-9._\-]{0,127}\z/', $t)) {
            $tags[] = $t;
        }
    }
    $tags = array_slice(asn_tags_ordnen(array_values(array_unique($tags))), 0, ASN_REGISTRY_TAGS_MAX);
    $annehmen = 'Accept: application/vnd.oci.image.index.v1+json, application/vnd.docker.distribution.manifest.list.v2+json, '
        . 'application/vnd.docker.distribution.manifest.v2+json, application/vnd.oci.image.manifest.v1+json';
    $liste = array();
    foreach ($tags as $t) {
        $rest = $ende - time();
        $dig = '';
        if ($rest >= 1) {
            list($c, , $k, $g) = asn_registry_holen($basis . '/v2/' . $name . '/manifests/' . rawurlencode($t), array($auth, $annehmen), true,
                max(1, min(ASN_REGISTRY_S, $rest)));
            $x = isset($k['docker-content-digest']) ? strtolower($k['docker-content-digest']) : '';
            $dig = ($g === '' && $c === 200 && asn_digest_gueltig($x)) ? $x : '';
        }
        $liste[] = array('tag' => $t, 'digest' => $dig);
    }
    if (!$liste) {
        return array(false, 'REGISTRY_LEER', array(), 0, false);
    }
    $jetzt = time();
    asn_json_schreiben($datei, array('repo' => $repo, 'zeit' => $jetzt, 'liste' => $liste), 0600);
    return array(true, '', $liste, $jetzt, false);
}

/** Adresse der sonn-Verwaltung (http://<IP>:7090/) - nur bei gueltiger IPv4, sonst ''. */
function asn_verwaltung_url(array $cfg)
{
    $ip = isset($cfg['ip']) ? (string) $cfg['ip'] : '';
    return asn_ipv4_zahl($ip) !== null ? 'http://' . $ip . ':7090/' : '';
}

/* ==================================================================
 * Takt (bin/asn_takt.php, cron.01min): Lage messen, zustand.json schreiben, MQTT senden
 * ================================================================== */

/** Sperre des Takts mit Prozessnummer (kein flock: er vererbte sich an docker exec, Regeln/03). true = gesperrt. */
/**
 * Sperre mit Prozessnummer. Fremdpruefung 31.10.2026, T2-5: die Sperrdatei liegt im Datenordner (auf der Karte, sie
 * uebersteht einen Neustart, wenn der Lauf hart endete). Ein /proc/<pid> allein ist kein Beleg - nach dem Neustart
 * kann ein ganz anderer Prozess diese Nummer tragen, und Takt oder Vorgang ruhten dann dauerhaft. Als lebend gilt der
 * Halter nur, wenn seine Befehlszeile php und das Programm $skript enthaelt (Gedaechtnis "PID-Datei ist kein Beleg").
 */
function asn_pid_lebt($pid, $skript)
{
    if ($pid <= 0 || DIRECTORY_SEPARATOR === '\\' || !is_dir('/proc/' . $pid)) {
        return false;
    }
    $c = @file_get_contents('/proc/' . $pid . '/cmdline');
    if (!is_string($c) || $c === '') {
        return false;   // Kernel-Faden, Zombie oder nicht lesbar: nicht unser Lauf
    }
    $teile = explode("\0", rtrim($c, "\0"));
    $php = preg_match('#(^|/)php[0-9.\-]*\z#', (string) $teile[0]) === 1;
    foreach ($teile as $t) {
        if ($php && basename($t) === $skript) {
            return true;
        }
    }
    return false;
}

function asn_pid_sperre($datei, $skript = '')
{
    $alt = is_file($datei) ? (int) trim((string) @file_get_contents($datei)) : 0;
    if ($alt > 0 && $alt !== getmypid() && asn_pid_lebt($alt, $skript)) {
        return false;
    }
    if (!is_dir(dirname($datei))) {
        @mkdir(dirname($datei), 0700, true);
    }
    return @file_put_contents($datei, (string) getmypid()) !== false;
}

function asn_upgrade_marke()
{
    $m = asn_paths()['marke'];
    if (!is_file($m)) {
        return false;
    }
    $t = (int) trim((string) @file_get_contents($m));
    return $t <= 0 || abs(time() - $t) <= 3600;
}

/**
 * Ein Taktlauf. Rueckgabe array(rc, Satz). Docker wird nur gefragt, wenn die Verwaltung an ist oder ein eigener
 * Container angelegt wurde. Ohne Antwort von sonn: online 0, Fehlwerte. Gesendet wird, was sich geaendert hat, und
 * alle 30 min der volle Satz.
 */
function asn_takt()
{
    $p = asn_paths();
    if (asn_upgrade_marke()) {
        return array(0, 'Upgrade laeuft - Takt ruht.');
    }
    if (!asn_pid_sperre($p['takt_sperre'], 'asn_takt.php')) {
        return array(0, 'Ein anderer Takt laeuft noch.');
    }
    $cfg = asn_config(false);
    $merk = asn_merk();
    $alt = asn_zustand();
    $zaehler = ($alt['zaehler'] + 1) % 1000;
    $z = array('ts' => time(), 'zaehler' => $zaehler, 'aktiv' => 0, 'docker' => '', 'container' => null, 'sonn' => null,
               'grund' => '', 'zonen' => array(), 'ok' => 0);
    $lage = array('online' => 0, 'gesund' => -1, 'gekoppelt' => -1, 'fassung' => '', 'zonen' => array());
    if (!empty($cfg['container_ein']) || $merk['angelegt']) {
        $z['aktiv'] = 1;
        list($dl, $dg, $df) = asn_docker_lage();
        $z['docker'] = $dl === 'ok' ? $df : '';
        if ($dl !== 'ok') {
            $z['grund'] = $dg;
        } else {
            list($da, $eigen, $kurz) = asn_container();
            if ($da === true && $eigen) {
                $z['container'] = $kurz;
                if ($kurz['laeuft']) {
                    list($ok, $g, $d) = asn_sonn_fragen(asn_namen()['container'], 'stand');
                    if ($ok && is_array($d)) {
                        $zon = asn_zonen_normieren(isset($d['zonen']) ? $d['zonen'] : array());
                        $st = isset($d['health']['status']) ? (string) $d['health']['status'] : '';
                        $lage = array('online' => 1,
                                      'gesund' => $st === 'ok' ? 2 : ($st === 'degraded' ? 1 : ($st === 'unhealthy' ? 0 : -1)),
                                      'gekoppelt' => isset($d['gekoppelt']) ? ($d['gekoppelt'] ? 1 : 0) : -1,
                                      'fassung' => isset($d['health']['version']) && is_scalar($d['health']['version']) ? asn_kurztext((string) $d['health']['version'], 40) : '',
                                      'zonen' => $zon);
                        $z['sonn'] = array(
                            'status' => $st,
                            'fassung' => $lage['fassung'],
                            'checks' => isset($d['health']['checks']) && is_array($d['health']['checks']) ? array_slice($d['health']['checks'], 0, 8) : array(),
                            'gekoppelt' => $lage['gekoppelt'],
                            'mac_id' => isset($d['mac_id']) && is_string($d['mac_id']) ? strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $d['mac_id'])) : '',
                            'ip' => isset($d['ip']) && is_string($d['ip']) && asn_ipv4_zahl($d['ip']) !== null ? $d['ip'] : '',
                            'mqtt_ein' => isset($d['mqtt_ein']) ? ($d['mqtt_ein'] ? 1 : 0) : -1,
                            'loxone_ein' => isset($d['loxone_ein']) ? ($d['loxone_ein'] ? 1 : 0) : -1,
                            'konten' => isset($d['konten']) && is_int($d['konten']) && $d['konten'] >= 0 ? $d['konten'] : -1,
                            // 0.9.2 Nachzug: Titel der Lokalen Bibliothek (/api/v1/browse ... Tracks, "total") und ob
                            // der Ordner ASN_MUSIK_NAME dort sichtbar ist; -1 = nicht feststellbar.
                            'titel' => isset($d['titel']) && is_int($d['titel']) && $d['titel'] >= 0 ? $d['titel'] : -1,
                            'musik_sichtbar' => isset($d['musik_sichtbar']) && is_bool($d['musik_sichtbar']) ? ($d['musik_sichtbar'] ? 1 : 0) : -1,
                            // 0.9.3: Lage der Herstellerdatei mit den Vorgabesendern (nur gelesen).
                            'tunein' => asn_tunein_normieren(isset($d['tunein']) ? $d['tunein'] : null),
                        );
                        $z['zonen'] = $zon;
                        $z['ok'] = $lage['gesund'] >= 1 ? 1 : 0;
                        if ($zon && max(array_keys($zon)) > (int) $cfg['zonen_hoechste']) {
                            $c2 = asn_config(false);
                            $c2['zonen_hoechste'] = max(array_keys($zon));
                            if (asn_config_lage() !== 'kaputt') {
                                asn_config_speichern($c2);
                            }
                        }
                    } else {
                        $z['grund'] = $g;
                        $z['zonen'] = $alt['zonen'];
                        $lage['zonen'] = $alt['zonen'];
                    }
                } else {
                    $z['grund'] = 'CONTAINER_STEHT';
                    $z['zonen'] = $alt['zonen'];
                    $lage['zonen'] = $alt['zonen'];
                }
            } elseif ($da === true) {
                $z['grund'] = 'CONTAINER_FREMD|' . asn_namen()['container'];
            } elseif ($da === false) {
                $z['grund'] = 'CONTAINER_FEHLT';
            } else {
                $z['grund'] = 'DOCKER_FEHLER|0';
            }
        }
        asn_log_wenn_neu('takt', $z['grund'] === '' ? 'ok' : $z['grund'],
            $z['grund'] === '' ? 'Takt: sonn antwortet wieder.' : 'Takt: ' . asn_grund_text($z['grund']));
    }
    asn_json_schreiben($p['zustand'], $z, 0600);
    $gesendet = 0;
    if (!empty($cfg['mqtt_ein'])) {
        $paare = asn_mqtt_paare($lage, array('zaehler' => $zaehler, 'ok' => $z['ok']), time());
        $vorher = asn_json_lesen($p['gesendet']);
        $voll = !isset($vorher['_voll']) || (time() - (int) $vorher['_voll']) >= ASN_VOLL_S;
        $raus = array();
        foreach ($paare as $t => $v) {
            if ($voll || in_array($t, array('status/zaehler', 'status/ok', 'status/ts', 'server/online'), true)
                || !array_key_exists($t, $vorher) || (string) $vorher[$t] !== (string) $v) {
                $raus[$t] = $v;
            }
        }
        $gesendet = asn_mqtt_senden($raus);
        if ($gesendet >= 0) {
            $neu = $paare;
            $neu['_voll'] = $voll ? time() : (int) (isset($vorher['_voll']) ? $vorher['_voll'] : time());
            asn_json_schreiben($p['gesendet'], $neu, 0600);
        }
    }
    if (is_array($z['sonn']) && isset($z['sonn']['tunein'])) {
        asn_tunein_takt($cfg, $z['sonn']['tunein'], $z['zonen']);
    }
    @unlink($p['takt_sperre']);
    return array(0, 'Takt ' . $zaehler . ': ' . ($z['aktiv'] ? ($z['grund'] === '' ? 'sonn antwortet' : asn_grund_text($z['grund'])) : 'Verwaltung aus')
        . ', ' . max(0, $gesendet) . ' MQTT-Datagramme.');
}

function asn_log_wenn_neu($schluessel, $lage, $text)
{
    $f = asn_paths()['datadir'] . '/.lage_' . preg_replace('/[^a-z0-9_]/i', '', $schluessel);
    $alt = is_file($f) ? trim((string) @file_get_contents($f)) : '';
    if ($alt === $lage) {
        return false;
    }
    @file_put_contents($f, $lage);
    if ($alt === '' && $lage === 'ok') {
        return false;
    }
    asn_log($text);
    return true;
}

/* ==================================================================
 * Hintergrundvorgang (bin/asn_vorgang.php; Bauart Alexa NG hue_vorgang.php)
 * ================================================================== */

function asn_vorgaenge()
{
    return array('anlegen', 'starten', 'stoppen', 'neustart', 'wechsel', 'update', 'rueckweg', 'probe_entfernen', 'bibliothek',
                 'favoriten', 'tunein');
}

function asn_vorgang()
{
    $d = asn_json_lesen(asn_paths()['vorgang']);
    $v = array('vorgang' => '', 'zustand' => '', 'pid' => 0, 'start' => 0, 'ende' => 0, 'schritt' => '', 'grund' => '',
               'text' => '', 'ergebnis' => '');
    foreach ($v as $k => $w) {
        if (isset($d[$k]) && (is_int($w) ? is_int($d[$k]) : is_string($d[$k]))) {
            $v[$k] = $d[$k];
        }
    }
    // Ein Vorgang, dessen Prozess nicht mehr lebt, ist abgebrochen (nicht "laeuft"). T2-5: "lebt" heisst php mit
    // asn_vorgang.php - eine nach dem Neustart wiedervergebene Nummer haelt keinen Vorgang mehr fest.
    if (in_array($v['zustand'], array('gestartet', 'laeuft'), true) && $v['pid'] > 0 && DIRECTORY_SEPARATOR !== '\\'
        && !asn_pid_lebt($v['pid'], 'asn_vorgang.php') && time() - $v['start'] > 10) {
        $v['zustand'] = 'abgebrochen';
    }
    if ($v['zustand'] === 'gestartet' && time() - $v['start'] > 60) {
        $v['zustand'] = 'abgebrochen';
    }
    return $v;
}

function asn_vorgang_schreiben(array $v)
{
    return asn_json_schreiben(asn_paths()['vorgang'], $v, 0600);
}

/** Den Vorgang im Hintergrund starten (setsid, Apache wartet nicht). Rueckgabe array(ok, grund). */
function asn_vorgang_starten($auftrag)
{
    if (!in_array($auftrag, asn_vorgaenge(), true)) {
        return array(false, 'VORGANG');
    }
    if (DIRECTORY_SEPARATOR === '\\' || !function_exists('proc_open')) {
        return array(false, 'NICHT_MOEGLICH');
    }
    $v = asn_vorgang();
    if (in_array($v['zustand'], array('gestartet', 'laeuft'), true)) {
        return array(false, 'VORGANG_LAEUFT|' . $v['vorgang']);
    }
    $prog = asn_paths()['bindir'] . '/asn_vorgang.php';
    if (!is_file($prog)) {
        return array(false, 'VORGANG');
    }
    if (!asn_vorgang_schreiben(array('vorgang' => $auftrag, 'zustand' => 'gestartet', 'pid' => 0, 'start' => time(), 'ende' => 0,
                                     'schritt' => '', 'grund' => '', 'text' => '', 'ergebnis' => ''))) {
        return array(false, 'SCHREIBEN');
    }
    $desk = array(0 => array('file', '/dev/null', 'r'), 1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w'));
    $pipes = array();
    $proc = @proc_open(array('sh', '-c', 'setsid "$0" "$@" </dev/null >/dev/null 2>&1 &', 'php', $prog, $auftrag), $desk, $pipes);
    if (!is_resource($proc)) {
        return array(false, 'VORGANG');
    }
    proc_close($proc);
    return array(true, '');
}

/** Den Vorgang ausfuehren (aus bin/asn_vorgang.php). Rueckgabe array(ok, grund, text, ergebnis). */
function asn_vorgang_ausfuehren($auftrag)
{
    $p = asn_paths();
    if (!asn_pid_sperre($p['vorgang_sperre'], 'asn_vorgang.php')) {
        return array(false, 'VORGANG_LAEUFT|?', '', '');
    }
    $v = asn_vorgang();
    $v['vorgang'] = $auftrag;
    $v['zustand'] = 'laeuft';
    $v['pid'] = (int) getmypid();
    if ($v['start'] === 0) {
        $v['start'] = time();
    }
    asn_vorgang_schreiben($v);
    $schritt = function ($s) use (&$v) {
        $v['schritt'] = $s;
        asn_vorgang_schreiben($v);
    };
    asn_log('Vorgang ' . $auftrag . ' beginnt.');
    switch ($auftrag) {
        case 'anlegen':
            $r = asn_anlegen($schritt, false);
            break;
        case 'wechsel':
            $r = asn_anlegen($schritt, true);
            break;
        case 'starten':
        case 'stoppen':
        case 'neustart':
            $r = asn_schalten($auftrag);
            break;
        case 'update':
            $r = asn_update($schritt);
            break;
        case 'rueckweg':
            $r = asn_rueckweg($schritt);
            break;
        case 'probe_entfernen':
            $r = asn_probe_entfernen();
            break;
        case 'bibliothek':
            $r = asn_bibliothek();
            break;
        case 'favoriten':
            $r = asn_favoriten_uebernehmen();
            break;
        case 'tunein':
            $r = asn_tunein_vorgang($schritt);
            break;
        default:
            $r = array(false, 'VORGANG', '', '');
    }
    $v['zustand'] = $r[0] ? 'fertig' : 'fehler';
    $v['grund'] = (string) $r[1];
    $v['text'] = asn_kurztext(asn_geheim_weg((string) $r[2]), 200);
    $v['ergebnis'] = (string) $r[3];
    $v['ende'] = time();
    asn_vorgang_schreiben($v);
    asn_log('Vorgang ' . $auftrag . ': ' . ($r[0] ? 'fertig (' . $r[3] . ')' : 'nicht gelungen - ' . asn_grund_text($r[1])
        . ($r[2] !== '' ? ' (' . asn_kurztext($r[2], 160) . ')' : '')));
    @unlink($p['vorgang_sperre']);
    return $r;
}

/** Den Satz zu einem beendeten Vorgang (fuer Meldung und Kopf). */
function asn_vorgang_satz(array $v)
{
    if ($v['vorgang'] === '') {
        return '';
    }
    $was = asn_t('VORGANG.' . strtoupper($v['vorgang']));
    if (in_array($v['zustand'], array('gestartet', 'laeuft'), true)) {
        return sprintf(asn_t('VORGANG.LAEUFT'), $was, $v['schritt'] !== '' ? $v['schritt'] : '-', max(0, time() - $v['start']));
    }
    if ($v['zustand'] === 'fertig') {
        return sprintf(asn_t('VORGANG.FERTIG'), $was, asn_t('ERGEBNIS.' . preg_replace('/[^A-Z_]/', '', strtoupper($v['ergebnis']))),
            date('Y-m-d H:i:s', $v['ende']));
    }
    if ($v['zustand'] === 'abgebrochen') {
        return sprintf(asn_t('VORGANG.ABGEBROCHEN'), $was);
    }
    return sprintf(asn_t('VORGANG.FEHLER'), $was, asn_grund_text($v['grund']) . ($v['text'] !== '' ? ' (' . $v['text'] . ')' : '')
        . ($v['ergebnis'] !== '' ? ' ' . asn_grund_text($v['ergebnis']) : ''),
        date('Y-m-d H:i:s', $v['ende'] > 0 ? $v['ende'] : time()));
}

/**
 * Fremdpruefung 31.10.2026, T2-2: den bisherigen eigenen Container fuer ein Neuanlegen beiseitestellen - anhalten
 * (macvlan: er gibt IP und MAC erst frei, wenn er steht), nachsehen, dann in <name>-alt-<JahrMonatTagStundeMinuteSekunde>
 * umbenennen. Gelingt das nicht, bleibt er unter seinem Namen und laeuft (wenn er lief) weiter.
 * Rueckgabe array(ok, grund, text, neuer Name).
 */
function asn_alt_beiseite($name, $lief)
{
    $alt = $name . '-alt-' . date('YmdHis');
    list($rc, , $err) = asn_docker(array('stop', '-t', '20', $name), 60);
    list($da, $info) = asn_inspect_container($name);
    if ($rc !== 0 || $da !== true || asn_container_kurz($info)['laeuft']) {
        if ($lief && $da === true && !asn_container_kurz($info)['laeuft']) {
            asn_docker(array('start', $name), 60);
        }
        return array(false, 'ALT_ANHALTEN|' . $rc, asn_letzte_zeile($err), '');
    }
    list($rc, , $err) = asn_docker(array('rename', $name, $alt), 30);
    if ($rc !== 0) {
        if ($lief) {
            asn_docker(array('start', $name), 60);
        }
        return array(false, 'ALT_UMBENENNEN|' . $rc, asn_letzte_zeile($err), '');
    }
    asn_log('Neu anlegen: der bisherige Container ist angehalten und heisst bis zum Start des neuen ' . $alt . '.');
    return array(true, '', '', $alt);
}

/**
 * Fremdpruefung 31.10.2026, T2-2: das Neuanlegen ist gescheitert. Ein neuer Container gleichen Namens geht weg (nur mit
 * beiden eigenen Labels; vorher die letzten Zeilen seines Protokolls gefiltert ins Protokoll des Plugins). Gab es einen
 * alten, bekommt er seinen Namen zurueck und startet, wenn er lief - aber NUR, wenn der neue nachweislich fort ist (zwei
 * Container mit derselben IP/MAC duerfen im macvlan nicht zugleich laufen). Rueckgabe: Verbleib als Grund ('' ohne alten).
 */
function asn_alt_zurueck($name, $alt, $lief, $grund)
{
    list($hda, $hinfo) = asn_inspect_container($name);
    if ($hda === true && asn_eigen($hinfo)) {
        list($lrc, $lout, $lerr) = asn_docker(array('logs', '--tail', '10', $name), ASN_SONNLOG_S);
        if ($lrc === 0) {
            foreach (array_slice(array_filter(array_map('trim', preg_split('/\r?\n/', $lout . "\n" . $lerr)), 'strlen'), -10) as $z) {
                asn_log('Neuer Container (gescheitert): ' . asn_kurztext(asn_geheim_filter($z), 200));
            }
        }
        asn_docker(array('stop', '-t', '20', $name), 60);
        asn_docker(array('rm', $name), 30);
        list($hda, ) = asn_inspect_container($name);
    }
    if ($alt === '') {
        asn_log('Anlegen gescheitert (' . asn_grund_text($grund) . ')' . ($hda === false ? ' - der neue Container ist wieder fort.'
            : ' - ein Container ' . $name . ' liegt noch da.'));
        return '';
    }
    if ($hda !== false) {
        asn_log('Neu anlegen gescheitert (' . asn_grund_text($grund) . '). Der Name ' . $name . ' ist nicht frei - der bisherige'
            . ' Container bleibt angehalten als ' . $alt . ' (nicht gestartet: dieselbe IP und MAC).');
        return 'ALT_LIEGT|' . $alt;
    }
    list($rc1, , ) = asn_docker(array('rename', $alt, $name), 30);
    if ($rc1 !== 0) {
        asn_log('Neu anlegen gescheitert (' . asn_grund_text($grund) . '); der bisherige Container liess sich nicht zurueckbenennen'
            . ' (Rueckgabe ' . $rc1 . ') - er liegt angehalten als ' . $alt . '.');
        return 'ALT_LIEGT|' . $alt;
    }
    if ($lief) {
        list($rc2, , ) = asn_docker(array('start', $name), 60);
        if ($rc2 !== 0) {
            asn_log('Neu anlegen gescheitert (' . asn_grund_text($grund) . '); der bisherige Container ist zurueck, startet aber nicht'
                . ' (Rueckgabe ' . $rc2 . ').');
            return 'ALT_STEHT';
        }
    }
    asn_log('Neu anlegen gescheitert (' . asn_grund_text($grund) . ') - der bisherige Container ist unveraendert zurueck'
        . ($lief ? ' und laeuft wieder.' : '.'));
    return 'ALT_ZURUECK';
}

/**
 * Fremdpruefung 31.10.2026, T2-2: Reste eines frueheren, abgebrochenen Neuanlegens (<name>-alt-<Zeit>, beide eigenen
 * Labels, angehalten). Fehlt der eigentliche Container, bekommt der juengste Rest seinen Namen zurueck (er ist der
 * bisherige Audioserver); die uebrigen angehaltenen Reste gehen weg. Ein laufender Rest bleibt unberuehrt.
 */
function asn_reste_aufraeumen()
{
    $name = asn_namen()['container'];
    list($rc, $out, ) = asn_docker(array('ps', '-a', '--filter', 'label=de.loxberry.plugin.folder=' . asn_paths()['plugin'],
        '--format', '{{.Names}}'), 15);
    if ($rc !== 0) {
        return;
    }
    $reste = array();
    foreach (preg_split('/\r?\n/', trim((string) $out)) as $n) {
        $n = trim($n);
        if (preg_match('/^' . preg_quote($name, '/') . '-alt-[0-9]{14}\z/', $n)) {
            list($da, $info) = asn_inspect_container($n);
            if ($da === true && asn_eigen($info) && !asn_container_kurz($info)['laeuft']) {
                $reste[] = $n;
            }
        }
    }
    if (!$reste) {
        return;
    }
    rsort($reste, SORT_STRING);
    list($da, ) = asn_inspect_container($name);
    if ($da === false) {
        $j = array_shift($reste);
        list($rc, , ) = asn_docker(array('rename', $j, $name), 30);
        asn_log('Rest eines abgebrochenen Neuanlegens: ' . $j . ($rc === 0 ? ' heisst wieder ' . $name . '.' : ' liess sich nicht zurueckbenennen.'));
    } elseif ($da === null) {
        return;
    }
    foreach ($reste as $n) {
        list($rc, , ) = asn_docker(array('rm', $n), 30);
        asn_log('Rest eines frueheren Neuanlegens ' . $n . ($rc === 0 ? ' entfernt.' : ' liess sich nicht entfernen.'));
    }
}

/** Eine in diesem Lauf angelegte Datenablage beiseitestellen (<pfad>.gescheitert-<Zeit>, nichts geloescht). */
function asn_daten_beiseite($pfad)
{
    if (!is_dir($pfad)) {
        return '';
    }
    $ziel = $pfad . '.gescheitert-' . date('YmdHis');
    if (@rename($pfad, $ziel)) {
        asn_log('Die Datenablage ' . basename($pfad) . ' aus dem gescheiterten Vorgang liegt jetzt als ' . basename($ziel)
            . ' daneben (nichts geloescht).');
        return $ziel;
    }
    asn_log('Die Datenablage ' . basename($pfad) . ' aus dem gescheiterten Vorgang liess sich nicht beiseitestellen.');
    return '';
}

/**
 * Einen Datenordner kopieren (Hilfscontainer aus dem schon geladenen Abbild, ohne Netz, Quelle nur lesend). Nur in
 * einen LEEREN Zielordner. Rueckgabe array(ok, grund, text).
 */
function asn_daten_kopieren($von, $nach, $bild)
{
    if (!is_dir($von)) {
        return array(false, 'KOPIE_QUELLE|' . basename($von), '');
    }
    // 0.9.2 Nachzug: die Kopie laeuft im Hilfscontainer, der NUR die beiden Datenordner sieht (kein Musik-Bind; der lebt
    // allein im Namensraum des sonn-Containers). Der Einhaengepunkt wird als leerer Ordner mitkopiert - Docker legt ihn
    // beim Anlegen ohnehin an. Zusaetzlich: ist er in der Quelle nicht leer oder ein anderes Dateisystem, wird NICHT
    // kopiert und der Zielordner gar nicht erst angelegt (sonst kaeme fremder Inhalt mit, und der Musikordner wuerde
    // ihn spaeter verdecken).
    $gp = asn_musik_punkt_pruefen($von);
    if ($gp !== '') {
        return array(false, 'KOPIE_MUSIK|' . basename($von) . '/music/local/' . ASN_MUSIK_NAME, '');
    }
    if (!is_dir($nach) && !@mkdir($nach, 0750, true)) {
        return array(false, 'SCHREIBEN', $nach);
    }
    $inhalt = @scandir($nach);
    if (!is_array($inhalt) || count(array_diff($inhalt, array('.', '..'))) > 0) {
        return array(false, 'KOPIE_ZIEL_BELEGT|' . basename($nach), '');
    }
    list($rc, $out, $err) = asn_docker(array('run', '--rm', '--network', 'none',
        '--label', 'de.loxberry.plugin.folder=' . asn_paths()['plugin'],
        '-v', $von . ':/von:ro', '-v', $nach . ':/nach', '--entrypoint', 'cp', $bild, '-a', '/von/.', '/nach/'), ASN_KOPIE_S);
    if ($rc !== 0) {
        return array(false, 'KOPIE|' . $rc, asn_letzte_zeile($err . "\n" . $out));
    }
    $nachher = @scandir($nach);
    if (!is_array($nachher) || count(array_diff($nachher, array('.', '..'))) === 0) {
        return array(false, 'KOPIE_LEER', '');
    }
    $gn = asn_musik_punkt_pruefen($nach);
    if ($gn !== '') {
        // "KOPIE|"-Gruppe: die Aufrufer stellen die neue Ablage beiseite (asn_kopie_halb()).
        return array(false, 'KOPIE_MUSIK_NACHHER|' . basename($nach) . '/music/local/' . ASN_MUSIK_NAME, '');
    }
    return array(true, '', '');
}

/** Hat eine gescheiterte Kopie schon in den Zielordner geschrieben (dann kommt er beiseite)? */
function asn_kopie_halb($g)
{
    return strpos((string) $g, 'KOPIE|') === 0 || strpos((string) $g, 'KOPIE_MUSIK_NACHHER|') === 0;
}

/** Abbild vorhanden? Sonst ziehen (per Digest). Rueckgabe array(ok, grund, text). */
function asn_abbild_holen($bild)
{
    list($rc, , ) = asn_docker(array('image', 'inspect', $bild), 30);
    if ($rc === 0) {
        return array(true, '', '');
    }
    asn_log('Abbild ' . $bild . ' fehlt - wird geladen (rund 330 MB gepackt, einige Minuten).');
    list($rc, $out, $err) = asn_docker(array('pull', $bild), ASN_ZIEHEN_S);
    if ($rc !== 0) {
        return array(false, 'ZIEHEN|' . $rc, asn_letzte_zeile($err . "\n" . $out));
    }
    list($rc2, , ) = asn_docker(array('image', 'inspect', $bild), 30);
    return $rc2 === 0 ? array(true, '', '') : array(false, 'ZIEHEN|0', '');
}

/**
 * Den Container anlegen oder auf die Sollwerte bringen. $wechsel = true: zuerst die Daten des (gestoppten)
 * Probe-Containers in den eigenen, leeren Datenordner kopieren. Rueckgabe array(ok, grund, text, ergebnis).
 * Reihenfolge: Docker -> Reste -> Sollwerte -> fremder Container? -> unveraendert und laeuft? -> Probe laeuft mit derselben
 * IP? -> IP belegt? -> Abbild -> Netz -> Datenordner (Kopie) -> Musik -> alten eigenen Container ANHALTEN UND UMBENENNEN
 * -> anlegen, starten -> nachsehen (laeuft?) -> erst dann den alten entfernen; sonst den neuen entfernen und den alten
 * zurueckholen -> Befund: NUR das macvlan-Netz, IP und MAC.
 * Fremdpruefung 31.10.2026, T2-2 (Vorbild MGiSmart 1.1.29 mg_gw_anlegen/mg_gw_alt_zurueck, Docker NG): bis dahin wurde
 * der alte Container VOR create/start entfernt - scheiterte create oder start (IPAM, Mount, Abbild), war der Audioserver
 * fort, auch beim Update und beim Rueckweg. macvlan: der alte ist angehalten, bevor der neue startet, und er startet erst
 * wieder, wenn der neue nachweislich fort ist - zwei Container mit derselben IP und MAC laufen nie zugleich. Ohne alten
 * Container (erstes Anlegen, Wechsel von der Probe) geht ein gescheiterter neuer wieder weg, und eine in diesem Lauf
 * kopierte Datenablage kommt beiseite (<name>.gescheitert-<Zeit>, nichts geloescht) - ein neuer Versuch ist frei.
 * Bei Fehlschlag steht im vierten Feld der Verbleib des alten Containers (ALT_ZURUECK, ALT_LIEGT|name, ALT_STEHT).
 */
function asn_anlegen($schritt, $wechsel)
{
    $cfg = asn_config();
    if (empty($cfg['container_ein'])) {
        return array(false, 'VERWALTUNG_AUS', '', '');
    }
    $schritt('docker');
    list($lage, $g) = asn_docker_lage();
    if ($lage !== 'ok') {
        return array(false, $g, '', '');
    }
    asn_reste_aufraeumen();
    $schritt('sollwerte');
    $gl = asn_container_pruefen($cfg, true);
    if ($gl) {
        return array(false, $gl[0], '', '');
    }
    list($ok, $g, $def) = asn_soll($cfg);
    if (!$ok) {
        return array(false, $g, '', '');
    }
    list($da, $eigen, $kurz) = asn_container();
    if ($da === null) {
        return array(false, 'DOCKER_FEHLER|0', '', '');
    }
    if ($da && !$eigen) {
        return array(false, 'CONTAINER_FREMD|' . $def['container'], '', '');
    }
    if ($wechsel && $da) {
        return array(false, 'WECHSEL_EIGEN_DA', '', '');
    }
    if ($da && $kurz['def'] === $def['hash'] && $kurz['laeuft']) {
        asn_merk_schreiben(array('angelegt' => 1, 'id' => $kurz['id'], 'def' => $def['hash'], 'netz' => $def['netz'], 'netz_eigen' => $def['netz_eigen']));
        return array(true, '', '', 'UNVERAENDERT');
    }
    list($pda, $pk) = asn_probe();
    if ($pda === true && $pk['laeuft']) {
        foreach ($pk['netze'] as $pn) {
            if ($pn['ip'] === $def['ip'] || $pn['mac'] === $def['mac']) {
                return array(false, 'PROBE_LAEUFT', '', '');
            }
        }
    }
    if ($wechsel && $pda !== true) {
        return array(false, 'PROBE_FEHLT', '', '');
    }
    $schritt('ip');
    $traegt = false;
    if ($da) {
        foreach ($kurz['netze'] as $n) {
            if ($n['ip'] === $def['ip']) {
                $traegt = $kurz['laeuft'];
            }
        }
    }
    if (!$traegt && asn_ip_belegt($def['ip'], $def['schnittstelle'])) {
        return array(false, 'IP_BELEGT|' . $def['ip'], '', '');
    }
    $schritt('abbild');
    list($ok, $g, $t) = asn_abbild_holen($def['bild']);
    if (!$ok) {
        return array(false, $g, $t, '');
    }
    $schritt('netz');
    list($nok, $netze) = asn_macvlan_netze();
    if (!$nok) {
        return array(false, 'DOCKER_FEHLER|0', '', '');
    }
    if ($def['netz_eigen']) {
        if (isset($netze[$def['netz']])) {
            $n = $netze[$def['netz']];
            if (!$n['eigen']) {
                return array(false, 'NETZ_FREMD|' . $def['netz'], '', '');
            }
            if ($n['parent'] !== $def['schnittstelle'] || !asn_ip_im_teilnetz($def['ip'], $n['subnetz'])) {
                return array(false, 'NETZ_PASST_NICHT|' . $def['netz'], '', '');
            }
        } else {
            list($nk, $g, $nn) = asn_netz_lesen($def['schnittstelle']);
            if (!$nk) {
                return array(false, $g, '', '');
            }
            list($rc, , $err) = asn_docker(array('network', 'create', '-d', 'macvlan',
                '--subnet', $nn['netzadresse'] . '/' . $nn['praefix'], '--gateway', $nn['gateway'], '-o', 'parent=' . $def['schnittstelle'],
                '--label', 'de.loxberry.plugin.folder=' . asn_paths()['plugin'], '--label', 'de.loxberry.plugin.name=' . asn_namen()['label'],
                $def['netz']), 30);
            if ($rc !== 0) {
                $e = asn_letzte_zeile($err);
                return array(false, stripos($e, 'overlap') !== false ? 'NETZ_UEBERLAPPT' : 'NETZ_ANLEGEN|' . $rc, $e, '');
            }
        }
    } else {
        if (!isset($netze[$def['netz']])) {
            return array(false, 'NETZ_FEHLT|' . $def['netz'], '', '');
        }
        $n = $netze[$def['netz']];
        if ($n['parent'] !== $def['schnittstelle'] || !asn_ip_im_teilnetz($def['ip'], $n['subnetz'])) {
            return array(false, 'NETZ_PASST_NICHT|' . $def['netz'], '', '');
        }
    }
    $schritt('daten');
    $kopiert = '';
    $sonn = asn_paths()['sonn'];
    if (!is_dir($sonn) && !@mkdir($sonn, 0750, true)) {
        return array(false, 'SCHREIBEN', $sonn, '');
    }
    if ($wechsel) {
        $quelle = isset($pk['mounts']['/app/data']['quelle']) ? $pk['mounts']['/app/data']['quelle'] : '';
        if ($quelle === '') {
            return array(false, 'PROBE_DATEN', '', '');
        }
        list($ok, $g, $t) = asn_daten_kopieren($quelle, $def['daten'], $def['bild']);
        if (!$ok) {
            if (asn_kopie_halb($g)) {
                asn_daten_beiseite($def['daten']);   // halb kopiert: beiseite, ein neuer Versuch ist frei
            }
            return array(false, $g, $t, '');
        }
        $kopiert = $def['daten'];
        asn_log('Wechsel: Daten der Probe nach ' . basename($sonn) . '/' . $def['datenname'] . ' kopiert; die Probe bleibt unveraendert.');
    } elseif (!is_dir($def['daten']) && !@mkdir($def['daten'], 0750, true)) {
        return array(false, 'SCHREIBEN', $def['daten'], '');
    }
    if ($def['musik'] !== '') {
        $gm = asn_musik_pruefen($def['musik'], true);
        if ($gm === '') {
            $gm = asn_musik_punkt_pruefen($def['daten']);   // 0.9.2 Nachzug: nichts verdecken
        }
        if ($gm !== '') {
            if ($kopiert !== '') {
                asn_daten_beiseite($kopiert);
            }
            return array(false, $gm, '', '');
        }
    }
    $schritt('container');
    $alt = '';
    $alt_lief = false;
    if ($da) {
        $alt_lief = (bool) $kurz['laeuft'];
        list($ok, $g, $t, $alt) = asn_alt_beiseite($def['container'], $alt_lief);
        if (!$ok) {
            if ($kopiert !== '') {
                asn_daten_beiseite($kopiert);
            }
            return array(false, $g, $t, 'ALT_UNVERAENDERT');
        }
    }
    $fehler = null;
    list($rc, $out, $err) = asn_docker(asn_create_args($def), 60);
    if ($rc !== 0) {
        $fehler = array('CONTAINER_ANLEGEN|' . $rc, asn_letzte_zeile($err . "\n" . $out));
    } else {
        list($rc, , $err) = asn_docker(array('start', $def['container']), 60);
        if ($rc !== 0) {
            $fehler = array('CONTAINER_STARTEN|' . $rc, asn_letzte_zeile($err));
        }
    }
    $schritt('nachsehen');
    $k2 = null;
    if ($fehler === null) {
        list($da2, $eigen2, $k2) = asn_container();
        if ($da2 !== true || !$eigen2) {
            $fehler = array('CONTAINER_FEHLT', '');
        } elseif (!$k2['laeuft']) {
            $fehler = array('CONTAINER_STEHT', (string) $k2['fehler']);
        }
    }
    if ($fehler !== null) {
        $verbleib = asn_alt_zurueck($def['container'], $alt, $alt_lief, $fehler[0]);
        if ($kopiert !== '') {
            asn_daten_beiseite($kopiert);
        }
        return array(false, $fehler[0], $fehler[1], $verbleib);
    }
    if ($alt !== '') {
        // Erst jetzt, nach gelungenem Start, den alten (angehaltenen) Container entfernen.
        list($rc_r, , $err_r) = asn_docker(array('rm', $alt), 30);
        if ($rc_r !== 0) {
            asn_log('Neu anlegen: der alte Container ' . $alt . ' liess sich nicht entfernen (Rueckgabe ' . $rc_r . ': '
                . asn_kurztext(asn_letzte_zeile($err_r), 160) . ') - er bleibt angehalten liegen und wird beim naechsten Anlegen abgeraeumt.');
        }
    }
    asn_merk_schreiben(array('angelegt' => 1, 'id' => $k2['id'], 'def' => $def['hash'], 'netz' => $def['netz'], 'netz_eigen' => $def['netz_eigen']));
    $gb = asn_container_befund($k2, $def);
    if ($gb !== '') {
        return array(false, $gb, '', '');
    }
    if ($wechsel) {
        $c = asn_config();
        $c['datenordner'] = $def['datenname'];
        asn_config_speichern($c);
    } elseif (!asn_datenordner_gueltig($cfg['datenordner'])) {
        $c = asn_config();
        $c['datenordner'] = $def['datenname'];
        asn_config_speichern($c);
    }
    return array(true, '', '', $wechsel ? 'GEWECHSELT' : 'ANGELEGT');
}

/** Stimmt der Container mit den Sollwerten? '' gut, sonst Grund (laeuft, NUR das macvlan-Netz, IP, MAC, Abbild, Musik). */
function asn_container_befund(array $k, array $def)
{
    if (!$k['laeuft']) {
        return 'CONTAINER_STEHT';
    }
    if (array_keys($k['netze']) !== array($def['netz'])) {
        return 'NETZ_ZUSATZ|' . implode(',', array_keys($k['netze']));
    }
    $n = $k['netze'][$def['netz']];
    if ($n['ip'] !== $def['ip']) {
        return 'IP_ANDERS';
    }
    if ($n['mac'] !== strtolower($def['mac'])) {
        return 'MAC_ANDERS';
    }
    if ($k['bild'] !== $def['bild']) {
        return 'ABBILD_ANDERS';
    }
    if ($def['musik'] !== '' && (!isset($k['mounts'][ASN_MUSIK_ZIEL]) || $k['mounts'][ASN_MUSIK_ZIEL]['rw'])) {
        return 'MUSIK_SCHREIBBAR';
    }
    return '';
}

/** Starten, stoppen, neu starten - nur der eigene Container. */
function asn_schalten($was)
{
    list($lage, $g) = asn_docker_lage();
    if ($lage !== 'ok') {
        return array(false, $g, '', '');
    }
    $name = asn_namen()['container'];
    list($da, $eigen, $kurz) = asn_container();
    if ($da !== true) {
        return array(false, $da === false ? 'CONTAINER_FEHLT' : 'DOCKER_FEHLER|0', '', '');
    }
    if (!$eigen) {
        return array(false, 'CONTAINER_FREMD|' . $name, '', '');
    }
    if ($was === 'starten') {
        list($pda, $pk) = asn_probe();
        if ($pda === true && $pk['laeuft']) {
            return array(false, 'PROBE_LAEUFT', '', '');
        }
    }
    $args = $was === 'starten' ? array('start', $name) : ($was === 'stoppen' ? array('stop', '-t', '20', $name) : array('restart', '-t', '20', $name));
    list($rc, , $err) = asn_docker($args, 90);
    if ($rc !== 0) {
        return array(false, 'SCHALTEN|' . $rc, asn_letzte_zeile($err), '');
    }
    list(, , $k2) = asn_container();
    $laeuft = is_array($k2) && $k2['laeuft'];
    if (($was === 'stoppen') === $laeuft) {
        return array(false, 'SCHALTEN|0', '', '');
    }
    return array(true, '', '', $was === 'starten' ? 'GESTARTET' : ($was === 'stoppen' ? 'GESTOPPT' : 'NEU_GESTARTET'));
}

/**
 * Update auf einen neuen Digest (cfg 'update_ziel' wird von der Oberflaeche nach dem Ja gesetzt): ziehen, Datenordner
 * KOPIEREN (alter bleibt), Container neu. Rueckgabe array(ok, grund, text, ergebnis).
 */
function asn_update($schritt)
{
    $cfg = asn_config();
    $ziel = asn_json_lesen(asn_paths()['datadir'] . '/update_ziel.json');
    $neu = isset($ziel['digest']) ? (string) $ziel['digest'] : '';
    if (!asn_digest_gueltig($neu) || !isset($ziel['zeit']) || abs(time() - (int) $ziel['zeit']) > 600) {
        return array(false, 'UPDATE_ZIEL', '', '');
    }
    $alt = asn_abbild_digest($cfg);
    if ($neu === $alt) {
        return array(true, '', '', 'UNVERAENDERT');
    }
    list($lage, $g) = asn_docker_lage();
    if ($lage !== 'ok') {
        return array(false, $g, '', '');
    }
    $schritt('abbild');
    list($ok, $g, $t) = asn_abbild_holen(asn_abbild_ref($neu));
    if (!$ok) {
        return array(false, $g, $t, '');
    }
    $schritt('daten');
    $alt_name = asn_datenordner_gueltig($cfg['datenordner']) ? $cfg['datenordner'] : 'daten_' . asn_kurzdigest($alt);
    $neu_name = 'daten_' . asn_kurzdigest($neu);
    list($ok, $g, $t) = asn_daten_kopieren(asn_datenordner_pfad($alt_name), asn_datenordner_pfad($neu_name), asn_abbild_ref($neu));
    if (!$ok) {
        if (asn_kopie_halb($g)) {
            asn_daten_beiseite(asn_datenordner_pfad($neu_name));   // halb kopiert: beiseite, ein neuer Versuch ist frei
        }
        return array(false, $g, $t, '');
    }
    $bisher = $cfg;
    $cfg['abbild_vorher'] = $alt;
    $cfg['datenordner_vorher'] = $alt_name;
    $cfg['abbild'] = $neu;
    $cfg['datenordner'] = $neu_name;
    if (!asn_config_speichern($cfg)) {
        return array(false, 'SCHREIBEN', '', '');
    }
    @unlink(asn_paths()['datadir'] . '/update_ziel.json');
    $r = asn_anlegen($schritt, false);
    if ($r[0]) {
        return array(true, '', '', 'AKTUALISIERT');
    }
    // Fremdpruefung 31.10.2026, T2-2: gescheitert - die Einstellungen zeigen wieder auf Abbild und Datenordner des
    // bisherigen Containers (er ist zurueck oder liegt bereit); die neue Kopie kommt beiseite (ein neuer Versuch ist frei).
    asn_einstellungen_zurueck($bisher);
    asn_daten_beiseite(asn_datenordner_pfad($neu_name));
    asn_log('Update gescheitert - Abbild und Datenordner wieder wie vorher (' . asn_kurzdigest($alt) . ', ' . $alt_name . ').');
    return $r;
}

/** Abbild und Datenordner (samt vorher) auf einen frueheren Stand zuruecksetzen - die anderen Einstellungen bleiben. */
function asn_einstellungen_zurueck(array $bisher)
{
    $c = asn_config();
    foreach (array('abbild', 'datenordner', 'abbild_vorher', 'datenordner_vorher') as $k) {
        $c[$k] = $bisher[$k];
    }
    return asn_config_speichern($c);
}

/** Rueckweg: vorheriger Digest UND vorheriger Datenordner (die beide unveraendert liegen). */
function asn_rueckweg($schritt)
{
    $cfg = asn_config();
    if (!asn_digest_gueltig($cfg['abbild_vorher']) || !asn_datenordner_gueltig($cfg['datenordner_vorher'])) {
        return array(false, 'RUECKWEG_FEHLT', '', '');
    }
    if (!is_dir(asn_datenordner_pfad($cfg['datenordner_vorher']))) {
        return array(false, 'KOPIE_QUELLE|' . $cfg['datenordner_vorher'], '', '');
    }
    $bisher = $cfg;
    $a = array($cfg['abbild'], $cfg['datenordner']);
    $cfg['abbild'] = $cfg['abbild_vorher'];
    $cfg['datenordner'] = $cfg['datenordner_vorher'];
    $cfg['abbild_vorher'] = asn_digest_gueltig($a[0]) ? $a[0] : asn_abbild_vorgabe()['digest'];
    $cfg['datenordner_vorher'] = $a[1];
    if (!asn_config_speichern($cfg)) {
        return array(false, 'SCHREIBEN', '', '');
    }
    $r = asn_anlegen($schritt, false);
    if ($r[0]) {
        return array(true, '', '', 'ZURUECK');
    }
    asn_einstellungen_zurueck($bisher);   // T2-2: gescheitert - Einstellungen wieder wie vor dem Rueckweg
    return $r;
}

/** Den Probe-Container entfernen (nur gestoppt, nur mit Label, ohne -f; Daten, Abbild, Netz bleiben). */
function asn_probe_entfernen()
{
    list($lage, $g) = asn_docker_lage();
    if ($lage !== 'ok') {
        return array(false, $g, '', '');
    }
    list($pda, $pk) = asn_probe();
    if ($pda !== true) {
        return array(false, $pda === false ? 'PROBE_FEHLT' : 'DOCKER_FEHLER|0', '', '');
    }
    if ($pk['laeuft']) {
        return array(false, 'PROBE_LAEUFT', '', '');
    }
    list($rc, , $err) = asn_docker(array('rm', ASN_PROBE_NAME), 30);
    list($da, ) = asn_inspect_container(ASN_PROBE_NAME);
    if ($da !== false) {
        return array(false, 'CONTAINER_ENTFERNEN|' . $rc, asn_letzte_zeile($err), '');
    }
    return array(true, '', '', 'PROBE_ENTFERNT');
}

/** Bibliothek neu einlesen (sonn-Konto noetig). */
function asn_bibliothek()
{
    $cfg = asn_config(false);
    list($da, $eigen, $kurz) = asn_container();
    if ($da !== true || !$eigen) {
        return array(false, $da === false ? 'CONTAINER_FEHLT' : ($da === null ? 'DOCKER_FEHLER|0' : 'CONTAINER_FREMD|' . asn_namen()['container']), '', '');
    }
    if (!$kurz['laeuft']) {
        return array(false, 'CONTAINER_STEHT', '', '');
    }
    list($ok, $g, $d) = asn_sonn_fragen(asn_namen()['container'], 'rescan', $cfg);
    if (!$ok) {
        return array(false, $g !== '' ? $g : 'ABFRAGE_ANTWORT|0', is_array($d) && isset($d['text']) ? (string) $d['text'] : '', '');
    }
    return array(true, '', '', 'BIBLIOTHEK');
}

/* ==================================================================
 * Favoriten (0.9.2, Wunsch Hausherr 01.11.2026): eine Liste "Nr = Art | Name | Quelle" wie Chromecast4lox 1.3.25
 * (Reiter Audio, Quellen). Die Nummer ist die Kennung des Zonenfavoriten in sonn = Eingang Fav des Audio Players in
 * Loxone (playZoneFav/<Nr>). Uebernommen wird nur auf Knopfdruck mit Ja-Haken im Hintergrundvorgang "favoriten",
 * ausschliesslich ueber die oeffentliche API /api/v1 von sonn (bin/asn_abfrage.js) - keine Datei von sonn.
 * ================================================================== */

/**
 * Ein Pfad im Musikordner: relativ, "/" getrennt, ohne ".."; ein vorangestelltes "/musik/" (Schreibweise bis 0.9.2) oder
 * der Ort im Container (ASN_MUSIK_ZIEL/) wird abgenommen. null = abgewiesen.
 */
function asn_fav_pfad($s)
{
    $t = str_replace('\\', '/', trim((string) $s));
    foreach (array(ASN_MUSIK_ALT . '/', ASN_MUSIK_ZIEL . '/') as $vor) {
        if (strpos($t, $vor) === 0) {
            $t = substr($t, strlen($vor));
            break;
        }
    }
    if ($t === '' || $t[0] === '/' || preg_match('/[\x00-\x1F\x7F]/', $t) || preg_match('/^[A-Za-z]:/', $t)
        || preg_match('#^[a-z][a-z0-9+.\-]*://#i', $t) || strlen($t) > 400) {
        return null;
    }
    $teile = array();
    foreach (explode('/', $t) as $x) {
        if ($x === '' || $x === '.') {
            continue;
        }
        if ($x === '..') {
            return null;
        }
        $teile[] = $x;
    }
    return $teile ? implode('/', $teile) : null;
}

/** Eine Stream-Adresse: http oder https, ohne Leer- und Steuerzeichen, hoechstens 400 Zeichen. */
function asn_fav_adresse($s)
{
    $s = (string) $s;
    return strlen($s) <= 400 && preg_match('#^https?://[^\s/?\#]+[^\s]*\z#i', $s) === 1 && !preg_match('/[\x00-\x1F\x7F]/', $s);
}

/**
 * Die Favoritenliste lesen: je Zeile "Nr = Art | Name | Quelle"; leere Zeilen und Zeilen mit # zaehlen nicht.
 * Art radio (http/https), liste (Ordner im Musikordner oder eine Wiedergabeliste von sonn) oder datei (Datei im
 * Musikordner oder http/https). Rueckgabe array(Liste nr => array(nr, art, name, quelle), Beanstandungen[]).
 */
function asn_favoriten_lesen($text)
{
    $aus = array();
    $fehler = array();
    foreach (preg_split('/[\r\n]+/', (string) $text) as $z) {
        $z = trim($z);
        if ($z === '' || $z[0] === '#') {
            continue;
        }
        $teile = array();
        $nr = null;
        if (strpos($z, '=') !== false) {
            list($nr_t, $rest) = explode('=', $z, 2);
            $nr_t = trim($nr_t);
            if (preg_match('/^[0-9]{1,2}\z/', $nr_t) && (int) $nr_t >= 1 && (int) $nr_t <= ASN_FAV_MAX) {
                $nr = (int) $nr_t;
            }
            $teile = array_map('trim', explode('|', $rest, 3));
        }
        if ($nr === null || count($teile) !== 3 || !in_array(strtolower($teile[0]), array('radio', 'liste', 'datei'), true)
            || $teile[2] === '') {
            $fehler[] = sprintf(asn_t('FAV.F_FORM'), asn_kurztext($z, 60), ASN_FAV_MAX);
            continue;
        }
        $art = strtolower($teile[0]);
        $name = $teile[1];
        $q = $teile[2];
        if (preg_match('/[\x00-\x1F\x7F]/', $name) || preg_match_all('/./us', $name) > 80
            || preg_match('//u', $name . $q) !== 1) {
            $fehler[] = sprintf(asn_t('FAV.F_NAME'), $nr);
            continue;
        }
        $web = preg_match('#^https?://#i', $q) === 1;
        if ($art === 'radio') {
            $ok = asn_fav_adresse($q);
        } elseif ($art === 'liste') {
            $ok = !$web && asn_fav_pfad($q) !== null;
            $q = $ok ? asn_fav_pfad($q) : $q;
        } else {
            $ok = $web ? asn_fav_adresse($q) : asn_fav_pfad($q) !== null;
            $q = (!$web && $ok) ? asn_fav_pfad($q) : $q;
        }
        if (!$ok) {
            $fehler[] = sprintf(asn_t('FAV.F_QUELLE'), $nr);
            continue;
        }
        if (isset($aus[$nr])) {
            $fehler[] = sprintf(asn_t('FAV.F_DOPPELT'), $nr);
            continue;
        }
        $aus[$nr] = array('nr' => $nr, 'art' => $art, 'name' => $name !== '' ? $name : asn_kurztext($q, 80), 'quelle' => $q);
    }
    ksort($aus);
    return array($aus, $fehler);
}

/** Die Zonen fuer die Favoriten: "" = alle Zonen von sonn, sonst Nummern 1 bis 24 (Komma, Strichpunkt oder Leerzeichen). */
function asn_favoriten_zonen_lesen($text)
{
    $t = trim((string) $text);
    if ($t === '') {
        return array(array(), array());
    }
    $aus = array();
    foreach (preg_split('/[\s,;]+/', $t) as $x) {
        if ($x === '') {
            continue;
        }
        if (!preg_match('/^[0-9]{1,2}\z/', $x) || (int) $x < 1 || (int) $x > ASN_ZONEN_MAX) {
            return array(array(), array(sprintf(asn_t('FAV.F_ZONEN'), ASN_ZONEN_MAX)));
        }
        if (!in_array((int) $x, $aus, true)) {
            $aus[] = (int) $x;
        }
    }
    sort($aus);
    return array($aus, array());
}

/** Die Liste als Text (gespeicherte Form: eine Zeile je Eintrag, sortiert). */
function asn_favoriten_text(array $liste)
{
    $z = array();
    foreach ($liste as $e) {
        $z[] = $e['nr'] . ' = ' . $e['art'] . ' | ' . $e['name'] . ' | ' . $e['quelle'];
    }
    return implode("\n", $z);
}

/** Der Stand des letzten Uebernehmens (data/plugins/<ordner>/favoriten_stand.json) - nur Nummern und Namen. */
function asn_favoriten_stand()
{
    $d = asn_json_lesen(asn_paths()['datadir'] . '/favoriten_stand.json');
    if (!isset($d['zeit']) || !is_int($d['zeit'])) {
        return null;
    }
    $zonen = array();
    foreach (isset($d['zonen']) && is_array($d['zonen']) ? $d['zonen'] : array() as $z) {
        if (!is_array($z) || !isset($z['zone']) || !is_int($z['zone'])) {
            continue;
        }
        $n = array();
        foreach (isset($z['nachher']) && is_array($z['nachher']) ? $z['nachher'] : array() as $x) {
            if (is_array($x) && isset($x['id']) && is_int($x['id'])) {
                $n[] = array('id' => $x['id'], 'name' => isset($x['name']) && is_string($x['name']) ? $x['name'] : '');
            }
        }
        $zonen[] = array('zone' => $z['zone'], 'gesetzt' => isset($z['gesetzt']) && is_int($z['gesetzt']) ? $z['gesetzt'] : 0, 'nachher' => $n);
    }
    $ueb = array();
    foreach (isset($d['uebersprungen']) && is_array($d['uebersprungen']) ? $d['uebersprungen'] : array() as $u) {
        if (is_array($u) && isset($u['nr']) && is_int($u['nr'])) {
            $ueb[] = array('nr' => $u['nr'], 'grund' => isset($u['grund']) && is_string($u['grund']) ? $u['grund'] : '');
        }
    }
    return array('zeit' => $d['zeit'], 'ok' => !empty($d['ok']), 'grund' => isset($d['grund']) && is_string($d['grund']) ? $d['grund'] : '',
                 'zonen' => $zonen, 'uebersprungen' => $ueb,
                 'vorher' => isset($d['vorher']) && is_string($d['vorher']) ? $d['vorher'] : '',
                 'custom' => isset($d['custom']) && is_array($d['custom']) ? $d['custom'] : null);
}

/** 0.9.3: der Satz zu den Custom Streams ('' = kein Radio-Eintrag). $klar: ohne HTML, fuer Vorgangstext und Protokoll. */
function asn_custom_satz($c, $klar)
{
    if (!is_array($c)) {
        return '';
    }
    $t = function ($s) use ($klar) {
        return $klar ? asn_klartext($s) : asn_t($s);
    };
    $f = isset($c['fehler']) && is_string($c['fehler']) ? $c['fehler'] : '';
    if ($f === 'KONTO_FEHLT' || $f === 'KONTO_FALSCH') {
        return $t('FAV.T_CUSTOM_KONTO');
    }
    $s = sprintf($t('FAV.T_CUSTOM'), isset($c['angelegt']) ? (int) $c['angelegt'] : 0, isset($c['vorhanden']) ? (int) $c['vorhanden'] : 0);
    return $f !== '' ? $s . ' ' . sprintf($t('FAV.T_CUSTOM_FEHLER'), str_replace('|', ' ', $f)) : $s;
}

/**
 * Hintergrundvorgang "favoriten": die gespeicherte Liste in die Zonen von sonn uebernehmen (ersetzt deren
 * Favoriten). Die bisherigen Favoriten jeder Zone landen vorher als Kopie in data/plugins/<ordner>/
 * favoriten_vorher_<Zeit>.json (0600). Rueckgabe wie asn_bibliothek(): array(ok, grund, text, ergebnis).
 */
function asn_favoriten_uebernehmen()
{
    $cfg = asn_config(false);
    list($da, $eigen, $kurz) = asn_container();
    if ($da !== true || !$eigen) {
        return array(false, $da === false ? 'CONTAINER_FEHLT' : ($da === null ? 'DOCKER_FEHLER|0' : 'CONTAINER_FREMD|' . asn_namen()['container']), '', '');
    }
    if (!$kurz['laeuft']) {
        return array(false, 'CONTAINER_STEHT', '', '');
    }
    list($ok, $g, $d) = asn_sonn_fragen(asn_namen()['container'], 'favoriten', $cfg);
    $p = asn_paths();
    $vorher = '';
    $zonen = array();
    if (is_array($d) && isset($d['zonen']) && is_array($d['zonen'])) {
        $kopie = array();
        foreach ($d['zonen'] as $z) {
            if (!is_array($z) || !isset($z['zone']) || !is_int($z['zone'])) {
                continue;
            }
            if (isset($z['vorher']) && is_array($z['vorher']) && $z['vorher']) {
                $kopie[(string) $z['zone']] = $z['vorher'];
            }
            $zonen[] = array('zone' => $z['zone'], 'gesetzt' => isset($z['gesetzt']) && is_int($z['gesetzt']) ? $z['gesetzt'] : 0,
                             'nachher' => isset($z['nachher']) && is_array($z['nachher']) ? $z['nachher'] : array());
        }
        if ($kopie) {
            $vorher = 'favoriten_vorher_' . date('Ymd_His') . '.json';
            if (!asn_json_schreiben($p['datadir'] . '/' . $vorher, array('zeit' => time(), 'zonen' => $kopie), 0600)) {
                $vorher = '';
                asn_log('Favoriten: die Kopie der bisherigen Favoriten liess sich nicht schreiben.');
            }
        }
    }
    $ueb = is_array($d) && isset($d['uebersprungen']) && is_array($d['uebersprungen']) ? $d['uebersprungen'] : array();
    asn_json_schreiben($p['datadir'] . '/favoriten_stand.json', array('zeit' => time(), 'ok' => $ok, 'grund' => (string) $g,
        'zonen' => $zonen, 'uebersprungen' => $ueb, 'vorher' => $vorher,
        'custom' => is_array($d) && isset($d['custom']) && is_array($d['custom']) ? $d['custom'] : null), 0600);
    $teile = array();
    foreach ($zonen as $z) {
        $teile[] = sprintf(asn_klartext('FAV.T_ZONE'), $z['zone'], $z['gesetzt']);
    }
    $nrn = array();
    foreach ($ueb as $u) {
        if (is_array($u) && isset($u['nr']) && is_int($u['nr'])) {
            $nrn[] = $u['nr'];
        }
    }
    if ($nrn) {
        $teile[] = sprintf(asn_klartext('FAV.T_UEBERSPRUNGEN'), implode(', ', $nrn));
    }
    $satz = asn_custom_satz(is_array($d) && isset($d['custom']) ? $d['custom'] : null, true);
    if ($satz !== '') {
        $teile[] = $satz;
    }
    $text = implode('; ', $teile);
    if (!$ok) {
        return array(false, $g !== '' ? $g : 'ABFRAGE_ANTWORT|0',
            $text !== '' ? $text : (is_array($d) && isset($d['text']) ? (string) $d['text'] : ''), '');
    }
    return array(true, '', $text, 'FAVORITEN');
}

/* ==================================================================
 * TuneIn-Vorgabesender (0.9.3, Wunsch Hausherr 01.11.2026). Ab Werk aus. Die Datei liegt in der beschreibbaren Schicht des Containers und ist nach jedem
 * Neuanlegen wieder im Herstellerzustand; deshalb prueft der Takt die Lage jede Minute und holt die Einstellung nach (ueber den Vorgang "tunein", nie waehrend
 * einer Wiedergabe). Geaendert wird im Container (bin/asn_abfrage.js, Auftrag tunein): nur der erkannte Block. Eine andere Herstellerzeile wird gemeldet, nicht ersetzt.
 * Antwortet sonn nach der Aenderung nicht, spielt der Vorgang die Sicherung zurueck und versucht es bei dieser Datei nicht wieder.
 * ================================================================== */
define('ASN_TUNEIN_DATEI', '/app/dist/adapters/content/providers/tunein/tuneinProvider.js');
define('ASN_TUNEIN_WARTEN_S', 120);           // so lange darf sonn nach dem Neustart brauchen
define('ASN_TUNEIN_PAUSE_S', 3600);           // der Takt versucht es bei derselben Datei hoechstens einmal je Stunde

/** Die Lage aus einer Antwort von sonn: array(lage, sha, hand) mit nur bekannten Werten. */
function asn_tunein_normieren($t)
{
    $lage = is_array($t) && isset($t['lage']) && in_array($t['lage'], array('sichtbar', 'ausgeblendet', 'unbekannt', 'fehlt'), true) ? $t['lage'] : '';
    $sha = is_array($t) && isset($t['sha']) && is_string($t['sha']) && preg_match('/^[0-9a-f]{16}\z/', $t['sha']) === 1 ? $t['sha'] : '';
    return array('lage' => $lage, 'sha' => $sha, 'hand' => is_array($t) && !empty($t['hand']) ? 1 : 0);
}

/** Die Lage der letzten Minute (Takt). lage '' = kein Stand. */
function asn_tunein_stand()
{
    $z = asn_zustand();
    return asn_tunein_normieren(is_array($z['sonn']) && isset($z['sonn']['tunein']) ? $z['sonn']['tunein'] : null);
}

function asn_tunein_merk()
{
    $d = asn_json_lesen(asn_paths()['datadir'] . '/tunein.json');
    return array('versuch' => isset($d['versuch']) && is_int($d['versuch']) ? $d['versuch'] : 0,
                 'sha' => isset($d['sha']) && is_string($d['sha']) ? $d['sha'] : '',
                 'gesperrt' => isset($d['gesperrt']) && is_array($d['gesperrt']) ? array_values(array_filter($d['gesperrt'], 'is_string')) : array(),
                 'hand' => !empty($d['hand']) ? 1 : 0);
}

function asn_tunein_merk_schreiben(array $m)
{
    return asn_json_schreiben(asn_paths()['datadir'] . '/tunein.json', $m, 0600);
}

/**
 * Die Datei vor jeder Aenderung auch auf den LoxBerry holen (data/plugins/<ordner>/sicherung_tunein/, wie am 01.11.2026 von Hand): nur von dort laesst sie sich
 * zurueckspielen, wenn sonn nicht mehr startet und "docker exec" nicht mehr geht. Rueckgabe der Pfad, '' = nicht gelungen (dann wird nichts geaendert).
 */
function asn_tunein_sichern($name, $sha)
{
    $ordner = asn_paths()['datadir'] . '/sicherung_tunein';
    $f = $ordner . '/tunein_vorher_' . $sha . '.js';
    if (is_file($f) && substr((string) hash_file('sha256', $f), 0, 16) === $sha) {
        return $f;
    }
    if (!is_dir($ordner) && !@mkdir($ordner, 0755, true)) {
        return '';
    }
    list($rc, $inhalt) = asn_docker(array('exec', $name, 'cat', ASN_TUNEIN_DATEI), ASN_ABFRAGE_S);
    if ((int) $rc !== 0 || substr(hash('sha256', (string) $inhalt), 0, 16) !== $sha) {
        return '';
    }
    $neu = $f . '.neu';
    if (@file_put_contents($neu, $inhalt) !== strlen($inhalt) || !@rename($neu, $f)) {
        return '';
    }
    @chmod($f, 0644);
    return substr((string) hash_file('sha256', $f), 0, 16) === $sha ? $f : '';
}

/** Warten, bis sonn nach einem Neustart wieder antwortet und die Datei so dasteht wie erwartet. */
function asn_tunein_warten($name, $erwartet)
{
    $frist = getenv('ASN_TUNEIN_WARTEN_ATTRAPPE') !== false ? (int) getenv('ASN_TUNEIN_WARTEN_ATTRAPPE') : ASN_TUNEIN_WARTEN_S;
    $ende = time() + $frist;
    do {
        list($ok, , $d) = asn_sonn_fragen($name, 'stand');
        if ($ok && is_array($d) && isset($d['zonen_code']) && (int) $d['zonen_code'] === 200
            && asn_tunein_normieren(isset($d['tunein']) ? $d['tunein'] : null)['lage'] === $erwartet) {
            return true;
        }
        if (time() >= $ende) {
            return false;
        }
        sleep(3);
    } while (true);
}

/** Vorgang "tunein": die Datei auf die Einstellung bringen. Rueckgabe wie asn_bibliothek(): array(ok, grund, text, ergebnis). */
function asn_tunein_vorgang($schritt)
{
    $cfg = asn_config(false);
    $ausblenden = !empty($cfg['tunein_ausblenden']);
    $name = asn_namen()['container'];
    list($da, $eigen, $kurz) = asn_container();
    if ($da !== true || !$eigen) {
        return array(false, $da === false ? 'CONTAINER_FEHLT' : ($da === null ? 'DOCKER_FEHLER|0' : 'CONTAINER_FREMD|' . $name), '', '');
    }
    if (!$kurz['laeuft']) {
        return array(false, 'CONTAINER_STEHT', '', '');
    }
    $schritt('lesen');
    list($ok, $g, $d) = asn_sonn_fragen($name, 'tunein', array('ziel' => 'lesen'));
    if (!$ok || !is_array($d)) {
        return array(false, $g !== '' ? $g : 'ABFRAGE_ANTWORT|0', is_array($d) && isset($d['text']) ? (string) $d['text'] : '', '');
    }
    $t = asn_tunein_normieren(isset($d['tunein']) ? $d['tunein'] : null);
    $soll = $ausblenden ? 'ausgeblendet' : 'sichtbar';
    if ($t['lage'] === $soll) {
        return array(true, '', '', 'TUNEIN_UNVERAENDERT');
    }
    if ($t['lage'] !== 'sichtbar' && $t['lage'] !== 'ausgeblendet') {
        asn_log('TuneIn-Vorgaben: Herstellerdatei nicht erkannt (Lage ' . ($t['lage'] !== '' ? $t['lage'] : '?') . ', Summe ' . $t['sha'] . ') - nichts geaendert, sonn nicht neu gestartet.');
        return array(false, $t['lage'] === 'fehlt' ? 'TUNEIN_FEHLT' : 'TUNEIN_UNBEKANNT', '', '');
    }
    $m = asn_tunein_merk();
    if ($ausblenden && in_array($t['sha'], $m['gesperrt'], true)) {
        return array(false, 'TUNEIN_GESPERRT', '', '');
    }
    $m['versuch'] = time();
    $m['sha'] = $t['sha'];
    asn_tunein_merk_schreiben($m);
    $schritt('sichern');
    $sich = asn_tunein_sichern($name, $t['sha']);
    if ($sich === '') {
        return array(false, 'TUNEIN_SICHERUNG', '', '');
    }
    $schritt('aendern');
    list($ok, $g, $d) = asn_sonn_fragen($name, 'tunein', array('ziel' => $ausblenden ? 'ausblenden' : 'zeigen'));
    if (!$ok) {
        // Im Container ist dann nichts umgestellt (der Auftrag schreibt zuletzt und unteilbar); sonn wurde nicht neu gestartet.
        return array(false, $g !== '' ? $g : 'ABFRAGE_ANTWORT|0', is_array($d) && isset($d['text']) ? (string) $d['text'] : '', '');
    }
    $schritt('neustart');
    $r = asn_schalten('neustart');
    $schritt('nachsehen');
    if ($r[0] && asn_tunein_warten($name, $soll)) {
        asn_log('TuneIn-Vorgaben: ' . ($ausblenden ? 'ausgeblendet' : 'wieder sichtbar') . ', sonn antwortet nach dem Neustart. Sicherung: ' . basename($sich));
        return array(true, '', '', $ausblenden ? 'TUNEIN_AUS' : 'TUNEIN_AN');
    }
    // sonn antwortet nicht: die Sicherung zurueckspielen. Geht auch, wenn der Container nicht mehr hochkommt (docker cp braucht keinen laufenden Prozess).
    $schritt('zurueck');
    if ($ausblenden) {
        $m['gesperrt'][] = $t['sha'];
        asn_tunein_merk_schreiben($m);
    }
    list($rc, , $err) = asn_docker(array('cp', $sich, $name . ':' . ASN_TUNEIN_DATEI), 60);
    $r2 = asn_schalten('neustart');
    $gut = (int) $rc === 0 && $r2[0] && asn_tunein_warten($name, $t['lage']);
    asn_log('TuneIn-Vorgaben: sonn hat nach der Aenderung nicht geantwortet. Sicherung ' . basename($sich) . ' zurueckgespielt (Rueckgabe ' . (int) $rc . '), sonn '
        . ($gut ? 'antwortet wieder.' : 'antwortet weiterhin nicht: ' . asn_kurztext(asn_letzte_zeile($err), 160)));
    return array(false, $gut ? 'TUNEIN_ZURUECK' : 'TUNEIN_ZURUECK_FEHL|' . basename($sich), '', '');
}

/** Aus dem Takt (jede Minute, sonn hat geantwortet): die Lage melden und die Einstellung nachholen. Aendert selbst nichts, startet nur den Vorgang. */
function asn_tunein_takt(array $cfg, array $t, array $zonen)
{
    if ($t['lage'] === '') {
        return;
    }
    $m = asn_tunein_merk();
    /* Die Aenderung von Hand vom 01.11.2026 einmal als Einstellung uebernehmen: sie soll ein Neuanlegen ueberstehen. Danach gilt allein, was im Formular steht. */
    if (empty($cfg['tunein_ausblenden']) && $t['lage'] === 'ausgeblendet' && $t['hand'] && !$m['hand']) {
        $c2 = asn_config(false);
        $c2['tunein_ausblenden'] = 1;
        $m['hand'] = 1;
        asn_tunein_merk_schreiben($m);
        if (asn_config_lage() !== 'kaputt' && asn_config_speichern($c2)) {
            asn_log('TuneIn-Vorgaben: von Hand ausgeblendet vorgefunden - die Einstellung "ausblenden" ist jetzt gesetzt, damit es ein Neuanlegen des Containers uebersteht.');
        }
        return;
    }
    if (empty($cfg['tunein_ausblenden'])) {
        asn_log_wenn_neu('tunein', 'ok', 'TuneIn-Vorgaben: Einstellung aus.');
        return;
    }
    if ($t['lage'] === 'unbekannt' || $t['lage'] === 'fehlt') {
        asn_log_wenn_neu('tunein', $t['lage'] . $t['sha'], 'TuneIn-Vorgaben: Herstellerdatei nicht erkannt (Lage ' . $t['lage'] . ', Summe ' . $t['sha']
            . ') - nichts geaendert, sonn laeuft unveraendert. Die Vorgabesender bleiben sichtbar, bis das Plugin diese Fassung kennt.');
        return;
    }
    if ($t['lage'] !== 'sichtbar') {
        asn_log_wenn_neu('tunein', 'ok', 'TuneIn-Vorgaben: ausgeblendet.');
        return;
    }
    if (in_array($t['sha'], $m['gesperrt'], true)) {
        asn_log_wenn_neu('tunein', 'gesperrt' . $t['sha'], 'TuneIn-Vorgaben: bei dieser Herstellerdatei nach einem Fehlschlag gesperrt - kein neuer Versuch von selbst.');
        return;
    }
    foreach ($zonen as $w) {
        if (is_array($w) && isset($w['zustand']) && (int) $w['zustand'] === 2) {
            return;     // nie waehrend einer Wiedergabe neu starten: naechste Minute wieder
        }
    }
    if ($m['sha'] === $t['sha'] && time() - $m['versuch'] < ASN_TUNEIN_PAUSE_S) {
        return;
    }
    list($ok, $g) = asn_vorgang_starten('tunein');
    if ($ok) {
        asn_log('TuneIn-Vorgaben: Herstellerzustand vorgefunden (Container neu angelegt?) - Vorgang gestartet, sonn startet einmal neu.');
    } elseif (strpos((string) $g, 'VORGANG_LAEUFT') !== 0) {
        asn_log_gebremst('tunein_start', 'TuneIn-Vorgaben: Vorgang liess sich nicht starten (' . asn_grund_text($g) . ').');
    }
}

/* ==================================================================
 * Befehle (Befehlsbruecke 1.0.2 und Endpunkt; EINE Stelle)
 * ================================================================== */

/** Die Befehle: Art, eingreifend, Sperre, Kachel. */
function asn_befehle()
{
    return array(
        'neustart'   => array('art' => 'puls', 'eingreifend' => true, 'sperre_s' => 300, 'kachel' => 'BEFEHL.K_NEUSTART', 'vorgang' => 'neustart'),
        'bibliothek' => array('art' => 'puls', 'eingreifend' => false, 'sperre_s' => 600, 'kachel' => 'BEFEHL.K_BIBLIOTHEK', 'vorgang' => 'bibliothek'),
    );
}

/**
 * Einen Befehl annehmen (Bruecke und Endpunkt). Beide starten den Hintergrundvorgang - die Quittung heisst damit
 * "uebergeben" (die Wirkung zeigt der Vorgang bzw. der Zustand). Rueckgabe array(ok 0|1, grund, http).
 */
function asn_befehl_annehmen($cmd, $wert, $cfg, $quelle)
{
    $b = asn_befehle();
    if (!isset($b[$cmd])) {
        return array('ok' => 0, 'grund' => 'BEFEHL_UNBEKANNT', 'http' => 400);
    }
    if ((string) $wert !== '1') {
        return array('ok' => 0, 'grund' => 'WERT', 'http' => 400);
    }
    if ($b[$cmd]['eingreifend'] && empty($cfg['steuerung_ein'])) {
        return array('ok' => 0, 'grund' => 'STEUERUNG_AUS', 'http' => 403);
    }
    if (!asn_merk()['angelegt']) {
        return array('ok' => 0, 'grund' => 'CONTAINER_FEHLT', 'http' => 409);
    }
    list($ok, $g) = asn_vorgang_starten($b[$cmd]['vorgang']);
    asn_log('Befehl ' . $cmd . ' (' . $quelle . '): ' . ($ok ? 'Vorgang gestartet' : 'abgelehnt - ' . asn_grund_text($g)));
    return $ok ? array('ok' => 1, 'grund' => '', 'http' => 200) : array('ok' => 0, 'grund' => $g, 'http' => 409);
}

function asn_befehl_ergebnis(array $r)
{
    return array((int) $r['ok'] === 1, (int) $r['ok'] === 1 ? '' : (string) $r['grund']);
}

function asn_befehl_zeile(array $r, $cmd)
{
    return 'CMD;OK=' . (int) $r['ok'] . ';BEFEHL=' . preg_replace('/[^a-z]/', '', (string) $cmd)
        . ((int) $r['ok'] === 1 ? ';STAND=UEBERGEBEN' : ';GRUND=' . str_replace(';', ',', (string) $r['grund']));
}

function asn_befehle_einstellungen()
{
    $c = asn_config(false);
    return array('praefix' => ASN_PRAEFIX,
                 'annehmen' => !empty($c['befehle_mqtt_ein']) && !empty($c['mqtt_ein']),
                 'eingreifend' => !empty($c['steuerung_ein']),
                 'mqtt_ein' => !empty($c['mqtt_ein']));
}

function asn_bruecke_laden()
{
    if (function_exists('bb_waechter')) {
        return true;
    }
    $p = asn_paths();
    $kand = array();
    if ($p['home'] !== '') {
        $kand[] = $p['bindir'] . '/befehlsbruecke.php';
    }
    $kand[] = dirname(dirname(__DIR__)) . '/bin/befehlsbruecke.php';
    foreach ($kand as $k) {
        if (is_file($k)) {
            if (!defined('BB_NUR_LADEN')) {
                define('BB_NUR_LADEN', true);
            }
            require_once $k;
            return function_exists('bb_waechter');
        }
    }
    return false;
}

function asn_bruecke_schalten($cfg = null)
{
    if ($cfg === null) {
        $cfg = asn_config(false);
    }
    if (!asn_bruecke_laden()) {
        return array(1, '', asn_t('BEFEHL.T_NICHT_DA'));
    }
    $p = bb_pfade();
    if (!$p) {
        return array(null, '', '');
    }
    if (!empty($cfg['befehle_mqtt_ein']) && !empty($cfg['mqtt_ein'])) {
        list($rc, $aus) = bb_sh($p, 'start');
        return array($rc, 'start', $aus);
    }
    if (!bb_dienste()) {
        return array(null, '', '');
    }
    list($rc, $aus) = bb_sh($p, 'stop');
    return array($rc, 'stop', $aus);
}

/** Die Befehlsliste der Bruecke (bb_liste(): EINE Quelle fuer Reiter Einbindung und Vorlage). array(Liste|null, Fehler). */
function asn_bruecke_liste()
{
    if (!asn_bruecke_laden()) {
        return array(null, asn_t('BEFEHL.T_NICHT_DA'));
    }
    list($z, $fehler) = bb_zuordnung_laden();
    if ($z === null) {
        return array(null, $fehler);
    }
    $GLOBALS['bb_zuordnung'] = $z;
    list($fehl) = bb_zuordnung_pruefen($z);
    if ($fehl) {
        return array(null, $fehl[0]);
    }
    return array(bb_liste($z, bb_einstellungen($z)), '');
}

/** Die Loxone-Vorlage der Befehle (VirtualOut an den UDP-Eingang des Gateways) aus der Bruecke. array(Name, Inhalt). */
function asn_vorlage_befehle()
{
    list($liste) = asn_bruecke_liste();
    if (!is_array($liste) || !function_exists('bb_vorlage')) {
        return array('', '');
    }
    $host = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
        ? preg_replace('/[^A-Za-z0-9\.\-]/', '', preg_replace('/:[0-9]+$/', '', (string) $_SERVER['HTTP_HOST']))
        : (gethostname() ?: 'loxberry');
    $port = (int) asn_mqtt_zustand()['udpport'];
    return array('VQ_AUDIOSERVERNG_MQTT.xml', bb_vorlage($liste, $host, $port > 0 ? $port : 11884));
}

/** Lage der Bruecke: array(true|false|null, Text) - null = Haken aus. */
function asn_bruecke_lage()
{
    if (!asn_bruecke_laden()) {
        return array(false, asn_t('BEFEHL.T_NICHT_DA'));
    }
    list($soll, $grund) = bb_soll_ermitteln();
    if ($soll === 1) {
        return array(null, asn_t('BEFEHL.T_AUS'));
    }
    if ($soll !== 0) {
        return array(false, sprintf(asn_t('BEFEHL.T_DEFEKT'), asn_e($grund)));
    }
    $zu = bb_zustand_lesen();
    $pids = bb_dienste();
    $alter = (is_array($zu) && isset($zu['ts'])) ? time() - (int) $zu['ts'] : null;
    if ($pids && $alter !== null && $alter <= BB_WACHE_GRENZE && !empty($zu['abo'])) {
        $zl = (isset($zu['zaehler']) && is_array($zu['zaehler'])) ? $zu['zaehler'] : array();
        return array(true, sprintf(asn_t('BEFEHL.T_LAEUFT'), implode(' ', $pids), ASN_PRAEFIX . '/befehl/#',
            isset($zl['angenommen']) ? (int) $zl['angenommen'] : 0, isset($zl['abgelehnt']) ? (int) $zl['abgelehnt'] : 0));
    }
    $wie = !$pids ? 'kein Dienst' : ($alter === null ? 'kein Zustand' : ($alter > BB_WACHE_GRENZE ? 'Zustand ' . $alter . ' s alt' : 'ohne Abo'));
    return array(false, sprintf(asn_t('BEFEHL.T_STEHT'), $wie));
}

/* ==================================================================
 * Regeln des Formulars und der Sicherung (EINE Stelle)
 * ================================================================== */

function asn_regeln()
{
    return array(
        'zahlen'   => array('speicher_mb' => array(256, 8192), 'cpus' => array(1, 8), 'wartezeit' => array(1, 10),
                            'zonen_hoechste' => array(0, ASN_ZONEN_MAX)),
        'schalter' => array('container_ein', 'mqtt_ein', 'befehle_mqtt_ein', 'steuerung_ein', 'endpunkt_ein', 'tunein_ausblenden'),
    );
}

/** Einen Wert aus einer Sicherung pruefen; ein Schluessel ohne Regel wird abgewiesen (fail closed). */
function asn_sicherung_wert_pruefen($k, $w)
{
    $r = asn_regeln();
    if (isset($r['zahlen'][$k])) {
        list($u, $o) = $r['zahlen'][$k];
        if (!is_int($w)) {
            return array(sprintf(asn_t('EINST.SICH_W_ZAHL'), $k));
        }
        return ($w < $u || $w > $o) ? array(sprintf(asn_t('EINST.FEHLER_BEREICH'), $k, $u, $o)) : array();
    }
    if (in_array($k, $r['schalter'], true)) {
        return ($w === 0 || $w === 1) ? array() : array(sprintf(asn_t('EINST.SICH_W_SCHALTER'), $k));
    }
    $text = function ($ok) use ($k) {
        return $ok ? array() : array(sprintf(asn_t('EINST.SICH_W_TEXT'), $k));
    };
    switch ($k) {
        case 'aktionstoken':
            return asn_token_gueltig($w) ? array() : array(asn_t('EINST.SICH_W_TOKEN'));
        case 'schnittstelle':
            return $text(asn_schnittstelle_gueltig($w));
        case 'netz_art':
            return $text(in_array($w, array('mitnutzen', 'eigen'), true));
        case 'netz_name':
            return $text($w === '' || asn_netzname_gueltig($w));
        case 'ip':
            return $text($w === '' || asn_ipv4_zahl($w) !== null);
        case 'mac':
            return $text($w === '' || asn_mac_pruefen($w) === '');
        case 'hostname':
            return $text(is_string($w) && preg_match('/^[a-z0-9]([a-z0-9\-]{0,30}[a-z0-9])?\z/', $w) === 1);
        case 'musik_pfad':
            return $text(is_string($w) && asn_musik_pruefen($w, false) === '');
        case 'abbild':
        case 'abbild_vorher':
            return $text($w === '' || asn_digest_gueltig($w));
        case 'datenordner':
        case 'datenordner_vorher':
            return $text($w === '' || asn_datenordner_gueltig($w));
        case 'sonn_benutzer':
            return $text(is_string($w) && ($w === '' || preg_match('/^[A-Za-z0-9._@\-]{1,64}\z/', $w) === 1));
        case 'sonn_kennwort':
            return $text(is_string($w) && strlen($w) <= 256 && !preg_match('/[\x00-\x1F\x7F]/', $w));
        case 'favoriten':
            if (!is_string($w) || strlen($w) > 65536) {
                return $text(false);
            }
            return asn_favoriten_lesen($w)[1];
        case 'favoriten_zonen':
            return is_string($w) ? asn_favoriten_zonen_lesen($w)[1] : $text(false);
    }
    return array(sprintf(asn_t('EINST.SICH_FREMD'), asn_e($k)));
}

/** Eine Sicherungsdatei einlesen - eine halb gueltige Datei ueberschreibt NICHTS. array(cfg|null, Mangel, Hinweise, Anzahl). */
function asn_sicherung_lesen($roh, $geltend = null)
{
    $mangel = array();
    $hinweise = array();
    $daten = json_decode((string) $roh, true);
    if (!is_array($daten) || ($daten && array_keys($daten) === range(0, count($daten) - 1))) {
        return array(null, array(asn_t('EINST.SICH_KEIN_JSON')), array(), 0);
    }
    if (!is_array($geltend)) {
        $geltend = asn_config(false);
    }
    $neu = asn_vorgaben();
    $anzahl = 0;
    foreach ($daten as $k => $w) {
        $k = (string) $k;
        if (substr($k, 0, 1) === '_') {
            continue;
        }
        if (!array_key_exists($k, $neu)) {
            $mangel[] = sprintf(asn_t('EINST.SICH_FREMD'), asn_e($k));
            continue;
        }
        $wm = asn_sicherung_wert_pruefen($k, $w);
        if ($wm) {
            $mangel = array_merge($mangel, $wm);
            continue;
        }
        $neu[$k] = $w;
        $anzahl++;
    }
    if ($anzahl === 0 && !$mangel) {
        $mangel[] = asn_t('EINST.SICH_LEER');
    }
    $fehlend = array();
    foreach (array_keys(asn_vorgaben()) as $fk) {
        if (array_key_exists($fk, $daten)) {
            continue;
        }
        if (in_array($fk, asn_spaet_schluessel(), true)) {
            $neu[$fk] = isset($geltend[$fk]) ? $geltend[$fk] : asn_vorgaben()[$fk];
            $hinweise[] = sprintf(asn_t('EINST.SICH_SPAET'), $fk);
            continue;
        }
        $fehlend[] = $fk;
    }
    if ($fehlend) {
        $mangel[] = sprintf(asn_t('EINST.SICH_FEHLEND'), count($fehlend), implode(', ', $fehlend));
    }
    return array($mangel ? null : $neu, $mangel, $hinweise, $anzahl);
}

function asn_sicherung_datei()
{
    $p = asn_paths();
    $kopf = array(
        '_plugin'  => 'Audioserver NG (' . $p['plugin'] . ')',
        '_stand'   => (asn_fassung() !== '' ? asn_fassung() : 'unbekannt') . ', ' . date('Y-m-d H:i:s'),
        '_hinweis' => asn_klartext('EINST.SICH_KOPF_HINWEIS'),
    );
    $cfg = asn_config();
    $daten = array();
    foreach (array_keys(asn_vorgaben()) as $k) {
        $daten[$k] = $cfg[$k];
    }
    return json_encode($kopf + $daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function asn_einmal_schreiben($meldungen, $fehler, $test, $hinweise = array(), $zusatz = null)
{
    $weg = function ($t) {
        return asn_geheim_weg((string) $t);
    };
    return asn_json_schreiben(asn_paths()['datadir'] . '/einmalmeldung.json', array(
        'zeit'      => time(),
        'meldungen' => array_map($weg, array_values((array) $meldungen)),
        'fehler'    => array_map($weg, array_values((array) $fehler)),
        'test'      => $weg($test),
        'hinweise'  => array_map($weg, array_values((array) $hinweise)),
        'zusatz'    => is_array($zusatz) ? $zusatz : null,
    ), 0600);
}

function asn_einmal_lesen()
{
    $f = asn_paths()['datadir'] . '/einmalmeldung.json';
    if (!is_file($f)) {
        return null;
    }
    $d = json_decode((string) @file_get_contents($f), true);
    @unlink($f);
    if (!is_array($d) || !isset($d['zeit']) || abs(time() - (int) $d['zeit']) > 120) {
        return null;
    }
    $liste = function ($x) {
        return is_array($x) ? array_values(array_map('strval', array_filter($x, 'is_scalar'))) : array();
    };
    return array(
        'meldungen' => $liste(isset($d['meldungen']) ? $d['meldungen'] : null),
        'fehler'    => $liste(isset($d['fehler']) ? $d['fehler'] : null),
        'test'      => isset($d['test']) && is_scalar($d['test']) ? (string) $d['test'] : '',
        'hinweise'  => $liste(isset($d['hinweise']) ? $d['hinweise'] : null),
        'zusatz'    => isset($d['zusatz']) && is_array($d['zusatz']) ? $d['zusatz'] : null,
    );
}

/* ==================================================================
 * Gesundheit (healthcheck, Kopf): NUR aus Dateien
 * ================================================================== */

/** array(status 3|4|5|6, Text): 3 Fehler, 4 Warnung, 5 in Ordnung, 6 Hinweis. */
function asn_gesundheit()
{
    $cfg = asn_config(false);
    $merk = asn_merk();
    if (empty($cfg['container_ein']) && !$merk['angelegt']) {
        return array(6, asn_klartext('GESUND.AUS'));
    }
    $z = asn_zustand();
    $alter = $z['ts'] > 0 ? time() - $z['ts'] : -1;
    if ($alter < 0 || $alter > ASN_TAKT_GRENZE) {
        return array(4, sprintf(asn_klartext('GESUND.TAKT'), $alter < 0 ? '-' : (string) $alter));
    }
    if ($z['grund'] !== '') {
        return array(3, asn_klartext('GESUND.FEHLER') . ' ' . asn_grund_text($z['grund']));
    }
    $s = is_array($z['sonn']) ? $z['sonn'] : array();
    $st = isset($s['status']) ? (string) $s['status'] : '';
    $mz = asn_zonen_maengel_text($z['zonen']);
    if ($st === 'unhealthy') {
        return array(3, sprintf(asn_klartext('GESUND.SONN'), $st) . ($mz !== '' ? ' ' . $mz : ''));
    }
    if ($st !== 'ok') {
        return array(4, sprintf(asn_klartext('GESUND.SONN'), $st !== '' ? $st : '-') . ($mz !== '' ? ' ' . $mz : ''));
    }
    if ($mz !== '') {
        return array(4, $mz);
    }
    return array(5, sprintf(asn_klartext('GESUND.OK'), isset($s['fassung']) ? (string) $s['fassung'] : '-', count($z['zonen'])));
}

/** Der Lesezweig des Endpunkts (eine Zeile). */
function asn_lesezeile()
{
    $z = asn_zustand();
    $s = is_array($z['sonn']) ? $z['sonn'] : array();
    $st = isset($s['status']) ? (string) $s['status'] : '';
    $o = 'ASN;OK=' . ($z['ok'] ? 1 : 0) . ';ZAEHLER=' . (int) $z['zaehler']
        . ';ONLINE=' . ($st !== '' ? 1 : 0)
        . ';GESUND=' . ($st === 'ok' ? 2 : ($st === 'degraded' ? 1 : ($st === 'unhealthy' ? 0 : -1)))
        . ';GEKOPPELT=' . (isset($s['gekoppelt']) ? (int) $s['gekoppelt'] : -1)
        . ';ZONEN=' . count($z['zonen']);
    foreach ($z['zonen'] as $nr => $w) {
        $o .= ';Z' . (int) $nr . '_ZUSTAND=' . (isset($w['zustand']) ? (int) $w['zustand'] : -1)
            . ';Z' . (int) $nr . '_LAUT=' . (isset($w['lautstaerke']) ? (int) $w['lautstaerke'] : -1);
    }
    return $o;
}

/** Freier Arbeitsspeicher in MB aus /proc/meminfo (MemAvailable), -1 = nicht lesbar. */
function asn_ram_frei_mb()
{
    $roh = @file_get_contents('/proc/meminfo');
    if (is_string($roh) && preg_match('/^MemAvailable:\s+([0-9]+)\s+kB/m', $roh, $m)) {
        return (int) floor((int) $m[1] / 1024);
    }
    return -1;
}

/* ==================================================================
 * Einrichtung fuer Laien (Nachfassung 3, 31.10.2026): EIN Knopf "Audioserver einrichten".
 * Das Plugin bestimmt Netz, Adresse, MAC und Musikordner selbst - aus der Probe, sonst aus dem Heimnetz -,
 * speichert sie, schaltet die Verwaltung ein und legt den Container ueber den bestehenden Vorgangsweg an
 * ('wechsel' mit Probe: Daten kopieren, Probe bleibt stehen; sonst 'anlegen'). Der Vorschlag wird gespeichert
 * (<datadir>/einrichten.json, 15 min) und nur mit seiner Kennung angewendet: was angezeigt wurde, wird eingerichtet.
 * ================================================================== */

define('ASN_VORSCHLAG_S', 900);      // so lange gilt ein gespeicherter Vorschlag
define('ASN_IP_VERSUCHE', 12);       // hoechstens so viele Adressen pruefen (eine freie kostet rund 1 s)
define('ASN_IP_ABSTAND', 15);        // gesucht wird ab der 15. Adresse unter der Rundsendeadresse abwaerts

/** Die Schnittstelle mit dem Standardweg (default route) - '' = nicht feststellbar. */
function asn_standard_schnittstelle()
{
    $att = asn_netz_attrappe();
    if ($att !== null) {
        return (string) $att['schnittstelle'];
    }
    if (DIRECTORY_SEPARATOR === '\\') {
        return '';
    }
    list($rc, $out, ) = asn_aufruf(array('ip', '-4', 'route', 'show', 'default'), 5);
    if ($rc === 0) {
        foreach (preg_split('/\r?\n/', $out) as $z) {
            if (preg_match('#^default via [0-9.]{7,15} dev (\S+)#', trim($z), $m) && asn_schnittstelle_gueltig($m[1])) {
                return $m[1];
            }
        }
    }
    return '';
}

/**
 * Adressen, die Docker-Containern zugewiesen sind - laufend UND gestoppt (IPAddress, sonst IPAMConfig). Ein
 * macvlan-Kind antwortet dem LoxBerry nie auf ping; ohne diese Liste saehe seine Adresse frei aus.
 * Rueckgabe array(ok, array(ip => containername)).
 */
function asn_docker_adressen()
{
    list($rc, $out, ) = asn_docker(array('ps', '-a', '--format', '{{.Names}}'), 15);
    if ($rc !== 0) {
        return array(false, array());
    }
    $aus = array();
    $n = 0;
    foreach (preg_split('/\r?\n/', trim($out)) as $name) {
        $name = trim($name);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.\-]{0,127}\z/', $name) || ++$n > 40) {
            continue;
        }
        list($da, $info) = asn_inspect_container($name, 10);
        if ($da !== true) {
            continue;
        }
        foreach (asn_container_kurz($info)['netze'] as $w) {
            if ($w['ip'] !== '') {
                $aus[$w['ip']] = $name;
            }
        }
    }
    return array(true, $aus);
}

/**
 * Eine feste, lokal verwaltete MAC (02:...), stabil abgeleitet aus der Maschinenkennung dieses LoxBerry
 * (/etc/machine-id, nur gehasht) - auf demselben Geraet kommt immer dieselbe MAC und damit dieselbe Seriennummer heraus.
 * Pruefstand: ASN_MASCHINE_ATTRAPPE. Ohne Kennung: Rechnername und LoxBerry-Pfad.
 */
function asn_mac_ableiten()
{
    $q = (string) getenv('ASN_MASCHINE_ATTRAPPE');
    if ($q === '' && DIRECTORY_SEPARATOR !== '\\' && is_readable('/etc/machine-id')) {
        $q = trim((string) @file_get_contents('/etc/machine-id'));
    }
    if ($q === '') {
        $q = php_uname('n') . '|' . asn_paths()['home'];
    }
    $h = hash('sha256', 'audioserverng-mac|' . $q);
    $o = str_split(substr($h, 0, 12), 2);
    $o[0] = '02';                       // lokal verwaltet, unicast - wie die MAC der Probe (02:...)
    return implode(':', $o);
}

/** Eine MAC gekuerzt fuer die Anzeige: erstes und letztes Byte (02:…:5f). */
function asn_mac_kurz($mac)
{
    return asn_mac_gueltig($mac) ? strtolower(substr($mac, 0, 2)) . ":\u{2026}:" . strtolower(substr($mac, -2)) : '-';
}

/**
 * Die MAC der Probe. Docker 29 zeigt bei einem GESTOPPTEN Container keine MAC (NetworkSettings...MacAddress leer,
 * gemessen 31.10.2026 am LoxBerry an sonn-probe) - dann gilt die macId, die sonn in config.json seines Datenordners
 * gespeichert hat (12 Hex; daraus die Seriennummer, die Loxone Config kennt). Rueckgabe array(mac, herkunft).
 */
function asn_probe_mac(array $pk)
{
    foreach ($pk['netze'] as $n => $w) {
        if ($n !== 'bridge' && $w['mac'] !== '' && asn_mac_pruefen($w['mac']) === '') {
            return array(strtolower($w['mac']), 'docker');
        }
    }
    $d = isset($pk['mounts']['/app/data']['quelle']) ? (string) $pk['mounts']['/app/data']['quelle'] : '';
    if ($d !== '') {
        $c = asn_json_lesen($d . '/config.json');
        $m = (isset($c['system']['audioserver']['macId']) && is_string($c['system']['audioserver']['macId']))
            ? strtolower($c['system']['audioserver']['macId']) : '';
        if (preg_match('/^[0-9a-f]{12}\z/', $m)) {
            $mac = implode(':', str_split($m, 2));
            if (asn_mac_pruefen($mac) === '') {
                return array($mac, 'sonn');
            }
        }
    }
    return array('', '');
}

/**
 * Die Werte der Probe (fuer "Werte der Probe uebernehmen" und die Einrichtung). Rueckgabe array(netz_name, ip, mac,
 * mac_herkunft, musik_pfad, musik_grund). Ein Musikordner der Probe, den das Plugin nicht einhaengen darf (Ort), wird
 * NICHT uebernommen - sonst scheitert das Speichern; musik_grund sagt warum.
 */
function asn_probe_werte(array $pk)
{
    $w = array('netz_name' => '', 'ip' => '', 'mac' => '', 'mac_herkunft' => '', 'musik_pfad' => '', 'musik_grund' => '');
    foreach ($pk['netze'] as $n => $x) {
        if ($n !== 'bridge') {
            $w['netz_name'] = (string) $n;
            $w['ip'] = (string) $x['ip'];
            break;
        }
    }
    list($w['mac'], $w['mac_herkunft']) = asn_probe_mac($pk);
    $mm = asn_musik_mount(isset($pk['mounts']) && is_array($pk['mounts']) ? $pk['mounts'] : array());
    $mu = $mm !== null ? (string) $mm['quelle'] : '';
    if ($mu !== '') {
        $g = asn_musik_pruefen($mu, true);
        if ($g === '' && asn_musik_wahl_tauglich($mu)) {
            $w['musik_pfad'] = $mu;
        } else {
            $w['musik_grund'] = $g !== '' ? $g : 'MUSIK_FORM';
        }
    }
    return $w;
}

/**
 * Eine freie Adresse im Heimnetz der Schnittstelle vorschlagen. Gesucht wird abwaerts ab der 15. Adresse unter der
 * Rundsendeadresse (bei /24 also ab .240): Router verteilen per DHCP meist darunter (FRITZ!Box ab Werk .20 bis .200).
 * Den wirklichen DHCP-Bereich kann das Plugin NICHT lesen - das steht offen im Text. Frei heisst: nicht Netz-,
 * Rundsende-, LoxBerry- oder Router-Adresse, keinem Docker-Container zugewiesen, keine Antwort auf ping und kein
 * ARP-Eintrag danach. Ein Geraet, das gerade aus ist, antwortet nicht - auch das steht im Text.
 * Rueckgabe array(ok, grund, ip, geprueft, belegt).
 */
function asn_ip_vorschlagen($schnittstelle, array $tabu)
{
    list($ok, $g, $n) = asn_netz_lesen($schnittstelle);
    if (!$ok) {
        return array(false, $g, '', 0, 0);
    }
    $netz = asn_ipv4_zahl($n['netzadresse']);
    $bc = asn_ipv4_zahl($n['broadcast']);
    $verboten = array_merge(array($n['ip'], $n['gateway']), $tabu);
    $geprueft = 0;
    $belegt = 0;
    for ($z = max($netz + 1, $bc - ASN_IP_ABSTAND); $z > $netz && $z < $bc && $geprueft < ASN_IP_VERSUCHE; $z--) {
        $ip = asn_ipv4_text($z);
        if (in_array($ip, $verboten, true)) {
            continue;
        }
        $geprueft++;
        if (asn_ip_belegt($ip, $schnittstelle)) {
            $belegt++;
            continue;
        }
        return array(true, '', $ip, $geprueft, $belegt);
    }
    return array(false, 'KEINE_FREIE_IP|' . $geprueft, '', $geprueft, $belegt);
}

/**
 * Einen Musikordner auf dem LoxBerry-Speicher erkennen: unter system/storage/usb/<Geraet>/ ein Ordner Musik, Music,
 * MP3 oder Audio. Netzfreigaben (system/storage/smb) durchsucht das Plugin NICHT - jeder Zugriff haengt sie ein und
 * haengt bei einem ausgeschalteten Server lange. Rueckgabe der Pfad oder ''.
 */
function asn_musik_vorschlagen()
{
    $p = asn_paths();
    if ($p['home'] === '') {
        return '';
    }
    $usb = $p['home'] . '/system/storage/usb';
    $geraete = is_dir($usb) ? @scandir($usb) : false;
    foreach (is_array($geraete) ? $geraete : array() as $g) {
        if ($g === '.' || $g === '..' || !@is_dir($usb . '/' . $g)) {
            continue;
        }
        $inhalt = @scandir($usb . '/' . $g);
        foreach (is_array($inhalt) ? $inhalt : array() as $e) {
            $k = $usb . '/' . $g . '/' . $e;
            if (preg_match('/^(musik|music|mp3|audio)\z/i', $e) && @is_dir($k) && asn_musik_wahl_tauglich($k)
                && asn_musik_pruefen($k, true) === '') {
                return $k;
            }
        }
    }
    return '';
}

/** Die Felder eines Vorschlags, die angewendet werden (und in seine Kennung eingehen). */
function asn_einrichten_felder()
{
    return array('quelle', 'schnittstelle', 'netz_art', 'netz_name', 'ip', 'mac', 'musik_pfad');
}

function asn_einrichten_kennung(array $v)
{
    $t = array();
    foreach (asn_einrichten_felder() as $k) {
        $t[$k] = isset($v[$k]) ? (string) $v[$k] : '';
    }
    return substr(sha1((string) json_encode($t)), 0, 12);
}

/**
 * Der Vorschlag fuer "Audioserver einrichten". Rueckgabe array(ok, grund, v) mit v: quelle (probe|neu), probe_laeuft,
 * schnittstelle, netz_art (mitnutzen|eigen), netz_name, netz_anzeige, netz_neu (1 = das Plugin legt das Netz an),
 * subnetz, ip, ip_herkunft (probe|konfig|frei), ip_geprueft, ip_belegt, mac, mac_herkunft (docker|sonn|konfig|abgeleitet),
 * mac_probe_fehlt, musik_pfad, musik_herkunft (probe|konfig|speicher|leer), musik_grund, zeit, kennung.
 * $neu = true ermittelt neu; sonst gilt der gespeicherte, solange er juenger als 15 min ist und die Probe-Lage stimmt.
 */
function asn_einrichten_vorschlag($neu = false)
{
    $datei = asn_paths()['datadir'] . '/einrichten.json';
    list($lage, $g) = asn_docker_lage();
    if ($lage !== 'ok') {
        return array(false, $g, null);
    }
    list($pda, $pk) = asn_probe(10);
    if ($pda === null) {
        return array(false, 'DOCKER_FEHLER|0', null);
    }
    $quelle = $pda === true ? 'probe' : 'neu';
    $laeuft = ($pda === true && $pk['laeuft']) ? 1 : 0;
    $alt = $neu ? array() : asn_json_lesen($datei);
    if (isset($alt['zeit'], $alt['kennung'], $alt['quelle'], $alt['probe_laeuft']) && is_int($alt['zeit'])
        && $alt['zeit'] <= time() && time() - $alt['zeit'] < ASN_VORSCHLAG_S && $alt['quelle'] === $quelle
        && $alt['probe_laeuft'] === $laeuft && $alt['kennung'] === asn_einrichten_kennung($alt)) {
        return array(true, '', $alt);
    }
    $cfg = asn_config();
    list($nok, $netze) = asn_macvlan_netze();
    if (!$nok) {
        return array(false, 'DOCKER_FEHLER|0', null);
    }
    $eigen = asn_namen()['netz'];
    $v = array('quelle' => $quelle, 'probe_laeuft' => $laeuft, 'schnittstelle' => '', 'netz_art' => 'mitnutzen', 'netz_name' => '',
               'netz_anzeige' => '', 'netz_neu' => 0, 'subnetz' => '', 'ip' => '', 'ip_herkunft' => '', 'ip_geprueft' => 0,
               'ip_belegt' => 0, 'mac' => '', 'mac_herkunft' => '', 'mac_probe_fehlt' => 0, 'musik_pfad' => '',
               'musik_herkunft' => 'leer', 'musik_grund' => '');
    $pw = null;
    if ($pda === true) {
        $pw = asn_probe_werte($pk);
        if ($pw['netz_name'] === '' || $pw['ip'] === '') {
            return array(false, 'PROBE_WERTE', null);
        }
        if (!isset($netze[$pw['netz_name']])) {
            return array(false, 'NETZ_FEHLT|' . $pw['netz_name'], null);
        }
        $pn = $netze[$pw['netz_name']];
        $v['schnittstelle'] = $pn['parent'];
        $v['netz_art'] = ($pw['netz_name'] === $eigen && $pn['eigen']) ? 'eigen' : 'mitnutzen';
        $v['netz_name'] = $v['netz_art'] === 'eigen' ? '' : $pw['netz_name'];
        $v['netz_anzeige'] = $pw['netz_name'];
        $v['subnetz'] = $pn['subnetz'];
        $v['ip'] = $pw['ip'];
        $v['ip_herkunft'] = 'probe';
        if ($pw['mac'] !== '') {
            $v['mac'] = $pw['mac'];
            $v['mac_herkunft'] = $pw['mac_herkunft'];
        } else {
            $v['mac_probe_fehlt'] = 1;
        }
    } else {
        $std = asn_standard_schnittstelle();
        $v['schnittstelle'] = $std !== '' ? $std : (string) $cfg['schnittstelle'];
        list($nk, $ng, $nn) = asn_netz_lesen($v['schnittstelle']);
        if (!$nk) {
            return array(false, $ng, null);
        }
        $kand = asn_netze_passend($netze, $v['schnittstelle'], $nn['ip']);
        ksort($kand);
        if (isset($kand[$eigen]) && $kand[$eigen]['eigen']) {
            $v['netz_art'] = 'eigen';
            $v['netz_anzeige'] = $eigen;
            $v['subnetz'] = $kand[$eigen]['subnetz'];
        } else {
            unset($kand[$eigen]);
            if ($kand) {
                $v['netz_name'] = (string) key($kand);
                $v['netz_anzeige'] = $v['netz_name'];
                $v['subnetz'] = $kand[$v['netz_name']]['subnetz'];
            } else {
                $v['netz_art'] = 'eigen';
                $v['netz_anzeige'] = $eigen;
                $v['netz_neu'] = 1;
                $v['subnetz'] = $nn['netzadresse'] . '/' . $nn['praefix'];
            }
        }
        list(, $adr) = asn_docker_adressen();
        $ci = (string) $cfg['ip'];
        if ($ci !== '' && !isset($adr[$ci]) && asn_ip_pruefen($ci, $v['schnittstelle'], true) === '') {
            $v['ip'] = $ci;
            $v['ip_herkunft'] = 'konfig';
        } else {
            list($iok, $ig, $iip, $v['ip_geprueft'], $v['ip_belegt']) = asn_ip_vorschlagen($v['schnittstelle'], array_keys($adr));
            if (!$iok) {
                return array(false, $ig, null);
            }
            $v['ip'] = $iip;
            $v['ip_herkunft'] = 'frei';
        }
    }
    if ($v['mac'] === '') {
        if (asn_mac_pruefen((string) $cfg['mac']) === '') {
            $v['mac'] = strtolower((string) $cfg['mac']);
            $v['mac_herkunft'] = 'konfig';
        } else {
            $v['mac'] = asn_mac_ableiten();
            $v['mac_herkunft'] = 'abgeleitet';
        }
    }
    if ($pw !== null && $pw['musik_pfad'] !== '') {
        $v['musik_pfad'] = $pw['musik_pfad'];
        $v['musik_herkunft'] = 'probe';
    } else {
        $v['musik_grund'] = $pw !== null ? $pw['musik_grund'] : '';
        $cm = trim((string) $cfg['musik_pfad']);
        $sm = $cm === '' ? asn_musik_vorschlagen() : '';
        if ($cm !== '' && asn_musik_pruefen($cm, true) === '') {
            $v['musik_pfad'] = $cm;
            $v['musik_herkunft'] = 'konfig';
        } elseif ($sm !== '') {
            $v['musik_pfad'] = $sm;
            $v['musik_herkunft'] = 'speicher';
        }
    }
    $v['zeit'] = time();
    $v['kennung'] = asn_einrichten_kennung($v);
    asn_json_schreiben($datei, $v, 0600);
    return array(true, '', $v);
}

/**
 * Einen Vorschlag anwenden: Werte in die Konfiguration, Verwaltung ein - erst nach der vollen Pruefung (Netz,
 * Adresse, Musikordner am Geraet; bei einer neuen Adresse zusaetzlich: antwortet sie inzwischen?). Scheitert etwas,
 * wird NICHTS gespeichert und der Vorschlag verworfen (beim naechsten Aufbau neu ermittelt).
 * Rueckgabe array(ok, gruende). Den Vorgang startet der Aufrufer ('wechsel' bei der Probe, sonst 'anlegen').
 */
function asn_einrichten_anwenden(array $v)
{
    $cfg = asn_config();
    $neu = $cfg;
    $neu['container_ein'] = 1;
    foreach (array('schnittstelle', 'netz_art', 'netz_name', 'ip', 'mac', 'musik_pfad') as $k) {
        $neu[$k] = (string) $v[$k];
    }
    $g = asn_container_pruefen($neu, true);
    if (!$g && $v['quelle'] === 'neu') {
        list(, $adr) = asn_docker_adressen();
        if (isset($adr[$neu['ip']]) || asn_ip_belegt($neu['ip'], $neu['schnittstelle'])) {
            $g[] = 'IP_BELEGT|' . $neu['ip'];
        }
    }
    if ($g) {
        @unlink(asn_paths()['datadir'] . '/einrichten.json');
        return array(false, $g);
    }
    if (!asn_config_speichern($neu)) {
        return array(false, array('SCHREIBEN'));
    }
    asn_log('Einrichtung: Werte ' . ($v['quelle'] === 'probe' ? 'der Probe' : 'des Vorschlags') . ' gespeichert, Verwaltung ein.');
    return array(true, array());
}

/**
 * Die Schritte nach dem Einrichten ("So geht es weiter") mit gemessenem Zustand: true erledigt, false offen,
 * null nicht messbar. Rueckgabe Liste array(nr, zustand, schluessel, zusatz).
 */
function asn_weiter_schritte(array $cfg, array $zu)
{
    $s = is_array($zu['sonn']) ? $zu['sonn'] : null;
    $konten = ($s !== null && isset($s['konten']) && is_int($s['konten'])) ? $s['konten'] : -1;
    $lox = ($s !== null && isset($s['loxone_ein'])) ? (int) $s['loxone_ein'] : -1;
    $gek = ($s !== null && isset($s['gekoppelt'])) ? (int) $s['gekoppelt'] : -1;
    $zonen = $s !== null ? $zu['zonen'] : array();
    $ohne = 0;
    foreach ($zonen as $w) {
        if (isset($w['verbunden']) && (string) $w['verbunden'] === '0') {
            $ohne++;
        }
    }
    $eins = $s === null || $konten < 0 ? null : ($konten > 0 && $lox === 1);
    $zwei = $s === null ? null : $gek === 1;
    $drei = $s === null ? null : (count($zonen) > 0 && $ohne === 0);
    // 0.9.2 Nachzug: Schritt 4 gemessen - erledigt, sobald die Lokale Bibliothek Titel hat (oeffentliche API von sonn,
    // Stand der letzten Minute); offen ohne Musikordner oder mit 0 Titeln; nicht messbar, wenn sonn nichts sagt.
    $titel = ($s !== null && isset($s['titel']) && is_int($s['titel'])) ? $s['titel'] : -1;
    if (trim((string) $cfg['musik_pfad']) === '') {
        $vier = false;
    } else {
        $vier = $titel < 0 ? null : $titel > 0;
    }
    $fuenf = asn_gesundheit()[0] === 5;
    return array(
        array(1, $eins, 'WEITER.S1', $konten),
        array(2, $zwei, 'WEITER.S2', ''),
        array(3, $drei, 'WEITER.S3', count($zonen) . '|' . $ohne),
        array(4, $vier, 'WEITER.S4', $titel),
        array(5, $fuenf, 'WEITER.S5', ''),
    );
}
