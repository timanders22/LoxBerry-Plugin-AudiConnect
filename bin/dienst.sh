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
PNAME=$(basename "$SELF")
LBHOMEDIR=$(cd "$SELF/../../.." && pwd)
PDATA="$LBHOMEDIR/data/plugins/$PNAME"
PLOG="$LBHOMEDIR/log/plugins/$PNAME"
PCONFIG="$LBHOMEDIR/config/plugins/$PNAME"
PID="$PDATA/dienst.pid"
SOLL="$PDATA/soll_laufen"
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
PY="$SELF/venv/bin/python3"
SKRIPT="$SELF/audi.py"

mkdir -p "$PDATA" "$PLOG" 2>/dev/null

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
ist_unser_dienst() {
    [ -r "/proc/$1/cmdline" ] || return 1
    [ "$(tr '\0' '\n' < "/proc/$1/cmdline" 2>/dev/null | sed -n '2p')" = "$SKRIPT" ]
}

laeuft() {
    [ -f "$PID" ] || return 1
    P=$(cat "$PID" 2>/dev/null)
    [ -n "$P" ] || return 1
    kill -0 "$P" 2>/dev/null || return 1
    ist_unser_dienst "$P" || return 1
    return 0
}

starten() {
    if laeuft; then
        echo "laeuft bereits (PID $(cat "$PID"))"
        return 0
    fi
    if [ ! -x "$PY" ]; then
        echo "FEHLER: virtuelle Python-Umgebung fehlt ($PY). Plugin neu installieren."
        return 1
    fi
    if [ ! -f "$PCONFIG/zugang.json" ]; then
        echo "FEHLER: Zugangsdaten fehlen ($PCONFIG/zugang.json). Erst in der Oberflaeche eintragen."
        return 1
    fi
    touch "$SOLL"
    # Die Ausgabe des Dienstes geht in die Startdatei, NICHT in das Protokoll:
    # dort schreibt allein der Handler des Programms. Beim Start gekappt, damit
    # sie nur die Ausgabe EINES Laufes sammelt und nicht unbegrenzt waechst.
    : > "$STARTLOG"
    nohup "$PY" "$SKRIPT" >> "$STARTLOG" 2>&1 &
    echo $! > "$PID"
    sleep 1
    if laeuft; then
        echo "gestartet (PID $(cat "$PID"))"
        return 0
    fi
    echo "FEHLER: Start fehlgeschlagen - siehe $STARTLOG und $LOGDATEI"
    rm -f "$PID"
    return 1
}

anhalten() {
    rm -f "$SOLL"
    if ! laeuft; then
        rm -f "$PID"
        echo "laeuft nicht"
        return 0
    fi
    P=$(cat "$PID")
    kill "$P" 2>/dev/null
    for i in 1 2 3 4 5 6 7 8 9 10; do
        laeuft || break
        sleep 1
    done
    if laeuft; then
        kill -9 "$P" 2>/dev/null
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
    if laeuft; then
        echo "FEHLER: Vorgang $P laeuft weiter - die PID-Datei bleibt stehen."
        echo "        Gehoert er einem anderen Benutzer? ps -o user= -p $P"
        return 1
    fi
    rm -f "$PID"
    echo "angehalten"
    return 0
}

case "$1" in
    start)   starten ;;
    stop)    anhalten ;;
    restart) anhalten; sleep 1; starten ;;
    status)
        if laeuft; then
            echo "laeuft $(cat "$PID")"
            exit 0
        fi
        echo "gestoppt"
        exit 1
        ;;
    waechter)
        # Nur neu starten, wenn der Dienst laufen SOLL. Ein bewusst
        # angehaltener Dienst bleibt angehalten.
        if [ -f "$SOLL" ] && ! laeuft; then
            echo "[$(date '+%Y-%m-%d %H:%M:%S')] Waechter: Dienst lief nicht, wird neu gestartet." >> "$LOGDATEI"
            starten >> "$STARTLOG" 2>&1
        fi
        ;;
    *)
        echo "Aufruf: $0 {start|stop|restart|status|waechter}"
        exit 2
        ;;
esac
