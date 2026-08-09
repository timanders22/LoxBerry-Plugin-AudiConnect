#!/bin/bash
# Audi Connect - preupgrade
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Vor dem Upgrade: laufenden Dienst anhalten und die Konfiguration ausserhalb
# des Plugin-Ordners sichern. Die Zugangsdaten liegen in einer eigenen Datei
# und werden getrennt gesichert (Rechte 0600 bleiben erhalten).
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-audiconnect}"
BASE="${ARGV5:-$LBHOMEDIR}"

PID="$BASE/data/plugins/$PFOLDER/dienst.pid"
if [ -f "$PID" ]; then
    # Frueher: kill, zwei Sekunden, dann BEDINGUNGSLOS kill -9. Zwei Fehler.
    # Erstens sind zwei Sekunden zu knapp: carconnectivity setzt HTTP-Anfragen
    # an myAudi ab, die bei ueberlasteten Servern zehn bis zwanzig Sekunden
    # haengen koennen - ein SIGKILL mittendrin hinterlaesst abgerissene
    # Verbindungen und halbe Dateien. Zweitens ging kill -9 auch dann hinaus,
    # wenn der Prozess laengst weg war; Prozessnummern werden wiederverwendet,
    # im unguenstigen Fall trifft es einen fremden Prozess.
    P=$(cat "$PID" 2>/dev/null)
    if [ -n "$P" ] && kill -0 "$P" 2>/dev/null; then
        kill "$P" 2>/dev/null || true
        i=0
        while [ $i -lt 15 ] && kill -0 "$P" 2>/dev/null; do
            sleep 1
            i=$((i + 1))
        done
        # Nur hart beenden, wenn er noch lebt UND es wirklich unser Dienst ist.
        if kill -0 "$P" 2>/dev/null && grep -qa "audi.py" "/proc/$P/cmdline" 2>/dev/null; then
            kill -9 "$P" 2>/dev/null || true
        fi
    fi
    rm -f "$PID"
    echo "<INFO> Laufender Dienst angehalten."
fi

CFGDIR="$BASE/config/plugins/$PFOLDER"
for f in audi.json zugang.json; do
    if [ -f "$CFGDIR/$f" ]; then
        cp -p "$CFGDIR/$f" "$BASE/config/plugins/$PFOLDER.backup.$f" || true
    fi
done
chmod 600 "$BASE/config/plugins/$PFOLDER.backup.zugang.json" 2>/dev/null || true
echo "<OK> preupgrade abgeschlossen."
exit 0
