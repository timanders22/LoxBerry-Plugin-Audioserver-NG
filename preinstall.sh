#!/bin/sh
# Audioserver NG - preinstall.sh (Bauart Xiaomi Licht 0.9.3). Laeuft als loxberry, VOR dem Kopieren.
# Aufruf durch LoxBerry: $1 Temp-Ordner, $2 Plugin-Name, $3 Plugin-Ordner, $4 Fassung, $5 LoxBerry-Wurzel.
# Nur Pruefungen (PHP >= 7.4, Docker und Zugriff als Hinweis) - und bei einer NEUinstallation (keine Upgrade-Marke)
# wird die Zweitschrift einer frueheren Installation beiseitegelegt (.alt). Nichts geloescht.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-audioserverng}"
BASE="${ARGV5:-$LBHOMEDIR}"
if command -v php >/dev/null 2>&1; then
    if php -r 'exit(PHP_VERSION_ID >= 70400 ? 0 : 1);' >/dev/null 2>&1; then
        echo "<OK> PHP $(php -r 'echo PHP_VERSION;' 2>/dev/null) - ausreichend (mindestens 7.4)."
    else
        echo "<WARNING> PHP ist aelter als 7.4 - das Plugin ist dafuer nicht gebaut."
    fi
else
    echo "<WARNING> Kein PHP gefunden - ohne PHP laufen weder Oberflaeche noch Takt."
fi
if command -v docker >/dev/null 2>&1; then
    # Fremdpruefung 31.10.2026, T1-5: mit timeout - ein haengender Docker-Dienst haelt die Installation sonst an.
    ASN_DV=$(timeout 10 docker info --format '{{.ServerVersion}}' 2>/dev/null)
    ASN_DRC=$?
    if [ -n "$ASN_DV" ]; then
        echo "<OK> Docker ist erreichbar ($ASN_DV)."
    elif [ "$ASN_DRC" = 124 ]; then
        echo "<WARNING> Docker antwortet nicht binnen 10 s - die Installation laeuft weiter; Docker bitte pruefen (Reiter Test)."
    else
        echo "<WARNING> Docker ist installiert, aber fuer den Benutzer $(id -un) nicht erreichbar (Gruppe docker?)."
    fi
else
    echo "<WARNING> Docker fehlt - sonn core laeuft als Docker-Container. Docker zum Beispiel mit dem Plugin Docker NG einrichten."
fi
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ] \
   || [ ! -f "$BASE/config/system/general.json" ]; then
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt - nichts beiseitegelegt."
    exit 0
fi
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts beiseitegelegt."; exit 0 ;;
esac
[ -f "$BASE/data/plugins/$PFOLDER.upgrade_laeuft" ] && exit 0
ZIEL="$BASE/config/plugins/$PFOLDER.backup.json"
if [ -e "$ZIEL" ]; then
    if mv -f "$ZIEL" "$ZIEL.alt" 2>/dev/null; then
        chmod 600 "$ZIEL.alt" 2>/dev/null
        echo "<WARNING> Neuinstallation: die Zweitschrift einer frueheren Installation wird NICHT eingespielt;"
        echo "<WARNING> sie liegt als $(basename "$ZIEL").alt daneben (die Deinstallation raeumt sie ab)."
    else
        echo "<WARNING> $ZIEL liess sich nicht beiseitelegen - bitte von Hand entfernen."
    fi
fi
if [ -d "$BASE/data/plugins/$PFOLDER.sonn" ]; then
    echo "<INFO> Daten von sonn aus einer frueheren Installation liegen unter data/plugins/$PFOLDER.sonn - sie bleiben unberuehrt."
fi
exit 0
