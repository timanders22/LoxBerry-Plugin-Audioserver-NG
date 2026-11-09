<?php
/**
 * Audioserver NG - Reiter Test (Selbstpruefung; Regeln/04, Bauvorschrift Audio Stufe 2 Punkt 8).
 *
 * Pflichtzeilen (ohne Docker, bei jedem Oeffnen): Konfiguration, Vollstaendigkeit, Reiterleiste, Formulare, Themenliste
 * gegen Sendecode, festgelegtes Abbild, Gateway V2 n von m Haken, Befehlsbruecke, Upgrade-Marke, Takt, Arbeitsspeicher.
 * Docker- und sonn-Zeilen laufen NUR, wenn der Reiter Test serverseitig der offene ist (echter Seitenwechsel).
 * Rueckgabe je Zeile array(ok true|false|null, Frage, Antwort-HTML) - null = Hinweis (kein Kreuz).
 */

/** Passen Reiterleiste, Bereiche, Muster und Reiterfeld zusammen? Gelesen wird die eigene Datei (eine Quelle). */
function asn_test_reiter_probe($datei = null)
{
    $datei = $datei !== null ? $datei : __DIR__ . '/index.php';
    $t = (string) @file_get_contents($datei);
    if ($t === '') {
        return array(false, asn_t('TEST.A_REITER_LESEN'));
    }
    preg_match_all('/data-ziel="tab-([a-z]+)"/', $t, $m1);
    preg_match_all('/class="sm-seite<\?= \$asn_tab === \'tab-([a-z]+)\' \? \' sm-active\' : \'\' \?>" id="tab-([a-z]+)"/', $t, $m2);
    $muster = preg_match('#\$asn_muster = \'/\^tab-\(([a-z|]+)\)\$/\';#', $t, $m3) ? explode('|', $m3[1]) : array();
    preg_match_all("/^\s+'([a-z]+)'\s+=> (?:'REITER\.[A-Z]+'|null),/m", $t, $m4);
    $leiste = $m1[1];
    $bereiche = array();
    foreach ($m2[1] as $i => $a) {
        if ($a === $m2[2][$i]) {
            $bereiche[] = $a;
        }
    }
    $feld = $m4[1];
    $gleich = count($leiste) > 0 && $leiste === $bereiche && $leiste === $muster && $leiste === $feld;
    return array($gleich, sprintf(asn_t($gleich ? 'TEST.A_REITER_OK' : 'TEST.A_REITER_FEHL'), count($leiste), count($bereiche),
        count($muster), count($feld)));
}

/** Tragen alle Formulare das Merkmal und eine Formularart? Zaehlung in der eigenen Datei. */
function asn_test_formulare_probe($datei = null)
{
    $datei = $datei !== null ? $datei : __DIR__ . '/index.php';
    $t = (string) @file_get_contents($datei);
    $formen = preg_match_all('/<form\b/', $t);
    $merk = preg_match_all('/name="fmt" value="<\?= asn_e\(\$asn_fmt\) \?>"/', $t);
    $art = preg_match_all('/name="formular" value="[a-z_]+"/', $t);
    $ok = $formen > 0 && $formen === $merk && $formen === $art;
    return array($ok, sprintf(asn_t($ok ? 'TEST.A_FORM_OK' : 'TEST.A_FORM_FEHL'), $formen, $merk, $art));
}

/**
 * Der HTTP-Port des LoxBerry-Webservers (config/system/general.json, Webserver.Port; sonst 80). Fremdpruefung
 * 31.10.2026, T2-1 (Bauart MGiSmart 1.1.29 mg_webport): bis dahin stand Port 80 fest. Bewusst nicht SERVER_PORT - ueber
 * HTTPS waere das 443, und http://127.0.0.1:443 antwortet nicht.
 */
function asn_webport()
{
    $p = asn_paths();
    $g = $p['home'] !== '' ? asn_json_lesen($p['home'] . '/config/system/general.json') : array();
    foreach (array('Webserver', 'WEBSERVER') as $ab) {
        if (isset($g[$ab]['Port']) && is_scalar($g[$ab]['Port']) && preg_match('/^[0-9]{1,5}\z/', (string) $g[$ab]['Port'])
            && (int) $g[$ab]['Port'] > 0 && (int) $g[$ab]['Port'] < 65536) {
            return (int) $g[$ab]['Port'];
        }
    }
    return 80;
}

/**
 * Befund E (31.10.2026): steht in den letzten 200 Zeilen des sonn-Protokolls ein gescheiterter Verbindungsaufbau zu einem
 * Cast-Ausgang (Port 8009: EHOSTUNREACH, EHOSTDOWN, ECONNREFUSED, ETIMEDOUT)? Nur lesend, nur bei offenem Reiter Test.
 */
function asn_zonen_ausgang_unerreichbar()
{
    list($ok, , $zeilen) = asn_sonn_protokoll(200);
    if (!$ok) {
        return false;
    }
    foreach ($zeilen as $z) {
        if (preg_match('/\b(EHOSTUNREACH|EHOSTDOWN|ECONNREFUSED|ETIMEDOUT)\b[^\n]*:8009\b/', $z)) {
            return true;
        }
    }
    return false;
}

/** Den eigenen Endpunkt wirklich aufrufen (?selftest=1, 127.0.0.1 am Webserver-Port, 3 s). Nur bei offenem Reiter. */
function asn_test_endpunkt()
{
    $p = asn_paths();
    $cfg = asn_config(false);
    if ($p['home'] === '' || !asn_token_gueltig($cfg['aktionstoken']) || !ini_get('allow_url_fopen')) {
        return array(null, asn_t('TEST.A_ENDPUNKT_UNKLAR'));
    }
    $port = asn_webport();
    $url = 'http://127.0.0.1' . ($port !== 80 ? ':' . $port : '') . '/plugins/' . rawurlencode($p['plugin']) . '/index.php?selftest=1&token=' . rawurlencode($cfg['aktionstoken']);
    $ctx = stream_context_create(array('http' => array('timeout' => 3, 'ignore_errors' => true)));
    $fp = @fopen($url, 'r', false, $ctx);
    if ($fp === false) {
        return array(null, asn_t('TEST.A_ENDPUNKT_KEINE_ANTWORT'));
    }
    $meta = @stream_get_meta_data($fp);
    $t = (string) @stream_get_contents($fp, 512);
    @fclose($fp);
    $code = 0;
    foreach ((isset($meta['wrapper_data']) && is_array($meta['wrapper_data'])) ? $meta['wrapper_data'] : array() as $z) {
        if (is_string($z) && preg_match('#^HTTP/\S+\s+([0-9]{3})#', $z, $m)) {
            $code = (int) $m[1];
        }
    }
    if ($code === 200 && strpos($t, 'SELFTEST;OK=1') === 0) {
        return array(true, asn_t('TEST.A_ENDPUNKT_OK'));
    }
    return array(false, sprintf(asn_t('TEST.A_ENDPUNKT_FEHL'), $code, asn_e(substr(trim($t), 0, 60))));
}

/** Die Zeile zum eigenen Endpunkt. */
function asn_test_endpunkt_zeile()
{
    list($ok, $t) = asn_test_endpunkt();
    return array($ok, asn_t('TEST.F_ENDPUNKT'), $t);
}

/** Die Pflichtzeilen (ohne Docker). */
function asn_test_zeilen()
{
    $z = array();
    $cfg = asn_config(false);
    $lage = asn_config_lage();
    $z[] = array($lage === 'ok' ? true : ($lage === 'kaputt' ? false : null), asn_t('TEST.F_KONFIG'), asn_e(asn_t('TEST.A_KONFIG_' . strtoupper($lage))));
    $roh = asn_json_lesen(asn_paths()['config']);
    $fehlt = array();
    foreach (array_keys(asn_vorgaben()) as $k) {
        if (!array_key_exists($k, $roh)) {
            $fehlt[] = $k;
        }
    }
    $z[] = array($roh ? !$fehlt : null, asn_t('TEST.F_VOLLSTAENDIG'), asn_e($roh ? ($fehlt ? sprintf(asn_t('TEST.A_FEHLT'), implode(', ', $fehlt))
        : sprintf(asn_t('TEST.A_VOLL'), count(asn_vorgaben()))) : asn_t('TEST.A_KONFIG_LEER')));
    list($ok, $t) = asn_test_reiter_probe();
    $z[] = array($ok, asn_t('TEST.F_REITER'), asn_e($t));
    list($ok, $t) = asn_test_formulare_probe();
    $z[] = array($ok, asn_t('TEST.F_FORMULARE'), asn_e($t));
    list($ok, $n, $a, $b) = asn_mqtt_namen_probe();
    $z[] = array($ok, asn_t('TEST.F_THEMEN'), asn_e($ok ? sprintf(asn_t('TEST.A_THEMEN_OK'), $n)
        : sprintf(asn_t('TEST.A_THEMEN_FEHL'), implode(', ', $a), implode(', ', $b))));
    $v = asn_abbild_vorgabe();
    $dig = asn_abbild_digest($cfg);
    $z[] = array($v['digest'] !== '', asn_t('TEST.F_ABBILD_VORGABE'), asn_e($v['digest'] !== ''
        ? sprintf(asn_t('TEST.A_ABBILD_VORGABE'), $v['repo'], asn_kurzdigest($v['digest']), $v['tag'], $v['datum'])
          . ($dig !== $v['digest'] ? ' ' . (asn_abbild_frueher($cfg) !== null
              ? sprintf(asn_t('TEST.A_ABBILD_FRUEHER'), asn_kurzdigest($dig), asn_abbild_frueher($cfg)['tag'])
              : sprintf(asn_t('TEST.A_ABBILD_UPDATE'), asn_kurzdigest($dig))) : '')
        : asn_t('TEST.A_ABBILD_FEHLT')));
    $v2 = asn_mqtt_v2_haken_lage();
    if ($v2[4] === 'v2') {
        $z[] = array($v2[0] === 1, asn_t('TEST.F_V2'), asn_e(sprintf(asn_t('TEST.A_V2'), $v2[1], $v2[2])
            . ($v2[3] ? ' ' . sprintf(asn_t('TEST.A_V2_NC'), implode(', ', $v2[3])) : '')));
    } else {
        $z[] = array(null, asn_t('TEST.F_V2'), asn_e(asn_t($v2[4] === 'v1' ? 'TEST.A_V2_V1' : 'TEST.A_V2_UNKLAR')));
    }
    list($bl, $bt) = asn_bruecke_lage();
    $z[] = array($bl, asn_t('TEST.F_BRUECKE'), $bt);
    $z[] = array(asn_upgrade_marke() ? false : true, asn_t('TEST.F_MARKE'), asn_e(asn_t(asn_upgrade_marke() ? 'TEST.A_MARKE_DA' : 'TEST.A_MARKE_KEINE')));
    $aktiv = !empty($cfg['container_ein']) || asn_merk()['angelegt'];
    $alter = asn_takt_alter();
    $z[] = array($aktiv ? ($alter >= 0 && $alter <= ASN_TAKT_GRENZE) : null, asn_t('TEST.F_TAKT'),
        asn_e($alter < 0 ? asn_t('TEST.A_TAKT_NIE') : sprintf(asn_t('TEST.A_TAKT'), $alter, asn_zustand()['zaehler'])));
    $ram = asn_ram_frei_mb();
    $z[] = array($ram < 0 ? null : $ram >= ASN_RAM_WARNUNG_MB, asn_t('TEST.F_RAM'),
        asn_e($ram < 0 ? asn_t('ALLG.NICHT_FESTSTELLBAR') : sprintf(asn_t('TEST.A_RAM'), $ram, ASN_RAM_WARNUNG_MB)));
    return $z;
}

/**
 * Zeile Speicher- und CPU-Grenze. $moeglich aus asn_speichergrenze_moeglich(): false = der Kernel setzt keine
 * Speichergrenze um (Raspberry Pi, cgroup_disable=memory; Docker legt trotzdem an, HostConfig.Memory bleibt 0) -
 * dann Hinweis statt Kreuz, solange die CPU-Grenze stimmt. Sonst muessen beide Grenzen den Einstellungen entsprechen.
 */
function asn_test_grenze_zeile(array $k, $soll_mb, $soll_cpus, $moeglich)
{
    $soll_mb = (int) $soll_mb;
    $soll_cpus = (int) $soll_cpus;
    $ist_cpu = $k['nanocpus'] > 0 ? (string) round($k['nanocpus'] / 1e9, 1) : '-';
    $cpu_ok = $k['nanocpus'] > 0 && $soll_cpus > 0 && abs($k['nanocpus'] - $soll_cpus * 1e9) < 1e6;
    if ($moeglich === false) {
        $t = sprintf(asn_t('TEST.A_GRENZE_OHNE_SPEICHER'), $soll_mb) . ' '
            . ($cpu_ok ? sprintf(asn_t('TEST.A_GRENZE_CPU_OK'), $ist_cpu) : sprintf(asn_t('TEST.A_GRENZE_CPU_ANDERS'), $ist_cpu, $soll_cpus));
        return array($cpu_ok ? null : false, asn_t('TEST.F_GRENZE'), asn_e($t));
    }
    $ok = $k['speicher'] > 0 && $k['speicher'] === $soll_mb * 1048576 && $cpu_ok;
    return array($ok, asn_t('TEST.F_GRENZE'), asn_e(sprintf(asn_t('TEST.A_GRENZE'), (int) round($k['speicher'] / 1048576), $ist_cpu)
        . ($ok ? '' : ' ' . sprintf(asn_t('TEST.A_GRENZE_SOLL'), $soll_mb, $soll_cpus))));
}

/** Die Docker- und sonn-Zeilen (nur bei offenem Reiter). */
function asn_test_docker_zeilen()
{
    $z = array();
    $cfg = asn_config(false);
    list($dl, $dg, $df) = asn_docker_lage();
    $z[] = array($dl === 'ok', asn_t('TEST.F_DOCKER'), asn_e($dl === 'ok' ? sprintf(asn_t('TEST.A_DOCKER'), $df) : asn_grund_text($dg)));
    if ($dl !== 'ok') {
        return $z;
    }
    list($pda, $pk) = asn_probe();
    if ($pda === true) {
        $pn = array_keys($pk['netze']);
        $z[] = array($pk['laeuft'] ? false : null, asn_t('TEST.F_PROBE'), asn_e(sprintf(asn_t($pk['laeuft'] ? 'TEST.A_PROBE_LAEUFT' : 'TEST.A_PROBE_STEHT'),
            ASN_PROBE_NAME, implode(', ', $pn))));
    } else {
        $z[] = array(null, asn_t('TEST.F_PROBE'), asn_e(asn_t($pda === false ? 'TEST.A_PROBE_KEINE' : 'ALLG.NICHT_FESTSTELLBAR')));
    }
    list($da, $eigen, $k) = asn_container();
    $nm = asn_namen();
    if ($da !== true) {
        $z[] = array(empty($cfg['container_ein']) ? null : false, asn_t('TEST.F_CONTAINER'),
            asn_e($da === false ? sprintf(asn_t('TEST.A_CONTAINER_KEINER'), $nm['container']) : asn_t('ALLG.NICHT_FESTSTELLBAR')));
        return $z;
    }
    if (!$eigen) {
        $z[] = array(false, asn_t('TEST.F_CONTAINER'), asn_e(asn_grund_text('CONTAINER_FREMD|' . $nm['container'])));
        return $z;
    }
    $z[] = array($k['laeuft'], asn_t('TEST.F_CONTAINER'), asn_e(sprintf(asn_t('TEST.A_CONTAINER'), $nm['container'], $k['status'],
        $k['neustarts'], $k['neustart_regel'])));
    list($sok, $sg, $sdef) = asn_soll($cfg);
    $netze = array_keys($k['netze']);
    $nur = count($netze) === 1 && !in_array('bridge', $netze, true);
    $z[] = array($nur && (!$sok || $netze === array($sdef['netz'])), asn_t('TEST.F_NUR_MACVLAN'), asn_e(sprintf(asn_t('TEST.A_NETZE'), implode(', ', $netze))));
    if ($sok) {
        $n = isset($k['netze'][$sdef['netz']]) ? $k['netze'][$sdef['netz']] : array('ip' => '', 'mac' => '');
        $gleich = $n['ip'] === $sdef['ip'] && $n['mac'] === $sdef['mac'];
        $z[] = array($gleich, asn_t('TEST.F_IP_MAC'), asn_e(sprintf(asn_t('TEST.A_IP_MAC'), $n['ip'] !== '' ? $n['ip'] : '-',
            $n['mac'] !== '' ? $n['mac'] : '-', $sdef['ip'], $sdef['mac'])));
        $z[] = array($k['bild'] === $sdef['bild'], asn_t('TEST.F_ABBILD'), asn_e(sprintf(asn_t('TEST.A_ABBILD'), asn_kurztext($k['bild'], 90),
            asn_kurzdigest($sdef['digest']))));
        if ($sdef['musik'] !== '') {
            // 0.9.2 Nachzug: gut nur am neuen Ort (Lokale Bibliothek von sonn). Ein Container mit dem alten /musik
            // (bis 0.9.2) ist rot - sonn sieht den Ordner dort nicht; "Container anlegen oder angleichen" hilft.
            $m = asn_musik_mount($k['mounts']);
            $neu = $m !== null && $m['ziel'] === ASN_MUSIK_ZIEL;
            $z[] = array($neu && !$m['rw'], asn_t('TEST.F_MUSIK'), asn_e($m === null ? asn_t('TEST.A_MUSIK_FEHLT')
                : (!$neu ? sprintf(asn_t('TEST.A_MUSIK_ALT'), $m['quelle'])
                : sprintf(asn_t($m['rw'] ? 'TEST.A_MUSIK_RW' : 'TEST.A_MUSIK_RO'), $m['quelle'], ASN_MUSIK_NAME))));
        }
        if ($k['def'] !== $sdef['hash']) {
            $z[] = array(null, asn_t('TEST.F_SOLL'), asn_e(asn_t('TEST.A_SOLL_ANDERS')));
        }
        if (!$sdef['netz_eigen']) {
            list(, $alle) = asn_macvlan_netze();
            $fremd = isset($alle[$sdef['netz']]) ? $alle[$sdef['netz']]['plugin'] : '';
            $z[] = array(null, asn_t('TEST.F_NETZ_GETEILT'), asn_e(sprintf(asn_t('TEST.A_NETZ_GETEILT'), $sdef['netz'], $fremd !== '' ? $fremd : '-')));
        }
    }
    $z[] = asn_test_grenze_zeile($k, $sok ? $sdef['speicher'] : (int) $cfg['speicher_mb'], $sok ? $sdef['cpus'] : (int) $cfg['cpus'],
        asn_speichergrenze_moeglich());
    if (!$k['laeuft']) {
        return $z;
    }
    list($ok, $g, $d) = asn_sonn_fragen($nm['container'], 'stand');
    if (!$ok || !is_array($d)) {
        $z[] = array(false, asn_t('TEST.F_SONN'), asn_e(asn_grund_text($g) . (is_array($d) && isset($d['text']) && $d['text'] !== '' ? ' (' . $d['text'] . ')' : '')));
        return $z;
    }
    $st = isset($d['health']['status']) ? (string) $d['health']['status'] : '';
    $det = array();
    foreach ((isset($d['health']['checks']) && is_array($d['health']['checks'])) ? $d['health']['checks'] : array() as $c) {
        if (is_array($c) && isset($c['status']) && $c['status'] !== 'ok') {
            $det[] = (isset($c['name']) ? $c['name'] : '?') . ': ' . (isset($c['detail']) ? $c['detail'] : $c['status']);
        }
    }
    $z[] = array($st === 'ok' ? true : ($st === 'degraded' ? null : false), asn_t('TEST.F_SONN'), asn_e(sprintf(asn_t('TEST.A_SONN'),
        $st !== '' ? $st : '-', isset($d['health']['version']) ? $d['health']['version'] : '-') . ($det ? ' - ' . implode('; ', $det) : '')));
    if ($sok) {
        $sm = strtoupper((string) (isset($d['mac_id']) ? $d['mac_id'] : ''));
        $si = (string) (isset($d['ip']) ? $d['ip'] : '');
        $gleich = $sm === asn_seriennummer($sdef['mac']) && $si === $sdef['ip'];
        $z[] = array($sm === '' && $si === '' ? null : $gleich, asn_t('TEST.F_SONN_MAC'), asn_e(sprintf(asn_t('TEST.A_SONN_MAC'),
            $sm !== '' ? $sm : '-', $si !== '' ? $si : '-', asn_seriennummer($sdef['mac']), $sdef['ip'])));
    }
    $z[] = array(!empty($d['gekoppelt']), asn_t('TEST.F_KOPPLUNG'), asn_e(asn_t(!empty($d['gekoppelt']) ? 'TEST.A_KOPPLUNG_JA' : 'TEST.A_KOPPLUNG_NEIN')));
    $zon = asn_zonen_normieren(isset($d['zonen']) ? $d['zonen'] : array());
    $namen = array();
    foreach ($zon as $nr => $w) {
        $vb = (string) $w['verbunden'] === '1' ? asn_t('ZONEN.JA') : ((string) $w['verbunden'] === '0' ? asn_t('ZONEN.NEIN') : asn_t('ZONEN.UNKLAR'));
        $namen[] = $nr . ' ' . $w['name'] . ' (' . $w['ausgang'] . ', ' . $vb . ')';
    }
    $z[] = array($zon ? true : null, asn_t('TEST.F_ZONEN'), asn_e($zon ? sprintf(asn_t('TEST.A_ZONEN'), count($zon), implode('; ', $namen)) : asn_t('TEST.A_ZONEN_KEINE')));
    if ($zon) {
        list($ohne, $fz) = asn_zonen_maengel($zon);
        $bekannt = count(array_filter($zon, function ($w) {
            return in_array((string) $w['verbunden'], array('1', '0'), true);
        }));
        /* Geraetetest 31.10.2026 23:00, Befund E: war ein Cast-Lautsprecher stromlos, meldet sonn bei jedem Play sofort
         * "connect EHOSTUNREACH <ip>:8009" und "Playback unavailable" - auch wenn er laengst wieder an ist; erst ein
         * Neustart des Containers hilft (am Geraet gemessen). Die Zonendaten tragen nur den Titel "Playback unavailable"
         * (sonn playbackErrors.js), den Grund nur das Protokoll. Deshalb: bei JEDEM Wiedergabefehler der Hinweis; steht
         * im Protokoll ein nicht erreichbarer Ausgang, ein Satz dazu. Kein automatischer Neustart (alle Zonen brechen ab). */
        $hinweis = '';
        if ($fz) {
            $hinweis = ' ' . (asn_zonen_ausgang_unerreichbar() ? asn_t('ZONEN.H_PROTOKOLL') . ' ' : '') . asn_t('ZONEN.H_NEUSTART');
        }
        $z[] = array(($ohne || $fz) ? false : ($bekannt === count($zon) ? true : null), asn_t('ZONEN.F_TEST'),
            asn_e((($ohne || $fz) ? asn_zonen_maengel_text($zon) : asn_t($bekannt === count($zon) ? 'ZONEN.A_OK' : 'ZONEN.A_KEINE_AUSSAGE')) . $hinweis));
    }
    // 0.9.3: Startlautstaerke je Zone (kommt aus Loxone, Parameter Standardlautstaerke/DefVol; sonn meldet sie als volumeLimits). Nur anzeigen und bei wenig warnen.
    foreach ($zon as $nr => $w) {
        $st = isset($w['start']) ? (int) $w['start'] : -1;
        $ho = isset($w['hoechst']) ? (int) $w['hoechst'] : -1;
        $z[] = array($st < 0 || $st < 20 ? null : true, sprintf(asn_t('TEST.F_START'), $nr, $w['name']), asn_e($st < 0 ? asn_t('ALLG.NICHT_FESTSTELLBAR')
            : sprintf(asn_t('TEST.A_START'), $st, $ho >= 0 ? (string) $ho : '-') . ($st < 20 ? ' ' . asn_t('TEST.H_START') : '')));
    }
    // 0.9.3: TuneIn-Vorgabesender - Lage der Herstellerdatei gegen die Einstellung. Rot nur, wenn die Einstellung an ist und das Plugin sie nicht erfuellen kann.
    $tu = asn_tunein_normieren(isset($d['tunein']) ? $d['tunein'] : null);
    $tu_ein = !empty($cfg['tunein_ausblenden']);
    if ($tu['lage'] === 'unbekannt' || $tu['lage'] === 'fehlt') {
        $z[] = array($tu_ein ? false : null, asn_t('TEST.F_TUNEIN'), asn_e(asn_grund_text($tu['lage'] === 'fehlt' ? 'TUNEIN_FEHLT' : 'TUNEIN_UNBEKANNT')));
    } elseif ($tu['lage'] === 'ausgeblendet') {
        $z[] = array($tu_ein ? true : null, asn_t('TEST.F_TUNEIN'), asn_e(asn_t($tu_ein ? 'TEST.A_TUNEIN_AUS' : 'TEST.A_TUNEIN_AUS_OHNE')));
    } elseif ($tu['lage'] === 'sichtbar') {
        $z[] = array($tu_ein ? false : null, asn_t('TEST.F_TUNEIN'), asn_e(asn_t($tu_ein ? 'TEST.A_TUNEIN_NOCH' : 'TEST.A_TUNEIN_WERK')));
    } elseif ($tu['lage'] === 'entfallen') {
        // 0.9.8: ab sonn core 4.0.0-beta.23 gibt es keine Vorgabesender mehr - das Ziel des Hakens ist ohne Aenderung erreicht.
        $z[] = array($tu_ein ? true : null, asn_t('TEST.F_TUNEIN'), asn_e(asn_t('TEST.A_TUNEIN_ENTFALLEN')));
    }
    // 0.9.2 Nachzug: Bibliothek gemessen - Titel der Lokalen Bibliothek und ob der Musikordner dort als Ordner
    // ASN_MUSIK_NAME erscheint (oeffentliche API /api/v1/browse). Nur mit gewaehltem Musikordner.
    if ($sok && $sdef['musik'] !== '') {
        $ti = isset($d['titel']) && is_int($d['titel']) && $d['titel'] >= 0 ? $d['titel'] : -1;
        $si = isset($d['musik_sichtbar']) && is_bool($d['musik_sichtbar']) ? $d['musik_sichtbar'] : null;
        if ($ti < 0 || $si === null) {
            $z[] = array(null, asn_t('TEST.F_BIBLIOTHEK'), asn_e(asn_t('TEST.A_BIBLIOTHEK_UNKLAR')));
        } else {
            $z[] = array($si && $ti > 0, asn_t('TEST.F_BIBLIOTHEK'), asn_e(sprintf(asn_t($si ? 'TEST.A_BIBLIOTHEK' : 'TEST.A_BIBLIOTHEK_OHNE'),
                $ti, ASN_MUSIK_NAME) . ($si && $ti === 0 ? ' ' . asn_t('TEST.H_BIBLIOTHEK_SCANNEN') : '')));
        }
    }
    if (!empty($d['mqtt_ein'])) {
        $z[] = array(null, asn_t('TEST.F_SONN_MQTT'), asn_e(asn_t('TEST.A_SONN_MQTT')));
    }
    return $z;
}
