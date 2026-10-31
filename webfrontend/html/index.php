<?php
/**
 * Audioserver NG - Endpunkt fuer den Miniserver und den Reiter Test (webfrontend/html/, ohne Anmeldung erreichbar).
 *
 *   ?selftest=1&token=<t>          prueft nur das Token, ohne Wirkung:
 *                                  SELFTEST;OK=1;TOKEN=OK | SELFTEST;OK=0;ERR=TOKEN | SELFTEST;OK=0;ERR=KEIN_TOKEN_EINGERICHTET
 *   ?cmd=neustart|bibliothek&wert=1&token=<t>
 *                                  derselbe Weg wie die Befehlsbruecke (asn_befehl_annehmen()); Neustart nur mit dem
 *                                  zweiten Haken. Fuer neue Projekte gehen Befehle ueber das MQTT-Gateway V2.
 *   ?token=<t>                     Lesezweig ASN;OK=..;ONLINE=.. - NUR mit endpunkt_ein 1, sonst 403.
 * Der Endpunkt legt nichts an und schreibt keine Konfiguration (asn_config(false), Regeln/05).
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');
require_once __DIR__ . '/asn_lib.php';
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

$asn_cfg = asn_config(false);
$asn_soll = is_string($asn_cfg['aktionstoken']) ? $asn_cfg['aktionstoken'] : '';
$asn_ist = (isset($_GET['token']) && is_string($_GET['token'])) ? $_GET['token'] : '';
$asn_selbsttest = isset($_GET['selftest']) && is_string($_GET['selftest']) && $_GET['selftest'] === '1';

if ($asn_soll === '') {
    http_response_code(403);
    echo $asn_selbsttest ? "SELFTEST;OK=0;ERR=KEIN_TOKEN_EINGERICHTET\n" : "CMD;OK=0;GRUND=KEIN_TOKEN_GESETZT\n";
    exit;
}
if (!asn_token_gueltig($asn_soll) || !hash_equals($asn_soll, $asn_ist)) {
    http_response_code(403);
    echo $asn_selbsttest ? "SELFTEST;OK=0;ERR=TOKEN\n" : "CMD;OK=0;GRUND=TOKEN\n";
    exit;
}
if ($asn_selbsttest) {
    echo "SELFTEST;OK=1;TOKEN=OK\n";
    exit;
}
if (isset($_GET['cmd'])) {
    $asn_cmd = is_string($_GET['cmd']) ? $_GET['cmd'] : '';
    $asn_wert = (isset($_GET['wert']) && is_string($_GET['wert'])) ? $_GET['wert'] : '1';
    if (!preg_match('/^[a-z]{1,16}\z/', $asn_cmd)) {
        http_response_code(400);
        echo "CMD;OK=0;GRUND=PARAMETER\n";
        exit;
    }
    $asn_r = asn_befehl_annehmen($asn_cmd, $asn_wert, $asn_cfg, 'http');
    if ((int) $asn_r['http'] !== 200) {
        http_response_code((int) $asn_r['http']);
    }
    echo asn_befehl_zeile($asn_r, $asn_cmd) . "\n";
    exit;
}
if (empty($asn_cfg['endpunkt_ein'])) {
    http_response_code(403);
    asn_log_gebremst('endpunkt_aus', 'Endpunkt: Leseanfrage abgewiesen - der HTTP-Lesezweig ist aus (Reiter Einbindung in '
        . 'Loxone). Werte kommen ueber das MQTT-Gateway V2.', 3600);
    echo "CMD;OK=0;GRUND=ENDPUNKT_AUS\n";
    exit;
}
echo asn_lesezeile() . "\n";
