# LoxBerry-Plugin: Audi Connect

Bindet **Audi-Fahrzeuge** über das myAudi-Konto an Loxone an: Ladezustand,
Tankfüllstand, Reichweite, Kilometerstand, Verriegelung, Türen, Fenster, Licht,
Handbremse, Fahrzeugzustand, Klimatisierung, Scheibenheizung, Ladewerte,
Standort sowie Inspektions- und Ölservice-Fristen. Auf Wunsch lassen sich
Klimatisierung, Ladevorgang, Ladegrenze, Ladestrom und Scheibenheizung
schalten.

Es arbeitet mit allen vernetzten Audi: **e-tron, Hybride und Verbrenner**. Beim
Verbrenner bleiben die elektrischen Werte leer, beim Plug-in-Hybrid führt das
Plugin beide Antriebe.

> **Fassung 0.9.0 — ungeprüft, mit einem zweiten Vorbehalt.** Das Plugin wurde
> ohne myAudi-Konto und ohne Fahrzeug gebaut. Ob die Anmeldung gelingt, ob ein
> bestimmtes Fahrzeug alle abgefragten Werte liefert und ob die schreibenden
> Befehle die erwartete Wirkung haben, ist **nicht** geprüft. Alles übrige ist
> es — und zwar gegen **echte Objekte der Bibliothek**. Der zweite Vorbehalt
> steht im nächsten Abschnitt. Schreibende Befehle sind ab Werk gesperrt.

## Zwei Bibliotheken, zwei Herkünfte

| Paket | Herkunft | Fassung | erste Fassung |
|---|---|---|---|
| `carconnectivity` | Till Steinbach | 0.11.10 | seit Jahren gepflegt |
| `carconnectivity-connector-audi` | **Achim Fischer** ([acfischer42](https://github.com/acfischer42/CarConnectivity-connector-audi)), auf Steinbachs Gerüst aufbauend | **0.3.2** (25.06.2026) | 28.09.2025, acht Veröffentlichungen |

Zum Vergleich: der Volkswagen-Connector desselben Gerüsts steht bei 0.10.6.

**Technisch ist der Audi-Teil baugleich.** Nachgeprüft, nicht vermutet: er
registriert dieselben sieben Befehlsklassen, macht dieselben elf Attribute
änderbar und hat dieselbe Untergrenze von 180 Sekunden wie der
Volkswagen-Connector. Der einzige Unterschied in der Konfiguration ist, dass
ihm `force_enable_access` fehlt — deshalb gibt es hier den Haken *Türzustand
erzwingen* nicht.

Er ist nur **jünger und weniger erprobt**. Deshalb sind in `postinstall.sh`
**beide** Fassungen festgenagelt: ein stillschweigendes Update könnte hier mehr
verändern als anderswo. Schlägt die feste Fassung fehl, wird die neueste
genommen — und das ausdrücklich gemeldet.

## Zwei Grenzen

* **Europa.** Der Connector spricht mit dem europäischen Dienst
  (`emea.bff.cariad.digital`).
* **Zwei-Faktor-Bestätigung.** Einzelne Konten verlangen sie beim Anmelden.
  Das lässt sich nicht automatisieren; wer betroffen ist, meldet sich einmal
  im Browser auf demselben Gerät an.

## Voraussetzungen

* **Python 3.9 oder neuer.** Das erfüllt jeder LoxBerry, den es heute gibt:
  Debian 12 (Bookworm) liefert 3.11, Debian 13 (Trixie) liefert 3.13.
* **Internetverbindung bei der Installation.** Beide Pakete werden von PyPI
  geholt (festgenagelt auf 0.11.10 und 0.3.2).
* **`python3-venv`.** Systemweites `pip3 install` scheitert auf Debian 12/13 an
  PEP 668 (`externally-managed-environment`); deshalb eine eigene venv unter
  `bin/plugins/audiconnect/venv`.
* MQTT-Gateway eingeschaltet, wenn die Werte per MQTT kommen sollen. Es ist
  seit LoxBerry 3 Bestandteil des Systems und wird unter *System → MQTT
  Gateway* aktiviert, nicht nachinstalliert.
* **Erreichbarer Namensdienst.** Die Bibliothek fragt beim Start einen
  Zeitserver (`pool.ntp.org`), um vor einer falsch gestellten Systemuhr zu
  warnen. Sie fängt dabei nur NTP-eigene Fehler ab — ein DNS-Fehler brächte
  den Konstruktor sonst zum Absturz. Das Plugin kapselt diesen Aufruf und
  vermerkt den Ausfall im Protokoll, statt daran zu sterben.

## Abholtakt: mindestens 180 Sekunden

Untergrenze der Bibliothek, keine Vorsicht dieses Plugins: der Connector wirft
darunter beim Anlegen einen `ValueError`. Das Plugin weist kleinere Werte
deshalb schon in der Oberfläche ab. Fünf Minuten sind ein guter Anfang.

## Aufbau

    bin/audi.py               Abrufdienst (Python, eigene venv)
    bin/dienst.sh             Start, Stopp, Wächter
    cron/cron.01min           minütlicher Wächter
    webfrontend/htmlauth/     Bedienoberfläche (fünf Reiter)
    webfrontend/html/         Endpunkt für den Miniserver + gemeinsame Bibliothek

Drei Aufgaben, drei Dateien: Die Oberfläche bedient, der Dienst ruft ab, der
Endpunkt bedient den Miniserver. Weder Oberfläche noch Endpunkt sprechen je
selbst mit Audi — sie lesen den Zwischenspeicher und legen Befehle in einer
Warteschlange ab, die der Dienst im Sekundentakt abarbeitet.

## Zugangsdaten

Die Zugangsdaten des **myAudi-Kontos** liegen in
`config/plugins/audiconnect/zugang.json` mit den Rechten 0600, nicht in der
Konfiguration, die die Oberfläche anzeigt, und nie in der Loxone-Projektdatei.

Nach der ersten Anmeldung legt die Bibliothek Anmeldemarken in
`data/plugins/audiconnect/token.json` ab (ebenfalls 0600, vom Dienst nach jedem
Schreibvorgang nachgesetzt). Nach einem Passwortwechsel sind sie wertlos —
dafür gibt es den Knopf *Anmeldung neu erzwingen*.

Ein S-PIN-Feld gibt es, es wird aber **nicht gebraucht**: Ver- und Entriegeln
sowie Hupe und Lichthupe bietet dieses Plugin bewusst nicht an.

## Endpunkte für Loxone

Alle Aufrufe brauchen das Token aus dem Reiter *Einbindung in Loxone*.
Statt der laufenden Nummer darf überall auch die Fahrgestellnummer stehen
(`fahrzeug=WAU…`).

| Aufruf | Zweck |
|---|---|
| `?token=T&aktion=status&fahrzeug=N` | `AUDI;OK=..;SOC=..;TANK=..;REICHW=..;KM=..;VERR=..;TUEREN=..;FENSTER=..;LICHT=..;HANDBR=..;KLIMA=..;ZIELTEMP=..;AUSSEN=..;SCHEIBE=..;ZUSTAND=..;ERREICH=..;ALTER=..` |
| `?token=T&aktion=laden&fahrzeug=N` | `LADEN;OK=..;SOC=..;LAEDT=..;LADEKW=..;TEMPO=..;LADEGR=..;LADESTROM=..;KABEL=..;STECKER=..;REICHWBAT=..;FERTIGMIN=..;ALTER=..` |
| `?token=T&aktion=wartung&fahrzeug=N` | `WARTUNG;OK=..;INSPTAGE=..;INSPKM=..;OELTAGE=..;OELKM=..;KM=..;ALTER=..` |
| `?token=T&aktion=position&fahrzeug=N` | `POSITION;OK=..;BREITE=..;LAENGE=..;ALTER=..` plus Anschrift in einer zweiten Zeile |
| `?token=T&aktion=fahrzeuge` | Liste der erkannten Fahrzeuge |
| `?token=T&aktion=roh` | vollständiges Abbild als JSON |
| `?token=T&aktion=klima_start&temp=21` | Klimatisierung starten |
| `?token=T&aktion=klima_stop` | Klimatisierung anhalten |
| `?token=T&aktion=zieltemperatur&temp=21` | Zieltemperatur setzen |
| `?token=T&aktion=laden_start` / `laden_stop` | Ladevorgang starten/anhalten |
| `?token=T&aktion=ladegrenze&prozent=80` | Ladegrenze setzen (10–100) |
| `?token=T&aktion=ladestrom&ampere=16` | Ladestrom setzen (5, 6, 10, 13, 16 oder 32) |
| `?token=T&aktion=scheibe_ein` / `scheibe_aus` | Scheibenheizung |
| `?token=T&aktion=wecken` | Fahrzeug aus dem Ruhezustand holen |
| `?token=T&aktion=abruf` | sofort abrufen statt auf den Takt zu warten |

`ZUSTAND` ist eine Stufe: `0` offline, `1` geparkt, `2` Zündung an, `3` fährt.

**Ein Strich als Wert** heißt: dieser Wert liegt nicht vor. Es wird bewusst
keine 0 gesendet — eine 0 wäre eine stille Falschaussage. Loxone behält dann
den letzten gültigen Wert; deshalb gehören `ALTER` und `OK` immer mit
ausgewertet.

Schaltende Aufrufe antworten mit `SET;OK=…`: `1` angenommen, `0` abgelehnt (mit
Grund), `2` eingereiht, aber innerhalb der Wartezeit ohne Antwort — also
Ergebnis unbekannt.

**Was `OK=1` nicht heißt.** Der Audi-Server hat den Auftrag mit HTTP 200
entgegengenommen. Ob das Fahrzeug ihn ausgeführt hat, zeigt erst der nächste
Abruf.

## Einheiten

Alle Werte werden in feste Einheiten umgerechnet, bevor sie den Endpunkt
verlassen: Kilometer, Grad Celsius, Kilowatt, km/h, Prozent, Ampere. Die
Bibliothek liefert je nach Kontoeinstellung auch Meilen und Fahrenheit — wer
das nicht umrechnet, sendet irgendwann Meilen an einen Baustein, der Kilometer
erwartet, und niemand sieht es, weil die Zahl plausibel bleibt.

## Was das Plugin nicht kann

* **Ver- und Entriegeln, Hupe und Lichthupe.** Der Connector böte es an, es
  verlangt die S-PIN. Bewusst weggelassen: ohne Fahrzeug lässt es sich nicht
  verantwortungsvoll erproben.
* **Warnleuchten.** Die Bibliothek führt dafür kein Feld.
* **Türzustand erzwingen.** Den Schalter `force_enable_access`, den der
  Volkswagen-Connector für Fahrzeuge ohne gemeldete `ACCESS`-Fähigkeit
  anbietet, gibt es im Audi-Connector nicht.

## Datenschutz

Es sind keine persönlichen Daten im Plugin enthalten. Zugangsdaten und alle
Einstellungen liegen ausschließlich in der lokalen Konfiguration. Verbindungen
gibt es nur zum Audi-Dienst, zu einem Zeitserver und, bei der Installation, zu
PyPI.

## Lizenz

MIT — siehe [LICENSE](LICENSE). Die Anbindung nutzt
[carconnectivity](https://github.com/tillsteinbach/CarConnectivity) von Till
Steinbach und den
[Audi-Connector](https://github.com/acfischer42/CarConnectivity-connector-audi)
von Achim Fischer (beide MIT). Das ist keine amtliche Audi-Schnittstelle: Audi
kann sie ohne Ankündigung ändern, womit dieses Plugin unbrauchbar würde. Das
Projekt ist weder mit der AUDI AG verbunden noch von dort unterstützt.
