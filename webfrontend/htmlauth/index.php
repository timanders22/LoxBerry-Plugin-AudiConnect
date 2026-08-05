<?php
/**
 * Audi Connect - Bedienoberflaeche
 *
 * Reiter: Einstellungen | MQTT | Einbindung in Loxone | Test | Logdateien
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

/* Aktiver Reiter. Wer einen Reiter hinzufuegt, muss diese Positivliste
 * mitziehen - sonst springt die Seite nach jedem Absenden zurueck auf
 * Einstellungen, obwohl der Reiter sichtbar und anklickbar ist. */
$au_muster = '/^tab-(settings|mqtt|loxone|test|log)$/';
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

/* ---------------- Vorlage herunterladen ---------------- */
if ($au_post && isset($_POST['vorlage'])) {
    $au_nr = preg_match('/^[0-9]{1,2}$/', (string) $_POST['vorlage']) ? (int) $_POST['vorlage'] : 1;
    list($au_name, $au_inhalt) = au_vorlage($au_nr);
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $au_name . '"');
    echo $au_inhalt;
    exit;
}

/* ---------------- Einstellungen speichern ---------------- */
if ($au_post && isset($_POST['speichern'])) {
    $au_cfg = au_config();

    foreach (array(
        // Untergrenze 180 s: der Audi-Connector wirft darunter beim
        // Anlegen einen ValueError. Lieber hier abweisen als dort abstuerzen.
        'intervall'    => array(180, 3600),
        'takt_wartung' => array(1, 240),
        'temp_min'     => array(10, 30),
        'temp_max'     => array(10, 30),
        'verlauf_tage' => array(1, 90),
        'wartezeit'    => array(0, 30),
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

    $au_cfg['mqtt_ein'] = isset($_POST['mqtt_ein']) ? 1 : 0;
    $au_cfg['steuerung_ein'] = isset($_POST['steuerung_ein']) ? 1 : 0;

    $au_topic = trim(preg_replace('/[\x00-\x1F\x7F"\']/', '', (string) $_POST['mqtt_topic']));
    if ($au_topic === '' || !preg_match('#^[A-Za-z0-9_/\-]{1,64}$#', $au_topic)) {
        $au_fehler[] = au_t('EINST.FEHLER_TOPIC');
    } else {
        $au_cfg['mqtt_topic'] = trim($au_topic, '/');
    }

    /* Zugangsdaten: eigene Datei mit Rechten 0600. Ein leer zurueckgegebenes
     * Passwortfeld loescht nichts - sonst stuende irgendwann ein leeres
     * Passwort in der Datei, ohne dass es jemand merkt. */
    $au_email = trim(preg_replace('/[\x00-\x1F\x7F"\']/', '', (string) $_POST['email']));
    $au_pw = isset($_POST['passwort']) ? (string) $_POST['passwort'] : '';
    $au_spin = isset($_POST['spin']) ? trim((string) $_POST['spin']) : '';
    if ($au_email !== '' && !filter_var($au_email, FILTER_VALIDATE_EMAIL)) {
        $au_fehler[] = au_t('EINST.FEHLER_EMAIL');
    } elseif ($au_spin !== '' && !preg_match('/^[0-9]{4}$/', $au_spin)) {
        // Ist die FORM eines Geheimnisses erkennbar falsch, wird beim Speichern
        // abgewiesen, statt den Benutzer in eine Fehlermeldung des Anbieters
        // laufen zu lassen.
        $au_fehler[] = au_t('EINST.FEHLER_SPIN');
    } else {
        if (!au_zugang_speichern($au_email, $au_pw, $au_spin)) {
            $au_fehler[] = au_t('EINST.FEHLER_ZUGANG_SPEICHERN');
        }
    }
    $au_zg = au_zugang();
    if ($au_zg['laenge'] > 0 && $au_zg['email'] === '') {
        $au_fehler[] = au_t('EINST.WARN_PW_OHNE_KONTO');
    }

    if (!$au_fehler) {
        if (au_config_speichern($au_cfg)) {
            $au_meldungen[] = au_t('EINST.GESPEICHERT');
        } else {
            $au_fehler[] = sprintf(au_t('EINST.FEHLER_SPEICHERN'), $au_p['config']);
        }
    }
    $au_tab = 'tab-settings';
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

/* ---------------- Neues Token ---------------- */
if ($au_post && isset($_POST['token_neu'])) {
    $au_cfg = au_config();
    $au_cfg['aktionstoken'] = au_token_erzeugen();
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
$au_token = au_token();
$au_zg = au_zugang();
$au_fahrzeuge = au_fahrzeuge();
$au_zustand = au_zustand();
$au_alter = au_alter();
$au_pid = au_dienst_pid();
$au_mqtt = au_mqtt_zustand();
$au_pyv = au_python_fassung();
$au_libv = au_bibliothek_fassung();
$au_host = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
    ? preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST'])
    : (gethostname() ?: 'loxberry');
$au_basis = 'http://' . $au_host . '/plugins/' . $au_p['plugin'] . '/index.php';
$au_logzeilen = array();
if (is_file($au_p['log'])) {
    $au_logzeilen = array_slice(
        array_reverse(file($au_p['log'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array()),
        0, 400);
}

$au_rahmen = class_exists('LBWeb', false);
if ($au_rahmen) {
    LBWeb::lbheader('Audi Connect', 'https://wiki.loxberry.de/', 'help.html');
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
</div>

<?php if (!empty($au_zustand['fehler'])) { ?>
<div class="sm-warnung"><b><?= au_e(au_t('ALLG.LETZTE_STOERUNG')) ?></b> <?= au_e($au_zustand['fehler']) ?></div>
<?php } ?>

<?php foreach ($au_fahrzeuge as $au_nr => $au_fz) { ?>
<div class="sm-hinweis">
<b><?= au_e($au_fz['modell'] ? $au_fz['modell'] : au_t('ALLG.OHNE_NAMEN')) ?></b>
(<?= au_e(au_t('ALLG.FAHRZEUG')) ?> <?= au_e($au_nr) ?><?= !empty($au_fz['kennzeichen']) ? ', ' . au_e($au_fz['kennzeichen']) : '' ?>)
&middot; <?= au_e(au_t('ALLG.SOC')) ?> <b><?= !isset($au_fz['soc']) || $au_fz['soc'] === null ? '&ndash;' : au_e($au_fz['soc']) . ' %' ?></b>
&middot; <?= au_e(au_t('ALLG.REICHWEITE')) ?> <?= !isset($au_fz['reichweite_km']) || $au_fz['reichweite_km'] === null ? '&ndash;' : au_e($au_fz['reichweite_km']) . ' km' ?>
&middot; <?= au_e(au_t('ALLG.KM')) ?> <?= !isset($au_fz['kilometerstand']) || $au_fz['kilometerstand'] === null ? '&ndash;' : au_e($au_fz['kilometerstand']) . ' km' ?>
&middot; <?= au_e(au_t('ALLG.VERRIEGELT')) ?>
<?php if (!isset($au_fz['verriegelt']) || $au_fz['verriegelt'] === null) { ?>&ndash;<?php
      } elseif ($au_fz['verriegelt']) { ?><span class="sm-an"><?= au_e(au_t('ALLG.JA')) ?></span><?php
      } else { ?><span class="sm-aus"><?= au_e(au_t('ALLG.NEIN')) ?></span><?php } ?>
<div style="margin-top:8px;"><?= au_soc_svg(au_verlauf_lesen((int) $au_nr)) ?></div>
<div class="sm-hilfe"><?= au_e(au_t('ALLG.VERLAUF_HINWEIS')) ?></div>
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
     faellt das Skript aus, ist die Seite weiterhin bedienbar. -->
<div class="sm-tabs">
	<a class="sm-tab" data-ziel="tab-settings" href="index.php?form=settings"><?= au_e(au_t('REITER.EINSTELLUNGEN')) ?></a>
	<a class="sm-tab" data-ziel="tab-mqtt"     href="index.php?form=mqtt">MQTT</a>
	<a class="sm-tab" data-ziel="tab-loxone"   href="index.php?form=loxone"><?= au_e(au_t('REITER.LOXONE')) ?></a>
	<a class="sm-tab" data-ziel="tab-test"     href="index.php?form=test"><?= au_e(au_t('REITER.TEST')) ?></a>
	<a class="sm-tab" data-ziel="tab-log"      href="index.php?form=log"><?= au_e(au_t('REITER.LOG')) ?></a>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="sm-seite" id="tab-settings">

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
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="dienst" value="start"><?= au_e(au_t('EINST.K_START')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="dienst" value="restart"><?= au_e(au_t('EINST.K_NEUSTART')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="dienst" value="stop"><?= au_e(au_t('EINST.K_STOPP')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="sitzung_verwerfen" value="1"><?= au_e(au_t('EINST.K_SITZUNG')) ?></button>
  </form>
</div>

<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="speichern" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

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

<h2>MQTT</h2>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="mqtt_ein" value="1" <?= !empty($au_cfg['mqtt_ein']) ? 'checked' : '' ?>>
    <?= au_e(au_t('EINST.L_MQTT_EIN')) ?>
  </label>
</div>
<div class="sm-feld">
  <label for="mqtt_topic"><?= au_e(au_t('EINST.L_MQTT_TOPIC')) ?></label>
  <input data-role="none" type="text" id="mqtt_topic" name="mqtt_topic" value="<?= au_e($au_cfg['mqtt_topic']) ?>" placeholder="vw">
  <div class="sm-hilfe"><?= au_t('EINST.H_MQTT_TOPIC') ?></div>
</div>

<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-lesen" type="submit"><?= au_e(au_t('ALLG.SPEICHERN')) ?></button>
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
    <td><?= au_e($au_fz['kennzeichen']) ?></td>
    <td><span class="sm-mono"><?= au_e($au_fz['vin']) ?></span></td>
    <td><?= au_e($au_fz['antriebsart']) ?></td>
    <td><?= empty($au_fz['batterie_kwh']) ? '&mdash;' : au_e($au_fz['batterie_kwh']) . ' kWh' ?></td>
    <td><?= au_e($au_fz['software']) ?></td></tr>
<?php } ?>
</table>
<p class="sm-hilfe"><?= au_t('EINST.VIN_HINWEIS') ?></p>
<?php } ?>
</div>

<!-- ================= Reiter: MQTT ================= -->
<div class="sm-seite" id="tab-mqtt">
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
<div class="sm-seite" id="tab-loxone">
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
<table class="sm-tbl">
<tr><th><?= au_e(au_t('ALLG.EIGENSCHAFT')) ?></th><th><?= au_e(au_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= au_e(au_t('LOX.T_ADRESSE')) ?></td>
    <td><span class="sm-mono"><?= au_e($au_basis) ?>?token=<?= au_e($au_token) ?>&amp;aktion=status&amp;fahrzeug=1</span></td></tr>
<tr><td><?= au_e(au_t('LOX.T_ZYKLUS')) ?></td><td>300 <?= au_e(au_t('ALLG.SEKUNDEN')) ?></td></tr>
</table>
<?= au_t('LOX.S3_BEFEHLE') ?>
<table class="sm-tbl">
<tr><th><?= au_e(au_t('LOX.T_TITEL')) ?></th><th><?= au_e(au_t('LOX.T_BEFEHL')) ?></th>
    <th><?= au_e(au_t('LOX.T_EINHEIT')) ?></th><th><?= au_e(au_t('LOX.T_BEDEUTUNG')) ?></th></tr>
<?php foreach (au_status_felder() as $au_feld => $au_info) { ?>
<tr><td><span class="sm-mono">AUDI_1_<?= au_e($au_feld) ?></span></td>
    <td><span class="sm-mono">\i<?= au_e($au_feld) ?>=\i\v</span></td>
    <td><?= $au_info[0] ?></td><td><?= au_t($au_info[1]) ?></td></tr>
<?php } ?>
</table>
<div class="sm-warnung"><?= au_t('LOX.S3_STRICH') ?></div>
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
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <input data-role="none" type="hidden" name="vorlage" value="1">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit"><?= au_e(au_t('LOX.K_VORLAGE')) ?></button>
  </form>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= au_t('LEGENDE.LESEN') ?></span>
</div>
</div>

<div class="sm-step"><b><?= au_e(au_t('LOX.S4_TITEL')) ?></b><br>
<?= au_t('LOX.S4_TEXT') ?>
<table class="sm-tbl">
<tr><th><?= au_e(au_t('ALLG.EIGENSCHAFT')) ?></th><th><?= au_e(au_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= au_e(au_t('LOX.T_ADRESSE')) ?></td><td><span class="sm-mono"><?= au_e($au_basis) ?>?token=<?= au_e($au_token) ?>&amp;aktion=laden&amp;fahrzeug=1</span></td></tr>
<tr><td><?= au_e(au_t('LOX.T_ZYKLUS')) ?></td><td>300 <?= au_e(au_t('ALLG.SEKUNDEN')) ?></td></tr>
</table>
<table class="sm-tbl">
<tr><th><?= au_e(au_t('LOX.T_BEFEHL')) ?></th><th><?= au_e(au_t('LOX.T_EINHEIT')) ?></th><th><?= au_e(au_t('LOX.T_BEDEUTUNG')) ?></th></tr>
<?php foreach (au_laden_felder() as $au_feld => $au_info) { ?>
<tr><td><span class="sm-mono">\i<?= au_e($au_feld) ?>=\i\v</span></td>
    <td><?= $au_info[0] ?></td><td><?= au_t($au_info[1]) ?></td></tr>
<?php } ?>
</table>
<?= au_t('LOX.S4_WARTUNG') ?>
<table class="sm-tbl">
<tr><th><?= au_e(au_t('LOX.T_BEFEHL')) ?></th><th><?= au_e(au_t('LOX.T_EINHEIT')) ?></th><th><?= au_e(au_t('LOX.T_BEDEUTUNG')) ?></th></tr>
<?php foreach (au_wartung_felder() as $au_feld => $au_info) { ?>
<tr><td><span class="sm-mono">\i<?= au_e($au_feld) ?>=\i\v</span></td>
    <td><?= $au_info[0] ?></td><td><?= au_t($au_info[1]) ?></td></tr>
<?php } ?>
</table>
<?= au_t('LOX.S4_POSITION') ?>
<table class="sm-tbl">
<tr><td><span class="sm-mono"><?= au_e($au_basis) ?>?token=<?= au_e($au_token) ?>&amp;aktion=position&amp;fahrzeug=1</span></td>
    <td><span class="sm-mono">\iBREITE=\i\v</span> / <span class="sm-mono">\iLAENGE=\i\v</span></td></tr>
</table>
</div>

<div class="sm-step"><b><?= au_e(au_t('LOX.S5_TITEL')) ?></b><br>
<?= au_t('LOX.S5_TEXT') ?>
<table class="sm-tbl">
<tr><th><?= au_e(au_t('ALLG.EIGENSCHAFT')) ?></th><th><?= au_e(au_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= au_e(au_t('LOX.T_VA_ADRESSE')) ?></td><td><span class="sm-mono">http://<?= au_e($au_host) ?></span></td></tr>
<tr><td><?= au_e(au_t('LOX.T_VA_KLIMA_EIN')) ?></td>
    <td><span class="sm-mono">/plugins/<?= au_e($au_p['plugin']) ?>/index.php?token=<?= au_e($au_token) ?>&amp;aktion=klima_start&amp;fahrzeug=1&amp;temp=21</span></td></tr>
<tr><td><?= au_e(au_t('LOX.T_VA_KLIMA_AUS')) ?></td>
    <td><span class="sm-mono">/plugins/<?= au_e($au_p['plugin']) ?>/index.php?token=<?= au_e($au_token) ?>&amp;aktion=klima_stop&amp;fahrzeug=1</span></td></tr>
<tr><td><?= au_e(au_t('LOX.T_VA_LADEN_EIN')) ?></td>
    <td><span class="sm-mono">/plugins/<?= au_e($au_p['plugin']) ?>/index.php?token=<?= au_e($au_token) ?>&amp;aktion=laden_start&amp;fahrzeug=1</span></td></tr>
<tr><td><?= au_e(au_t('LOX.T_VA_LADEN_AUS')) ?></td>
    <td><span class="sm-mono">/plugins/<?= au_e($au_p['plugin']) ?>/index.php?token=<?= au_e($au_token) ?>&amp;aktion=laden_stop&amp;fahrzeug=1</span></td></tr>
<tr><td><?= au_e(au_t('LOX.T_VA_LADEGRENZE')) ?></td>
    <td><span class="sm-mono">/plugins/<?= au_e($au_p['plugin']) ?>/index.php?token=<?= au_e($au_token) ?>&amp;aktion=ladegrenze&amp;fahrzeug=1&amp;prozent=&lt;v&gt;</span></td></tr>
<tr><td><?= au_e(au_t('LOX.T_VA_LADESTROM')) ?></td>
    <td><span class="sm-mono">/plugins/<?= au_e($au_p['plugin']) ?>/index.php?token=<?= au_e($au_token) ?>&amp;aktion=ladestrom&amp;fahrzeug=1&amp;ampere=&lt;v&gt;</span></td></tr>
<tr><td><?= au_e(au_t('LOX.T_VA_SCHEIBE')) ?></td>
    <td><span class="sm-mono">/plugins/<?= au_e($au_p['plugin']) ?>/index.php?token=<?= au_e($au_token) ?>&amp;aktion=scheibe_ein&amp;fahrzeug=1</span></td></tr>
<tr><td><?= au_e(au_t('LOX.T_VA_ABRUF')) ?></td>
    <td><span class="sm-mono">/plugins/<?= au_e($au_p['plugin']) ?>/index.php?token=<?= au_e($au_token) ?>&amp;aktion=abruf</span></td></tr>
</table>
<div class="sm-warnung"><?= au_t('LOX.S5_WARNUNG') ?></div>
</div>

<div class="sm-step"><b><?= au_e(au_t('LOX.S6_TITEL')) ?></b><br>
<table class="sm-tbl">
<tr><th><?= au_e(au_t('ALLG.EIGENSCHAFT')) ?></th><th><?= au_e(au_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= au_e(au_t('LOX.T_TOKEN')) ?></td><td><span class="sm-mono"><?= au_e($au_token) ?></span></td></tr>
</table>
<?= au_t('LOX.S6_TEXT') ?>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="token_neu" value="1"><?= au_e(au_t('LOX.K_TOKEN_NEU')) ?></button>
  </form>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= au_t('LEGENDE.AKTION_TOKEN') ?></span>
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
        array(14, 'BAUSTEIN.T_ODER',    'BAUSTEIN.N14', '',             'I1 &larr; #13, I2 &larr; #6, I3 &larr; #7, I4 &larr; #8'),
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
</table>
</div>
</div>

<!-- ================= Reiter: Test ================= -->
<div class="sm-seite" id="tab-test">
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
  <a class="sm-btn sm-b-lesen" href="<?= au_e($au_basis) ?>?token=<?= au_e($au_token) ?>&amp;aktion=fahrzeuge" target="_blank"><?= au_e(au_t('TEST.K_FAHRZEUGE')) ?></a>
</div>

<h3><?= au_e(au_t('TEST.H_TECHNIK')) ?></h3>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
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
<input data-role="none" type="hidden" name="activetab" value="tab-test">
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
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="laden_start"><?= au_e(au_t('TEST.K_LADEN_EIN')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="laden_stop"><?= au_e(au_t('TEST.K_LADEN_AUS')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="ladegrenze"><?= au_e(au_t('TEST.K_LADEGRENZE')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="ladestrom"><?= au_e(au_t('TEST.K_LADESTROM')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="scheibe_ein"><?= au_e(au_t('TEST.K_SCHEIBE_EIN')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="scheibe_aus"><?= au_e(au_t('TEST.K_SCHEIBE_AUS')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="wecken"><?= au_e(au_t('TEST.K_WECKEN')) ?></button>
</div>
</form>

<div class="sm-warnung"><b><?= au_e(au_t('TEST.H_UNGEPRUEFT')) ?></b><br><?= au_t('TEST.UNGEPRUEFT') ?></div>
</div>

<!-- ================= Reiter: Logdateien ================= -->
<div class="sm-seite" id="tab-log">
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
    <input data-role="none" type="hidden" name="activetab" value="tab-log">
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
