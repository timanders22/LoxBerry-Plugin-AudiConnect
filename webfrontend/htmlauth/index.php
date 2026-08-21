<?php
/**
 * Audi Connect - Bedienoberflaeche
 *
 * Reiter: Einstellungen | MQTT | Einbindung in Loxone | Ladevorgaenge | Test |
 *         Logdateien
 *
 * Diese Datei ist NUR Oberflaeche. Der Datenabruf laeuft im Dienst
 * (bin/audi.py), der Miniserver spricht mit webfrontend/html/index.php.
 * Ein Plugin, das den Abruf hier erledigt, ist falsch gebaut - auch wenn es
 * funktioniert.
 *
 * Praefix 'au_', weil LBWeb::lbheader() SDK-Globale setzt (unter anderem $cfg
 * aus der general.json als stdClass) und gleichnamige Plugin-Variablen
 * ueberschreiben wuerde.
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

/* Bibliothek einbinden. Sie liegt unter webfrontend/html/, weil der
 * Miniserver-Endpunkt sie ebenfalls braucht - installiert unter
 * .../html/plugins/<ordner>/, im Archiv unter ../html/. */
$au_gefunden = false;
foreach (array(
    // installiert: <home>/webfrontend/htmlauth/plugins/<ordner>  ->
    //              <home>/webfrontend/html/plugins/<ordner>
    dirname(dirname(__DIR__)) . '/html/plugins/' . basename(__DIR__) . '/au_lib.php',
    dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . basename(__DIR__) . '/au_lib.php',
    // im Archiv: <plugin>/webfrontend/htmlauth -> <plugin>/webfrontend/html
    dirname(__DIR__) . '/html/au_lib.php',
) as $au_kandidat) {
    if (is_file($au_kandidat)) {
        require_once $au_kandidat;
        $au_gefunden = true;
        break;
    }
}
if (!$au_gefunden) {
    echo '<p><b>Fehler:</b> au_lib.php wurde nicht gefunden. Bitte das Plugin neu installieren.</p>';
    exit;
}
require_once __DIR__ . '/au_test.php';

$au_p = au_paths();
if ($au_p['home'] !== '' && is_file($au_p['home'] . '/libs/phplib/loxberry_system.php')) {
    require_once $au_p['home'] . '/libs/phplib/loxberry_system.php';
    require_once $au_p['home'] . '/libs/phplib/loxberry_web.php';
}

/* ==================================================================
 * DIE REITERLISTE STEHT GENAU EINMAL
 *
 * Bis 0.9.7 stand sie dreimal: als Positivliste im Muster, als Leiste im
 * Markup und als sechs Bereiche. Wer einen Reiter hinzufuegt und die
 * Positivliste vergisst, bekommt einen Reiter, der sichtbar und anklickbar
 * ist - aber nach jedem Absenden springt die Seite zurueck auf
 * "Einstellungen", ohne dass jemand den Grund sieht.
 *
 * Jetzt entstehen Positivliste und Leiste aus diesem einen Feld. Zu pruefen
 * bleibt nur, dass es zu jeder Zeile einen Bereich mit derselben id gibt.
 * ================================================================== */
$au_reiter = array(
    'settings' => 'REITER.EINSTELLUNGEN',
    'mqtt'     => 'MQTT',
    'loxone'   => 'REITER.LOXONE',
    'ladungen' => 'REITER.LADUNGEN',
    'test'     => 'REITER.TEST',
    'log'      => 'REITER.LOG',
);
$au_muster = '/^tab-(' . implode('|', array_keys($au_reiter)) . ')$/';
$au_tab = 'tab-settings';
if (isset($_POST['activetab']) && preg_match($au_muster, (string) $_POST['activetab'])) {
    $au_tab = (string) $_POST['activetab'];
} elseif (isset($_GET['form']) && preg_match($au_muster, 'tab-' . (string) $_GET['form'])) {
    $au_tab = 'tab-' . (string) $_GET['form'];
}

$au_meldungen = array();   // Erfolgsmeldungen
$au_fehler = array();      // Beanstandungen - gesammelt, nicht ueberschrieben
$au_testausgabe = '';
$au_post = (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') === 'POST';

/* Beide Token anlegen, BEVOR das Formulartoken geprueft wird - es leitet sich
 * aus dem Lesetoken ab. */
au_token('lesen');

/* ---------------- Formulartoken ----------------
 * Die Oberflaeche liegt hinter der LoxBerry-Anmeldung, aber ein Formular, das
 * jede POST-Anfrage ausfuehrt, laesst sich von einer fremden Seite aus
 * abschicken, solange der Browser noch angemeldet ist. Betroffen waeren
 * Dienststart, Tokenwechsel und - schlimmer - die schaltenden Knoepfe des
 * Reiters Test, die auf ein Fahrzeug wirken. */
if ($au_post && !au_formtoken_pruefen()) {
    $au_fehler[] = au_t('ALLG.FORMTOKEN');
    $au_post = false;      // nichts ausfuehren, aber die Seite normal zeigen
}

/* ---------------- Vorlagen herunterladen ---------------- */
if ($au_post && isset($_POST['vorlage'])) {
    $au_nr = preg_match('/^[0-9]{1,2}$/', (string) $_POST['vorlage']) ? (int) $_POST['vorlage'] : 1;
    $au_art = isset($_POST['vorlage_art']) ? (string) $_POST['vorlage_art'] : 'status';
    if (!in_array($au_art, array('status', 'laden', 'wartung', 'position'), true)) {
        $au_art = 'status';
    }
    list($au_name, $au_inhalt) = au_vorlage($au_nr, $au_art);
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $au_name . '"');
    echo $au_inhalt;
    exit;
}
if ($au_post && isset($_POST['vorlage_vo'])) {
    $au_nr = preg_match('/^[0-9]{1,2}$/', (string) $_POST['vorlage_vo']) ? (int) $_POST['vorlage_vo'] : 1;
    list($au_name, $au_inhalt) = au_vorlage_vo($au_nr);
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $au_name . '"');
    echo $au_inhalt;
    exit;
}
if ($au_post && isset($_POST['verlauf_csv'])) {
    $au_nr = preg_match('/^[0-9]{1,2}$/', (string) $_POST['verlauf_csv']) ? (int) $_POST['verlauf_csv'] : 1;
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audi_verlauf_' . $au_nr . '.csv"');
    echo au_verlauf_csv($au_nr);
    exit;
}

/* ---------------- Einstellungen speichern ---------------- */
if ($au_post && isset($_POST['speichern'])) {
    $au_cfg = au_config();

    foreach (array(
        // Untergrenze 180 s: der Audi-Connector wirft darunter beim
        // Anlegen einen ValueError. Lieber hier abweisen als dort abstuerzen.
        'intervall'      => array(180, 3600),
        'takt_wartung'   => array(1, 240),
        'temp_min'       => array(10, 30),
        'temp_max'       => array(10, 30),
        'verlauf_tage'   => array(1, 90),
        'wartezeit'      => array(0, 30),
        'abruf_abstand'  => array(0, 3600),
        'befehle_stunde' => array(0, 500),
        'strom_abstand'  => array(0, 3600),
    ) as $au_feld => $au_grenzen) {
        $au_wert = isset($_POST[$au_feld]) ? trim((string) $_POST[$au_feld]) : '';
        if (!preg_match('/^[0-9]+$/', $au_wert)) {
            $au_fehler[] = sprintf(au_t('EINST.FEHLER_ZAHL'), au_t('EINST.L_' . strtoupper($au_feld)));
            continue;
        }
        $au_zahl = (int) $au_wert;
        if ($au_zahl < $au_grenzen[0] || $au_zahl > $au_grenzen[1]) {
            $au_fehler[] = sprintf(au_t('EINST.FEHLER_BEREICH'),
                au_t('EINST.L_' . strtoupper($au_feld)), $au_grenzen[0], $au_grenzen[1]);
            continue;
        }
        $au_cfg[$au_feld] = $au_zahl;
    }
    if (isset($au_cfg['temp_min'], $au_cfg['temp_max'])
        && $au_cfg['temp_min'] > $au_cfg['temp_max']) {
        $au_fehler[] = au_t('EINST.FEHLER_TEMP_TAUSCH');
    }

    $au_cfg['steuerung_ein']  = isset($_POST['steuerung_ein']) ? 1 : 0;
    $au_cfg['gefahr_ein']     = isset($_POST['gefahr_ein']) ? 1 : 0;
    $au_cfg['probe_ein']      = isset($_POST['probe_ein']) ? 1 : 0;
    $au_cfg['gps_ein']        = isset($_POST['gps_ein']) ? 1 : 0;
    $au_cfg['melden_ein']     = isset($_POST['melden_ein']) ? 1 : 0;
    $au_cfg['nur_miniserver'] = isset($_POST['nur_miniserver']) ? 1 : 0;

    /* Der zweite Haken ohne S-PIN ist eine Freigabe ins Leere: der Connector
     * weist Ver- und Entriegeln ohne S-PIN ab. Beanstanden, nicht das ganze
     * Speichern verhindern - der Anwender traegt die S-PIN gleich nach. */
    $au_zg_vorher = au_zugang();
    $au_spin_neu = isset($_POST['spin']) ? trim((string) $_POST['spin']) : '';
    if ($au_cfg['gefahr_ein'] && $au_spin_neu === '' && $au_zg_vorher['spin_laenge'] === 0) {
        $au_fehler[] = au_t('EINST.WARN_GEFAHR_OHNE_SPIN');
    }
    if ($au_cfg['nur_miniserver'] && !au_miniserver_adressen()) {
        $au_fehler[] = au_t('EINST.WARN_KEIN_MINISERVER');
    }

    /* Zugangsdaten: eigene Datei mit Rechten 0600.
     *
     * REIHENFOLGE BERICHTIGT IN 0.9.8. Bis dahin wurden sie geschrieben,
     * BEVOR feststand, ob die Zahlenfelder in Ordnung sind. Fiel eine
     * Zahlenpruefung durch, erschien "Es wurde nichts gespeichert." - und die
     * Zugangsdaten standen trotzdem schon in der Datei. Jetzt wird zuerst
     * alles geprueft und erst danach geschrieben.
     *
     * Ein leer zurueckgegebenes Passwortfeld loescht nichts - sonst stuende
     * irgendwann ein leeres Passwort in der Datei, ohne dass es jemand merkt.
     * Fuer die E-Mail gilt: ein leeres Feld wird als "nicht aendern" gelesen,
     * nicht als "Konto loeschen". Zum Loeschen gibt es den eigenen Knopf. */
    $au_email = trim(preg_replace('/[\x00-\x1F\x7F"\']/', '', (string) $_POST['email']));
    $au_pw = isset($_POST['passwort']) ? (string) $_POST['passwort'] : '';
    if ($au_email !== '' && !filter_var($au_email, FILTER_VALIDATE_EMAIL)) {
        $au_fehler[] = au_t('EINST.FEHLER_EMAIL');
    }
    if ($au_spin_neu !== '' && !preg_match('/^[0-9]{4}$/', $au_spin_neu)) {
        // Ist die FORM eines Geheimnisses erkennbar falsch, wird beim Speichern
        // abgewiesen, statt den Benutzer in eine Fehlermeldung des Anbieters
        // laufen zu lassen.
        $au_fehler[] = au_t('EINST.FEHLER_SPIN');
    }
    if ($au_pw !== '' && $au_email === '' && $au_zg_vorher['email'] === '') {
        $au_fehler[] = au_t('EINST.WARN_PW_OHNE_KONTO');
    }

    if (!$au_fehler) {
        if (!au_zugang_speichern($au_email !== '' ? $au_email : null, $au_pw, $au_spin_neu)) {
            $au_fehler[] = au_t('EINST.FEHLER_ZUGANG_SPEICHERN');
        } elseif (au_config_speichern($au_cfg)) {
            $au_meldungen[] = au_t('EINST.GESPEICHERT');
        } else {
            $au_fehler[] = sprintf(au_t('EINST.FEHLER_SPEICHERN'), $au_p['config']);
        }
    }
    $au_tab = 'tab-settings';

    /* mqtt_ein, mqtt_topic und die Automatik werden hier bewusst NICHT
     * angefasst: sie haben eigene Formulare mit eigenen Handlern. Die
     * Konfiguration kommt aus au_config(), die Werte ueberleben also
     * unveraendert. Stuende hier weiter "isset($_POST['mqtt_ein']) ? 1 : 0",
     * wuerde jedes Speichern der Einstellungen MQTT stillschweigend
     * abschalten. */
}

/* ---------------- Automatik und gerechnete Groessen ----------------
 *
 * Eigenes Formular UND eigener Handler gehoeren zusammen. Loesten mehrere
 * Formulare denselben Handler aus, setzte dieser die Haken des jeweils nicht
 * abgeschickten Formulars per isset() auf 0 - der Benutzer verloere Werte,
 * die er nie gesehen hat. */
if ($au_post && isset($_POST['save_automatik'])) {
    $au_acfg = au_config();
    $au_acfg['abfahrt_ein']  = isset($_POST['abfahrt_ein']) ? 1 : 0;
    $au_acfg['ladeempf_ein'] = isset($_POST['ladeempf_ein']) ? 1 : 0;
    $au_acfg['ladeempf_unter'] = isset($_POST['ladeempf_unter']) ? 1 : 0;

    foreach (array(
        'abfahrt_vorlauf'  => array(1, 120),
        'abfahrt_temp'     => array(10, 30),
        'abfahrt_alter'    => array(60, 3600),
        'abfahrt_fahrzeug' => array(1, 99),
        'ladeempf_alter'   => array(60, 86400),
        'kapazitaet'       => array(0, 500),
        'heim_radius'      => array(20, 5000),
    ) as $au_feld => $au_grenzen) {
        $au_wert = isset($_POST[$au_feld]) ? trim((string) $_POST[$au_feld]) : '';
        if (!preg_match('/^[0-9]+$/', $au_wert)) {
            $au_fehler[] = sprintf(au_t('EINST.FEHLER_ZAHL'), au_t('EINST.L_' . strtoupper($au_feld)));
            continue;
        }
        $au_zahl = (int) $au_wert;
        if ($au_zahl < $au_grenzen[0] || $au_zahl > $au_grenzen[1]) {
            $au_fehler[] = sprintf(au_t('EINST.FEHLER_BEREICH'),
                au_t('EINST.L_' . strtoupper($au_feld)), $au_grenzen[0], $au_grenzen[1]);
            continue;
        }
        $au_acfg[$au_feld] = $au_zahl;
    }

    // Der Ladeempfehlungs-Schwellwert darf negativ und gebrochen sein: an der
    // Boerse kostet Strom zeitweise weniger als nichts.
    $au_gr = isset($_POST['ladeempf_grenze']) ? trim((string) $_POST['ladeempf_grenze']) : '';
    $au_gr = str_replace(',', '.', $au_gr);
    if ($au_gr === '' || !preg_match('/^-?[0-9]+(\.[0-9]+)?$/', $au_gr)) {
        $au_fehler[] = sprintf(au_t('EINST.FEHLER_ZAHL'), au_t('EINST.L_LADEEMPF_GRENZE'));
    } else {
        $au_acfg['ladeempf_grenze'] = (float) $au_gr;
    }

    $au_pr = trim(preg_replace('/[\x00-\x1F\x7F"\']/', '',
        (string) (isset($_POST['abfahrt_praefix']) ? $_POST['abfahrt_praefix'] : '')));
    if ($au_pr === '' || !preg_match('#^[A-Za-z0-9_/\-]{1,64}$#', $au_pr)) {
        $au_fehler[] = au_t('EINST.FEHLER_PRAEFIX');
    } else {
        $au_acfg['abfahrt_praefix'] = trim($au_pr, '/');
    }

    $au_th = trim(preg_replace('/[\x00-\x1F\x7F"\']/', '',
        (string) (isset($_POST['ladeempf_thema']) ? $_POST['ladeempf_thema'] : '')));
    if ($au_th !== '' && !preg_match('#^[A-Za-z0-9_/\-\.\+#]{1,128}$#', $au_th)) {
        $au_fehler[] = au_t('EINST.FEHLER_THEMA');
    } else {
        $au_acfg['ladeempf_thema'] = $au_th;
    }
    if ($au_acfg['ladeempf_ein'] && $au_acfg['ladeempf_thema'] === '') {
        $au_fehler[] = au_t('EINST.FEHLER_THEMA_LEER');
    }

    // Heimatposition: entweder beide oder keine. Eine halbe Koordinate ergibt
    // keinen Punkt, und ZUHAUSE bliebe stumm leer.
    foreach (array('heim_breite' => array(-90, 90), 'heim_laenge' => array(-180, 180))
             as $au_feld => $au_gr2) {
        $au_wert = isset($_POST[$au_feld]) ? trim(str_replace(',', '.', (string) $_POST[$au_feld])) : '';
        if ($au_wert === '') {
            $au_acfg[$au_feld] = '';
            continue;
        }
        if (!preg_match('/^-?[0-9]{1,3}(\.[0-9]{1,8})?$/', $au_wert)
            || (float) $au_wert < $au_gr2[0] || (float) $au_wert > $au_gr2[1]) {
            $au_fehler[] = sprintf(au_t('EINST.FEHLER_BEREICH'),
                au_t('EINST.L_' . strtoupper($au_feld)), $au_gr2[0], $au_gr2[1]);
            continue;
        }
        $au_acfg[$au_feld] = $au_wert;
    }
    if ((trim((string) $au_acfg['heim_breite']) === '')
        !== (trim((string) $au_acfg['heim_laenge']) === '')) {
        $au_fehler[] = au_t('EINST.FEHLER_HEIM_HALB');
    }

    if (!$au_fehler) {
        if (au_config_speichern($au_acfg)) {
            $au_meldungen[] = au_t('EINST.GESPEICHERT');
        } else {
            $au_fehler[] = sprintf(au_t('EINST.FEHLER_SPEICHERN'), $au_p['config']);
        }
    }
    $au_tab = 'tab-settings';
}

/* ---------------- MQTT (eigener Reiter, eigenes Formular) ---------------- */
if ($au_post && isset($_POST['save_mqtt'])) {
    $au_mcfg = au_config();
    $au_mcfg['mqtt_ein'] = isset($_POST['mqtt_ein']) ? 1 : 0;
    $au_mtopic = trim(preg_replace('/[\x00-\x1F\x7F"\']/', '',
        (string) (isset($_POST['mqtt_topic']) ? $_POST['mqtt_topic'] : '')));
    if ($au_mtopic === '' || !preg_match('#^[A-Za-z0-9_/\-]{1,64}$#', $au_mtopic)) {
        $au_fehler[] = au_t('EINST.FEHLER_TOPIC');
    } else {
        $au_mcfg['mqtt_topic'] = trim($au_mtopic, '/');
    }
    if (!$au_fehler) {
        if (au_config_speichern($au_mcfg)) {
            $au_meldungen[] = au_t('EINST.GESPEICHERT');
        } else {
            $au_fehler[] = sprintf(au_t('EINST.FEHLER_SPEICHERN'), $au_p['config']);
        }
    }
    $au_tab = 'tab-mqtt';
}

/* ---------------- Dienst starten, anhalten, neu starten ---------------- */
if ($au_post && isset($_POST['dienst'])) {
    $au_befehl = (string) $_POST['dienst'];
    list($au_ok, $au_ausgabe) = au_dienst($au_befehl);
    if ($au_ok) {
        $au_meldungen[] = au_t('EINST.DIENST_' . strtoupper($au_befehl)) . ' ' . au_e($au_ausgabe);
    } else {
        $au_fehler[] = au_e($au_ausgabe);
    }
    $au_tab = 'tab-settings';
}

/* ---------------- Anmeldemarken verwerfen ----------------
 * Die Bibliothek legt ihre Anmeldemarken in token.json ab und meldet sich
 * damit an, statt jedes Mal das Passwort zu senden. Sind sie verdorben -
 * etwa nach einem Passwortwechsel -, hilft nur Wegwerfen. */
if ($au_post && isset($_POST['sitzung_verwerfen'])) {
    $au_datei = $au_p['datadir'] . '/token.json';
    if (is_file($au_datei) && @unlink($au_datei)) {
        $au_meldungen[] = au_t('EINST.SITZUNG_VERWORFEN');
    } else {
        $au_meldungen[] = au_t('EINST.SITZUNG_KEINE');
    }
    $au_tab = 'tab-settings';
}

/* ---------------- Zugangsdaten loeschen ----------------
 * Ein leeres Feld loescht nichts (siehe Speichern-Handler). Wer sie wirklich
 * entfernen will, braucht deshalb einen eigenen Knopf - sonst bleibt ein
 * altes Passwort fuer immer stehen. */
if ($au_post && isset($_POST['zugang_loeschen'])) {
    if (au_datei_schreiben($au_p['zugang'], json_encode(array(
            'email' => '', 'passwort' => '', 'spin' => '')), 0600)) {
        $au_meldungen[] = au_t('EINST.ZUGANG_GELOESCHT');
    } else {
        $au_fehler[] = au_t('EINST.FEHLER_ZUGANG_SPEICHERN');
    }
    $au_tab = 'tab-settings';
}

/* ---------------- Neue Token ---------------- */
if ($au_post && isset($_POST['token_neu'])) {
    $au_art = (string) $_POST['token_neu'];
    $au_cfg = au_config();
    if ($au_art === 'lesen' || $au_art === 'beide') {
        $au_cfg['aktionstoken'] = au_token_erzeugen();
    }
    if ($au_art === 'schalten' || $au_art === 'beide') {
        $au_cfg['schalttoken'] = au_token_erzeugen();
    }
    if (au_config_speichern($au_cfg)) {
        $au_meldungen[] = au_t('LOX.TOKEN_NEU');
    } else {
        $au_fehler[] = sprintf(au_t('EINST.FEHLER_SPEICHERN'), $au_p['config']);
    }
    $au_tab = 'tab-loxone';
}

/* ---------------- Log leeren ---------------- */
if ($au_post && isset($_POST['log_leeren'])) {
    @mkdir(dirname($au_p['log']), 0775, true);
    @file_put_contents($au_p['log'], '[' . date('Y-m-d H:i:s') . '] ' . au_t('LOG.GELEERT') . "\n");
    $au_meldungen[] = au_t('LOG.GELEERT');
    $au_tab = 'tab-log';
}

/* ---------------- Aktionen des Reiters Test ---------------- */
if ($au_post && isset($_POST['test'])) {
    list($au_stand, $au_text) = au_test_aktion((string) $_POST['test']);
    if ($au_stand === 1) {
        $au_meldungen[] = au_e($au_text);
    } else {
        $au_fehler[] = au_e($au_text);
    }
    $au_tab = 'tab-test';
}
if ($au_post && isset($_POST['selbsttest'])) {
    $au_testausgabe = au_selbsttest();
    $au_tab = 'tab-test';
}

/* ---------------- Laden ---------------- */
$au_cfg = au_config();
$au_token = au_token('lesen');
$au_stoken = au_token('schalten');
$au_ftoken = au_formtoken($au_cfg);
$au_zg = au_zugang();
$au_fahrzeuge = au_fahrzeuge();
$au_zustand = au_zustand();
$au_alter = au_alter();
$au_pid = au_dienst_pid();
$au_mqtt = au_mqtt_zustand();
$au_horcher = au_horcher_zustand();
$au_pyv = au_python_fassung();
$au_libv = au_bibliothek_fassung();
$au_fassungen = au_bibliothek_fassungen();
$au_host = au_host();
$au_basis = 'http://' . $au_host . '/plugins/' . $au_p['plugin'] . '/index.php';
$au_logzeilen = array_reverse(au_log_ende($au_p['log'], 400));
$au_ladungen = au_ladungen_lesen(200);

/* Welcher Tag wird im Verlauf gezeigt? */
$au_vtag = isset($_GET['tag']) && preg_match('/^[0-9]{8}$/', (string) $_GET['tag'])
    ? (string) $_GET['tag'] : date('Ymd');

$au_rahmen = class_exists('LBWeb', false);
if ($au_rahmen) {
    LBWeb::lbheader('Audi Connect', 'https://wiki.loxberry.de/', 'help.html');
}

/** Ein verstecktes Feld-Paar, das in JEDES Formular gehoert. */
function au_formfelder($tab, $ftoken)
{
    echo '<input data-role="none" type="hidden" name="activetab" value="' . au_e($tab) . '">'
       . '<input data-role="none" type="hidden" name="formtoken" value="' . au_e($ftoken) . '">';
}
?>
<style>
/* Hausstandard, wortgetreu aus VORLAGE_hausstandard.css.html uebernommen.
   Nicht neu erfinden: der Knopf-Fehler vom 30.07.2026 steckte in sieben
   Plugins gleichzeitig, weil jedes seine eigene Kopie hatte. */
.sm-wrap { max-width: 980px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap, .sm-wrap *, .sm-tabs, .sm-tabs * { text-shadow: none !important; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0;
          padding: 9px 18px; font-size: 0.95em; color: #444 !important; text-decoration: none; display: inline-block; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-feld { margin: 14px 0; }
.sm-feld > label { display: block; font-weight: 600; font-size: 0.9em; color: #555; margin: 0 0 4px; }
.sm-feld .ui-input-text, .sm-feld .ui-select, .sm-feld .ui-textinput { max-width: 520px; }
.sm-feld .ui-input-text input, .sm-feld .ui-input-text textarea { font-size: 0.95em; }
.sm-hilfe { font-size: 0.85em; color: #555; margin: 4px 0 0; max-width: 640px; }
.sm-step { border: 1px solid #ddd; border-left: 4px solid #6dac20; background: #fafafa;
    border-radius: 6px; padding: 12px 14px; margin: 12px 0; font-size: 0.92em; line-height: 1.5; }
.sm-tbl { border-collapse: collapse; width: 100%; margin: 8px 0; font-size: 0.9em; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ccc; padding: 5px 7px; text-align: left; vertical-align: top; }
.sm-tbl th { background: #eef3e6; font-weight: 600; }
.sm-mono { font-family: Consolas, "Courier New", monospace; background: #f0f0f0;
    padding: 1px 4px; border-radius: 3px; font-size: 0.94em; word-break: break-all; }
.sm-pre { background: #f4f4f4; border: 1px solid #ccc; padding: 10px; font-size: 0.85em;
    overflow: auto; margin: 8px 0; white-space: pre-wrap; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.sm-knopfreihe form { margin: 0; display: flex; }
.sm-wrap .sm-knopfreihe .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button.sm-btn {
    flex: 0 0 auto; min-width: 250px; text-align: center; display: inline-flex;
    align-items: center; justify-content: center; line-height: 1.25;
    padding: 10px 14px !important; border-radius: 6px !important;
    color: #fff !important; text-decoration: none !important; font-size: 0.92em;
    border: 0 !important; cursor: pointer; font-weight: 600 !important;
    text-shadow: none !important; box-shadow: none !important;
    opacity: 1 !important; margin: 0 !important; width: auto !important; }
.sm-kacheln { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0; }
.sm-kachel { border: 1px solid #ddd; border-radius: 10px; padding: 10px 14px; min-width: 130px; }
.sm-kachel b { display: block; font-size: 1.35em; color: #33691e; }
.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-wrap .sm-btn.sm-b-lesen   { background: #6dac20 !important; }
.sm-wrap .sm-btn.sm-b-technik { background: #546e7a !important; }
.sm-wrap .sm-btn.sm-b-aktion  { background: #e0620d !important; }
.sm-wrap .sm-btn.sm-b-lesen:hover,   .sm-wrap .sm-btn.sm-b-lesen:focus   { background: #5c9219 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-technik:hover, .sm-wrap .sm-btn.sm-b-technik:focus { background: #435962 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-aktion:hover,  .sm-wrap .sm-btn.sm-b-aktion:focus  { background: #b84f0a !important; color: #fff !important; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
.sm-seite { display: none; padding-top: 4px; }
.sm-seite.sm-active { display: block; }
.sm-hinweis { border: 1px solid #cfe3b0; background: #f2f8ea; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-warnung { border: 1px solid #f0c9a0; background: #fdf4ec; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-fehler { border: 1px solid #ef9a9a; background: #ffebee; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-an  { color: #1a7f1a; font-weight: 700; }
.sm-aus { color: #b00000; font-weight: 700; }
.sm-leer { color: #888; font-style: italic; }
.sm-log { background: #1e1e1e; color: #d4d4d4; font-family: Consolas, "Courier New", monospace;
    font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto;
    white-space: pre-wrap; }
</style>
<div class="sm-wrap">

<?php foreach ($au_meldungen as $au_m) { ?>
<div class="sm-hinweis"><?= $au_m ?></div>
<?php } ?>
<?php if ($au_fehler) { ?>
<div class="sm-fehler"><b><?= au_e(au_t('ALLG.BEANSTANDUNG')) ?></b>
<ul style="margin:6px 0 0 18px;padding:0;">
<?php foreach ($au_fehler as $au_f) { ?><li><?= $au_f ?></li><?php } ?>
</ul></div>
<?php } ?>

<?php if (!empty($au_cfg['probe_ein'])) { ?>
<div class="sm-warnung"><b><?= au_e(au_t('EINST.L_PROBE_EIN')) ?></b> <?= au_t('EINST.PROBE_LAEUFT') ?></div>
<?php } ?>

<!-- ================= Statuskacheln ================= -->
<div class="sm-kacheln">
  <div class="sm-kachel"><?= au_e(au_t('ALLG.DIENST')) ?>
    <b class="<?= $au_pid ? 'sm-an' : 'sm-aus' ?>"><?= $au_pid ? au_e(au_t('ALLG.LAEUFT')) : au_e(au_t('ALLG.GESTOPPT')) ?></b>
    <span class="sm-hilfe"><?= $au_pid ? 'PID ' . (int) $au_pid : au_e(au_t('ALLG.KEINE_PID')) ?></span>
  </div>
  <div class="sm-kachel"><?= au_e(au_t('ALLG.LETZTER_ABRUF')) ?>
    <b><?= $au_alter < 0 ? '&ndash;' : (int) $au_alter . ' s' ?></b>
    <span class="sm-hilfe"><?= $au_alter < 0 ? au_e(au_t('ALLG.NIE')) : au_e(date('d.m.Y H:i:s', time() - $au_alter)) ?></span>
  </div>
  <div class="sm-kachel"><?= au_e(au_t('ALLG.FAHRZEUGE')) ?>
    <b><?= count($au_fahrzeuge) ?></b>
    <span class="sm-hilfe"><?= $au_libv !== '' ? au_e($au_libv) : au_e(au_t('ALLG.LIB_FEHLT')) ?></span>
  </div>
  <div class="sm-kachel">MQTT
    <b class="<?= $au_mqtt['autostart'] ? 'sm-an' : 'sm-aus' ?>"><?= $au_mqtt['autostart'] ? au_e(au_t('ALLG.EIN')) : au_e(au_t('ALLG.AUS')) ?></b>
    <span class="sm-hilfe"><?= au_e(au_t('ALLG.GATEWAY')) ?></span>
  </div>
  <div class="sm-kachel"><?= au_e(au_t('ALLG.BEFEHLE_STUNDE')) ?>
    <b><?= (int) (isset($au_zustand['befehle_stunde']) ? $au_zustand['befehle_stunde'] : 0) ?></b>
    <span class="sm-hilfe"><?= (int) $au_cfg['befehle_stunde'] === 0
        ? au_e(au_t('TEST.A_OHNE_GRENZE'))
        : au_e(sprintf(au_t('ALLG.VON_N'), (int) $au_cfg['befehle_stunde'])) ?></span>
  </div>
</div>

<?php if (!empty($au_zustand['fehler'])) { ?>
<div class="sm-warnung"><b><?= au_e(au_t('ALLG.LETZTE_STOERUNG')) ?></b> <?= au_e($au_zustand['fehler']) ?>
<?php if (isset($au_zustand['fehler_code'])) { ?>
<span class="sm-hilfe">(<?= au_e(au_t('ALLG.FEHLERKLASSE')) ?> <?= (int) $au_zustand['fehler_code'] ?>)</span>
<?php } ?>
</div>
<?php } ?>

<?php foreach ($au_fahrzeuge as $au_nr => $au_fz) { ?>
<div class="sm-hinweis">
<b><?= au_e($au_fz['modell'] ? $au_fz['modell'] : au_t('ALLG.OHNE_NAMEN')) ?></b>
(<?= au_e(au_t('ALLG.FAHRZEUG')) ?> <?= au_e($au_nr) ?>)
&middot; <?= au_e(au_t('ALLG.SOC')) ?> <b><?= !isset($au_fz['soc']) || $au_fz['soc'] === null ? '&ndash;' : au_e($au_fz['soc']) . ' %' ?></b>
&middot; <?= au_e(au_t('ALLG.REICHWEITE')) ?> <?= !isset($au_fz['reichweite_km']) || $au_fz['reichweite_km'] === null ? '&ndash;' : au_e($au_fz['reichweite_km']) . ' km' ?>
&middot; <?= au_e(au_t('ALLG.KM')) ?> <?= !isset($au_fz['kilometerstand']) || $au_fz['kilometerstand'] === null ? '&ndash;' : au_e($au_fz['kilometerstand']) . ' km' ?>
&middot; <?= au_e(au_t('ALLG.VERRIEGELT')) ?>
<?php if (!isset($au_fz['verriegelt']) || $au_fz['verriegelt'] === null) { ?>&ndash;<?php
      } elseif ($au_fz['verriegelt']) { ?><span class="sm-an"><?= au_e(au_t('ALLG.JA')) ?></span><?php
      } else { ?><span class="sm-aus"><?= au_e(au_t('ALLG.NEIN')) ?></span><?php } ?>
<?php if (!empty($au_fz['tueren_namen'])) { ?>
&middot; <?= au_e(au_t('ALLG.OFFEN')) ?> <span class="sm-mono"><?= au_e($au_fz['tueren_namen']) ?></span>
<?php } ?>
<?php if (isset($au_fz['zuhause']) && $au_fz['zuhause'] !== null) { ?>
&middot; <?= $au_fz['zuhause'] ? au_e(au_t('ALLG.ZUHAUSE_JA')) : au_e(au_t('ALLG.ZUHAUSE_NEIN')) ?>
<?php if (isset($au_fz['entfernung_m']) && $au_fz['entfernung_m'] !== null) { ?>
(<?= (int) $au_fz['entfernung_m'] ?> m)
<?php } } ?>
<div style="margin-top:8px;"><?= au_soc_svg(au_verlauf_lesen((int) $au_nr, $au_vtag), $au_vtag) ?></div>
<div class="sm-hilfe"><?= au_e(au_t('ALLG.VERLAUF_HINWEIS')) ?>
<?php $au_tage = au_verlauf_tage((int) $au_nr); if (count($au_tage) > 1) { ?>
<br><?= au_e(au_t('ALLG.TAG_WAEHLEN')) ?>
<?php foreach (array_slice($au_tage, 0, 14) as $au_t1) { ?>
<a href="index.php?form=settings&amp;tag=<?= au_e($au_t1) ?>"<?= $au_t1 === $au_vtag ? ' style="font-weight:700;"' : '' ?>><?= au_e(substr($au_t1, 6, 2) . '.' . substr($au_t1, 4, 2) . '.') ?></a>
<?php } } ?>
</div>
<?php if (!empty($au_fz['ausfaelle']) && is_array($au_fz['ausfaelle'])) { ?>
<div class="sm-hilfe"><b><?= au_e(au_t('ALLG.AUSFAELLE')) ?></b>
<?php foreach ($au_fz['ausfaelle'] as $au_ep => $au_gr) { ?>
<br><span class="sm-mono"><?= au_e($au_ep) ?></span>: <?= au_e($au_gr) ?>
<?php } ?>
</div>
<?php } ?>
</div>
<?php } ?>

<!-- Reiterleiste: echte Links, JavaScript faengt den Klick ab. So bleibt jeder
     Reiter verlinkbar, Eingaben in anderen Reitern gehen nicht verloren, und
     faellt das Skript aus, ist die Seite weiterhin bedienbar.

     WELCHER REITER OFFEN IST, ENTSCHEIDET DER SERVER. Die Klasse sm-active
     steht deshalb AUCH hier und an jedem Bereich - bis 0.9.7 tat sie das
     nicht, und weil .sm-seite auf display:none steht, war die Seite ohne
     JavaScript vollstaendig leer. Nur die Reiterleiste war zu sehen. -->
<div class="sm-tabs">
<?php foreach ($au_reiter as $au_k => $au_bez) { ?>
	<a class="sm-tab<?= $au_tab === 'tab-' . $au_k ? ' sm-active' : '' ?>" data-ziel="tab-<?= au_e($au_k) ?>"
	   href="index.php?form=<?= au_e($au_k) ?>"><?= au_e($au_bez === 'MQTT' ? 'MQTT' : au_t($au_bez)) ?></a>
<?php } ?>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="sm-seite<?= $au_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">

<?php if ($au_pyv !== '' && version_compare($au_pyv, '3.9.0', '<')) { ?>
<div class="sm-fehler"><?= au_t('EINST.PYTHON_ZU_ALT') ?></div>
<?php } ?>

<h2><?= au_e(au_t('EINST.H_DIENST')) ?></h2>
<p class="sm-hilfe"><?= au_t('EINST.DIENST_ERKLAERUNG') ?></p>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= au_t('LEGENDE.LESEN') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= au_t('LEGENDE.AKTION') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <?php au_formfelder('tab-settings', $au_ftoken); ?>
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="dienst" value="start"><?= au_e(au_t('EINST.K_START')) ?></button>
  </form>
  <form action="index.php" method="post">
    <?php au_formfelder('tab-settings', $au_ftoken); ?>
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="dienst" value="restart"><?= au_e(au_t('EINST.K_NEUSTART')) ?></button>
  </form>
  <form action="index.php" method="post">
    <?php au_formfelder('tab-settings', $au_ftoken); ?>
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="dienst" value="stop"><?= au_e(au_t('EINST.K_STOPP')) ?></button>
  </form>
  <form action="index.php" method="post">
    <?php au_formfelder('tab-settings', $au_ftoken); ?>
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="sitzung_verwerfen" value="1"><?= au_e(au_t('EINST.K_SITZUNG')) ?></button>
  </form>
</div>

<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="speichern" value="1">
<?php au_formfelder('tab-settings', $au_ftoken); ?>

<h2><?= au_e(au_t('EINST.H_KONTO')) ?></h2>
<div class="sm-warnung"><?= au_t('EINST.KONTO_ERKLAERUNG') ?></div>
<div class="sm-feld">
  <label for="email"><?= au_e(au_t('EINST.L_EMAIL')) ?></label>
  <input data-role="none" type="text" id="email" name="email" value="<?= au_e($au_zg['email']) ?>" placeholder="name@example.com">
  <div class="sm-hilfe"><?= au_t('EINST.H_EMAIL') ?></div>
</div>
<div class="sm-feld">
  <label for="passwort"><?= au_e(au_t('EINST.L_PASSWORT')) ?></label>
  <input data-role="none" type="password" id="passwort" name="passwort" value="" placeholder="<?= $au_zg['laenge'] > 0 ? au_e(sprintf(au_t('EINST.PW_GESETZT'), $au_zg['laenge'])) : au_e(au_t('EINST.PW_LEER')) ?>">
  <div class="sm-hilfe"><?= au_t('EINST.H_PASSWORT') ?></div>
</div>
<div class="sm-feld">
  <label for="spin"><?= au_e(au_t('EINST.L_SPIN')) ?></label>
  <input data-role="none" type="password" id="spin" name="spin" value="" maxlength="4" placeholder="<?= $au_zg['spin_laenge'] > 0 ? au_e(au_t('EINST.SPIN_GESETZT')) : au_e(au_t('EINST.SPIN_LEER')) ?>">
  <div class="sm-hilfe"><?= au_t('EINST.H_SPIN') ?></div>
</div>
<div class="sm-hinweis"><?= au_t('EINST.SITZUNG_ERKLAERUNG') ?></div>

<h2><?= au_e(au_t('EINST.H_TAKT')) ?></h2>
<div class="sm-warnung"><?= au_t('EINST.TAKT_WARNUNG') ?></div>
<div class="sm-feld">
  <label for="intervall"><?= au_e(au_t('EINST.L_INTERVALL')) ?></label>
  <input data-role="none" type="number" id="intervall" name="intervall" value="<?= (int) $au_cfg['intervall'] ?>" min="180" max="3600">
  <div class="sm-hilfe"><?= au_t('EINST.H_INTERVALL') ?></div>
</div>
<div class="sm-feld">
  <label for="takt_wartung"><?= au_e(au_t('EINST.L_TAKT_WARTUNG')) ?></label>
  <input data-role="none" type="number" id="takt_wartung" name="takt_wartung" value="<?= (int) $au_cfg['takt_wartung'] ?>" min="1" max="240">
  <div class="sm-hilfe"><?= au_t('EINST.H_TAKT_WARTUNG') ?></div>
</div>
<div class="sm-feld">
  <label for="verlauf_tage"><?= au_e(au_t('EINST.L_VERLAUF_TAGE')) ?></label>
  <input data-role="none" type="number" id="verlauf_tage" name="verlauf_tage" value="<?= (int) $au_cfg['verlauf_tage'] ?>" min="1" max="90">
  <div class="sm-hilfe"><?= au_t('EINST.H_VERLAUF_TAGE') ?></div>
</div>

<h2><?= au_e(au_t('EINST.H_STEUERUNG')) ?></h2>
<div class="sm-warnung"><?= au_t('EINST.STEUERUNG_ERKLAERUNG') ?></div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="steuerung_ein" value="1" <?= !empty($au_cfg['steuerung_ein']) ? 'checked' : '' ?>>
    <?= au_e(au_t('EINST.L_STEUERUNG_EIN')) ?>
  </label>
</div>
<div class="sm-fehler"><?= au_t('EINST.GEFAHR_ERKLAERUNG') ?></div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="gefahr_ein" value="1" <?= !empty($au_cfg['gefahr_ein']) ? 'checked' : '' ?>>
    <?= au_e(au_t('EINST.L_GEFAHR_EIN')) ?>
  </label>
  <div class="sm-hilfe"><?= au_t('EINST.H_GEFAHR_EIN') ?></div>
</div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="probe_ein" value="1" <?= !empty($au_cfg['probe_ein']) ? 'checked' : '' ?>>
    <?= au_e(au_t('EINST.L_PROBE_EIN')) ?>
  </label>
  <div class="sm-hilfe"><?= au_t('EINST.H_PROBE_EIN') ?></div>
</div>
<div class="sm-feld">
  <label for="temp_min"><?= au_e(au_t('EINST.L_TEMP_MIN')) ?></label>
  <input data-role="none" type="number" id="temp_min" name="temp_min" value="<?= (int) $au_cfg['temp_min'] ?>" min="10" max="30">
</div>
<div class="sm-feld">
  <label for="temp_max"><?= au_e(au_t('EINST.L_TEMP_MAX')) ?></label>
  <input data-role="none" type="number" id="temp_max" name="temp_max" value="<?= (int) $au_cfg['temp_max'] ?>" min="10" max="30">
  <div class="sm-hilfe"><?= au_t('EINST.H_TEMP') ?></div>
</div>
<div class="sm-feld">
  <label for="wartezeit"><?= au_e(au_t('EINST.L_WARTEZEIT')) ?></label>
  <input data-role="none" type="number" id="wartezeit" name="wartezeit" value="<?= (int) $au_cfg['wartezeit'] ?>" min="0" max="30">
  <div class="sm-hilfe"><?= au_t('EINST.H_WARTEZEIT') ?></div>
</div>

<h2><?= au_e(au_t('EINST.H_DROSSEL')) ?></h2>
<div class="sm-warnung"><?= au_t('EINST.DROSSEL_ERKLAERUNG') ?></div>
<div class="sm-feld">
  <label for="abruf_abstand"><?= au_e(au_t('EINST.L_ABRUF_ABSTAND')) ?></label>
  <input data-role="none" type="number" id="abruf_abstand" name="abruf_abstand" value="<?= (int) $au_cfg['abruf_abstand'] ?>" min="0" max="3600">
  <div class="sm-hilfe"><?= au_t('EINST.H_ABRUF_ABSTAND') ?></div>
</div>
<div class="sm-feld">
  <label for="befehle_stunde"><?= au_e(au_t('EINST.L_BEFEHLE_STUNDE')) ?></label>
  <input data-role="none" type="number" id="befehle_stunde" name="befehle_stunde" value="<?= (int) $au_cfg['befehle_stunde'] ?>" min="0" max="500">
  <div class="sm-hilfe"><?= au_t('EINST.H_BEFEHLE_STUNDE') ?></div>
</div>
<div class="sm-feld">
  <label for="strom_abstand"><?= au_e(au_t('EINST.L_STROM_ABSTAND')) ?></label>
  <input data-role="none" type="number" id="strom_abstand" name="strom_abstand" value="<?= (int) $au_cfg['strom_abstand'] ?>" min="0" max="3600">
  <div class="sm-hilfe"><?= au_t('EINST.H_STROM_ABSTAND') ?></div>
</div>

<h2><?= au_e(au_t('EINST.H_DATENSCHUTZ')) ?></h2>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="gps_ein" value="1" <?= !empty($au_cfg['gps_ein']) ? 'checked' : '' ?>>
    <?= au_e(au_t('EINST.L_GPS_EIN')) ?>
  </label>
  <div class="sm-hilfe"><?= au_t('EINST.H_GPS_EIN') ?></div>
</div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="nur_miniserver" value="1" <?= !empty($au_cfg['nur_miniserver']) ? 'checked' : '' ?>>
    <?= au_e(au_t('EINST.L_NUR_MINISERVER')) ?>
  </label>
  <div class="sm-hilfe"><?= au_t('EINST.H_NUR_MINISERVER') ?>
  <?php $au_ms = au_miniserver_adressen(); if ($au_ms) { ?>
  <br><span class="sm-mono"><?= au_e(implode(', ', $au_ms)) ?></span>
  <?php } else { ?>
  <br><span class="sm-leer"><?= au_e(au_t('EINST.KEIN_MINISERVER')) ?></span>
  <?php } ?>
  </div>
</div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="melden_ein" value="1" <?= !empty($au_cfg['melden_ein']) ? 'checked' : '' ?>>
    <?= au_e(au_t('EINST.L_MELDEN_EIN')) ?>
  </label>
  <div class="sm-hilfe"><?= au_t('EINST.H_MELDEN_EIN') ?></div>
</div>

<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= au_e(au_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>

<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= au_t('LEGENDE.AKTION') ?></span></div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <?php au_formfelder('tab-settings', $au_ftoken); ?>
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="zugang_loeschen" value="1"><?= au_e(au_t('EINST.K_ZUGANG_LOESCHEN')) ?></button>
  </form>
</div>

<!-- ---------------- Automatik: eigenes Formular, eigener Handler ---------------- -->
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="save_automatik" value="1">
<?php au_formfelder('tab-settings', $au_ftoken); ?>

<h2><?= au_e(au_t('EINST.H_GERECHNET')) ?></h2>
<p class="sm-hilfe"><?= au_t('EINST.GERECHNET_ERKLAERUNG') ?></p>
<div class="sm-feld">
  <label for="kapazitaet"><?= au_e(au_t('EINST.L_KAPAZITAET')) ?></label>
  <input data-role="none" type="number" id="kapazitaet" name="kapazitaet" value="<?= (int) $au_cfg['kapazitaet'] ?>" min="0" max="500">
  <div class="sm-hilfe"><?= au_t('EINST.H_KAPAZITAET') ?></div>
</div>
<div class="sm-feld">
  <label for="heim_breite"><?= au_e(au_t('EINST.L_HEIM_BREITE')) ?></label>
  <input data-role="none" type="text" id="heim_breite" name="heim_breite" value="<?= au_e($au_cfg['heim_breite']) ?>" placeholder="48.137">
</div>
<div class="sm-feld">
  <label for="heim_laenge"><?= au_e(au_t('EINST.L_HEIM_LAENGE')) ?></label>
  <input data-role="none" type="text" id="heim_laenge" name="heim_laenge" value="<?= au_e($au_cfg['heim_laenge']) ?>" placeholder="11.575">
</div>
<div class="sm-feld">
  <label for="heim_radius"><?= au_e(au_t('EINST.L_HEIM_RADIUS')) ?></label>
  <input data-role="none" type="number" id="heim_radius" name="heim_radius" value="<?= (int) $au_cfg['heim_radius'] ?>" min="20" max="5000">
  <div class="sm-hilfe"><?= au_t('EINST.H_HEIM') ?></div>
</div>

<h2><?= au_e(au_t('EINST.H_ABFAHRT')) ?></h2>
<div class="sm-warnung"><?= au_t('EINST.ABFAHRT_ERKLAERUNG') ?></div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="abfahrt_ein" value="1" <?= !empty($au_cfg['abfahrt_ein']) ? 'checked' : '' ?>>
    <?= au_e(au_t('EINST.L_ABFAHRT_EIN')) ?>
  </label>
</div>
<div class="sm-feld">
  <label for="abfahrt_praefix"><?= au_e(au_t('EINST.L_ABFAHRT_PRAEFIX')) ?></label>
  <input data-role="none" type="text" id="abfahrt_praefix" name="abfahrt_praefix" value="<?= au_e($au_cfg['abfahrt_praefix']) ?>" placeholder="abfahrt">
  <div class="sm-hilfe"><?= au_t('EINST.H_ABFAHRT_PRAEFIX') ?></div>
</div>
<div class="sm-feld">
  <label for="abfahrt_vorlauf"><?= au_e(au_t('EINST.L_ABFAHRT_VORLAUF')) ?></label>
  <input data-role="none" type="number" id="abfahrt_vorlauf" name="abfahrt_vorlauf" value="<?= (int) $au_cfg['abfahrt_vorlauf'] ?>" min="1" max="120">
  <div class="sm-hilfe"><?= au_t('EINST.H_ABFAHRT_VORLAUF') ?></div>
</div>
<div class="sm-feld">
  <label for="abfahrt_temp"><?= au_e(au_t('EINST.L_ABFAHRT_TEMP')) ?></label>
  <input data-role="none" type="number" id="abfahrt_temp" name="abfahrt_temp" value="<?= (int) $au_cfg['abfahrt_temp'] ?>" min="10" max="30">
</div>
<div class="sm-feld">
  <label for="abfahrt_fahrzeug"><?= au_e(au_t('EINST.L_ABFAHRT_FAHRZEUG')) ?></label>
  <input data-role="none" type="number" id="abfahrt_fahrzeug" name="abfahrt_fahrzeug" value="<?= (int) $au_cfg['abfahrt_fahrzeug'] ?>" min="1" max="99">
  <div class="sm-hilfe"><?= au_t('EINST.H_ABFAHRT_FAHRZEUG') ?></div>
</div>
<div class="sm-feld">
  <label for="abfahrt_alter"><?= au_e(au_t('EINST.L_ABFAHRT_ALTER')) ?></label>
  <input data-role="none" type="number" id="abfahrt_alter" name="abfahrt_alter" value="<?= (int) $au_cfg['abfahrt_alter'] ?>" min="60" max="3600">
  <div class="sm-hilfe"><?= au_t('EINST.H_ABFAHRT_ALTER') ?></div>
</div>

<h2><?= au_e(au_t('EINST.H_LADEEMPF')) ?></h2>
<div class="sm-warnung"><?= au_t('EINST.LADEEMPF_ERKLAERUNG') ?></div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="ladeempf_ein" value="1" <?= !empty($au_cfg['ladeempf_ein']) ? 'checked' : '' ?>>
    <?= au_e(au_t('EINST.L_LADEEMPF_EIN')) ?>
  </label>
</div>
<div class="sm-feld">
  <label for="ladeempf_thema"><?= au_e(au_t('EINST.L_LADEEMPF_THEMA')) ?></label>
  <input data-role="none" type="text" id="ladeempf_thema" name="ladeempf_thema" value="<?= au_e($au_cfg['ladeempf_thema']) ?>" placeholder="awattar/preis_jetzt">
  <div class="sm-hilfe"><?= au_t('EINST.H_LADEEMPF_THEMA') ?></div>
</div>
<div class="sm-feld">
  <label for="ladeempf_grenze"><?= au_e(au_t('EINST.L_LADEEMPF_GRENZE')) ?></label>
  <input data-role="none" type="text" id="ladeempf_grenze" name="ladeempf_grenze" value="<?= au_e($au_cfg['ladeempf_grenze']) ?>" placeholder="0">
  <div class="sm-hilfe"><?= au_t('EINST.H_LADEEMPF_GRENZE') ?></div>
</div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="ladeempf_unter" value="1" <?= !empty($au_cfg['ladeempf_unter']) ? 'checked' : '' ?>>
    <?= au_e(au_t('EINST.L_LADEEMPF_UNTER')) ?>
  </label>
  <div class="sm-hilfe"><?= au_t('EINST.H_LADEEMPF_UNTER') ?></div>
</div>
<div class="sm-feld">
  <label for="ladeempf_alter"><?= au_e(au_t('EINST.L_LADEEMPF_ALTER')) ?></label>
  <input data-role="none" type="number" id="ladeempf_alter" name="ladeempf_alter" value="<?= (int) $au_cfg['ladeempf_alter'] ?>" min="60" max="86400">
  <div class="sm-hilfe"><?= au_t('EINST.H_LADEEMPF_ALTER') ?></div>
</div>

<?php if (au_horcher_themen($au_cfg)) { ?>
<div class="<?= ($au_horcher['fehler'] === '' && $au_horcher['verbunden']) ? 'sm-hinweis' : 'sm-fehler' ?>">
<b><?= au_e(au_t('EINST.HORCHER_ZUSTAND')) ?></b>
<?php if ($au_horcher['fehler'] !== '') { ?>
<?= au_e($au_horcher['fehler']) ?>
<?php } elseif (!$au_horcher['verbunden']) { ?>
<?= au_t('EINST.HORCHER_AUS') ?>
<?php } else { ?>
<?= au_e(implode(', ', $au_horcher['themen'])) ?>
<?php } ?>
<?php if ($au_fassungen['paho'] === '') { ?>
<br><?= au_t('EINST.PAHO_FEHLT') ?>
<?php } ?>
</div>
<?php } ?>

<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= au_e(au_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>

<h2><?= au_e(au_t('EINST.H_ERKANNT')) ?></h2>
<?php if (!$au_fahrzeuge) { ?>
<div class="sm-warnung"><?= au_t('EINST.KEINE_FAHRZEUGE') ?></div>
<?php } else { ?>
<table class="sm-tbl">
<tr><th><?= au_e(au_t('EINST.T_NR')) ?></th><th><?= au_e(au_t('EINST.T_MODELL')) ?></th>
    <th><?= au_e(au_t('EINST.T_KENNZEICHEN')) ?></th><th><?= au_e(au_t('EINST.T_VIN')) ?></th>
    <th><?= au_e(au_t('EINST.T_ANTRIEB')) ?></th><th><?= au_e(au_t('EINST.T_BATTERIE')) ?></th>
    <th><?= au_e(au_t('EINST.T_SOFTWARE')) ?></th></tr>
<?php foreach ($au_fahrzeuge as $au_nr => $au_fz) { ?>
<tr><td><?= au_e($au_nr) ?></td><td><?= au_e($au_fz['modell']) ?></td>
    <td><?= empty($au_fz['kennzeichen']) ? '<span class="sm-leer">' . au_e(au_t('EINST.NICHT_GELIEFERT')) . '</span>' : au_e($au_fz['kennzeichen']) ?></td>
    <td><span class="sm-mono"><?= au_e($au_fz['vin']) ?></span></td>
    <td><?= au_e($au_fz['antriebsart']) ?></td>
    <td><?= empty($au_fz['batterie_kwh']) ? '<span class="sm-leer">' . au_e(au_t('EINST.NICHT_GELIEFERT')) . '</span>' : au_e($au_fz['batterie_kwh']) . ' kWh' ?></td>
    <td><?= empty($au_fz['software']) ? '<span class="sm-leer">' . au_e(au_t('EINST.NICHT_GELIEFERT')) . '</span>' : au_e($au_fz['software']) ?></td></tr>
<?php } ?>
</table>
<p class="sm-hilfe"><?= au_t('EINST.VIN_HINWEIS') ?></p>
<div class="sm-warnung"><?= au_t('EINST.NICHT_GELIEFERT_ERKLAERUNG') ?></div>
<?php } ?>
</div>

<!-- ================= Reiter: MQTT ================= -->
<div class="sm-seite<?= $au_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" id="tab-mqtt">

<h2>MQTT</h2>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="save_mqtt" value="1">
<?php au_formfelder('tab-mqtt', $au_ftoken); ?>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="mqtt_ein" value="1" <?= !empty($au_cfg['mqtt_ein']) ? 'checked' : '' ?>>
    <?= au_e(au_t('EINST.L_MQTT_EIN')) ?>
  </label>
</div>
<div class="sm-feld">
  <label for="mqtt_topic"><?= au_e(au_t('EINST.L_MQTT_TOPIC')) ?></label>
  <input data-role="none" type="text" id="mqtt_topic" name="mqtt_topic" value="<?= au_e($au_cfg['mqtt_topic']) ?>" placeholder="audi">
  <div class="sm-hilfe"><?= au_t('EINST.H_MQTT_TOPIC') ?></div>
</div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= au_t('LEGENDE.AKTION') ?></span></div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= au_e(au_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>
<h2><?= au_e(au_t('MQTT.H_ZUSTAND')) ?></h2>
<p class="sm-hilfe"><?= au_t('MQTT.GATEWAY_ERKLAERUNG') ?></p>

<?php if (!$au_mqtt['gefunden']) { ?>
<div class="sm-fehler"><?= au_t('MQTT.NICHT_GEFUNDEN') ?></div>
<?php } elseif (!$au_mqtt['autostart']) { ?>
<div class="sm-fehler"><?= au_t('MQTT.AUTOSTART_AUS') ?></div>
<?php } else { ?>
<div class="sm-hinweis"><?= au_t('MQTT.AUTOSTART_EIN') ?></div>
<?php } ?>

<table class="sm-tbl">
<tr><th><?= au_e(au_t('ALLG.EIGENSCHAFT')) ?></th><th><?= au_e(au_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= au_e(au_t('MQTT.T_AUTOSTART')) ?></td><td class="<?= $au_mqtt['autostart'] ? 'sm-an' : 'sm-aus' ?>"><?= $au_mqtt['autostart'] ? au_e(au_t('ALLG.EIN')) : au_e(au_t('ALLG.AUS')) ?></td></tr>
<tr><td><?= au_e(au_t('MQTT.T_BROKER')) ?></td><td><span class="sm-mono"><?= au_e($au_mqtt['broker']) ?>:<?= au_e($au_mqtt['brokerport']) ?></span></td></tr>
<tr><td><?= au_e(au_t('MQTT.T_UDP')) ?></td><td><span class="sm-mono"><?= (int) $au_mqtt['udpport'] ?></span></td></tr>
<tr><td><?= au_e(au_t('MQTT.T_PLUGIN')) ?></td><td class="<?= !empty($au_cfg['mqtt_ein']) ? 'sm-an' : 'sm-aus' ?>"><?= !empty($au_cfg['mqtt_ein']) ? au_e(au_t('ALLG.EIN')) : au_e(au_t('ALLG.AUS')) ?></td></tr>
<tr><td><?= au_e(au_t('MQTT.T_HORCHER')) ?></td>
    <td><?= au_horcher_themen($au_cfg)
        ? au_e(implode(', ', $au_horcher['themen'])) . ($au_horcher['verbunden'] ? '' : ' (' . au_e(au_t('EINST.HORCHER_AUS')) . ')')
        : '<span class="sm-leer">' . au_e(au_t('MQTT.HORCHER_KEINE')) . '</span>' ?></td></tr>
</table>

<h2><?= au_e(au_t('MQTT.H_ABO')) ?></h2>
<div class="sm-warnung"><?= au_t('MQTT.ABO_WARNUNG') ?></div>
<div class="sm-step">
<?= au_t('MQTT.ABO_SCHRITTE') ?>
<p><span class="sm-mono"><?= au_e($au_cfg['mqtt_topic']) ?>/#</span></p>
</div>

<h2><?= au_e(au_t('MQTT.H_THEMEN')) ?></h2>
<p class="sm-hilfe"><?= au_t('MQTT.THEMEN_ERKLAERUNG') ?></p>
<table class="sm-tbl">
<tr><th><?= au_e(au_t('MQTT.T_THEMA')) ?></th><th><?= au_e(au_t('MQTT.T_BEDEUTUNG')) ?></th></tr>
<?php foreach (au_mqtt_themen() as $au_thema => $au_schluessel) { ?>
<tr><td><span class="sm-mono"><?= au_e($au_cfg['mqtt_topic'] . '/' . $au_thema) ?></span></td>
    <td><?= au_t($au_schluessel) ?></td></tr>
<?php } ?>
</table>
<p class="sm-hilfe"><?= au_t('MQTT.PLATZHALTER') ?></p>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="sm-seite<?= $au_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">
<h2><?= au_e(au_t('LOX.H_TITEL')) ?></h2>
<p><?= au_t('LOX.EINLEITUNG') ?></p>

<div class="sm-step"><b><?= au_e(au_t('LOX.S1_TITEL')) ?></b><br>
<?= au_t('LOX.S1_TEXT') ?>
</div>

<div class="sm-step"><b><?= au_e(au_t('LOX.S2_TITEL')) ?></b><br>
<?= au_t('LOX.S2_TEXT') ?>
<p><span class="sm-mono"><?= au_e($au_cfg['mqtt_topic']) ?>/#</span></p>
<div class="sm-warnung"><?= au_t('LOX.S2_WARNUNG') ?></div>
</div>

<div class="sm-step"><b><?= au_e(au_t('LOX.S3_TITEL')) ?></b><br>
<?= au_t('LOX.S3_TEXT') ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= au_t('LEGENDE.LESEN') ?></span>
</div>
<?php
/* Je Endpunkt ein Kasten: Adresse, Feldtabelle und ein Knopf, der genau
 * diese Vorlage erzeugt. Bis 0.9.7 gab es nur den Statusknopf - die uebrigen
 * drei Endpunkte hatten Tabellen ohne Vorlage. */
$au_endpunkte = array(
    'status'   => array('AUDI',     'LOX.EP_STATUS'),
    'laden'    => array('LADEN',    'LOX.EP_LADEN'),
    'wartung'  => array('WARTUNG',  'LOX.EP_WARTUNG'),
    'position' => array('POSITION', 'LOX.EP_POSITION'),
);
$au_nummern = $au_fahrzeuge ? array_keys($au_fahrzeuge) : array('1');
foreach ($au_endpunkte as $au_art => $au_info) {
?>
<h3><?= au_e(au_t($au_info[1])) ?></h3>
<table class="sm-tbl">
<tr><th><?= au_e(au_t('ALLG.EIGENSCHAFT')) ?></th><th><?= au_e(au_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= au_e(au_t('LOX.T_ADRESSE')) ?></td>
    <td><span class="sm-mono"><?= au_e($au_basis) ?>?token=<?= au_e($au_token) ?>&amp;aktion=<?= au_e($au_art) ?>&amp;fahrzeug=1</span></td></tr>
<tr><td><?= au_e(au_t('LOX.T_ZYKLUS')) ?></td><td><?= (int) $au_cfg['intervall'] ?> <?= au_e(au_t('ALLG.SEKUNDEN')) ?></td></tr>
</table>
<table class="sm-tbl">
<tr><th><?= au_e(au_t('LOX.T_TITEL')) ?></th><th><?= au_e(au_t('LOX.T_BEFEHL')) ?></th>
    <th><?= au_e(au_t('LOX.T_EINHEIT')) ?></th><th><?= au_e(au_t('LOX.T_HERKUNFT')) ?></th>
    <th><?= au_e(au_t('LOX.T_BEDEUTUNG')) ?></th></tr>
<?php foreach (au_felder_von($au_art) as $au_feld => $au_fi) { ?>
<tr><td><span class="sm-mono">AUDI_1_<?= au_e($au_feld) ?></span></td>
    <td><span class="sm-mono"><?= $au_feld === 'FEHLERTEXT' ? '&mdash;' : au_e(au_check($au_feld)) ?></span></td>
    <td><?= $au_fi['einheit'] ?></td>
    <td<?= $au_fi['herkunft'] === 'leer' ? ' class="sm-leer"' : '' ?>><?= au_e(au_herkunft_text($au_fi['herkunft'])) ?></td>
    <td><?= au_t($au_fi['bez']) ?></td></tr>
<?php } ?>
</table>
<div class="sm-knopfreihe">
<?php foreach ($au_nummern as $au_nr2) { ?>
  <form action="index.php" method="post">
    <?php au_formfelder('tab-loxone', $au_ftoken); ?>
    <input data-role="none" type="hidden" name="vorlage_art" value="<?= au_e($au_art) ?>">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="vorlage" value="<?= au_e($au_nr2) ?>"><?= au_e(sprintf(au_t('LOX.K_VORLAGE_N'), au_t($au_info[1]), $au_nr2)) ?></button>
  </form>
<?php } ?>
</div>
<?php } ?>
<div class="sm-warnung"><?= au_t('LOX.S3_STRICH') ?></div>
<div class="sm-hinweis"><?= au_t('LOX.S3_ADRESSE_ZEILE') ?></div>
<?php if (count($au_fahrzeuge) > 1) { ?>
<p><b><?= au_e(au_t('LOX.MEHRERE_FAHRZEUGE')) ?></b></p>
<table class="sm-tbl">
<tr><th><?= au_e(au_t('ALLG.FAHRZEUG')) ?></th><th><?= au_e(au_t('EINST.T_MODELL')) ?></th><th><?= au_e(au_t('LOX.T_ADRESSE')) ?></th></tr>
<?php foreach ($au_fahrzeuge as $au_nr => $au_fz) { ?>
<tr><td><?= au_e($au_nr) ?></td><td><?= au_e($au_fz['modell']) ?></td>
    <td><span class="sm-mono"><?= au_e($au_basis) ?>?token=<?= au_e($au_token) ?>&amp;aktion=status&amp;fahrzeug=<?= au_e($au_nr) ?></span></td></tr>
<?php } ?>
</table>
<?php } ?>
</div>

<div class="sm-step"><b><?= au_e(au_t('LOX.S4_TITEL')) ?></b><br>
<?= au_t('LOX.S4_TEXT') ?>
<table class="sm-tbl">
<tr><th><?= au_e(au_t('ALLG.EIGENSCHAFT')) ?></th><th><?= au_e(au_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= au_e(au_t('LOX.T_ADRESSE')) ?></td>
    <td><span class="sm-mono"><?= au_e($au_basis) ?>?token=<?= au_e($au_token) ?>&amp;aktion=text&amp;fahrzeug=1</span></td></tr>
</table>
</div>

<div class="sm-step"><b><?= au_e(au_t('LOX.S5_TITEL')) ?></b><br>
<?= au_t('LOX.S5_TEXT') ?>
<table class="sm-tbl">
<tr><th><?= au_e(au_t('ALLG.EIGENSCHAFT')) ?></th><th><?= au_e(au_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= au_e(au_t('LOX.T_VA_ADRESSE')) ?></td><td><span class="sm-mono">http://<?= au_e($au_host) ?></span></td></tr>
</table>
<table class="sm-tbl">
<tr><th><?= au_e(au_t('LOX.T_BEFEHL')) ?></th><th><?= au_e(au_t('LOX.T_ZIEL')) ?></th></tr>
<?php foreach (au_befehle() as $au_ak => $au_eig) {
    if ($au_ak === 'einstellung') { continue; }
    if ($au_eig['gefahr'] && empty($au_cfg['gefahr_ein'])) { continue; }
    $au_z = '/plugins/' . $au_p['plugin'] . '/index.php?token=' . $au_stoken
          . '&amp;aktion=' . $au_ak . ($au_eig['ohne_fz'] ? '' : '&amp;fahrzeug=1');
    if ($au_eig['zusatz'] === 'temp')    { $au_z .= '&amp;temp=&lt;v&gt;'; }
    if ($au_eig['zusatz'] === 'prozent') { $au_z .= '&amp;prozent=&lt;v&gt;'; }
    if ($au_eig['zusatz'] === 'ampere')  { $au_z .= '&amp;ampere=&lt;v&gt;'; }
    if ($au_eig['zusatz'] === 'dauer')   { $au_z .= '&amp;dauer=10'; }
?>
<tr><td><?= au_t($au_eig['bez']) ?><?= $au_eig['gefahr'] ? ' <b>(' . au_e(au_t('LOX.EINGRIFF')) . ')</b>' : '' ?></td>
    <td><span class="sm-mono"><?= $au_z ?></span></td></tr>
<?php } ?>
<?php foreach (au_einstellungen() as $au_en => $au_eb) { ?>
<tr><td><?= au_t($au_eb) ?></td>
    <td><span class="sm-mono">/plugins/<?= au_e($au_p['plugin']) ?>/index.php?token=<?= au_e($au_stoken) ?>&amp;aktion=einstellung&amp;fahrzeug=1&amp;name=<?= au_e($au_en) ?>&amp;wert=1</span></td></tr>
<?php } ?>
</table>
<div class="sm-warnung"><?= au_t('LOX.S5_WARNUNG') ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= au_t('LEGENDE.LESEN') ?></span>
</div>
<div class="sm-knopfreihe">
<?php foreach ($au_nummern as $au_nr2) { ?>
  <form action="index.php" method="post">
    <?php au_formfelder('tab-loxone', $au_ftoken); ?>
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="vorlage_vo" value="<?= au_e($au_nr2) ?>"><?= au_e(sprintf(au_t('LOX.K_VORLAGE_VO'), $au_nr2)) ?></button>
  </form>
<?php } ?>
</div>
</div>

<div class="sm-step"><b><?= au_e(au_t('LOX.S6_TITEL')) ?></b><br>
<table class="sm-tbl">
<tr><th><?= au_e(au_t('ALLG.EIGENSCHAFT')) ?></th><th><?= au_e(au_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= au_e(au_t('LOX.T_TOKEN_LESEN')) ?></td><td><span class="sm-mono"><?= au_e($au_token) ?></span></td></tr>
<tr><td><?= au_e(au_t('LOX.T_TOKEN_SCHALTEN')) ?></td><td><span class="sm-mono"><?= au_e($au_stoken) ?></span></td></tr>
</table>
<?= au_t('LOX.S6_TEXT') ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= au_t('LEGENDE.AKTION_TOKEN') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <?php au_formfelder('tab-loxone', $au_ftoken); ?>
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="token_neu" value="lesen"><?= au_e(au_t('LOX.K_TOKEN_LESEN')) ?></button>
  </form>
  <form action="index.php" method="post">
    <?php au_formfelder('tab-loxone', $au_ftoken); ?>
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="token_neu" value="schalten"><?= au_e(au_t('LOX.K_TOKEN_SCHALTEN')) ?></button>
  </form>
</div>
</div>

<div class="sm-step"><b><?= au_e(au_t('LOX.S7_TITEL')) ?></b><br>
<?= au_t('LOX.S7_TEXT') ?>
</div>

<?php
/**
 * Die komplette Baustein-Liste. Pflicht im Hausstandard.
 *
 * Anspruch: Wer die Tabelle von oben nach unten abarbeitet, hat die Funktion
 * nachgebaut, ohne nachzudenken. Loxone Config fuehrt alle Bausteine in der
 * Baustein-Suche (F5).
 *
 * Je Zeile: Nummer, Typ, Name, Parameter, woran die Eingaenge kommen.
 * Typ, Name und Parameter stehen als Sprachschluessel drin, die Eingangsspalte
 * ist symbolisch und damit sprachfrei.
 *
 * ACHTUNG bei UND und ODER: die Zahl der Eingaenge ist eine Eigenschaft des
 * Bausteins, die Loxone Config selbst setzt. Wer einen dritten Eingang
 * aufzieht, verliert beim naechsten Oeffnen der Datei alle Verbindungen, die
 * daran hingen - ohne Meldung. An einem ODER duerfen deshalb mehrere Quellen
 * an EINEM Eingang haengen (sie werden dort ODER-verknuepft); an einem UND
 * waere dieselbe Form falsch, weil sie das UND still in ein ODER verwandelte.
 */
function au_bausteine()
{
    return array(
        array(1,  'BAUSTEIN.T_VE',      'BAUSTEIN.N01', 'BAUSTEIN.P01', '&mdash;'),
        array(2,  'BAUSTEIN.T_VE',      'BAUSTEIN.N02', 'BAUSTEIN.P02', '&mdash;'),
        array(3,  'BAUSTEIN.T_VE',      'BAUSTEIN.N03', 'BAUSTEIN.P03', '&mdash;'),
        array(4,  'BAUSTEIN.T_VE',      'BAUSTEIN.N04', 'BAUSTEIN.P04', '&mdash;'),
        array(5,  'BAUSTEIN.T_VE',      'BAUSTEIN.N05', 'BAUSTEIN.P05', '&mdash;'),
        array(6,  'BAUSTEIN.T_VE',      'BAUSTEIN.N06', 'BAUSTEIN.P06', '&mdash;'),
        array(7,  'BAUSTEIN.T_VE',      'BAUSTEIN.N07', 'BAUSTEIN.P07', '&mdash;'),
        array(8,  'BAUSTEIN.T_VE',      'BAUSTEIN.N08', 'BAUSTEIN.P08', '&mdash;'),
        array(9,  'BAUSTEIN.T_VE',      'BAUSTEIN.N09', 'BAUSTEIN.P09', '&mdash;'),
        array(10, 'BAUSTEIN.T_VE',      'BAUSTEIN.N10', 'BAUSTEIN.P10', '&mdash;'),
        array(11, 'BAUSTEIN.T_VE',      'BAUSTEIN.N11', 'BAUSTEIN.P11', '&mdash;'),
        array(12, 'BAUSTEIN.T_VE',      'BAUSTEIN.N12', 'BAUSTEIN.P12', '&mdash;'),
        array(13, 'BAUSTEIN.T_NICHT',   'BAUSTEIN.N13', '',             'I &larr; #5'),
        array(14, 'BAUSTEIN.T_ODER',    'BAUSTEIN.N14', '',             'I1 &larr; #13, #6 &middot; I2 &larr; #7, #8'),
        array(15, 'BAUSTEIN.T_EVZ',     'BAUSTEIN.N15', 'BAUSTEIN.P15', 'I &larr; #14'),
        array(16, 'BAUSTEIN.T_BENACHR', 'BAUSTEIN.N16', 'BAUSTEIN.P16', 'I &larr; #15'),
        array(17, 'BAUSTEIN.T_SWS',     'BAUSTEIN.N17', 'BAUSTEIN.P17', 'I &larr; #1'),
        array(18, 'BAUSTEIN.T_SWS',     'BAUSTEIN.N18', 'BAUSTEIN.P18', 'I &larr; #2'),
        array(19, 'BAUSTEIN.T_ODER',    'BAUSTEIN.N19', '',             'I1 &larr; #17, I2 &larr; #18'),
        array(20, 'BAUSTEIN.T_BENACHR', 'BAUSTEIN.N20', 'BAUSTEIN.P20', 'I &larr; #19'),
        array(21, 'BAUSTEIN.T_SWS',     'BAUSTEIN.N21', 'BAUSTEIN.P21', 'I &larr; #10'),
        array(22, 'BAUSTEIN.T_BENACHR', 'BAUSTEIN.N22', 'BAUSTEIN.P22', 'I &larr; #21'),
        array(23, 'BAUSTEIN.T_SWS',     'BAUSTEIN.N23', 'BAUSTEIN.P23', 'I &larr; #12'),
        array(24, 'BAUSTEIN.T_BENACHR', 'BAUSTEIN.N24', 'BAUSTEIN.P24', 'I &larr; #23'),
        array(25, 'BAUSTEIN.T_SWS',     'BAUSTEIN.N25', 'BAUSTEIN.P25', 'I &larr; #11'),
        array(26, 'BAUSTEIN.T_BENACHR', 'BAUSTEIN.N26', 'BAUSTEIN.P26', 'I &larr; #25'),
        array(27, 'BAUSTEIN.T_STATUS',  'BAUSTEIN.N27', 'BAUSTEIN.P27', 'I1 &larr; #1, I2 &larr; #3, I3 &larr; #5'),
        array(28, 'BAUSTEIN.T_WOCHE',   'BAUSTEIN.N28', 'BAUSTEIN.P28', '&mdash;'),
        array(29, 'BAUSTEIN.T_TASTER',  'BAUSTEIN.N29', 'BAUSTEIN.P29', '&mdash;'),
        array(30, 'BAUSTEIN.T_UND',     'BAUSTEIN.N30', 'BAUSTEIN.P30', 'I1 &larr; #28, I2 &larr; ' . au_t('BAUSTEIN.ANWESEND')),
        array(31, 'BAUSTEIN.T_ODER',    'BAUSTEIN.N31', '',             'I1 &larr; #29, I2 &larr; #30'),
        array(32, 'BAUSTEIN.T_IMPULS',  'BAUSTEIN.N32', 'BAUSTEIN.P32', 'I &larr; #31'),
        array(33, 'BAUSTEIN.T_VA',      'BAUSTEIN.N33', 'BAUSTEIN.P33', 'I &larr; #32'),
        array(34, 'BAUSTEIN.T_VA',      'BAUSTEIN.N34', 'BAUSTEIN.P34', au_t('BAUSTEIN.MANUELL')),
        // ---- Neu in 0.9.8 ------------------------------------------------
        array(35, 'BAUSTEIN.T_VE',      'BAUSTEIN.N35', 'BAUSTEIN.P35', '&mdash;'),
        array(36, 'BAUSTEIN.T_VE',      'BAUSTEIN.N36', 'BAUSTEIN.P36', '&mdash;'),
        array(37, 'BAUSTEIN.T_VE',      'BAUSTEIN.N37', 'BAUSTEIN.P37', '&mdash;'),
        array(38, 'BAUSTEIN.T_UND',     'BAUSTEIN.N38', 'BAUSTEIN.P38', 'I1 &larr; #35, I2 &larr; #36'),
        array(39, 'BAUSTEIN.T_VA',      'BAUSTEIN.N39', 'BAUSTEIN.P39', 'I &larr; #38'),
        array(40, 'BAUSTEIN.T_SWS',     'BAUSTEIN.N40', 'BAUSTEIN.P40', 'I &larr; #37'),
        array(41, 'BAUSTEIN.T_BENACHR', 'BAUSTEIN.N41', 'BAUSTEIN.P41', 'I &larr; #40'),
    );
}
?>

<div class="sm-step"><b><?= au_e(au_t('LOX.S8_TITEL')) ?></b><br>
<?= au_t('LOX.S8_TEXT') ?>
<table class="sm-tbl">
<tr><th>#</th><th><?= au_e(au_t('LOX.T_BAUSTEIN')) ?></th><th><?= au_e(au_t('LOX.T_NAMENSVORSCHLAG')) ?></th>
    <th><?= au_e(au_t('LOX.T_PARAMETER')) ?></th><th><?= au_e(au_t('LOX.T_EINGAENGE')) ?></th></tr>
<?php foreach (au_bausteine() as $au_b) { ?>
<tr><td><?= (int) $au_b[0] ?></td><td><?= au_t($au_b[1]) ?></td><td><?= au_t($au_b[2]) ?></td>
    <td><?= $au_b[3] !== '' ? au_t($au_b[3]) : '&mdash;' ?></td><td><?= $au_b[4] ?></td></tr>
<?php } ?>
</table>
<?= au_t('LOX.S8_ERLAEUTERUNG') ?>
</div>

<div class="sm-step"><b><?= au_e(au_t('LOX.S9_TITEL')) ?></b><br>
<?= au_t('LOX.S9_TEXT') ?>
<table class="sm-tbl">
<tr><th><?= au_e(au_t('LOX.T_PRUEFUNG')) ?></th><th><?= au_e(au_t('LOX.T_ERWARTUNG')) ?></th></tr>
<tr><td><span class="sm-mono"><?= au_e($au_basis) ?>?token=<?= au_e($au_token) ?>&amp;aktion=status</span></td>
    <td><span class="sm-mono">AUDI;OK=1;SOC=...</span></td></tr>
<tr><td><span class="sm-mono"><?= au_e($au_basis) ?>?aktion=status</span></td>
    <td><span class="sm-mono">FEHLER;OK=0;GRUND=TOKEN</span> (HTTP 403)</td></tr>
<tr><td><span class="sm-mono"><?= au_e($au_basis) ?>?token=<?= au_e($au_token) ?>&amp;aktion=quatsch</span></td>
    <td><span class="sm-mono">FEHLER;OK=0;GRUND=UNBEKANNTE_AKTION</span> (HTTP 400)</td></tr>
<tr><td><span class="sm-mono"><?= au_e($au_basis) ?>?token=<?= au_e($au_token) ?>&amp;aktion=wecken</span></td>
    <td><span class="sm-mono">FEHLER;OK=0;GRUND=LESETOKEN_SCHALTET_NICHT</span> (HTTP 403)</td></tr>
</table>
</div>
</div>

<!-- ================= Reiter: Ladevorgaenge ================= -->
<div class="sm-seite<?= $au_tab === 'tab-ladungen' ? ' sm-active' : '' ?>" id="tab-ladungen">
<h2><?= au_e(au_t('LADUNG.H_TITEL')) ?></h2>
<p class="sm-hilfe"><?= au_t('LADUNG.ERKLAERUNG') ?></p>
<?php if ((int) $au_cfg['kapazitaet'] === 0) { ?>
<div class="sm-warnung"><?= au_t('LADUNG.OHNE_KAPAZITAET') ?></div>
<?php } ?>
<?php if (!$au_ladungen) { ?>
<div class="sm-hinweis"><?= au_t('LADUNG.LEER') ?></div>
<?php } else { ?>
<table class="sm-tbl">
<tr><th><?= au_e(au_t('LADUNG.T_FAHRZEUG')) ?></th><th><?= au_e(au_t('LADUNG.T_START')) ?></th>
    <th><?= au_e(au_t('LADUNG.T_ENDE')) ?></th><th><?= au_e(au_t('LADUNG.T_DAUER')) ?></th>
    <th><?= au_e(au_t('LADUNG.T_SOC')) ?></th><th><?= au_e(au_t('LADUNG.T_KWH')) ?></th>
    <th><?= au_e(au_t('LADUNG.T_KM')) ?></th></tr>
<?php $au_summe = 0.0; foreach ($au_ladungen as $au_l) {
    if ($au_l['kwh'] !== null) { $au_summe += (float) $au_l['kwh']; } ?>
<tr><td><?= au_e($au_l['fahrzeug']) ?></td>
    <td><?= au_e(date('d.m.Y H:i', $au_l['start'])) ?></td>
    <td><?= au_e(date('d.m.Y H:i', $au_l['ende'])) ?></td>
    <td><?= $au_l['dauer'] === null ? '&ndash;' : (int) $au_l['dauer'] . ' min' ?></td>
    <td><?= $au_l['soc_start'] === null ? '&ndash;' : au_e($au_l['soc_start']) . ' &rarr; ' . au_e($au_l['soc_ende']) . ' %' ?></td>
    <td><?= $au_l['kwh'] === null ? '<span class="sm-leer">&ndash;</span>' : au_e($au_l['kwh']) . ' kWh' ?></td>
    <td><?= $au_l['km'] === null ? '&ndash;' : au_e($au_l['km']) . ' km' ?></td></tr>
<?php } ?>
</table>
<p class="sm-hilfe"><?= au_e(sprintf(au_t('LADUNG.SUMME'), count($au_ladungen), round($au_summe, 2))) ?></p>
<?php } ?>

<h2><?= au_e(au_t('LADUNG.H_VERLAUF')) ?></h2>
<p class="sm-hilfe"><?= au_t('LADUNG.VERLAUF_ERKLAERUNG') ?></p>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= au_t('LEGENDE.LESEN') ?></span>
</div>
<div class="sm-knopfreihe">
<?php foreach ($au_nummern as $au_nr2) { ?>
  <form action="index.php" method="post">
    <?php au_formfelder('tab-ladungen', $au_ftoken); ?>
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="verlauf_csv" value="<?= au_e($au_nr2) ?>"><?= au_e(sprintf(au_t('LADUNG.K_CSV'), $au_nr2)) ?></button>
  </form>
<?php } ?>
  <a class="sm-btn sm-b-lesen" href="<?= au_e($au_basis) ?>?token=<?= au_e($au_token) ?>&amp;aktion=ladungen" target="_blank"><?= au_e(au_t('LADUNG.K_ENDPUNKT')) ?></a>
</div>
</div>

<!-- ================= Reiter: Test ================= -->
<div class="sm-seite<?= $au_tab === 'tab-test' ? ' sm-active' : '' ?>" id="tab-test">
<h2><?= au_e(au_t('TEST.H_SELBSTPRUEFUNG')) ?></h2>
<p class="sm-hilfe"><?= au_t('TEST.EINLEITUNG') ?></p>
<table class="sm-tbl">
<tr><th style="width:36px;">&nbsp;</th><th><?= au_e(au_t('TEST.T_FRAGE')) ?></th><th><?= au_e(au_t('TEST.T_BEFUND')) ?></th></tr>
<?php foreach (au_pruefungen() as $au_z) { ?>
<tr><td style="text-align:center;"><?php
    if ($au_z['stand'] === 1) { echo '<span class="sm-an">&#10004;</span>'; }
    elseif ($au_z['stand'] === 0) { echo '<span class="sm-aus">&#10008;</span>'; }
    else { echo '<span style="color:#888;">&#9679;</span>'; }
?></td><td><?= $au_z['frage'] ?></td><td><?= $au_z['antwort'] ?></td></tr>
<?php } ?>
</table>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= au_t('LEGENDE.LESEN') ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?= au_t('LEGENDE.TECHNIK') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= au_t('LEGENDE.AKTION') ?></span>
</div>

<h3><?= au_e(au_t('TEST.H_LESEN')) ?></h3>
<div class="sm-knopfreihe">
  <a class="sm-btn sm-b-lesen" href="<?= au_e($au_basis) ?>?token=<?= au_e($au_token) ?>&amp;aktion=status&amp;fahrzeug=1" target="_blank"><?= au_e(au_t('TEST.K_STATUS')) ?></a>
  <a class="sm-btn sm-b-lesen" href="<?= au_e($au_basis) ?>?token=<?= au_e($au_token) ?>&amp;aktion=laden&amp;fahrzeug=1" target="_blank"><?= au_e(au_t('TEST.K_LADEN')) ?></a>
  <a class="sm-btn sm-b-lesen" href="<?= au_e($au_basis) ?>?token=<?= au_e($au_token) ?>&amp;aktion=wartung&amp;fahrzeug=1" target="_blank"><?= au_e(au_t('TEST.K_WARTUNG')) ?></a>
  <a class="sm-btn sm-b-lesen" href="<?= au_e($au_basis) ?>?token=<?= au_e($au_token) ?>&amp;aktion=position&amp;fahrzeug=1" target="_blank"><?= au_e(au_t('TEST.K_POSITION')) ?></a>
  <a class="sm-btn sm-b-lesen" href="<?= au_e($au_basis) ?>?token=<?= au_e($au_token) ?>&amp;aktion=text&amp;fahrzeug=1" target="_blank"><?= au_e(au_t('TEST.K_TEXT')) ?></a>
  <a class="sm-btn sm-b-lesen" href="<?= au_e($au_basis) ?>?token=<?= au_e($au_token) ?>&amp;aktion=fahrzeuge" target="_blank"><?= au_e(au_t('TEST.K_FAHRZEUGE')) ?></a>
</div>

<h3><?= au_e(au_t('TEST.H_TECHNIK')) ?></h3>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <?php au_formfelder('tab-test', $au_ftoken); ?>
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="selbsttest" value="1"><?= au_e(au_t('TEST.K_SELBSTTEST')) ?></button>
  </form>
  <a class="sm-btn sm-b-technik" href="<?= au_e($au_basis) ?>?token=<?= au_e($au_token) ?>&amp;aktion=roh" target="_blank"><?= au_e(au_t('TEST.K_ROH')) ?></a>
</div>
<?php if ($au_testausgabe !== '') { ?>
<div class="sm-pre"><?= au_e($au_testausgabe) ?></div>
<?php } ?>

<h3><?= au_e(au_t('TEST.H_SCHALTEN')) ?></h3>
<div class="sm-warnung"><?= au_t('TEST.SCHALTEN_WARNUNG') ?></div>
<?php if (empty($au_cfg['steuerung_ein'])) { ?>
<div class="sm-hinweis"><?= au_t('TEST.SCHALTEN_GESPERRT') ?></div>
<?php } ?>
<form action="index.php" method="post">
<?php au_formfelder('tab-test', $au_ftoken); ?>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="test_probe" value="1">
    <?= au_e(au_t('TEST.L_PROBE')) ?>
  </label>
  <div class="sm-hilfe"><?= au_t('TEST.H_PROBE') ?></div>
</div>
<div class="sm-feld">
  <label for="test_fahrzeug"><?= au_e(au_t('TEST.L_FAHRZEUG')) ?></label>
  <input data-role="none" type="number" id="test_fahrzeug" name="test_fahrzeug" value="1" min="1" max="99">
</div>
<div class="sm-feld">
  <label for="test_temp"><?= au_e(au_t('TEST.L_TEMP')) ?></label>
  <input data-role="none" type="text" id="test_temp" name="test_temp" value="<?= (int) $au_cfg['temp_min'] ?>">
  <div class="sm-hilfe"><?= au_t('TEST.H_TEMP') ?></div>
</div>
<div class="sm-feld">
  <label for="test_prozent"><?= au_e(au_t('TEST.L_PROZENT')) ?></label>
  <input data-role="none" type="number" id="test_prozent" name="test_prozent" value="80" min="10" max="100">
  <div class="sm-hilfe"><?= au_t('TEST.H_PROZENT') ?></div>
</div>
<div class="sm-feld">
  <label for="test_ampere"><?= au_e(au_t('TEST.L_AMPERE')) ?></label>
  <select data-role="none" id="test_ampere" name="test_ampere">
    <option value="5">5 A</option>
    <option value="6">6 A</option>
    <option value="10">10 A</option>
    <option value="13">13 A</option>
    <option value="16" selected>16 A</option>
    <option value="32">32 A</option>
  </select>
  <div class="sm-hilfe"><?= au_t('TEST.H_AMPERE') ?></div>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="abruf"><?= au_e(au_t('TEST.K_ABRUF')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="klima_start"><?= au_e(au_t('TEST.K_KLIMA_EIN')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="klima_stop"><?= au_e(au_t('TEST.K_KLIMA_AUS')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="zieltemperatur"><?= au_e(au_t('TEST.K_ZIELTEMP')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="laden_start"><?= au_e(au_t('TEST.K_LADEN_EIN')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="laden_stop"><?= au_e(au_t('TEST.K_LADEN_AUS')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="ladegrenze"><?= au_e(au_t('TEST.K_LADEGRENZE')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="ladestrom"><?= au_e(au_t('TEST.K_LADESTROM')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="scheibe_ein"><?= au_e(au_t('TEST.K_SCHEIBE_EIN')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="scheibe_aus"><?= au_e(au_t('TEST.K_SCHEIBE_AUS')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="wecken"><?= au_e(au_t('TEST.K_WECKEN')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="spin_pruefen"><?= au_e(au_t('TEST.K_SPIN')) ?></button>
</div>

<h3><?= au_e(au_t('TEST.H_EINSTELLUNG')) ?></h3>
<p class="sm-hilfe"><?= au_t('TEST.EINSTELLUNG_ERKLAERUNG') ?></p>
<div class="sm-feld">
  <label for="test_einstellung"><?= au_e(au_t('TEST.L_EINSTELLUNG')) ?></label>
  <select data-role="none" id="test_einstellung" name="test_einstellung">
<?php foreach (au_einstellungen() as $au_en => $au_eb) { ?>
    <option value="<?= au_e($au_en) ?>"><?= au_e(au_klartext(au_t($au_eb))) ?></option>
<?php } ?>
  </select>
</div>
<div class="sm-feld">
  <label for="test_ewert"><?= au_e(au_t('TEST.L_EWERT')) ?></label>
  <select data-role="none" id="test_ewert" name="test_ewert">
    <option value="1"><?= au_e(au_t('ALLG.EIN')) ?></option>
    <option value="0"><?= au_e(au_t('ALLG.AUS')) ?></option>
  </select>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="einstellung"><?= au_e(au_t('TEST.K_EINSTELLUNG')) ?></button>
</div>

<h3><?= au_e(au_t('TEST.H_EINGRIFF')) ?></h3>
<div class="sm-fehler"><?= au_t('TEST.EINGRIFF_WARNUNG') ?></div>
<?php if (empty($au_cfg['gefahr_ein'])) { ?>
<div class="sm-hinweis"><?= au_t('TEST.EINGRIFF_GESPERRT') ?></div>
<?php } else { ?>
<div class="sm-feld">
  <label for="test_dauer"><?= au_e(au_t('TEST.L_DAUER')) ?></label>
  <input data-role="none" type="number" id="test_dauer" name="test_dauer" value="10" min="1" max="60">
  <div class="sm-hilfe"><?= au_t('TEST.H_DAUER') ?></div>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="verriegeln"><?= au_e(au_t('TEST.K_VERRIEGELN')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="entriegeln"><?= au_e(au_t('TEST.K_ENTRIEGELN')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="lichthupe"><?= au_e(au_t('TEST.K_LICHTHUPE')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="hupe"><?= au_e(au_t('TEST.K_HUPE')) ?></button>
</div>
<?php } ?>
</form>

<div class="sm-warnung"><b><?= au_e(au_t('TEST.H_UNGEPRUEFT')) ?></b><br><?= au_t('TEST.UNGEPRUEFT') ?></div>
</div>

<!-- ================= Reiter: Logdateien ================= -->
<div class="sm-seite<?= $au_tab === 'tab-log' ? ' sm-active' : '' ?>" id="tab-log">
<h2><?= au_e(au_t('LOG.H_TITEL')) ?></h2>
<?php
if (class_exists('LBWeb', false) && method_exists('LBWeb', 'loglist_html')) {
    echo LBWeb::loglist_html();
}
?>
<p class="sm-hilfe"><?= au_t('LOG.ERKLAERUNG') ?><br>
<span class="sm-mono"><?= au_e($au_p['log']) ?></span></p>
<?php if ($au_logzeilen) { ?>
<div class="sm-log"><?= au_e(implode("\n", $au_logzeilen)) ?></div>
<?php } else { ?>
<div class="sm-hinweis"><?= au_t('LOG.LEER') ?></div>
<?php } ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= au_t('LEGENDE.AKTION_LOG') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <?php au_formfelder('tab-log', $au_ftoken); ?>
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="log_leeren" value="1"><?= au_e(au_t('LOG.K_LEEREN')) ?></button>
  </form>
</div>
</div>

</div><!-- /sm-wrap -->

<script>
(function () {
	var reiter = document.querySelectorAll('.sm-tab');
	function zeige(id) {
		reiter.forEach(function (r) { r.classList.toggle('sm-active', r.dataset.ziel === id); });
		document.querySelectorAll('.sm-seite').forEach(function (s) { s.classList.toggle('sm-active', s.id === id); });
		document.querySelectorAll('input[name="activetab"]').forEach(function (f) { f.value = id; });
		if (history.replaceState) { history.replaceState(null, '', 'index.php?form=' + id.replace('tab-', '')); }
	}
	reiter.forEach(function (r) {
		r.addEventListener('click', function (e) { e.preventDefault(); zeige(r.dataset.ziel); });
	});
	zeige(<?= json_encode($au_tab) ?>);
})();
</script>
<?php
if ($au_rahmen) {
    LBWeb::lbfooter();
}
