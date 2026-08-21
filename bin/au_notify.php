<?php
/**
 * Audi Connect - Meldung in den LoxBerry-Benachrichtigungsbereich legen
 *
 * Aufruf:  php au_notify.php <Schwere 1-7> <Text> [Pluginordner]
 *
 * Der Pluginordner wird als drittes Argument uebergeben, weil der Dienst aus
 * dem Cron oder aus postinstall.sh heraus starten kann und dabei die
 * LoxBerry-Umgebungsvariablen fehlen. Ohne ihn fiele dieses Skript auf den
 * fest eingetragenen Namen zurueck - wer das Plugin in einen anderen Ordner
 * installiert hat, faende seine Warnung dann unter einem Paketnamen, den es
 * nicht gibt, und damit gar nicht.
 *
 * Der Abrufdienst ist in Python geschrieben; fuer Benachrichtigungen gibt es
 * dort keine LoxBerry-Schnittstelle. Deshalb dieses Zwischenstueck, das
 * dieselbe Funktion notify_ext() aufruft, die auch die Oberflaeche benutzen
 * wuerde. Wortgleich uebernommen aus LoxBerry-Plugin-APC-UPS-1.2.1
 * (bin/apc_notify.php) - nicht neu geschrieben, weil die Fassung dort
 * geprueft ist.
 *
 * Rueckgabewert 0 = abgelegt, 1 = nicht moeglich.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins UND webfrontend enthaelt.
 *
 * DIESER BLOCK STEHT VOR SEINEM AUFRUF. PHP zieht Funktionen, die in einem
 * if-Block stehen, nicht vor: sie entstehen erst, wenn die Zeile ausgefuehrt
 * wird. In APC-UPS stand derselbe Block bis 1.1.6 am Dateiende - also HINTER
 * seinem eigenen Aufruf - und das Skript endete mit "Call to undefined
 * function", sobald LBHOMEDIR leer war. Genau davon geht ein Dienst aus, der
 * aus dem Cron gestartet wurde.
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/webfrontend')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

$home = getenv('LBHOMEDIR');
if (!$home) {
    $home = lb_wurzel_ermitteln();
}
$sdk = $home . '/libs/phplib/loxberry_log.php';
if (!$home || !file_exists($sdk)) {
    fwrite(STDERR, "LoxBerry-Bibliothek nicht gefunden: " . $sdk . "\n");
    exit(1);
}
require_once $home . '/libs/phplib/loxberry_system.php';
require_once $sdk;

$schwere = isset($argv[1]) && preg_match('/^[0-9]+$/', (string) $argv[1]) ? (int) $argv[1] : 4;
$text    = isset($argv[2]) ? (string) $argv[2] : '';
if (trim($text) === '') {
    fwrite(STDERR, "Kein Text angegeben.\n");
    exit(1);
}

// Reihenfolge: was der Dienst mitgibt, dann die Umgebung, dann der feste
// Name. Das dritte Argument ist der verlaessliche Weg - siehe Kopf.
$paket = isset($argv[3]) ? preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $argv[3]) : '';
if ($paket === '') {
    $paket = (string) getenv('LBPPLUGINDIR');
}
if (!$paket) {
    $paket = 'audiconnect';
}

if (!function_exists('notify_ext')) {
    fwrite(STDERR, "notify_ext() steht in dieser LoxBerry-Fassung nicht bereit.\n");
    exit(1);
}

notify_ext(array(
    'PACKAGE'  => $paket,
    'NAME'     => 'Audi Connect',
    'MESSAGE'  => $text,
    'SEVERITY' => $schwere,
));

exit(0);
