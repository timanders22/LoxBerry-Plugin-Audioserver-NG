#!/bin/sh
# Audioserver NG - postupgrade.sh (Bauart Xiaomi Licht 0.9.3). Laeuft als loxberry NACH postinstall.
# - Konfiguration aus der Zweitschrift zurueck, wenn die mitgelieferte Datei keine Einstellungen traegt;
# - Upgrade-Marke entfernen (auch bei Abbruch: trap); Befehlsbruecke starten (sie startet nur, wenn ihr Haken an ist).
# Fremdpruefung 31.10.2026: die Wurzel gilt nur mit config/system/general.json (T1-2); zurueckgespielt wird unteilbar
# (umask 077, Vergleich, mv - T1-4, Wortlaut wie preupgrade.sh).
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
    echo "<WARNING> Es wurde kein LoxBerry-Wurzelverzeichnis gefunden - nichts zurueckgespielt."
    exit 1
fi
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts getan."; exit 1 ;;
esac
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
asn_marke_weg() { rm -f "$MARKE"; }
trap asn_marke_weg EXIT
CF="$BASE/config/plugins/$PFOLDER/audioserverng.json"
BK="$BASE/config/plugins/$PFOLDER.backup.json"
asn_kopieren() {   # $1 Quelle, $2 Ziel - unteilbar, Wortlaut wie preupgrade.sh
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
asn_traegt() {   # $1 Datei - 0 traegt Einstellungen, 1 nicht, 2 nicht pruefbar
    [ -s "$1" ] || return 1
    command -v php >/dev/null 2>&1 || return 2
    php -d allow_url_fopen=0 -r '$d = json_decode((string) @file_get_contents($argv[1]), true);
        exit((is_array($d) && ((isset($d["aktionstoken"]) && is_string($d["aktionstoken"]) && $d["aktionstoken"] !== "")
            || (isset($d["ip"]) && is_string($d["ip"]) && $d["ip"] !== ""))) ? 0 : 1);' "$1" >/dev/null 2>&1
}
if [ -f "$BK" ]; then
    asn_traegt "$CF"; ASN_C=$?
    asn_traegt "$BK"; ASN_B=$?
    if [ "$ASN_C" = 1 ] && [ "$ASN_B" = 0 ]; then
        mkdir -p "$BASE/config/plugins/$PFOLDER" 2>/dev/null
        if asn_kopieren "$BK" "$CF"; then
            echo "<OK> Konfiguration aus der Zweitschrift wiederhergestellt."
        else
            echo "<WARNING> Die Konfiguration liess sich nicht zurueckspielen - die Zweitschrift liegt unter $BK."
        fi
    elif [ "$ASN_C" = 2 ] || [ "$ASN_B" = 2 ]; then
        echo "<WARNING> php fehlt - Konfiguration nicht geprueft; die Zweitschrift liegt unter $BK."
    fi
fi
[ -f "$CF" ] && chmod 600 "$CF" 2>/dev/null
[ -f "$BK" ] && chmod 600 "$BK" 2>/dev/null
asn_marke_weg
ASN_BBSH="$BASE/bin/plugins/$PFOLDER/befehlsbruecke.sh"
if [ -f "$ASN_BBSH" ]; then
    ASN_BBAUS=$(/bin/bash "$ASN_BBSH" start 2>&1)
    echo "<INFO> Befehlsbruecke: $ASN_BBAUS"
fi
echo "<OK> postupgrade abgeschlossen."
exit 0
