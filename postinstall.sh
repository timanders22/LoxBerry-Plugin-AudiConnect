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

# Fail-closed wie in uninstall/uninstall: sieht die Lage nicht wie ein
# LoxBerry aus, wird nichts angelegt. Ohne diese Zeile war BASE nach einem
# fehlgeschlagenen cd leer, und der Installer legte als root /data/plugins,
# /log/plugins und /config/plugins im Wurzelverzeichnis an - mit Erfolg,
# weil root das darf, und mit einer Installation, die Erfolg meldet.
if [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ]; then
    echo "<FAIL> $BASE sieht nicht wie ein LoxBerry aus (config/plugins und"
    echo "<FAIL> data/plugins fehlen). Es wurde nichts angelegt."
    exit 1
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
# Die Rechte gleich hier setzen, nicht erst am Ende. Zwischen dem Anlegen
# und dem chown am Dateiende liegen sieben Abbruchstellen, und eine davon
# sagt ausdruecklich "Das Plugin bleibt installiert". Nach so einem
# Abbruch gehoerten die Ordner und die frisch angelegte zugang.json dem
# Benutzer root; die Oberflaeche laeuft als loxberry und koennte die
# Zugangsdaten dann nicht speichern. Am Ende wird es wiederholt, weil die
# venv erst dort steht.
chown -R loxberry:loxberry "$PDATA" "$PLOG" "$PCONFIG" 2>/dev/null || true

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
            if cp -p "$BK" "$CF"; then
                echo "<OK> $f aus Sicherung wiederhergestellt."
            else
                echo "<FAIL> $f liess sich nicht wiederherstellen ($BK)."
            fi
        fi
    fi
done
chmod 600 "$PCONFIG/zugang.json"

# ---------- Den Datenordner zurueckholen ----------
#
# NEU IN 0.9.12. Ein Upgrade raeumt data/plugins/<ordner>/ ab - nachgemessen
# am 05.09.2026 an sbin/plugininstall.pl (Einzelheiten im Kopf von
# preupgrade.sh). Bis 0.9.11 stand hier das Gegenteil, und mit jedem Update
# gingen der Verlauf, das Ladeprotokoll, der Merker und die Anmeldemarken
# verloren, ohne dass irgendwo etwas davon stand.
#
# Zurueckgespielt wird nur, was fehlt: eine Neuinstallation neben einer
# alten Rettung soll den frischen Stand nicht ueberschreiben.
RETTUNG="$BASE/data/plugins/$PFOLDER.rettung"
if [ -d "$RETTUNG" ]; then
    for f in merker.json token.json; do
        if [ -f "$RETTUNG/$f" ] && [ ! -s "$PDATA/$f" ]; then
            if cp -p "$RETTUNG/$f" "$PDATA/$f"; then
                echo "<OK> $f zurueckgespielt."
            else
                echo "<FAIL> $f liess sich nicht zurueckspielen."
            fi
        fi
    done
    if [ -d "$RETTUNG/verlauf" ] && [ ! -d "$PDATA/verlauf" ]; then
        if cp -rp "$RETTUNG/verlauf" "$PDATA/verlauf"; then
            echo "<OK> Verlauf und Ladeprotokoll zurueckgespielt."
        else
            echo "<FAIL> Der Verlauf liess sich nicht zurueckspielen."
        fi
    fi
    # Erst wegraeumen, wenn wirklich etwas angekommen ist - sonst waere ein
    # fehlgeschlagenes Zurueckspielen ein endgueltiger Verlust.
    if [ -s "$PDATA/merker.json" ] || [ -d "$PDATA/verlauf" ] || [ -s "$PDATA/token.json" ]; then
        rm -rf "$RETTUNG"
    else
        echo "<INFO> $RETTUNG bleibt liegen - es kam nichts an."
    fi
fi
chmod 600 "$PDATA/token.json" 2>/dev/null || true

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
    # BEIDE Pakete nennen, nicht nur den Connector.
    #
    # Frueher stand hier allein "carconnectivity-connector-audi". pip haette
    # dann die neueste Fassung geholt und als Abhaengigkeit gleich die neueste
    # carconnectivity mitgezogen - genau das Geruest, dessen Fassung oben mit
    # KERN festgenagelt wird. Der Ersatzweg haette die Festlegung also
    # ausgehebelt, ohne dass es jemand merkt.
    if ! "$VENV/bin/python3" -m pip install --no-cache-dir --prefer-binary \
            "carconnectivity" "carconnectivity-connector-audi"; then
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

# ---------- paho-mqtt fuer den Mithoerer ----------
#
# NEU IN 0.9.8. Gebraucht wird es nur, wenn die Vorklimatisierung am
# Abfahrtsassistenten oder die Ladeempfehlung aus einem fremden Thema benutzt
# wird - beide sind ab Werk AUS. Deshalb ist ein Fehlschlag hier KEIN Grund
# abzubrechen: das Plugin ist ohne paho voll brauchbar, nur diese beiden
# Zusatzfunktionen bleiben wirkungslos. Gesagt wird es trotzdem, und die
# Selbstpruefung im Reiter Test nennt es ebenfalls.
#
# carconnectivity bringt paho NICHT mit - nachgesehen, nicht angenommen: der
# Kern hat keine MQTT-Abhaengigkeit, die steckt allein im Zusatzplugin
# carconnectivity-plugin-mqtt, das dieses Plugin nicht benutzt.
if "$VENV/bin/python3" -c 'import paho.mqtt.client' 2>/dev/null; then
    echo "<OK> paho-mqtt ist bereits vorhanden."
# Die Ausgabe wird gefangen, nicht verworfen. Bis 0.9.11 stand hier
# ">/dev/null 2>&1", und damit war der GRUND fort - kein Netz, PyPI nicht
# erreichbar, kein passendes Rad fuer die Architektur, ein Proxy. Die vier
# INFO-Zeilen nannten die Folge und nie die Ursache. Der Hauptaufruf weiter
# oben macht es richtig: dort ist die Ausgabe sichtbar.
elif PAHO_AUSGABE=$("$VENV/bin/python3" -m pip install --no-cache-dir \
        --prefer-binary "paho-mqtt" 2>&1) \
     && "$VENV/bin/python3" -c 'import paho.mqtt.client' 2>/dev/null; then
    echo "<OK> paho-mqtt installiert (fuer Vorklimatisierung und Ladeempfehlung)."
else
    echo "<INFO> paho-mqtt liess sich nicht installieren. Das Plugin laeuft trotzdem;"
    echo "<INFO> nur die Vorklimatisierung am Abfahrtsassistenten und die"
    echo "<INFO> Ladeempfehlung aus einem fremden MQTT-Thema bleiben wirkungslos."
    echo "<INFO> Beide sind ab Werk ausgeschaltet."
    if [ -n "$PAHO_AUSGABE" ]; then
        echo "<INFO> pip meldete: $(echo "$PAHO_AUSGABE" | tail -n 3 | tr '\n' ' ')"
    fi
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
# JEDER Rueckgabewert wird geprueft - das verspricht der Kopf dieser Datei,
# und der Rechte-Block war bis 0.9.11 der einzige, der es nicht tat: fuenf
# Aufrufe, alle mit unterdrueckter Fehlerausgabe, keiner geprueft, und
# unmittelbar danach "<OK> Installation abgeschlossen." Fehlt der Benutzer
# loxberry, kann der Dienst spaeter weder Protokoll noch Daten schreiben.
chmod 755 "$PBIN/audi.py" 2>/dev/null || echo "<INFO> chmod auf audi.py nicht moeglich."
chmod 755 "$PBIN/dienst.sh" 2>/dev/null || echo "<INFO> chmod auf dienst.sh nicht moeglich."
if ! chown -R loxberry:loxberry "$PBIN" "$PDATA" "$PLOG" "$PCONFIG" 2>/dev/null; then
    echo "<FAIL> Die Dateien liessen sich nicht dem Benutzer loxberry zuordnen."
    echo "<FAIL> Gibt es den Benutzer? (id loxberry). Ohne die richtigen Rechte"
    echo "<FAIL> kann der Dienst weder Daten noch Protokoll schreiben."
    exit 1
fi
chmod 600 "$PCONFIG/zugang.json" || echo "<INFO> chmod 600 auf zugang.json nicht moeglich."
chmod 600 "$PDATA/token.json" 2>/dev/null || true

# ---------- Dienst wieder starten, wenn er vor dem Upgrade lief ----------
#
# Der Merker entsteht nur in preupgrade.sh und nur dann, wenn dort ein
# laufender Vorgang angehalten wurde. Bei einer Erstinstallation gibt es
# ihn nicht, und dann passiert hier nichts.
#
# BERICHTIGT IN 0.9.12. Hier stand, der Sollmerker unter data/ ueberlebe
# das Upgrade und der Cron-Waechter hole den Dienst ohnehin binnen einer
# Minute zurueck; dieser Start sei nur eine Verkuerzung.
#
# Beides ist falsch. Nachgemessen am 05.09.2026 an sbin/plugininstall.pl
# (Einzelheiten im Kopf von preupgrade.sh): das Upgrade raeumt
# data/plugins/<ordner>/ ab, und damit ist soll_laufen fort. bin/dienst.sh
# startet im Waechterzweig NUR, wenn es diese Datei gibt. Der Waechter holt
# den Dienst also NICHT zurueck - dieser Merker ist das Einzige, was ihn
# wieder anwirft. Deshalb haengt er seit 0.9.12 am Sollmerker und nicht
# mehr daran, ob gerade ein Vorgang lief: ein zum Upgrade-Zeitpunkt
# abgestuerzter Dienst waere sonst dauerhaft aus geblieben.
#
# Er wird IN JEDEM FALL entfernt, auch wenn der Start scheitert. Ein
# liegengebliebener Merker startete den Dienst bei einer spaeteren
# Installation ungefragt - auch dann, wenn er absichtlich abgeschaltet
# worden war.
#
# Abgeraeumt wird er an ZWEI Stellen: hier und im Deinstallationsskript,
# das es seit 0.9.7 gibt. Beide braucht es: nach einem abgebrochenen
# Upgrade laeuft keins von beiden, und dann startet die naechste
# Installation den Dienst einmal - der sich ohne Zugangsdaten selbst
# abweist. Nachgesehen, nicht angenommen.
MERKER="$BASE/config/plugins/$PFOLDER.lief_vorher"
if [ -f "$MERKER" ]; then
    rm -f "$MERKER"
    if [ ! -x "$PBIN/dienst.sh" ]; then
        echo "<INFO> $PBIN/dienst.sh fehlt - der Dienst wurde nicht gestartet."
    else
        # Als loxberry und nicht als root: der Dienst schreibt in data/
        # und log/. Was root dort anlegt, kann die Oberflaeche danach
        # nicht mehr ueberschreiben.
        if [ "$(id -u)" = "0" ]; then
            AUSGABE=$(su -s /bin/bash -c "$PBIN/dienst.sh start" loxberry 2>&1)
        else
            AUSGABE=$("$PBIN/dienst.sh" start 2>&1)
        fi
        case "$AUSGABE" in
            *gestartet*|*laeuft*)
                echo "<OK> Dienst wieder gestartet: $AUSGABE" ;;
            *)
                echo "<INFO> Der Dienst liess sich nicht wieder starten: $AUSGABE"
                echo "<INFO> Reiter Einstellungen, Knopf 'Dienst starten'." ;;
        esac
    fi
fi

echo "<OK> Installation abgeschlossen."
echo "<INFO> Bitte die Plugin-Oberflaeche oeffnen, die Zugangsdaten des myAudi-Kontos"
echo "<INFO> eintragen und den Dienst im Reiter Einstellungen starten."
exit 0
