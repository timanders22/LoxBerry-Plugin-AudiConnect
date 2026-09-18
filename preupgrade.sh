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

# BASE fail-closed. postinstall.sh und uninstall/uninstall haben diese
# Ableitung seit jeher, preupgrade.sh nicht - und ohne sie werden aus den
# Pfaden /config/plugins/... und /data/plugins/..., jedes rm und cp laeuft ins
# Leere, und die Schlusszeile meldete trotzdem <OK>. Der Installer uebergibt
# $5 zwar immer; von Hand aufgerufen fehlt es.
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    SELF=$(cd "$(dirname "$0")" && pwd)
    BASE=$(cd "$SELF/../.." 2>/dev/null && pwd)
fi
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ]; then
    echo "<FAIL> Die LoxBerry-Wurzel liess sich nicht bestimmen (\$5='$ARGV5',"
    echo "<FAIL> LBHOMEDIR='$LBHOMEDIR'). Es wurde NICHTS gesichert und nichts"
    echo "<FAIL> angehalten - lieber ein sichtbarer Abbruch als eine stille"
    echo "<FAIL> Sicherung ins Leere."
    exit 1
fi

PDATA="$BASE/data/plugins/$PFOLDER"
CFGDIR="$BASE/config/plugins/$PFOLDER"
PID="$PDATA/dienst.pid"
SOLL="$PDATA/soll_laufen"
MERKER="$BASE/config/plugins/$PFOLDER.lief_vorher"
RETTUNG="$BASE/data/plugins/$PFOLDER.rettung"
SKRIPT="$BASE/bin/plugins/$PFOLDER/audi.py"

# ---------------------------------------------------------------------------
# WAS EIN UPGRADE UEBERLEBT - und was nicht
#
# BERICHTIGT IN 0.9.12, und diesmal mit der Messung dazu. Hier stand bis 0.9.11
# das Gegenteil ("Geloescht werden sie nur in purge_installation (:1604, :1606),
# und die laeuft ausschliesslich im Deinstallations-Zweig (:233)"). Das war
# falsch, und ALLE sechs genannten Zeilennummern waren es auch.
#
# Nachgemessen am 05.09.2026 an sbin/plugininstall.pl selbst - Zweig master,
# 2054 Zeilen, 65 120 Byte, SHA256 009de368e2c0cdb5842cee33a2728dd78ace1418...:
#
#   grep -n purge_installation
#     :233   &purge_installation("all");   <- Deinstallation
#     :886   &purge_installation;          <- im Zweig  if ($isupgrade)  ab :858
#     :1542  sub purge_installation
#
#   sub purge_installation, Block 7 "Delete plugin folders":
#     :1626  if ($pfolder) {               <- KEINE Pruefung auf $option
#     :1629      rm -rfv .../config/plugins/$pfolder/
#     :1631      rm -rfv .../data/plugins/$pfolder/
#
#   Angelegt wird beides erst DANACH: :916/:920 (config), :1021/:1025 (data).
#   Reihenfolge: preupgrade :857 -> purge :886 -> preinstall :890
#                -> postinstall :1316 -> postupgrade :1341
#
# Ein Upgrade raeumt also BEIDE Ordner ab. Was es ueberleben soll, muss NEBEN
# ihnen liegen - ein "rm -rf <ordner>/" trifft den Nachbarn mit dem Punkt
# nicht. Deshalb:
#
#   config/plugins/<ordner>.backup.audi.json     die Konfiguration
#   config/plugins/<ordner>.backup.zugang.json   die Zugangsdaten (0600)
#   config/plugins/<ordner>.lief_vorher          der Startmerker
#   data/plugins/<ordner>.rettung/               Verlauf, Merker, Anmeldemarken
#
# Und der Sollmerker data/plugins/<ordner>/soll_laufen ueberlebt NICHT. Der
# minuetliche Waechter in bin/dienst.sh startet nur, wenn es ihn gibt (:157) -
# er holt den Dienst nach einem Update also NICHT von selbst zurueck. Der
# Merker .lief_vorher ist damit nicht "eine Verkuerzung um 60 Sekunden",
# sondern das Einzige, was den Dienst wieder anwirft.
# ---------------------------------------------------------------------------

rm -f "$MERKER"

# Der Merker haengt am SOLL, nicht am Ist.
#
# BERICHTIGT IN 0.9.12. Bis 0.9.11 entstand er nur, wenn ein Vorgang
# nachweislich lief. War der Dienst zum Zeitpunkt des Upgrades abgestuerzt -
# er SOLL laufen, tut es gerade nicht -, entstand kein Merker, soll_laufen
# wurde mit dem Datenordner geloescht, und der Dienst blieb danach DAUERHAFT
# aus, ohne eine Meldung. Der Sollmerker ist die richtige Frage.
if [ -f "$SOLL" ]; then
    : > "$MERKER"
    echo "<INFO> Der Dienst soll laufen - er wird nach dem Upgrade wieder gestartet."
fi

# Prozesse argumentweise erkennen, nicht per grep ueber die Kommandozeile.
#
# BERICHTIGT IN 0.9.12. Hier stand
#     grep -qa "audi.py" "/proc/$P/cmdline"
# - und das war die letzte Stelle der Linie mit dieser zu weichen Pruefung.
# bin/dienst.sh beschreibt sie seit 0.9.8 als Fehler und ersetzt sie,
# uninstall/uninstall ebenso, webfrontend/html/au_lib.php ebenso. Hier stand
# sie ausgerechnet vor einem kill -9: /proc/<pid>/cmdline traegt ALLE
# Argumente durch Nullbytes getrennt, ein grep darueber trifft auch einen
# Editor mit geoeffneter audi.py - und bei wiederverwendeter Prozessnummer
# haette es einen fremden Prozess hart abgeschossen.
ist_unser_dienst() {
    [ -r "/proc/$1/cmdline" ] || return 1
    [ "$(tr '\0' '\n' < "/proc/$1/cmdline" 2>/dev/null | sed -n '2p')" = "$SKRIPT" ]
}

if [ -f "$PID" ]; then
    P=$(cat "$PID" 2>/dev/null)
    # Frueher: kill, zwei Sekunden, dann BEDINGUNGSLOS kill -9. Zwei Fehler.
    # Erstens sind zwei Sekunden zu knapp: carconnectivity setzt HTTP-Anfragen
    # an myAudi ab, die bei ueberlasteten Servern zehn bis zwanzig Sekunden
    # haengen koennen - ein SIGKILL mittendrin hinterlaesst abgerissene
    # Verbindungen und halbe Dateien. Zweitens ging kill -9 auch dann hinaus,
    # wenn der Prozess laengst weg war; Prozessnummern werden wiederverwendet.
    if [ -n "$P" ] && kill -0 "$P" 2>/dev/null && ist_unser_dienst "$P"; then
        kill "$P" 2>/dev/null || true
        i=0
        while [ $i -lt 15 ] && kill -0 "$P" 2>/dev/null; do
            sleep 1
            i=$((i + 1))
        done
        if kill -0 "$P" 2>/dev/null && ist_unser_dienst "$P"; then
            kill -9 "$P" 2>/dev/null || true
            sleep 1
        fi
        if kill -0 "$P" 2>/dev/null; then
            echo "<INFO> Der Dienst (PID $P) laeuft weiter - er gehoert"
            echo "<INFO> moeglicherweise einem anderen Benutzer: ps -o user= -p $P"
        else
            echo "<INFO> Laufender Dienst angehalten."
        fi
    fi
    rm -f "$PID"
fi

# ---------------------------------------------------------------------------
# Sichern. JEDER Rueckgabewert wird geprueft.
#
# BERICHTIGT IN 0.9.12: der einzige Zweck dieses Skripts war bis 0.9.11 der
# einzige Schritt, dessen Rueckgabewert verworfen wurde ("cp ... || true"),
# und die Schlusszeile meldete danach bedingungslos <OK>. Ein volles
# Dateisystem, fehlende Rechte, ein Schreibfehler - alles endete in "in
# Ordnung", und der Anwender hatte keine Sicherung seiner Zugangsdaten.
#
# NEU IN 0.9.18: DIE SICHERUNG WIRD NICHT MIT EINEM STAND OHNE GEHEIMNIS
# UEBERSCHRIEBEN. Bis dahin ging jede vorhandene audi.json ungefragt ueber die
# Sicherung - auch eine abgeschnittene. Gemessen am 18.09.2026 in WSL/Ubuntu
# (Pruefung-AudiConnect-0.9.18/Pruefstaende/messe_au_klasseA.sh, Fall
# "upgrade"): eine halb geschriebene audi.json machte aus der heilen Sicherung
# eine unlesbare, und danach half auch die Selbstheilung der Oberflaeche nicht
# mehr - es gab nichts mehr, woraus sie haette heilen koennen. Regeln/05:
# "auch in preupgrade.sh: [ -s "$datei" ] heisst nur 'nicht leer' und ist zu
# schwach".
# ---------------------------------------------------------------------------
FEHLER=0

# Traegt DATEI gueltiges JSON mit mindestens einem nichtleeren der genannten
# Schluessel? Rueckgabe 0 = ja.
#
# Es muss ein JSON-Leser sein, kein grep: eine ABGESCHNITTENE Datei enthaelt
# den Text des Tokens noch, ist als Ganzes aber unbrauchbar - genau der Fall,
# um den es hier geht. perl mit JSON::PP ist auf jedem LoxBerry da
# (plugininstall.pl selbst ist Perl und benutzt es). Fehlt es wider Erwarten,
# antwortet die Funktion "nein", die Sicherung wird wie bisher geschrieben,
# und die Zeile darunter sagt, dass nicht geprueft werden konnte.
traegt() {
    d=$1; shift
    [ -f "$d" ] || return 1
    perl -MJSON::PP -e '
        my $f = shift; local $/;
        open my $fh, "<", $f or exit 1;
        my $d = eval { JSON::PP->new->decode(scalar <$fh>) };
        exit 1 unless ref $d eq "HASH";
        for my $k (@ARGV) {
            my $v = $d->{$k};
            next if !defined $v || ref $v;
            $v =~ s/^\s+|\s+$//g;
            exit 0 if $v ne "";
        }
        exit 1;
    ' "$d" "$@" 2>/dev/null
}
command -v perl >/dev/null 2>&1 || \
    echo "<INFO> perl fehlt - die Sicherung wird OHNE Inhaltspruefung geschrieben."

for f in audi.json zugang.json; do
    case $f in
        audi.json)   SCHL="aktionstoken schalttoken formgeheimnis" ;;
        zugang.json) SCHL="email passwort spin" ;;
    esac
    ZIEL="$BASE/config/plugins/$PFOLDER.backup.$f"
    if [ -f "$CFGDIR/$f" ]; then
        if [ -f "$ZIEL" ] && traegt "$ZIEL" $SCHL && ! traegt "$CFGDIR/$f" $SCHL; then
            echo "<INFO> $f traegt kein Geheimnis mehr - die vorhandene Sicherung"
            echo "<INFO> bleibt unveraendert, sie ist der einzige Rueckweg."
            continue
        fi
        if cp -p "$CFGDIR/$f" "$ZIEL"; then
            echo "<OK> $f gesichert."
        else
            echo "<FAIL> $f liess sich nicht sichern ($CFGDIR/$f)."
            FEHLER=1
        fi
    fi
done
chmod 600 "$BASE/config/plugins/$PFOLDER.backup.zugang.json" 2>/dev/null || true

# Der Datenordner. Was hier nicht gerettet wird, ist nach dem Upgrade fort:
#   verlauf/        Tagesdateien und ladungen.csv - das Einzige, was sich
#                   nicht nachbeschaffen laesst (bis zu 90 Tage Messreihen)
#   merker.json     angefangener Ladevorgang, letzter Verbrauch
#   token.json      Anmeldemarken der Bibliothek; ohne sie ist eine neue
#                   Anmeldung noetig, und die kann bei Konten mit
#                   Zwei-Faktor-Bestaetigung nicht von selbst gelingen
# Nicht gerettet werden loxone.json, zustand.json, cache.json und
# bibliothek_cache.json: die entstehen beim naechsten Abruf ohnehin neu.
if [ -d "$PDATA" ]; then
    rm -rf "$RETTUNG"
    if mkdir -p "$RETTUNG"; then
        for f in merker.json token.json; do
            if [ -f "$PDATA/$f" ]; then
                if cp -p "$PDATA/$f" "$RETTUNG/$f"; then
                    echo "<OK> $f gerettet."
                else
                    echo "<FAIL> $f liess sich nicht retten."
                    FEHLER=1
                fi
            fi
        done
        if [ -d "$PDATA/verlauf" ]; then
            if cp -rp "$PDATA/verlauf" "$RETTUNG/verlauf"; then
                echo "<OK> Verlauf und Ladeprotokoll gerettet."
            else
                echo "<FAIL> Der Verlauf liess sich nicht retten."
                FEHLER=1
            fi
        fi
        chmod 700 "$RETTUNG" 2>/dev/null || true
        chmod 600 "$RETTUNG/token.json" 2>/dev/null || true
    else
        echo "<FAIL> $RETTUNG liess sich nicht anlegen."
        FEHLER=1
    fi
fi

if [ "$FEHLER" -ne 0 ]; then
    echo "<FAIL> preupgrade NICHT vollstaendig - siehe die Zeilen darueber."
    echo "<FAIL> Das Upgrade wird abgebrochen, damit nichts verlorengeht."
    exit 1
fi
echo "<OK> preupgrade abgeschlossen."
exit 0
