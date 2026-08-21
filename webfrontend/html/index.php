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
 * ZWEI TOKEN SEIT 0.9.8
 * Das Lesetoken steht in jeder Adresse eines virtuellen Eingangs und damit in
 * jeder Loxone-Projektdatei, die weitergegeben wird. Bis 0.9.7 konnte man mit
 * genau diesem Token auch die Klimaanlage starten. Schaltende Aufrufe
 * verlangen jetzt das SCHALTTOKEN; lesende nehmen beide an.
 *
 * Lesende Aktionen:
 *   status   [&fahrzeug=N]   Hauptwerte des Fahrzeugs
 *   laden    [&fahrzeug=N]   Ladewerte (nur bei Elektro und Hybrid belegt)
 *   wartung  [&fahrzeug=N]   Inspektion und Oelservice
 *   position [&fahrzeug=N]   Standort
 *   text     [&fahrzeug=N]   Klartexte fuer virtuelle Texteingaenge
 *   ladungen [&fahrzeug=N]   die letzten protokollierten Ladevorgaenge
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
 *   einstellung &name=<Name>&wert=<0|1>
 *   spin_pruefen
 *   abruf                       sofortiger Abruf statt Warten auf den Takt
 *
 * Eingreifende Aktionen - brauchen ZUSAETZLICH den zweiten Haken:
 *   verriegeln                  entriegeln
 *   hupe [&dauer=<s>]           lichthupe [&dauer=<s>]
 *
 * Jeder schaltende Aufruf vertraegt &probe=1: dann wird der ganze Weg samt
 * aller Wachen gegangen, aber NICHTS an das Fahrzeug gesendet.
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

/* ---------------- Aktion (Weissliste) ----------------
 * Zuerst die Aktion, dann das Token: die Weissliste entscheidet, WELCHES
 * Token verlangt wird. */
$au_lesend = array('status', 'laden', 'wartung', 'position', 'text', 'ladungen',
                   'fahrzeuge', 'roh');
$au_schaltend = array_keys(au_befehle());
$au_aktion = isset($_GET['aktion']) ? (string) $_GET['aktion'] : 'status';
if (!in_array($au_aktion, array_merge($au_lesend, $au_schaltend), true)) {
    http_response_code(400);
    echo "FEHLER;OK=0;GRUND=UNBEKANNTE_AKTION\n";
    echo 'Erlaubt sind: ' . implode(', ', array_merge($au_lesend, $au_schaltend)) . "\n";
    exit;
}
$au_schaltet = in_array($au_aktion, $au_schaltend, true);

/* ---------------- Beschraenkung auf den Miniserver ----------------
 * Ab Werk aus. Eingeschaltet nimmt der Endpunkt nur noch Aufrufe von den
 * Adressen an, die LoxBerry als Miniserver kennt. Findet sich keine, bleibt
 * die Beschraenkung wirkungslos - eine leere Liste wuerde sonst JEDEN Zugriff
 * abweisen, auch den berechtigten, und niemand fuende den Grund. */
if (!empty($au_cfg['nur_miniserver'])) {
    $au_erlaubt = au_miniserver_adressen();
    $au_woher = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    if ($au_erlaubt && !in_array($au_woher, $au_erlaubt, true)
        && $au_woher !== '127.0.0.1' && $au_woher !== '::1') {
        http_response_code(403);
        echo "FEHLER;OK=0;GRUND=FREMDE_ADRESSE\n";
        echo 'Der Aufruf kam von ' . $au_woher . '. Zugelassen sind nur die Miniserver '
           . 'dieses LoxBerry. Der Haken steht im Reiter Einstellungen.' . "\n";
        exit;
    }
}

/* ---------------- Token ----------------
 * Lesende Aufrufe nehmen beide Token an, schaltende nur das Schalttoken. */
$au_lesetoken = (string) $au_cfg['aktionstoken'];
$au_schalttoken = (string) $au_cfg['schalttoken'];
$au_ist = isset($_GET['token']) ? (string) $_GET['token'] : '';
if ($au_lesetoken === '' && $au_schalttoken === '') {
    http_response_code(403);
    echo "FEHLER;OK=0;GRUND=KEIN_TOKEN_GESETZT\n";
    echo "Die Plugin-Oberflaeche wurde noch nie geoeffnet - es gibt noch kein Token.\n";
    exit;
}
$au_passt = false;
if ($au_schalttoken !== '' && hash_equals($au_schalttoken, $au_ist)) {
    $au_passt = true;
} elseif (!$au_schaltet && $au_lesetoken !== '' && hash_equals($au_lesetoken, $au_ist)) {
    $au_passt = true;
}
if (!$au_passt) {
    http_response_code(403);
    // Wenn das LESEtoken stimmt, aber geschaltet werden soll, wird der Grund
    // benannt. Sonst sucht man an der falschen Stelle: das Token "stimmt" ja.
    if ($au_schaltet && $au_lesetoken !== '' && hash_equals($au_lesetoken, $au_ist)) {
        echo "FEHLER;OK=0;GRUND=LESETOKEN_SCHALTET_NICHT\n";
        echo "Das ist das Lesetoken. Schaltende Aufrufe brauchen das Schalttoken aus dem "
           . "Reiter 'Einbindung in Loxone'.\n";
    } else {
        echo "FEHLER;OK=0;GRUND=TOKEN\n";
    }
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
$au_dauer    = au_param('dauer', '/^[0-9]{1,2}$/', '');
$au_name     = au_param('name', '/^[a-z_]{1,32}$/', '');
$au_wert     = au_param('wert', '/^[01]$/', '');
$au_probe    = au_param('probe', '/^[01]$/', '0');
$au_tag      = au_param('tag', '/^[0-9]{8}$/', '');

$au_lox = au_loxone();
$au_alter = au_alter();
$au_ok = (!empty($au_lox['ok']) && $au_alter >= 0) ? 1 : 0;
list($au_grund, $au_fehlertext) = au_fehlergrund($au_lox, $au_ok, $au_alter);
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

/** Semikolon und Zeilenumbruch heraus - beides zerreisst die Zeile. */
function au_sauber($s)
{
    return str_replace(array("\r", "\n", ';'), array(' ', ' ', ','), (string) $s);
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
    echo 'FAHRZEUGE;OK=' . $au_ok . ';N=' . count($au_alle) . ';ALTER=' . $au_alter
       . ';GRUND=' . (int) $au_grund . "\n";
    foreach ($au_alle as $au_nr => $au_f) {
        echo $au_nr . ';' . au_sauber(isset($au_f['modell']) ? $au_f['modell'] : '') . ';'
           . au_sauber(isset($au_f['kennzeichen']) ? $au_f['kennzeichen'] : '') . ';'
           . au_sauber(isset($au_f['vin']) ? $au_f['vin'] : '') . ';'
           . 'FZOK=' . (isset($au_f['ok']) ? (int) $au_f['ok'] : 0) . ';'
           . 'Ausfaelle=' . (isset($au_f['ausfaelle']) && is_array($au_f['ausfaelle'])
                             ? count($au_f['ausfaelle']) : 0) . "\n";
    }
    exit;
}

if ($au_aktion === 'ladungen') {
    /* Die protokollierten Ladevorgaenge. Fuer Loxone selten brauchbar - dafuer
     * fuer eine Auswertung von Hand und fuer die Fehlersuche: hier steht
     * schwarz auf weiss, wann wie lange geladen wurde. */
    $au_l = au_ladungen_lesen(200);
    echo 'LADUNGEN;OK=' . $au_ok . ';N=' . count($au_l) . ";\n";
    echo "# fahrzeug;start;ende;dauer_min;soc_start;soc_ende;km;kwh\n";
    foreach ($au_l as $au_z) {
        if ($au_fahrzeug !== '' && (string) $au_z['fahrzeug'] !== (string) $au_fahrzeug
            && $au_fahrzeug !== '1') {
            continue;
        }
        echo $au_z['fahrzeug'] . ';' . $au_z['start'] . ';' . $au_z['ende'] . ';'
           . ($au_z['dauer'] === null ? '' : $au_z['dauer']) . ';'
           . ($au_z['soc_start'] === null ? '' : $au_z['soc_start']) . ';'
           . ($au_z['soc_ende'] === null ? '' : $au_z['soc_ende']) . ';'
           . ($au_z['km'] === null ? '' : $au_z['km']) . ';'
           . ($au_z['kwh'] === null ? '' : $au_z['kwh']) . "\n";
    }
    exit;
}

$au_f = au_waehlen($au_alle, $au_fahrzeug);

if (in_array($au_aktion, array('status', 'laden', 'wartung', 'position', 'text'), true)
    && $au_f === null) {
    printf("%s;OK=0;GRUND=FAHRZEUG_UNBEKANNT;N=%d;ALTER=%d\n",
        strtoupper($au_aktion), count($au_alle), $au_alter);
    exit;
}

if ($au_aktion === 'status') {
    echo au_zeile('AUDI', 'status', $au_f, $au_ok, $au_alter, $au_grund, $au_fehlertext);
    exit;
}

if ($au_aktion === 'laden') {
    echo au_zeile('LADEN', 'laden', $au_f, $au_ok, $au_alter, $au_grund, $au_fehlertext);
    exit;
}

if ($au_aktion === 'wartung') {
    echo au_zeile('WARTUNG', 'wartung', $au_f, $au_ok, $au_alter, $au_grund, $au_fehlertext);
    exit;
}

if ($au_aktion === 'position') {
    echo au_zeile('POSITION', 'position', $au_f, $au_ok, $au_alter, $au_grund, $au_fehlertext);
    // Die Anschrift steht in einer zweiten Zeile, damit die erste Zeile fuer
    // Loxone rein aus Zahlen besteht.
    echo 'ADRESSE;' . au_sauber(isset($au_f['adresse']) ? $au_f['adresse'] : '') . "\n";
    exit;
}

if ($au_aktion === 'text') {
    /* Klartexte fuer virtuelle TEXTeingaenge.
     *
     * Der Dienst kennt zu mehreren Zahlen den Klartext - ZUSTAND=1 heisst
     * "parked", KLIMA=1 kann heizen, kuehlen oder lueften heissen, und
     * TUEREN=1 sagt nicht, WELCHE Tuer offen steht. In der App will man das
     * lesen koennen. Je Zeile ein Wert, damit eine Befehlserkennung sie
     * einzeln greifen kann. */
    $au_texte = array(
        'ZUSTAND'  => isset($au_f['zustand_text']) ? $au_f['zustand_text'] : '',
        'KLIMA'    => isset($au_f['klima_text']) ? $au_f['klima_text'] : '',
        'LADEN'    => isset($au_f['ladezustand_text']) ? $au_f['ladezustand_text'] : '',
        'TUEREN'   => isset($au_f['tueren_namen']) ? $au_f['tueren_namen'] : '',
        'FENSTER'  => isset($au_f['fenster_namen']) ? $au_f['fenster_namen'] : '',
        'LICHTER'  => isset($au_f['licht_namen']) ? $au_f['licht_namen'] : '',
        'SCHEIBEN' => isset($au_f['scheibe_namen']) ? $au_f['scheibe_namen'] : '',
        'ADRESSE'  => isset($au_f['adresse']) ? $au_f['adresse'] : '',
        'SAEULE'   => isset($au_f['saeule_name']) ? $au_f['saeule_name'] : '',
        'MODELL'   => isset($au_f['modell']) ? $au_f['modell'] : '',
        'FEHLER'   => $au_fehlertext,
    );
    echo 'TEXT;OK=' . $au_ok . ';ALTER=' . $au_alter . ';GRUND=' . (int) $au_grund . "\n";
    foreach ($au_texte as $au_k => $au_v) {
        echo $au_k . '=' . au_sauber($au_v) . "\n";
    }
    exit;
}

/* ================= Schaltende Aktionen ================= */

$au_eig = au_befehle();
$au_eig = $au_eig[$au_aktion];

if ($au_aktion !== 'abruf' && empty($au_cfg['steuerung_ein'])) {
    http_response_code(403);
    echo "SET;OK=0;GRUND=STEUERUNG_AUS\n";
    echo "Schreibende Befehle sind gesperrt. Reiter Einstellungen, Haken 'Schreibende Befehle zulassen'.\n";
    exit;
}
if ($au_eig['gefahr'] && empty($au_cfg['gefahr_ein'])) {
    /* Der ZWEITE Haken. Ver- und Entriegeln, Hupe und Lichthupe wirken auf ein
     * Fahrzeug, das im oeffentlichen Raum steht - ein versehentliches
     * Entriegeln laesst es offen stehen, ohne dass es jemand merkt. Sie
     * haengen deshalb nicht am allgemeinen Steuerungshaken. */
    http_response_code(403);
    echo "SET;OK=0;GRUND=EINGRIFF_GESPERRT\n";
    echo "Dieser Befehl greift in ein Fahrzeug ein, das im oeffentlichen Raum steht. "
       . "Reiter Einstellungen, zweiter Haken 'Eingreifende Befehle zulassen'.\n";
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

$au_befehl = array('aktion' => $au_aktion);
if (!$au_eig['ohne_fz']) {
    $au_befehl['fahrzeug'] = $au_fahrzeug;
}
if ($au_probe === '1') {
    $au_befehl['probe'] = 1;
}

if ($au_eig['zusatz'] === 'temp') {
    if ($au_temp === '') {
        http_response_code(400);
        echo "SET;OK=0;GRUND=TEMP_FEHLT\n";
        echo "Der Parameter temp fehlt (Zieltemperatur in Grad Celsius).\n";
        exit;
    }
    $au_befehl['temp'] = str_replace(',', '.', $au_temp);
} elseif ($au_eig['zusatz'] === 'prozent') {
    if ($au_prozent === '') {
        http_response_code(400);
        echo "SET;OK=0;GRUND=PROZENT_FEHLT\n";
        echo "Der Parameter prozent fehlt (Ladegrenze, 10 bis 100).\n";
        exit;
    }
    $au_befehl['prozent'] = (int) $au_prozent;
} elseif ($au_eig['zusatz'] === 'ampere') {
    if ($au_ampere === '') {
        http_response_code(400);
        echo "SET;OK=0;GRUND=AMPERE_FEHLT\n";
        echo "Der Parameter ampere fehlt (zulaessig: 5, 6, 10, 13, 16 oder 32).\n";
        exit;
    }
    $au_befehl['ampere'] = (int) $au_ampere;
} elseif ($au_eig['zusatz'] === 'dauer') {
    if ($au_dauer !== '') {
        $au_befehl['dauer'] = (int) $au_dauer;
    }
} elseif ($au_eig['zusatz'] === 'einstellung') {
    $au_bekannt = au_einstellungen();
    if ($au_name === '' || !isset($au_bekannt[$au_name])) {
        http_response_code(400);
        echo "SET;OK=0;GRUND=NAME_FEHLT\n";
        echo 'Der Parameter name fehlt oder ist unbekannt. Bekannt sind: '
           . implode(', ', array_keys($au_bekannt)) . "\n";
        exit;
    }
    if ($au_wert === '') {
        http_response_code(400);
        echo "SET;OK=0;GRUND=WERT_FEHLT\n";
        echo "Der Parameter wert fehlt (0 oder 1).\n";
        exit;
    }
    $au_befehl['name'] = $au_name;
    $au_befehl['wert'] = $au_wert;
}

list($au_erg, $au_meldung) = au_befehl_absetzen($au_befehl);
if ($au_erg === 0) {
    http_response_code(500);
}
printf("SET;OK=%d;AKTION=%s;MELDUNG=%s\n", $au_erg, $au_aktion, au_sauber($au_meldung));
