<?php
/**
 * Audi Connect - Konfiguration vervollstaendigen, Kommandozeile (seit 0.9.41).
 *
 * Aufgerufen vom Dienst beim Start (bin/audi.py main(), vor dem ersten
 * Lesen der Konfiguration). Geschrieben wird
 * ausschliesslich ueber au_config_vervollstaendigen() in au_lib.php - der
 * Dienst schreibt die Konfiguration nicht selbst (ein Schreiber fuer ein
 * Format; Bauart BLE-Scanner 1.3.40). Wurzel: LBHOMEDIR, sonst drei Ebenen
 * ueber dem Ablageort - beides nur mit config/plugins, data/plugins und
 * config/system/general.json.
 *
 * Aufruf: php au_vervollstaendigen.php <pluginordner>
 * Ausgabe genau eine Zeile:
 *   VERVOLLSTAENDIGEN;STAND=<ruht|ohne_datei|ohne_merkwort|vollstaendig|ergaenzt|fehler>;SCHLUESSEL=a,b
 *   VERVOLLSTAENDIGEN;STAND=0;KENNUNG=<AUFRUF|WURZEL|MODUL_FEHLT>
 * Rueckgabe 0 ausser bei fehler und den Kennungen (1). Die Zeile nennt nur
 * Schluesselnamen, nie einen Wert.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur Kommandozeile.\n");
}
$au_o = isset($argv[1]) ? (string) $argv[1] : '';
if (count($argv) !== 2 || preg_match('/^[A-Za-z0-9_-]{1,64}\z/', $au_o) !== 1) {
    echo "VERVOLLSTAENDIGEN;STAND=0;KENNUNG=AUFRUF\n";
    exit(1);
}
$au_wurzel = function ($h) {
    return is_string($h) && $h !== '' && is_dir($h . '/config/plugins') && is_dir($h . '/data/plugins')
        && is_file($h . '/config/system/general.json');
};
$au_h = getenv('LBHOMEDIR');
if (!$au_wurzel($au_h)) {
    $au_h = dirname(dirname(dirname(__DIR__)));
    if (!$au_wurzel($au_h)) {
        echo "VERVOLLSTAENDIGEN;STAND=0;KENNUNG=WURZEL\n";
        exit(1);
    }
}
$au_lib = $au_h . '/webfrontend/html/plugins/' . $au_o . '/au_lib.php';
if (!is_file($au_lib)) {
    echo "VERVOLLSTAENDIGEN;STAND=0;KENNUNG=MODUL_FEHLT\n";
    exit(1);
}
putenv('LBHOMEDIR=' . $au_h);
putenv('LBPPLUGINDIR=' . $au_o);
require_once $au_lib;
$au_e = au_config_vervollstaendigen();
echo 'VERVOLLSTAENDIGEN;STAND=' . $au_e['stand'] . ';SCHLUESSEL=' . implode(',', $au_e['schluessel']) . "\n";
exit($au_e['stand'] === 'fehler' ? 1 : 0);
