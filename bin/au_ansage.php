<?php
/**
 * Audi Connect - Ansage auf Zuruf des Abrufdienstes (Nr. 36 b, Stufe 2, seit 0.9.25)
 *
 * Aufruf:  php au_ansage.php <Pluginordner>      (Auftrag als JSON auf der Standardeingabe)
 *
 * Der Dienst bin/audi.py ist in Python geschrieben; die gemeinsame Sprachausgabe
 * der Plugins dieses Hauses (sprachausgabe.php neben au_lib.php) gibt es nur in
 * PHP. Diese Bruecke nimmt einen Anlass entgegen, baut den Satz aus der
 * Sprachdatei (Abschnitt AU_ANSAGE) und spricht ihn mit ansage_cli() ueber die
 * eingestellte Ausgabeart (Block tts der Konfiguration, ab Werk aus).
 *
 * Der Auftrag kommt auf der STANDARDEINGABE, nie auf der Kommandozeile - die
 * sieht jeder in der Prozessliste:
 *   {"anlass": "laden_fertig", "nr": 1, "name": "e-tron", "soc": 80, "grenze": 80}
 * Angenommen werden nur die Anlaesse aus au_ansage_anlaesse(); der Satz entsteht
 * hier, der Dienst schickt keinen freien Text.
 *
 * Antwort: EINE Zeile ohne Text und ohne Token, z. B.
 *   ANSAGE;STAND=1;ART=musicserver;KENNUNG=-;HTTP=200;ZEICHEN=39
 * Rueckgabewert wie ansage_cli(): 0 gesendet, 1 gescheitert, 3 nichts gesendet
 * ohne Fehler (Ausgabe aus), 2 Aufruf falsch. 1 auch, wenn die Bibliothek fehlt.
 *
 * Der Pluginordner wird mitgegeben wie bei den *_notify.php-Stuecken anderer
 * Linien: dem Dienst koennen die LoxBerry-Umgebungsvariablen fehlen, und bei
 * einer Zweitinstallation heisst der Ordner audiconnect_01.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "ANSAGE;OK=0;GRUND=KEIN_ENDPUNKT\n";
    exit;
}

/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen - dieselbe Regel
 * wie au_lib.php. DIESER BLOCK STEHT VOR SEINEM AUFRUF: PHP zieht Funktionen in
 * einem if-Block nicht vor. */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/data/plugins')
                && is_file($d . '/config/system/general.json')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

$au_home = getenv('LBHOMEDIR');
if (!$au_home || !is_dir($au_home . '/config/plugins') || !is_dir($au_home . '/data/plugins')) {
    $au_home = lb_wurzel_ermitteln();
}
$au_paket = isset($argv[1]) ? preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $argv[1]) : '';
if ($au_paket === '') {
    $au_paket = preg_replace('/[^A-Za-z0-9_\-]/', '', basename(rtrim((string) getenv('LBPPLUGINDIR'), '/')));
}
if ($au_paket === '') {
    $au_paket = 'audiconnect';
}

/* Die Bibliothek: installiert unter <home>/webfrontend/html/plugins/<ordner>/,
 * im Archiv unter ../webfrontend/html/. */
$au_lib = '';
foreach (array(
    $au_home ? $au_home . '/webfrontend/html/plugins/' . $au_paket . '/au_lib.php' : '',
    dirname(__DIR__) . '/webfrontend/html/au_lib.php',
) as $au_kandidat) {
    if ($au_kandidat !== '' && is_file($au_kandidat)) {
        $au_lib = $au_kandidat;
        break;
    }
}
if ($au_lib === '') {
    fwrite(STDERR, "au_lib.php nicht gefunden - es wurde nichts angesagt.\n");
    echo "ANSAGE;STAND=0;KENNUNG=BIBLIOTHEK\n";
    exit(1);
}
/* Die Sprache des LoxBerry (Base.Lang) kennt erst LBSystem. */
if ($au_home && is_file($au_home . '/libs/phplib/loxberry_system.php')) {
    require_once $au_home . '/libs/phplib/loxberry_system.php';
}
require_once $au_lib;

$au_roh = stream_get_contents(STDIN);
$au_d = is_string($au_roh) && strlen($au_roh) <= 4096 ? json_decode($au_roh, true) : null;
$au_anlaesse = au_ansage_anlaesse();
$au_zahl = function ($w) {
    if ($w === null) { return null; }
    if (!is_int($w) && !is_float($w)) { return false; }
    $i = (int) round((float) $w);
    return ($i >= 0 && $i <= 100) ? $i : false;
};
$au_ok = is_array($au_d)
    && isset($au_d['anlass']) && is_string($au_d['anlass']) && isset($au_anlaesse[$au_d['anlass']])
    && isset($au_d['nr']) && is_int($au_d['nr']) && $au_d['nr'] >= 0 && $au_d['nr'] <= 99
    && (!isset($au_d['name']) || is_string($au_d['name']));
$au_soc = $au_ok ? $au_zahl(isset($au_d['soc']) ? $au_d['soc'] : null) : false;
$au_grenze = $au_ok ? $au_zahl(isset($au_d['grenze']) ? $au_d['grenze'] : null) : false;
if (!$au_ok || $au_soc === false || $au_grenze === false) {
    fwrite(STDERR, "Auftrag fehlt, ist kein JSON oder nennt einen unbekannten Anlass.\n");
    echo "ANSAGE;STAND=0;KENNUNG=AUFRUF\n";
    exit(2);
}
/* Der Fahrzeugname kommt aus dem Konto; nur Steuerzeichen fallen weg, und er
 * wird auf 60 Zeichen begrenzt - er wird gesprochen, nicht gespeichert. */
$au_name = isset($au_d['name']) ? trim((string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', $au_d['name'])) : '';
if (preg_match('//u', $au_name) !== 1) {
    $au_name = '';
}
if (function_exists('mb_substr')) {
    $au_name = mb_substr($au_name, 0, 60, 'UTF-8');
} elseif (strlen($au_name) > 60) {
    $au_name = '';
}

$au_text = au_ansage_satz($au_d['anlass'], $au_d['nr'], $au_name, $au_soc, $au_grenze);
list($au_rc, $au_zeile) = ansage_cli(json_encode(array('text' => $au_text), JSON_UNESCAPED_UNICODE),
                                     au_tts(false), au_ansage_k());
echo $au_zeile, "\n";
exit($au_rc);
