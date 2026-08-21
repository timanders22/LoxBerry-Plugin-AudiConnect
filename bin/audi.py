#!REPLACELBPBINDIR/venv/bin/python3
"""Audi Connect - Abrufdienst fuer LoxBerry.

Holt die Werte der myAudi-Schnittstelle ueber die freie Bibliothek
"carconnectivity" samt ihrem Audi-Connector, legt sie als
JSON-Zwischenspeicher ab, gibt sie auf Wunsch ueber das LoxBerry-MQTT-Gateway
weiter und arbeitet Schreibbefehle aus einer Warteschlange ab, die der
Loxone-Endpunkt fuellt.

Zwei Bibliotheken, zwei Herkuenfte - das ist wichtig zu wissen:
  carconnectivity                        das Geruest, von Till Steinbach
  carconnectivity-connector-audi         der Audi-Teil, von Achim Fischer,
                                         aufbauend auf diesem Geruest

Der Audi-Connector ist deutlich juenger als der fuer Volkswagen (erste
Fassung 28.09.2025, aktuell 0.3.2) und hat einen anderen Betreuer. Technisch
ist er baugleich: dieselben Befehlsklassen, dieselben Attribute, dieselbe
Untergrenze von 180 Sekunden. Wo dieses Plugin auf etwas baut, das nur der
Connector liefern kann, steht der Vorbehalt dabei.

Drei Aufgaben, drei Dateien - dieses Skript ist der Dienst. Die Oberflaeche
(webfrontend/htmlauth/index.php) und der Miniserver-Endpunkt
(webfrontend/html/index.php) rufen es nie direkt auf, sondern lesen den
Zwischenspeicher beziehungsweise legen Befehle ab.

=============================================================================
WAS AM AUDI-CONNECTOR 0.3.2 GEMESSEN IST (20.08.2026)
=============================================================================
Gegen den Quelltext des Tags v0.3.2 gemessen - also gegen genau die Fassung,
die postinstall.sh festnagelt. Der Stand von "main" ist byteweise derselbe
(cmp ueber connector.py).

SIEBEN ATTRIBUTE GIBT ES IM KERNMODELL, DER AUDI-CONNECTOR FUELLT SIE NIE:
    software.version, license_plate, model_year, parking_brake, manufacturer,
    battery.total_capacity, CombustionDrive.oil_level
Gemessen: keiner dieser Namen kommt im Connector auch nur vor (Volltextsuche
ueber connector.py, vehicle.py, climatization.py, charging.py, capability.py).
Das Plugin liest sie weiterhin - eine spaetere Connector-Fassung koennte sie
fuellen -, aber die Oberflaeche und die Feldtabellen BENENNEN sie als nicht
belegt. Ein Feld, das dauerhaft leer bleibt und nicht sagt warum, ist ein
Versprechen, das das Plugin nicht halten kann.

ZWEI BEFEHLE SIND VORHANDEN, ABER UNBRAUCHBAR BEZIEHUNGSWEISE SCHADHAFT:
  - wake-sleep mit "sleep": der Connector wirft dafuer ausdruecklich
    CommandError("Sleep command not supported by vehicle...") (Z. 3423).
    Deshalb bietet dieses Plugin nur "wake" an.
  - honk-flash: der else-Zweig haengt am try/except statt am if (Z. 3487-3490).
    Nach einem ERFOLGREICHEN POST wird deshalb CommandError("Unknown command
    ...") geworfen. Das Plugin wertet genau diese Meldung als Erfolg - der
    Ausnahmezweig ist nur erreichbar, wenn der POST 200/204 ergab. Derselbe
    Fehler steckt im Volkswagen-Connector (Z. 1920).

DER ORTSDIENST LAEUFT OHNE ZUTUN: carconnectivity.py registriert
OSMLocationService und GeofenceLocationService fest (Z. 333-340). Strasse und
Ort entstehen also von selbst, sobald eine Position vorliegt.

Aufrufe:
    audi.py                 Dienst (Dauerbetrieb)
    audi.py --einmal        ein einzelner Abruf, dann Ende
    audi.py --selbsttest    Pruefungen ohne Netz, Ausgabe als Klartext
"""

from __future__ import annotations

import json
import logging
import math
import os
import signal
import socket
import subprocess
import sys
import time
from datetime import datetime, timezone
from logging.handlers import RotatingFileHandler
from pathlib import Path


def lb_wurzel_ermitteln():
    """Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.

    Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
    config/plugins UND webfrontend enthaelt. Trifft die uebliche
    Installation genauso wie eine an einem anderen Ort.
    """
    d = os.path.dirname(os.path.abspath(__file__))
    for _ in range(8):
        if os.path.isdir(os.path.join(d, "config", "plugins")) \
                and os.path.isdir(os.path.join(d, "webfrontend")):
            return d
        eltern = os.path.dirname(d)
        if eltern == d:
            break
        d = eltern
    return ""


def mqtt_wert_saeubern(wert):
    """Einen Wert fuer den UDP-Eingang des MQTT-Gateways unschaedlich machen.

    Das Gateway liest zeilenweise. Ein Zeilenumbruch im Wert zerlegt die
    Uebertragung, und aus den Bruchstuecken bildet das Gateway erfundene
    Themen. Ein Tabulator schadet ebenso, weil Leerzeichen Thema und Wert
    trennt.
    """
    text = str(wert)
    for zeichen in ("\r\n", "\r", "\n", "\t"):
        text = text.replace(zeichen, " ")
    while "  " in text:
        text = text.replace("  ", " ")
    return text.strip()


# ---------------------------------------------------------------------------
# Pfade aus dem EIGENEN Ablageort ableiten.
#
# Nicht ueber LoxBerry::System: das leitet den Pluginordner aus dem Aufrufort
# ab und liefert bei einem Start aus postinstall.sh oder aus dem Cron ueberall
# Leerstring. Sichtbare Folge waere ein Dienst, der gegen /-Pfade werkelt und
# trotzdem Erfolg meldet.
# ---------------------------------------------------------------------------
SELF = Path(__file__).resolve().parent            # <home>/bin/plugins/<ordner>
PNAME = SELF.name
if len(SELF.parents) >= 3:
    LBHOME = SELF.parents[2]
else:
    LBHOME = Path(os.environ.get("LBHOMEDIR") or lb_wurzel_ermitteln())
PDATA = LBHOME / "data" / "plugins" / PNAME
PLOG = LBHOME / "log" / "plugins" / PNAME
PCONFIG = LBHOME / "config" / "plugins" / PNAME

DATEI_CONFIG = PCONFIG / "audi.json"
DATEI_ZUGANG = PCONFIG / "zugang.json"
DATEI_CACHE = PDATA / "cache.json"
DATEI_LOXONE = PDATA / "loxone.json"
DATEI_ZUSTAND = PDATA / "zustand.json"
DATEI_TOKEN = PDATA / "token.json"          # Anmeldemarken der Bibliothek
DATEI_ZWISCHEN = PDATA / "bibliothek_cache.json"
DATEI_MERKER = PDATA / "merker.json"        # ueberlebt einen Neustart
ORDNER_BEFEHLE = PDATA / "befehle"
ORDNER_ANTWORTEN = PDATA / "antworten"
ORDNER_VERLAUF = PDATA / "verlauf"
DATEI_LADUNGEN = ORDNER_VERLAUF / "ladungen.csv"
DATEI_LOG = PLOG / "audi.log"
SKRIPT_MELDEN = SELF / "au_notify.php"

# Muessen zu au_vorgaben() in webfrontend/html/au_lib.php passen.
#
# Der Takt hat eine harte Untergrenze von 180 Sekunden: der Connector wirft
# darunter beim Anlegen einen ValueError ("Intervall must be at least 180
# seconds"). Der Wert wird deshalb schon hier gekappt, nicht erst dort.
VORGABEN = {
    "intervall": 300,
    "takt_wartung": 12,
    "mqtt_ein": 0,
    "mqtt_topic": "audi",
    "steuerung_ein": 0,
    "temp_min": 16,
    "temp_max": 29,
    "verlauf_tage": 8,
    # --- Eingreifende Befehle: ZWEITER Haken, ab Werk aus -------------------
    # Ver- und Entriegeln, Hupe und Lichthupe wirken auf ein Fahrzeug, das
    # irgendwo im oeffentlichen Raum steht. Sie haengen deshalb nicht am
    # allgemeinen Steuerungshaken, sondern an einem eigenen, der ZUSAETZLICH
    # gesetzt sein muss. Der Vorbehalt steht in der Oberflaeche daneben.
    "gefahr_ein": 0,
    "probe_ein": 0,
    "gps_ein": 1,
    "melden_ein": 1,
    # --- Drosselung --------------------------------------------------------
    "abruf_abstand": 60,
    "befehle_stunde": 30,
    "strom_abstand": 300,
    # --- Gerechnete Groessen. Leer heisst: der Wert entsteht nicht. --------
    "kapazitaet": 0,
    "heim_breite": "",
    "heim_laenge": "",
    "heim_radius": 150,
    # --- Vorklimatisierung am Abfahrtsassistenten - ab Werk AUS ------------
    "abfahrt_ein": 0,
    "abfahrt_praefix": "abfahrt",
    "abfahrt_vorlauf": 20,
    "abfahrt_temp": 21,
    "abfahrt_alter": 300,
    "abfahrt_fahrzeug": 1,
    # --- Ladeempfehlung aus einem fremden Thema - ab Werk AUS --------------
    "ladeempf_ein": 0,
    "ladeempf_thema": "",
    "ladeempf_grenze": 0,
    "ladeempf_unter": 1,
    "ladeempf_alter": 900,
}

TAKT_MIN = 180

# Zustaende, die Loxone als Zahl braucht. Ein unbekannter Zustand wird zu None
# (am Endpunkt ein Strich), NICHT zu 0 - eine 0 waere eine stille
# Falschaussage: "Tueren zu", obwohl niemand es weiss.
OFFEN_ZU = {"closed": 0, "open": 1, "ajar": 1}
AN_AUS = {"off": 0, "on": 1}
VERRIEGELT = {"locked": 1, "unlocked": 0}
KLIMA_AN = {"off": 0, "heating": 1, "cooling": 1, "ventilation": 1}
# Der Klartext dahinter, als Stufe fuer Loxone: 0 aus, 1 heizen, 2 kuehlen,
# 3 luefteln. KLIMA bildet alle drei auf 1 ab - wer den Unterschied braucht,
# liest KLIMAART.
KLIMA_ART = {"off": 0, "heating": 1, "cooling": 2, "ventilation": 3}
LADEN_AN = {"charging": 1, "off": 0, "ready_for_charging": 0, "conservation": 0,
            "error": 0, "discharging": 0}
# Auch hier der Klartext als Stufe: LAEDT bildet fuenf Zustaende auf 0 ab.
LADE_STUFE = {"off": 0, "charging": 1, "ready_for_charging": 2,
              "conservation": 3, "discharging": 4, "error": 5}
KABEL = {"connected": 1, "disconnected": 0}
STECKER = {"locked": 1, "unlocked": 0}
# Gemessen am Kern (charging_connector.py Z. 83-88).
EXTERNE_KRAFT = {"unavailable": 0, "available": 1, "active": 2}
# Gemessen am Kern (charging.py Z. 178-182).
LADESTROMART = {"off": 0, "ac": 1, "dc": 2}
# Gemessen am Kern (position.py Z. 86-89): der Wert heisst "parking", nicht
# "parked". Ein geratener Name waere hier still falsch gewesen.
POSITIONSART = {"parking": 1, "driving": 2, "invalid": 0}
# Fahrzeugzustand als Stufe: je hoeher, desto "wacher".
FAHRZEUGZUSTAND = {"offline": 0, "parked": 1, "ignition_on": 2, "driving": 3}
ERREICHBAR = {"online": 1, "reachable": 1, "connected": 1,
              "offline": 0, "disconnected": 0, "error": 0}
HEIZQUELLE = {"electric": 1}

# Ladestrom: der Connector nimmt nur diese Stufen an. Fahrzeuge, die den Strom
# in Ampere fuehren, koennen 5/10/13/32; die uebrigen kennen nur "reduziert"
# (6) und "maximal" (16). Welches von beidem, weiss nur das Fahrzeug - deshalb
# werden hier alle sechs zugelassen und die Ablehnung der Bibliothek
# weitergereicht, statt vorher zu raten.
LADESTROM_STUFEN = (5, 6, 10, 13, 16, 32)

# ---------------------------------------------------------------------------
# Fehlerklassen als ZAHL.
#
# Bis 0.9.7 hat der Endpunkt versucht, aus dem Fehlertext eine Klasse zu
# raten - er suchte nach "429", "timeout", "too many". Im Text steht aber die
# DEUTSCHE Fassung, die fehlertext() weiter unten erzeugt. Von vierzehn
# Meldungen trafen genau zwei, und die nur zufaellig, weil sie das Wort
# "Zugangsdaten" enthalten. Ausgerechnet die beiden Faelle, wegen derer es die
# Klasse gibt - "Konto gedrosselt" gegen "Audi gestoert" -, waren
# unerreichbar.
#
# Die Klasse entsteht jetzt HIER, an der Ausnahme selbst, und wandert als Zahl
# in zustand.json und loxone.json. Der Endpunkt liest sie, statt zu raten.
# ---------------------------------------------------------------------------
CODE_OK = 0
CODE_NIE = 1
CODE_ANMELDUNG = 2
CODE_GEDROSSELT = 3
CODE_UNERREICHBAR = 4
CODE_STOERUNG = 5
CODE_KEIN_FAHRZEUG = 6
CODE_ZUGANG_FEHLT = 7
CODE_EINRICHTUNG = 8
CODE_UNBEKANNT = 9

_LAUF = True
_LOG = logging.getLogger("audi")
_LETZTE_MELDUNG: dict = {}
_LETZTE_NOTIZ: dict = {}


# ---------------------------------------------------------------------------
# Protokollierung
#
# Ausschliesslich in die Datei. Das Startskript leitet die Ausgabe des Dienstes
# ohnehin in dieselbe Datei um - ein zweiter Kanal nach stdout schriebe jede
# Zeile doppelt hinein.
# ---------------------------------------------------------------------------
def log_einrichten() -> None:
    PLOG.mkdir(parents=True, exist_ok=True)
    _LOG.setLevel(logging.INFO)
    try:
        h = RotatingFileHandler(DATEI_LOG, maxBytes=512000, backupCount=1, encoding="utf-8")
    except OSError as err:
        h = logging.StreamHandler(sys.stderr)
        print(f"Logdatei nicht beschreibbar ({err}) - schreibe nach stderr.", file=sys.stderr)
    h.setFormatter(logging.Formatter("[%(asctime)s] %(levelname)s %(message)s", "%Y-%m-%d %H:%M:%S"))
    _LOG.handlers = [h]
    _LOG.propagate = False
    # Die Bibliothek protokolliert reichlich und in den eigenen Wurzel-Logger.
    # Ohne diesen Umweg landet nichts davon in der Plugin-Logdatei, und mit
    # DEBUG wuerde sie unlesbar.
    wurzel = logging.getLogger("carconnectivity")
    wurzel.handlers = [h]
    wurzel.setLevel(logging.WARNING)
    wurzel.propagate = False
    for fremd in ("requests", "urllib3", "oauthlib", "requests_oauthlib", "paho"):
        logging.getLogger(fremd).setLevel(logging.WARNING)


def melde_gebremst(schluessel: str, text: str, sekunden: int = 3600) -> None:
    """Dieselbe Meldung hoechstens einmal je Zeitfenster - sonst wird die
    Logdatei durch eine Dauerstoerung unlesbar."""
    jetzt = time.time()
    if jetzt - _LETZTE_MELDUNG.get(schluessel, 0) >= sekunden:
        _LETZTE_MELDUNG[schluessel] = jetzt
        _LOG.warning(text)


def melden(schluessel: str, schwere: int, text: str, sekunden: int = 21600) -> None:
    """Eine Meldung in den Benachrichtigungsbereich des LoxBerry legen.

    Fuer Python gibt es dafuer keine LoxBerry-Schnittstelle, deshalb der Umweg
    ueber bin/au_notify.php. Schlaegt er fehl, ist das KEIN Fehler des
    Abrufs - es wird vermerkt und weitergearbeitet.

    Gebremst wie das Protokoll: eine Dauerstoerung darf den
    Benachrichtigungsbereich nicht zuschuetten.
    """
    if not config().get("melden_ein"):
        return
    jetzt = time.time()
    if jetzt - _LETZTE_NOTIZ.get(schluessel, 0) < sekunden:
        return
    _LETZTE_NOTIZ[schluessel] = jetzt
    if not SKRIPT_MELDEN.is_file():
        melde_gebremst("melden_fehlt",
                       f"{SKRIPT_MELDEN} fehlt - es wurde keine Benachrichtigung abgelegt.",
                       86400)
        return
    try:
        e = subprocess.run(["php", str(SKRIPT_MELDEN), str(int(schwere)), text, PNAME],
                           stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=20,
                           check=False)
        if e.returncode != 0:
            melde_gebremst("melden_fehl",
                           "Benachrichtigung nicht abgelegt (%s): %s"
                           % (e.returncode, e.stderr.decode("utf-8", "replace").strip()),
                           86400)
    except (OSError, subprocess.SubprocessError) as err:
        melde_gebremst("melden_ruf", f"au_notify.php nicht aufrufbar: {err}", 86400)


# ---------------------------------------------------------------------------
# Konfiguration
# ---------------------------------------------------------------------------
def json_lesen(pfad: Path) -> dict:
    try:
        with pfad.open("r", encoding="utf-8") as f:
            d = json.load(f)
        return d if isinstance(d, dict) else {}
    except (OSError, ValueError):
        return {}


def json_schreiben(pfad: Path, daten, rechte=None) -> bool:
    """Erst in eine Nebendatei, dann umbenennen. So liest die Oberflaeche nie
    eine halb geschriebene Datei."""
    try:
        pfad.parent.mkdir(parents=True, exist_ok=True)
        tmp = pfad.with_suffix(pfad.suffix + ".tmp")
        with tmp.open("w", encoding="utf-8") as f:
            json.dump(daten, f, ensure_ascii=False, indent=1, default=str)
        if rechte is not None:
            os.chmod(tmp, rechte)
        os.replace(tmp, pfad)
        return True
    except (OSError, TypeError, ValueError) as err:
        _LOG.error("Datei %s konnte nicht geschrieben werden: %s", pfad, err)
        return False


def ganz(wert, ersatz: int) -> int:
    try:
        return int(wert)
    except (TypeError, ValueError):
        return ersatz


def zahl(wert):
    """Fliesskommazahl oder None. Ein leeres Feld ergibt None, nicht 0."""
    if wert is None or wert == "":
        return None
    try:
        return float(str(wert).replace(",", "."))
    except (TypeError, ValueError):
        return None


def config() -> dict:
    c = dict(VORGABEN)
    c.update(json_lesen(DATEI_CONFIG))
    c["intervall"] = max(TAKT_MIN, min(3600, ganz(c.get("intervall"), 300)))
    c["takt_wartung"] = max(1, min(240, ganz(c.get("takt_wartung"), 12)))
    c["temp_min"] = max(10, min(30, ganz(c.get("temp_min"), 16)))
    c["temp_max"] = max(10, min(30, ganz(c.get("temp_max"), 29)))
    if c["temp_min"] > c["temp_max"]:
        c["temp_min"], c["temp_max"] = c["temp_max"], c["temp_min"]
    c["verlauf_tage"] = max(1, min(90, ganz(c.get("verlauf_tage"), 8)))
    c["abruf_abstand"] = max(0, min(3600, ganz(c.get("abruf_abstand"), 60)))
    c["befehle_stunde"] = max(0, min(500, ganz(c.get("befehle_stunde"), 30)))
    c["strom_abstand"] = max(0, min(3600, ganz(c.get("strom_abstand"), 300)))
    c["kapazitaet"] = max(0, min(500, ganz(c.get("kapazitaet"), 0)))
    c["heim_radius"] = max(20, min(5000, ganz(c.get("heim_radius"), 150)))
    c["abfahrt_vorlauf"] = max(1, min(120, ganz(c.get("abfahrt_vorlauf"), 20)))
    c["abfahrt_temp"] = max(c["temp_min"], min(c["temp_max"],
                                               ganz(c.get("abfahrt_temp"), 21)))
    c["abfahrt_alter"] = max(60, min(3600, ganz(c.get("abfahrt_alter"), 300)))
    c["abfahrt_fahrzeug"] = max(1, min(99, ganz(c.get("abfahrt_fahrzeug"), 1)))
    c["ladeempf_alter"] = max(60, min(86400, ganz(c.get("ladeempf_alter"), 900)))
    return c


def zugang() -> dict:
    z = json_lesen(DATEI_ZUGANG)
    return {
        "email": str(z.get("email") or "").strip(),
        "passwort": str(z.get("passwort") or ""),
        "spin": str(z.get("spin") or ""),
    }


# ---------------------------------------------------------------------------
# MQTT ueber das LoxBerry-Gateway
#
# Das MQTT-Gateway ist seit LoxBerry 3 Bestandteil des Systems, kein Plugin.
# Es wird nicht nachinstalliert, sondern unter System -> MQTT Gateway
# eingeschaltet.
#
# Achtung: Mqtt.Brokerhost ist ab Werk gesetzt ("localhost"). Eine Pruefung
# darauf beantwortet also NICHT die Frage, ob Nachrichten ankommen koennen.
# Massgeblich ist Gatewayautostart.
# ---------------------------------------------------------------------------
def mqtt_zustand() -> dict:
    gen = json_lesen(LBHOME / "config" / "system" / "general.json")
    m = gen.get("Mqtt") or gen.get("mqtt") or {}
    autostart = m.get("Gatewayautostart", m.get("gatewayautostart"))
    udp = m.get("Udpinport", m.get("udpinport"))
    try:
        udp = int(udp)
    except (TypeError, ValueError):
        udp = 0
    return {
        "gefunden": bool(m),
        "autostart": 1 if str(autostart) in ("1", "true", "True") else 0,
        "udpport": udp,
        "broker": str(m.get("Brokerhost", m.get("brokerhost", ""))),
        "brokerport": str(m.get("Brokerport", m.get("brokerport", ""))),
    }


def mqtt_broker() -> dict:
    """Zugang zum Broker - fuer den Horcher, der ZUHOERT statt zu senden.

    Gesendet wird ueber den UDP-Eingang des Gateways; dafuer braucht es keine
    Anmeldung. Zum Abonnieren fremder Themen fuehrt aber kein Weg am Broker
    vorbei, und der des LoxBerry verlangt ab Werk eine Anmeldung.
    """
    gen = json_lesen(LBHOME / "config" / "system" / "general.json")
    m = gen.get("Mqtt") or gen.get("mqtt") or {}

    def hol(gross, klein, ersatz=""):
        if gross in m:
            return m[gross]
        return m.get(klein, ersatz)

    return {
        "host": str(hol("Brokerhost", "brokerhost", "localhost")) or "localhost",
        "port": ganz(hol("Brokerport", "brokerport", 1883), 1883),
        "benutzer": str(hol("Brokeruser", "brokeruser", "")),
        "passwort": str(hol("Brokerpass", "brokerpass", "")),
    }


def mqtt_senden(paare: dict, praefix: str) -> None:
    z = mqtt_zustand()
    if not z["udpport"]:
        melde_gebremst("mqtt_kein_port",
                       "MQTT: kein UDP-Eingangsport in general.json gefunden - nichts gesendet.")
        return
    if not z["autostart"]:
        melde_gebremst(
            "mqtt_aus",
            "MQTT: das Gateway ist nicht auf Autostart gestellt (System -> MQTT Gateway). "
            "Es wird gesendet, aber vermutlich hoert niemand zu.",
        )
    try:
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    except OSError as err:
        melde_gebremst("mqtt_socket", f"MQTT: Socket nicht moeglich ({err}).")
        return
    try:
        for k, v in paare.items():
            if v is None:
                continue
            # Zeilenumbrueche zerreissen die Syntax des UDP-Gateways: es liest
            # Zeile fuer Zeile, ein \n im Wert waere der Anfang eines neuen
            # Befehls. Genau das trifft den Fall, in dem es zaehlt - der Wert
            # ist dann oft ein Fehlertext von myAudi, und der ist mehrzeilig.
            sauber = str(v).replace("\r", " ").replace("\n", " ").strip()
            s.sendto(f"publish {praefix}/{k} {mqtt_wert_saeubern(sauber)}".encode("utf-8"),
                     ("127.0.0.1", z["udpport"]))
    except OSError as err:
        melde_gebremst("mqtt_senden", f"MQTT: Senden fehlgeschlagen ({err}).")
    finally:
        s.close()


class Horcher:
    """Haelt eine Verbindung zum Broker und die zuletzt empfangenen Werte.

    Der Horcher gehoert in den DIENST, nicht in die Oberflaeche. Zwei
    Verbindungen zum selben Broker aus zwei Prozessen waeren zwei Stellen, die
    auseinanderlaufen - und die Oberflaeche wird bei jedem Klick neu aufgebaut,
    haette also nie eine Vorgeschichte.
    """

    def __init__(self):
        self.client = None
        self.werte: dict = {}          # thema -> (wert, zeitstempel)
        self.themen: set = set()
        self.fehler = ""
        self.verbunden = False

    def moeglich(self):
        try:
            import paho.mqtt.client as mqtt  # noqa: F401
        except ImportError:
            return (False, "Das Python-Modul paho-mqtt fehlt in der virtuellen Umgebung. "
                           "carconnectivity bringt es NICHT mit - postinstall.sh "
                           "installiert es zusaetzlich, und das braucht eine "
                           "Internetverbindung. Ohne es kann das Plugin keine fremden "
                           "Themen lesen; Vorklimatisierung und Ladeempfehlung bleiben aus.")
        return (True, "")

    def sicherstellen(self, themen: set) -> None:
        """Verbindet, falls noetig, und richtet die Abos auf themen aus."""
        themen = {t for t in themen if t}
        if not themen:
            self.schliessen()
            return
        ok, grund = self.moeglich()
        if not ok:
            if self.fehler != grund:
                self.fehler = grund
                melde_gebremst("horcher_modul", grund, 86400)
            return
        import paho.mqtt.client as mqtt

        if self.client is None:
            b = mqtt_broker()
            try:
                # Ein eigener Client-Name je Prozess: zwei Clients mit
                # demselben Namen werfen sich gegenseitig vom Broker.
                self.client = mqtt.Client(
                    mqtt.CallbackAPIVersion.VERSION2,
                    client_id="audiconnect-%s-%d" % (PNAME, os.getpid()))
            except (AttributeError, TypeError):
                # Aeltere paho-Fassungen kennen die Aufzaehlung nicht.
                self.client = mqtt.Client(client_id="audiconnect-%s-%d"
                                          % (PNAME, os.getpid()))
            if b["benutzer"] != "":
                self.client.username_pw_set(b["benutzer"], b["passwort"])
            self.client.on_message = self._nachricht
            self.client.on_connect = self._verbunden_cb
            self.client.on_disconnect = self._getrennt_cb
            try:
                self.client.connect(b["host"], b["port"], keepalive=60)
                self.client.loop_start()
            except Exception as err:  # noqa: BLE001
                grund = ("Der Broker %s:%d hat die Verbindung nicht angenommen: %s. "
                         "Ist ein Benutzer hinterlegt? Der Broker des LoxBerry "
                         "verlangt ab Werk eine Anmeldung."
                         % (b["host"], b["port"], err))
                self.fehler = grund
                melde_gebremst("horcher_verbindung", grund, 900)
                self.client = None
                return
            self.fehler = ""

        neu = themen - self.themen
        fort = self.themen - themen
        for t in sorted(fort):
            try:
                self.client.unsubscribe(t)
            except Exception:  # noqa: BLE001
                pass
            self.werte.pop(t, None)
        for t in sorted(neu):
            try:
                self.client.subscribe(t, qos=0)
                _LOG.info("Horcher abonniert %s", t)
            except Exception as err:  # noqa: BLE001
                melde_gebremst("horcher_abo", "Abo %s nicht moeglich: %s" % (t, err), 900)
        self.themen = set(themen)

    def _verbunden_cb(self, *args, **kwargs):
        self.verbunden = True
        # Nach jedem Verbindungsaufbau werden die Abos neu gesetzt: der Broker
        # kennt sie nach einer Trennung nicht mehr.
        for t in sorted(self.themen):
            try:
                self.client.subscribe(t, qos=0)
            except Exception:  # noqa: BLE001
                pass

    def _getrennt_cb(self, *args, **kwargs):
        self.verbunden = False

    def _nachricht(self, client, userdata, nachricht):
        try:
            text = nachricht.payload.decode("utf-8", "replace").strip()
        except Exception:  # noqa: BLE001
            return
        self.werte[nachricht.topic] = (text, time.time())

    def wert(self, thema: str, hoechstalter: int):
        """Wert und Alter, oder (None, -1) wenn nichts Frisches vorliegt."""
        eintrag = self.werte.get(thema)
        if not eintrag:
            return (None, -1)
        alter = int(time.time() - eintrag[1])
        if alter > hoechstalter:
            return (None, alter)
        return (eintrag[0], alter)

    def schliessen(self) -> None:
        if self.client is not None:
            try:
                self.client.loop_stop()
                self.client.disconnect()
            except Exception:  # noqa: BLE001
                pass
        self.client = None
        self.themen = set()
        self.verbunden = False


# ---------------------------------------------------------------------------
# Werte aus den Attributobjekten der Bibliothek holen
#
# Jedes Feld ist ein Attributobjekt mit .value, .unit und .enabled. Ein Feld,
# das das Fahrzeug nicht liefert, ist entweder nicht enabled oder hat den Wert
# None - beides ergibt hier None und am Endpunkt einen Strich. Bewusst keine 0:
# eine 0 waere eine stille Falschaussage.
# ---------------------------------------------------------------------------
def wert(attr, einheit=None, nachkomma=None):
    """Wert eines Attributs, auf Wunsch in eine feste Einheit umgerechnet.

    Die Einheit ist wichtig: die Bibliothek liefert je nach Konto Kilometer
    oder Meilen, Grad Celsius oder Fahrenheit. Wer das nicht umrechnet, sendet
    irgendwann Meilen an einen Baustein, der Kilometer erwartet - und niemand
    sieht es, weil die Zahl plausibel bleibt.
    """
    if attr is None:
        return None
    try:
        if attr.enabled is False:
            return None
    except AttributeError:
        pass
    v = getattr(attr, "value", None)
    if v is None:
        return None
    if einheit is not None:
        u = getattr(attr, "unit", None)
        if u is not None and u != einheit:
            try:
                v = type(attr).convert(v, u, einheit)
            except Exception:  # noqa: BLE001 - eine misslungene Umrechnung ist kein Wert
                return None
            if v is None:
                return None
    if isinstance(v, bool):
        return 1 if v else 0
    if nachkomma is not None:
        try:
            return round(float(v), nachkomma) if nachkomma else int(round(float(v)))
        except (TypeError, ValueError):
            return None
    return v


def etext(attr) -> str:
    """Ein Aufzaehlungswert als Zeichenkette ('locked', 'charging' ...)."""
    v = getattr(attr, "value", None) if attr is not None else None
    if v is None:
        return ""
    return str(getattr(v, "value", v))


def kennzahl(attr, tabelle: dict):
    """Aufzaehlungswert in eine Zahl fuer Loxone. Unbekannt bleibt None."""
    t = etext(attr).lower()
    return tabelle.get(t)


def tage_bis(attr):
    """Tage bis zu einem Termin. Vergangene Termine ergeben negative Werte -
    das ist gewollt: 'Inspektion seit 12 Tagen faellig' ist eine Aussage."""
    d = getattr(attr, "value", None) if attr is not None else None
    if not isinstance(d, datetime):
        return None
    jetzt = datetime.now(timezone.utc) if d.tzinfo else datetime.now()
    return int(round((d - jetzt).total_seconds() / 86400))


def zeitstempel(attr):
    d = getattr(attr, "value", None) if attr is not None else None
    if not isinstance(d, datetime):
        return None
    try:
        return int(d.timestamp())
    except (OSError, OverflowError, ValueError):
        return None


def geaendert_vor(attr):
    """Sekunden, seit sich dieses Attribut zuletzt GEAENDERT hat.

    Der Kern fuehrt je Attribut last_changed und last_updated
    (attributes.py Z. 95-98). Der Unterschied zaehlt: last_updated wandert bei
    jedem Abruf mit, auch wenn derselbe Wert kam. Fuer "wie lange steht das
    Fahrzeug schon" ist nur last_changed brauchbar.
    """
    d = getattr(attr, "last_changed", None) if attr is not None else None
    if not isinstance(d, datetime):
        return None
    jetzt = datetime.now(timezone.utc) if d.tzinfo else datetime.now()
    try:
        return max(0, int((jetzt - d).total_seconds()))
    except (OverflowError, ValueError):
        return None


def offene_teile(behaelter, feldname: str, attributname: str, offen: tuple):
    """Zaehlt die offenen (bzw. eingeschalteten) Einzelteile und benennt sie.

    Die Bibliothek fuehrt Tueren, Fenster, Leuchten und Scheibenheizungen
    JEWEILS als Verzeichnis einzelner Objekte (doors.doors, windows.windows,
    lights.lights, window_heatings.windows - am Kern gemessen). Der
    Sammelzustand sagt nur, DASS etwas offen ist. Welche Tuer, sagt erst das
    Verzeichnis - und genau das braucht eine Meldung, die nachts jemanden
    weckt.

    Rueckgabe: (Anzahl, "front_left, trunk"). Anzahl None, wenn es das
    Verzeichnis nicht gibt - nicht 0: eine 0 hiesse "nachgesehen, alles zu".
    """
    verzeichnis = getattr(behaelter, feldname, None) if behaelter is not None else None
    if not isinstance(verzeichnis, dict) or not verzeichnis:
        return (None, "")
    namen = []
    bekannt = 0
    for name, teil in verzeichnis.items():
        t = etext(getattr(teil, attributname, None)).lower()
        if t == "":
            continue
        bekannt += 1
        if t in offen:
            namen.append(str(name))
    if bekannt == 0:
        return (None, "")
    return (len(namen), ", ".join(sorted(namen)))


# ---------------------------------------------------------------------------
# Fehlermeldungen, die sagen, wer geantwortet hat - und eine Klasse als Zahl
# ---------------------------------------------------------------------------
def fehlertext(err: Exception) -> str:
    name = type(err).__name__
    inhalt = str(err) or name
    klein = inhalt.lower()

    if name in ("TooManyRequestsError",):
        return ("Audi hat wegen zu vieler Anfragen abgewiesen. Den Takt in den "
                "Einstellungen vergroessern; unter 300 Sekunden ist erfahrungsgemaess zu dicht.")
    if name in ("TemporaryAuthenticationError",):
        return ("Die Anmeldung war voruebergehend nicht moeglich. Meist eine Stoerung bei "
                "Audi - der naechste Takt versucht es erneut.")
    if name in ("AuthenticationError",):
        if "netrc" in klein:
            return ("Die Zugangsdatei konnte nicht gelesen werden. Zugangsdaten in der "
                    "Oberflaeche neu eintragen und speichern.")
        return ("Anmeldung abgewiesen: Benutzername oder Passwort stimmen nicht. Es sind die "
                "Zugangsdaten des myAudi-Kontos, nicht die eines Haendlerportals. Wenn beides "
                "stimmt: Audi verlangt bei manchen Konten eine Zwei-Faktor-Bestaetigung, die "
                "sich hier nicht automatisieren laesst - dann einmal im Browser auf diesem "
                "Geraet anmelden.")
    if name in ("APICompatibilityError",):
        return ("Die Antwort von Audi sah anders aus als erwartet. Das passiert, wenn Audi "
                "die Schnittstelle umbaut - dann hilft nur eine neuere Fassung des "
                "Audi-Connectors. Er ist juenger als der fuer Volkswagen; solche Faelle sind "
                "hier wahrscheinlicher.")
    if name in ("ConfigurationError",):
        return f"Die Bibliothek hat die Einstellungen abgelehnt: {inhalt}"
    if name in ("SetterError", "CommandError"):
        return f"Der Befehl wurde nicht angenommen: {inhalt}"
    if name in ("RetrievalError", "MultipleRetrievalError", "APIError"):
        return f"Abruf fehlgeschlagen: {inhalt}"

    grund = getattr(err, "os_error", None)
    errno = getattr(grund, "errno", None) if grund is not None else getattr(err, "errno", None)
    if errno == 111:
        return ("Verbindung abgewiesen (ECONNREFUSED): der Gegenstelle ist der Port bekannt, "
                "aber es lauscht nichts.")
    if errno == 113:
        return "Kein Weg zum Ziel (EHOSTUNREACH): Netzwerk und Standardroute des LoxBerry pruefen."
    if errno in (-2, -3):
        return ("Namensaufloesung fehlgeschlagen: der DNS-Server des LoxBerry antwortet nicht. "
                "Ohne DNS erreicht das Plugin weder Audi noch den Zeitserver.")
    if "timed out" in klein or name in ("Timeout", "ReadTimeout", "ConnectTimeout"):
        return ("Zeitueberlauf: Audi hat nicht geantwortet. Meist eine gestoerte "
                "Internetverbindung oder eine Stoerung beim Anbieter.")
    if "<html" in klein or "<!doctype" in klein:
        return ("Es kam HTML statt JSON zurueck - geantwortet hat also ein vorgelagerter Dienst "
                "(Proxy, Portal, Fehlerseite), nicht die Audi-Schnittstelle. Die Anmeldung "
                "selbst ist damit nicht der Fehler.")
    return f"{name}: {inhalt}"


def fehler_code(err: Exception) -> int:
    """Die Fehlerklasse als Zahl - an der AUSNAHME bestimmt, nicht am Text.

    Bis 0.9.7 hat der Endpunkt das aus dem deutschen Fehlertext geraten und
    lag in zwoelf von vierzehn Faellen auf 9 ("unbekannt"). Hier steht die
    Ausnahme selbst zur Verfuegung; das ist die einzige Stelle, an der sich
    die Klasse ohne Raten bestimmen laesst.
    """
    name = type(err).__name__
    klein = (str(err) or "").lower()

    if name == "TooManyRequestsError" or "429" in klein or "too many requests" in klein:
        return CODE_GEDROSSELT
    if name == "AuthenticationError":
        return CODE_ANMELDUNG
    if name in ("TemporaryAuthenticationError", "APICompatibilityError"):
        return CODE_STOERUNG
    if name == "ConfigurationError":
        return CODE_EINRICHTUNG
    if name in ("Timeout", "ReadTimeout", "ConnectTimeout") or "timed out" in klein:
        return CODE_UNERREICHBAR

    grund = getattr(err, "os_error", None)
    errno = getattr(grund, "errno", None) if grund is not None else getattr(err, "errno", None)
    if errno in (111, 113, -2, -3):
        return CODE_UNERREICHBAR
    if name in ("ConnectionError", "NewConnectionError", "MaxRetryError", "gaierror"):
        return CODE_UNERREICHBAR
    if "<html" in klein or "<!doctype" in klein:
        return CODE_STOERUNG
    # Ein HTTP-Status im Text ist ein Hinweis, aber nur wenn er als solcher
    # dasteht. Die Pruefung, die hier bis 0.9.7 im Endpunkt stand, lautete
    # strpos($text,'5')===0 - das hiess "der Text beginnt mit der Ziffer 5"
    # und traf nie.
    for muster in (" 500", " 502", " 503", " 504", "http 5"):
        if muster in klein:
            return CODE_STOERUNG
    if " 401" in klein or "unauthorized" in klein:
        return CODE_ANMELDUNG
    return CODE_UNBEKANNT


# ---------------------------------------------------------------------------
# Entfernungsrechnung fuer den Geofence
# ---------------------------------------------------------------------------
def entfernung_m(b1, l1, b2, l2):
    """Luftlinie in Metern zwischen zwei Punkten (Haversine).

    Fehlt eine der vier Zahlen, entsteht KEIN Wert - und ganz gewiss keine 0.
    Eine 0 hiesse "das Fahrzeug steht genau hier".
    """
    if b1 is None or l1 is None or b2 is None or l2 is None:
        return None
    try:
        r = 6371000.0
        p1 = math.radians(float(b1))
        p2 = math.radians(float(b2))
        dp = math.radians(float(b2) - float(b1))
        dl = math.radians(float(l2) - float(l1))
        a = math.sin(dp / 2) ** 2 + math.cos(p1) * math.cos(p2) * math.sin(dl / 2) ** 2
        return int(round(2 * r * math.asin(min(1.0, math.sqrt(a)))))
    except (TypeError, ValueError, OverflowError):
        return None


# ---------------------------------------------------------------------------
# Abbilden eines Fahrzeugs
#
# Jeder Abschnitt einzeln abgesichert: hat ein Fahrzeug keine Klimatisierung
# oder keinen Ladeanschluss, bleiben genau diese Felder leer.
# ---------------------------------------------------------------------------
def antriebe(fahrzeug) -> dict:
    """Die Antriebe eines Fahrzeugs nach Art sortiert.

    Ein e-tron hat einen Antrieb, ein TFSI e deren zwei. Die Bibliothek fuehrt
    sie in einem Verzeichnis, dessen Schluessel je Fahrzeug anders heissen -
    massgeblich ist deshalb die Klasse, nicht der Schluessel.
    """
    elektro = None
    verbrenner = None
    drives = getattr(getattr(fahrzeug, "drives", None), "drives", None) or {}
    for d in drives.values():
        name = type(d).__name__
        if "Electric" in name and elektro is None:
            elektro = d
        elif "Combustion" in name or "Diesel" in name:
            if verbrenner is None:
                verbrenner = d
    return {"elektro": elektro, "verbrenner": verbrenner, "anzahl": len(drives)}


def abbild_stamm(fahrzeug) -> dict:
    from carconnectivity.units import Length
    # kennzeichen, baujahr, hersteller und software stehen hier weiterhin,
    # obwohl der Audi-Connector 0.3.2 sie nachweislich NIE fuellt (siehe
    # Kopf). Eine spaetere Fassung koennte es tun; bis dahin sagt die
    # Oberflaeche ausdruecklich, dass sie leer bleiben.
    return {
        "vin": str(wert(getattr(fahrzeug, "vin", None)) or ""),
        "name": str(wert(getattr(fahrzeug, "name", None)) or ""),
        "modell": str(wert(getattr(fahrzeug, "model", None)) or ""),
        "hersteller": str(wert(getattr(fahrzeug, "manufacturer", None)) or ""),
        "baujahr": wert(getattr(fahrzeug, "model_year", None)),
        "kennzeichen": str(wert(getattr(fahrzeug, "license_plate", None)) or ""),
        "antriebsart": etext(getattr(fahrzeug, "type", None)),
        "software": str(wert(getattr(getattr(fahrzeug, "software", None), "version", None)) or ""),
        "kilometerstand": wert(getattr(fahrzeug, "odometer", None), Length.KM, 0),
    }


def abbild_status(fahrzeug) -> dict:
    from carconnectivity.units import Temperature
    tueren = getattr(fahrzeug, "doors", None)
    fenster = getattr(fahrzeug, "windows", None)
    lichter = getattr(fahrzeug, "lights", None)
    heizung = getattr(fahrzeug, "window_heatings", None)
    klima = getattr(fahrzeug, "climatization", None)
    einst = getattr(klima, "settings", None)

    tuer_anz, tuer_namen = offene_teile(tueren, "doors", "open_state", ("open", "ajar"))
    fen_anz, fen_namen = offene_teile(fenster, "windows", "open_state", ("open", "ajar"))
    licht_anz, licht_namen = offene_teile(lichter, "lights", "light_state", ("on",))
    heiz_anz, heiz_namen = offene_teile(heizung, "windows", "heating_state", ("on",))

    return {
        "zustand": kennzahl(getattr(fahrzeug, "state", None), FAHRZEUGZUSTAND),
        "zustand_text": etext(getattr(fahrzeug, "state", None)),
        "erreichbar": kennzahl(getattr(fahrzeug, "connection_state", None), ERREICHBAR),
        "aktiv": wert(getattr(fahrzeug, "is_active", None)),
        "verriegelt": kennzahl(getattr(tueren, "lock_state", None), VERRIEGELT),
        "tueren_offen": kennzahl(getattr(tueren, "open_state", None), OFFEN_ZU),
        "tueren_anzahl": tuer_anz,
        "tueren_namen": tuer_namen,
        "fenster_offen": kennzahl(getattr(fenster, "open_state", None), OFFEN_ZU),
        "fenster_anzahl": fen_anz,
        "fenster_namen": fen_namen,
        "licht_an": kennzahl(getattr(lichter, "light_state", None), AN_AUS),
        "licht_anzahl": licht_anz,
        "licht_namen": licht_namen,
        "handbremse": wert(getattr(fahrzeug, "parking_brake", None)),
        "aussentemperatur": wert(getattr(fahrzeug, "outside_temperature", None), Temperature.C, 1),
        "klima_an": kennzahl(getattr(klima, "state", None), KLIMA_AN),
        "klima_stufe": kennzahl(getattr(klima, "state", None), KLIMA_ART),
        "klima_text": etext(getattr(klima, "state", None)),
        "zieltemperatur": wert(getattr(einst, "target_temperature", None), Temperature.C, 1),
        "klima_fertig_um": zeitstempel(getattr(klima, "estimated_date_reached", None)),
        "scheibenheizung": kennzahl(getattr(heizung, "heating_state", None), AN_AUS),
        "scheibe_anzahl": heiz_anz,
        "scheibe_namen": heiz_namen,
        "sitzheizung_ein": wert(getattr(einst, "seat_heating", None)),
        "klima_bei_entriegeln": wert(getattr(einst, "climatization_at_unlock", None)),
        "scheibe_dauer": wert(getattr(einst, "window_heating", None)),
        "klima_ohne_strom": wert(getattr(einst, "climatization_without_external_power", None)),
        "heizquelle": kennzahl(getattr(einst, "heater_source", None), HEIZQUELLE),
        "zone_vl": wert(getattr(einst, "front_zone_left_enabled", None)),
        "zone_vr": wert(getattr(einst, "front_zone_right_enabled", None)),
        "zone_hl": wert(getattr(einst, "rear_zone_left_enabled", None)),
        "zone_hr": wert(getattr(einst, "rear_zone_right_enabled", None)),
        # Wie lange steht der Fahrzeugzustand schon unveraendert. Aus
        # last_changed, nicht last_updated - siehe geaendert_vor().
        "zustand_seit": geaendert_vor(getattr(fahrzeug, "state", None)),
    }


def abbild_reichweite(fahrzeug) -> dict:
    from carconnectivity.units import Length, Level, Energy, Temperature
    a = antriebe(fahrzeug)
    e, v = a["elektro"], a["verbrenner"]
    batterie = getattr(e, "battery", None)
    return {
        "reichweite_km": wert(getattr(getattr(fahrzeug, "drives", None), "total_range", None),
                              Length.KM, 0),
        "anzahl_antriebe": a["anzahl"],
        "soc": wert(getattr(e, "level", None), Level.PERCENTAGE, 0),
        "reichweite_elektro_km": wert(getattr(e, "range", None), Length.KM, 0),
        "batterie_kwh": wert(getattr(batterie, "total_capacity", None), Energy.KWH, 1),
        "batterie_temp": wert(getattr(batterie, "temperature", None), Temperature.C, 1),
        "batterie_temp_min": wert(getattr(batterie, "temperature_min", None), Temperature.C, 1),
        "batterie_temp_max": wert(getattr(batterie, "temperature_max", None), Temperature.C, 1),
        "tank_prozent": wert(getattr(v, "level", None), Level.PERCENTAGE, 0),
        "reichweite_verbrenner_km": wert(getattr(v, "range", None), Length.KM, 0),
        "oelstand_prozent": wert(getattr(v, "oil_level", None), Level.PERCENTAGE, 0),
        # AdBlue fuehrt der Connector nur als Reichweite; Fuellstand und
        # Tankgroesse gibt es im Kern, werden aber nicht gefuellt (gemessen).
        "adblue_km": wert(getattr(v, "adblue_range", None), Length.KM, 0),
    }


def abbild_laden(fahrzeug) -> dict:
    from carconnectivity.units import Power, Speed, Level, Current
    laden = getattr(fahrzeug, "charging", None)
    einst = getattr(laden, "settings", None)
    stecker = getattr(laden, "connector", None)
    saeule = getattr(laden, "charging_station", None)
    return {
        "laedt": kennzahl(getattr(laden, "state", None), LADEN_AN),
        "lade_stufe": kennzahl(getattr(laden, "state", None), LADE_STUFE),
        "ladezustand_text": etext(getattr(laden, "state", None)),
        "ladeleistung_kw": wert(getattr(laden, "power", None), Power.KW, 1),
        "ladetempo_kmh": wert(getattr(laden, "rate", None), Speed.KMH, 1),
        "ladeart": etext(getattr(laden, "type", None)),
        "ladeart_zahl": kennzahl(getattr(laden, "type", None), LADESTROMART),
        "laden_fertig_um": zeitstempel(getattr(laden, "estimated_date_reached", None)),
        "ladegrenze": wert(getattr(einst, "target_level", None), Level.PERCENTAGE, 0),
        "ladestrom_a": wert(getattr(einst, "maximum_current", None), Current.A, 0),
        "stecker_entriegeln": wert(getattr(einst, "auto_unlock", None)),
        "kabel_verbunden": kennzahl(getattr(stecker, "connection_state", None), KABEL),
        "stecker_verriegelt": kennzahl(getattr(stecker, "lock_state", None), STECKER),
        "externe_stromversorgung": etext(getattr(stecker, "external_power", None)),
        "externe_kraft": kennzahl(getattr(stecker, "external_power", None), EXTERNE_KRAFT),
        # Die Ladesaeule loest der Kern selbst ueber OpenStreetMap auf, sobald
        # geladen wird und die Position bekannt ist (charging.py Z. 97-137).
        # Dafuer ist im Connector nichts noetig.
        "saeule_name": str(wert(getattr(saeule, "name", None)) or ""),
        "saeule_betreiber": str(wert(getattr(saeule, "operator_name", None)) or ""),
    }


def abbild_wartung(fahrzeug) -> dict:
    from carconnectivity.units import Length
    w = getattr(fahrzeug, "maintenance", None)
    return {
        "inspektion_tage": tage_bis(getattr(w, "inspection_due_at", None)),
        "inspektion_km": wert(getattr(w, "inspection_due_after", None), Length.KM, 0),
        "oelservice_tage": tage_bis(getattr(w, "oil_service_due_at", None)),
        "oelservice_km": wert(getattr(w, "oil_service_due_after", None), Length.KM, 0),
    }


def abbild_position(fahrzeug, cfg: dict) -> dict:
    """Standort. Ist er in den Einstellungen abgeschaltet, entsteht er gar
    nicht erst - dann steht er auch in keiner Datei, die jemand mitliest."""
    if not cfg.get("gps_ein"):
        return {"breite": None, "laenge": None, "positionsart": "",
                "positionsart_zahl": None, "strasse": "", "ort": "", "adresse": "",
                "gps_aus": 1}
    p = getattr(fahrzeug, "position", None)
    ort = getattr(p, "location", None)
    strasse = " ".join(x for x in (str(wert(getattr(ort, "road", None)) or ""),
                                   str(wert(getattr(ort, "house_number", None)) or "")) if x)
    stadt = str(wert(getattr(ort, "city", None)) or "")
    return {
        "breite": wert(getattr(p, "latitude", None), None, 6),
        "laenge": wert(getattr(p, "longitude", None), None, 6),
        "positionsart": etext(getattr(p, "position_type", None)),
        "positionsart_zahl": kennzahl(getattr(p, "position_type", None), POSITIONSART),
        "strasse": strasse,
        "ort": stadt,
        "adresse": ", ".join(x for x in (strasse, stadt) if x),
        "gps_aus": 0,
    }


def fahrzeug_abbilden(fahrzeug, cfg: dict, zyklus: int, stamm: dict) -> dict:
    """Setzt das Abbild eines Fahrzeugs zusammen.

    Jeder Abschnitt einzeln abgesichert: wirft die Bibliothek in einem
    Abschnitt, bleiben die uebrigen gueltig, und der Ausfall wird benannt
    statt verschwiegen.
    """
    ausfaelle: dict = {}
    d: dict = {}
    abschnitte = [("stamm", abbild_stamm), ("status", abbild_status),
                  ("reichweite", abbild_reichweite), ("laden", abbild_laden),
                  ("position", lambda f: abbild_position(f, cfg))]
    namen = [n for n, _ in abschnitte]
    if zyklus % cfg["takt_wartung"] == 0 or not stamm:
        abschnitte.append(("wartung", abbild_wartung))
        namen.append("wartung")
    for name, funktion in abschnitte:
        try:
            d.update(funktion(fahrzeug))
        except Exception as err:  # noqa: BLE001
            ausfaelle[name] = fehlertext(err)
            melde_gebremst(f"ab_{name}", f"Abschnitt '{name}' konnte nicht gelesen werden: "
                                         f"{ausfaelle[name]}", 900)
    if "wartung" not in namen:
        # Zwischen zwei Wartungsabrufen den letzten bekannten Stand weiterreichen.
        for k, v in stamm.items():
            if k.startswith(("inspektion", "oelservice")):
                d.setdefault(k, v)
    d["ausfaelle"] = ausfaelle
    d["ok"] = 0 if len(ausfaelle) >= 3 else 1
    return d


# ---------------------------------------------------------------------------
# Gerechnete Groessen
#
# "gerechnet" heisst: das Plugin bildet den Wert selbst. Er ist damit nicht
# besser belegt als seine Zutaten - fehlt eine, entsteht KEIN Wert und
# keine 0.
# ---------------------------------------------------------------------------
def abgeleitetes_ergaenzen(nummer: str, d: dict, cfg: dict, merker: dict,
                           fehler_folge: int, empfehlung) -> None:
    """Fuellt die gerechneten Felder und fuehrt die Ladevorgaenge nach."""
    m = merker.setdefault("fz" + str(nummer), {})

    # ---- Stoerungszaehler ----
    # Er gilt fuer den ganzen Dienst, nicht je Fahrzeug - er steht trotzdem in
    # jeder Statuszeile, weil Loxone je Fahrzeug EINEN Eingang liest. Dass es
    # derselbe Wert ist, sagt der Hilfetext.
    d["fehlfolge"] = int(fehler_folge)
    d["ladeempf"] = empfehlung

    # ---- Geofence ----
    hb = zahl(cfg.get("heim_breite"))
    hl = zahl(cfg.get("heim_laenge"))
    if hb is None or hl is None or not cfg.get("gps_ein"):
        d["zuhause"] = None          # keine Heimatposition, oder GPS ist aus
        d["entfernung_m"] = None
    else:
        e = entfernung_m(zahl(d.get("breite")), zahl(d.get("laenge")), hb, hl)
        d["entfernung_m"] = e
        d["zuhause"] = None if e is None else (1 if e <= cfg["heim_radius"] else 0)

    # ---- Standzeit in Minuten ----
    s = d.get("zustand_seit")
    d["standzeit_min"] = None if s is None else int(s // 60)

    # ---- Ladevorgaenge ----
    laedt = d.get("laedt")
    soc = zahl(d.get("soc"))
    km = zahl(d.get("kilometerstand"))
    kap = cfg["kapazitaet"]
    jetzt = int(time.time())
    vorher = m.get("laedt")
    if laedt in (0, 1):
        if vorher != 1 and laedt == 1:
            # Ladevorgang beginnt. Damit endet gleichzeitig ein Fahrabschnitt.
            if soc is not None:
                m["l_start"] = jetzt
                m["l_soc"] = soc
                m["l_km"] = km
            # Verbrauch aus dem abgeschlossenen Fahrabschnitt.
            fs = m.get("f_soc")
            fk = m.get("f_km")
            if (kap > 0 and fs is not None and fk is not None
                    and soc is not None and km is not None):
                dkm = km - fk
                dsoc = fs - soc
                # Unter 20 km ist die Zahl Rauschen: der Ladezustand wird in
                # ganzen Prozent gemeldet, ein Prozent sind bei 60 kWh schon
                # 0,6 kWh.
                if dkm >= 20 and dsoc > 0:
                    m["verbrauch"] = round(dsoc / 100.0 * kap / dkm * 100.0, 1)
                    m["verbrauch_km"] = int(dkm)
            m.pop("f_soc", None)
            m.pop("f_km", None)
        if vorher == 1 and laedt == 0:
            # Ladevorgang endet.
            start = m.get("l_start")
            ssoc = m.get("l_soc")
            if start and ssoc is not None and soc is not None:
                dauer = int(round((jetzt - int(start)) / 60.0))
                kwh = round((soc - ssoc) / 100.0 * kap, 2) if kap > 0 and soc > ssoc \
                    else None
                ladung_anhaengen({
                    "fahrzeug": nummer, "start": int(start), "ende": jetzt,
                    "dauer": dauer, "soc_start": ssoc, "soc_ende": soc,
                    "km": km, "kwh": kwh}, cfg["verlauf_tage"])
                if kwh is not None:
                    m["ladekwh"] = kwh
                _LOG.info("Fahrzeug %s: Ladevorgang beendet, %d Minuten, %s -> %s %%%s",
                          nummer, dauer, ssoc, soc,
                          "" if kwh is None else (", %.2f kWh" % kwh))
            m.pop("l_start", None)
            m.pop("l_soc", None)
            m.pop("l_km", None)
            # Ein neuer Fahrabschnitt beginnt.
            if soc is not None and km is not None:
                m["f_soc"] = soc
                m["f_km"] = km
        m["laedt"] = laedt

    d["verbrauch"] = m.get("verbrauch")
    d["verbrauch_km"] = m.get("verbrauch_km")
    d["ladekwh"] = m.get("ladekwh")


# ---------------------------------------------------------------------------
# Verlauf (Ladezustand beziehungsweise Tankfuellstand ueber den Tag)
# ---------------------------------------------------------------------------
def verlauf_anhaengen(nummer: int, stand, reichweite, km, tage: int) -> None:
    """Eine Zeile je Messpunkt: Zeit;Fuellstand;Reichweite;Kilometerstand.

    Die vierte Spalte ist neu in 0.9.8. Aeltere Dateien haben drei - der Leser
    in der Oberflaeche vertraegt beides, denn eine Datei aus der Vorwoche
    laesst sich nicht nachtraeglich ergaenzen.
    """
    if stand is None:
        return
    ORDNER_VERLAUF.mkdir(parents=True, exist_ok=True)
    datei = ORDNER_VERLAUF / f"fahrzeug{nummer}_{time.strftime('%Y%m%d')}.csv"
    marke = PDATA / f".verlauf_ts_{nummer}"
    letzte = 0
    try:
        letzte = int(marke.read_text())
    except (OSError, ValueError):
        pass
    if time.time() - letzte < 240:
        return
    try:
        with datei.open("a", encoding="utf-8") as f:
            f.write("%d;%s;%s;%s\n" % (
                int(time.time()), stand,
                reichweite if reichweite is not None else "",
                km if km is not None else ""))
        marke.write_text(str(int(time.time())))
    except OSError:
        return
    grenze = time.time() - tage * 86400
    for alt in ORDNER_VERLAUF.glob("fahrzeug*_*.csv"):
        try:
            if alt.stat().st_mtime < grenze:
                alt.unlink()
        except OSError:
            pass


def ladung_anhaengen(zeile: dict, tage: int) -> None:
    """Einen abgeschlossenen Ladevorgang protokollieren.

    Eine Zeile je Vorgang, aelter als verlauf_tage wird verworfen. Aufgeraeumt
    wird beim Schreiben, nicht per Cron: eine Datei, um die sich niemand
    kuemmert, waechst genau so lange, bis sie stoert.
    """
    ORDNER_VERLAUF.mkdir(parents=True, exist_ok=True)
    text = "%s;%d;%d;%s;%s;%s;%s;%s\n" % (
        str(zeile.get("fahrzeug") or "").replace(";", ","),
        int(zeile.get("start") or 0), int(zeile.get("ende") or 0),
        "" if zeile.get("dauer") is None else int(zeile["dauer"]),
        "" if zeile.get("soc_start") is None else zeile["soc_start"],
        "" if zeile.get("soc_ende") is None else zeile["soc_ende"],
        "" if zeile.get("km") is None else zeile["km"],
        "" if zeile.get("kwh") is None else zeile["kwh"])
    try:
        neu = not DATEI_LADUNGEN.exists()
        with DATEI_LADUNGEN.open("a", encoding="utf-8") as f:
            if neu:
                f.write("# fahrzeug;start;ende;dauer_min;soc_start;soc_ende;km;kwh\n")
            f.write(text)
    except OSError as err:
        _LOG.error("Ladeprotokoll nicht schreibbar: %s", err)
        return
    # Alte Zeilen entfernen. Massgeblich ist das ENDE des Vorgangs.
    grenze = int(time.time()) - tage * 86400
    try:
        zeilen = DATEI_LADUNGEN.read_text(encoding="utf-8").splitlines()
    except OSError:
        return
    behalten = [z for z in zeilen
                if z.startswith("#")
                or (len(z.split(";")) > 2 and ganz(z.split(";")[2], 0) >= grenze)]
    if len(behalten) < len(zeilen):
        try:
            DATEI_LADUNGEN.write_text("\n".join(behalten) + "\n", encoding="utf-8")
        except OSError:
            pass


# ---------------------------------------------------------------------------
# Drosselung
#
# WARUM ES SIE GIBT
# Bis 0.9.7 lief 'abruf' an der Steuerungssperre vorbei, die Warteschlange
# wird im Sekundentakt abgearbeitet, und ein 'abruf' brach die Wartezeit
# sofort ab. Ein flatternder Baustein in Loxone - ein Impulsgeber am falschen
# Eingang genuegt - loeste damit JEDE SEKUNDE einen vollstaendigen Abruf aus.
# Die Folge ist keine Fehlermeldung, sondern eine 24-Stunden-Sperre des
# myAudi-Kontos. Der Fehlertext dafuer stand schon im Code, der Riegel nicht.
#
# Abgewiesen wird ausdruecklich und mit Grund - nicht stillschweigend
# verworfen. Wer nicht erfaehrt, dass sein Befehl nicht ausgefuehrt wurde,
# drueckt noch einmal.
# ---------------------------------------------------------------------------
class Bremse:
    """Haelt fest, wann zuletzt was hinausging."""

    def __init__(self):
        self.letzter_abruf = 0.0
        self.letzter_strom: dict = {}      # vin -> (zeit, ampere)
        self.befehle: list = []            # Zeitstempel der letzten Stunde

    def abruf_erlaubt(self, cfg: dict):
        abstand = ganz(cfg.get("abruf_abstand"), 60)
        if abstand <= 0:
            return (True, 0)
        rest = int(abstand - (time.time() - self.letzter_abruf))
        return (rest <= 0, max(0, rest))

    def abruf_vermerken(self) -> None:
        self.letzter_abruf = time.time()

    def stunde_erlaubt(self, cfg: dict):
        grenze = ganz(cfg.get("befehle_stunde"), 30)
        if grenze <= 0:
            return (True, 0)
        jetzt = time.time()
        self.befehle = [t for t in self.befehle if jetzt - t < 3600]
        return (len(self.befehle) < grenze, len(self.befehle))

    def befehl_vermerken(self) -> None:
        self.befehle.append(time.time())

    def strom_erlaubt(self, cfg: dict, vin: str, ampere: int):
        """Der Ladestrom ist der Hebel fuer das Ueberschussladen - und genau
        deshalb der gefaehrlichste. Ein Loxone-Regler liefert alle paar
        Sekunden einen neuen Sollwert; jeder davon waere eine Anfrage an Audi.

        Zwei Riegel: derselbe Wert geht gar nicht erst hinaus, und zwischen
        zwei Aenderungen liegt ein Mindestabstand.
        """
        abstand = ganz(cfg.get("strom_abstand"), 300)
        letzte = self.letzter_strom.get(vin)
        if letzte is None:
            return (True, 0, "")
        zeit, alt = letzte
        if int(alt) == int(ampere):
            return (False, 0, "gleich")
        if abstand <= 0:
            return (True, 0, "")
        rest = int(abstand - (time.time() - zeit))
        if rest > 0:
            return (False, rest, "abstand")
        return (True, 0, "")

    def strom_vermerken(self, vin: str, ampere: int) -> None:
        self.letzter_strom[vin] = (time.time(), int(ampere))


# ---------------------------------------------------------------------------
# Schreibbefehle aus der Warteschlange
#
# Der Loxone-Endpunkt legt hier eine JSON-Datei ab, der Dienst arbeitet sie ab
# und legt die Antwort daneben. Der Endpunkt selbst spricht NIE mit Audi.
# ---------------------------------------------------------------------------
def antwort_schreiben(kennung: str, ok: int, meldung: str, zusatz=None) -> None:
    ORDNER_ANTWORTEN.mkdir(parents=True, exist_ok=True)
    d = {"ok": ok, "meldung": meldung, "ts": int(time.time())}
    if zusatz:
        d.update(zusatz)
    json_schreiben(ORDNER_ANTWORTEN / f"{kennung}.json", d)
    grenze = time.time() - 900
    for alt in ORDNER_ANTWORTEN.glob("*.json"):
        try:
            if alt.stat().st_mtime < grenze:
                alt.unlink()
        except OSError:
            pass


def fahrzeug_waehlen(fahrzeuge: list, nummer_oder_vin):
    """Nimmt entweder die laufende Nummer (1-basiert) oder die Fahrgestellnummer."""
    s = str(nummer_oder_vin or "").strip()
    for f in fahrzeuge:
        v = str(wert(getattr(f, "vin", None)) or "")
        if v and v.upper() == s.upper():
            return f
    try:
        n = int(s)
    except (TypeError, ValueError):
        n = 1
    return fahrzeuge[n - 1] if 1 <= n <= len(fahrzeuge) else None


def befehl_holen(objekt, name: str):
    """Holt einen Befehl aus einem Befehlsverzeichnis, oder None."""
    cmds = getattr(objekt, "commands", None)
    verzeichnis = getattr(cmds, "commands", None) or {}
    return verzeichnis.get(name)


# Die Einstellungen, die sich als Ja/Nein setzen lassen.
#
# Gemessen am Connector 0.3.2: fuer jede dieser Eigenschaften ist ein
# Set-Hook registriert (__on_air_conditioning_settings_change bzw.
# __on_charging_settings_change). Die vier Zonen sind eine AUDI-EIGENE
# Erweiterung - der Volkswagen-Connector desselben Geruests hat sie nicht.
SCHALTER = {
    "stecker_auto":     ("laden", "auto_unlock"),
    "klima_unlock":     ("klima", "climatization_at_unlock"),
    "scheibe_dauer":    ("klima", "window_heating"),
    "klima_ohne_strom": ("klima", "climatization_without_external_power"),
    "sitzheizung":      ("klima", "seat_heating"),
    "zone_vl":          ("klima", "front_zone_left_enabled"),
    "zone_vr":          ("klima", "front_zone_right_enabled"),
    "zone_hl":          ("klima", "rear_zone_left_enabled"),
    "zone_hr":          ("klima", "rear_zone_right_enabled"),
}

# Befehle, die auf ein Fahrzeug im oeffentlichen Raum wirken. Sie brauchen
# ZUSAETZLICH zum Steuerungshaken den Haken 'gefahr_ein'.
GEFAEHRLICH = ("verriegeln", "entriegeln", "hupe", "lichthupe")


def befehl_ausfuehren(cc, fahrzeuge: list, cfg: dict, b: dict, bremse: Bremse):
    """Rueckgabe: (ok, Meldung, Zusatzfelder). ok = 1 angenommen, 0 abgelehnt.

    Was "angenommen" heisst: die Bibliothek setzt den Befehl als HTTP-Anfrage
    an den Audi-Server ab und wirft, wenn der nicht mit 200 antwortet.
    ok = 1 bedeutet also: der Server hat den Auftrag entgegengenommen. Ob das
    Fahrzeug ihn ausfuehrt, zeigt erst der naechste Abruf - das steht auch so
    in jeder Antwort.
    """
    aktion = str(b.get("aktion") or "")
    # Trockenlauf: entweder fuer diesen einen Befehl angefordert, oder in den
    # Einstellungen dauerhaft eingeschaltet.
    probe = bool(b.get("probe")) or bool(cfg.get("probe_ein"))
    vorsatz = "PROBE - es wurde NICHTS an das Fahrzeug gesendet. " if probe else ""

    if aktion == "abruf":
        erlaubt, rest = bremse.abruf_erlaubt(cfg)
        if not erlaubt:
            return (0, f"Sofortabruf abgewiesen: der letzte liegt weniger als "
                       f"{cfg['abruf_abstand']} s zurueck, der naechste ist in {rest} s "
                       f"moeglich. Jeder Abruf weckt das Fahrzeug; zu dichte Abrufe fuehren "
                       f"bei Audi zu einer Sperre des Kontos. Der Mindestabstand steht im "
                       f"Reiter Einstellungen.", {"wartet": rest})
        if probe:
            return (1, vorsatz + "Ein Sofortabruf wuerde eingeplant.", {})
        return (1, "Sofortabruf eingeplant.", {})

    if not cfg.get("steuerung_ein"):
        return (0, "Die Steuerung ist ausgeschaltet. Reiter Einstellungen, "
                   "Haken 'Schreibende Befehle zulassen'.", {})

    if aktion in GEFAEHRLICH and not cfg.get("gefahr_ein"):
        return (0, "Dieser Befehl greift in ein Fahrzeug ein, das im oeffentlichen Raum "
                   "steht, und ist deshalb zusaetzlich gesperrt. Reiter Einstellungen, "
                   "zweiter Haken 'Eingreifende Befehle zulassen'.", {})

    # Die Stundenbremse gilt fuer alles Schreibende. Ein Abruf zaehlt nicht
    # mit: er hat seine eigene, engere Bremse.
    erlaubt, bisher = bremse.stunde_erlaubt(cfg)
    if not erlaubt:
        return (0, f"Abgewiesen: in der letzten Stunde sind bereits {bisher} schaltende "
                   f"Befehle hinausgegangen, die Obergrenze steht auf "
                   f"{cfg['befehle_stunde']}. Das ist ein Schutz vor einer Kontosperre "
                   f"bei Audi - meist steckt ein flatternder Baustein in Loxone dahinter.",
                {"bisher": bisher})

    # Der S-PIN-Test haengt am CONNECTOR, nicht am Fahrzeug (gemessen:
    # audi/connector.py Z. 426-429 haengt SpinCommand an self.commands).
    if aktion == "spin_pruefen":
        if not zugang()["spin"]:
            return (0, "Es ist keine S-PIN hinterlegt - Reiter Einstellungen, Feld S-PIN.", {})
        if probe:
            return (1, vorsatz + "Die S-PIN wuerde bei Audi geprueft.", {})
        cmd = None
        verz = getattr(getattr(cc, "connectors", None), "connectors", None) or {}
        if isinstance(verz, dict):
            for verbinder in verz.values():
                cmd = befehl_holen(verbinder, "spin")
                if cmd is not None:
                    break
        if cmd is None:
            return (0, "Der Connector bietet keine S-PIN-Pruefung an.", {})
        cmd.value = "verify"
        bremse.befehl_vermerken()
        return (1, "Die S-PIN wurde von Audi angenommen.", {})

    if not fahrzeuge:
        return (0, "Es ist noch kein Fahrzeug bekannt. Erst einen Abruf abwarten.", {})

    f = fahrzeug_waehlen(fahrzeuge, b.get("fahrzeug"))
    if f is None:
        return (0, f"Fahrzeug '{b.get('fahrzeug')}' gibt es nicht. "
                   f"Bekannt sind {len(fahrzeuge)} Fahrzeuge.", {})
    vin = str(wert(getattr(f, "vin", None)) or "")
    nachsatz = (" Der Audi-Server hat den Auftrag angenommen; ob das Fahrzeug ihn "
                "ausfuehrt, zeigt der naechste Abruf.")

    def fehlt(was: str):
        return (0, f"Dieses Fahrzeug bietet '{was}' nicht an. Entweder kann es das nicht, "
                   f"oder die Funktion ist im Audi-Konto nicht freigeschaltet.", {})

    def erledigt(text: str, zusatz=None):
        z = {"vin": vin}
        if zusatz:
            z.update(zusatz)
        if probe:
            return (1, vorsatz + text, z)
        bremse.befehl_vermerken()
        return (1, text + nachsatz, z)

    if aktion in ("klima_start", "klima_stop"):
        cmd = befehl_holen(getattr(f, "climatization", None), "start-stop")
        if cmd is None:
            return fehlt("Klimatisierung")
        if aktion == "klima_stop":
            if not probe:
                cmd.value = "stop"
            return erledigt("Klimatisierung aus angefordert.")
        temp = wert_zahl(b.get("temp"))
        if temp is None:
            return (0, "Die Zieltemperatur fehlt oder ist keine Zahl.", {})
        lo, hi = cfg["temp_min"], cfg["temp_max"]
        if temp < lo or temp > hi:
            # Abweisen, nicht zurechtbiegen: ein still gekappter Sollwert
            # fuehrt zu einem Fahrzeug, das etwas anderes tut als angezeigt.
            return (0, f"Zieltemperatur {temp} Grad liegt ausserhalb der eingestellten Grenzen "
                       f"({lo} bis {hi} Grad). Grenzen im Reiter Einstellungen anpassen.", {})
        if not probe:
            cmd.value = f"start --target-temperature {temp} --target-temperature-unit °C"
        return erledigt(f"Klimatisierung mit {temp} Grad angefordert.", {"temp": temp})

    if aktion == "zieltemperatur":
        temp = wert_zahl(b.get("temp"))
        if temp is None:
            return (0, "Die Zieltemperatur fehlt oder ist keine Zahl.", {})
        lo, hi = cfg["temp_min"], cfg["temp_max"]
        if temp < lo or temp > hi:
            return (0, f"Zieltemperatur {temp} Grad liegt ausserhalb der eingestellten Grenzen "
                       f"({lo} bis {hi} Grad).", {})
        einst = getattr(getattr(f, "climatization", None), "settings", None)
        attr = getattr(einst, "target_temperature", None)
        if attr is None:
            return fehlt("Zieltemperatur")
        if not probe:
            attr.value = float(temp)
        return erledigt(f"Zieltemperatur {temp} Grad gesetzt.", {"temp": temp})

    if aktion in ("laden_start", "laden_stop"):
        cmd = befehl_holen(getattr(f, "charging", None), "start-stop")
        if cmd is None:
            return fehlt("Laden steuern")
        if not probe:
            cmd.value = "start" if aktion == "laden_start" else "stop"
        return erledigt("Laden starten angefordert." if aktion == "laden_start"
                        else "Laden anhalten angefordert.")

    if aktion == "ladegrenze":
        p = wert_zahl(b.get("prozent"))
        if p is None:
            return (0, "Der Prozentwert fuer die Ladegrenze fehlt oder ist keine Zahl.", {})
        if p < 10 or p > 100:
            return (0, f"{p} % ist keine zulaessige Ladegrenze. Zulaessig sind 10 bis 100 %.", {})
        einst = getattr(getattr(f, "charging", None), "settings", None)
        attr = getattr(einst, "target_level", None)
        if attr is None:
            return fehlt("Ladegrenze")

        # Auf Zehnerschritte runden, bevor der Wert hinausgeht.
        #
        # Das Fahrzeug kennt nur feste Stufen. Bisher stand im Ergebnistext
        # nur der Hinweis, dass Audi rundet - geschickt wurde trotzdem der
        # krumme Wert, und myAudi antwortete darauf mit HTTP 400. In Loxone
        # kommt so ein Wert leicht zustande: ein Schieberegler liefert eben
        # 83 statt 80. Besser die eine Zahl anpassen, die das Fahrzeug
        # ohnehin annehmen wuerde, als die Anfrage scheitern lassen.
        gerundet = int(round(p / 10.0) * 10)
        gerundet = max(10, min(100, gerundet))
        if not probe:
            attr.value = float(gerundet)
        if gerundet != int(p):
            text = (f"Ladegrenze {gerundet} % gesetzt (von {int(p)} % auf die naechste "
                    f"Stufe gerundet - das Fahrzeug kennt nur Zehnerschritte).")
        else:
            text = f"Ladegrenze {gerundet} % gesetzt."
        return erledigt(text, {"prozent": gerundet, "angefordert": int(p)})

    if aktion == "ladestrom":
        a = wert_zahl(b.get("ampere"))
        if a is None:
            return (0, "Der Ampere-Wert fehlt oder ist keine Zahl.", {})
        if int(a) not in LADESTROM_STUFEN:
            return (0, f"{a} A ist keine zulaessige Stufe. Zulaessig sind: "
                       f"{', '.join(str(x) for x in LADESTROM_STUFEN)} A. "
                       f"Welche davon Ihr Fahrzeug kennt, haengt vom Modell ab: manche fuehren "
                       f"den Strom in Ampere (5/10/13/32), die uebrigen kennen nur reduziert (6) "
                       f"und maximal (16).", {})
        einst = getattr(getattr(f, "charging", None), "settings", None)
        attr = getattr(einst, "maximum_current", None)
        if attr is None:
            return fehlt("Ladestrom")
        erlaubt, rest, warum = bremse.strom_erlaubt(cfg, vin, int(a))
        if not erlaubt and warum == "gleich":
            return (1, f"Der Ladestrom steht bereits auf {int(a)} A - es wurde nichts "
                       f"gesendet. Das ist Absicht: beim Ueberschussladen liefert Loxone "
                       f"denselben Sollwert im Sekundentakt.",
                    {"ampere": int(a), "vin": vin, "gesendet": 0})
        if not erlaubt:
            return (0, f"Ladestrom abgewiesen: die letzte Aenderung liegt weniger als "
                       f"{cfg['strom_abstand']} s zurueck, die naechste ist in {rest} s "
                       f"moeglich. Ohne diese Entprellung setzt ein Ueberschussregler das "
                       f"Konto binnen einer Stunde in die Sperre.", {"wartet": rest})
        if not probe:
            attr.value = float(int(a))
            bremse.strom_vermerken(vin, int(a))
        return erledigt(f"Ladestrom {int(a)} A gesetzt.", {"ampere": int(a)})

    if aktion in ("scheibe_ein", "scheibe_aus"):
        cmd = befehl_holen(getattr(f, "window_heatings", None), "start-stop")
        if cmd is None:
            return fehlt("Scheibenheizung")
        if not probe:
            cmd.value = "start" if aktion == "scheibe_ein" else "stop"
        return erledigt("Scheibenheizung ein angefordert." if aktion == "scheibe_ein"
                        else "Scheibenheizung aus angefordert.")

    if aktion == "wecken":
        cmd = befehl_holen(f, "wake-sleep")
        if cmd is None:
            return fehlt("Wecken")
        if not probe:
            cmd.value = "wake"
        return erledigt("Weckruf gesendet.")

    # ---- Einstellungen als Ja/Nein ----
    if aktion == "einstellung":
        name = str(b.get("name") or "")
        if name not in SCHALTER:
            return (0, f"Unbekannte Einstellung '{name}'. Bekannt sind: "
                       f"{', '.join(sorted(SCHALTER))}.", {})
        w = str(b.get("wert") or "")
        if w not in ("0", "1"):
            return (0, "Der Wert muss 0 oder 1 sein.", {})
        zweig, feld = SCHALTER[name]
        if zweig == "klima":
            einst = getattr(getattr(f, "climatization", None), "settings", None)
        else:
            einst = getattr(getattr(f, "charging", None), "settings", None)
        attr = getattr(einst, feld, None)
        if attr is None:
            return fehlt(name)
        if not probe:
            attr.value = (w == "1")
        return erledigt(f"Einstellung '{name}' auf {'ein' if w == '1' else 'aus'} gesetzt.",
                        {"name": name, "wert": int(w)})

    # ---- Eingreifende Befehle ----
    if aktion in ("verriegeln", "entriegeln"):
        cmd = befehl_holen(getattr(f, "doors", None), "lock-unlock")
        if cmd is None:
            return fehlt("Ver- und Entriegeln")
        if not zugang()["spin"]:
            # Der Connector wirft sonst CommandError("S-PIN is missing...").
            # Lieber hier abweisen, mit dem Hinweis, wo die S-PIN hingehoert.
            return (0, "Ver- und Entriegeln verlangt die S-PIN des myAudi-Kontos. "
                       "Sie ist nicht hinterlegt - Reiter Einstellungen, Feld S-PIN.", {})
        if not probe:
            cmd.value = "lock" if aktion == "verriegeln" else "unlock"
        return erledigt("Verriegeln angefordert." if aktion == "verriegeln"
                        else "Entriegeln angefordert.")

    if aktion in ("hupe", "lichthupe"):
        cmd = befehl_holen(f, "honk-flash")
        if cmd is None:
            return fehlt("Hupe und Lichthupe")
        was = "Hupe und Lichthupe" if aktion == "hupe" else "Lichthupe"
        dauer = wert_zahl(b.get("dauer"))
        dauer = 10 if dauer is None else max(1, min(60, int(dauer)))
        if probe:
            return erledigt(f"{was} fuer {dauer} s wuerde ausgeloest.", {"dauer": dauer})
        art = "honk-and-flash" if aktion == "hupe" else "flash"
        try:
            cmd.value = f"{art} --duration {dauer}"
        except Exception as err:  # noqa: BLE001
            # HIER STECKT EIN FEHLER DES CONNECTORS, UND ER IST GEMESSEN.
            #
            # In audi/connector.py 0.3.2 haengt der else-Zweig am try/except
            # statt am if (Z. 3487-3490). Der else eines try laeuft genau
            # dann, wenn KEINE Ausnahme kam - also nach einem POST, der 200
            # oder 204 ergeben hat. Dort wird dann
            # CommandError("Unknown command ...") geworfen.
            #
            # Diese eine Ausnahme ist damit der BELEG fuer den Erfolg, nicht
            # fuer einen Fehlschlag: waere der POST schiefgegangen, haette der
            # Zweig darueber eine andere Meldung geworfen. Derselbe Fehler
            # steckt im Volkswagen-Connector (Z. 1920). Jede ANDERE Ausnahme
            # wird unveraendert weitergereicht.
            if type(err).__name__ == "CommandError" \
                    and str(err).startswith("Unknown command"):
                bremse.befehl_vermerken()
                return (1, f"{was} fuer {dauer} s ausgeloest. (Der Audi-Connector 0.3.2 "
                           f"meldet danach faelschlich einen Fehler - das ist ein bekannter "
                           f"Programmfehler der Bibliothek und kein Fehlschlag.)",
                        {"vin": vin, "dauer": dauer})
            raise
        bremse.befehl_vermerken()
        return (1, f"{was} fuer {dauer} s ausgeloest." + nachsatz,
                {"vin": vin, "dauer": dauer})

    return (0, f"Unbekannte Aktion '{aktion}'.", {})


def wert_zahl(v):
    """Zahl aus einem Befehlsparameter. Keine Zahl ergibt None, nicht 0."""
    if v is None or v == "":
        return None
    try:
        f = float(str(v).replace(",", "."))
    except (TypeError, ValueError):
        return None
    return int(f) if f == int(f) else round(f, 1)


def warteschlange(cc, fahrzeuge: list, cfg: dict, bremse: Bremse) -> bool:
    """Arbeitet alle vorliegenden Befehle ab. True, wenn ein Sofortabruf
    angefordert wurde."""
    ORDNER_BEFEHLE.mkdir(parents=True, exist_ok=True)
    sofort = False
    for datei in sorted(ORDNER_BEFEHLE.glob("*.json")):
        b = json_lesen(datei)
        kennung = datei.stem
        try:
            datei.unlink()
        except OSError:
            pass
        if not b:
            antwort_schreiben(kennung, 0, "Befehlsdatei war leer oder unlesbar.")
            continue
        try:
            ok, meldung, zusatz = befehl_ausfuehren(cc, fahrzeuge, cfg, b, bremse)
        except Exception as err:  # noqa: BLE001 - jeder Fehler gehoert gemeldet, nicht verschluckt
            ok, meldung, zusatz = 0, fehlertext(err), {}
        antwort_schreiben(kennung, ok, meldung, zusatz)
        _LOG.info("Befehl %s (%s): ok=%s %s", kennung, b.get("aktion"), ok, meldung)
        if b.get("aktion") == "abruf" and ok and not b.get("probe") \
                and not cfg.get("probe_ein"):
            sofort = True
    return sofort


# ---------------------------------------------------------------------------
# Abbild schreiben
# ---------------------------------------------------------------------------
MQTT_FELDER = (
    "soc", "tank_prozent", "reichweite_km", "reichweite_elektro_km",
    "reichweite_verbrenner_km", "kilometerstand", "verriegelt",
    "tueren_offen", "tueren_anzahl", "fenster_offen", "fenster_anzahl",
    "licht_an", "handbremse", "zustand", "erreichbar", "aktiv",
    "klima_an", "klima_stufe", "zieltemperatur", "aussentemperatur",
    "scheibenheizung", "sitzheizung_ein", "klima_bei_entriegeln",
    "klima_fertig_um", "laedt", "lade_stufe", "ladeleistung_kw",
    "ladetempo_kmh", "ladegrenze", "ladestrom_a", "kabel_verbunden",
    "stecker_verriegelt", "stecker_entriegeln", "externe_kraft",
    "ladeart_zahl", "laden_fertig_um", "batterie_temp", "adblue_km",
    "breite", "laenge", "positionsart_zahl", "inspektion_tage",
    "inspektion_km", "oelservice_tage", "oelservice_km",
    # gerechnet
    "zuhause", "entfernung_m", "standzeit_min", "verbrauch", "ladekwh",
    "ladeempf", "fehlfolge", "ok",
)

# Klartexte gehen ebenfalls hinaus. In MQTT stoert eine Zeichenkette nicht,
# und in der App will man lesen koennen, WELCHE Tuer offen steht.
MQTT_TEXTE = ("zustand_text", "klima_text", "ladezustand_text", "tueren_namen",
              "fenster_namen", "adresse", "saeule_name", "modell", "vin")


def abbild_schreiben(stand: dict, cfg: dict, ok: int, fehler: str = "",
                     code: int = CODE_OK) -> dict:
    """Schreibt den Zwischenspeicher.

    Bei einem fehlgeschlagenen Abruf bleiben die zuletzt gueltigen Werte
    stehen, und der Zeitstempel wird NICHT aufgefrischt. Beides mit Absicht:
    sonst meldete der Endpunkt ploetzlich FAHRZEUG_UNBEKANNT, obwohl nur eine
    Anfrage schiefging, und ALTER bliebe klein - woran aber die
    Ausfallerkennung in Loxone haengt.
    """
    fahrzeuge = stand.get("fahrzeuge") or {}
    lox = {
        "ok": ok,
        "fehler": fehler,
        "fehler_code": int(code),
        "letzter_versuch": int(time.time()),
        "anzahl_fahrzeuge": len(fahrzeuge),
        "fahrzeuge": fahrzeuge,
    }
    if stand.get("ts"):
        lox["ts"] = int(stand["ts"])
    json_schreiben(DATEI_LOXONE, lox)
    json_schreiben(DATEI_CACHE, {"ts": int(time.time()), "ok": ok,
                                 "fehler": fehler, "fahrzeuge": fahrzeuge})

    praefix = str(cfg.get("mqtt_topic") or "audi").strip("/") or "audi"
    if not ok:
        # Bei einer Stoerung nur das ok-Thema senden. Die alten Messwerte
        # erneut zu veroeffentlichen liesse sie frisch aussehen.
        if cfg.get("mqtt_ein"):
            mqtt_senden({"ok": 0, "grund": int(code)}, praefix)
        return lox

    for nummer, f in fahrzeuge.items():
        try:
            verlauf_anhaengen(int(nummer),
                              f.get("soc") if f.get("soc") is not None else f.get("tank_prozent"),
                              f.get("reichweite_km"), f.get("kilometerstand"),
                              cfg["verlauf_tage"])
        except (TypeError, ValueError):
            pass

    if cfg.get("mqtt_ein"):
        paare = {"ok": ok, "grund": 0, "fahrzeuge": len(fahrzeuge)}
        for nummer, f in fahrzeuge.items():
            for feld in MQTT_FELDER:
                paare[f"fahrzeug{nummer}/{feld}"] = f.get(feld)
            for feld in MQTT_TEXTE:
                w = f.get(feld)
                if w not in (None, ""):
                    paare[f"fahrzeug{nummer}/{feld}"] = w
        mqtt_senden(paare, praefix)

    return lox


def zustand_schreiben(**felder) -> None:
    z = json_lesen(DATEI_ZUSTAND)
    z.update(felder)
    z["ts"] = int(time.time())
    json_schreiben(DATEI_ZUSTAND, z)


def merker_lesen() -> dict:
    """Der Merker haelt fest, was ZWISCHEN zwei Abrufen gilt: der letzte
    Ladezustand, der Anfang eines Fahrabschnitts, der letzte Verbrauch.

    Er ueberlebt einen Neustart des Dienstes mit Absicht: sonst ginge nach
    jedem Update der angefangene Ladevorgang verloren, und der Verbrauch
    entstuende erst nach der uebernaechsten Fahrt wieder.
    """
    return json_lesen(DATEI_MERKER)


def merker_schreiben(m: dict) -> None:
    json_schreiben(DATEI_MERKER, m)


# ---------------------------------------------------------------------------
# Die Bibliothek vorbereiten
# ---------------------------------------------------------------------------
def ntp_entschaerfen() -> None:
    """CarConnectivity fragt beim Anlegen einen Zeitserver.

    In der Bibliothek faengt ntp_time_delta() nur NTPException ab. Kann der
    LoxBerry pool.ntp.org nicht aufloesen - kein DNS nach aussen, gesperrter
    UDP-Port 123 -, kommt eine socket.gaierror durch und der Konstruktor
    stirbt, bevor irgendetwas passiert ist. Die Abfrage dient nur einer
    Warnung ueber eine abweichende Systemzeit; sie darf den Dienst nicht
    verhindern.

    Deshalb wird sie hier gekapselt. Geprueft an carconnectivity 0.11.10.
    """
    try:
        import carconnectivity.util as util
        import carconnectivity.carconnectivity as kern
    except ImportError:
        return
    original = getattr(util, "ntp_time_delta", None)
    if original is None or getattr(original, "_lb_gekapselt", False):
        return

    def sicher(server: str = "pool.ntp.org"):
        try:
            return original(server)
        except OSError as err:
            melde_gebremst("ntp", f"Zeitserver nicht erreichbar ({err}) - die Pruefung der "
                                  f"Systemzeit entfaellt. Das ist kein Fehler des Plugins.",
                           86400)
            return None

    sicher._lb_gekapselt = True
    util.ntp_time_delta = sicher
    if hasattr(kern, "ntp_time_delta"):
        kern.ntp_time_delta = sicher


def bibliothek_config(z: dict, cfg: dict) -> dict:
    """Die Konfiguration, die CarConnectivity erwartet.

    Zugangsdaten stehen nur hier im Arbeitsspeicher, nie in einer Datei, die
    die Oberflaeche anzeigt.
    """
    connector = {
        "username": z["email"],
        "password": z["passwort"],
        "interval": max(TAKT_MIN, int(cfg["intervall"])),
    }
    if z["spin"]:
        connector["spin"] = z["spin"]
    return {
        "carConnectivity": {
            "log_level": "WARNING",
            "connectors": [{"type": "audi", "config": connector}],
        }
    }


# ---------------------------------------------------------------------------
# Fremde Themen: Vorklimatisierung und Ladeempfehlung
# ---------------------------------------------------------------------------
def horcher_themen(cfg: dict) -> set:
    """Welche FREMDEN Themen braucht das Plugin gerade?

    Dieselbe Liste fuehrt die Oberflaeche in au_horcher_themen(). Sie steht
    absichtlich zweimal da: die Selbstpruefung vergleicht die Erwartung der
    Oberflaeche mit dem, was der Dienst wirklich abonniert hat. Ein Abo, das
    der Dienst nach einer Aenderung nicht nachgezogen hat, ist sonst
    unsichtbar - die Vorklimatisierung loest dann einfach nie aus, und das
    sieht aus wie "der Assistent sendet nichts".
    """
    t = set()
    if cfg.get("abfahrt_ein"):
        p = str(cfg.get("abfahrt_praefix") or "abfahrt").strip("/")
        if p:
            t.add(p + "/ABFAHRT_IN")
            t.add(p + "/OK")
    if cfg.get("ladeempf_ein"):
        th = str(cfg.get("ladeempf_thema") or "").strip()
        if th:
            t.add(th)
    return t


def ladeempfehlung(horcher: Horcher, cfg: dict):
    """1, 0 oder None - aus einem fremden MQTT-Thema.

    Gedacht fuer die Spotpreis-Plugins (aWATTar, Tibber, Octopus) oder einen
    PV-Ueberschusswert. Das Plugin entscheidet NICHT, ob geladen wird; es
    reicht die Empfehlung als Zahl an Loxone weiter. Dort gehoert die
    Entscheidung hin, weil dort auch alles andere steht.

    Kein Wert, wenn das Thema schweigt oder veraltet ist - keine 0. Eine 0
    hiesse "jetzt nicht laden", und das waere eine Aussage, die niemand
    getroffen hat.
    """
    if not cfg.get("ladeempf_ein"):
        return None
    thema = str(cfg.get("ladeempf_thema") or "").strip()
    if not thema:
        return None
    roh, alter = horcher.wert(thema, ganz(cfg.get("ladeempf_alter"), 900))
    if roh is None:
        melde_gebremst("ladeempf_alt",
                       "Ladeempfehlung: das Thema %s liefert nichts Frisches (Alter %s s, "
                       "zulaessig %s s). Es entsteht kein Wert."
                       % (thema, alter, cfg.get("ladeempf_alter")), 3600)
        return None
    z = zahl(roh)
    if z is None:
        melde_gebremst("ladeempf_zahl",
                       "Ladeempfehlung: unter %s steht '%s' - das ist keine Zahl."
                       % (thema, roh), 3600)
        return None
    grenze = zahl(cfg.get("ladeempf_grenze"))
    if grenze is None:
        return None
    return 1 if (z <= grenze if cfg.get("ladeempf_unter") else z >= grenze) else 0


def vorklimatisierung(horcher: Horcher, cfg: dict, merker: dict):
    """Startet die Klimatisierung, wenn der Abfahrtsassistent es nahelegt.

    Das Plugin Abfahrtsassistent veroeffentlicht unter dem eingestellten
    Praefix - ab Werk "abfahrt" - die Minuten bis zur Abfahrt als ABFAHRT_IN
    und einen Zustand OK.

    Rueckgabe: ein Befehlsverzeichnis oder None. Ausgeloest wird hoechstens
    einmal je Abfahrt; der Merker haelt fest, dass es schon geschehen ist,
    und wird erst zurueckgesetzt, wenn ABFAHRT_IN wieder ueber dem Vorlauf
    liegt - also bei der naechsten Abfahrt.
    """
    if not cfg.get("abfahrt_ein"):
        return None
    p = str(cfg.get("abfahrt_praefix") or "abfahrt").strip("/")
    if not p:
        return None
    grenze = ganz(cfg.get("abfahrt_alter"), 300)
    ok_roh, _ = horcher.wert(p + "/OK", grenze)
    ai_roh, _ = horcher.wert(p + "/ABFAHRT_IN", grenze)
    m = merker.setdefault("abfahrt", {})
    if ai_roh is None:
        melde_gebremst("abfahrt_still",
                       "Vorklimatisierung: unter %s/ABFAHRT_IN kommt nichts Frisches an. "
                       "Laeuft das Plugin Abfahrtsassistent, und stimmt das Praefix?"
                       % p, 3600)
        return None
    if ok_roh is not None and zahl(ok_roh) == 0:
        return None
    ai = zahl(ai_roh)
    if ai is None:
        return None
    vorlauf = ganz(cfg.get("abfahrt_vorlauf"), 20)
    if ai > vorlauf or ai < 0:
        # Noch zu frueh (oder die Abfahrt liegt zurueck). Sobald der Wert
        # wieder ueber dem Vorlauf liegt, ist die naechste Abfahrt eine neue.
        if ai > vorlauf:
            m.pop("ausgeloest", None)
        return None
    if m.get("ausgeloest"):
        return None
    m["ausgeloest"] = int(time.time())
    nr = str(max(1, ganz(cfg.get("abfahrt_fahrzeug"), 1)))
    _LOG.info("Vorklimatisierung: Abfahrt in %s Minuten (Vorlauf %s), Fahrzeug %s, "
              "Zieltemperatur %d Grad.", ai, vorlauf, nr, cfg["abfahrt_temp"])
    return {"aktion": "klima_start", "fahrzeug": nr, "temp": cfg["abfahrt_temp"],
            "anlass": "abfahrt"}


# ---------------------------------------------------------------------------
# Dienst
# ---------------------------------------------------------------------------
def signal_behandeln(*_):
    global _LAUF
    _LAUF = False
    _LOG.info("Beendigungssignal erhalten - Dienst haelt an.")


def dienst(einmal: bool = False) -> int:
    ntp_entschaerfen()
    from carconnectivity.carconnectivity import CarConnectivity

    cfg = config()
    z = zugang()
    if not z["email"] or not z["passwort"]:
        _LOG.error("Zugangsdaten fehlen. Reiter Einstellungen der Plugin-Oberflaeche oeffnen.")
        zustand_schreiben(ok=0, fehler="Zugangsdaten fehlen.", fehler_code=CODE_ZUGANG_FEHLT)
        return 1

    _LOG.info("Dienst startet (Takt %s s, Steuerung %s, eingreifende Befehle %s).",
              cfg["intervall"], "ein" if cfg.get("steuerung_ein") else "aus",
              "ein" if cfg.get("gefahr_ein") else "aus")
    if cfg.get("probe_ein"):
        _LOG.warning("PROBELAUF ist eingeschaltet: schaltende Befehle werden vollstaendig "
                     "geprueft, aber NICHT an das Fahrzeug gesendet.")

    try:
        # tokenstore_file: die Bibliothek legt hier ihre Anmeldemarken ab und
        # spart sich damit bei jedem Start eine neue Anmeldung. Die Datei
        # bekommt deshalb die Rechte 0600.
        cc = CarConnectivity(config=bibliothek_config(z, cfg),
                             tokenstore_file=str(DATEI_TOKEN),
                             cache_file=str(DATEI_ZWISCHEN))
    except Exception as err:  # noqa: BLE001
        meldung = fehlertext(err)
        code = fehler_code(err)
        _LOG.error("Die Bibliothek liess sich nicht einrichten: %s", meldung)
        zustand_schreiben(ok=0, fehler=meldung, fehler_code=code)
        melden("einrichtung", 3,
               "Audi Connect: die Bibliothek liess sich nicht einrichten. " + meldung)
        return 1
    rechte_sichern()

    stand: dict = {"ts": 0, "fahrzeuge": {}}
    zyklus = 0
    fehler_folge = 0
    stammdaten: dict = {}
    merker = merker_lesen()
    bremse = Bremse()
    horcher = Horcher()

    try:
        while _LAUF:
            cfg = config()  # Aenderungen aus der Oberflaeche ohne Neustart uebernehmen
            horcher.sicherstellen(horcher_themen(cfg))
            ok = 0
            fehler = ""
            code = CODE_OK
            fahrzeuge: dict = {}
            liste: list = []
            try:
                cc.fetch_all()
                # Auch der TAKTMAESSIGE Abruf zaehlt fuer die Bremse: sonst
                # koennte gleich nach einem regulaeren Abruf ein Sofortabruf
                # hinterhergehen, und die Wirkung waere ein doppelter Takt.
                bremse.abruf_vermerken()
                garage = cc.get_garage()
                liste = list(garage.list_vehicles()) if garage is not None else []
                liste.sort(key=lambda f: str(wert(getattr(f, "vin", None)) or ""))
                empfehlung = ladeempfehlung(horcher, cfg)
                for i, f in enumerate(liste, start=1):
                    vin = str(wert(getattr(f, "vin", None)) or str(i))
                    stammdaten.setdefault(vin, {})
                    abbild = fahrzeug_abbilden(f, cfg, zyklus, stammdaten[vin])
                    for k, v in abbild.items():
                        if k.startswith(("inspektion", "oelservice")) and v is not None:
                            stammdaten[vin][k] = v
                    abgeleitetes_ergaenzen(str(i), abbild, cfg, merker,
                                           fehler_folge, empfehlung)
                    fahrzeuge[str(i)] = abbild
                ok = 1 if fahrzeuge and any(x.get("ok") for x in fahrzeuge.values()) else 0
                if not liste:
                    fehler = "Das Konto fuehrt kein Fahrzeug."
                    code = CODE_KEIN_FAHRZEUG
                fehler_folge = 0 if ok else fehler_folge + 1
            except Exception as err:  # noqa: BLE001
                fehler = fehlertext(err)
                code = fehler_code(err)
                fehler_folge += 1
                melde_gebremst("abruf", f"Abruf fehlgeschlagen: {fehler}", 900)
                # Erst nach drei Fehlversuchen melden: eine einzelne Stoerung
                # bei Audi ist kein Anlass, jemanden zu behelligen.
                if fehler_folge == 3:
                    melden("abruf_%d" % code,
                           3 if code in (CODE_ANMELDUNG, CODE_GEDROSSELT) else 4,
                           "Audi Connect: seit drei Versuchen kein Abruf moeglich. " + fehler)

            if ok and fahrzeuge:
                stand = {"ts": int(time.time()), "fahrzeuge": fahrzeuge}
            abbild_schreiben(stand, cfg, ok, fehler, code)
            merker_schreiben(merker)
            zustand_schreiben(ok=ok, fehler=fehler, fehler_code=code, zyklus=zyklus,
                              fehler_folge=fehler_folge, pid=os.getpid(),
                              intervall=cfg["intervall"],
                              anzahl_fahrzeuge=len(stand["fahrzeuge"]),
                              befehle_stunde=len(bremse.befehle),
                              probe=1 if cfg.get("probe_ein") else 0,
                              horcher=sorted(horcher.themen),
                              horcher_verbunden=1 if horcher.verbunden else 0,
                              horcher_fehler=horcher.fehler)
            rechte_sichern()
            zyklus += 1
            if einmal:
                return 0 if ok else 1

            rest = cfg["intervall"]
            if fehler_folge >= 3:
                rest = min(3600, cfg["intervall"] * min(8, fehler_folge))
                melde_gebremst("bremse",
                               f"{fehler_folge} Fehlversuche - naechster Abruf erst in {rest} s.",
                               1800)
            while rest > 0 and _LAUF:
                try:
                    if warteschlange(cc, liste, cfg, bremse):
                        break  # Sofortabruf angefordert
                    # Die Vorklimatisierung wird wie ein Befehl von aussen
                    # behandelt: derselbe Weg, dieselben Wachen, dieselbe
                    # Protokollzeile. Ein zweiter Weg an der Warteschlange
                    # vorbei waere eine zweite Stelle, an der dieselben Regeln
                    # gelten muessten - und die erste, die man vergisst.
                    auftrag = vorklimatisierung(horcher, cfg, merker)
                    if auftrag is not None:
                        e_ok, e_meldung, _ = befehl_ausfuehren(cc, liste, cfg,
                                                               auftrag, bremse)
                        _LOG.info("Vorklimatisierung: ok=%s %s", e_ok, e_meldung)
                        merker_schreiben(merker)
                except Exception as err:  # noqa: BLE001
                    _LOG.error("Warteschlange: %s", fehlertext(err))
                time.sleep(1)
                rest -= 1
    finally:
        try:
            horcher.schliessen()
        except Exception:  # noqa: BLE001
            pass
        try:
            merker_schreiben(merker)
        except Exception:  # noqa: BLE001
            pass
        try:
            cc.shutdown()
        except Exception:  # noqa: BLE001
            pass
        rechte_sichern()
    _LOG.info("Dienst beendet.")
    return 0


def rechte_sichern() -> None:
    """Die Bibliothek legt Marken- und Zwischenspeicherdatei selbst an.

    In der Markendatei stehen Anmeldemarken - sie gehoert niemandem sonst
    lesbar. Die Bibliothek setzt die Rechte nicht, also wird es hier nach
    jedem Schreibvorgang nachgeholt.
    """
    for p in (DATEI_TOKEN, DATEI_ZWISCHEN):
        try:
            if p.exists():
                os.chmod(p, 0o600)
        except OSError:
            pass


# ---------------------------------------------------------------------------
# Selbsttest - beantwortet ohne Netz und ohne Loxone, ob die Einrichtung traegt
# ---------------------------------------------------------------------------
def selbsttest() -> int:
    zeilen = []
    fehler = 0

    v = sys.version_info
    if v >= (3, 9):
        zeilen.append(f"[OK]   Python {v.major}.{v.minor}.{v.micro} "
                      f"(carconnectivity verlangt 3.9 oder neuer)")
    else:
        fehler += 1
        zeilen.append(f"[FEHL] Python {v.major}.{v.minor}.{v.micro} ist zu alt - "
                      f"carconnectivity verlangt 3.9 oder neuer")

    venv = SELF / "venv" / "bin" / "python3"
    zeilen.append(f"[{'OK]  ' if venv.exists() else 'FEHL]'} Virtuelle Umgebung: {venv}")
    if not venv.exists():
        fehler += 1

    import importlib.metadata as md
    for paket, modul in (("carconnectivity", "carconnectivity.carconnectivity"),
                         ("carconnectivity-connector-audi",
                          "carconnectivity_connectors.audi.connector")):
        try:
            __import__(modul)
            try:
                fassung = md.version(paket)
            except Exception:  # noqa: BLE001
                fassung = "unbekannt"
            zeilen.append(f"[OK]   Bibliothek {paket} geladen, Fassung {fassung}")
        except Exception as err:  # noqa: BLE001
            fehler += 1
            zeilen.append(f"[FEHL] Bibliothek {paket} laesst sich nicht laden: {err}")

    c = config()
    themen = horcher_themen(c)
    if themen:
        ok, grund = Horcher().moeglich()
        if ok:
            zeilen.append("[OK]   paho-mqtt vorhanden - der Horcher kann fremde Themen lesen: "
                          + ", ".join(sorted(themen)))
        else:
            fehler += 1
            zeilen.append("[FEHL] " + grund)
    else:
        zeilen.append("[INFO] Kein fremdes Thema abonniert (Vorklimatisierung und "
                      "Ladeempfehlung sind aus) - paho-mqtt wird nicht gebraucht")

    for name, pfad in (("Konfiguration", PCONFIG), ("Daten", PDATA), ("Log", PLOG)):
        schreibbar = os.access(pfad, os.W_OK) if pfad.exists() else False
        zeilen.append(f"[{'OK]  ' if schreibbar else 'FEHL]'} Ordner {name} beschreibbar: {pfad}")
        if not schreibbar:
            fehler += 1

    z = zugang()
    # Ein Pruefknopf darf die FORM eines Geheimnisses beurteilen, nie seinen Wert zeigen.
    if z["email"] and "@" in z["email"]:
        zeilen.append(f"[OK]   Audi-Benutzername hinterlegt ({z['email'][:2]}...@..., "
                      f"{len(z['email'])} Zeichen)")
    elif z["email"]:
        fehler += 1
        zeilen.append("[FEHL] Der Benutzername sieht nicht wie eine E-Mail-Adresse aus")
    else:
        fehler += 1
        zeilen.append("[FEHL] Kein Benutzername hinterlegt")
    if z["passwort"]:
        zeilen.append(f"[OK]   Passwort hinterlegt ({len(z['passwort'])} Zeichen, "
                      f"Inhalt wird nicht angezeigt)")
    else:
        fehler += 1
        zeilen.append("[FEHL] Kein Passwort hinterlegt")
    if z["spin"]:
        if z["spin"].isdigit() and len(z["spin"]) == 4:
            zeilen.append("[OK]   S-PIN hinterlegt (vier Ziffern)")
        else:
            fehler += 1
            zeilen.append(f"[FEHL] Die S-PIN hat {len(z['spin'])} Zeichen - erwartet werden "
                          f"genau vier Ziffern")
    elif c.get("gefahr_ein"):
        fehler += 1
        zeilen.append("[FEHL] Eingreifende Befehle sind zugelassen, aber es ist keine S-PIN "
                      "hinterlegt - Ver- und Entriegeln wird der Connector abweisen")
    else:
        zeilen.append("[INFO] Keine S-PIN hinterlegt (nur fuer Ver- und Entriegeln noetig, "
                      "das eigens freigegeben werden muss)")

    for name, p, soll in (("Zugangsdatei", DATEI_ZUGANG, True),
                          ("Markendatei der Bibliothek", DATEI_TOKEN, False)):
        try:
            rechte = p.stat().st_mode & 0o777
            passt = (rechte & 0o077) == 0
            zeilen.append(f"[{'OK]  ' if passt else 'FEHL]'} Rechte {name}: {oct(rechte)} "
                          f"(erwartet 0o600)")
            if not passt:
                fehler += 1
        except OSError:
            if soll:
                fehler += 1
                zeilen.append(f"[FEHL] {name} fehlt: {p}")
            else:
                zeilen.append(f"[INFO] {name} noch nicht angelegt (entsteht beim ersten Abruf)")

    zeilen.append(f"[INFO] Takt {c['intervall']} s (Untergrenze der Bibliothek: {TAKT_MIN} s), "
                  f"Wartung alle {c['takt_wartung']} Takte")
    zeilen.append(f"[INFO] Schreibende Befehle: "
                  f"{'zugelassen' if c.get('steuerung_ein') else 'gesperrt'}, "
                  f"Zieltemperatur erlaubt von {c['temp_min']} bis {c['temp_max']} Grad")
    zeilen.append(f"[INFO] Eingreifende Befehle (Ver-/Entriegeln, Hupe): "
                  f"{'ZUGELASSEN' if c.get('gefahr_ein') else 'gesperrt'}")
    if c.get("probe_ein"):
        zeilen.append("[INFO] PROBELAUF eingeschaltet - schaltende Befehle werden geprueft, "
                      "aber nicht gesendet")
    zeilen.append(f"[INFO] Drosselung: Sofortabruf fruehestens alle {c['abruf_abstand']} s, "
                  f"hoechstens {c['befehle_stunde']} schaltende Befehle je Stunde, "
                  f"Ladestrom fruehestens alle {c['strom_abstand']} s")
    if c["kapazitaet"] > 0:
        zeilen.append("[OK]   Batteriekapazitaet %d kWh hinterlegt - Verbrauch und geladene "
                      "Menge werden gerechnet" % c["kapazitaet"])
    else:
        zeilen.append("[INFO] Keine Batteriekapazitaet hinterlegt. Verbrauch und geladene "
                      "Menge entstehen deshalb nicht - geraten wird nichts")
    if zahl(c.get("heim_breite")) is not None and zahl(c.get("heim_laenge")) is not None:
        zeilen.append("[OK]   Heimatposition hinterlegt, Radius %d m - ZUHAUSE wird gerechnet"
                      % c["heim_radius"])
    else:
        zeilen.append("[INFO] Keine Heimatposition hinterlegt - ZUHAUSE bleibt leer")
    if not c.get("gps_ein"):
        zeilen.append("[INFO] Standort ist abgeschaltet - Breite, Laenge und Anschrift "
                      "entstehen gar nicht erst")

    m = mqtt_zustand()
    if not m["gefunden"]:
        zeilen.append("[FEHL] Im general.json des LoxBerry ist kein MQTT-Abschnitt zu finden")
        fehler += 1
    elif m["autostart"]:
        zeilen.append(f"[OK]   MQTT-Gateway auf Autostart, Broker {m['broker']}:{m['brokerport']}, "
                      f"UDP-Eingang {m['udpport']}")
    else:
        zeilen.append("[FEHL] Das MQTT-Gateway ist nicht auf Autostart gestellt "
                      "(System -> MQTT Gateway). Ohne das kommt am Miniserver nichts an.")
        fehler += 1

    zu = json_lesen(DATEI_ZUSTAND)
    if themen:
        zeilen.append("[%s Horcher am Broker: %s, abonniert: %s"
                      % ("OK]  " if zu.get("horcher_verbunden") else "INFO]",
                         "verbunden" if zu.get("horcher_verbunden") else "nicht verbunden",
                         ", ".join(zu.get("horcher") or []) or "nichts"))
        if zu.get("horcher_fehler"):
            zeilen.append("[FEHL] Horcher: %s" % zu["horcher_fehler"])
            fehler += 1

    lox = json_lesen(DATEI_LOXONE)
    if lox:
        alter = int(time.time()) - ganz(lox.get("ts"), 0)
        zeilen.append(f"[INFO] Letzter erfolgreicher Abruf vor {alter} s, ok={lox.get('ok')}, "
                      f"Fehlerklasse {lox.get('fehler_code')}, "
                      f"{lox.get('anzahl_fahrzeuge')} Fahrzeug(e)")
        for nummer, f in (lox.get("fahrzeuge") or {}).items():
            aus = f.get("ausfaelle") or {}
            zeilen.append(f"[INFO] Fahrzeug {nummer}: {f.get('modell') or 'ohne Modellangabe'}, "
                          f"{len(aus)} ausgefallene Abschnitte"
                          + (": " + ", ".join(sorted(aus)) if aus else ""))
    else:
        zeilen.append("[INFO] Es hat noch kein Abruf stattgefunden")

    zeilen.append("")
    zeilen.append("Am Audi-Connector 0.3.2 GEMESSEN und deshalb dauerhaft leer:")
    zeilen.append("  Kennzeichen, Baujahr, Hersteller, Softwarestand, Handbremse,")
    zeilen.append("  Batteriekapazitaet und Oelstand. Der Connector fuellt diese sieben")
    zeilen.append("  Attribute an keiner Stelle - das ist kein Fehler dieses Plugins und")
    zeilen.append("  auch keiner Ihres Fahrzeugs.")
    zeilen.append("")
    zeilen.append("Nicht geprueft, weil dafuer ein Audi-Konto und ein Fahrzeug noetig sind:")
    zeilen.append("  - ob die Anmeldung an der Audi-Schnittstelle gelingt")
    zeilen.append("  - ob dieses Fahrzeug die abgefragten Werte ueberhaupt liefert")
    zeilen.append("  - ob die schreibenden Befehle am Fahrzeug die erwartete Wirkung haben")
    print("\n".join(zeilen))
    return 1 if fehler else 0


def main() -> int:
    log_einrichten()
    if "--selbsttest" in sys.argv:
        return selbsttest()
    signal.signal(signal.SIGTERM, signal_behandeln)
    signal.signal(signal.SIGINT, signal_behandeln)
    try:
        return dienst(einmal="--einmal" in sys.argv)
    except KeyboardInterrupt:
        return 0
    except Exception as err:  # noqa: BLE001
        _LOG.error("Dienst abgebrochen: %s", fehlertext(err))
        zustand_schreiben(ok=0, fehler=fehlertext(err), fehler_code=fehler_code(err))
        return 1


if __name__ == "__main__":
    sys.exit(main())
