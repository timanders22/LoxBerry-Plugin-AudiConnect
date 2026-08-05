#!/bin/bash
# Audi Connect - postinstall
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Legt an: Konfigurations-, Daten- und Logordner, die Zugangsdatei mit Rechten
# 0600 und die virtuelle Python-Umgebung samt der Bibliothek carconnectivity
# und ihrem Audi-Connector.
#
# ZWEI HERKUENFTE, EINE VENV:
#   carconnectivity                  das Geruest, von Till Steinbach
#   carconnectivity-connector-audi   der Audi-Teil, von Achim Fischer
# Der Audi-Connector ist deutlich juenger als der fuer Volkswagen (erste
# Fassung 28.09.2025). Deshalb sind BEIDE Fassungen festgenagelt - ein
# stillschweigendes Update koennte hier mehr veraendern als anderswo.
#
# WICHTIG (PEP 668): Debian 12/13 kennzeichnen die System-Python-Umgebung als
# extern verwaltet. Ein systemweites "pip3 install" wird mit
# "error: externally-managed-environment" abgewiesen - auch mit --user, auch
# als root. Deshalb eine eigene venv, und der Shebang der Skripte zeigt direkt
# darauf. JEDER Rueckgabewert wird geprueft: eine Installation, die "ALLES
# ERLEDIGT" meldet, obwohl die venv fehlschlug, ist schlimmer als ein Abbruch.
#
# Python: carconnectivity verlangt 3.9 oder neuer. Das erfuellt jeder LoxBerry,
# den es heute gibt (Debian 12 liefert 3.11, Debian 13 liefert 3.13).

ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-audiconnect}"
BASE="${ARGV5:-$LBHOMEDIR}"
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    # Ableitung aus dem eigenen Ablageort - LoxBerry::System taugt hier nicht,
    # weil es den Pluginordner aus dem Aufrufort ableitet und aus
    # postinstall.sh heraus ueberall Leerstring liefert.
    SELF=$(cd "$(dirname "$0")" && pwd)
    BASE=$(cd "$SELF/../.." 2>/dev/null && pwd)
fi

PBIN="$BASE/bin/plugins/$PFOLDER"
PDATA="$BASE/data/plugins/$PFOLDER"
PLOG="$BASE/log/plugins/$PFOLDER"
PCONFIG="$BASE/config/plugins/$PFOLDER"
VENV="$PBIN/venv"

KERN="0.11.10"
CONNECTOR="0.3.2"

mkdir -p "$PDATA" "$PLOG" "$PCONFIG" "$PDATA/befehle" "$PDATA/antworten" || {
    echo "<FAIL> Ordner konnten nicht angelegt werden."
    exit 1
}
chmod 755 "$PDATA" "$PLOG" "$PCONFIG" 2>/dev/null

# ---------- Konfiguration ----------
[ -f "$PCONFIG/audi.json" ] || echo '{}' > "$PCONFIG/audi.json"
if [ ! -f "$PCONFIG/zugang.json" ]; then
    echo '{}' > "$PCONFIG/zugang.json"
fi
chmod 600 "$PCONFIG/zugang.json"

# Sicherung zurueckspielen (uebersteht Update UND Neuinstallation)
for f in audi.json zugang.json; do
    BK="$BASE/config/plugins/$PFOLDER.backup.$f"
    CF="$PCONFIG/$f"
    if [ -f "$BK" ]; then
        INHALT=$(cat "$CF" 2>/dev/null)
        if [ ! -s "$CF" ] || [ "$INHALT" = "{}" ]; then
            cp -p "$BK" "$CF" && echo "<OK> $f aus Sicherung wiederhergestellt."
        fi
    fi
done
chmod 600 "$PCONFIG/zugang.json"

# ---------- Python suchen ----------
PY=""
for k in python3.13 python3.12 python3.11 python3.10 python3.9; do
    if command -v "$k" >/dev/null 2>&1; then PY="$k"; break; fi
done
if [ -z "$PY" ] && command -v python3 >/dev/null 2>&1; then
    if python3 -c 'import sys; sys.exit(0 if sys.version_info >= (3,9) else 1)'; then
        PY="python3"
    fi
fi
if [ -z "$PY" ]; then
    HAVE=$(python3 -V 2>&1 || echo "kein python3")
    echo "<FAIL> Es wurde kein Python 3.9 oder neuer gefunden (gefunden: $HAVE)."
    echo "<FAIL> Die Bibliothek carconnectivity setzt Python >= 3.9 voraus."
    echo "<FAIL> Das Plugin bleibt installiert, der Dienst kann aber nicht starten."
    exit 1
fi
echo "<INFO> Verwendetes Python: $PY ($($PY -V 2>&1))"

# ---------- virtuelle Umgebung ----------
BRAUCHBAR=0
if [ -x "$VENV/bin/python3" ]; then
    if "$VENV/bin/python3" -c 'import sys; sys.exit(0 if sys.version_info >= (3,9) else 1)' 2>/dev/null; then
        BRAUCHBAR=1
    fi
fi
if [ "$BRAUCHBAR" -eq 0 ]; then
    rm -rf "$VENV"
    if ! "$PY" -m venv "$VENV"; then
        echo "<FAIL> Virtuelle Umgebung konnte nicht angelegt werden ($VENV)."
        echo "<FAIL> Fehlt das Paket python3-venv? (apt install python3-venv)"
        exit 1
    fi
    echo "<OK> Virtuelle Umgebung angelegt: $VENV"
fi
if [ ! -x "$VENV/bin/python3" ]; then
    echo "<FAIL> $VENV/bin/python3 fehlt - Abbruch."
    exit 1
fi

"$VENV/bin/python3" -m pip install --upgrade pip setuptools wheel >/dev/null 2>&1 || \
    echo "<INFO> pip liess sich nicht aktualisieren - wird mit der vorhandenen Fassung versucht."

echo "<INFO> Installiere carconnectivity $KERN und den Audi-Connector $CONNECTOR"
echo "<INFO> (benoetigt eine Internetverbindung) ..."
if ! "$VENV/bin/python3" -m pip install --no-cache-dir \
        "carconnectivity==$KERN" "carconnectivity-connector-audi==$CONNECTOR"; then
    echo "<INFO> Feste Fassungen nicht installierbar - versuche die neuesten."
    if ! "$VENV/bin/python3" -m pip install --no-cache-dir \
            "carconnectivity-connector-audi"; then
        echo "<FAIL> carconnectivity konnte nicht installiert werden."
        echo "<FAIL> Haeufigste Ursachen: keine Internetverbindung, oder PyPI war"
        echo "<FAIL> nicht erreichbar."
        exit 1
    fi
    # Ersatzweg gegangen - und angezeigt, sonst wird aus dem Ersatz unbemerkt
    # der Normalfall. Beim Audi-Connector faellt das besonders ins Gewicht: er
    # ist jung, und zwischen 0.1.3 und 0.3.2 lagen acht Fassungen in neun
    # Monaten.
    echo "<INFO> ERSATZWEG: Es wurden die neuesten Fassungen statt $KERN / $CONNECTOR"
    echo "<INFO> installiert. Falls Werte leer bleiben, im Reiter Test"
    echo "<INFO> 'Rohdaten als JSON ansehen' aufrufen und vergleichen."
fi

# Rueckgabewert allein genuegt nicht - es wird nachgesehen, ob sich beide
# Pakete auch laden lassen.
if ! "$VENV/bin/python3" -c 'from carconnectivity.carconnectivity import CarConnectivity' 2>/dev/null; then
    echo "<FAIL> carconnectivity ist installiert, laesst sich aber nicht laden."
    exit 1
fi
if ! "$VENV/bin/python3" -c 'import carconnectivity_connectors.audi.connector' 2>/dev/null; then
    echo "<FAIL> Der Audi-Connector ist installiert, laesst sich aber nicht laden."
    exit 1
fi
IST=$("$VENV/bin/python3" -c 'import importlib.metadata as m; print(m.version("carconnectivity"), m.version("carconnectivity-connector-audi"))' 2>/dev/null || echo "unbekannt")
echo "<OK> carconnectivity geladen, Fassungen: $IST"

# ---------- Rechte ----------
chmod 755 "$PBIN/audi.py" 2>/dev/null
chmod 755 "$PBIN/dienst.sh" 2>/dev/null
chown -R loxberry:loxberry "$PBIN" "$PDATA" "$PLOG" "$PCONFIG" 2>/dev/null
chmod 600 "$PCONFIG/zugang.json"
chmod 600 "$PDATA/token.json" 2>/dev/null

echo "<OK> Installation abgeschlossen."
echo "<INFO> Bitte die Plugin-Oberflaeche oeffnen, die Zugangsdaten des myAudi-Kontos"
echo "<INFO> eintragen und den Dienst im Reiter Einstellungen starten."
exit 0
