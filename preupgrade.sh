#!/bin/sh
# Audioserver NG - preupgrade.sh (Bauart Xiaomi Licht 0.9.3). Laeuft als loxberry VOR dem Kopieren.
# - Upgrade-Marke data/plugins/<ordner>.upgrade_laeuft (Takt und Vorgang ruhen);
# - Befehlsbruecke anhalten;
# - Konfiguration in die Zweitschrift config/plugins/<ordner>.backup.json sichern (0600), nur wenn sie Einstellungen
#   traegt. Der sonn-Container laeuft waehrend des Upgrades unberuehrt weiter (er gehoert nicht zum Plugin-Ordner).
# Fremdpruefung 31.10.2026 (Vorbild Renault NG 2.1.21 preupgrade.sh):
# - T1-2: die Wurzel gilt nur mit config/system/general.json - auch $5/$LBHOMEDIR, nicht nur der Rueckfall;
# - T1-3: laesst sich die Marke nicht anlegen, bricht das Update ab (exit 2, VOR purge_installation) - ohne Marke hielte
#   preinstall.sh es fuer eine Neuinstallation und legte die Zweitschrift beiseite;
# - T1-4: unteilbar kopieren (umask 077 - kein 0644-Fenster -, Vergleich, mv);
# - T1-1: eine unbrauchbare Konfiguration (kein JSON, oder Einstellungen ohne Token und Adresse) wird nie ersatzlos
#   verworfen: sie kommt als <ordner>.json.kaputt.<zeit> (0600) NEBEN den Ordner - purge_installation loescht den Ordner
#   gleich. Ebenso die Datei audioserverng.json.kaputt, die das Plugin selbst beim Lesen beiseitelegt. Laesst sich die
#   Konfiguration nicht sichern und gibt es keine heile Zweitschrift, bricht das Update ab (exit 2).
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-audioserverng}"
BASE="${ARGV5:-$LBHOMEDIR}"
asn_wurzel_gilt() {
    [ -n "$1" ] && [ -d "$1/config/plugins" ] && [ -d "$1/data/plugins" ] && [ -f "$1/config/system/general.json" ]
}
asn_wurzel_suchen() {
    asn_v=$(cd "$1" 2>/dev/null && pwd -P) || return 1
    asn_i=0
    while [ -n "$asn_v" ] && [ "$asn_v" != "/" ] && [ "$asn_i" -lt 8 ]; do
        if asn_wurzel_gilt "$asn_v"; then
            echo "$asn_v"
            return 0
        fi
        asn_v=$(dirname "$asn_v")
        asn_i=$((asn_i + 1))
    done
    return 1
}
SELF=$(cd "$(dirname "$(readlink -f "$0")")" && pwd)
if ! asn_wurzel_gilt "$BASE"; then
    BASE=$(asn_wurzel_suchen "$SELF") || BASE=""
fi
if ! asn_wurzel_gilt "$BASE"; then
    echo "<WARNING> Es wurde kein LoxBerry-Wurzelverzeichnis gefunden - nichts gesichert."
    exit 2
fi
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts getan."; exit 2 ;;
esac
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
ASN_MARKE_VORHER=0
[ -f "$MARKE" ] && ASN_MARKE_VORHER=1
if { date +%s > "$MARKE"; } 2>/dev/null && [ -s "$MARKE" ]; then
    echo "<OK> Takt und Vorgaenge bis zum Ende der Aktualisierung angehalten."
else
    echo "<FAIL> Die Marke $MARKE liess sich nicht anlegen. Ohne sie hielte die Installation dieses Update fuer eine"
    echo "<FAIL> Neuinstallation und legte die Einstellungen beiseite. Die Aktualisierung wird abgebrochen; die bisherige"
    echo "<FAIL> Fassung bleibt installiert. Bitte den freien Platz auf der Karte pruefen."
    exit 2
fi
CF="$BASE/config/plugins/$PFOLDER/audioserverng.json"
BK="$BASE/config/plugins/$PFOLDER.backup.json"
ASN_ZEIT=$(date +%Y%m%d%H%M%S 2>/dev/null)
# Eine Datei unteilbar kopieren: gleich -> nichts; sonst Nebendatei mit umask 077 (0600 schon beim Anlegen), Vergleich,
# mv, Vergleich. rm -f trifft nur die eigene Nebendatei. Rueckgabe 0 gelungen.
asn_kopieren() {   # $1 Quelle, $2 Ziel
    [ -f "$2" ] && cmp -s "$1" "$2" && { chmod 600 "$2" 2>/dev/null; return 0; }
    if ( umask 077 && cp "$1" "$2.neu.$$" ) 2>/dev/null \
       && chmod 600 "$2.neu.$$" 2>/dev/null \
       && cmp -s "$1" "$2.neu.$$" \
       && mv -fT "$2.neu.$$" "$2" 2>/dev/null \
       && cmp -s "$1" "$2"; then
        return 0
    fi
    rm -f "$2.neu.$$" 2>/dev/null
    return 1
}
# 0 traegt Einstellungen (Token oder Adresse), 1 leer ({} oder leere Datei), 3 unbrauchbar, 2 nicht pruefbar (php fehlt)
asn_urteil() {
    [ -s "$1" ] || return 1
    command -v php >/dev/null 2>&1 || return 2
    php -d allow_url_fopen=0 -r '$r = trim((string) @file_get_contents($argv[1])); $d = json_decode($r, true);
        if (!is_array($d)) { exit($r === "" ? 1 : 3); }
        if ((isset($d["aktionstoken"]) && is_string($d["aktionstoken"]) && $d["aktionstoken"] !== "")
            || (isset($d["ip"]) && is_string($d["ip"]) && $d["ip"] !== "")) { exit(0); }
        exit(count($d) === 0 ? 1 : 3);' "$1" >/dev/null 2>&1
    case "$?" in
        0) return 0 ;;
        1) return 1 ;;
        3) return 3 ;;
        *) return 2 ;;
    esac
}
asn_kaputt_beiseite() {   # $1 Datei, $2 Namenszusatz
    ASN_KZ="$BASE/config/plugins/$PFOLDER.json.kaputt.$ASN_ZEIT$2"
    if asn_kopieren "$1" "$ASN_KZ"; then
        echo "<WARNING> $(basename "$1") ist unbrauchbar (kein gueltiges JSON oder ohne Token und Adresse). Der Inhalt"
        echo "<WARNING> liegt jetzt unter $ASN_KZ (nur fuer den Eigentuemer lesbar, die Deinstallation raeumt ihn ab)."
    else
        echo "<WARNING> $(basename "$1") ist unbrauchbar und liess sich nicht nach $ASN_KZ legen."
    fi
}
asn_sicherung_gescheitert() {
    asn_urteil "$BK"
    if [ "$?" = 0 ]; then
        echo "<WARNING> Die Konfiguration liess sich nicht nach $BK sichern - die vorhandene Zweitschrift ist heil"
        echo "<WARNING> und wird nach dem Update zurueckgespielt."
        return 0
    fi
    echo "<FAIL> Die Konfiguration liess sich nicht sichern, und es gibt keine heile Zweitschrift. Die Aktualisierung"
    echo "<FAIL> wird abgebrochen, damit die Einstellungen nicht verloren gehen. Bitte den Platz auf der Karte pruefen."
    [ "$ASN_MARKE_VORHER" = 1 ] || rm -f "$MARKE" 2>/dev/null
    exit 2
}
[ -f "$CF.kaputt" ] && asn_kaputt_beiseite "$CF.kaputt" "-frueher"
asn_urteil "$CF"
ASN_C=$?
if [ "$ASN_C" = 0 ]; then
    if asn_kopieren "$CF" "$BK"; then
        echo "<OK> Konfiguration gesichert."
    else
        asn_sicherung_gescheitert
    fi
elif [ "$ASN_C" = 3 ]; then
    asn_kaputt_beiseite "$CF" ""
    asn_urteil "$BK"
    if [ "$?" = 0 ]; then
        echo "<WARNING> Die vorhandene Zweitschrift $BK bleibt unberuehrt und wird nach dem Update zurueckgespielt."
    else
        echo "<WARNING> Es gibt keine heile Zweitschrift - nach dem Update bitte die Einstellungen neu eintragen."
    fi
elif [ "$ASN_C" = 2 ]; then
    echo "<WARNING> php fehlt - die Konfiguration wurde nicht geprueft und nicht gesichert."
elif [ -f "$BK" ]; then
    echo "<INFO> audioserverng.json traegt keine Einstellungen - die vorhandene Zweitschrift bleibt unberuehrt."
fi
ASN_BBSH="$BASE/bin/plugins/$PFOLDER/befehlsbruecke.sh"
if [ -f "$ASN_BBSH" ]; then
    ASN_BBAUS=$(timeout 40 /bin/bash "$ASN_BBSH" stop 2>&1)
    echo "<INFO> Befehlsbruecke: $ASN_BBAUS"
fi
echo "<OK> preupgrade abgeschlossen (der sonn-Container laeuft weiter)."
exit 0
