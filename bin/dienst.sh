#!/bin/bash
# Audi Connect - Start, Stopp und Waechter des Abrufdienstes.
#
# Die Pfade werden aus dem EIGENEN Ablageort abgeleitet, nicht ueber
# LoxBerry::System. Grund: LoxBerry::System leitet den Pluginordner aus dem
# Aufrufort ab; wird dieses Skript aus postinstall.sh oder aus dem Cron
# gestartet, kommt dort ueberall Leerstring zurueck - das Skript werkelt dann
# gegen /-Pfade und meldet trotzdem Erfolg.

# readlink -f loest Symlinks auf. Dieses Plugin bringt keinen daemon/-Ordner
# mit, den LoxBerry verlinken wuerde - der Fall tritt heute also nicht ein.
# Der Pfad ist aber die Identitaet dieses Skripts: aus ihm kommen Plugin-Name,
# Daten-, Log- und Konfigurationsverzeichnis. Wird es irgendwann doch ueber
# einen Symlink aufgerufen, waere PNAME der Name des VERLINKENDEN Ordners, und
# der Dienst schriebe woanders hin - ohne Fehlermeldung.
# Als loxberry laufen, nicht als root.
#
# Der minuetliche Waechter kommt aus dem Cron. Laeuft der als root - und je
# nach Ablage des Cronjobs tut er das -, dann gehoerten PID-Datei, Sollmerker
# und Protokoll danach root. Die Oberflaeche laeuft als loxberry und koennte
# den Dienst anschliessend weder anhalten noch neu starten: sie darf die
# Dateien nicht mehr schreiben. Schlimmer noch, 'dienst.sh stop' meldet dann
# Erfolg - das kill scheitert, aber das rm der PID-Datei gelingt, weil das
# Verzeichnis loxberry gehoert. Der Dienst laeuft weiter und ist nur noch
# ueber die Prozessliste zu finden.
#
# Deshalb setzt sich das Skript selbst herunter, EINMAL und bevor es
# irgendetwas anlegt. exec, damit kein zusaetzlicher Prozess stehen bleibt.
# '-s /bin/bash' ausdruecklich: ohne das nimmt su die Login-Shell aus
# /etc/passwd. Steht dort nologin oder /bin/false, endet dieses Skript hier
# still und ohne Meldung - und weil es 'exec' ist, kaeme nicht einmal ein
# Rueckgabewert zurueck. Auf einem regulaeren LoxBerry ist der Zweig ohnehin
# unerreichbar (der Cron laeuft bereits als loxberry); er greift nur, wenn
# jemand von Hand mit sudo aufruft.
#
# Woertlich uebernommen aus LoxBerry-Plugin-Dashboard-0.9.12, dort seit dem
# 16.08.2026 in Betrieb. Ueber den Bestand gezaehlt am 31.08.2026: 15 von 17
# dienst.sh hatten den Abstieg nicht, obwohl REGELN_2 ihn seit langem
# verlangt.
if [ "$(id -u)" = "0" ] && id loxberry >/dev/null 2>&1; then
    exec su -s /bin/bash loxberry -c "$(printf '%q ' "$0" "$@")"
fi

SELF=$(cd "$(dirname "$(readlink -f "$0")")" && pwd)   # <home>/bin/plugins/<ordner>

# ---------- Wurzel und Ordnername: GELESEN, nicht geraten (NEU IN 0.9.20) ----
#
# Bis 0.9.19 stand hier
#     PNAME=$(basename "$SELF")
#     LBHOMEDIR=$(cd "$SELF/../../.." && pwd)
# und weiter unten ein 'mkdir -p "$PDATA" "$PLOG"' auf oberster Ebene. Der
# eigene Ablageort war damit die EINZIGE Quelle: ein gesetztes $LBHOMEDIR
# wurde ueberschrieben, der Ordnername kam aus dem Verzeichnisnamen, und der
# geratene Pfad wurde bei JEDEM Aufruf angelegt - auch bei 'status'.
#
# In WSL gemessen (Pruefung-AudiConnect-0.9.20, messung_vorher.txt):
#   Fall h_status    'status' in einem fremden Baum legte dort
#                    data/plugins/audiconnect und log/plugins/audiconnect an;
#   Fall h_start     derselbe Aufruf startete dort einen Dienst (1 Prozess);
#   Fall h_stop      'stop' nahm dem fremden Baum sein soll_laufen weg und
#                    meldete rc=0;
#   Fall h_archiv    aus dem ausgepackten Archiv heraus entstanden data/ und
#                    log/ ueber dem Archiv - im ersten Grundlauf, in dem das
#                    Archiv direkt unter /tmp lag, waren es /tmp/data/plugins/bin
#                    und /tmp/log/plugins/bin;
#   Fall h_mkdir     'status' in der ECHTEN Anlage legte den gerade
#                    abgeraeumten Datenordner wieder an.
#
# Hausform (Regeln/06, "ohne brauchbare Wurzel warnen statt vollziehen";
# Vorbilder Govee 0.9.20, Sprachsteuerung 0.11.9): Stufe 1 ist die gelesene
# Umgebung, Stufe 2 die Aufwaertssuche nach einem Verzeichnis, das
# nachweislich eine Wurzel IST - config/plugins, data/plugins UND
# config/system/general.json. Ohne den dritten Nachweis gilt jeder Rest eines
# frueheren Pruefstands als Wurzel (Regeln/06, Raumklima 0.11.3: der
# Eichlauf loeschte C:\data\plugins). Eine dritte Stufe "drei Ebenen ueber dem
# Ablageort" gibt es NICHT mehr - sie war genau der Rueckfall, der in Govee,
# Zendure und Weissware nach der Suche stehen geblieben war.
au_wurzel_suchen() {
    au_v="$SELF"
    au_i=0
    while [ -n "$au_v" ] && [ "$au_v" != "/" ] && [ "$au_i" -lt 8 ]; do
        if [ -d "$au_v/config/plugins" ] && [ -d "$au_v/data/plugins" ] \
           && [ -f "$au_v/config/system/general.json" ]; then
            echo "$au_v"
            return 0
        fi
        au_v=$(dirname "$au_v")
        au_i=$((au_i + 1))
    done
    return 1
}
if [ -n "${LBHOMEDIR:-}" ] && [ -d "$LBHOMEDIR/config/plugins" ] \
   && [ -d "$LBHOMEDIR/data/plugins" ]; then
    # 'pwd -P': ist die Wurzel ein Verweis, zaehlt der aufgeloeste Pfad - so
    # steht er in der Befehlszeile des Dienstes, denn SELF ist ueber
    # readlink -f ebenfalls aufgeloest.
    LBHOMEDIR=$(cd "$LBHOMEDIR" && pwd -P)
else
    LBHOMEDIR=$(au_wurzel_suchen) || LBHOMEDIR=""
fi
# Ohne Wurzel: nichts anlegen, nichts starten, nichts anhalten. 'status'
# antwortet mit 4 ("Zustand unbekannt"), damit es sich von 1 ("gestoppt")
# unterscheidet; alles andere mit 1.
if [ -z "$LBHOMEDIR" ]; then
    echo "FEHLER: Es wurde kein LoxBerry-Wurzelverzeichnis gefunden."
    echo "FEHLER: \$LBHOMEDIR ist nicht gesetzt, und oberhalb von $SELF traegt kein"
    echo "FEHLER: Verzeichnis config/plugins, data/plugins und config/system/general.json."
    echo "FEHLER: Es wurde nichts angelegt, nichts gestartet und nichts angehalten."
    [ "${1:-}" = "status" ] && exit 4
    exit 1
fi
# Der Ordnername kommt aus $LBPPLUGINDIR, sonst aus dem Ablageort. Am Geraet
# steht $LBPPLUGINDIR in keiner Cron-Schale (Regeln/03, 43 Linien) - dann
# traegt der Ablageort, und bei einer regulaeren Installation ist das richtig.
PNAME="${LBPPLUGINDIR:-}"
PNAME="${PNAME%/}"
PNAME="${PNAME##*/}"
[ -n "$PNAME" ] || PNAME=$(basename "$SELF")
PBIN="$LBHOMEDIR/bin/plugins/$PNAME"

# Die Gegenprobe steht VOR allem, was schreibt: liegt dieses Skript nicht im
# bin-Ordner der Anlage, und ist <ordner> dort auch kein eingerichtetes
# Plugin, dann kommt der Aufruf aus einem ausgepackten Archiv oder einem
# Pruefordner - es wird nichts angelegt und nichts angefasst.
if [ "$SELF" != "$(readlink -f "$PBIN" 2>/dev/null)" ] \
   && [ ! -d "$LBHOMEDIR/config/plugins/$PNAME" ]; then
    echo "FEHLER: '$PNAME' ist unter $LBHOMEDIR kein eingerichtetes Plugin,"
    echo "        und $SELF ist nicht dessen bin-Ordner."
    echo "        Der Aufruf kommt offenbar aus einem ausgepackten Archiv oder"
    echo "        einem Pruefordner. Es wurde nichts angelegt."
    echo "        Abhilfe: LBHOMEDIR und LBPPLUGINDIR setzen oder dienst.sh aus"
    echo "        <LoxBerry-Wurzel>/bin/plugins/<ordner> aufrufen."
    [ "${1:-}" = "status" ] && exit 4
    exit 1
fi

PDATA="$LBHOMEDIR/data/plugins/$PNAME"
PLOG="$LBHOMEDIR/log/plugins/$PNAME"
PCONFIG="$LBHOMEDIR/config/plugins/$PNAME"
PID="$PDATA/dienst.pid"
SOLL="$PDATA/soll_laufen"
# Die Marke "Aktualisierung laeuft". preupgrade.sh legt sie als Erstes an,
# postinstall.sh entfernt sie unmittelbar vor dem Dienststart. Sie liegt
# NEBEN dem Datenordner, weil purge_installation den Ordner selbst loescht
# (Regeln/06, sbin/plugininstall.pl:1629/1631).
MARKE="$LBHOMEDIR/data/plugins/$PNAME.upgrade_laeuft"
LOGDATEI="$PLOG/audi.log"
# Eigene Datei fuer alles, was NEBEN dem Protokoll anfaellt: Meldungen des
# Starts und alles, was das Programm nach stderr schreibt, bevor sein
# Protokoll steht (Syntaxfehler, fehlende Bibliothek, Abbruch im Importpfad).
#
# Bis 0.9.13 ging diese Ausgabe mit ">> $LOGDATEI" in DIESELBE Datei, die
# bin/audi.py mit einem umlaufenden Handler fuehrt. Das haelt einen zweiten,
# anhaengenden Deskriptor auf diese Datei offen. Beim Ueberlauf benennt der
# Handler um, beim Leeren der Ramdisk verschwindet die Datei ganz - der
# Deskriptor dieser Shell zeigt danach weiter auf die weggeschobene oder
# geloeschte Datei, und was er traegt, sieht niemand mehr. Am Geraet gemessen
# (06.09.2026): sieben Dienste hielten so eine geloeschte Protokolldatei offen.
# Regel: genau einer schreibt in eine Protokolldatei.
STARTLOG="$PLOG/audi_start.log"
# Umgebung und Dienstskript kommen aus der GELESENEN Wurzel, nicht aus dem
# Ablageort. Sonst verwaltete ein dienst.sh aus einem ausgepackten Archiv den
# Dienst des ARCHIVS, waehrend der Aufrufer mit LBHOMEDIR/LBPPLUGINDIR die
# Anlage meinte. Installiert ist PBIN derselbe Ordner wie SELF (Gegenprobe
# oben), der Pfad ist dann zeichengenau derselbe wie bisher - ein von einer
# frueheren Fassung gestarteter Dienst bleibt erkannt.
PY="$PBIN/venv/bin/python3"
SKRIPT="$PBIN/audi.py"
# Der Dienst laeuft als loxberry (siehe den Abstieg oben); wo es den Benutzer
# nicht gibt, als der eigene. Gebraucht fuer die Suche nach Diensten OHNE
# PID-Datei: ohne Benutzergrenze liefe sie ueber fremde Prozesse.
DIENSTUID=$(id -u loxberry 2>/dev/null || id -u)

# Angelegt wird erst beim START - in starten() und im Waechter, bevor er in
# die Startdatei umlenkt -, nicht mehr bei jedem Aufruf. Bis 0.9.19 stand
# dieses mkdir auf oberster Ebene; 'status' legte damit in der Upgrade-Luecke
# den gerade abgeraeumten Datenordner wieder an (Fall h_mkdir). Der Waechter
# braucht es trotzdem: log/plugins ist eine Ramdisk, und fehlt der Ordner
# nach einem Neustart, scheiterte die Umlenkung - und 'exec 2>>datei'
# beendet die Schale, wenn die Umleitung scheitert.
ordner_anlegen() {
    mkdir -p "$PDATA" "$PLOG" 2>/dev/null
}

# Nummernrecycling ausschliessen: der Prozess muss unser Skript sein.
#
# BERICHTIGT IN 0.9.8. Hier stand bis dahin
#     grep -qa "audi.py" "/proc/$P/cmdline"
# und damit genau die zu weiche Pruefung, die au_lib.php in au_dienst_pid()
# ausfuehrlich als Fehler beschreibt und dort seit 0.9.7 ersetzt hatte.
# /proc/<pid>/cmdline enthaelt ALLE Argumente, durch Nullbytes getrennt; ein
# grep darueber trifft auch einen Editor mit geoeffneter audi.py.
#
# Diese Datei wiegt dabei schwerer als die Oberflaeche: sie traegt den
# minuetlichen Waechter. Hatte die wiederverwendete Prozessnummer aus der
# PID-Datei irgendetwas erwischt, in dessen Kommandozeile "audi.py" vorkam,
# hielt der Waechter den toten Dienst fuer lebendig und startete ihn NIE
# wieder. Die Oberflaeche zeigte korrekt "gestoppt", der Waechter tat nichts,
# und niemand fand den Grund.
#
# Geprueft wird jetzt argumentweise: das ZWEITE Argument muss genau unser
# Skript sein - mit vollem Pfad, damit ein zweites Exemplar des Plugins
# (LoxBerry haengt bei Namenskonflikt 01, 02 ... an den Ordnernamen an) nicht
# faelschlich fuer das eigene gehalten wird.
# ERWEITERT IN 0.9.20. Bis 0.9.19 wurde nur argv[1] geprueft. Das ist zu
# wenig: "nano <pfad>/audi.py" fuehrt denselben Pfad als zweites Argument,
# und "audi.py --selbsttest" ist ein Einmallauf, kein Dienst. Geprueft wird
# jetzt viererlei (Regeln/06, Abschnitt zur argumentweisen Diensterkennung;
# Vorbilder Midea2Lox 4.5.6, APC-UPS 1.2.10, Govee 0.9.20):
#   argv[0]  ein Python,
#   argv[1]  zeichengenau unser Dienstskript (bei relativem Start ueber
#            /proc/<pid>/cwd aufgeloest),
#   argv[2]  gibt es nicht (sonst ist es ein Einmallauf),
#   Benutzer der Prozess gehoert dem Dienstbenutzer.
ist_dienst() {   # $1 PID
    [ -r "/proc/$1/cmdline" ] || return 1
    [ "$(stat -c %u "/proc/$1" 2>/dev/null)" = "$DIENSTUID" ] || return 1
    # cat statt Umlenkung: sonst meldet die Schale einen Prozess, der
    # zwischen Auflistung und Lesen endet, auf der Fehlerausgabe - und die
    # landet ueber au_dienst() in der Oberflaeche.
    ROH=$(cat "/proc/$1/cmdline" 2>/dev/null | tr '\0' '\n')
    [ -n "$ROH" ] || return 1
    A0=$(printf '%s\n' "$ROH" | sed -n '1p')
    A1=$(printf '%s\n' "$ROH" | sed -n '2p')
    A2=$(printf '%s\n' "$ROH" | sed -n '3p')
    [ -n "$A0" ] && [ -n "$A1" ] && [ -z "$A2" ] || return 1
    case "${A0##*/}" in python|python[0-9.]*) ;; *) return 1 ;; esac
    case "$A1" in
        /*) ZIEL=$A1 ;;
        *)  WD=$(readlink "/proc/$1/cwd" 2>/dev/null) || return 1
            ZIEL="${WD% (deleted)}/$A1" ;;
    esac
    [ "$ZIEL" = "$SKRIPT" ]
}

# Jeder eigene Dienst, auch der OHNE PID-Datei.
#
# NEU IN 0.9.20, und der Grund steht in Pruefung-AudiConnect-0.9.19: haelt
# preupgrade.sh den Dienst nicht an - weil purge_installation die PID-Datei
# mit dem Datenordner geloescht hat -, ueberlebt der alte Dienst unsichtbar,
# und postinstall.sh stellt einen ZWEITEN daneben. Gemessen: 2 Prozesse
# (Fall g_waise_upgrade). Beide fuehren ihre eigene Drosselung, melden sich
# beide bei myAudi an und schreiben in dieselben Dateien.
dienste_suchen() {
    for D in /proc/[0-9]*; do
        ist_dienst "${D#/proc/}" && echo "${D#/proc/}"
    done
    return 0
}

# Die Nummer des laufenden Dienstes auf der Ausgabe, Rueckgabe 0/1.
# Erst die PID-Datei - sie ist die billige und die richtige Antwort, solange
# sie stimmt -, danach die Suche. Beide Wege pruefen argumentweise.
dienst_pid() {
    if [ -f "$PID" ]; then
        P=$(cat "$PID" 2>/dev/null)
        if [ -n "$P" ] && ist_dienst "$P"; then
            printf '%s\n' "$P"
            return 0
        fi
    fi
    P=$(dienste_suchen | sed -n '1p')
    [ -n "$P" ] || return 1
    printf '%s\n' "$P"
    return 0
}

# Laeuft gerade eine Aktualisierung dieses Plugins?
#
# Der Installer legt die Cron-Datei rund eine Minute VOR postinstall.sh neu
# an; dazwischen hat purge_installation config/, data/ und bin/ dieses
# Plugins geloescht (Regeln/06, am Geraet 08.09.2026 an der Einspeisebremse
# gemessen). In WSL nachgestellt (Pruefung-AudiConnect-0.9.19,
# messe_au_marke.sh): der Minutentakt startet in dieser Luecke nichts, weil
# der Sollmerker mit dem Datenordner verschwunden ist - der Knopf "Dienst
# starten" der Oberflaeche aber schon, sobald postinstall.sh die virtuelle
# Umgebung angelegt hat und noch beim pip-Lauf steht (Fall knopf_venv:
# rc=0, "gestartet", 1 Prozess). Der Dienst laeuft dann gegen eine halb
# eingerichtete Umgebung.
#
# Die Marke traegt die Unixzeit ihrer Entstehung:
#   juenger als 3600 s  -> es laeuft eine Installation, nicht starten
#   aelter, aus der Zukunft oder unlesbar -> sie gilt nicht. Eine
#     abgebrochene Installation darf den Dienst nicht fuer immer stilllegen.
#   ohne lesbare Uhr faellt die Pruefung GESCHLOSSEN aus (die Marke gilt):
#     ein Schutz, der seine Entscheidungsgrundlage verliert, laesst nicht
#     durch (CLAUDE.md Punkt 4).
upgrade_laeuft() {
    [ -f "$MARKE" ] || return 1
    JETZT=$(date +%s 2>/dev/null)
    case "$JETZT" in ''|*[!0-9]*) return 0 ;; esac
    SEIT=$(cat "$MARKE" 2>/dev/null)
    case "$SEIT" in ''|*[!0-9]*) return 1 ;; esac
    [ "$SEIT" -gt "$JETZT" ] && return 1
    [ $((JETZT - SEIT)) -lt 3600 ]
}

laeuft() {
    dienst_pid >/dev/null 2>&1
}

# Stehen E-Mail UND Passwort in der Zugangsdatei? NEU IN 0.9.15.
#
# Bis 0.9.14 fragte starten() nur, ob die Datei EXISTIERT. postinstall.sh
# legt sie aber bei jeder Neuinstallation als "{}" an - die Pruefung war
# damit immer erfuellt. Am Geraet gemessen (11.09.2026, 0.9.14,
# Neuinstallation ohne Zugangsdaten, einmal "Dienst starten" gedrueckt):
# der Sollmerker blieb liegen, audi.py brach jedes Mal mit "Zugangsdaten
# fehlen" ab, und der Waechter startete es zwischen 01:40 und 09:03 genau
# 444 Mal neu - 889 Protokollzeilen, auf den Tag gerechnet rund 2.880.
# Regeln/03 verlangt: der Sollmerker wird erst nach erfolgreicher Pruefung
# gesetzt oder im Fehlerzweig entfernt.
#
# Gelesen wird mit dem Python der eigenen Umgebung, nicht mit grep: eine
# Zeichenkettensuche hielte {"email": "", "passwort": ""} fuer vollstaendig.
# Ausgegeben wird nichts aus der Datei - weder hier noch im Fehlerfall.
zugang_vollstaendig() {
    [ -r "$PCONFIG/zugang.json" ] || return 1
    "$PY" -c 'import json, sys
try:
    z = json.load(open(sys.argv[1], encoding="utf-8"))
except Exception:
    sys.exit(1)
ok = isinstance(z, dict) and str(z.get("email") or "").strip() != "" and str(z.get("passwort") or "") != ""
sys.exit(0 if ok else 1)' "$PCONFIG/zugang.json" 2>/dev/null
}

starten() {
    if P=$(dienst_pid); then
        # Die Nummer kommt aus der PID-Datei ODER aus der Suche. Der zweite
        # Weg ist der Punkt: ohne ihn stellte dieser Aufruf einem Dienst ohne
        # PID-Datei einen zweiten daneben (Fall g_waise_start: 2 Prozesse).
        # Die PID-Datei wird hier NICHT nachgetragen - das taete ein Schreiben
        # in einem Zweig, der nichts startet; 'status' und der Waechter finden
        # die Waise ueber dieselbe Suche.
        echo "laeuft bereits (PID $P)"
        return 0
    fi
    # Der Sollmerker wird hier NICHT angefasst: waehrend einer Aktualisierung
    # soll sich am Willen des Anwenders nichts aendern. postinstall.sh startet
    # den Dienst am Ende selbst und entfernt die Marke vorher.
    if upgrade_laeuft; then
        echo "Es laeuft gerade eine Aktualisierung dieses Plugins."
        echo "Der Dienst wird nach der Installation gestartet."
        return 0
    fi
    if [ ! -x "$PY" ]; then
        rm -f "$SOLL"
        echo "FEHLER: virtuelle Python-Umgebung fehlt ($PY). Plugin neu installieren."
        return 1
    fi
    if ! zugang_vollstaendig; then
        rm -f "$SOLL"
        echo "FEHLER: Es sind keine vollstaendigen Zugangsdaten hinterlegt ($PCONFIG/zugang.json)."
        echo "        Erst im Reiter Einstellungen E-Mail und Passwort des myAudi-Kontos eintragen"
        echo "        und speichern. Der Dienst bleibt angehalten; der Waechter startet ihn nicht."
        return 1
    fi
    # Erst hier wird angelegt: nach der Markenpruefung und nachdem feststeht,
    # dass wirklich gestartet wird (siehe ordner_anlegen weiter oben).
    ordner_anlegen
    touch "$SOLL"
    # Die Ausgabe des Dienstes geht in die Startdatei, NICHT in das Protokoll:
    # dort schreibt allein der Handler des Programms. Beim Start gekappt, damit
    # sie nur die Ausgabe EINES Laufes sammelt und nicht unbegrenzt waechst.
    # Der Waechter kappt selbst, bevor er seine Fehlerausgabe hineinlenkt,
    # und setzt STARTLOG_KAPPEN=0 - sonst ginge hier verloren, was er
    # vorher schon hineingeschrieben hat.
    [ "${STARTLOG_KAPPEN:-1}" = 0 ] || : > "$STARTLOG"
    nohup "$PY" "$SKRIPT" >> "$STARTLOG" 2>&1 &
    echo $! > "$PID"
    # Drei Sekunden hinsehen, nicht eine - BERICHTIGT IN 0.9.15.
    #
    # Bis 0.9.14 stand hier ein einzelnes "sleep 1". Im Sandkasten am Geraet
    # gemessen (11.09.2026): audi.py braucht auf dem Pi laenger als eine
    # Sekunde, um die Bibliothek zu laden und abzubrechen; dienst.sh meldete
    # "gestartet (PID ...)" mit Rueckgabewert 0 fuer einen Dienst, der zwei
    # Sekunden spaeter tot war. Am Knopf derselben Anlage kam dagegen "Start
    # fehlgeschlagen" - welche Antwort erschien, entschied der Zufall.
    for i in 1 2 3; do
        sleep 1
        laeuft || break
    done
    if laeuft; then
        echo "gestartet (PID $(cat "$PID"))"
        return 0
    fi
    # Stirbt er gleich nach dem Start, hilft ein Neustart je Minute nicht:
    # Sollmerker fort, und die letzten Zeilen gehoeren in die Meldung
    # (Regeln/03), nicht nur ein Verweis auf zwei Dateien.
    rm -f "$PID" "$SOLL"
    echo "FEHLER: Der Dienst hat sich gleich nach dem Start wieder beendet."
    echo "        Der Waechter startet ihn nicht erneut."
    if [ -s "$LOGDATEI" ]; then
        echo "Letzte Zeilen aus $LOGDATEI:"
        tail -n 3 "$LOGDATEI" | sed 's/^/    /'
    fi
    if [ -s "$STARTLOG" ]; then
        echo "Ausgabe des Starts ($STARTLOG):"
        tail -n 5 "$STARTLOG" | sed 's/^/    /'
    fi
    return 1
}

anhalten() {
    rm -f "$SOLL"
    # ALLE eigenen Dienste, nicht nur den aus der PID-Datei - NEU IN 0.9.20.
    # Sonst bleibt eine Waise stehen, und der naechste Start teilt sich mit
    # ihr das myAudi-Konto (Fall g_waise_stop: 1 Prozess blieb uebrig).
    # Beendet wird nur, was die argumentweise Suche gefunden hat, und vor
    # jedem Signal steht dieselbe Probe.
    LISTE=$(dienste_suchen)
    if [ -z "$LISTE" ]; then
        rm -f "$PID"
        echo "laeuft nicht"
        return 0
    fi
    P=$(printf '%s\n' "$LISTE" | tr '\n' ' ')
    P=${P% }
    kill $LISTE 2>/dev/null
    for i in 1 2 3 4 5 6 7 8 9 10; do
        [ -n "$(dienste_suchen)" ] || break
        sleep 1
    done
    REST=$(dienste_suchen)
    if [ -n "$REST" ]; then
        kill -9 $REST 2>/dev/null
        sleep 1
    fi
    # NACHSEHEN, ob er wirklich weg ist - BERICHTIGT IN 0.9.12.
    #
    # Bis 0.9.11 folgte hier ohne weitere Pruefung "rm -f $PID" und
    # "angehalten", und die Rueckgabewerte beider kill wurden verworfen.
    # Das ist woertlich der Fall, den der Kopf dieser Datei beschreibt:
    # gehoert der Vorgang einem anderen Benutzer - nach einem Upgrade von
    # 0.9.10, wo der Abstieg auf loxberry noch fehlte, laeuft der alte
    # Dienst als root -, scheitert das kill mit EPERM, das rm gelingt aber,
    # weil das Verzeichnis loxberry gehoert. Danach meldet "laeuft" false,
    # ein anschliessendes "start" legt ein ZWEITES Exemplar an, und beide
    # fuehren ihre eigene, prozesslokale Bremse - die Drosselung, wegen der
    # es sie gibt, ist damit halbiert.
    UEBRIG=$(dienste_suchen)
    if [ -n "$UEBRIG" ]; then
        echo "FEHLER: Vorgang $(printf '%s\n' "$UEBRIG" | tr '\n' ' ')laeuft weiter - die PID-Datei bleibt stehen."
        echo "        Gehoert er einem anderen Benutzer? ps -o user= -p $UEBRIG"
        return 1
    fi
    rm -f "$PID"
    echo "angehalten ($P)"
    return 0
}

case "$1" in
    start)   starten ;;
    stop)    anhalten ;;
    restart) anhalten; sleep 1; starten ;;
    status)
        if P=$(dienst_pid); then
            echo "laeuft $P"
            exit 0
        fi
        echo "gestoppt"
        exit 1
        ;;
    waechter)
        # Nur neu starten, wenn der Dienst laufen SOLL. Ein bewusst
        # angehaltener Dienst bleibt angehalten.
        #
        # SEIT 0.9.15: ohne Zugangsdaten gar nicht erst anlaufen, sondern den
        # Sollmerker zuruecknehmen und es EINMAL sagen. Und scheitert der
        # Neustart, sagt das Protokoll es, statt nur die Startdatei.
        # Die Ausgabe von starten() wird gesammelt und danach angehaengt:
        # starten() kappt die Startdatei selbst, und eine Umleitung in
        # dieselbe Datei, aus der es im Fehlerfall zitiert, liefe im Kreis.
        # upgrade_laeuft in DERSELBEN Bedingung, nicht als eigener Zweig
        # darunter: sonst kappte der Waechter erst die Startdatei und
        # schriebe seine Zeile ins Protokoll, bevor er merkt, dass er nichts
        # tun darf - eine Zeile je Minute, solange die Installation laeuft.
        if [ -f "$SOLL" ] && ! laeuft && ! upgrade_laeuft; then
            # Die Fehlerausgabe DIESES Skripts geht, sobald der Waechter
            # etwas tut, in die Startdatei - SEIT 0.9.15. Der Cron-Eintrag
            # ruft mit ">/dev/null 2>&1" auf (am Geraet gelesen, 11.09.2026),
            # und damit verschwand jede Meldung der Schale, etwa ein
            # Protokoll ohne Schreibrecht. Regeln/03: der Cron verschluckt
            # seine Fehlerausgabe nicht. Die Umlenkung steht HIER und nicht
            # in der Cron-Datei, damit sie auch fuer einen Aufruf von Hand
            # gilt. BERICHTIGT IN 0.9.16: hier stand, die Cron-Datei werde bei
            # einem Update nicht zuverlaessig erneuert. Das war falsch -
            # plugininstall.pl loescht sie bei jedem Upgrade (:1554 rm -fv)
            # und kopiert sie neu (:990 cp), Regeln/06. Erst kappen, dann
            # umlenken; starten() kappt deshalb hier nicht noch einmal.
            ordner_anlegen
            : > "$STARTLOG"
            exec 2>>"$STARTLOG"
            jetzt=$(date '+%Y-%m-%d %H:%M:%S')
            if [ -x "$PY" ] && ! zugang_vollstaendig; then
                rm -f "$SOLL"
                echo "[$jetzt] Waechter: keine Zugangsdaten - Dienst bleibt angehalten, Sollmerker entfernt." >> "$LOGDATEI"
                exit 0
            fi
            echo "[$jetzt] Waechter: Dienst lief nicht, wird neu gestartet." >> "$LOGDATEI"
            if ausgabe=$(STARTLOG_KAPPEN=0 starten 2>&1); then
                printf '%s\n' "$ausgabe" >> "$STARTLOG"
            else
                printf '%s\n' "$ausgabe" >> "$STARTLOG"
                echo "[$(date '+%Y-%m-%d %H:%M:%S')] Waechter: Neustart gescheitert - Sollmerker entfernt, Einzelheiten in $STARTLOG." >> "$LOGDATEI"
            fi
        fi
        ;;
    *)
        echo "Aufruf: $0 {start|stop|restart|status|waechter}"
        exit 2
        ;;
esac
