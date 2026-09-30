#!/bin/bash
# Audi Connect - preinstall
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Neu in 0.9.22 (I1, Entscheidung 1 vom 29.09.2026), Bauform
# Abfahrts-Assistent 1.6.16. Der Installer ruft dieses Skript bei JEDEM
# Einbau auf, nach dem Aufraeumen der alten Fassung und VOR dem Kopieren von
# Konfiguration, Cron-Datei und Oberflaeche (sbin/plugininstall.pl:
# preupgrade :846, purge :874, preinstall :877, Cron :990, HTML :1066 -
# Geraet/2026-09-05/08_plugininstall.pl).
#
# Eine Aktualisierung erkennt es allein an der Marke
# data/plugins/<ordner>.upgrade_laeuft, die preupgrade.sh als Erstes anlegt
# (kein Altersvergleich). Dann tut es nichts: Zweitschriften und Rettung
# braucht postinstall.sh.
#
# Ohne Marke ist es eine NEUINSTALLATION. Liegengebliebene Zweitschriften
# (config/plugins/<ordner>.backup.audi.json und .backup.zugang.json) und die
# Rettung (data/plugins/<ordner>.rettung) einer frueheren Installation gehen
# nach <name>.alt, der Startmerker .lief_vorher wird entfernt, gemeldet mit
# genau einer <WARNING>. Bis 0.9.21 spielte postinstall.sh sie ungefragt
# zurueck - Token, myAudi-Zugangsdaten samt S-PIN, Anmeldemarken und Verlauf
# des frueheren Kontos, und der Dienst meldete sich damit an (in WSL
# gemessen, Installer-Pruefer Faelle D, D3, D4). Die Selbstheilung der
# Bibliothek liest .alt nie; die Deinstallation raeumt es ab.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-audiconnect}"
BASE="${ARGV5:-$LBHOMEDIR}"
# Wurzelsuche wie in den uebrigen Hakenskripten: ohne config/plugins,
# data/plugins UND config/system/general.json wird nichts angefasst.
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ] \
   || [ ! -f "$BASE/config/system/general.json" ]; then
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt ('$BASE') - nichts beiseitegelegt."
    exit 0
fi
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts beiseitegelegt."; exit 0 ;;
esac
[ -f "$BASE/data/plugins/$PFOLDER.upgrade_laeuft" ] && exit 0

BEISEITE=""
FEST=""
for ZIEL in "$BASE/config/plugins/$PFOLDER.backup.audi.json" \
            "$BASE/config/plugins/$PFOLDER.backup.zugang.json" \
            "$BASE/data/plugins/$PFOLDER.rettung"; do
    if [ -e "$ZIEL" ] || [ -L "$ZIEL" ]; then
        rm -rf "${ZIEL:?}.alt" 2>/dev/null
        if mv -f "$ZIEL" "$ZIEL.alt" 2>/dev/null; then
            BEISEITE="$BEISEITE $ZIEL.alt"
        else
            FEST="$FEST $ZIEL"
        fi
    fi
done
for A in "$BASE/config/plugins/$PFOLDER.backup.audi.json.alt" "$BASE/config/plugins/$PFOLDER.backup.zugang.json.alt"; do
    [ -f "$A" ] && [ ! -L "$A" ] && chmod 600 "$A" 2>/dev/null
done
MERKER="$BASE/config/plugins/$PFOLDER.lief_vorher"
if [ -e "$MERKER" ]; then
    rm -f "$MERKER" && BEISEITE="$BEISEITE (Startmerker $MERKER entfernt)"
fi
if [ -n "$BEISEITE" ] || [ -n "$FEST" ]; then
    T="<WARNING> Neuinstallation: Einstellungen und Zugangsdaten einer frueheren Installation werden NICHT eingespielt."
    [ -n "$BEISEITE" ] && T="$T Beiseitegelegt:$BEISEITE (die Deinstallation raeumt sie ab)."
    [ -n "$FEST" ] && T="$T Nicht zu verschieben, bitte von Hand entfernen:$FEST"
    echo "$T"
fi
exit 0
