<?php
/**
 * Audi Connect - Endpunkt fuer den Miniserver
 *
 * Liegt im unangemeldeten Bereich, damit Loxone ihn ohne Zugangsdaten
 * erreicht, und ist deshalb durch ein Token geschuetzt. Verglichen wird mit
 * hash_equals, also in gleichbleibender Zeit - ein einfaches == liesse sich
 * ueber die Antwortzeit Zeichen fuer Zeichen erraten.
 *
 *   /plugins/<ordner>/index.php?token=<TOKEN>&aktion=<Befehl>
 *
 * Lesende Aktionen:
 *   status   [&fahrzeug=N]   Hauptwerte des Fahrzeugs
 *   laden    [&fahrzeug=N]   Ladewerte (nur bei Elektro und Hybrid belegt)
 *   wartung  [&fahrzeug=N]   Inspektion, Oelservice, Warnleuchten
 *   position [&fahrzeug=N]   Standort
 *   fahrzeuge                Liste der erkannten Fahrzeuge
 *   roh                      vollstaendiges Abbild als JSON (Fehlersuche)
 *
 * Schaltende Aktionen (nur wenn im Reiter Einstellungen zugelassen):
 *   klima_start &temp=<Grad>    klima_stop
 *   zieltemperatur &temp=<Grad>
 *   laden_start                 laden_stop
 *   ladegrenze &prozent=<%>
 *   ladestrom &ampere=<A>
 *   scheibe_ein                 scheibe_aus
 *   wecken
 *   abruf                       sofortiger Abruf statt Warten auf den Takt
 *
 * Der Endpunkt spricht NIE selbst mit der Audi-Schnittstelle. Lesende Aktionen
 * beantwortet er aus dem Zwischenspeicher, schaltende legt er in einer
 * Warteschlange ab, die der Dienst abarbeitet.
 *
 * Ein Strich als Wert bedeutet: dieser Wert liegt nicht vor. Es wird bewusst
 * keine 0 gesendet - eine 0 waere eine stille Falschaussage. Loxone behaelt
 * dann den letzten gueltigen Wert; genau das ist bei einem fehlenden Messwert
 * richtig.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
require_once __DIR__ . '/au_lib.php';
header('Content-Type: text/plain; charset=utf-8');

$au_cfg = au_config();
$au_p = au_paths();

/* ---------------- Token ---------------- */
$au_soll = (string) $au_cfg['aktionstoken'];
$au_ist = isset($_GET['token']) ? (string) $_GET['token'] : '';
if ($au_soll === '') {
    http_response_code(403);
    echo "FEHLER;OK=0;GRUND=KEIN_TOKEN_GESETZT\n";
    echo "Die Plugin-Oberflaeche wurde noch nie geoeffnet - es gibt noch kein Token.\n";
    exit;
}
if (!hash_equals($au_soll, $au_ist)) {
    http_response_code(403);
    echo "FEHLER;OK=0;GRUND=TOKEN\n";
    exit;
}

/* ---------------- Aktion (Weissliste) ---------------- */
$au_lesend = array('status', 'laden', 'wartung', 'position', 'fahrzeuge', 'roh');
$au_schaltend = array('klima_start', 'klima_stop', 'zieltemperatur', 'laden_start',
                      'laden_stop', 'ladegrenze', 'ladestrom', 'scheibe_ein',
                      'scheibe_aus', 'wecken', 'abruf');
$au_aktion = isset($_GET['aktion']) ? (string) $_GET['aktion'] : 'status';
if (!in_array($au_aktion, array_merge($au_lesend, $au_schaltend), true)) {
    http_response_code(400);
    echo "FEHLER;OK=0;GRUND=UNBEKANNTE_AKTION\n";
    echo 'Erlaubt sind: ' . implode(', ', array_merge($au_lesend, $au_schaltend)) . "\n";
    exit;
}

/* ---------------- Parameter pruefen ----------------
 * Was nicht ins Muster passt, wird abgewiesen und gemeldet. Nie Zeichen
 * entfernen, nie zurechtbiegen - ein still veraenderter Wert fuehrt zu einem
 * Fahrzeug, das etwas anderes tut, als die Adresse sagt.
 */
function au_param($name, $muster, $vorgabe = '')
{
    if (!isset($_GET[$name]) || $_GET[$name] === '') {
        return $vorgabe;
    }
    $w = (string) $_GET[$name];
    if (!preg_match($muster, $w)) {
        http_response_code(400);
        echo "FEHLER;OK=0;GRUND=PARAMETER\n";
        echo 'Der Wert von ' . $name . " passt nicht ins erlaubte Muster.\n";
        exit;
    }
    return $w;
}

// Die laufende Nummer oder eine VIN (17 Zeichen, Buchstaben und Ziffern).
$au_fahrzeug = au_param('fahrzeug', '/^([0-9]{1,2}|[A-Za-z0-9]{17})$/', '1');
$au_temp     = au_param('temp', '/^[0-9]{1,2}([.,][05])?$/', '');
$au_prozent  = au_param('prozent', '/^[0-9]{1,3}$/', '');
$au_ampere   = au_param('ampere', '/^[0-9]{1,2}$/', '');

/* ---------------- Hilfsausgabe ---------------- */
function au_w($v)
{
    if ($v === null || $v === '') {
        return '-';
    }
    if (is_numeric($v)) {
        return (string) (0 + $v);
    }
    // Weicher zweiter Versuch: liefert die Bibliothek einen Wert mit Einheit
    // oder Komma ("54,5" oder "312 km"), waere die harte Pruefung ein '-' -
    // und in Loxone stuende dann nichts, obwohl die Zahl da ist. Nur wenn
    // wirklich keine Ziffer am Anfang steht, wird aufgegeben.
    $t = str_replace(',', '.', trim((string) $v));
    if (preg_match('/^-?\d+(\.\d+)?/', $t, $m)) {
        return (string) (0 + $m[0]);
    }
    return '-';
}

$au_lox = au_loxone();
$au_alter = au_alter();
$au_ok = (!empty($au_lox['ok']) && $au_alter >= 0) ? 1 : 0;

/**
 * Kurzer Fehlergrund fuer Loxone - als Zahl UND als Text.
 *
 * Bisher bekam der Miniserver nur OK=0. Er wusste damit, dass die Werte alt
 * sind, aber nicht warum: Wartung bei Audi, Passwort geaendert, oder das
 * Konto wegen zu vieler Anfragen fuer 24 Stunden gesperrt. Der Unterschied
 * entscheidet, ob man warten oder etwas tun muss - und stand bisher nur im
 * Protokoll des LoxBerry, wo ihn niemand sucht.
 *
 * GRUND ist eine Zahl fuer den Statusbaustein, FEHLERTEXT die Klartextfassung
 * fuer eine Textanzeige in der App. Der Text wird von Semikolon und
 * Zeilenumbruch befreit - beides wuerde die Zeile zerreissen, die Loxone
 * auswertet.
 */
function au_fehlergrund($zustand, $ok, $alter)
{
    if ($ok) {
        return array(0, '');
    }
    $text = trim((string) (isset($zustand['fehler']) ? $zustand['fehler'] : ''));
    $klein = mb_strtolower($text, 'UTF-8');
    $code = 9;                                            // 9 = unbekannt
    if ($text === '' && $alter < 0) {
        $code = 1;                                        // noch nie gelaufen
        $text = 'Es hat noch kein Abruf stattgefunden. Laeuft der Dienst?';
    } elseif (strpos($klein, 'zugangsdaten') !== false || strpos($klein, '401') !== false
              || strpos($klein, 'unauthorized') !== false || strpos($klein, 'login') !== false) {
        $code = 2;                                        // Anmeldung abgelehnt
    } elseif (strpos($klein, '429') !== false || strpos($klein, 'too many') !== false
              || strpos($klein, 'rate') !== false) {
        $code = 3;                                        // Konto gedrosselt
    } elseif (strpos($klein, 'timeout') !== false || strpos($klein, 'timed out') !== false
              || strpos($klein, 'connection') !== false || strpos($klein, 'name or service') !== false) {
        $code = 4;                                        // Audi nicht erreichbar
    } elseif (strpos($klein, '5') === 0 || strpos($klein, 'http 5') !== false
              || strpos($klein, 'server error') !== false) {
        $code = 5;                                        // Stoerung bei Audi
    }
    // Semikolon und Zeilenumbruch heraus: die Statuszeile trennt mit ';'.
    $text = str_replace(array(';', "\r", "\n"), array(',', ' ', ' '), $text);
    if (function_exists('mb_substr')) {
        $text = mb_substr($text, 0, 160, 'UTF-8');
    } else {
        $text = substr($text, 0, 160);
    }
    return array($code, trim($text));
}

list($au_grund, $au_fehlertext) = au_fehlergrund(au_zustand(), $au_ok, $au_alter);

/** Anhang fuer jede Statuszeile: Grund und Klartext, nur wenn es hakt. */
function au_grund_anhang($grund, $text)
{
    if ((int) $grund === 0) {
        return ';GRUND=0';
    }
    return ';GRUND=' . (int) $grund . ';FEHLERTEXT=' . $text;
}
$au_alle = au_fahrzeuge();

/** Findet das Fahrzeug zur laufenden Nummer oder zur VIN. */
function au_waehlen($alle, $schluessel)
{
    if (isset($alle[$schluessel])) {
        return $alle[$schluessel];
    }
    foreach ($alle as $f) {
        if (isset($f['vin']) && strcasecmp((string) $f['vin'], (string) $schluessel) === 0) {
            return $f;
        }
    }
    return null;
}

/* ================= Lesende Aktionen ================= */

if ($au_aktion === 'roh') {
    header('Content-Type: application/json; charset=utf-8');
    $au_json = json_encode($au_lox, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($au_json === false) {
        // json_encode gibt bei ungueltigem UTF-8 false zurueck - echo false
        // schreibt nichts, und der Abrufer bekommt eine leere Seite ohne
        // jeden Hinweis. Fahrzeugnamen und Ausstattungsbezeichnungen kommen
        // von myAudi; auf deren Kodierung ist kein Verlass.
        http_response_code(500);
        echo json_encode(array(
            'ok' => 0,
            'fehler' => 'Die Rohdaten liessen sich nicht in JSON wandeln: ' . json_last_error_msg(),
            'hinweis' => 'Vermutlich enthaelt eine Bezeichnung aus der Audi-Antwort '
                       . 'ungueltige Zeichen. Die uebrigen Endpunkte sind davon nicht betroffen.',
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    echo $au_json;
    exit;
}

if ($au_aktion === 'fahrzeuge') {
    echo 'FAHRZEUGE;OK=' . $au_ok . ';N=' . count($au_alle) . ';ALTER=' . $au_alter . "\n";
    foreach ($au_alle as $au_nr => $au_f) {
        echo $au_nr . ';' . (isset($au_f['modell']) ? $au_f['modell'] : '') . ';'
           . (isset($au_f['kennzeichen']) ? $au_f['kennzeichen'] : '') . ';'
           . (isset($au_f['vin']) ? $au_f['vin'] : '') . ';'
           . 'Ausfaelle=' . (isset($au_f['ausfaelle']) && is_array($au_f['ausfaelle'])
                             ? count($au_f['ausfaelle']) : 0) . "\n";
    }
    exit;
}

$au_f = au_waehlen($au_alle, $au_fahrzeug);

if (in_array($au_aktion, array('status', 'laden', 'wartung', 'position'), true) && $au_f === null) {
    printf("%s;OK=0;GRUND=FAHRZEUG_UNBEKANNT;N=%d;ALTER=%d\n",
        strtoupper($au_aktion), count($au_alle), $au_alter);
    exit;
}

/** Ein Wert aus dem Abbild, oder null. */
function au_v($f, $name)
{
    return isset($f[$name]) ? $f[$name] : null;
}

if ($au_aktion === 'status') {
    printf("AUDI;OK=%d;SOC=%s;TANK=%s;REICHW=%s;KM=%s;VERR=%s;TUEREN=%s;FENSTER=%s;"
         . "LICHT=%s;HANDBR=%s;KLIMA=%s;ZIELTEMP=%s;AUSSEN=%s;SCHEIBE=%s;ZUSTAND=%s;"
         . "ERREICH=%s;ALTER=%d%s\n",
        $au_ok,
        au_w(au_v($au_f, 'soc')), au_w(au_v($au_f, 'tank_prozent')),
        au_w(au_v($au_f, 'reichweite_km')), au_w(au_v($au_f, 'kilometerstand')),
        au_w(au_v($au_f, 'verriegelt')), au_w(au_v($au_f, 'tueren_offen')),
        au_w(au_v($au_f, 'fenster_offen')), au_w(au_v($au_f, 'licht_an')),
        au_w(au_v($au_f, 'handbremse')), au_w(au_v($au_f, 'klima_an')),
        au_w(au_v($au_f, 'zieltemperatur')), au_w(au_v($au_f, 'aussentemperatur')),
        au_w(au_v($au_f, 'scheibenheizung')), au_w(au_v($au_f, 'zustand')),
        au_w(au_v($au_f, 'erreichbar')), $au_alter, au_grund_anhang($au_grund, $au_fehlertext));
    exit;
}

if ($au_aktion === 'laden') {
    // Der Fertigzeitpunkt kommt als Unix-Zeit aus dem Dienst. Loxone kann mit
    // einer Restzeit in Minuten mehr anfangen als mit einem Zeitstempel, also
    // wird hier umgerechnet - und nur, wenn er in der Zukunft liegt.
    $au_fertig = au_v($au_f, 'laden_fertig_um');
    $au_restmin = null;
    if (is_numeric($au_fertig) && (int) $au_fertig > time()) {
        $au_restmin = (int) ceil(((int) $au_fertig - time()) / 60);
    }
    printf("LADEN;OK=%d;SOC=%s;LAEDT=%s;LADEKW=%s;TEMPO=%s;LADEGR=%s;LADESTROM=%s;"
         . "KABEL=%s;STECKER=%s;REICHWBAT=%s;FERTIGMIN=%s;ALTER=%d%s\n",
        $au_ok,
        au_w(au_v($au_f, 'soc')), au_w(au_v($au_f, 'laedt')),
        au_w(au_v($au_f, 'ladeleistung_kw')), au_w(au_v($au_f, 'ladetempo_kmh')),
        au_w(au_v($au_f, 'ladegrenze')), au_w(au_v($au_f, 'ladestrom_a')),
        au_w(au_v($au_f, 'kabel_verbunden')), au_w(au_v($au_f, 'stecker_verriegelt')),
        au_w(au_v($au_f, 'reichweite_elektro_km')), au_w($au_restmin), $au_alter, au_grund_anhang($au_grund, $au_fehlertext));
    exit;
}

if ($au_aktion === 'wartung') {
    printf("WARTUNG;OK=%d;INSPTAGE=%s;INSPKM=%s;OELTAGE=%s;OELKM=%s;KM=%s;ALTER=%d%s\n",
        $au_ok,
        au_w(au_v($au_f, 'inspektion_tage')), au_w(au_v($au_f, 'inspektion_km')),
        au_w(au_v($au_f, 'oelservice_tage')), au_w(au_v($au_f, 'oelservice_km')),
        au_w(au_v($au_f, 'kilometerstand')), $au_alter, au_grund_anhang($au_grund, $au_fehlertext));
    exit;
}

if ($au_aktion === 'position') {
    printf("POSITION;OK=%d;BREITE=%s;LAENGE=%s;ALTER=%d%s\n",
        $au_ok, au_w(au_v($au_f, 'breite')), au_w(au_v($au_f, 'laenge')), $au_alter, au_grund_anhang($au_grund, $au_fehlertext));
    // Die Anschrift steht in einer zweiten Zeile, damit die erste Zeile fuer
    // Loxone rein aus Zahlen besteht.
    echo 'ADRESSE;' . str_replace(array("\r", "\n", ';'), ' ',
        (string) au_v($au_f, 'adresse')) . "\n";
    exit;
}

/* ================= Schaltende Aktionen ================= */

if ($au_aktion !== 'abruf' && empty($au_cfg['steuerung_ein'])) {
    http_response_code(403);
    echo "SET;OK=0;GRUND=STEUERUNG_AUS\n";
    echo "Schreibende Befehle sind gesperrt. Reiter Einstellungen, Haken 'Schreibende Befehle zulassen'.\n";
    exit;
}
if (au_dienst_pid() === 0) {
    // Nicht stillschweigend einreihen: ohne laufenden Dienst passiert nichts,
    // und der Befehl laege bis zum naechsten Start in der Warteschlange.
    http_response_code(503);
    echo "SET;OK=0;GRUND=DIENST_LAEUFT_NICHT\n";
    echo "Der Abrufdienst laeuft nicht. Reiter Einstellungen, Knopf 'Dienst starten'.\n";
    exit;
}

$au_befehl = array('aktion' => $au_aktion, 'fahrzeug' => $au_fahrzeug);

if ($au_aktion === 'klima_start' || $au_aktion === 'zieltemperatur') {
    if ($au_temp === '') {
        http_response_code(400);
        echo "SET;OK=0;GRUND=TEMP_FEHLT\n";
        echo "Der Parameter temp fehlt (Zieltemperatur in Grad Celsius).\n";
        exit;
    }
    $au_befehl['temp'] = str_replace(',', '.', $au_temp);
} elseif ($au_aktion === 'ladegrenze') {
    if ($au_prozent === '') {
        http_response_code(400);
        echo "SET;OK=0;GRUND=PROZENT_FEHLT\n";
        exit;
    }
    $au_befehl['prozent'] = (int) $au_prozent;
} elseif ($au_aktion === 'ladestrom') {
    if ($au_ampere === '') {
        http_response_code(400);
        echo "SET;OK=0;GRUND=AMPERE_FEHLT\n";
        echo "Der Parameter ampere fehlt (zulaessig: 5, 6, 10, 13, 16 oder 32).\n";
        exit;
    }
    $au_befehl['ampere'] = (int) $au_ampere;
}

list($au_erg, $au_meldung) = au_befehl_absetzen($au_befehl);
if ($au_erg === 0) {
    http_response_code(500);
}
printf("SET;OK=%d;AKTION=%s;MELDUNG=%s\n", $au_erg, $au_aktion,
    str_replace(array("\r", "\n", ';'), ' ', $au_meldung));
