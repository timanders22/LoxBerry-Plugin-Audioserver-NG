<?php
/**
 * Audioserver NG - Hintergrundvorgang (Bauart Alexa NG bin/hue_vorgang.php): Container anlegen, Probe uebernehmen,
 * starten, stoppen, neu starten, Update, Rueckweg, Probe entfernen, Bibliothek einlesen.
 *
 * Gestartet von der Oberflaeche, vom Endpunkt und von der Befehlsbruecke ueber asn_vorgang_starten() (setsid). Im
 * Hintergrund, weil docker pull und das Kopieren eines Datenordners Minuten dauern koennen. Der Stand steht in
 * data/plugins/<ordner>/vorgang.json; ein zweiter Vorgang waehrend eines laufenden endet sofort (Sperrdatei mit
 * Prozessnummer, kein flock - die Sperre vererbte sich sonst an jedes Kind von proc_open, Regeln/03).
 *
 * Aufruf: php asn_vorgang.php anlegen|wechsel|starten|stoppen|neustart|update|rueckweg|probe_entfernen|bibliothek|favoriten|tunein
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
    fwrite(STDERR, "asn_vorgang.php: asn_lib.php nicht gefunden.\n");
    exit(1);
}
asn_keine_wurzel_abbruch('asn_vorgang.php');
$asn_auftrag = isset($argv[1]) ? (string) $argv[1] : '';
if (!in_array($asn_auftrag, asn_vorgaenge(), true)) {
    fwrite(STDERR, "asn_vorgang.php: unbekannter Auftrag.\n");
    exit(2);
}
if (asn_upgrade_marke()) {
    asn_vorgang_schreiben(array('vorgang' => $asn_auftrag, 'zustand' => 'fehler', 'pid' => (int) getmypid(), 'start' => time(),
        'ende' => time(), 'schritt' => '', 'grund' => 'UPGRADE_LAEUFT', 'text' => '', 'ergebnis' => ''));
    exit(1);
}
$asn_r = asn_vorgang_ausfuehren($asn_auftrag);
echo ($asn_r[0] ? 'fertig ' . $asn_r[3] : 'nicht gelungen ' . $asn_r[1]) . "\n";
exit($asn_r[0] ? 0 : 1);
