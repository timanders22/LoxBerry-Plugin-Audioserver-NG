<?php
/**
 * bin/befehle_zuordnung.php - Zuordnungstabelle der Befehlsbruecke 1.0.2, Linie Audioserver NG (Bauart Xiaomi Licht
 * 0.9.3). Die Bruecke selbst (bin/befehlsbruecke.php und .sh) ist byte-gleich die Stammfassung Werkzeuge/gemeinsam/ -
 * linieneigen ist nur diese Datei.
 *
 * Nur zwei Befehle (Bauvorschrift Audio Stufe 2, Punkt 7 - die Musikbedienung laeuft nativ ueber App und Audio-Player):
 *   audioserverng/befehl/neustart   1   sonn-Container neu starten. Eingreifend (unterbricht die Musik in allen Zonen):
 *                                       nur mit dem zweiten Haken "Neustart aus Loxone zulassen" (steuerung_ein).
 *                                       Sperre 300 s.
 *   audioserverng/befehl/bibliothek 1   Musikbibliothek neu einlesen (braucht das sonn-Konto). Nicht eingreifend.
 *                                       Sperre 600 s (das Einlesen einer grossen Sammlung dauert).
 * Beide starten den Hintergrundvorgang (bin/asn_vorgang.php); die Quittung heisst deshalb "uebergeben" - die Wirkung
 * zeigt der Vorgang im Reiter Einstellungen und der Zustand ueber MQTT.
 */
if (!function_exists('asn_config')) {
    $asn_kand = array();
    if (basename(dirname(__DIR__)) === 'plugins') {
        $asn_kand[] = dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/asn_lib.php';
    } else {
        $asn_kand[] = dirname(__DIR__) . '/webfrontend/html/asn_lib.php';
    }
    foreach ($asn_kand as $asn_k) {
        if (is_file($asn_k)) { require_once $asn_k; break; }
    }
}
$asn_eintrag = function ($name, $titel, $grund) {
    $b = asn_befehle();
    $e = $b[$name];
    return array('art' => $e['art'], 'eingreifend' => $e['eingreifend'], 'sperre_s' => $e['sperre_s'], 'begruendung' => $grund,
                 'titel' => $titel, 'kachel' => $e['kachel'], 'quittung' => 'uebergeben',
                 'tun' => function ($w, $t) use ($name) {
                     return asn_befehl_ergebnis(asn_befehl_annehmen($name, (string) $w, asn_config(false), 'mqtt'));
                 });
};
return array(
    'fassung' => 1,
    'linie'   => 'audioserverng',
    'plugin'  => 'Audioserver NG',
    'geladen' => function_exists('asn_config') && function_exists('asn_befehl_annehmen') && function_exists('asn_befehl_ergebnis'),
    'zweiter_haken' => 'steuerung_ein',
    'einstellungen' => function () {
        return asn_befehle_einstellungen();
    },
    'protokoll' => function ($stufe, $text) {
        asn_log('Befehlsbruecke: ' . ($stufe === 'INFO' ? '' : $stufe . ' ') . $text);
    },
    'befehle' => function_exists('asn_befehle') ? array(
        'neustart'   => $asn_eintrag('neustart', 'Audioserver NG Neustart (MQTT)',
            'Ein Neustart unterbricht alle Zonen und dauert rund eine Minute; oefter als alle 5 min hilft nicht'),
        'bibliothek' => $asn_eintrag('bibliothek', 'Audioserver NG Bibliothek einlesen (MQTT)',
            'Das Einlesen einer grossen Sammlung dauert Minuten; ein zweiter Auftrag waehrenddessen bringt nichts'),
    ) : array(),
);
