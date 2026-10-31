#!/bin/sh
# Audioserver NG - postinstall.sh (Bauart Xiaomi Licht 0.9.3). Laeuft als loxberry, auch beim Upgrade (Reihenfolge
# preupgrade -> Kopie -> postinstall -> postupgrade, Regeln/06), idempotent.
# Rechte: data/plugins/<ordner> 0700, Konfiguration und Zweitschrift 0600, bin/* ausfuehrbar. Legt NICHTS an, startet
# und aendert KEINEN Container (die Verwaltung ist ab Werk aus; ein laufender Container laeuft unberuehrt weiter).
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-audioserverng}"
BASE="${ARGV5:-$LBHOMEDIR}"
asn_wurzel_gilt() {   # Fremdpruefung 31.10.2026, T1-2/T2-4a: die Wurzel gilt nur mit config/system/general.json
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
    echo "<WARNING> Es wurde kein LoxBerry-Wurzelverzeichnis gefunden - es wurden keine Rechte gesetzt."
    exit 1
fi
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts getan."; exit 1 ;;
esac
PBIN="$BASE/bin/plugins/$PFOLDER"
PDATA="$BASE/data/plugins/$PFOLDER"
PCONFIG="$BASE/config/plugins/$PFOLDER"
[ -d "$PDATA" ] && chmod 700 "$PDATA" 2>/dev/null
[ -d "$PCONFIG" ] && chmod 700 "$PCONFIG" 2>/dev/null
for f in "$PCONFIG/audioserverng.json" "$BASE/config/plugins/$PFOLDER.backup.json"; do
    [ -f "$f" ] && chmod 600 "$f" 2>/dev/null
done
if [ -d "$PBIN" ]; then
    for f in "$PBIN/befehlsbruecke.sh" "$PBIN/healthcheck"; do
        [ -f "$f" ] && chmod 755 "$f" 2>/dev/null
    done
fi
if ! command -v php >/dev/null 2>&1; then
    echo "<FAIL> Es wurde kein PHP gefunden - ohne PHP laufen weder Oberflaeche noch Takt."
    exit 1
fi
if [ -f "$PBIN/befehlsbruecke.php" ]; then
    if AUS=$(php "$PBIN/befehlsbruecke.php" --selbsttest 2>&1); then
        echo "<OK> Selbsttest der Befehlsbruecke: $(echo "$AUS" | tail -1)"
    else
        echo "<INFO> Der Selbsttest der Befehlsbruecke ist nicht sauber durchgelaufen:"
        echo "$AUS" | head -10 | sed 's/^/<INFO> /'
    fi
fi
echo "<OK> Installation abgeschlossen. Die Container-Verwaltung ist ab Werk aus."
echo "<INFO> Naechste Schritte im Reiter Einstellungen: Adresse, MAC und Netz eintragen (oder Werte der Probe"
echo "<INFO> uebernehmen), Verwaltung einschalten, speichern, Container anlegen; danach den Reiter Test pruefen."
exit 0
