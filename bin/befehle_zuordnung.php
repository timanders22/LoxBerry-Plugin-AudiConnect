<?php
/**
 * bin/befehle_zuordnung.php - Zuordnungstabelle der Befehlsbruecke, Linie Audi Connect (Gruppe 41, 0.9.30).
 *
 * Die Befehlsbruecke (bin/befehlsbruecke.php und .sh, byte-gleich aus der Stammfassung Werkzeuge/gemeinsam/, 1.0.2)
 * abonniert <mqtt_topic>/befehl/# und gibt jeden gueltigen Befehl an tun() dieser Tabelle. tun() ruft DIESELBE
 * Funktion wie der HTTP-Endpunkt webfrontend/html/index.php - au_befehl_annehmen() in au_lib.php, Quelle 'mqtt'
 * (Pruefreihenfolge wie dort: Fahrzeug, Steuerung, zweiter Haken, Dienst, Zusatzwerte, Gleichwert-Bremse X-7) -
 * und gibt array(ok, grund) fuer die Quittung zurueck (au_befehl_quittung()). Ein Token gibt es auf diesem Weg
 * nicht; an seiner Stelle stehen die Haken "Befehle ueber MQTT annehmen" (befehle_mqtt_ein) und, fuer die
 * eingreifenden Befehle (Ver-/Entriegeln, Hupe, Lichthupe), "Eingreifende Befehle auch ueber MQTT annehmen"
 * (befehle_mqtt_steuern) ZUSAMMEN mit "Eingreifende Befehle zulassen" (gefahr_ein) - alle ab Werk aus
 * (Entscheidung E4/E5 Gruppe 41; UDP 11884 ist im Heimnetz ohne Anmeldung erreichbar). Die S-PIN-Bremse des
 * Dienstes gilt fuer Entriegeln weiter; die S-PIN-Pruefung (spin_pruefen) gibt es ueber MQTT nicht.
 *
 * Quittungsart "uebergeben" (E3): die Bruecke wartet nie - angenommen heisst, der Befehl liegt in der
 * Warteschlange des Dienstes (oder die Gleichwert-Bremse X-7 meldete UNVERAENDERT). Der Dienst prueft danach
 * ein zweites Mal (Steuerung, S-PIN-Sperre, Stundenbremse 30/h fuer alle Wege gemeinsam) und schreibt das
 * Ergebnis ins Protokoll und in den Reiter Test.
 *
 * Sperrzeiten (Bauvorschrift G41 G2): Sollwerte und Zustaende 0 s - die 60-s-Gleichwert-Bremse X-7 des Plugins
 * bleibt die eine Pruefung; Ereignisse (auffrischen, wecken, hupe, lichthupe) 60 s (Vorgabe der Bruecke).
 * Fahrzeug 1 unter <p>/befehl/<name>, Fahrzeug 2-9 unter <p>/befehl/<n>/<name> (Teil {nr}).
 * Die Datei setzt keine Konfiguration und schreibt nichts; wie der unangemeldete Endpunkt heilt die Bruecke
 * nichts (au_config(false)).
 */
if (!function_exists('au_befehl_annehmen')) {
    $au_kand = array();
    $au_kand[] = 'REPLACELBPHTMLDIR/au_lib.php';
    $au_kand[] = dirname(__DIR__) . '/webfrontend/html/au_lib.php';
    if (basename(dirname(__DIR__)) === 'plugins') {
        $au_kand[] = dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/au_lib.php';
    }
    foreach ($au_kand as $au_k) {
        if (strpos($au_k, 'REPLACE') !== 0 && is_file($au_k)) {
            require_once $au_k;
            break;
        }
    }
}
$au_grund_soll = 'Die 60-s-Gleichwert-Bremse X-7 des Plugins bleibt die eine Pruefung; sie antwortet UNVERAENDERT, '
    . 'also angenommen (Bauvorschrift Gruppe 41, G2).';
/* Ein Befehl der Bruecke -> (Aktion des Endpunkts, Zusatzwerte) fuer au_befehl_annehmen(). */
$au_tun = function ($wie) {
    return function ($w, $t) use ($wie) {
        $nr = isset($t['nr']) ? (string) (int) $t['nr'] : '1';
        list($aktion, $param) = $wie((string) $w);
        if ($aktion === 'aussentemp') {
            return au_befehl_quittung(au_aussentemp_merken($param['wert']));
        }
        return au_befehl_quittung(au_befehl_annehmen($aktion, $param, $nr, 'mqtt'));
    };
};
$au_fest = function ($a) { return function ($w) use ($a) { return array($a, array()); }; };
$au_mit = function ($a, $feld) { return function ($w) use ($a, $feld) { return array($a, array($feld => $w)); }; };
$au_schalter = function ($an, $aus) { return function ($w) use ($an, $aus) { return array($w === '1' ? $an : $aus, array()); }; };
$au_einst = function ($name) { return function ($w) use ($name) { return array('einstellung', array('name' => $name, 'wert' => $w)); }; };
/* Name => array(Art, eingreifend, sperre_s|null fuer die Vorgabe 60, Titel in Loxone, Kachel, Zusatz, Aufruf) */
$au_def = array(
    'auffrischen'    => array('puls', false, null, 'Audi Werte abfragen (MQTT)', 'BEFEHL.K_AUFFRISCHEN', array(), $au_fest('abruf')),
    'laden'          => array('schalter', false, 0, 'Audi Laden (MQTT)', 'BEFEHL.K_LADEN', array(), $au_schalter('laden_start', 'laden_stop')),
    'klima_an'       => array('zahl', false, 0, 'Audi Klimatisierung ein (MQTT)', 'BEFEHL.K_KLIMA_AN', array('min' => 10, 'max' => 30), $au_mit('klima_start', 'temp')),
    'klima_aus'      => array('puls', false, 0, 'Audi Klimatisierung aus (MQTT)', 'BEFEHL.K_KLIMA_AUS', array(), $au_fest('klima_stop')),
    'zieltemperatur' => array('zahl', false, 0, 'Audi Zieltemperatur (MQTT)', 'BEFEHL.K_ZIELTEMP', array('min' => 10, 'max' => 30), $au_mit('zieltemperatur', 'temp')),
    'ladegrenze'     => array('ganz', false, 0, 'Audi Ladegrenze (MQTT)', 'BEFEHL.K_LADEGRENZE', array('min' => 10, 'max' => 100), $au_mit('ladegrenze', 'prozent')),
    'ladestrom'      => array('auswahl', false, 0, 'Audi Ladestrom (MQTT)', 'BEFEHL.K_LADESTROM', array('werte' => array('5', '6', '10', '13', '16', '32')), $au_mit('ladestrom', 'ampere')),
    'scheibe'        => array('schalter', false, 0, 'Audi Scheibenheizung (MQTT)', 'BEFEHL.K_SCHEIBE', array(), $au_schalter('scheibe_ein', 'scheibe_aus')),
    'wecken'         => array('puls', false, null, 'Audi Fahrzeug wecken (MQTT)', 'BEFEHL.K_WECKEN', array(), $au_fest('wecken')),
    'einstellung_stecker_auto'     => array('schalter', false, 0, 'Audi Stecker nach dem Laden entriegeln (MQTT)', 'BEFEHL.K_E_STECKER_AUTO', array(), $au_einst('stecker_auto')),
    'einstellung_klima_unlock'     => array('schalter', false, 0, 'Audi Klima beim Entriegeln beenden (MQTT)', 'BEFEHL.K_E_KLIMA_UNLOCK', array(), $au_einst('klima_unlock')),
    'einstellung_scheibe_dauer'    => array('schalter', false, 0, 'Audi Scheibenheizung bei jeder Klimatisierung (MQTT)', 'BEFEHL.K_E_SCHEIBE_DAUER', array(), $au_einst('scheibe_dauer')),
    'einstellung_klima_ohne_strom' => array('schalter', false, 0, 'Audi Klimatisieren ohne Netzanschluss (MQTT)', 'BEFEHL.K_E_KLIMA_OHNE_STROM', array(), $au_einst('klima_ohne_strom')),
    'einstellung_zone_vl'          => array('schalter', false, 0, 'Audi Klimazone vorn links (MQTT)', 'BEFEHL.K_E_ZONE_VL', array(), $au_einst('zone_vl')),
    'einstellung_zone_vr'          => array('schalter', false, 0, 'Audi Klimazone vorn rechts (MQTT)', 'BEFEHL.K_E_ZONE_VR', array(), $au_einst('zone_vr')),
    'einstellung_zone_hl'          => array('schalter', false, 0, 'Audi Klimazone hinten links (MQTT)', 'BEFEHL.K_E_ZONE_HL', array(), $au_einst('zone_hl')),
    'einstellung_zone_hr'          => array('schalter', false, 0, 'Audi Klimazone hinten rechts (MQTT)', 'BEFEHL.K_E_ZONE_HR', array(), $au_einst('zone_hr')),
    // E5 (Gruppe 41): eingreifend - nur mit befehle_mqtt_steuern UND gefahr_ein; die S-PIN-Bremse gilt im Dienst.
    'verriegeln'     => array('puls', true, 0, 'Audi Verriegeln (MQTT)', 'BEFEHL.K_VERRIEGELN', array(), $au_fest('verriegeln')),
    'entriegeln'     => array('puls', true, 0, 'Audi Entriegeln (MQTT)', 'BEFEHL.K_ENTRIEGELN', array(), $au_fest('entriegeln')),
    'hupe'           => array('puls', true, null, 'Audi Hupe (MQTT)', 'BEFEHL.K_HUPE', array(), $au_fest('hupe')),
    'lichthupe'      => array('puls', true, null, 'Audi Lichthupe (MQTT)', 'BEFEHL.K_LICHTHUPE', array(), $au_fest('lichthupe')),
);
$au_befehle_mqtt = array();
foreach (array('' => '', '{nr}/' => ' Fahrzeug N') as $au_vor => $au_zus) {
    foreach ($au_def as $au_name => $au_d) {
        $au_e = array('art' => $au_d[0], 'eingreifend' => $au_d[1],
                      'titel' => $au_zus === '' ? $au_d[3] : preg_replace('/^Audi /', 'Audi Fahrzeug N ', $au_d[3]),
                      'kachel' => $au_d[4], 'quittung' => 'uebergeben', 'tun' => $au_tun($au_d[6]));
        if ($au_d[2] !== null) {
            $au_e['sperre_s'] = $au_d[2];
            $au_e['begruendung'] = $au_grund_soll;
        }
        $au_befehle_mqtt[$au_vor . $au_name] = $au_e + $au_d[5];
    }
}
/* G39 E-E4 / G41 G5: die Aussentemperatur der Hauswetterstation aus Loxone - nur einmal, gilt fuer alle
 * Fahrzeuge (Wettergrenze der Vorklimatisierung, 30 min gueltig). */
$au_befehle_mqtt['aussentemp'] = array('art' => 'zahl', 'eingreifend' => false, 'min' => -40, 'max' => 60,
    'sperre_s' => 0, 'begruendung' => 'Ein Messwert: jeder neue Wert zaehlt, auch ein gleicher (frischt das Alter auf).',
    'titel' => 'Audi Außentemperatur aus Loxone (MQTT)', 'kachel' => 'BEFEHL.K_AUSSENTEMP', 'quittung' => 'uebergeben',
    'tun' => $au_tun(function ($w) { return array('aussentemp', array('wert' => $w)); }));
return array(
    'fassung' => 1,
    'linie'   => 'audiconnect',
    'plugin'  => 'Audi',
    'geladen' => function_exists('au_befehl_annehmen') && function_exists('au_befehl_quittung')
                 && function_exists('au_config') && function_exists('au_aussentemp_merken'),
    'zweiter_haken' => 'befehle_mqtt_steuern',
    'einstellungen' => function () {
        $c = au_config(false);   // wie der unangemeldete Endpunkt: nichts heilen, nichts anlegen
        $p = trim((string) $c['mqtt_topic'], '/ ');
        return array('praefix'     => $p === '' ? 'audi' : $p,
                     'annehmen'    => !empty($c['befehle_mqtt_ein']),
                     // E5: eingreifend nur mit dem zweiten Haken UND "Eingreifende Befehle zulassen"
                     'eingreifend' => !empty($c['befehle_mqtt_steuern']) && !empty($c['gefahr_ein']),
                     'mqtt_ein'    => !empty($c['mqtt_ein']));
    },
    'protokoll' => function ($stufe, $text) {
        au_log('Befehlsbruecke: ' . ($stufe === 'INFO' ? '' : $stufe . ' ') . $text);
    },
    'teile' => array('nr' => '[2-9]'),
    'befehle' => $au_befehle_mqtt,
);
