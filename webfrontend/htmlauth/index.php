<?php
/**
 * Audioserver NG - Bedienoberflaeche (Regeln/04; Kopf und Gerust wie Xiaomi Licht 0.9.3).
 *
 * Reiter: Einstellungen | MQTT | Einbindung in Loxone | Test | Logdateien. Diese Datei ist NUR Oberflaeche: den
 * Container fasst der Hintergrundvorgang an (bin/asn_vorgang.php), den Zustand misst der Takt (bin/asn_takt.php).
 * Docker wird hier nur im Reiter Test (wenn er serverseitig offen ist) und auf Knopfdruck gefragt.
 * Jedes Formular traegt das Merkmal (fmt) und formular=<name>; EINE Pruefung vor allen Handlern; jeder POST endet mit
 * 303 (Einmalmeldung). Kennwort des sonn-Kontos: Passwortfeld, nie vorbefuellt, leeres Feld behaelt, Loeschhaken.
 * Praefix 'asn_', weil LBWeb::lbheader() SDK-Globale setzt. PHP 7.4, 8.4, 8.5.
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

$asn_gefunden_lib = false;
if (basename(dirname(__DIR__)) === 'plugins') {
    $asn_kandidaten = array(dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . basename(__DIR__) . '/asn_lib.php');
} else {
    $asn_kandidaten = array(dirname(__DIR__) . '/html/asn_lib.php');
}
foreach ($asn_kandidaten as $asn_kandidat) {
    if (is_file($asn_kandidat)) {
        require_once $asn_kandidat;
        $asn_gefunden_lib = true;
        break;
    }
}
if (!$asn_gefunden_lib) {
    echo '<p><b>Fehler:</b> asn_lib.php wurde nicht gefunden. Bitte das Plugin neu installieren.</p>';
    exit;
}
require_once __DIR__ . '/asn_test.php';

$asn_p = asn_paths();
if ($asn_p['home'] !== '' && is_file($asn_p['home'] . '/libs/phplib/loxberry_system.php')) {
    require_once $asn_p['home'] . '/libs/phplib/loxberry_system.php';
    require_once $asn_p['home'] . '/libs/phplib/loxberry_web.php';
}
if ($asn_p['home'] !== '' && !is_dir($asn_p['datadir'])) {
    @mkdir($asn_p['datadir'], 0700, true);
}

/* Die Reiter an EINER Stelle; Leiste und Bereiche sind ausgeschrieben, die Pruefzeile im Reiter Test misst sie gegen
 * dieses Feld und den Ausdruck darunter (Regeln/04). */
$asn_reiter = array(
    'settings' => 'REITER.EINSTELLUNGEN',
    'mqtt'     => null,
    'loxone'   => 'REITER.LOXONE',
    'test'     => 'REITER.TEST',
    'log'      => 'REITER.LOG',
);
$asn_muster = '/^tab-(settings|mqtt|loxone|test|log)$/';
$asn_tab = 'tab-settings';
if (isset($_POST['activetab']) && is_string($_POST['activetab']) && preg_match($asn_muster, $_POST['activetab'])) {
    $asn_tab = $_POST['activetab'];
} elseif (isset($_GET['form']) && is_string($_GET['form']) && preg_match($asn_muster, 'tab-' . $_GET['form'])) {
    $asn_tab = 'tab-' . $_GET['form'];
}

$asn_meldungen = array();
$asn_fehler = array();      // Beanstandungen beim Speichern - es wurde nichts gespeichert
$asn_misslungen = array();  // ein Vorgang ohne Speichern ist nicht gelungen
$asn_hinweise = array();
$asn_testausgabe = '';
$asn_zusatz = array();
$asn_post = (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') === 'POST';
$asn_post_roh = $asn_post;

if ($asn_p['home'] !== '') {
    asn_token();
}
$asn_fmt = asn_formtoken();
if ($asn_post) {
    $asn_mit = (isset($_POST['fmt']) && is_string($_POST['fmt'])) ? $_POST['fmt'] : '';
    if ($asn_fmt === '' || !hash_equals($asn_fmt, $asn_mit)) {
        $asn_post = false;
        $asn_misslungen[] = asn_t('ALLG.FORMULAR_FREMD');
    }
}
$asn_formular = ($asn_post && isset($_POST['formular']) && is_string($_POST['formular'])) ? $_POST['formular'] : '';
$asn_wert = function ($k, $vorgabe = '') {
    return (isset($_POST[$k]) && is_string($_POST[$k])) ? trim($_POST[$k]) : $vorgabe;
};
/* Einen Hintergrundvorgang starten und das Ergebnis als Meldung festhalten. */
$asn_vorgang_los = function ($auftrag) use (&$asn_meldungen, &$asn_misslungen) {
    list($ok, $g) = asn_vorgang_starten($auftrag);
    if ($ok) {
        $asn_meldungen[] = sprintf(asn_t('VORGANG.GESTARTET'), asn_t('VORGANG.' . strtoupper($auftrag)));
    } else {
        $asn_misslungen[] = asn_grund_text($g);
    }
    return $ok;
};

/* ---------------- Handler (nur POST mit gueltigem Merkmal; jeder fasst nur seine eigenen Schluessel an) ---------------- */
if ($asn_post) {
    $asn_cfg = asn_config();
    switch ($asn_formular) {
        case 'container':
            $asn_tab = 'tab-settings';
            $asn_aktion = $asn_wert('aktion');
            if (!in_array($asn_aktion, array('anlegen', 'starten', 'stoppen', 'neustart'), true)) {
                $asn_misslungen[] = asn_t('ALLG.FORMULAR_UNBEKANNT');
                break;
            }
            if ($asn_aktion === 'anlegen' && empty($asn_cfg['container_ein'])) {
                $asn_misslungen[] = asn_grund_text('VERWALTUNG_AUS');
                break;
            }
            $asn_vorgang_los($asn_aktion);
            break;

        case 'einstellungen':
            $asn_tab = 'tab-settings';
            $asn_neu = $asn_cfg;
            $asn_neu['container_ein'] = $asn_wert('container_ein') === '1' ? 1 : 0;
            $asn_neu['schnittstelle'] = $asn_wert('schnittstelle');
            $asn_neu['netz_art'] = $asn_wert('netz_art');
            $asn_neu['netz_name'] = $asn_wert('netz_name');
            $asn_neu['ip'] = $asn_wert('ip');
            $asn_neu['mac'] = strtolower(str_replace('-', ':', $asn_wert('mac')));
            if ($asn_wert('mac_neu') === '1') {
                $asn_neu['mac'] = asn_mac_erzeugen();
                $asn_hinweise[] = asn_t('EINST.MAC_NEU');
            }
            $asn_neu['hostname'] = $asn_wert('hostname');
            $asn_sp = $asn_wert('speicher_mb');
            $asn_cp = $asn_wert('cpus');
            $asn_neu['speicher_mb'] = preg_match('/^[0-9]{1,5}\z/', $asn_sp) ? (int) $asn_sp : -1;
            $asn_neu['cpus'] = preg_match('/^[0-9]{1,2}\z/', $asn_cp) ? (int) $asn_cp : -1;
            $asn_neu['musik_pfad'] = $asn_wert('musik_pfad');
            $asn_gruende = ($asn_neu['container_ein'] || $asn_neu['ip'] !== '' || $asn_neu['mac'] !== '')
                ? asn_container_pruefen($asn_neu, $asn_p['home'] !== '') : array();
            if ($asn_gruende) {
                /* Nachfassung 3: Meldungen sagen, was zu tun ist - fehlt das Netz und gibt es keines an der
                 * Schnittstelle, heisst es "keins gefunden, das Plugin legt beim Einrichten eines an". */
                if (in_array('NETZ_NAME', $asn_gruende, true)) {
                    list($asn_nok, $asn_nz) = asn_macvlan_netze();
                    if ($asn_nok && !asn_netze_passend($asn_nz, (string) $asn_neu['schnittstelle'], '')) {
                        $asn_gruende[array_search('NETZ_NAME', $asn_gruende, true)] = 'NETZ_NAME_KEINS';
                    }
                }
                foreach ($asn_gruende as $asn_g) {
                    $asn_fehler[] = asn_grund_text($asn_g);
                }
                $asn_zusatz['eingaben'] = array();
                foreach (array('schnittstelle', 'netz_art', 'netz_name', 'ip', 'mac', 'hostname', 'musik_pfad') as $asn_k) {
                    $asn_zusatz['eingaben'][$asn_k] = substr((string) $asn_neu[$asn_k], 0, 400);
                }
                $asn_zusatz['eingaben']['speicher_mb'] = substr($asn_sp, 0, 6);
                $asn_zusatz['eingaben']['cpus'] = substr($asn_cp, 0, 3);
                $asn_zusatz['eingaben']['container_ein'] = (string) $asn_neu['container_ein'];
                break;     // eine Beanstandung: NICHTS wird gespeichert
            }
            if (asn_mac_gueltig($asn_neu['mac']) && !asn_mac_lokal($asn_neu['mac'])) {
                $asn_hinweise[] = asn_t('EINST.MAC_NICHT_LOKAL');
            }
            if (asn_config_speichern($asn_neu)) {
                $asn_meldungen[] = asn_t('EINST.M_GESPEICHERT');
                if (asn_merk()['angelegt']) {
                    $asn_hinweise[] = asn_t('EINST.H_NEU_ANLEGEN');
                }
            } else {
                $asn_misslungen[] = asn_t('EINST.F_SPEICHERN');
            }
            break;

        case 'einrichten':
            /* Nachfassung 3: EIN Knopf. Nur mit Ja-Haken; angewendet wird genau der angezeigte Vorschlag (Kennung). */
            $asn_tab = 'tab-settings';
            if (!isset($_POST['einrichten_ok'])) {
                $asn_misslungen[] = asn_t('EINRICHTEN.F_HAKEN');
                break;
            }
            if (asn_merk()['angelegt']) {
                $asn_misslungen[] = asn_t('EINRICHTEN.F_SCHON');
                break;
            }
            list($asn_vok, $asn_vg, $asn_v) = asn_einrichten_vorschlag(false);
            if (!$asn_vok) {
                $asn_misslungen[] = asn_grund_text($asn_vg);
                break;
            }
            if ($asn_v['kennung'] !== $asn_wert('kennung')) {
                $asn_misslungen[] = asn_t('EINRICHTEN.F_GEAENDERT');
                break;
            }
            if ($asn_v['probe_laeuft']) {
                $asn_misslungen[] = asn_grund_text('PROBE_LAEUFT');
                break;
            }
            list($asn_aok, $asn_ag) = asn_einrichten_anwenden($asn_v);
            if (!$asn_aok) {
                $asn_misslungen[] = asn_t('EINRICHTEN.F_NICHT');
                foreach ($asn_ag as $asn_g) {
                    $asn_misslungen[] = asn_grund_text($asn_g);
                }
                $asn_misslungen[] = asn_t('EINRICHTEN.F_NEU_VORGESCHLAGEN');
                break;
            }
            $asn_meldungen[] = asn_t('EINRICHTEN.M_GESPEICHERT');
            $asn_vorgang_los($asn_v['quelle'] === 'probe' ? 'wechsel' : 'anlegen');
            break;

        case 'probe':
            $asn_tab = 'tab-settings';
            $asn_aktion = $asn_wert('aktion');
            if ($asn_aktion === 'werte') {
                list($asn_pda, $asn_pk) = asn_probe();
                if ($asn_pda !== true) {
                    $asn_misslungen[] = asn_grund_text($asn_pda === false ? 'PROBE_FEHLT' : 'DOCKER_FEHLER|0');
                    break;
                }
                /* Nachfassung 3: die MAC eines GESTOPPTEN Containers zeigt Docker 29 nicht - asn_probe_werte() liest dann
                 * die macId aus dem Datenordner der Probe; ein Musikordner an einem nicht erlaubten Ort wird nicht
                 * uebernommen; die Verwaltung steht im Formular gleich auf ein (gespeichert wird erst mit Speichern). */
                $asn_pw = asn_probe_werte($asn_pk);
                list(, $asn_nz) = asn_macvlan_netze();
                $asn_eig = $asn_pw['netz_name'] === asn_namen()['netz'];
                $asn_e = array('container_ein' => '1',
                               'schnittstelle' => isset($asn_nz[$asn_pw['netz_name']]) ? $asn_nz[$asn_pw['netz_name']]['parent'] : $asn_cfg['schnittstelle'],
                               'hostname' => $asn_cfg['hostname'], 'speicher_mb' => (string) $asn_cfg['speicher_mb'], 'cpus' => (string) $asn_cfg['cpus'],
                               'netz_art' => $asn_eig ? 'eigen' : 'mitnutzen', 'netz_name' => $asn_eig ? '' : $asn_pw['netz_name'],
                               'ip' => $asn_pw['ip'], 'mac' => $asn_pw['mac'], 'musik_pfad' => $asn_pw['musik_pfad']);
                $asn_zusatz['eingaben'] = $asn_e;
                $asn_hinweise[] = asn_t('PROBE.M_WERTE');
                if ($asn_pw['mac_herkunft'] === 'sonn') {
                    $asn_hinweise[] = asn_t('PROBE.M_MAC_SONN');
                } elseif ($asn_pw['mac'] === '') {
                    $asn_hinweise[] = asn_t('PROBE.M_MAC_FEHLT');
                }
                if ($asn_pw['musik_grund'] !== '') {
                    $asn_hinweise[] = sprintf(asn_t('PROBE.M_MUSIK_NICHT'), asn_grund_text($asn_pw['musik_grund']));
                }
            } elseif ($asn_aktion === 'wechsel') {
                if (!isset($_POST['wechsel_ok'])) {
                    $asn_misslungen[] = asn_t('PROBE.F_HAKEN');
                    break;
                }
                if (empty($asn_cfg['container_ein'])) {
                    $asn_misslungen[] = asn_grund_text('VERWALTUNG_AUS');
                    break;
                }
                $asn_vorgang_los('wechsel');
            } elseif ($asn_aktion === 'entfernen') {
                if (!isset($_POST['entfernen_ok'])) {
                    $asn_misslungen[] = asn_t('PROBE.F_HAKEN');
                    break;
                }
                $asn_vorgang_los('probe_entfernen');
            } else {
                $asn_misslungen[] = asn_t('ALLG.FORMULAR_UNBEKANNT');
            }
            break;

        case 'abbild':
            $asn_tab = 'tab-settings';
            $asn_aktion = $asn_wert('aktion');
            if ($asn_aktion === 'update') {
                $asn_d = strtolower($asn_wert('digest'));
                if (!asn_digest_gueltig($asn_d)) {
                    $asn_fehler[] = asn_grund_text('UPDATE_ZIEL');
                    break;
                }
                if (!isset($_POST['update_ok'])) {
                    $asn_misslungen[] = asn_t('ABBILD.F_HAKEN');
                    break;
                }
                if (!asn_json_schreiben($asn_p['datadir'] . '/update_ziel.json', array('digest' => $asn_d, 'zeit' => time()), 0600)) {
                    $asn_misslungen[] = asn_t('EINST.F_SPEICHERN');
                    break;
                }
                $asn_vorgang_los('update');
            } elseif ($asn_aktion === 'rueckweg') {
                if (!isset($_POST['rueckweg_ok'])) {
                    $asn_misslungen[] = asn_t('ABBILD.F_HAKEN');
                    break;
                }
                $asn_vorgang_los('rueckweg');
            } else {
                $asn_misslungen[] = asn_t('ALLG.FORMULAR_UNBEKANNT');
            }
            break;

        case 'abbilder':
            $asn_tab = 'tab-settings';
            if ($asn_wert('aktion') !== 'aufraeumen') {
                $asn_misslungen[] = asn_t('ALLG.FORMULAR_UNBEKANNT');
                break;
            }
            if (!isset($_POST['aufraeumen_ok'])) {
                $asn_misslungen[] = asn_t('ABBILDER.F_HAKEN');
                break;
            }
            list($asn_aok, $asn_ag, $asn_aw, $asn_an, $asn_af) = asn_abbilder_aufraeumen();
            if (!$asn_aok) {
                $asn_misslungen[] = asn_grund_text($asn_ag);
                break;
            }
            $asn_meldungen[] = sprintf(asn_t('ABBILDER.M_ERGEBNIS'), $asn_aw, asn_mb($asn_af));
            if ($asn_an > 0) {
                $asn_misslungen[] = sprintf(asn_t('ABBILDER.M_ABGELEHNT'), $asn_an);
            }
            break;

        case 'konto':
            $asn_tab = 'tab-settings';
            $asn_neu = $asn_cfg;
            $asn_b = $asn_wert('sonn_benutzer');
            $asn_k = (isset($_POST['sonn_kennwort']) && is_string($_POST['sonn_kennwort'])) ? $_POST['sonn_kennwort'] : '';
            unset($_POST['sonn_kennwort']);
            /* Fremdpruefung 31.10.2026, T1-6 (Bauart Renault F_LOESCHEN_WIDERSPRUCH): neues Kennwort oder anderer Benutzer
             * UND "Konto entfernen" ist ein Widerspruch - nichts aendern. "Konto entfernen" leert Benutzer und Kennwort
             * (so beschriftet). Bei jeder Beanstandung kommt der getippte Benutzername ins Feld zurueck (nie das Kennwort). */
            if (isset($_POST['kennwort_loeschen']) && ($asn_k !== '' || ($asn_b !== '' && $asn_b !== (string) $asn_cfg['sonn_benutzer']))) {
                $asn_fehler[] = asn_t('KONTO.F_WIDERSPRUCH');
                $asn_zusatz['konto_benutzer'] = substr($asn_b, 0, 64);
                break;
            }
            if (isset($_POST['kennwort_loeschen'])) {
                $asn_neu['sonn_benutzer'] = '';
                $asn_neu['sonn_kennwort'] = '';
            } else {
                $asn_neu['sonn_benutzer'] = $asn_b;
                if ($asn_k !== '') {
                    $asn_neu['sonn_kennwort'] = $asn_k;
                }
            }
            $asn_m = array_merge(asn_sicherung_wert_pruefen('sonn_benutzer', $asn_neu['sonn_benutzer']),
                                 asn_sicherung_wert_pruefen('sonn_kennwort', $asn_neu['sonn_kennwort']));
            if ($asn_m) {
                $asn_fehler = array_merge($asn_fehler, $asn_m);
                $asn_zusatz['konto_benutzer'] = substr($asn_b, 0, 64);
                break;
            }
            $asn_meldungen[] = asn_config_speichern($asn_neu) ? asn_t('KONTO.M_GESPEICHERT') : asn_t('EINST.F_SPEICHERN');
            break;

        case 'favoriten':
            /* 0.9.2: Favoritenliste. "speichern" schreibt nur die Konfiguration; "uebernehmen" (eingreifend) braucht den
             * Ja-Haken, speichert und startet den Hintergrundvorgang "favoriten" - er ersetzt die Favoriten der Zonen in sonn. */
            $asn_tab = 'tab-settings';
            $asn_aktion = $asn_wert('aktion');
            if (!in_array($asn_aktion, array('speichern', 'uebernehmen'), true)) {
                $asn_misslungen[] = asn_t('ALLG.FORMULAR_UNBEKANNT');
                break;
            }
            $asn_ft = (isset($_POST['favoriten']) && is_string($_POST['favoriten'])) ? str_replace("\r", '', $_POST['favoriten']) : '';
            $asn_fz = $asn_wert('favoriten_zonen');
            $asn_zurueck = function () use (&$asn_zusatz, $asn_ft, $asn_fz) {
                $asn_zusatz['fav_eingabe'] = substr($asn_ft, 0, 65536);
                $asn_zusatz['fav_zonen'] = substr($asn_fz, 0, 100);
            };
            if ($asn_aktion === 'uebernehmen' && !isset($_POST['favoriten_ok'])) {
                $asn_misslungen[] = asn_t('FAV.F_HAKEN');
                $asn_zurueck();
                break;
            }
            if (strlen($asn_ft) > 65536) {
                $asn_fehler[] = asn_t('FAV.F_LANG');
                break;
            }
            list($asn_fl, $asn_ff) = asn_favoriten_lesen($asn_ft);
            list(, $asn_fzf) = asn_favoriten_zonen_lesen($asn_fz);
            if ($asn_ff || $asn_fzf) {
                $asn_fehler = array_merge($asn_fehler, $asn_ff, $asn_fzf);
                $asn_zurueck();
                break;     // eine Beanstandung: NICHTS wird gespeichert
            }
            if ($asn_aktion === 'uebernehmen' && !$asn_fl) {
                $asn_misslungen[] = asn_grund_text('FAV_LEER');
                $asn_zurueck();
                break;
            }
            $asn_neu = $asn_cfg;
            $asn_neu['favoriten'] = asn_favoriten_text($asn_fl);
            $asn_neu['favoriten_zonen'] = implode(',', asn_favoriten_zonen_lesen($asn_fz)[0]);
            if (!asn_config_speichern($asn_neu)) {
                $asn_misslungen[] = asn_t('EINST.F_SPEICHERN');
                $asn_zurueck();
                break;
            }
            $asn_meldungen[] = sprintf(asn_t('FAV.M_GESPEICHERT'), count($asn_fl));
            if ($asn_aktion === 'uebernehmen') {
                $asn_vorgang_los('favoriten');
            }
            break;

        case 'tunein':
            /* 0.9.3: Vorgabesender von TuneIn. Speichern; weicht die Datei im Container von der Einstellung ab, stellt der Hintergrundvorgang sie um. */
            $asn_tab = 'tab-settings';
            $asn_tw = isset($_POST['tunein_ausblenden']) ? 1 : 0;
            $asn_neu = $asn_cfg;
            $asn_neu['tunein_ausblenden'] = $asn_tw;
            if (!asn_config_speichern($asn_neu)) {
                $asn_misslungen[] = asn_t('EINST.F_SPEICHERN');
                break;
            }
            $asn_meldungen[] = asn_t($asn_tw ? 'TUNEIN.M_EIN' : 'TUNEIN.M_AUS');
            $asn_tm = asn_tunein_merk();
            if ($asn_tw && empty($asn_cfg['tunein_ausblenden']) && $asn_tm['gesperrt']) {
                $asn_tm['gesperrt'] = array();      // der neue Haken ist die Erlaubnis fuer genau einen neuen Versuch
                asn_tunein_merk_schreiben($asn_tm);
            }
            $asn_tl = asn_tunein_stand()['lage'];
            if ($asn_tw && ($asn_tl === 'unbekannt' || $asn_tl === 'fehlt')) {
                $asn_misslungen[] = asn_grund_text($asn_tl === 'fehlt' ? 'TUNEIN_FEHLT' : 'TUNEIN_UNBEKANNT');
            } elseif (($asn_tl === 'sichtbar' && $asn_tw) || ($asn_tl === 'ausgeblendet' && !$asn_tw)) {
                $asn_vorgang_los('tunein');
            }
            break;

        case 'sicherung':
            $asn_tab = 'tab-settings';
            $asn_aktion = $asn_wert('aktion');
            if ($asn_aktion === 'holen') {
                header('Content-Type: application/json; charset=utf-8');
                header('Content-Disposition: attachment; filename="audioserverng_sicherung_' . date('Ymd_His') . '.json"');
                echo asn_sicherung_datei();
                exit;
            }
            if ($asn_aktion !== 'zurueck') {
                $asn_misslungen[] = asn_t('ALLG.FORMULAR_UNBEKANNT');
                break;
            }
            if (!isset($_POST['zurueck_ok'])) {
                $asn_misslungen[] = asn_t('EINST.SICH_HAKEN');
                break;
            }
            if (!isset($_FILES['sicherung']['tmp_name']) || !is_string($_FILES['sicherung']['tmp_name'])
                || !@is_uploaded_file($_FILES['sicherung']['tmp_name'])) {
                $asn_misslungen[] = asn_t('EINST.SICH_KEINE_DATEI');
                break;
            }
            if ((int) $_FILES['sicherung']['size'] > 262144) {
                $asn_misslungen[] = asn_t('EINST.SICH_ZU_GROSS');
                break;
            }
            list($asn_neu, $asn_mangel, $asn_sh, $asn_n) = asn_sicherung_lesen((string) @file_get_contents($_FILES['sicherung']['tmp_name']), $asn_cfg);
            if ($asn_neu === null) {
                $asn_fehler[] = asn_t('EINST.SICH_ABGELEHNT');
                $asn_fehler = array_merge($asn_fehler, $asn_mangel);
                break;
            }
            if (asn_config_speichern($asn_neu)) {
                $asn_meldungen[] = sprintf(asn_t('EINST.SICH_UEBERNOMMEN'), $asn_n);
                $asn_hinweise = array_merge($asn_hinweise, $asn_sh);
                list($asn_brc, $asn_bw) = asn_bruecke_schalten($asn_neu);
                if ($asn_bw !== '') {
                    $asn_hinweise[] = sprintf(asn_t('MQTT.M_BRUECKE'), $asn_bw, $asn_brc === 0 ? asn_t('ALLG.JA') : asn_t('ALLG.NEIN'));
                }
            } else {
                $asn_misslungen[] = asn_t('EINST.F_SPEICHERN');
            }
            break;

        case 'mqtt':
            $asn_tab = 'tab-mqtt';
            $asn_neu = $asn_cfg;
            foreach (array('mqtt_ein', 'befehle_mqtt_ein', 'steuerung_ein') as $asn_k) {
                $asn_neu[$asn_k] = $asn_wert($asn_k) === '1' ? 1 : 0;
            }
            $asn_wz = $asn_wert('wartezeit');
            if (!preg_match('/^[0-9]{1,2}\z/', $asn_wz) || (int) $asn_wz < 1 || (int) $asn_wz > 10) {
                $asn_fehler[] = sprintf(asn_t('EINST.FEHLER_BEREICH'), asn_t('MQTT.L_WARTEZEIT'), 1, 10);
                break;
            }
            $asn_neu['wartezeit'] = (int) $asn_wz;
            if (!asn_config_speichern($asn_neu)) {
                $asn_misslungen[] = asn_t('EINST.F_SPEICHERN');
                break;
            }
            $asn_meldungen[] = asn_t('EINST.M_GESPEICHERT');
            list($asn_brc, $asn_bw, $asn_baus) = asn_bruecke_schalten($asn_neu);
            if ($asn_bw !== '') {
                $asn_hinweise[] = sprintf(asn_t('MQTT.M_BRUECKE'), $asn_bw, $asn_brc === 0 ? asn_t('ALLG.JA') : asn_t('ALLG.NEIN'));
            }
            break;

        case 'vorlage':
            list($asn_vname, $asn_vinhalt) = asn_vorlage_befehle();
            if ($asn_vinhalt === '') {
                $asn_tab = 'tab-loxone';
                $asn_misslungen[] = asn_t('LOX.F_VORLAGE');
                break;
            }
            header('Content-Type: application/xml; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $asn_vname . '"');
            echo $asn_vinhalt;
            exit;

        case 'endpunkt':
            $asn_tab = 'tab-loxone';
            $asn_aktion = $asn_wert('aktion');
            $asn_neu = $asn_cfg;
            if ($asn_aktion === 'speichern') {
                $asn_neu['endpunkt_ein'] = $asn_wert('endpunkt_ein') === '1' ? 1 : 0;
            } elseif ($asn_aktion === 'token_neu') {
                if (!isset($_POST['token_neu_ok'])) {
                    $asn_misslungen[] = asn_t('LOX.F_TOKEN_HAKEN');
                    break;
                }
                $asn_neu['aktionstoken'] = asn_token_erzeugen();
            } else {
                $asn_misslungen[] = asn_t('ALLG.FORMULAR_UNBEKANNT');
                break;
            }
            $asn_meldungen[] = asn_config_speichern($asn_neu) ? asn_t('EINST.M_GESPEICHERT') : asn_t('EINST.F_SPEICHERN');
            break;

        case 'test':
            $asn_tab = 'tab-test';
            $asn_ta = $asn_wert('aktion');
            if ($asn_ta === 'neu') {
                $asn_meldungen[] = asn_t('TEST.M_NEU');
            } elseif ($asn_ta === 'rohwerte') {
                $asn_roh = array('zustand' => asn_zustand(), 'vorgang' => asn_vorgang(), 'merk' => asn_merk());
                $asn_testausgabe = json_encode($asn_roh, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } elseif ($asn_ta === 'bibliothek') {
                $asn_vorgang_los('bibliothek');
            } else {
                $asn_misslungen[] = asn_t('ALLG.FORMULAR_UNBEKANNT');
            }
            break;

        case 'log':
            $asn_tab = 'tab-log';
            if (!isset($_POST['leeren_ok'])) {
                $asn_misslungen[] = asn_t('LOG.F_HAKEN');
                break;
            }
            if (is_file($asn_p['log']) && @file_put_contents($asn_p['log'], '') === false) {
                $asn_misslungen[] = asn_t('LOG.F_LEEREN');
            } else {
                asn_log('Protokoll geleert (Oberflaeche).');
                $asn_meldungen[] = asn_t('LOG.M_GELEERT');
            }
            break;

        default:
            $asn_misslungen[] = asn_t('ALLG.FORMULAR_UNBEKANNT');
    }
}
if ($asn_post_roh) {
    /* Jeder POST endet mit 303 und einer Einmalmeldung - ein Neuladen schickt nichts doppelt. */
    asn_einmal_schreiben($asn_meldungen, array_merge($asn_fehler ? array('#F#') : array(), $asn_fehler), $asn_testausgabe,
        array_merge($asn_hinweise, $asn_misslungen ? array('#M#') : array(), $asn_misslungen), $asn_zusatz);
    header('Location: index.php?form=' . substr($asn_tab, 4), true, 303);
    exit;
}
$asn_einmal = asn_einmal_lesen();
if ($asn_einmal) {
    $asn_meldungen = $asn_einmal['meldungen'];
    $asn_fehler = array_values(array_filter($asn_einmal['fehler'], function ($x) { return $x !== '#F#'; }));
    $asn_teil = array_search('#M#', $asn_einmal['hinweise'], true);
    $asn_hinweise = $asn_teil === false ? $asn_einmal['hinweise'] : array_slice($asn_einmal['hinweise'], 0, $asn_teil);
    $asn_misslungen = $asn_teil === false ? array() : array_slice($asn_einmal['hinweise'], $asn_teil + 1);
    $asn_testausgabe = $asn_einmal['test'];
    $asn_zusatz = is_array($asn_einmal['zusatz']) ? $asn_einmal['zusatz'] : array();
}

/* ---------------- Seitenaufbau (nur Dateien; Docker nur im offenen Reiter Test und fuer die Netzliste) ---------------- */
$asn_cfg = asn_config();
$asn_zu = asn_zustand();
$asn_merk = asn_merk();
$asn_vg = asn_vorgang();
$asn_mqtt = asn_mqtt_zustand();
list($asn_bl, $asn_bt) = asn_bruecke_lage();
$asn_aktiv = !empty($asn_cfg['container_ein']) || $asn_merk['angelegt'];
$asn_test_offen = ($asn_tab === 'tab-test');
$asn_logzeilen = asn_log_ende($asn_p['log'], 300);
$asn_eingaben = isset($asn_zusatz['eingaben']) && is_array($asn_zusatz['eingaben']) ? $asn_zusatz['eingaben'] : array();
$asn_ein = function ($k) use ($asn_eingaben, $asn_cfg) {
    return array_key_exists($k, $asn_eingaben) ? (string) $asn_eingaben[$k] : (string) $asn_cfg[$k];
};
$asn_ja_nein = function ($b) {
    return asn_e(asn_t($b ? 'ALLG.JA' : 'ALLG.NEIN'));
};
$asn_zeichen = function ($ok) {
    return $ok === true ? '<span class="sm-an">&#10004;</span>' : ($ok === false ? '<span class="sm-aus">&#10008;</span>'
        : '<span class="sm-hinweiszeichen">&#9432;</span>');
};
/* Netzliste und Probe nur, wenn die Verwaltung an ist oder der Reiter Einstellungen offen ist (sie fragen Docker). */
$asn_netze = array();
$asn_probe = array(null, null);
if ($asn_tab === 'tab-settings' && asn_docker_bin()) {
    list(, $asn_netze) = asn_macvlan_netze();
    $asn_netze = asn_netze_passend($asn_netze, $asn_ein('schnittstelle'), '');   // nur Netze an der Schnittstelle des Formulars
    $asn_probe = asn_probe(10);
}
/* Nachfassung 3: Kasten "Audioserver einrichten" (solange kein eigener Container angelegt ist) bzw. danach die
 * Schritt-Liste. Der Vorschlag fragt Docker und pingt hoechstens 12 Adressen - nur im Reiter Einstellungen, nicht
 * waehrend eines Vorgangs, und gespeichert fuer 15 min (angezeigt = angewendet). */
$asn_einr_vg = in_array($asn_vg['vorgang'], array('anlegen', 'wechsel'), true) ? $asn_vg : null;
$asn_einr_laeuft = $asn_einr_vg !== null && in_array($asn_einr_vg['zustand'], array('gestartet', 'laeuft'), true);
$asn_einr = null;
if ($asn_tab === 'tab-settings' && !$asn_merk['angelegt'] && !$asn_einr_laeuft) {
    $asn_einr = asn_einrichten_vorschlag(isset($_GET['vorschlag']) && $_GET['vorschlag'] === 'neu');
}
$asn_weiter = $asn_merk['angelegt'] ? asn_weiter_schritte($asn_cfg, $asn_zu) : array();
$asn_schnitt = asn_schnittstellen();
$asn_c = is_array($asn_zu['container']) ? $asn_zu['container'] : null;
$asn_s = is_array($asn_zu['sonn']) ? $asn_zu['sonn'] : null;
$asn_seriennr = asn_seriennummer($asn_cfg['mac']);
$asn_vgd = asn_abbild_vorgabe();
$asn_dig = asn_abbild_digest($asn_cfg);
/* Nachfassung 1: nur auf Knopfdruck (GET, nur lesend) - Protokoll von sonn, Abbilder, verfuegbare Fassungen. */
$asn_get = function ($k) {
    return (isset($_GET[$k]) && is_string($_GET[$k])) ? $_GET[$k] : '';
};
$asn_sonnlog = ($asn_tab === 'tab-log' && $asn_get('sonnlog') === '1') ? asn_sonn_protokoll() : null;
$asn_abbilder = ($asn_tab === 'tab-settings' && $asn_get('abbilder') === '1') ? asn_abbilder_lage() : null;
$asn_fassungen = ($asn_tab === 'tab-settings' && in_array($asn_get('fassungen'), array('1', '2'), true)) ? asn_fassungen($asn_get('fassungen') === '2') : null;
$asn_verw_url = asn_verwaltung_url($asn_cfg);

$asn_rahmen = class_exists('LBWeb', false);
if ($asn_rahmen) {
    LBWeb::lbheader('Audioserver NG', 'https://wiki.loxberry.de/plugins/audioserverng/start', 'help.html');
}
?>
<style>
/* Hausstandard (VORLAGE_hausstandard.css.html, wortgleich wie Xiaomi Licht 0.9.3). */
.sm-wrap { max-width: 980px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap, .sm-wrap *, .sm-tabs, .sm-tabs * { text-shadow: none !important; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0;
          padding: 9px 18px; font-size: 0.95em; color: #444 !important; text-decoration: none; display: inline-block; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-feld { margin: 14px 0; }
.sm-feld > label { display: block; font-weight: 600; font-size: 0.9em; color: #555; margin: 0 0 4px; }
.sm-feld .ui-input-text, .sm-feld .ui-select, .sm-feld .ui-textinput { max-width: 520px; }
.sm-hilfe { font-size: 0.85em; color: #555; margin: 4px 0 0; max-width: 640px; }
.sm-step { border: 1px solid #ddd; border-left: 4px solid #6dac20; background: #fafafa;
    border-radius: 6px; padding: 12px 14px; margin: 12px 0; font-size: 0.92em; line-height: 1.5; }
.sm-tbl { border-collapse: collapse; width: 100%; margin: 8px 0; font-size: 0.9em; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ccc; padding: 5px 7px; text-align: left; vertical-align: top; }
.sm-tbl th { background: #eef3e6; font-weight: 600; }
.sm-breit { overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 10px 0; }
.sm-breit .sm-tbl { margin: 0; min-width: 760px; }
.sm-bild { margin: 12px 0; }
.sm-bild img { max-width: 100%; height: auto; border: 1px solid #ccc; border-radius: 4px; background: #fff; }
.sm-bild figcaption { font-size: .9em; color: #555; margin-top: 4px; }
.sm-mono { font-family: Consolas, "Courier New", monospace; background: #f0f0f0;
    padding: 1px 4px; border-radius: 3px; font-size: 0.94em; word-break: break-all; }
.sm-pre { background: #f4f4f4; border: 1px solid #ccc; padding: 10px; font-size: 0.85em;
    overflow: auto; margin: 8px 0; white-space: pre-wrap; font-family: Consolas, "Courier New", monospace; }
.sm-log { background: #f4f4f4; border: 1px solid #ccc; padding: 10px; font-size: 0.82em;
    overflow: auto; margin: 8px 0; max-height: 420px; white-space: pre-wrap; font-family: Consolas, "Courier New", monospace; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.sm-knopfreihe form { margin: 0; display: flex; }
.sm-wrap .sm-knopfreihe .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button.sm-btn {
    flex: 0 0 auto; min-width: 250px; text-align: center; display: inline-flex;
    align-items: center; justify-content: center; line-height: 1.25;
    padding: 10px 14px !important; border-radius: 6px !important;
    color: #fff !important; text-decoration: none !important; font-size: 0.92em;
    border: 0 !important; cursor: pointer; font-weight: 600 !important;
    text-shadow: none !important; box-shadow: none !important;
    opacity: 1 !important; margin: 0 !important; width: auto !important; }
.sm-wrap .sm-tbl button.sm-btn { min-width: 0; padding: 5px 10px !important; }
.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-wrap .sm-btn.sm-b-lesen   { background: #6dac20 !important; }
.sm-wrap .sm-btn.sm-b-technik { background: #546e7a !important; }
.sm-wrap .sm-btn.sm-b-aktion  { background: #e0620d !important; }
.sm-wrap .sm-btn.sm-b-lesen:hover,   .sm-wrap .sm-btn.sm-b-lesen:focus   { background: #5c9219 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-technik:hover, .sm-wrap .sm-btn.sm-b-technik:focus { background: #435962 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-aktion:hover,  .sm-wrap .sm-btn.sm-b-aktion:focus  { background: #b84f0a !important; color: #fff !important; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
.sm-seite { display: none; padding-top: 4px; }
.sm-seite.sm-active { display: block; }
.sm-hinweis { border: 1px solid #cfe3b0; background: #f2f8ea; border-radius: 6px; padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-warnung { border: 1px solid #f0c9a0; background: #fdf4ec; border-radius: 6px; padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-an  { color: #1a7f1a; font-weight: 700; }
.sm-aus { color: #b00000; font-weight: 700; }
.sm-hinweiszeichen { color: #546e7a; font-weight: 700; }
.sm-status { margin: 10px 0 0; }
.sm-status td:first-child { width: 28%; font-weight: 600; }
.sm-wrap select {
    appearance: none; -webkit-appearance: none; -moz-appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='9' viewBox='0 0 14 9'%3E%3Cpath d='M1 1l6 6 6-6' fill='none' stroke='%234f7d17' stroke-width='2'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 10px center; padding-right: 32px; cursor: pointer; }
.sm-tbl select { padding-right: 28px; background-position: right 7px center; }
.sm-tbl input[type=text], .sm-tbl input[type=password] { width: 100%; box-sizing: border-box; }
.sm-wrap details { margin: 12px 0; }
.sm-wrap summary { cursor: pointer; font-weight: 600; color: #4f7d17; }
</style>

<div class="sm-wrap">

<?php if ($asn_meldungen) { ?>
<div class="sm-hinweis"><?= implode('<br>', array_map('asn_e', $asn_meldungen)) ?></div>
<?php } ?>
<?php if ($asn_fehler) { ?>
<div class="sm-warnung"><b><?= asn_e(asn_t('ALLG.BEANSTANDUNG')) ?></b><br><?= implode('<br>', array_map('asn_e', $asn_fehler)) ?></div>
<?php } ?>
<?php if ($asn_misslungen) { ?>
<div class="sm-warnung"><b><?= asn_e(asn_t('ALLG.MISSLUNGEN')) ?></b><br><?= implode('<br>', array_map('asn_e', $asn_misslungen)) ?></div>
<?php } ?>
<?php if ($asn_hinweise) { ?>
<div class="sm-warnung"><?= implode('<br>', array_map('asn_e', $asn_hinweise)) ?></div>
<?php } ?>

<!-- Statusuebersicht ueber der Reiterleiste (Kopf wie Dashboard, Entscheidung Nr. 43). -->
<table class="sm-tbl sm-status">
<tr><th><?= asn_e(asn_t('KOPF.EIGENSCHAFT')) ?></th><th><?= asn_e(asn_t('KOPF.WERT')) ?></th></tr>
<tr><td><?= asn_e(asn_t('KOPF.CONTAINER')) ?></td>
<td><?php if (!$asn_aktiv) { ?><b class="sm-aus"><?= asn_e(asn_t('KOPF.VERWALTUNG_AUS')) ?></b><?php } elseif ($asn_c === null) { ?><b class="sm-aus"><?= asn_e($asn_zu['grund'] !== '' ? asn_grund_text($asn_zu['grund']) : asn_t('ALLG.NICHT_FESTSTELLBAR')) ?></b><?php } else { ?><b class="<?= $asn_c['laeuft'] ? 'sm-an' : 'sm-aus' ?>"><?= asn_e(asn_t($asn_c['laeuft'] ? 'ALLG.LAEUFT' : 'ALLG.GESTOPPT')) ?></b> &middot; <?= asn_e(asn_namen()['container']) ?><?php } ?></td></tr>
<tr><td>sonn core</td>
<td><?= asn_e($asn_s !== null ? sprintf(asn_t('KOPF.SONN'), $asn_s['status'] !== '' ? $asn_s['status'] : '-', $asn_s['fassung'] !== '' ? $asn_s['fassung'] : '-',
    asn_t(!empty($asn_s['gekoppelt']) && (int) $asn_s['gekoppelt'] === 1 ? 'KOPF.GEKOPPELT' : 'KOPF.NICHT_GEKOPPELT'), count($asn_zu['zonen'])) : '-') ?></td></tr>
<tr><td><?= asn_e(asn_t('LOX.VERWALTUNG')) ?></td>
<td><?php if ($asn_verw_url !== '') { ?><a href="<?= asn_e($asn_verw_url) ?>" target="_blank" rel="noopener"><span class="sm-mono"><?= asn_e($asn_verw_url) ?></span></a><?php } else { ?>-<?php } ?></td></tr>
<tr><td>MQTT</td>
<td><b class="<?= !empty($asn_cfg['mqtt_ein']) ? 'sm-an' : 'sm-aus' ?>"><?= asn_e(asn_t(!empty($asn_cfg['mqtt_ein']) ? 'ALLG.EIN' : 'ALLG.AUS')) ?></b>
<span class="sm-hilfe"><?= asn_e(sprintf(asn_t('KOPF.AUTOSTART'), !$asn_mqtt['gefunden'] ? asn_t('ALLG.NICHT_FESTSTELLBAR')
    : asn_t($asn_mqtt['autostart'] ? 'ALLG.EIN' : 'ALLG.AUS'))) ?></span></td></tr>
<tr><td><?= asn_e(asn_t('KOPF.BRUECKE')) ?></td>
<td><?= $asn_zeichen($asn_bl) ?> <?= $asn_bt ?></td></tr>
<tr><td><?= asn_e(asn_t('KOPF.VORGANG')) ?></td>
<td><?= asn_e($asn_vg['vorgang'] !== '' ? asn_vorgang_satz($asn_vg) : '-') ?></td></tr>
</table>

<div class="sm-tabs">
	<a class="sm-tab<?= $asn_tab === 'tab-settings' ? ' sm-active' : '' ?>" data-ziel="tab-settings"
	   href="index.php?form=settings"><?= asn_e(asn_t('REITER.EINSTELLUNGEN')) ?></a>
	<a class="sm-tab<?= $asn_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" data-ziel="tab-mqtt"
	   href="index.php?form=mqtt">MQTT</a>
	<a class="sm-tab<?= $asn_tab === 'tab-loxone' ? ' sm-active' : '' ?>" data-ziel="tab-loxone"
	   href="index.php?form=loxone"><?= asn_e(asn_t('REITER.LOXONE')) ?></a>
	<a class="sm-tab<?= $asn_tab === 'tab-test' ? ' sm-active' : '' ?>" data-ziel="tab-test"
	   href="index.php?form=test"><?= asn_e(asn_t('REITER.TEST')) ?></a>
	<a class="sm-tab<?= $asn_tab === 'tab-log' ? ' sm-active' : '' ?>" data-ziel="tab-log"
	   href="index.php?form=log"><?= asn_e(asn_t('REITER.LOG')) ?></a>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="sm-seite<?= $asn_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">
<div class="sm-hinweis"><?= asn_e(asn_t('EINST.WAS_IST_DAS')) ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= asn_e(asn_t('LEGENDE.LESEN')) ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= asn_e(asn_t('LEGENDE.AKTION')) ?></span>
</div>

<?php /* Nachfassung 3: Einrichtung fuer Laien - der Kasten, solange kein eigener Container angelegt ist. */ ?>
<?php if (!$asn_merk['angelegt']) { ?>
<div class="sm-step" id="asn_einrichten">
<h2><?= asn_e(asn_t('EINRICHTEN.H')) ?></h2>
<p><?= asn_e(asn_t('EINRICHTEN.TEXT')) ?></p>
<?php if ($asn_einr_laeuft) { ?>
<div class="sm-hinweis"><b><?= asn_e(asn_t('EINRICHTEN.LAEUFT')) ?></b><br><?= asn_e(asn_vorgang_satz($asn_einr_vg)) ?></div>
<div class="sm-knopfreihe">
<a class="sm-btn sm-b-lesen" href="index.php?form=settings#asn_einrichten"><?= asn_e(asn_t('EINRICHTEN.K_NEU_LADEN')) ?></a>
</div>
<?php } else { ?>
<?php if ($asn_einr_vg !== null && in_array($asn_einr_vg['zustand'], array('fehler', 'abgebrochen'), true)) { ?>
<div class="sm-warnung"><b><?= asn_e(asn_t('EINRICHTEN.FEHLGESCHLAGEN')) ?></b><br><?= asn_e(asn_vorgang_satz($asn_einr_vg)) ?><br><?= asn_e(asn_t('EINRICHTEN.NOCHMAL')) ?></div>
<?php } ?>
<?php list($asn_eok, $asn_eg, $asn_ev) = is_array($asn_einr) ? $asn_einr : array(false, 'NICHT_MOEGLICH', null); ?>
<?php if (!$asn_eok) { ?>
<div class="sm-warnung"><b><?= asn_e(asn_t('EINRICHTEN.GEHT_NICHT')) ?></b><br><?= asn_e(asn_grund_text($asn_eg)) ?></div>
<?php } else { ?>
<table class="sm-tbl sm-status">
<tr><th><?= asn_e(asn_t('EINRICHTEN.SP_WAS')) ?></th><th><?= asn_e(asn_t('EINRICHTEN.SP_WERT')) ?></th><th><?= asn_e(asn_t('EINRICHTEN.SP_WOHER')) ?></th></tr>
<tr><td><?= asn_e(asn_t('EINRICHTEN.Z_NETZ')) ?></td><td><span class="sm-mono"><?= asn_e($asn_ev['netz_anzeige']) ?></span> (<?= asn_e($asn_ev['subnetz'] !== '' ? $asn_ev['subnetz'] : '-') ?>)</td>
<td><?= asn_e(asn_t($asn_ev['quelle'] === 'probe' ? 'EINRICHTEN.W_PROBE' : ($asn_ev['netz_neu'] ? 'EINRICHTEN.W_NETZ_NEU' : ($asn_ev['netz_art'] === 'eigen' ? 'EINRICHTEN.W_NETZ_EIGEN' : 'EINRICHTEN.W_NETZ_DA')))) ?></td></tr>
<tr><td><?= asn_e(asn_t('EINRICHTEN.Z_IP')) ?></td><td><span class="sm-mono"><?= asn_e($asn_ev['ip']) ?></span></td>
<td><?= asn_e($asn_ev['ip_herkunft'] === 'frei' ? sprintf(asn_t('EINRICHTEN.W_IP_FREI'), $asn_ev['ip_geprueft'], $asn_ev['ip_belegt'])
    : asn_t($asn_ev['ip_herkunft'] === 'probe' ? 'EINRICHTEN.W_PROBE' : 'EINRICHTEN.W_KONFIG')) ?></td></tr>
<tr><td><?= asn_e(asn_t('EINRICHTEN.Z_MAC')) ?></td><td><span class="sm-mono"><?= asn_e(asn_mac_kurz($asn_ev['mac'])) ?></span></td>
<td><?= asn_e(asn_t('EINRICHTEN.W_MAC_' . strtoupper($asn_ev['mac_herkunft']))) ?></td></tr>
<tr><td><?= asn_e(asn_t('EINRICHTEN.Z_MUSIK')) ?></td><td><?= $asn_ev['musik_pfad'] !== '' ? '<span class="sm-mono">' . asn_e($asn_ev['musik_pfad']) . '</span>' : asn_e(asn_t('EINRICHTEN.MUSIK_KEINER')) ?></td>
<td><?= asn_e(asn_t('EINRICHTEN.W_MUSIK_' . strtoupper($asn_ev['musik_herkunft']))) ?></td></tr>
</table>
<?php if ($asn_ev['quelle'] === 'probe') { ?>
<p class="sm-hilfe"><?= asn_e(asn_t('EINRICHTEN.H_PROBE')) ?></p>
<?php } ?>
<?php if ($asn_ev['ip_herkunft'] === 'frei') { ?>
<p class="sm-hilfe"><?= asn_e(asn_t('EINRICHTEN.H_IP_FREI')) ?></p>
<?php } ?>
<?php if ($asn_ev['netz_neu']) { ?>
<p class="sm-hilfe"><?= asn_e(asn_t('EINRICHTEN.H_NETZ_NEU')) ?></p>
<?php } ?>
<?php if ($asn_ev['mac_probe_fehlt']) { ?>
<div class="sm-warnung"><?= asn_e(asn_t('EINRICHTEN.H_MAC_PROBE_FEHLT')) ?></div>
<?php } ?>
<?php if ($asn_ev['musik_grund'] !== '') { ?>
<p class="sm-hilfe"><?= asn_e(sprintf(asn_t('EINRICHTEN.H_MUSIK_PROBE'), asn_grund_text($asn_ev['musik_grund']))) ?></p>
<?php } ?>
<?php if ($asn_ev['musik_pfad'] === '') { ?>
<p class="sm-hilfe"><?= asn_e(asn_t('EINRICHTEN.H_MUSIK_LEER')) ?></p>
<?php } ?>
<?php if ($asn_ev['probe_laeuft']) { ?>
<div class="sm-warnung"><?= asn_e(asn_grund_text('PROBE_LAEUFT')) ?></div>
<?php } else { ?>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<input data-role="none" type="hidden" name="fmt" value="<?= asn_e($asn_fmt) ?>">
<input data-role="none" type="hidden" name="formular" value="einrichten">
<input data-role="none" type="hidden" name="kennung" value="<?= asn_e($asn_ev['kennung']) ?>">
<div class="sm-feld"><label><input data-role="none" type="checkbox" name="einrichten_ok" value="1"> <?= asn_e(asn_t('EINRICHTEN.HAKEN')) ?></label></div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="aktion" value="einrichten"><?= asn_e(asn_t('EINRICHTEN.K_EINRICHTEN')) ?></button>
</div>
</form>
<?php } ?>
<?php } ?>
<div class="sm-knopfreihe">
<a class="sm-btn sm-b-lesen" href="index.php?form=settings&amp;vorschlag=neu#asn_einrichten"><?= asn_e(asn_t('EINRICHTEN.K_NEU_ERMITTELN')) ?></a>
</div>
<p class="sm-hilfe"><?= asn_e(asn_t('EINRICHTEN.FUSS')) ?></p>
<?php } ?>
</div>
<!-- /asn_einrichten -->
<?php } else { ?>
<?php /* Nachfassung 3: nach dem Einrichten die Schritt-Liste mit gemessenem Zustand. */ ?>
<div class="sm-step" id="asn_weiter">
<h2><?= asn_e(asn_t('WEITER.H')) ?></h2>
<p class="sm-hilfe"><?= asn_e(asn_t('WEITER.TEXT')) ?></p>
<?php if ($asn_s === null) { ?>
<div class="sm-hinweis"><?= asn_e(asn_t('WEITER.SONN_STILL')) ?></div>
<?php } ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= asn_e(asn_t('WEITER.SP_SCHRITT')) ?></th><th><?= asn_e(asn_t('WEITER.SP_ZUSTAND')) ?></th><th><?= asn_e(asn_t('WEITER.SP_TUN')) ?></th><th></th></tr>
<?php foreach ($asn_weiter as $asn_ws) { list($asn_wn, $asn_wz, $asn_wk, $asn_wx) = $asn_ws; ?>
<tr><td><b><?= asn_e(array("\u{2460}", "\u{2461}", "\u{2462}", "\u{2463}", "\u{2464}")[$asn_wn - 1]) ?></b> <?= asn_e(asn_t($asn_wk . '_KURZ')) ?></td>
<td><?= $asn_zeichen($asn_wz) ?> <?= asn_e(asn_t($asn_wz === true ? 'WEITER.ERLEDIGT' : ($asn_wz === false ? 'WEITER.OFFEN' : 'WEITER.SELBST'))) ?></td>
<td><?= asn_e(asn_t($asn_wk)) ?><?php if ($asn_wn === 1 && is_int($asn_wx) && $asn_wx >= 0) { ?> <span class="sm-hilfe">(<?= asn_e(sprintf(asn_t('WEITER.KONTEN'), $asn_wx)) ?>)</span><?php } ?><?php if ($asn_wn === 3) { list($asn_wa, $asn_wo) = explode('|', $asn_wx); ?> <span class="sm-hilfe">(<?= asn_e(sprintf(asn_t('WEITER.ZONEN'), (int) $asn_wa, (int) $asn_wo)) ?>)</span><?php } ?><?php if ($asn_wn === 4 && trim((string) $asn_cfg['musik_pfad']) === '') { ?> <span class="sm-hilfe">(<?= asn_e(asn_t('WEITER.MUSIK_FEHLT')) ?>)</span><?php } elseif ($asn_wn === 4 && is_int($asn_wx) && $asn_wx >= 0) { ?> <span class="sm-hilfe">(<?= asn_e(sprintf(asn_t($asn_wx > 0 ? 'WEITER.TITEL' : 'WEITER.TITEL_KEINE'), $asn_wx)) ?>)</span><?php } ?></td>
<td><?php if ($asn_wn === 2) { ?><?php if ($asn_seriennr !== '') { ?><button data-role="none" class="sm-btn sm-b-lesen sm-kopie" type="button" data-kopie="<?= asn_e($asn_seriennr) ?>"><?= asn_e(asn_t('WEITER.K_SERIENNUMMER')) ?></button><?php } else { ?>-<?php } ?>
<?php } elseif ($asn_wn === 5) { ?><a class="sm-btn sm-b-lesen" href="index.php?form=test"><?= asn_e(asn_t('WEITER.K_TEST')) ?></a>
<?php } elseif ($asn_verw_url !== '') { ?><a class="sm-btn sm-b-lesen" href="<?= asn_e($asn_verw_url) ?>" target="_blank" rel="noopener"><?= asn_e(asn_t('WEITER.K_VERWALTUNG')) ?></a>
<?php } else { ?>-<?php } ?></td></tr>
<?php } ?>
</table>
</div>
</div>
<?php } ?>

<div class="sm-warnung"><b><?= asn_e(asn_t('EINST.H_GRENZEN')) ?></b><br><?= asn_e(asn_t('EINST.GRENZEN_TEXT')) ?></div>

<?php if ($asn_merk['angelegt']) { ?>
<h2><?= asn_e(asn_t('EINST.H_CONTAINER')) ?></h2>
<p class="sm-hilfe"><?= asn_e(asn_t('EINST.CONTAINER_TEXT')) ?></p>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="hidden" name="fmt" value="<?= asn_e($asn_fmt) ?>">
    <input data-role="none" type="hidden" name="formular" value="container">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="aktion" value="starten"><?= asn_e(asn_t('EINST.K_STARTEN')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="hidden" name="fmt" value="<?= asn_e($asn_fmt) ?>">
    <input data-role="none" type="hidden" name="formular" value="container">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="aktion" value="neustart"><?= asn_e(asn_t('EINST.K_NEUSTART')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="hidden" name="fmt" value="<?= asn_e($asn_fmt) ?>">
    <input data-role="none" type="hidden" name="formular" value="container">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="aktion" value="stoppen"><?= asn_e(asn_t('EINST.K_STOPPEN')) ?></button>
  </form>
</div>
<?php } ?>

<?php /* 0.9.2: Favoritenliste (Wunsch Hausherr 01.11.2026; Bedienung wie Chromecast4lox 1.3.25, Quellen). Werkszustand leer. */
$asn_fav_text = isset($asn_zusatz['fav_eingabe']) && is_string($asn_zusatz['fav_eingabe']) ? $asn_zusatz['fav_eingabe'] : (string) $asn_cfg['favoriten'];
$asn_fav_zonen = isset($asn_zusatz['fav_zonen']) && is_string($asn_zusatz['fav_zonen']) ? $asn_zusatz['fav_zonen'] : (string) $asn_cfg['favoriten_zonen'];
list($asn_fav_liste) = asn_favoriten_lesen((string) $asn_cfg['favoriten']);
$asn_fav_stand = asn_favoriten_stand();
$asn_fav_laeuft = $asn_vg['vorgang'] === 'favoriten' && in_array($asn_vg['zustand'], array('gestartet', 'laeuft'), true); ?>
<div class="sm-step" id="asn_favoriten">
<h2><?= asn_e(asn_t('FAV.H')) ?></h2>
<p><?= asn_e(asn_t('FAV.TEXT')) ?></p>
<p class="sm-hilfe"><?= asn_e(asn_t('FAV.H_NUMMER')) ?></p>
<p class="sm-hilfe"><?= asn_e(asn_t('FAV.H_CUSTOM')) ?></p>
<p class="sm-hilfe"><?= asn_e(asn_t('FAV.H_LISTE')) ?><?= trim((string) $asn_cfg['musik_pfad']) === '' ? ' ' . asn_e(asn_t('FAV.H_KEIN_MUSIKORDNER')) : '' ?></p>
<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<input data-role="none" type="hidden" name="fmt" value="<?= asn_e($asn_fmt) ?>">
<input data-role="none" type="hidden" name="formular" value="favoriten">
<div class="sm-feld"><label for="asn_favoriten"><?= asn_e(asn_t('FAV.L_LISTE')) ?></label>
<textarea data-role="none" id="asn_favoriten" name="favoriten" rows="8" style="width:100%;font-family:monospace;" placeholder="1 = radio | Sender | https://…/stream.mp3&#10;2 = liste | Album | Interpret/Album&#10;3 = datei | Lied | Interpret/Album/01 Lied.mp3"><?= asn_e($asn_fav_text) ?></textarea>
<p class="sm-hilfe"><?= asn_e(sprintf(asn_t('FAV.H_FELD'), ASN_FAV_MAX)) ?></p></div>
<div class="sm-feld"><label for="asn_favoriten_zonen"><?= asn_e(asn_t('FAV.L_ZONEN')) ?></label>
<input data-role="none" type="text" id="asn_favoriten_zonen" name="favoriten_zonen" value="<?= asn_e($asn_fav_zonen) ?>" maxlength="100" placeholder="1,2">
<p class="sm-hilfe"><?= asn_e(asn_t('FAV.H_ZONEN')) ?></p></div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="aktion" value="speichern"><?= asn_e(asn_t('ALLG.K_SPEICHERN')) ?></button>
</div>
<div class="sm-warnung"><?= asn_e(asn_t('FAV.H_ERSETZT')) ?></div>
<div class="sm-feld"><label><input data-role="none" type="checkbox" name="favoriten_ok" value="1"> <?= asn_e(asn_t('FAV.HAKEN')) ?></label></div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="aktion" value="uebernehmen"<?= $asn_fav_laeuft ? ' disabled' : '' ?>><?= asn_e(asn_t('FAV.K_UEBERNEHMEN')) ?></button>
</div>
</form>
<?php if ($asn_fav_liste) { ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th style="width:8%;"><?= asn_e(asn_t('FAV.SP_NR')) ?></th><th style="width:10%;"><?= asn_e(asn_t('FAV.SP_ART')) ?></th><th style="width:30%;"><?= asn_e(asn_t('FAV.SP_NAME')) ?></th><th><?= asn_e(asn_t('FAV.SP_QUELLE')) ?></th></tr>
<?php foreach ($asn_fav_liste as $asn_fe) { ?>
<tr><td><b><?= (int) $asn_fe['nr'] ?></b></td><td><?= asn_e($asn_fe['art']) ?></td><td><?= asn_e($asn_fe['name']) ?></td><td><span class="sm-mono"><?= asn_e($asn_fe['quelle']) ?></span></td></tr>
<?php } ?>
</table>
</div>
<?php } else { ?>
<p class="sm-hilfe"><?= asn_e(asn_t('FAV.LEER')) ?></p>
<?php } ?>
<?php if ($asn_fav_laeuft) { ?>
<div class="sm-hinweis"><?= asn_e(asn_vorgang_satz($asn_vg)) ?></div>
<?php } elseif ($asn_fav_stand !== null) { ?>
<h3><?= asn_e(sprintf(asn_t('FAV.H_STAND'), date('Y-m-d H:i:s', $asn_fav_stand['zeit']))) ?></h3>
<div class="<?= $asn_fav_stand['ok'] ? 'sm-hinweis' : 'sm-warnung' ?>"><?= asn_e($asn_fav_stand['ok'] ? asn_t('FAV.STAND_OK') : sprintf(asn_t('FAV.STAND_FEHLER'), asn_grund_text($asn_fav_stand['grund']))) ?></div>
<?php foreach ($asn_fav_stand['zonen'] as $asn_fz) { ?>
<p class="sm-hilfe"><?= asn_e(sprintf(asn_t('FAV.STAND_ZONE'), $asn_fz['zone'], $asn_fz['nachher'] ? implode(' · ', array_map(function ($x) { return $x['id'] . ' ' . $x['name']; }, $asn_fz['nachher'])) : '-')) ?></p>
<?php } ?>
<?php foreach ($asn_fav_stand['uebersprungen'] as $asn_fu) { ?>
<p class="sm-hilfe"><?= asn_e(sprintf(asn_t('FAV.STAND_UEBERSPRUNGEN'), $asn_fu['nr'], asn_grund_text($asn_fu['grund']))) ?></p>
<?php } ?>
<?php $asn_fc = asn_custom_satz($asn_fav_stand['custom'], false);
if ($asn_fc !== '') { ?>
<p class="sm-hilfe"><?= asn_e($asn_fc) ?></p>
<?php } ?>
<?php if ($asn_fav_stand['vorher'] !== '') { ?>
<p class="sm-hilfe"><?= asn_e(sprintf(asn_t('FAV.STAND_VORHER'), $asn_fav_stand['vorher'])) ?></p>
<?php } ?>
<?php } ?>
</div>

<?php /* 0.9.3: Vorgabesender von TuneIn. Ab Werk aus; der Stand kommt aus dem Takt der letzten Minute. */
$asn_tu = asn_tunein_stand();
$asn_tu_laeuft = $asn_vg['vorgang'] === 'tunein' && in_array($asn_vg['zustand'], array('gestartet', 'laeuft'), true); ?>
<div class="sm-step" id="asn_tunein">
<h2><?= asn_e(asn_t('TUNEIN.H')) ?></h2>
<p><?= asn_e(asn_t('TUNEIN.TEXT')) ?></p>
<?php if ($asn_tu_laeuft) { ?>
<div class="sm-hinweis"><?= asn_e(asn_vorgang_satz($asn_vg)) ?></div>
<?php } elseif (!empty($asn_cfg['tunein_ausblenden']) && ($asn_tu['lage'] === 'unbekannt' || $asn_tu['lage'] === 'fehlt')) { ?>
<div class="sm-warnung"><?= asn_e(asn_grund_text($asn_tu['lage'] === 'fehlt' ? 'TUNEIN_FEHLT' : 'TUNEIN_UNBEKANNT')) ?></div>
<?php } else { ?>
<p class="sm-hilfe"><?= asn_e(asn_t('TUNEIN.STAND_' . strtoupper($asn_tu['lage'] !== '' ? $asn_tu['lage'] : 'keiner'))) ?></p>
<?php } ?>
<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<input data-role="none" type="hidden" name="fmt" value="<?= asn_e($asn_fmt) ?>">
<input data-role="none" type="hidden" name="formular" value="tunein">
<div class="sm-feld"><label><input data-role="none" type="checkbox" name="tunein_ausblenden" value="1"<?= !empty($asn_cfg['tunein_ausblenden']) ? ' checked' : '' ?>> <?= asn_e(asn_t('TUNEIN.HAKEN')) ?></label>
<p class="sm-hilfe"><?= asn_e(asn_t('TUNEIN.H_NEUSTART')) ?></p></div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="speichern" value="1"<?= $asn_tu_laeuft ? ' disabled' : '' ?>><?= asn_e(asn_t('ALLG.K_SPEICHERN')) ?></button>
</div>
</form>
</div>

<?php /* Nachfassung 3: die technischen Felder zugeklappt; offen, wenn eine Eingabe zurueckkommt (Beanstandung oder
   "Werte der Probe uebernehmen"). Das Ende des Aufklappbereichs steht in 06_probe_abbild.php nach dem Probe-Abschnitt. */ ?>
<details id="asn_erweitert"<?= $asn_eingaben ? ' open' : '' ?>>
<summary><?= asn_e(asn_t('EINST.H_ERWEITERT')) ?></summary>
<p class="sm-hilfe"><?= asn_e(asn_t('EINST.ERWEITERT_TEXT')) ?></p>
<?php if ($asn_probe[0] === true) { ?>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="hidden" name="fmt" value="<?= asn_e($asn_fmt) ?>">
    <input data-role="none" type="hidden" name="formular" value="probe">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="aktion" value="werte"><?= asn_e(asn_t('PROBE.K_WERTE')) ?></button>
  </form>
</div>
<p class="sm-hilfe"><?= asn_e(asn_t('PROBE.WERTE_TEXT')) ?></p>
<?php } ?>

<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<input data-role="none" type="hidden" name="fmt" value="<?= asn_e($asn_fmt) ?>">
<input data-role="none" type="hidden" name="formular" value="einstellungen">
<div class="sm-feld"><label><input data-role="none" type="checkbox" name="container_ein" value="1"<?= $asn_ein('container_ein') === '1' ? ' checked' : '' ?>>
<?= asn_e(asn_t('EINST.L_VERWALTUNG')) ?></label><p class="sm-hilfe"><?= asn_e(asn_t('EINST.VERWALTUNG_HILFE')) ?></p></div>
<div class="sm-feld"><label for="asn_schnittstelle"><?= asn_e(asn_t('EINST.L_SCHNITTSTELLE')) ?></label>
<select data-role="none" id="asn_schnittstelle" name="schnittstelle">
<?php foreach (array_unique(array_merge($asn_schnitt, array($asn_ein('schnittstelle')))) as $asn_sn) { ?>
<option value="<?= asn_e($asn_sn) ?>"<?= $asn_sn === $asn_ein('schnittstelle') ? ' selected' : '' ?>><?= asn_e($asn_sn) ?></option>
<?php } ?>
</select></div>
<div class="sm-feld"><label for="asn_netz_art"><?= asn_e(asn_t('EINST.L_NETZ_ART')) ?></label>
<select data-role="none" id="asn_netz_art" name="netz_art">
<option value="mitnutzen"<?= $asn_ein('netz_art') === 'mitnutzen' ? ' selected' : '' ?>><?= asn_e(asn_t('EINST.NETZ_MITNUTZEN')) ?></option>
<option value="eigen"<?= $asn_ein('netz_art') === 'eigen' ? ' selected' : '' ?>><?= asn_e(sprintf(asn_t('EINST.NETZ_EIGEN'), asn_namen()['netz'])) ?></option>
</select><p class="sm-hilfe"><?= asn_e(asn_t('EINST.NETZ_HILFE')) ?></p></div>
<div class="sm-feld"><label for="asn_netz_name"><?= asn_e(asn_t('EINST.L_NETZ_NAME')) ?></label>
<select data-role="none" id="asn_netz_name" name="netz_name">
<option value=""><?= asn_e(asn_t('EINST.NETZ_KEINS')) ?></option>
<?php foreach (array_unique(array_merge(array_keys($asn_netze), $asn_ein('netz_name') !== '' ? array($asn_ein('netz_name')) : array())) as $asn_nn) {
    $asn_nd = isset($asn_netze[$asn_nn]) ? $asn_netze[$asn_nn] : null; ?>
<option value="<?= asn_e($asn_nn) ?>"<?= $asn_nn === $asn_ein('netz_name') ? ' selected' : '' ?>><?= asn_e($asn_nn . ($asn_nd !== null
    ? ' (' . $asn_nd['parent'] . ', ' . $asn_nd['subnetz'] . ($asn_nd['plugin'] !== '' ? ', ' . $asn_nd['plugin'] : '') . ')' : '')) ?></option>
<?php } ?>
</select></div>
<div class="sm-feld"><label for="asn_ip"><?= asn_e(asn_t('EINST.L_IP')) ?></label>
<input data-role="none" type="text" id="asn_ip" name="ip" value="<?= asn_e($asn_ein('ip')) ?>" maxlength="15" placeholder="192.0.2.50">
<p class="sm-hilfe"><?= asn_e(asn_t('EINST.IP_HILFE')) ?></p></div>
<div class="sm-feld"><label for="asn_mac"><?= asn_e(asn_t('EINST.L_MAC')) ?></label>
<input data-role="none" type="text" id="asn_mac" name="mac" value="<?= asn_e($asn_ein('mac')) ?>" maxlength="17" placeholder="02:xx:xx:xx:xx:xx">
<label><input data-role="none" type="checkbox" name="mac_neu" value="1"> <?= asn_e(asn_t('EINST.L_MAC_NEU')) ?></label>
<p class="sm-hilfe"><?= asn_e(asn_t('EINST.MAC_HILFE')) ?></p></div>
<div class="sm-feld"><label for="asn_hostname"><?= asn_e(asn_t('EINST.L_HOSTNAME')) ?></label>
<input data-role="none" type="text" id="asn_hostname" name="hostname" value="<?= asn_e($asn_ein('hostname')) ?>" maxlength="32"></div>
<div class="sm-feld"><label for="asn_speicher"><?= asn_e(asn_t('EINST.L_SPEICHER')) ?></label>
<input data-role="none" type="text" id="asn_speicher" name="speicher_mb" value="<?= asn_e($asn_ein('speicher_mb')) ?>" maxlength="5" inputmode="numeric"></div>
<div class="sm-feld"><label for="asn_cpus"><?= asn_e(asn_t('EINST.L_CPUS')) ?></label>
<input data-role="none" type="text" id="asn_cpus" name="cpus" value="<?= asn_e($asn_ein('cpus')) ?>" maxlength="2" inputmode="numeric"></div>
<?php /* Musikordner: Speicherauswahl von LoxBerry (asn_musik_wahl_html), eingesetzt beim ersten Oeffnen des Reiters;
   ihre Wahl fuellt das Feld musik_pfad, nur das wird gespeichert (Bauart Chromecast4lox 1.3.25 Nachfassung 4). */
$asn_mu_pfad = $asn_ein('musik_pfad');
$asn_mu_wahl = $asn_tab === 'tab-settings' ? asn_musik_wahl_html($asn_mu_pfad) : '';
$asn_mu_json = $asn_mu_wahl !== '' ? json_encode($asn_mu_wahl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) : false; ?>
<h3><?= asn_e(asn_t('EINST.H_MUSIK')) ?></h3>
<p class="sm-hilfe"><?= asn_e(asn_t('EINST.MUSIK_HILFE')) ?></p>
<?php if (is_string($asn_mu_json)) { ?>
<div id="asn_musik_wahl_ort"></div>
<script>var asnMusikWahl = <?= $asn_mu_json ?>;</script>
<?php } ?>
<div class="sm-feld"><label for="asn_musik_pfad"><?= asn_e(asn_t('EINST.L_MUSIK_HAND')) ?></label>
<input data-role="none" type="text" id="asn_musik_pfad" name="musik_pfad" value="<?= asn_e($asn_mu_pfad) ?>" maxlength="400">
<p class="sm-hilfe"><?= asn_e(sprintf(asn_t('EINST.MUSIK_ORTE'), implode(', ', asn_musik_wurzeln()))) ?></p></div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="speichern" value="1"><?= asn_e(asn_t('ALLG.K_SPEICHERN')) ?></button>
</div>
</form>
<p class="sm-hilfe"><?= asn_e(asn_t('EINST.CONTAINER_TEXT')) ?></p>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="hidden" name="fmt" value="<?= asn_e($asn_fmt) ?>">
    <input data-role="none" type="hidden" name="formular" value="container">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="aktion" value="anlegen"><?= asn_e(asn_t('EINST.K_ANLEGEN')) ?></button>
  </form>
</div>

<h2><?= asn_e(asn_t('PROBE.H')) ?></h2>
<?php list($asn_pda, $asn_pk) = $asn_probe; ?>
<?php if ($asn_pda === true) { ?>
<div class="<?= $asn_pk['laeuft'] ? 'sm-warnung' : 'sm-hinweis' ?>"><?= asn_e(sprintf(asn_t($asn_pk['laeuft'] ? 'PROBE.LAEUFT' : 'PROBE.STEHT'), ASN_PROBE_NAME,
    implode(', ', array_keys($asn_pk['netze'])), isset($asn_pk['mounts']['/app/data']) ? $asn_pk['mounts']['/app/data']['quelle'] : '-')) ?></div>
<p class="sm-hilfe"><?= asn_e(asn_t('PROBE.TEXT')) ?></p>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<input data-role="none" type="hidden" name="fmt" value="<?= asn_e($asn_fmt) ?>">
<input data-role="none" type="hidden" name="formular" value="probe">
<div class="sm-feld"><label><input data-role="none" type="checkbox" name="wechsel_ok" value="1"> <?= asn_e(asn_t('PROBE.WECHSEL_HAKEN')) ?></label></div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="aktion" value="wechsel"><?= asn_e(asn_t('PROBE.K_WECHSEL')) ?></button>
</div>
</form>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<input data-role="none" type="hidden" name="fmt" value="<?= asn_e($asn_fmt) ?>">
<input data-role="none" type="hidden" name="formular" value="probe">
<div class="sm-feld"><label><input data-role="none" type="checkbox" name="entfernen_ok" value="1"> <?= asn_e(asn_t('PROBE.ENTFERNEN_HAKEN')) ?></label></div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="aktion" value="entfernen"><?= asn_e(asn_t('PROBE.K_ENTFERNEN')) ?></button>
</div>
</form>
<?php } else { ?>
<p class="sm-hilfe"><?= asn_e(asn_t($asn_pda === false ? 'PROBE.KEINE' : 'PROBE.UNKLAR')) ?></p>
<?php } ?>

</details>

<h2><?= asn_e(asn_t('ABBILD.H')) ?></h2>
<p><?= asn_e(sprintf(asn_t('ABBILD.STAND'), $asn_vgd['repo'], asn_kurzdigest($asn_dig), $asn_dig === $asn_vgd['digest'] ? asn_t('ABBILD.VORGABE') : asn_t('ABBILD.NACH_UPDATE'),
    $asn_vgd['tag'], asn_kurzdigest($asn_vgd['digest']))) ?></p>
<p class="sm-hilfe"><?= asn_e(asn_t('ABBILD.TEXT')) ?></p>
<p class="sm-hilfe" id="asn_fassungen"><?= asn_e(asn_t('FASSUNGEN.TEXT')) ?></p>
<div class="sm-knopfreihe">
<a class="sm-btn sm-b-lesen" href="index.php?form=settings&amp;fassungen=1#asn_fassungen"><?= asn_e(asn_t('FASSUNGEN.K_ZEIGEN')) ?></a>
<?php if ($asn_fassungen !== null) { ?>
<a class="sm-btn sm-b-lesen" href="index.php?form=settings&amp;fassungen=2#asn_fassungen"><?= asn_e(asn_t('FASSUNGEN.K_NEU')) ?></a>
<?php } ?>
</div>
<?php if ($asn_fassungen !== null) { list($asn_fok, $asn_fg, $asn_fl, $asn_fz, $asn_fsp) = $asn_fassungen; ?>
<?php if (!$asn_fok) { ?>
<div class="sm-warnung"><?= asn_e(asn_t('FASSUNGEN.F') . ' ' . asn_grund_text($asn_fg)) ?></div>
<?php } else { ?>
<p class="sm-hilfe"><?= asn_e(sprintf(asn_t($asn_fsp ? 'FASSUNGEN.STAND_SPEICHER' : 'FASSUNGEN.STAND_NEU'), $asn_vgd['repo'], date('d.m.Y H:i', $asn_fz), count($asn_fl))) ?></p>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= asn_e(asn_t('FASSUNGEN.SP_TAG')) ?></th><th><?= asn_e(asn_t('FASSUNGEN.SP_DIGEST')) ?></th><th><?= asn_e(asn_t('FASSUNGEN.SP_HIER')) ?></th><th></th></tr>
<?php foreach ($asn_fl as $asn_fe) { $asn_fd = isset($asn_fe['digest']) ? (string) $asn_fe['digest'] : ''; ?>
<tr><td><span class="sm-mono"><?= asn_e(isset($asn_fe['tag']) ? $asn_fe['tag'] : '-') ?></span></td>
<td><span class="sm-mono"><?= asn_e(asn_digest_gueltig($asn_fd) ? asn_kurzdigest($asn_fd) . "\u{2026}" : '-') ?></span></td>
<td><?= asn_e($asn_fd !== '' && $asn_fd === $asn_dig ? asn_t('FASSUNGEN.AKTUELL') : ($asn_fd !== '' && $asn_fd === $asn_cfg['abbild_vorher'] ? asn_t('FASSUNGEN.VORHER') : '')) ?></td>
<td><?php if (asn_digest_gueltig($asn_fd)) { ?><button data-role="none" class="sm-btn sm-b-lesen asn-uebernehmen" type="button" data-digest="<?= asn_e($asn_fd) ?>"><?= asn_e(asn_t('FASSUNGEN.K_UEBERNEHMEN')) ?></button><?php } ?></td></tr>
<?php } ?>
</table>
</div>
<p class="sm-hilfe"><?= asn_e(asn_t('FASSUNGEN.HINWEIS')) ?></p>
<?php } ?>
<?php } ?>
<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<input data-role="none" type="hidden" name="fmt" value="<?= asn_e($asn_fmt) ?>">
<input data-role="none" type="hidden" name="formular" value="abbild">
<div class="sm-feld"><label for="asn_digest"><?= asn_e(asn_t('ABBILD.L_DIGEST')) ?></label>
<input data-role="none" type="text" id="asn_digest" name="digest" value="" maxlength="71" placeholder="sha256:..."></div>
<div class="sm-feld"><label><input data-role="none" type="checkbox" name="update_ok" value="1"> <?= asn_e(asn_t('ABBILD.UPDATE_HAKEN')) ?></label></div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="aktion" value="update"><?= asn_e(asn_t('ABBILD.K_UPDATE')) ?></button>
</div>
</form>
<?php if (asn_digest_gueltig($asn_cfg['abbild_vorher'])) { ?>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<input data-role="none" type="hidden" name="fmt" value="<?= asn_e($asn_fmt) ?>">
<input data-role="none" type="hidden" name="formular" value="abbild">
<p class="sm-hilfe"><?= asn_e(sprintf(asn_t('ABBILD.RUECKWEG_TEXT'), asn_kurzdigest($asn_cfg['abbild_vorher']), $asn_cfg['datenordner_vorher'])) ?></p>
<div class="sm-feld"><label><input data-role="none" type="checkbox" name="rueckweg_ok" value="1"> <?= asn_e(asn_t('ABBILD.RUECKWEG_HAKEN')) ?></label></div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="aktion" value="rueckweg"><?= asn_e(asn_t('ABBILD.K_RUECKWEG')) ?></button>
</div>
</form>
<?php } ?>

<h2 id="asn_abbilder"><?= asn_e(asn_t('ABBILDER.H')) ?></h2>
<p class="sm-hilfe"><?= asn_e(asn_t('ABBILDER.TEXT')) ?></p>
<div class="sm-knopfreihe">
<a class="sm-btn sm-b-lesen" href="index.php?form=settings&amp;abbilder=1#asn_abbilder"><?= asn_e(asn_t('ABBILDER.K_ZEIGEN')) ?></a>
</div>
<?php if ($asn_abbilder !== null) { list($asn_aok, $asn_ag, $asn_al, $asn_afrei) = $asn_abbilder; ?>
<?php if (!$asn_aok) { ?>
<div class="sm-warnung"><?= asn_e(asn_grund_text($asn_ag)) ?></div>
<?php } elseif (!$asn_al) { ?>
<p class="sm-hilfe"><?= asn_e(sprintf(asn_t('ABBILDER.KEINE'), $asn_vgd['repo'])) ?></p>
<?php } else { $asn_azahl = 0; ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= asn_e(asn_t('ABBILDER.SP_ID')) ?></th><th><?= asn_e(asn_t('ABBILDER.SP_NAMEN')) ?></th><th><?= asn_e(asn_t('ABBILDER.SP_ERSTELLT')) ?></th>
<th><?= asn_e(asn_t('ABBILDER.SP_GROESSE')) ?></th><th><?= asn_e(asn_t('ABBILDER.SP_ART')) ?></th></tr>
<?php foreach ($asn_al as $asn_ab) { if ($asn_ab['art'] === 'entfernbar') { $asn_azahl++; } ?>
<tr><td><span class="sm-mono"><?= asn_e($asn_ab['id']) ?></span></td>
<td><span class="sm-mono"><?= asn_e(implode(', ', array_map(function ($r) { return (string) preg_replace('/@sha256:([0-9a-f]{12})[0-9a-f]{52}/', "@\$1\u{2026}", $r); }, $asn_ab['refs']))) ?></span></td>
<td><?= asn_e($asn_ab['erstellt'] !== '' ? $asn_ab['erstellt'] : '-') ?></td>
<td><?= asn_e(asn_mb($asn_ab['groesse'])) ?></td>
<td><?= $asn_ab['art'] === 'entfernbar' ? '<b class="sm-aus">' . asn_e(asn_t('ABBILDER.ART_ENTFERNBAR')) . '</b>' : asn_e(asn_t('ABBILDER.ART_' . strtoupper($asn_ab['art']))) ?></td></tr>
<?php } ?>
</table>
</div>
<p><?= asn_e(sprintf(asn_t('ABBILDER.FREI'), $asn_azahl, asn_mb($asn_afrei))) ?></p>
<?php if ($asn_azahl > 0) { ?>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<input data-role="none" type="hidden" name="fmt" value="<?= asn_e($asn_fmt) ?>">
<input data-role="none" type="hidden" name="formular" value="abbilder">
<div class="sm-feld"><label><input data-role="none" type="checkbox" name="aufraeumen_ok" value="1"> <?= asn_e(sprintf(asn_t('ABBILDER.HAKEN'), $asn_azahl, asn_mb($asn_afrei))) ?></label></div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="aktion" value="aufraeumen"><?= asn_e(asn_t('ABBILDER.K_AUFRAEUMEN')) ?></button>
</div>
</form>
<?php } ?>
<?php } ?>
<?php } ?>

<h2><?= asn_e(asn_t('KONTO.H')) ?></h2>
<p class="sm-hilfe"><?= asn_e(asn_t('KONTO.TEXT')) ?></p>
<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<input data-role="none" type="hidden" name="fmt" value="<?= asn_e($asn_fmt) ?>">
<input data-role="none" type="hidden" name="formular" value="konto">
<div class="sm-feld"><label for="asn_sb"><?= asn_e(asn_t('KONTO.L_BENUTZER')) ?></label>
<input data-role="none" type="text" id="asn_sb" name="sonn_benutzer" value="<?= asn_e(isset($asn_zusatz['konto_benutzer']) && is_string($asn_zusatz['konto_benutzer']) ? $asn_zusatz['konto_benutzer'] : $asn_cfg['sonn_benutzer']) ?>" maxlength="64"></div>
<div class="sm-feld"><label for="asn_sk"><?= asn_e(asn_t('KONTO.L_KENNWORT')) ?></label>
<input data-role="none" type="password" id="asn_sk" name="sonn_kennwort" value="" maxlength="256" autocomplete="new-password">
<p class="sm-hilfe"><?= asn_e(asn_t((string) $asn_cfg['sonn_kennwort'] !== '' ? 'KONTO.KENNWORT_DA' : 'KONTO.KENNWORT_LEER')) ?></p></div>
<div class="sm-feld"><label><input data-role="none" type="checkbox" name="kennwort_loeschen" value="1"> <?= asn_e(asn_t('KONTO.L_LOESCHEN')) ?></label></div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="speichern" value="1"><?= asn_e(asn_t('ALLG.K_SPEICHERN')) ?></button>
</div>
</form>

<h2><?= asn_e(asn_t('EINST.H_SICHERUNG')) ?></h2>
<p class="sm-hilfe"><?= asn_e(asn_t('EINST.SICHERUNG_TEXT')) ?></p>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="hidden" name="fmt" value="<?= asn_e($asn_fmt) ?>">
    <input data-role="none" type="hidden" name="formular" value="sicherung">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="aktion" value="holen"><?= asn_e(asn_t('EINST.K_SICHERUNG')) ?></button>
  </form>
</div>
<form action="index.php" method="post" enctype="multipart/form-data">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<input data-role="none" type="hidden" name="fmt" value="<?= asn_e($asn_fmt) ?>">
<input data-role="none" type="hidden" name="formular" value="sicherung">
<div class="sm-feld"><label for="sicherung"><?= asn_e(asn_t('EINST.L_ZURUECK')) ?></label>
<input data-role="none" type="file" id="sicherung" name="sicherung" accept=".json,application/json"></div>
<div class="sm-feld"><label><input data-role="none" type="checkbox" name="zurueck_ok" value="1"> <?= asn_e(asn_t('EINST.ZURUECK_HAKEN')) ?></label></div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="aktion" value="zurueck"><?= asn_e(asn_t('EINST.K_ZURUECK')) ?></button>
</div>
</form>
</div>

<!-- ================= Reiter: MQTT ================= -->
<div class="sm-seite<?= $asn_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" id="tab-mqtt">
<div class="sm-hinweis"><?= asn_e(asn_t('MQTT.WAS_IST_DAS')) ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= asn_e(asn_t('LEGENDE.AKTION')) ?></span>
</div>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<input data-role="none" type="hidden" name="fmt" value="<?= asn_e($asn_fmt) ?>">
<input data-role="none" type="hidden" name="formular" value="mqtt">
<div class="sm-feld"><label><input data-role="none" type="checkbox" name="mqtt_ein" value="1"<?= !empty($asn_cfg['mqtt_ein']) ? ' checked' : '' ?>>
<?= asn_e(asn_t('MQTT.L_EIN')) ?></label></div>
<div class="sm-feld"><label><?= asn_e(asn_t('MQTT.L_PRAEFIX')) ?></label><span class="sm-mono"><?= asn_e(ASN_PRAEFIX) ?></span>
<p class="sm-hilfe"><?= asn_e(asn_t('MQTT.PRAEFIX_HILFE')) ?></p></div>
<h3><?= asn_e(asn_t('MQTT.H_BRUECKE')) ?></h3>
<div class="sm-feld"><label><input data-role="none" type="checkbox" name="befehle_mqtt_ein" value="1"<?= !empty($asn_cfg['befehle_mqtt_ein']) ? ' checked' : '' ?>>
<?= asn_e(asn_t('MQTT.L_BEFEHLE')) ?></label>
<p class="sm-hilfe"><?= asn_e(asn_t('MQTT.BEFEHLE_HILFE')) ?></p></div>
<div class="sm-feld"><label><input data-role="none" type="checkbox" name="steuerung_ein" value="1"<?= !empty($asn_cfg['steuerung_ein']) ? ' checked' : '' ?>>
<?= asn_e(asn_t('MQTT.L_STEUERUNG')) ?></label>
<p class="sm-hilfe"><?= asn_e(asn_t('MQTT.STEUERUNG_HILFE')) ?></p></div>
<div class="sm-feld"><label for="wartezeit"><?= asn_e(asn_t('MQTT.L_WARTEZEIT')) ?></label>
<input data-role="none" type="text" id="wartezeit" name="wartezeit" value="<?= (int) $asn_cfg['wartezeit'] ?>" maxlength="2" inputmode="numeric"></div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="speichern" value="1"><?= asn_e(asn_t('ALLG.K_SPEICHERN')) ?></button>
</div>
</form>
<h2><?= asn_e(asn_t('MQTT.H_GATEWAY')) ?></h2>
<?php $asn_v2 = asn_mqtt_v2_haken_lage(); ?>
<p><?= asn_e($asn_mqtt['gefunden'] ? sprintf(asn_t('MQTT.GATEWAY'), $asn_mqtt['fassung'] > 0 ? (string) $asn_mqtt['fassung'] : '?',
    (int) $asn_mqtt['udpport'], asn_t($asn_mqtt['autostart'] ? 'ALLG.EIN' : 'ALLG.AUS')) : asn_t('MQTT.GATEWAY_UNKLAR')) ?></p>
<?php if ((int) $asn_mqtt['fassung'] === 1) {
    list($asn_abo_pfad, $asn_abo_da) = asn_abo_datei(); ?>
<div class="<?= $asn_abo_da ? 'sm-hinweis' : 'sm-warnung' ?>"><?= asn_e(sprintf(asn_t($asn_abo_da ? 'MQTT.ABO_V1_DATEI' : 'MQTT.ABO_V1_FEHLT'), ASN_PRAEFIX . '/#')) ?></div>
<?php } else { ?>
<div class="sm-hinweis"><?= asn_e(asn_t('MQTT.ABO_V2')) ?> <?= asn_e($asn_v2[4] === 'v2' ? sprintf(asn_t('TEST.A_V2'), $asn_v2[1], $asn_v2[2]) : '') ?></div>
<h3><?= asn_e(asn_t('MQTT.H_HAKEN')) ?></h3>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= asn_e(asn_t('MQTT.SP_DATENPUNKT')) ?></th><th><?= asn_e(asn_t('MQTT.SP_NONCACHED')) ?></th><th><?= asn_e(asn_t('MQTT.SP_BEDEUTUNG')) ?></th></tr>
<?php foreach (asn_mqtt_hakenliste() as $asn_h) { ?>
<tr><td><span class="sm-mono"><?= asn_e($asn_h[0]) ?></span></td><td><?= $asn_ja_nein($asn_h[3]) ?></td><td><?= asn_e($asn_h[2]) ?></td></tr>
<?php } ?>
</table>
</div>
<?php } ?>
<h2><?= asn_e(asn_t('MQTT.H_THEMEN')) ?></h2>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= asn_e(asn_t('MQTT.SP_THEMA')) ?></th><th><?= asn_e(asn_t('MQTT.SP_BEREICH')) ?></th><th><?= asn_e(asn_t('MQTT.SP_RETAIN')) ?></th>
<th><?= asn_e(asn_t('MQTT.SP_V2')) ?></th><th><?= asn_e(asn_t('MQTT.SP_BEDEUTUNG')) ?></th></tr>
<?php foreach (asn_mqtt_themen() as $asn_th) { ?>
<tr><td><span class="sm-mono"><?= asn_e(ASN_PRAEFIX . '/' . str_replace('zoneN/', 'zone<n>/', $asn_th['t'])) ?></span></td>
<td><?= asn_e($asn_th['b']) ?><?= $asn_th['f'] !== '' ? ' (' . asn_e(asn_t('MQTT.FEHLWERT')) . ' ' . asn_e($asn_th['f']) . ')' : '' ?></td>
<td><?= $asn_ja_nein($asn_th['r']) ?></td><td><?= $asn_th['v2'] ? ($asn_th['nc'] ? asn_e(asn_t('MQTT.V2_NC')) : asn_e(asn_t('ALLG.JA'))) : asn_e(asn_t('ALLG.NEIN')) ?></td>
<td><?= asn_e(asn_t($asn_th['k'])) ?></td></tr>
<?php } ?>
<tr><td><span class="sm-mono"><?= asn_e(ASN_PRAEFIX) ?>/befehl/&hellip;/quittung</span></td><td>Text</td><td><?= $asn_ja_nein(false) ?></td>
<td><?= $asn_ja_nein(false) ?></td><td><?= asn_e(asn_t('MQTT.T_QUITTUNG')) ?></td></tr>
</table>
</div>
<p class="sm-hilfe"><?= asn_e(asn_t('MQTT.SONN_EIGEN')) ?></p>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="sm-seite<?= $asn_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">
<div class="sm-hinweis"><?= asn_e(asn_t('LOX.WAS_IST_DAS')) ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= asn_e(asn_t('LEGENDE.KOPIEREN')) ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?= asn_e(asn_t('LEGENDE.TECHNIK')) ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= asn_e(asn_t('LEGENDE.AKTION')) ?></span>
</div>
<div class="sm-step"><b><?= asn_e(asn_t('LOX.S1_TITEL')) ?></b><br><?= asn_e(asn_t('LOX.S1_TEXT')) ?>
<table class="sm-tbl">
<tr><td><?= asn_e(asn_t('LOX.SERIENNUMMER')) ?></td><td><span class="sm-mono"><?= asn_e($asn_seriennr !== '' ? $asn_seriennr : '-') ?></span>
<?php if ($asn_seriennr !== '') { ?><br><button data-role="none" class="sm-btn sm-b-lesen sm-kopie" type="button" data-kopie="<?= asn_e($asn_seriennr) ?>"><?= asn_e(asn_t('LOX.K_KOPIEREN')) ?></button><?php } ?></td></tr>
<tr><td><?= asn_e(asn_t('LOX.SONN_MELDET')) ?></td><td><span class="sm-mono"><?= asn_e($asn_s !== null && $asn_s['mac_id'] !== '' ? $asn_s['mac_id'] : '-') ?></span>
<?= $asn_s !== null && $asn_s['mac_id'] !== '' ? $asn_zeichen($asn_s['mac_id'] === $asn_seriennr) : '' ?></td></tr>
<tr><td><?= asn_e(asn_t('LOX.HOST')) ?></td><td><span class="sm-mono"><?= asn_e($asn_cfg['ip'] !== '' ? $asn_cfg['ip'] : '-') ?></span></td></tr>
<tr><td><?= asn_e(asn_t('LOX.VERWALTUNG')) ?></td><td><?php if ($asn_verw_url !== '') { ?><a href="<?= asn_e($asn_verw_url) ?>" target="_blank" rel="noopener"><span class="sm-mono"><?= asn_e($asn_verw_url) ?></span></a><?php } else { ?><span class="sm-mono">http://<?= asn_e($asn_cfg['ip'] !== '' ? $asn_cfg['ip'] : '<IP>') ?>:7090</span><?php } ?></td></tr>
</table>
<p class="sm-hilfe"><?= asn_e(asn_t('LOX.S1_HINWEIS')) ?></p></div>
<div class="sm-step"><b><?= asn_e(asn_t('LOX.S2_TITEL')) ?></b><br><?= asn_e(asn_t('LOX.S2_TEXT')) ?></div>
<div class="sm-step"><b><?= asn_e(asn_t('LOX.S3_TITEL')) ?></b><br><?= asn_e(asn_t('LOX.S3_TEXT')) ?></div>
<div class="sm-step"><b><?= asn_e(asn_t('LOX.S4_TITEL')) ?></b><br><?= asn_e(asn_t((int) $asn_mqtt['fassung'] === 1 ? 'LOX.S4_V1' : 'LOX.S4_V2')) ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= asn_e(asn_t('LOX.SP_NAME')) ?></th><th><?= asn_e(asn_t('LOX.SP_BAUFORM')) ?></th><th><?= asn_e(asn_t('MQTT.SP_BEDEUTUNG')) ?></th>
<th><?= asn_e(asn_t('MQTT.FEHLWERT')) ?></th><th><?= asn_e(asn_t('MQTT.SP_V2')) ?></th></tr>
<?php foreach (asn_mqtt_namen(asn_zonen_nummern()) as $asn_n) {
    if (!$asn_n['v2']) { continue; } ?>
<tr><td><span class="sm-mono"><?= asn_e($asn_n['name']) ?></span><br>
<button data-role="none" class="sm-btn sm-b-lesen sm-kopie" type="button" data-kopie="<?= asn_e($asn_n['name']) ?>"><?= asn_e(asn_t('LOX.K_KOPIEREN')) ?></button></td>
<td><?= asn_e(asn_t($asn_n['bereich'] === 'Text' ? 'LOX.BAUFORM_TEXT' : 'LOX.BAUFORM_VI')) ?></td><td><?= asn_e($asn_n['bedeutung']) ?></td>
<td><?= asn_e($asn_n['fehlwert'] !== '' ? $asn_n['fehlwert'] : '-') ?></td><td><?= asn_e($asn_n['noncached'] ? 'N' : asn_t('ALLG.JA')) ?></td></tr>
<?php } ?>
</table>
</div>
<p class="sm-hilfe"><?= asn_e(asn_t('LOX.S4_SAETZE')) ?></p></div>
<div class="sm-step"><b><?= asn_e(asn_t('LOX.S5_TITEL')) ?></b><br><?= asn_e(asn_t('LOX.S5_TEXT')) ?>
<div class="<?= !empty($asn_cfg['befehle_mqtt_ein']) ? 'sm-hinweis' : 'sm-warnung' ?>"><?= asn_e(asn_t('LOX.S5_HAKEN')) ?></div>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= asn_e(asn_t('LOX.SP_BEFEHL')) ?></th><th><?= asn_e(asn_t('LOX.SP_WERT')) ?></th><th><?= asn_e(asn_t('LOX.SP_EINGREIFEND')) ?></th><th><?= asn_e(asn_t('LOX.SP_LOXONE')) ?></th></tr>
<?php foreach (asn_befehle() as $asn_bn => $asn_b) { ?>
<tr><td><span class="sm-mono"><?= asn_e($asn_bn) ?></span><br><span class="sm-hilfe"><?= asn_e(asn_t($asn_b['kachel'])) ?></span></td>
<td>1</td><td><?= $asn_ja_nein($asn_b['eingreifend']) ?></td>
<td><span class="sm-mono">publish <?= asn_e(ASN_PRAEFIX) ?>/befehl/<?= asn_e($asn_bn) ?> 1</span></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <input data-role="none" type="hidden" name="fmt" value="<?= asn_e($asn_fmt) ?>">
    <input data-role="none" type="hidden" name="formular" value="vorlage">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="aktion" value="holen"><?= asn_e(asn_t('LOX.K_VORLAGE')) ?></button>
  </form>
</div>
</div>
<div class="sm-step"><b><?= asn_e(asn_t('LOX.S6_TITEL')) ?></b><br><?= asn_e(asn_t('LOX.S6_TEXT')) ?></div>

<?php /* Nachfassung 3: die drei Seiten des LoxBerry-Plugins Musterprojekts (Druck 8a98512e, vom Hausherrn angeordnet) als
   Bilder, Bauart Chromecast4lox 1.3.25 (figure.sm-bild, Seitenname in alt und Unterschrift, je ein erklaerender Satz). */
$asn_bilder = array(
    array('bild' => 'einbindung_loxone.png',        'seite' => 'Audioserver NG',        'satz' => 'LOX.BILD1_TEXT'),
    array('bild' => 'einbindung_loxone_werte.png',  'seite' => 'Audioserver NG Werte',  'satz' => 'LOX.BILD2_TEXT'),
    array('bild' => 'einbindung_loxone_zonen.png',  'seite' => 'Audioserver NG Zonen',  'satz' => 'LOX.BILD3_TEXT'),
); ?>
<h2><?= asn_e(asn_t('LOX.H_MUSTER')) ?></h2>
<p class="sm-hilfe"><?= asn_e(asn_t('LOX.MUSTER_TEXT')) ?></p>
<?php foreach ($asn_bilder as $asn_bi) { ?>
<h3><?= asn_e($asn_bi['seite']) ?></h3>
<p><?= asn_e(asn_t($asn_bi['satz'])) ?></p>
<figure class="sm-bild">
<img src="<?= asn_e($asn_bi['bild']) ?>" alt="<?= asn_e(sprintf(asn_t('LOX.BILD_ALT'), $asn_bi['seite'])) ?>" loading="lazy">
<figcaption><?= asn_e(sprintf(asn_t('LOX.BILD_UNTERSCHRIFT'), $asn_bi['seite'])) ?></figcaption>
</figure>
<?php } ?>
<details>
<summary><?= asn_e(asn_t('LOX.H_HTTP')) ?></summary>
<p class="sm-hilfe"><?= asn_e(asn_t('LOX.HTTP_TEXT')) ?></p>
<p><span class="sm-mono">http://&lt;LoxBerry&gt;/plugins/<?= asn_e($asn_p['plugin']) ?>/index.php?token=&lt;<?= asn_e(asn_t('LOX.TOKEN_PLATZ')) ?>&gt;</span></p>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-loxone">
<input data-role="none" type="hidden" name="fmt" value="<?= asn_e($asn_fmt) ?>">
<input data-role="none" type="hidden" name="formular" value="endpunkt">
<div class="sm-feld"><label><input data-role="none" type="checkbox" name="endpunkt_ein" value="1"<?= !empty($asn_cfg['endpunkt_ein']) ? ' checked' : '' ?>>
<?= asn_e(asn_t('LOX.L_ENDPUNKT')) ?></label></div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="aktion" value="speichern"><?= asn_e(asn_t('ALLG.K_SPEICHERN')) ?></button>
</div>
</form>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-loxone">
<input data-role="none" type="hidden" name="fmt" value="<?= asn_e($asn_fmt) ?>">
<input data-role="none" type="hidden" name="formular" value="endpunkt">
<div class="sm-feld"><label><input data-role="none" type="checkbox" name="token_neu_ok" value="1"> <?= asn_e(asn_t('LOX.TOKEN_NEU_HAKEN')) ?></label></div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="aktion" value="token_neu"><?= asn_e(asn_t('LOX.K_TOKEN_NEU')) ?></button>
</div>
</form>
</details>
</div>

<!-- ================= Reiter: Test (echter Seitenwechsel; Docker-Pruefungen nur, wenn er serverseitig offen ist) ================= -->
<div class="sm-seite<?= $asn_tab === 'tab-test' ? ' sm-active' : '' ?>" id="tab-test">
<div class="sm-hinweis"><?= asn_e(asn_t('TEST.WAS_IST_DAS')) ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= asn_e(asn_t('LEGENDE.LESEN')) ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?= asn_e(asn_t('LEGENDE.TECHNIK')) ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= asn_e(asn_t('LEGENDE.AKTION')) ?></span>
</div>
<?php if ($asn_test_offen) { ?>
<h2><?= asn_e(asn_t('TEST.H_SELBST')) ?></h2>
<div class="sm-breit">
<table class="sm-tbl">
<?php foreach (array_merge(asn_test_zeilen(), $asn_aktiv ? asn_test_docker_zeilen() : array(), array(asn_test_endpunkt_zeile())) as $asn_tz) { ?>
<tr><td><?= $asn_zeichen($asn_tz[0]) ?></td><td><?= asn_e($asn_tz[1]) ?></td><td><?= $asn_tz[2] ?></td></tr>
<?php } ?>
</table>
</div>
<?php if (!$asn_aktiv) { ?>
<div class="sm-hinweis"><?= asn_e(asn_t('TEST.VERWALTUNG_AUS')) ?></div>
<?php } ?>
<?php } else { ?>
<div class="sm-hinweis"><?= asn_e(asn_t('TEST.NUR_OFFEN')) ?></div>
<?php } ?>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="fmt" value="<?= asn_e($asn_fmt) ?>">
    <input data-role="none" type="hidden" name="formular" value="test">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="aktion" value="neu"><?= asn_e(asn_t('TEST.K_NEU')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="fmt" value="<?= asn_e($asn_fmt) ?>">
    <input data-role="none" type="hidden" name="formular" value="test">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="aktion" value="rohwerte"><?= asn_e(asn_t('TEST.K_ROHWERTE')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="fmt" value="<?= asn_e($asn_fmt) ?>">
    <input data-role="none" type="hidden" name="formular" value="test">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="aktion" value="bibliothek"><?= asn_e(asn_t('TEST.K_BIBLIOTHEK')) ?></button>
  </form>
</div>
<?php if ($asn_testausgabe !== '') { ?>
<div class="sm-pre"><?= asn_e($asn_testausgabe) ?></div>
<?php } ?>
</div>

<!-- ================= Reiter: Logdateien ================= -->
<div class="sm-seite<?= $asn_tab === 'tab-log' ? ' sm-active' : '' ?>" id="tab-log">
<div class="sm-hinweis"><?= asn_e(asn_t('LOG.ERKLAERUNG')) ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= asn_e(asn_t('LEGENDE.LESEN')) ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= asn_e(asn_t('LEGENDE.AKTION')) ?></span>
</div>
<?php
if (class_exists('LBWeb', false) && method_exists('LBWeb', 'loglist_html')) {
    echo LBWeb::loglist_html();
}
?>
<p class="sm-hilfe"><span class="sm-mono"><?= asn_e($asn_p['log']) ?></span></p>
<?php if ($asn_logzeilen) { ?>
<div class="sm-log"><?= asn_e(implode("\n", $asn_logzeilen)) ?></div>
<?php } else { ?>
<p class="sm-hilfe"><?= asn_e(asn_t('LOG.LEER')) ?></p>
<?php } ?>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-log">
<input data-role="none" type="hidden" name="fmt" value="<?= asn_e($asn_fmt) ?>">
<input data-role="none" type="hidden" name="formular" value="log">
<div class="sm-feld"><label><input data-role="none" type="checkbox" name="leeren_ok" value="1"> <?= asn_e(asn_t('LOG.HAKEN')) ?></label></div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="leeren" value="1"><?= asn_e(asn_t('LOG.K_LEEREN')) ?></button>
</div>
</form>
<?php /* Fremdpruefung 31.10.2026, T2-3: Fehlerausgabe des Takts (cron.01min -> takt.err), sobald etwas darin steht. */
$asn_takt_err = array_map('asn_geheim_weg', asn_log_ende($asn_p['logdir'] . '/takt.err', 50));
if ($asn_takt_err) { ?>
<h2><?= asn_e(asn_t('LOG.TAKT_ERR_H')) ?></h2>
<p class="sm-hilfe"><?= asn_e(asn_t('LOG.TAKT_ERR_TEXT')) ?> <span class="sm-mono"><?= asn_e($asn_p['logdir'] . '/takt.err') ?></span></p>
<div class="sm-log"><?= asn_e(implode("\n", $asn_takt_err)) ?></div>
<?php } ?>

<h2 id="asn_sonnlog"><?= asn_e(asn_t('SONNLOG.H')) ?></h2>
<p class="sm-hilfe"><?= asn_e(sprintf(asn_t('SONNLOG.TEXT'), ASN_SONNLOG_ZEILEN)) ?></p>
<div class="sm-knopfreihe">
<a class="sm-btn sm-b-lesen" href="index.php?form=log&amp;sonnlog=1#asn_sonnlog"><?= asn_e(sprintf(asn_t('SONNLOG.K_ZEIGEN'), ASN_SONNLOG_ZEILEN)) ?></a>
</div>
<?php if ($asn_sonnlog !== null) { list($asn_lok, $asn_lg, $asn_lz, $asn_ln) = $asn_sonnlog; ?>
<?php if (!$asn_lok) { ?>
<div class="sm-warnung"><?= asn_e(asn_grund_text($asn_lg)) ?></div>
<?php } elseif (!$asn_lz) { ?>
<p class="sm-hilfe"><?= asn_e(sprintf(asn_t('SONNLOG.LEER'), $asn_ln)) ?></p>
<?php } else { ?>
<p class="sm-hilfe"><?= asn_e(sprintf(asn_t('SONNLOG.STAND'), $asn_ln, count($asn_lz))) ?></p>
<div class="sm-log"><?= asn_e(implode("\n", $asn_lz)) ?></div>
<?php } ?>
<?php } ?>
</div>

</div>

<script>
(function () {
	var reiter = document.querySelectorAll('.sm-tab');
	function zeige(id) {
		reiter.forEach(function (r) { r.classList.toggle('sm-active', r.dataset.ziel === id); });
		document.querySelectorAll('.sm-seite').forEach(function (s) { s.classList.toggle('sm-active', s.id === id); });
		document.querySelectorAll('input[name="activetab"]').forEach(function (f) { f.value = id; });
		if (history.replaceState) { history.replaceState(null, '', 'index.php?form=' + id.replace('tab-', '')); }
	}
	reiter.forEach(function (r) {
		if (r.dataset.ziel === 'tab-test' || r.dataset.ziel === 'tab-settings') { return; }   // Test und Einstellungen fragen Docker: Seitenwechsel
		r.addEventListener('click', function (e) { e.preventDefault(); zeige(r.dataset.ziel); });
	});
	zeige(<?= json_encode($asn_tab) ?>);
	document.querySelectorAll('.sm-kopie').forEach(function (k) {
		k.addEventListener('click', function () {
			var n = k.getAttribute('data-kopie');
			var fertig = function () { k.textContent = <?= json_encode(asn_t('LOX.KOPIERT')) ?>; };
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
	/* Nachfassung 1: einen Digest aus der Fassungsliste ins Update-Feld uebernehmen (aktualisiert wird erst mit Ja). */
	document.querySelectorAll('.asn-uebernehmen').forEach(function (k) {
		k.addEventListener('click', function () {
			var f = document.getElementById('asn_digest');
			if (f) { f.value = k.getAttribute('data-digest') || ''; f.focus(); }
		});
	});
})();
/* Speicherauswahl von LoxBerry (Bauart Chromecast4lox 1.3.25 Nachfassung 4): ihre Wahl (verstecktes Feld
 * asn_musik_wahl, das Widget loest dort ein jQuery-"change" aus) fuellt das Feld musik_pfad; ohne jQuery bleibt nur das Feld. */
(function () {
	var ort = document.getElementById('asn_musik_wahl_ort');
	if (!ort || typeof asnMusikWahl !== 'string' || !window.jQuery) { return; }
	var jq = window.jQuery;
	jq(ort).append(asnMusikWahl);
	jq(document).on('change', '#asn_musik_wahl', function () {
		jq('#asn_musik_pfad').val(String(jq(this).val() || ''));
	});
})();
</script>
<?php
if ($asn_rahmen) {
    LBWeb::lbfooter();
}
