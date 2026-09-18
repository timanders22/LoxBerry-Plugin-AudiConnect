# LoxBerry-Plugin: Audi Connect

Version 0.9.19 · LoxBerry ab 3.0 · PHP 7.4 und 8.4

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

## Neu in 0.9.19

- **Während einer Aktualisierung startet der Dienst nicht mehr.** Der
  LoxBerry-Installer legt die Cron-Datei des Plugins rund eine Minute *vor*
  `postinstall.sh` neu an; dazwischen sind `config/`, `data/` und `bin/`
  dieses Plugins gelöscht. Was in dieser Lücke anläuft, arbeitet gegen eine
  halb eingerichtete Installation. Für dieses Plugin ist die Lücke in
  WSL/Ubuntu nachgestellt und **jeder** Startweg einzeln gemessen worden:

  | Weg | ohne Marke gemessen |
  |---|---|
  | Minutentakt (`cron.01min` → `dienst.sh waechter`) | startet nichts — der Sollmerker lag im gelöschten Datenordner |
  | Knopf *Dienst starten*, unmittelbar nach dem Abräumen | startet nichts — die virtuelle Python-Umgebung ist mit `bin/` gelöscht |
  | Knopf *Dienst starten*, während `postinstall.sh` beim `pip`-Lauf steht | **startet den Dienst** (Rückgabewert 0, „gestartet“, ein Prozess) |
  | Oberfläche öffnen und Einstellungen speichern | verliert nichts; die drei Geheimnisse kommen aus der Zweitschrift zurück, die Zugangsdaten holt `postinstall.sh` |
  | Unangemeldeter Loxone-Endpunkt | legt nichts an |

  Der dritte Fall ist der Grund für die Änderung: der `pip`-Lauf dauert auf
  einem Raspberry Pi Minuten, und in dieser Zeit ist die Oberfläche
  erreichbar. `preupgrade.sh` legt deshalb als **Erstes**
  `data/plugins/<ordner>.upgrade_laeuft` mit der Unixzeit an — neben dem
  Datenordner, weil der Ordner selbst gelöscht wird. `bin/dienst.sh` startet
  nicht, solange diese Marke gilt, und der minütliche Wächter ebenso wenig.
  `postinstall.sh` entfernt sie unmittelbar **vor** dem Dienststart; das
  Deinstallationsskript räumt sie weg.

- **Eine abgebrochene Installation legt das Plugin nicht still.** Die Marke
  gilt nur eine Stunde. Ist sie älter, trägt sie keinen Zeitpunkt oder liegt
  ihr Zeitpunkt in der Zukunft, dann gilt sie nicht — gemessen: der Dienst
  startet. Steigt `postinstall.sh` vorzeitig aus (kein Python, kein `pip`,
  keine Bibliothek), fällt die Marke über einen `trap` trotzdem.
  **Umgekehrt** fällt die Prüfung geschlossen aus, wenn die Uhr nicht
  lesbar ist: dann gilt die Marke, und der Dienst startet nicht.

- **Der Reiter Test nennt die Marke.** Eine neue Zeile beantwortet, ob gerade
  eine Aktualisierung läuft. Liegt eine Marke, die nicht mehr gilt, steht dort
  ein Kreuz samt Dateiname — sie hätte am Ende der Installation entfernt
  werden sollen.

- **Die Oberfläche sperrt nicht.** Das ist eine Messung, keine Annahme: in
  der Lücke geöffnet und mit unverändertem Formular abgeschickt, verliert
  diese Oberfläche nichts. Eine Sperre ohne Schaden nähme dem Anwender nur
  die Seite. Wer während der Installation auf *Dienst starten* oder *Dienst
  neu starten* drückt, bekommt den Hinweis, dass gerade eine Aktualisierung
  läuft und sie den Dienst an ihrem Ende selbst startet — **nicht** die
  Meldung „Dienst gestartet“. `bin/dienst.sh` endet in diesem Fall mit 0,
  und ein Rückgabewert ist keine Wirkung. *Dienst anhalten* bleibt erlaubt.

## Neu in 0.9.18

- **Die Selbstheilung der Konfiguration entscheidet nach Inhalt, nicht mehr
  nach Form.** Bis 0.9.18 fragte `au_config()`, ob `audi.json` *fehlt, leer
  ist oder `{}` enthält*. Eine **abgeschnittene** Datei — eine, deren
  Schreibvorgang durch Stromausfall oder ein volles Dateisystem in der Mitte
  abbrach — ist nichts davon: sie ist nicht leer, nicht `{}`, aber für den
  JSON-Leser unbrauchbar. Sie kam deshalb nie in die Selbstheilung; das Plugin
  las die blanken Vorgaben, würfelte **alle drei Geheimnisse** neu und
  kopierte sie über die Zweitschrift. Danach antwortete der Endpunkt jedem
  virtuellen Eingang im Miniserver mit HTTP 403, und es gab keinen Weg zurück.
  Gemessen am 18.09.2026 in WSL/Ubuntu (PHP 8.3.6) und unter PHP 7.4.33:
  aktionstoken, schalttoken und formgeheimnis waren in **beiden** Dateien
  fort. Jetzt entscheidet, ob der Stand die Geheimnisse trägt — **jedes
  einzeln**, damit eine Zweitschrift aus der Zeit vor 0.9.12, die noch kein
  `formgeheimnis` kennt, das Aktionstoken trotzdem retten kann.
- **Der verdrängte Stand wird nicht weggeworfen.** Was vor einer Heilung in
  `audi.json` stand, liegt danach als `audi.json.kaputt` mit den Rechten 0600
  daneben. Ein geheilter Schaden ist kein Nicht-Schaden: die Zweitschrift kann
  älter sein als das, was verlorenging.
- **Die Zweitschrift wird nie mit einem Stand ohne Geheimnis überschrieben.**
  `au_config_speichern()` zieht sie seither über `au_zweitschrift_ziehen()`
  nach. Trägt die Zweitschrift ein Geheimnis, das der zu schreibende Stand
  nicht mehr trägt, bleibt sie unverändert; gespeichert wird trotzdem, und das
  Protokoll sagt, was unterblieben ist. Zerstört wird damit nur eines nicht:
  der einzige Rückweg.
- **Dieselbe Frage stellen jetzt auch die beiden Hakenskripte.**
  `preupgrade.sh` schrieb bis dahin jede vorhandene `audi.json` ungefragt über
  die Sicherung — auch eine abgeschnittene; danach war die Sicherung ebenfalls
  unlesbar, und der Selbstheilung der Oberfläche blieb nichts mehr, woraus sie
  hätte heilen können. `postinstall.sh` spielte umgekehrt nur zurück, wenn die
  Konfigurationsdatei leer war oder `{}` enthielt — eine abgeschnittene blieb
  liegen, obwohl die Sicherung danebenlag. Beide entscheiden jetzt nach
  Inhalt. Gelesen wird dafür mit `perl` und `JSON::PP` (beides Bestandteil
  jedes LoxBerry, `plugininstall.pl` selbst ist Perl): eine Textsuche genügt
  hier nicht, weil eine abgeschnittene Datei den Text des Tokens noch enthält.
  Fehlt `perl` wider Erwarten, sagt das Skript es und verhält sich wie bisher.
- **Am Verhalten im Normalfall ändert sich nichts.** Eine Neuinstallation
  fängt weiter bei null an und erzeugt jedes Geheimnis genau einmal; eine
  heile Konfiguration wird nicht angefasst; und ein Wert, den jemand in der
  Oberfläche bewusst geleert hat, wird nicht aus der Zweitschrift
  „geheilt" — geheilt wird nach Geheimnis, nicht nach Vollständigkeit.
  Prüfstand und Rückbau-Eichung dazu:
  `Pruefung-AudiConnect-0.9.18/Pruefstaende/messe_au_klasseA.sh`.

## Neu in 0.9.16

- **Vor dem ersten Abruf antwortet der Endpunkt mit HTTP 503.** 0.9.15 hatte
  den Grund berichtigt (`GRUND=7` statt `FAHRZEUG_UNBEKANNT`), lieferte ihn
  aber mit HTTP 200. Nach der Hausregel antwortet ein Endpunkt ohne jede Daten
  mit 503: dann schaltet Loxone den Onlinestatus des Behälters ab, und der
  Ausfall ist sichtbar. Mit 200 und `OK=0` sah er aus wie ein gewöhnlicher
  Zustand. Betroffen sind `status`, `laden`, `wartung`, `position`, `text` und
  `fahrzeuge`, solange kein Fahrzeug im Abbild steht. Die Zeile mit dem Grund
  kommt weiter mit. Unverändert bleiben: eine unbekannte Nummer bei
  vorhandenen Fahrzeugen antwortet mit 404, und ein bekanntes Fahrzeug mit
  200. Der Prüfstand misst den Status jetzt über HTTP. Die Kommandozeile, über
  die 0.9.15 geprüft wurde, zeigt ihn nicht.
- **Ein falscher Kommentar in `bin/dienst.sh` ist berichtigt.** Er behauptete,
  die Cron-Datei werde bei einem Update nicht zuverlässig erneuert. Der
  Installer ersetzt sie bei jeder Installation. Am Verhalten ändert sich nichts.

## Neu in 0.9.15

Alles hier ist am LoxBerry gemessen (11.09.2026, 0.9.14 frisch installiert,
**ohne** Zugangsdaten) und dann im Prüfstand beidseitig nachgestellt. Ein
myAudi-Konto braucht keiner der Fälle.

- **Ohne Zugangsdaten startet der Dienst nicht mehr — und der Wächter hört
  auf.** `postinstall.sh` legt die Zugangsdatei als `{}` an; `dienst.sh`
  fragte nur, ob es sie *gibt*. Ein Druck auf „Dienst starten" setzte den
  Sollmerker, der Dienst brach sofort mit „Zugangsdaten fehlen" ab, und der
  minütliche Wächter startete ihn neu — am Gerät **444 Mal** zwischen 01:40
  und 09:03, rund 2.880 Protokollzeilen am Tag. Jetzt prüft `dienst.sh`, ob
  E-Mail **und** Passwort eingetragen sind, bevor es irgendetwas anlegt; der
  Wächter nimmt den Sollmerker zurück und sagt es einmal; und `audi.py` räumt
  ihn selbst weg, wenn es vor der Abrufschleife aus einem Grund abbricht, den
  ein Neustart nicht behebt. Eine vorübergehende Störung (Netz, Audi) startet
  der Wächter weiter neu — das ist sein Zweck.
- **„gestartet" heißt jetzt gestartet.** `dienst.sh` sah eine Sekunde nach
  dem Start hin. Auf dem Pi braucht `audi.py` länger, um die Bibliothek zu
  laden und abzubrechen: im Sandkasten meldete `dienst.sh` „gestartet (PID …)"
  für einen Dienst, der zwei Sekunden später tot war. Jetzt drei Sekunden, und
  im Fehlerfall stehen die letzten Protokollzeilen in der Meldung statt nur
  zweier Dateinamen.
- **Ein Knopf, der nichts speichert, meldet keine Beanstandung des
  Speicherns.** „Dienst starten" und die Knöpfe des Reiters Test schrieben
  ihr Scheitern unter „Es wurde nichts gespeichert. Bitte diese Punkte
  berichtigen" — obwohl gar nichts gespeichert werden sollte. Sie haben
  jetzt einen eigenen Kasten: „Der Vorgang ist nicht gelungen".
- **Zwei Hinweise verhinderten das ganze Speichern.** „Eingreifende Befehle
  ohne S-PIN" und „nur Miniserver, aber LoxBerry kennt keinen" standen in der
  Beanstandungsliste — und gespeichert wird nur, wenn die leer ist. Kommentar
  und Text sagten, der Haken werde gespeichert; gemessen wurde **nichts**
  gespeichert, auch nicht die übrigen, gültig geänderten Felder. Jetzt wird
  gespeichert, und der Hinweis steht darüber.
- **Der Endpunkt nennt vor dem ersten Abruf den Grund.** `status` und `text`
  antworteten ohne jedes Fahrzeug im Abbild mit HTTP 404 und
  `GRUND=FAHRZEUG_UNBEKANNT`; `fahrzeuge` mit `GRUND=1` („läuft der
  Dienst?"). Die eigentliche Ursache stand in `zustand.json` und kam beim
  Miniserver nie an. Jetzt liefern die lesenden Aktionen ihre gewohnte Zeile
  mit `OK=0` und dem Grund aus `zustand.json` — ohne Zugangsdaten also
  `GRUND=7`. Bei vorhandenen Fahrzeugen bleibt eine unbekannte Nummer
  `FAHRZEUG_UNBEKANNT`, und schaltende Aufrufe ohne Abbild bleiben abgewiesen.

Nicht geändert und am Gerät nachgemessen: der Endpunkt ohne oder mit falschem
Token (403, nichts angelegt), das Lesetoken schaltet nicht, und der Horcher
meldet sich mit paho-mqtt **2.1.0** am Broker des LoxBerry an, empfängt ein
abonniertes Thema und meldet ein falsches Kennwort mit dem CONNACK-Code
statt „verbunden".

## Neu in 0.9.14

- **Nur Schreibweise.** Die Sprachdateien führten für sichtbare Zeichen
  noch HTML-Entitäten (`&mdash;`, `&auml;`, `&bdquo;`); jetzt stehen dort die
  Zeichen selbst — in dieser Fassung **2** Stück. Das ist der Hausbeschluss
  vom 14.08.2026: mit direkten Zeichen darf `htmlspecialchars` folgenlos
  zweimal laufen, und die Doppelmaskierung fällt als Fehlerklasse weg.
  `&nbsp;` und `&shy;` bleiben Entität (unsichtbares Zeichen im Quelltext ist
  eine Wartungsfalle), ebenso die bedeutungstragenden `&amp;`, `&lt;`, `&gt;`,
  `&quot;` und `&apos;`. **Am Verhalten ändert sich nichts.**

- **Eine unvollständige Sicherung wird nicht mehr zurückgespielt.** In 0.9.12
  und 0.9.13 wurde sie angenommen: was in der Datei stand, wurde übernommen, alles
  Übrige **behielt den Wert dieser Anlage**, und die Seite nannte die Zahl der
  fehlenden Einstellungen. Das ergab einen halb zurückgespielten Stand, in dem
  sich Werte aus der Datei mit denen dieser Anlage mischen. Jetzt nennt der
  Rückspieler, welche Einstellungen fehlen, und eine halb gültige Datei ändert
  gar nichts.

  *Berichtigt in 0.9.15:* hier stand bis dahin, die übrigen Einstellungen
  seien „auf Werk zurückgefallen" und das Aktionstoken sei „dabei mitgefallen".
  Das stimmte für andere Linien des Bestands, nicht für diese — hier ging die
  Mischung seit 0.9.12 vom Bestand aus, und der Prüfstand hat an 0.9.12
  gemessen, dass beide Token stehen bleiben (`au_sicherungstest.py`, Fall 4).
  Die Umbaunotiz zu 0.9.13 hatte genau davor gewarnt.

## Neu in 0.9.13

- **Der Reiter Test sagt jetzt, ob die MQTT-Veröffentlichung dieses Plugins
  eingeschaltet ist.** Bis 0.9.12 stand dort nur der Zustand des MQTT-Gateways
  von LoxBerry — das ist eine Aussage über den LoxBerry, nicht über dieses
  Plugin. Wer die Veröffentlichung ausgeschaltet hatte, sah trotzdem einen
  grünen Haken und konnte am Reiter nicht erkennen, dass nichts an den Broker
  geht. Die neue Zeile steht vor der Gateway-Zeile und ist **grau**, wenn
  ausgeschaltet — das ist eine Entscheidung, kein Fehler. Anlass: derselbe
  Befund an BatterieBMS 0.9.17, dort am Gerät gemessen (`Regeln/04`).

- **Das Auswahlfeld zeichnet seinen Pfeil selbst.** Bis 0.9.12 kam er von der
  Oberfläche des LoxBerry. Am 05.09.2026 am Gerät gemessen (LoxBerry 4.0.0.15,
  `system/css/components.css`): deren Regel `.lb-content select`
  gibt es erst seit der neuen Oberfläche, und jede eigene Feldregel mit der
  Kurzform `background:` löscht sie wieder. Darauf soll sich eine
  Plugin-Oberfläche nicht verlassen (`Regeln/04`). Sonst ist an dieser
  Fassung nichts geändert.

### Der Dienst konnte sein Protokoll verlieren, ohne dass es auffiel

`log/plugins` liegt auf einer Ramdisk (`/dev/zram0`). Wird sie geleert — beim
Neustart, durch LoxBerrys `log_maint`, oder von Hand —, ist die Datei fort. Ein
`RotatingFileHandler`, der sie beim Start **einmal** geöffnet hat, schreibt
danach bis zum nächsten Neustart in einen gelöschten Inode: keine
Fehlermeldung, keine Datei, kein Hinweis. Auch die Rotation greift dann nicht
mehr.

Diese Fassung benutzt deshalb `WachsameRotation` in `bin/audi.py` — einen
umlaufenden Handler, der vor jeder Zeile Gerätenummer und Inode vergleicht und
nötigenfalls neu öffnet. Die Standardbibliothek hat für den einen Fall den
`WatchedFileHandler` und für den anderen den `RotatingFileHandler`, aber
nichts, was beides kann; deshalb die eigene Klasse.

Auf dem LoxBerry geeicht, vier Prüfungen und in beide Richtungen: schreiben,
nach dem Löschen weiterschreiben, Umlauf bei Überlänge, nach dem Umlauf erneut
löschen. Mit dem alten Handler ist die Zeile nach dem Löschen verloren und
bleibt es, mit dem neuen steht sie in der wieder angelegten Datei. Auf einem
Windows-Arbeitsplatz lässt sich das nicht messen — dort kann eine offene Datei
gar nicht gelöscht werden.

Aufgefallen ist die Bauart am Heimkino-Plugin, dessen Dienst sieben Stunden
ohne Protokolldatei lief, und am laufenden Gerät belegt: der
Midea2Lox-Dienst hielt `midea2lox.log (deleted)` offen, während unter
demselben Namen längst eine neue Datei fortgeschrieben wurde — von außen sah
das Plugin gesund aus. Elf Linien tragen dieselbe Bauart; alle elf sind am
06.09.2026 nachgezogen worden.

**Die zweite Hälfte gehört dem Startskript.** `bin/dienst.sh` hängte die
Ausgabe des Dienstes mit `nohup … >> "$LOGDATEI"` an **dieselbe** Datei, die
der Handler führt. Damit hält die Shell einen zweiten, anhängenden Deskriptor
darauf — und der bleibt auf der gelöschten Datei stehen, gleich wie gut das
Programm nachfasst. Am Gerät gemessen (06.09.2026): sieben laufende Dienste
hielten so eine gelöschte Protokolldatei offen. Die Ausgabe geht jetzt in
`audi_start.log`, das bei jedem Start geleert wird; das Protokoll gehört
allein dem Handler. Übernommen von AnkerSolix, das es seit 0.9.6 so macht.

Im Sandkasten am Gerät geprüft, in beide Richtungen: mit dem alten Skript
steht die Dienstausgabe im Protokoll und es gibt keine Startdatei, mit dem
neuen ist es umgekehrt — Start, Startdatei, unberührtes Protokoll und Stopp
je sechs von sechs.


## Was 0.9.8 ändert

Diese Fassung ist die größte seit dem Anfang. Sie bringt neue Funktionen, sie
beseitigt zwölf Befunde aus einer Zeile-für-Zeile-Durchsicht, und sie stützt
sich erstmals auf eine **Messung am Quelltext des Audi-Connectors** statt auf
Annahmen darüber, was er liefert.

### Sechs Werte bleiben dauerhaft leer — und das steht jetzt überall dabei

Gemessen am Quelltext des Tags `v0.3.2`, also an genau der Fassung, die
`postinstall.sh` festnagelt (der Stand von `main` ist byteweise derselbe):
**der Audi-Connector füllt sechs Attribute an keiner Stelle.**

> Bis 0.9.11 waren es hier *sieben*, mit dem **Hersteller** in der Tabelle.
> Das war falsch: der Connector setzt ihn in `vehicle.py` am Ende von
> `__init__`, ausserhalb jeder Bedingung, für jedes Fahrzeug. An einem
> echten Objekt nachgemessen ergibt `manufacturer` den Wert *Audi*. Die
> übrigen sechs sind bestätigt.

| Feld | Attribut im Kernmodell |
|---|---|
| Kennzeichen | `license_plate` |
| Baujahr | `model_year` |
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
    templates/lang/           Sprachdateien und Hilfetexte (de/en)
    templates/help/help.html  Gerüst der Hilfe hinter dem Fragezeichen
    postinstall.sh            venv, Bibliotheken, Rechte, Rückspielen
    preupgrade.sh             Dienst anhalten und sichern, VOR dem Upgrade
    postupgrade.sh            gibt an postinstall.sh ab
    uninstall/uninstall       Dienst beenden, Sicherungen sicher entfernen

Drei Aufgaben, drei Dateien: Die Oberfläche bedient, der Dienst ruft ab, der
Endpunkt bedient den Miniserver. Weder Oberfläche noch Endpunkt sprechen je
selbst mit Audi — sie lesen den Zwischenspeicher und legen Befehle in einer
Warteschlange ab, die der Dienst im Sekundentakt abarbeitet.

## Einstellungen sichern und zurückspielen

Im Reiter *Einstellungen* stehen zwei Knöpfe.

**Einstellungen sichern** lädt eine JSON-Datei mit allen Einstellungen dieses
Plugins herunter — beide Token **und** die Zugangsdaten des myAudi-Kontos
(E-Mail, Passwort, S-PIN). Damit ist sie alles, was ein zweiter LoxBerry
braucht; damit ist sie aber auch ein Geheimnis. Behandeln Sie die Datei wie
ein Passwort: nicht in ein Forum hängen und nicht an einen Fehlerbericht
heften. Nicht enthalten ist das Merkmal gegen fremde Formulare — das entsteht
auf jeder Anlage neu und darf nicht wandern.

**Einstellungen zurückspielen** liest eine solche Datei wieder ein. Dabei gilt:

* Jeder **Wert** wird geprüft, nicht nur der Schlüsselname — gegen dieselbe
  Liste, die auch das Formular benutzt. Eine Datei mit einem unzulässigen Wert
  wird abgelehnt, und dann ändert sich **gar nichts**.
* Ein **unbekannter Schlüssel** ist eine Beanstandung, kein stiller Verlust.
* Was in der Datei **fehlt**, behält seinen bisherigen Wert; die Zahl steht in
  der Meldung. Eine Sicherung aus einer älteren Fassung setzt also nicht
  stillschweigend alles auf Werk zurück.
* Danach wird der **Dienst nachgezogen**, und die Meldung sagt, was mit ihm
  geschah — neu gestartet, oder er lief nicht und bleibt gestoppt.

> **Was sich gegenüber 0.9.11 geändert hat.** Dort prüfte das Zurückspielen
> nur die Schlüsselnamen: eine Datei, in der das Abrufintervall gar keine
> Zahl war, wurde angenommen und geschrieben. Fehlende Schlüssel fielen auf
> die Werkseinstellung zurück — eine Sicherung aus einer älteren Fassung
> löschte damit **beide Token**, und die Meldung war grün. Und der Warntext
> versprach Zugangsdaten, die gar nicht in der Datei standen.

## Was ein Update überlebt

Der LoxBerry-Installer räumt beim Upgrade **beide** Plugin-Ordner ab —
`config/plugins/<ordner>/` und `data/plugins/<ordner>/`. Nachgemessen an
`sbin/plugininstall.pl` selbst: `purge_installation` wird an zwei Stellen
gerufen, einmal beim Deinstallieren und einmal im Upgrade-Zweig, und der
Block, der die Ordner löscht, prüft dabei nicht, welcher der beiden Fälle
vorliegt.

Was ein Update überstehen soll, muss deshalb **neben** den Ordnern liegen —
ein `rm -rf <ordner>/` trifft den Nachbarn mit dem Punkt nicht:

    config/plugins/<ordner>.backup.audi.json     die Einstellungen
    config/plugins/<ordner>.backup.zugang.json   die Zugangsdaten (0600)
    config/plugins/<ordner>.lief_vorher          Startmerker
    data/plugins/<ordner>.rettung/               Verlauf, Ladeprotokoll,
                                                 Merker, Anmeldemarken
    data/plugins/<ordner>.upgrade_laeuft         Marke: es läuft gerade
                                                 eine Aktualisierung

`preupgrade.sh` legt das an, `postinstall.sh` spielt es zurück, und das
Deinstallationsskript räumt es wieder weg — im Rettungsordner stehen die
Anmeldemarken des Kontos.

Seit 0.9.18 entscheiden beide dabei nach **Inhalt**: eine Konfiguration, die
kein Geheimnis mehr trägt, überschreibt keine Sicherung, die eines trägt, und
eine Konfiguration ohne Geheimnis wird aus der Sicherung zurückgeholt — auch
dann, wenn sie weder leer noch `{}` ist.

> **Was sich gegenüber 0.9.11 geändert hat.** Bis dahin behaupteten
> `preupgrade.sh`, `postinstall.sh` und der Quelltext des Dienstes, der
> Datenordner überlebe ein Update. Er tut es nicht. Mit jedem Update gingen
> das Ladeprotokoll, bis zu 90 Tage Verlauf, der angefangene Ladevorgang und
> die Anmeldemarken verloren — und mit dem Sollmerker auch die Möglichkeit
> des Wächters, den Dienst von selbst zurückzuholen.

## Zugangsdaten

Die Zugangsdaten des **myAudi-Kontos** liegen in
`config/plugins/audiconnect/zugang.json` mit den Rechten 0600, nicht in der
Konfiguration, die die Oberfläche anzeigt, und nie in der Loxone-Projektdatei.

Nach der ersten Anmeldung legt die Bibliothek Anmeldemarken in
`data/plugins/audiconnect/token.json` ab. Die Datei entsteht seit 0.9.12
gleich mit den Rechten 0600 — der Dienst setzt dafür beim Anlegen der
Bibliothek kurz die `umask` —, und er zieht die Rechte zusätzlich einmal je
Abrufzyklus nach. Bis 0.9.11 stand hier, sie würden *nach jedem
Schreibvorgang* nachgesetzt; das konnte nicht stimmen, denn geschrieben wird
die Datei von der Bibliothek, und `rechte_sichern()` läuft an drei Stellen —
einmal nach dem Einrichten, einmal je Zyklus, einmal beim Beenden. Nach einem Passwortwechsel sind sie wertlos —
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
