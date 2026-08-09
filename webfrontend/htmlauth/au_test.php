<?php
/**
 * Audi Connect - die Aktionen des Reiters Test
 *
 * Die Selbstpruefung beantwortet OHNE Loxone und ohne Audi-Konto, ob die
 * Einrichtung traegt. Was sich nur mit Fahrzeug pruefen liesse, wird als
 * solches benannt statt geraten.
 */

/** Eine Zeile der Selbstpruefung. $stand: 1 = ja, 0 = nein, -1 = Hinweis. */
function au_pruefzeile($stand, $frage, $antwort)
{
    return array('stand' => $stand, 'frage' => $frage, 'antwort' => $antwort);
}

function au_pruefungen()
{
    $p = au_paths();
    $cfg = au_config();
    $z = au_zugang();
    $zeilen = array();

    $venv = $p['bindir'] . '/venv/bin/python3';
    $zeilen[] = au_pruefzeile(is_file($venv) ? 1 : 0, au_t('TEST.F_VENV'),
        is_file($venv) ? $venv : au_t('TEST.A_VENV_FEHLT'));

    // carconnectivity verlangt Python 3.9 oder neuer. Das ist auf jedem
    // LoxBerry erfuellt, den es heute gibt (Debian 12 liefert 3.11) - die
    // Zeile bleibt trotzdem stehen, damit man es schwarz auf weiss hat.
    $pyv = au_python_fassung();
    $pyok = 0;
    if ($pyv !== '') {
        // Nicht blind auf $teile[1] zugreifen. Fehlt die virtuelle Umgebung,
        // liefert die Abfrage keine Fassungsnummer, sondern einen Fehlertext
        // wie "python3: not found" - explode ergibt dann ein Feld mit einem
        // einzigen Element, und der Zugriff auf [1] setzt unter PHP 8 eine
        // Warnung ab. Ausgerechnet in der Selbstpruefung, die den Fehler
        // erklaeren soll.
        $teile = explode('.', $pyv);
        $haupt = isset($teile[0]) ? (int) $teile[0] : 0;
        $neben = isset($teile[1]) ? (int) $teile[1] : 0;
        $pyok = ($haupt > 3 || ($haupt === 3 && $neben >= 9)) ? 1 : 0;
    }
    $zeilen[] = au_pruefzeile($pyv === '' ? 0 : $pyok, au_t('TEST.F_PYTHON'),
        $pyv !== '' ? au_e($pyv) . ($pyok ? '' : ' &mdash; ' . au_t('TEST.A_PYTHON_ZU_ALT'))
                    : au_t('TEST.A_PYTHON_UNBEKANNT'));

    $f = au_bibliothek_fassungen();
    $zeilen[] = au_pruefzeile($f['kern'] !== '' ? 1 : 0, au_t('TEST.F_LIB'),
        $f['kern'] !== '' ? 'carconnectivity ' . au_e($f['kern']) : au_t('TEST.A_LIB_FEHLT'));
    $zeilen[] = au_pruefzeile($f['connector'] !== '' ? 1 : 0, au_t('TEST.F_CONNECTOR'),
        $f['connector'] !== '' ? 'carconnectivity-connector-audi ' . au_e($f['connector'])
                               : au_t('TEST.A_CONNECTOR_FEHLT'));

    $pid = au_dienst_pid();
    $zeilen[] = au_pruefzeile($pid > 0 ? 1 : 0, au_t('TEST.F_DIENST'),
        $pid > 0 ? au_t('TEST.A_DIENST_LAEUFT') . ' ' . $pid
                 : (au_dienst_soll() ? au_t('TEST.A_DIENST_SOLL_TOT') : au_t('TEST.A_DIENST_GESTOPPT')));

    $zeilen[] = au_pruefzeile($z['email'] !== '' && strpos($z['email'], '@') !== false ? 1 : 0,
        au_t('TEST.F_KONTO'),
        $z['email'] !== '' ? au_e($z['email']) : au_t('TEST.A_KONTO_FEHLT'));

    // Ein Pruefknopf darf die FORM eines Geheimnisses beurteilen, nie seinen
    // Wert anzeigen.
    $zeilen[] = au_pruefzeile($z['laenge'] > 0 ? 1 : 0, au_t('TEST.F_PASSWORT'),
        $z['laenge'] > 0 ? sprintf(au_t('TEST.A_PASSWORT_DA'), $z['laenge']) : au_t('TEST.A_PASSWORT_FEHLT'));

    $rechte = is_file($p['zugang']) ? (fileperms($p['zugang']) & 0777) : -1;
    $zeilen[] = au_pruefzeile(($rechte >= 0 && ($rechte & 0077) === 0) ? 1 : 0,
        au_t('TEST.F_RECHTE'),
        $rechte >= 0 ? '0' . decoct($rechte) : au_t('TEST.A_ZUGANGSDATEI_FEHLT'));

    // In der Markendatei der Bibliothek stehen Anmeldemarken. Sie darf
    // niemandem sonst lesbar sein; die Bibliothek setzt die Rechte nicht
    // selbst, der Dienst holt es nach.
    $marke = $p['datadir'] . '/token.json';
    if (is_file($marke)) {
        $mr = fileperms($marke) & 0777;
        $zeilen[] = au_pruefzeile(($mr & 0077) === 0 ? 1 : 0, au_t('TEST.F_MARKE'),
            '0' . decoct($mr));
    } else {
        $zeilen[] = au_pruefzeile(-1, au_t('TEST.F_MARKE'), au_t('TEST.A_MARKE_FEHLT'));
    }

    $fahrzeuge = au_fahrzeuge();
    $zeilen[] = au_pruefzeile(count($fahrzeuge) > 0 ? 1 : 0, au_t('TEST.F_FAHRZEUGE'),
        count($fahrzeuge) > 0 ? sprintf(au_t('TEST.A_FAHRZEUGE'), count($fahrzeuge))
                              : au_t('TEST.A_KEINE_FAHRZEUGE'));

    // Ausgefallene Einzelabrufe benennen, statt sie zu verschweigen. Ein
    // Fahrzeug, das die Klimasteuerung nicht kennt, ist kein Fehler - ein
    // stillschweigend leeres Feld dagegen schon.
    $aus = array();
    foreach ($fahrzeuge as $nr => $f) {
        if (!empty($f['ausfaelle']) && is_array($f['ausfaelle'])) {
            foreach (array_keys($f['ausfaelle']) as $name) {
                $aus[] = $nr . ':' . $name;
            }
        }
    }
    if ($aus) {
        $zeilen[] = au_pruefzeile(0, au_t('TEST.F_AUSFAELLE'), au_e(implode(', ', $aus)));
    } elseif ($fahrzeuge) {
        $zeilen[] = au_pruefzeile(1, au_t('TEST.F_AUSFAELLE'), au_t('TEST.A_KEINE_AUSFAELLE'));
    }

    $alter = au_alter();
    if ($alter < 0) {
        $zeilen[] = au_pruefzeile(0, au_t('TEST.F_ABRUF'), au_t('TEST.A_NIE_ABGERUFEN'));
    } else {
        $frisch = $alter <= max(600, 3 * (int) $cfg['intervall']);
        $zeilen[] = au_pruefzeile($frisch ? 1 : 0, au_t('TEST.F_ABRUF'),
            sprintf(au_t('TEST.A_ABRUF_ALTER'), $alter));
    }

    $zu = au_zustand();
    if (!empty($zu['fehler'])) {
        $zeilen[] = au_pruefzeile(0, au_t('TEST.F_LETZTER_FEHLER'), au_e($zu['fehler']));
    }

    $m = au_mqtt_zustand();
    if (!$m['gefunden']) {
        $zeilen[] = au_pruefzeile(0, au_t('TEST.F_MQTT'), au_t('TEST.A_MQTT_NICHT_GEFUNDEN'));
    } elseif ($m['autostart']) {
        $zeilen[] = au_pruefzeile(1, au_t('TEST.F_MQTT'),
            au_e($m['broker']) . ':' . au_e($m['brokerport']) . ' (UDP ' . (int) $m['udpport'] . ')');
    } else {
        $zeilen[] = au_pruefzeile(0, au_t('TEST.F_MQTT'), au_t('TEST.A_MQTT_AUS'));
    }

    $zeilen[] = au_pruefzeile(!empty($cfg['steuerung_ein']) ? 1 : -1, au_t('TEST.F_STEUERUNG'),
        !empty($cfg['steuerung_ein']) ? au_t('TEST.A_STEUERUNG_EIN') : au_t('TEST.A_STEUERUNG_AUS'));

    return $zeilen;
}

/**
 * Fuehrt eine Aktion des Reiters Test aus.
 * Rueckgabe: array(stand, Meldung) - stand wie bei au_befehl_absetzen.
 */
function au_test_aktion($aktion)
{
    $nr = isset($_POST['test_fahrzeug']) ? (string) $_POST['test_fahrzeug'] : '1';
    if (!preg_match('/^[0-9]{1,2}$/', $nr)) {
        return array(0, au_t('TEST.M_FAHRZEUG_UNGUELTIG'));
    }

    switch ($aktion) {
        case 'abruf':
            return au_befehl_absetzen(array('aktion' => 'abruf'), 10);

        case 'klima_start':
            $temp = isset($_POST['test_temp']) ? str_replace(',', '.', (string) $_POST['test_temp']) : '';
            if (!preg_match('/^[0-9]{1,2}(\.[05])?$/', $temp)) {
                return array(0, au_t('TEST.M_TEMP_UNGUELTIG'));
            }
            return au_befehl_absetzen(array('aktion' => 'klima_start', 'fahrzeug' => $nr, 'temp' => $temp));

        case 'klima_stop':
            return au_befehl_absetzen(array('aktion' => 'klima_stop', 'fahrzeug' => $nr));

        case 'laden_start':
            return au_befehl_absetzen(array('aktion' => 'laden_start', 'fahrzeug' => $nr));

        case 'laden_stop':
            return au_befehl_absetzen(array('aktion' => 'laden_stop', 'fahrzeug' => $nr));

        case 'ladegrenze':
            $p = isset($_POST['test_prozent']) ? (string) $_POST['test_prozent'] : '';
            if (!preg_match('/^[0-9]{1,3}$/', $p)) {
                return array(0, au_t('TEST.M_PROZENT_UNGUELTIG'));
            }
            return au_befehl_absetzen(array('aktion' => 'ladegrenze', 'fahrzeug' => $nr, 'prozent' => (int) $p));

        case 'ladestrom':
            $a = isset($_POST['test_ampere']) ? (string) $_POST['test_ampere'] : '';
            if (!preg_match('/^[0-9]{1,2}$/', $a)) {
                return array(0, au_t('TEST.M_AMPERE_UNGUELTIG'));
            }
            return au_befehl_absetzen(array('aktion' => 'ladestrom', 'fahrzeug' => $nr, 'ampere' => (int) $a));

        case 'scheibe_ein':
            return au_befehl_absetzen(array('aktion' => 'scheibe_ein', 'fahrzeug' => $nr));

        case 'scheibe_aus':
            return au_befehl_absetzen(array('aktion' => 'scheibe_aus', 'fahrzeug' => $nr));

        case 'wecken':
            return au_befehl_absetzen(array('aktion' => 'wecken', 'fahrzeug' => $nr));

        default:
            return array(0, au_t('TEST.M_UNBEKANNT'));
    }
}

/** Mini-SVG: Fuellstand ueber den heutigen Tag (0 bis 24 h, 0 bis 100 %). */
function au_soc_svg($punkte)
{
    $w = 720; $h = 120; $x0 = 34; $y0 = 8; $pw = $w - $x0 - 8; $ph = $h - $y0 - 20;
    $tag0 = strtotime('today 00:00');
    $svg = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" style="width:100%;max-width:' . $w
         . 'px;height:auto;background:#fafafa;border:1px solid #e0e0e0;border-radius:8px;"'
         . ' xmlns="http://www.w3.org/2000/svg">';
    foreach (array(0, 25, 50, 75, 100) as $pct) {
        $y = $y0 + $ph - $ph * $pct / 100;
        $svg .= '<line x1="' . $x0 . '" y1="' . $y . '" x2="' . ($x0 + $pw) . '" y2="' . $y
              . '" stroke="#e5e5e5" stroke-width="1"/>';
        $svg .= '<text x="' . ($x0 - 5) . '" y="' . ($y + 3)
              . '" font-size="9" fill="#999" text-anchor="end">' . $pct . '</text>';
    }
    foreach (array(0, 6, 12, 18, 24) as $hh) {
        $x = $x0 + $pw * $hh / 24;
        $svg .= '<line x1="' . $x . '" y1="' . $y0 . '" x2="' . $x . '" y2="' . ($y0 + $ph)
              . '" stroke="#eeeeee" stroke-width="1"/>';
        $svg .= '<text x="' . $x . '" y="' . ($h - 6)
              . '" font-size="9" fill="#999" text-anchor="middle">' . $hh . ':00</text>';
    }
    $poly = array();
    foreach ($punkte as $pt) {
        $anteil = ($pt[0] - $tag0) / 86400;
        if ($anteil < 0 || $anteil > 1) {
            continue;
        }
        // Hart auf Fliesskomma wandeln. PHP 8 wirft bei einer Rechnung mit
        // einer nicht-numerischen Zeichenkette einen TypeError - unter PHP 7
        // war das nur eine Warnung. Die Messpunkte kommen aus einer
        // CSV-Datei; bricht der Strom mitten im Schreiben ab, steht dort eine
        // halbe Zeile, und der Reiter Einstellungen liesse sich nicht mehr
        // oeffnen, weil die Tagesgrafik ihn mitreisst.
        $wert = isset($pt[1]) && is_numeric($pt[1]) ? (float) $pt[1] : 0.0;
        $poly[] = round($x0 + $pw * $anteil, 1) . ','
                . round($y0 + $ph - $ph * max(0.0, min(100.0, $wert)) / 100, 1);
    }
    if (count($poly) >= 2) {
        $erst = explode(',', $poly[0]);
        $letzt = explode(',', $poly[count($poly) - 1]);
        $svg .= '<polygon points="' . $erst[0] . ',' . ($y0 + $ph) . ' ' . implode(' ', $poly) . ' '
              . $letzt[0] . ',' . ($y0 + $ph) . '" fill="#6dac20" opacity="0.15"/>';
        $svg .= '<polyline points="' . implode(' ', $poly) . '" fill="none" stroke="#6dac20" stroke-width="2"/>';
        $svg .= '<circle cx="' . $letzt[0] . '" cy="' . $letzt[1] . '" r="3" fill="#6dac20"/>';
    } else {
        $svg .= '<text x="' . ($x0 + $pw / 2) . '" y="' . ($y0 + $ph / 2)
              . '" font-size="11" fill="#aaa" text-anchor="middle">'
              . au_e(au_t('TEST.KEINE_MESSPUNKTE')) . '</text>';
    }
    return $svg . '</svg>';
}
