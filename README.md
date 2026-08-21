# LoxBerry-Plugin: Audi Connect

Bindet **Audi-Fahrzeuge** über das myAudi-Konto an Loxone an: Ladezustand,
Tankfüllstand, Reichweite (elektrisch und Verbrenner getrennt), Kilometerstand,
Verriegelung, Türen und Fenster — auch **welche** offen stehen —, Licht,
Fahrzeugzustand, Klimatisierung samt Zonen und Klartext, Scheibenheizung,
Ladewerte, Batterietemperatur, Standort mit Geofence sowie Inspektions- und
Ölservice-Fristen. Auf Wunsch lassen sich Klimatisierung, Ladevorgang,
Ladegrenze, Ladestrom, Scheibenheizung und neun Fahrzeugeinstellungen schalten
— und, eigens freizugeben, Ver- und Entriegeln, Hupe und Lichthupe.

Es arbeitet mit allen vernetzten Audi: **e-tron, Hybride und Verbrenner**. Beim
Verbrenner bleiben die elektrischen Werte leer, beim Plug-in-Hybrid führt das
Plugin beide Antriebe getrennt.

> **Ungeprüft, mit einem zweiten Vorbehalt.** Das Plugin wurde ohne
> myAudi-Konto und ohne Fahrzeug gebaut. Ob die Anmeldung gelingt, ob ein
> bestimmtes Fahrzeug alle abgefragten Werte liefert und ob die schreibenden
> Befehle die erwartete Wirkung haben, ist **nicht** geprüft. Alles übrige ist
> es — und zwar gegen den **Quelltext der festgenagelten Bibliotheksfassung**.
> Der zweite Vorbehalt steht weiter unten. Schreibende Befehle sind ab Werk
> gesperrt, eingreifende noch einmal gesondert.

## Was 0.9.8 ändert

Diese Fassung ist die größte seit dem Anfang. Sie bringt neue Funktionen, sie
beseitigt zwölf Befunde aus einer Zeile-für-Zeile-Durchsicht, und sie stützt
sich erstmals auf eine **Messung am Quelltext des Audi-Connectors** statt auf
Annahmen darüber, was er liefert.

### Sieben Werte bleiben dauerhaft leer — und das steht jetzt überall dabei

Gemessen am Quelltext des Tags `v0.3.2`, also an genau der Fassung, die
`postinstall.sh` festnagelt (der Stand von `main` ist byteweise derselbe):
**der Audi-Connector füllt sieben Attribute an keiner Stelle.**

| Feld | Attribut im Kernmodell |
|---|---|
| Kennzeichen | `license_plate` |
| Baujahr | `model_year` |
| Hersteller | `manufacturer` |
| Softwarestand | `software.version` |
| Handbremse | `parking_brake` |
| Batteriekapazität | `battery.total_capacity` |
| Ölstand | `CombustionDrive.oil_level` |

Keiner dieser Namen kommt im Connector überhaupt vor — geprüft über
`connector.py`, `vehicle.py`, `climatization.py`, `charging.py` und
`capability.py`. Das Kernmodell kennt die Felder, der Audi-Teil bedient sie
nicht.

Für `HANDBR` heißt das: dieser virtuelle Eingang hat seit der ersten Fassung
**nie** einen Wert bekommen. Er ist nicht entfernt worden — eine spätere
Connector-Fassung kann ihn füllen —, aber die Feldtabellen führen jetzt eine
Spalte *Herkunft*, und dort steht bei diesen Feldern **„bleibt leer"**. Das
gilt auch in der erzeugten Loxone-Vorlage und in der Fahrzeugtabelle der
Oberfläche. Die Batteriekapazität lässt sich von Hand hinterlegen; sie wird
für Verbrauch und geladene Menge gebraucht.

### 26 Werte kommen neu heraus

Der Dienst hatte 55 Felder je Fahrzeug im Abbild, 29 gingen über MQTT hinaus,
und 14 verließen das Plugin an **keiner** Stelle. Das ist behoben. Neu an den
Endpunkten und über MQTT:

* **Status** — `TUERANZ`, `FENSTERANZ` (wie viele offen), `KLIMAART`
  (heizen/kühlen/lüften statt nur „läuft"), `KLIMAFERTIG`, `SITZHEIZ`,
  `KLIMAUNLOCK`, `AKTIV`, `STANDZEIT`, `FZOK`, `AUSFALL`, `FEHLFOLGE`
* **Laden** — `LADESTUFE` (unterscheidet aus/lädt/bereit/Erhaltung/entlädt/
  Fehler, die `LAEDT` alle auf 0 abbildet), `LADEART` (AC/DC), `EXTSTROM`,
  `STECKERAUTO`, `BATTTEMP`
* **Wartung** — `ADBLUE`
* **Standort** — `POSART` (geparkt/fährt), `ZUHAUSE`, `ENTF`
* **Neuer Abruf `text`** — Klartexte für einen virtuellen **Texteingang**:
  welchen Zustand das Fahrzeug meldet, ob die Klimatisierung heizt oder kühlt,
  **welche** Tür offen steht, die Anschrift, der Name der Ladesäule
* **Neuer Abruf `ladungen`** — das Ladeprotokoll als Textliste

### Der Fehlergrund wurde geraten — und lag fast immer falsch

Der Endpunkt bestimmte die Fehlerklasse `GRUND`, indem er den Fehlertext nach
`429`, `too many`, `timeout` und `unauthorized` durchsuchte. Im Text steht aber
die **deutsche** Meldung, die `bin/audi.py` erzeugt. Von den vierzehn Meldungen,
die der Dienst schreibt, trafen genau zwei — und die nur zufällig, weil sie das
Wort „Zugangsdaten" enthalten. Ausgerechnet die Unterscheidung, wegen der es
die Zahl gibt — *Konto für 24 Stunden gesperrt* gegen *Audi ist gestört, warten
genügt* —, war unerreichbar. Dazu war `strpos($klein,'5') === 0` nicht
„HTTP 5xx", sondern „der Text beginnt mit der Ziffer 5".

Die Klasse entsteht jetzt an der **Ausnahme selbst**, im Dienst, und wandert
als Zahl in den Zwischenspeicher. Der Endpunkt liest sie, statt zu raten.

### Drei Bremsen gegen die Kontosperre

`abruf` lief an der Steuerungssperre vorbei, die Warteschlange wird im
Sekundentakt abgearbeitet, und ein `abruf` brach die Wartezeit sofort ab. Ein
flatternder Baustein in Loxone konnte damit **jede Sekunde** einen vollständigen
Abruf auslösen. Die Folge ist keine Fehlermeldung, sondern eine
24-Stunden-Sperre des myAudi-Kontos — der Fehlertext dafür stand längst im
Code, der Riegel nicht.

Neu und einstellbar: Mindestabstand für Sofortabrufe (Vorgabe 60 s),
Obergrenze für schaltende Befehle je Stunde (Vorgabe 30) und eine Entprellung
für Änderungen des Ladestroms (Vorgabe 300 s). Die letzte ist für das
Überschussladen entscheidend. Abgewiesen wird **mit Grund**, nicht
stillschweigend.

### Zwei Token statt einem

Das Token steht in jeder Adresse eines virtuellen Eingangs und damit in jeder
Loxone-Projektdatei, die weitergegeben wird. Mit genau diesem Token ließ sich
bisher auch die Klimatisierung starten. Schaltende Aufrufe verlangen jetzt ein
eigenes **Schalttoken**; lesende nehmen beide an. Dazu wahlweise eine
Beschränkung des Endpunkts auf die Adressen, die LoxBerry als Miniserver kennt.

### Neue Befehle

* **Ver- und Entriegeln** (verlangt die S-PIN), **Hupe** und **Lichthupe** —
  hinter einem zweiten Haken, ab Werk aus.
* **`einstellung`** setzt neun Optionen im Fahrzeug: Stecker nach dem Laden
  entriegeln, Klimatisierung beim Entriegeln beenden, Scheibenheizung bei jeder
  Klimatisierung, Klimatisieren ohne Netzanschluss, Sitzheizung und die **vier
  Klimazonen** — letztere eine Audi-eigene Erweiterung, die der
  Volkswagen-Connector nicht hat.
* **`spin_pruefen`** lässt Audi die hinterlegte S-PIN bestätigen, ohne etwas am
  Fahrzeug auszulösen.
* **Probelauf**: jeder schaltende Befehl vertraegt `&probe=1`, und in den
  Einstellungen lässt er sich dauerhaft einschalten. Dann wird der ganze Weg
  gegangen — Freigaben, Grenzen, Drosselung, ob das Fahrzeug die Funktion
  überhaupt anbietet — und **nichts** gesendet. Gerade weil an dieser Linie
  nichts am Fahrzeug erprobt ist, ist das der richtige erste Schritt.

> **Zur Hupe gehört ein Befund über die Bibliothek.** In
> `audi/connector.py` 0.3.2 hängt der `else`-Zweig am `try/except` statt am
> `if` (Z. 3487–3490). Der `else` eines `try` läuft genau dann, wenn **keine**
> Ausnahme kam — also nach einem POST, der 200 oder 204 ergeben hat. Dort wird
> dann `CommandError("Unknown command …")` geworfen. Diese eine Ausnahme ist
> damit der **Beleg für den Erfolg**, nicht für einen Fehlschlag; das Plugin
> wertet sie entsprechend und sagt es in der Antwort. Jede andere Ausnahme
> wird unverändert weitergereicht. Derselbe Fehler steckt im
> Volkswagen-Connector (Z. 1920).
>
> Ebenfalls gemessen: `wake-sleep` mit `sleep` wirft im Connector
> ausdrücklich „Sleep command not supported by vehicle". Das Plugin bietet
> deshalb nur `wake` an.

### Gerechnete Größen und ein Ladeprotokoll

Aus vorhandenen Werten — ohne eine einzige zusätzliche Anfrage an Audi:

* **`ZUHAUSE` und `ENTF`** aus einer hinterlegten Heimatposition mit Radius.
  Ein Breitengrad ist in Loxone kaum zu gebrauchen, ein `ZUHAUSE=1` sofort.
* **`VERBRAUCH`** in kWh/100 km aus dem letzten abgeschlossenen Fahrabschnitt
  und **`LADEKWH`** aus dem letzten Ladevorgang — beides braucht die von Hand
  hinterlegte Batteriekapazität. Fahrabschnitte unter 20 km werden verworfen:
  der Ladezustand kommt in ganzen Prozent, und ein Prozent sind bei 60 kWh
  schon 0,6 kWh.
* **`STANDZEIT`** aus `last_changed` des Fahrzeugzustands — nicht aus
  `last_updated`, das bei jedem Abruf mitwandert.
* **`FEHLFOLGE`**: wie viele Abrufe hintereinander schiefgingen.
* Ein **Ladeprotokoll** (Beginn, Ende, Dauer, Ladezustand davor und danach,
  Kilometerstand, Menge) im neuen Reiter **Ladevorgänge**.

### Automatik: Vorklimatisierung und Ladeempfehlung

Der Dienst hört auf Wunsch fremde MQTT-Themen mit:

* **Vorklimatisierung am Abfahrtsassistenten.** Läuft das Plugin
  *Abfahrtsassistent* auf demselben LoxBerry, startet Audi Connect die
  Klimatisierung selbst — so viele Minuten vor der Abfahrt, wie eingestellt.
  Ausgelöst wird höchstens einmal je Abfahrt, und der Auftrag geht denselben
  Weg durch die Warteschlange wie jeder andere Befehl, mit denselben Wachen.
* **Ladeempfehlung** aus einem beliebigen Thema — Börsenstrompreis aus einem
  der Spotpreis-Plugins, PV-Überschuss in Watt — mit Schwellwert und Richtung.
  Ergebnis ist `LADEEMPF` als 1 oder 0. **Das Plugin entscheidet nicht, ob
  geladen wird**; das gehört nach Loxone.

Beides ist ab Werk aus und braucht `paho-mqtt`, das `postinstall.sh` zusätzlich
holt. Schlägt das fehl, ist es kein Grund abzubrechen — das Plugin ist ohne
dieses Modul voll brauchbar, nur diese beiden Funktionen bleiben wirkungslos,
und die Selbstprüfung sagt es.

### Fertige Importdateien statt Abtippen

* Der Knopf *Vorlage* gibt es jetzt **je Abruf und je Fahrzeug** — vorher nur
  für den Status und nur für Fahrzeug 1, obwohl die Adressen aller Fahrzeuge
  in einer Tabelle daneben standen.
* Neu ist eine Vorlage für den **virtuellen Ausgang** mit allen Befehlen. Bis
  0.9.7 mussten sie einzeln abgetippt werden, jede Zeile mit einem
  24-stelligen Token darin. Eingreifende Befehle kommen nur hinein, wenn sie
  freigegeben sind — sonst erzeugte die Vorlage einen Ausgang, der jedes Mal
  HTTP 403 bekäme, und der Anwender suchte den Fehler bei sich.
* Der **Abfragezyklus** in der Vorlage folgt jetzt dem eingestellten Takt.
  Vorher stand dort fest 300: bei einem Takt von 900 s fragte Loxone dreimal
  dieselben Werte ab, bei 180 s hinkte es hinterher.

### Neun weitere Befunde aus der Durchsicht

* **Ohne JavaScript war die Seite leer.** Die Reiterinhalte stehen auf
  `display:none`; die Klasse `sm-active` wurde erst vom Skript am Dateiende
  gesetzt. Sie steht jetzt serverseitig, wie es der Hausstandard verlangt.
* **Die Reiterliste stand dreimal** — Positivliste, Leiste, Bereiche. Jetzt
  entsteht sie aus **einem** Feld.
* **Der Reiter *Test* reihte Befehle ein, ohne zu prüfen, ob der Dienst
  läuft.** Der Endpunkt hatte diesen Riegel längst; die Oberfläche blockierte
  bis zu 30 Sekunden und meldete dann „Ergebnis unbekannt" — die Datei blieb
  liegen und wurde beim nächsten Start ausgeführt.
* **`dienst.sh` hatte die alte, zu weiche Prozessprüfung behalten.**
  `au_lib.php` erklärt seit 0.9.7 ausführlich, warum ein `grep` über
  `/proc/<pid>/cmdline` nicht genügt — und `dienst.sh`, das den minütlichen
  Wächter trägt, tat weiterhin genau das. Ein toter Dienst wurde dann **nie**
  neu gestartet, während die Oberfläche korrekt „gestoppt" zeigte.
* **„Es wurde nichts gespeichert." stimmte nicht.** Die Zugangsdaten wurden
  geschrieben, bevor feststand, ob die Zahlenfelder in Ordnung sind. Jetzt
  wird erst geprüft und dann geschrieben. Eine leer abgeschickte E-Mail löscht
  das Konto nicht mehr; dafür gibt es einen eigenen Knopf.
* **`mb_strtolower` stand ungeschützt** in derselben Funktion, in der
  `mb_substr` ausdrücklich mit `function_exists()` abgesichert war. Ohne die
  Erweiterung `mbstring` starb damit **jeder** Endpunktaufruf, bevor die
  abgesicherte Zeile erreicht war.
* **`ALTER` fehlte in zwei Feldlisten**, `GRUND` und `FEHLERTEXT` in allen —
  sie gingen an den Miniserver hinaus und kamen in keiner Tabelle, keiner
  Sprachdatei und keiner Vorlage vor. Es gibt jetzt **eine** Feldliste, aus
  der Antwortzeile, Tabellen und Vorlagen gemeinsam entstehen.
* **`zieltemperatur` fehlte im Reiter Test**, `scheibe_aus`, `wecken` und
  `zieltemperatur` in der Befehlstabelle. Auch die Befehle kommen jetzt aus
  einer einzigen Liste.
* **Tote Einträge** `standheizung_start` / `standheizung_stop` sind entfernt —
  es gab sie weder im Endpunkt noch im Dienst noch im Test.

Dazu: ein **Formulartoken** gegen fremde Absender, die Logdatei wird
**blockweise vom Ende** gelesen statt ganz eingelesen, der Verlauf ist über
mehrere Tage einsehbar und als CSV herunterzuladen (bis 0.9.7 wurden die
Messpunkte bis zu 90 Tage aufbewahrt und nur der heutige gezeigt), und
Störungen landen auf Wunsch im **Benachrichtigungsbereich** des LoxBerry.

## Zwei Bibliotheken, zwei Herkünfte

| Paket | Herkunft | Fassung | erste Fassung |
|---|---|---|---|
| `carconnectivity` | Till Steinbach | 0.11.10 | seit Jahren gepflegt |
| `carconnectivity-connector-audi` | **Achim Fischer** ([acfischer42](https://github.com/acfischer42/CarConnectivity-connector-audi)), auf Steinbachs Gerüst aufbauend | **0.3.2** (25.06.2026) | 28.09.2025, acht Veröffentlichungen |
| `paho-mqtt` | Eclipse | neueste | nur für den Mithörer, ab Werk ungenutzt |

Zum Vergleich: der Volkswagen-Connector desselben Gerüsts steht bei 0.10.6.

**Technisch ist der Audi-Teil weitgehend baugleich**, mit zwei gemessenen
Unterschieden: ihm fehlt `force_enable_access` (deshalb gibt es hier den Haken
*Türzustand erzwingen* nicht), und er hat mit den **vier Klimazonen** eine
Erweiterung, die der Volkswagen-Connector nicht kennt.

Er ist nur **jünger und weniger erprobt**. Deshalb sind in `postinstall.sh`
**beide** Fassungen festgenagelt: ein stillschweigendes Update könnte hier mehr
verändern als anderswo. Schlägt die feste Fassung fehl, wird die neueste
genommen — und das ausdrücklich gemeldet.

## Zwei Grenzen

* **Europa.** Der Connector spricht mit dem europäischen Dienst
  (`emea.bff.cariad.digital`).
* **Zwei-Faktor-Bestätigung.** Einzelne Konten verlangen sie beim Anmelden.
  Das lässt sich nicht automatisieren; wer betroffen ist, meldet sich einmal
  im Browser auf diesem Gerät an.

## Voraussetzungen

* **Python 3.9 oder neuer.** Das erfüllt jeder LoxBerry, den es heute gibt:
  Debian 12 (Bookworm) liefert 3.11, Debian 13 (Trixie) liefert 3.13.
* **Internetverbindung bei der Installation.** Beide Pakete werden von PyPI
  geholt (festgenagelt auf 0.11.10 und 0.3.2), dazu `paho-mqtt`.
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
    bin/au_notify.php         Meldung in den LoxBerry-Benachrichtigungsbereich
    bin/dienst.sh             Start, Stopp, Wächter
    cron/cron.01min           minütlicher Wächter
    webfrontend/htmlauth/     Bedienoberfläche (sechs Reiter)
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

Die **S-PIN** wird ausschließlich für Ver- und Entriegeln gebraucht. Ohne sie
weist der Connector diese beiden Befehle ab; das Plugin sagt das vorher, statt
den Anwender in die Fehlermeldung des Anbieters laufen zu lassen.

## Endpunkte für Loxone

Lesende Aufrufe nehmen **beide** Token an, schaltende nur das Schalttoken.
Statt der laufenden Nummer darf überall auch die Fahrgestellnummer stehen
(`fahrzeug=WAU…`). Jeder schaltende Aufruf verträgt `&probe=1`.

| Aufruf | Zweck |
|---|---|
| `?token=T&aktion=status&fahrzeug=N` | Hauptwerte, 29 Felder |
| `?token=T&aktion=laden&fahrzeug=N` | Ladewerte, 20 Felder |
| `?token=T&aktion=wartung&fahrzeug=N` | Inspektion, Ölservice, AdBlue |
| `?token=T&aktion=position&fahrzeug=N` | Standort, Geofence, Anschrift in einer zweiten Zeile |
| `?token=T&aktion=text&fahrzeug=N` | Klartexte für einen virtuellen Texteingang |
| `?token=T&aktion=ladungen` | protokollierte Ladevorgänge |
| `?token=T&aktion=fahrzeuge` | Liste der erkannten Fahrzeuge |
| `?token=T&aktion=roh` | vollständiges Abbild als JSON |
| `?token=S&aktion=klima_start&temp=21` / `klima_stop` | Klimatisierung |
| `?token=S&aktion=zieltemperatur&temp=21` | Zieltemperatur setzen |
| `?token=S&aktion=laden_start` / `laden_stop` | Ladevorgang |
| `?token=S&aktion=ladegrenze&prozent=80` | Ladegrenze (10–100, wird auf Zehnerschritte gerundet) |
| `?token=S&aktion=ladestrom&ampere=16` | Ladestrom (5, 6, 10, 13, 16 oder 32) |
| `?token=S&aktion=scheibe_ein` / `scheibe_aus` | Scheibenheizung |
| `?token=S&aktion=wecken` | Fahrzeug aus dem Ruhezustand holen |
| `?token=S&aktion=einstellung&name=…&wert=0\|1` | neun Fahrzeugeinstellungen |
| `?token=S&aktion=spin_pruefen` | S-PIN von Audi bestätigen lassen |
| `?token=S&aktion=abruf` | sofort abrufen statt auf den Takt zu warten |
| `?token=S&aktion=verriegeln` / `entriegeln` | **zweiter Haken**, verlangt die S-PIN |
| `?token=S&aktion=hupe` / `lichthupe` `[&dauer=10]` | **zweiter Haken** |

`ZUSTAND` ist eine Stufe: `0` offline, `1` geparkt, `2` Zündung an, `3` fährt.
`GRUND` nennt die Fehlerklasse: `0` in Ordnung, `1` nie gelaufen, `2` Anmeldung
abgelehnt, `3` Konto gedrosselt, `4` Audi nicht erreichbar, `5` Störung bei
Audi, `6` kein Fahrzeug im Konto, `7` Zugangsdaten fehlen, `8` Einrichtung
fehlerhaft, `9` unbekannt.

**Ein Strich als Wert** heißt: dieser Wert liegt nicht vor. Es wird bewusst
keine 0 gesendet — eine 0 wäre eine stille Falschaussage. Loxone behält dann
den letzten gültigen Wert; deshalb gehören `ALTER`, `OK` und `GRUND` immer mit
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

* **Sieben Werte des Kernmodells** — siehe oben. Das ist keine Entscheidung
  dieses Plugins, sondern eine gemessene Lücke des Audi-Connectors.
* **Warnleuchten und Fehlerspeicher.** Der Connector fragt
  `vehicleHealthWarnings` zwar ab, überführt es aber in **kein** Attribut; es
  ist nur roh über `rawAPI` erreichbar, und ein modelliertes Feld gibt es
  nicht. (Bis 0.9.7 versprach der Kopf des Endpunkts hier Warnleuchten,
  während die README einen Absatz später schrieb, es gebe kein Feld dafür —
  ein Widerspruch in der eigenen Dokumentation, der jetzt aufgelöst ist.)
* **Ladeprofile und Abfahrtszeiten im Fahrzeug** (`chargingProfiles`,
  `departureTimers`). Ebenfalls abgefragt, aber nicht modelliert; es gibt weder
  Setter noch Befehl. Die Vorklimatisierung dieses Plugins läuft deshalb über
  den Abfahrtsassistenten und die Klimatisierung, nicht über die Zeitschaltuhr
  des Fahrzeugs.
* **Reifendruck.** Im Kernmodell nicht vorhanden.
* **Fahrten und Ladesitzungen aus der Bibliothek.** Weder Kern noch Connector
  führen sie; das Ladeprotokoll dieses Plugins entsteht aus beobachteten
  Zustandswechseln.
* **Schlafen schicken.** `wake-sleep` mit `sleep` wirft im Connector
  ausdrücklich einen Fehler.
* **Türzustand erzwingen.** Den Schalter `force_enable_access`, den der
  Volkswagen-Connector anbietet, gibt es im Audi-Connector nicht.

## Datenschutz

Es sind keine persönlichen Daten im Plugin enthalten. Zugangsdaten und alle
Einstellungen liegen ausschließlich in der lokalen Konfiguration. Verbindungen
gibt es nur zum Audi-Dienst, zu einem Zeitserver, bei der Installation zu PyPI
und — sobald geladen wird und die Position bekannt ist — zu OpenStreetMap, das
der Bibliothekskern für Anschrift und Ladesäulenname selbst befragt. Der
Standortabruf lässt sich vollständig abschalten; dann entsteht er gar nicht
erst.

## Lizenz

MIT — siehe [LICENSE](LICENSE). Die Anbindung nutzt
[carconnectivity](https://github.com/tillsteinbach/CarConnectivity) von Till
Steinbach und den
[Audi-Connector](https://github.com/acfischer42/CarConnectivity-connector-audi)
von Achim Fischer (beide MIT). Das ist keine amtliche Audi-Schnittstelle: Audi
kann sie ohne Ankündigung ändern, womit dieses Plugin unbrauchbar würde. Das
Projekt ist weder mit der AUDI AG verbunden noch von dort unterstützt.
