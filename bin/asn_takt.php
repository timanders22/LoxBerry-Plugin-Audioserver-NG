<?php
/**
 * Audioserver NG - Takt (cron.01min, laeuft als loxberry).
 *
 * Misst die Lage (Docker, eigener Container, sonn per docker exec), schreibt data/plugins/<ordner>/zustand.json und
 * sendet ueber den UDP-Eingang des MQTT-Gateways (V2-Hausform). Docker wird nur gefragt, wenn die Verwaltung an ist
 * oder ein eigener Container angelegt wurde - ab Werk nie. Sperre mit Prozessnummer (kein flock: er vererbte sich an
 * docker exec, Regeln/03). Waehrend eines Upgrades (Marke) ruht der Takt.
 *
 * Aufruf: php asn_takt.php            ein Lauf
 *         php asn_takt.php --mqtt-leeren   zurueckbehaltene Zonenthemen abraeumen (Deinstallation)
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');

$asn_kand = array();
if (getenv('LBHOMEDIR') && getenv('LBPPLUGINDIR')) {
    $asn_kand[] = rtrim(getenv('LBHOMEDIR'), '/') . '/webfrontend/html/plugins/' . basename(getenv('LBPPLUGINDIR')) . '/asn_lib.php';
}
if (basename(dirname(__DIR__)) === 'plugins') {
    $asn_kand[] = dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/asn_lib.php';
} else {
    // Fremdpruefung 31.10.2026, T3-2: der Archivpfad nur AUSSERHALB von bin/plugins/ (wie healthcheck).
    $asn_kand[] = dirname(__DIR__) . '/webfrontend/html/asn_lib.php';
}
$asn_da = false;
foreach ($asn_kand as $asn_k) {
    if (is_file($asn_k)) {
        require_once $asn_k;
        $asn_da = true;
        break;
    }
}
if (!$asn_da) {
    fwrite(STDERR, "asn_takt.php: asn_lib.php nicht gefunden.\n");
    exit(1);
}
asn_keine_wurzel_abbruch('asn_takt.php');
$asn_arg = isset($argv[1]) ? (string) $argv[1] : '';
if ($asn_arg === '--mqtt-leeren') {
    foreach (asn_mqtt_abraeumen() as $asn_z) {
        echo $asn_z . "\n";
    }
    exit(0);
}
if ($asn_arg !== '') {
    fwrite(STDERR, "asn_takt.php: unbekannter Schalter.\n");
    exit(2);
}
list($asn_rc, $asn_satz) = asn_takt();
echo $asn_satz . "\n";
exit($asn_rc);
