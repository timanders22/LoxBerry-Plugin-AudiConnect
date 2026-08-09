<?php
/**
 * Audi Connect - gemeinsame Bibliothek
 *
 * Liegt bewusst unter webfrontend/html/, weil der Miniserver-Endpunkt sie
 * ebenso braucht wie die Oberflaeche. Nur so gibt es EINE Datei statt zweier
 * Kopien, die auseinanderlaufen. Die Oberflaeche unter htmlauth/ laedt sie von
 * hier (drei Kandidatenpfade: installiert und im Archiv).
 *
 * Die Bibliothek spricht NIE mit der Audi-Schnittstelle. Sie liest den
 * Zwischenspeicher, den bin/audi.py schreibt, und legt Schreibbefehle in
 * einer Warteschlange ab. Ein Plugin, das den Datenabruf in der Oberflaeche
 * oder im Endpunkt erledigt, ist falsch gebaut - auch wenn es funktioniert.
 *
 * Praefix 'au_', weil LBWeb::lbheader() SDK-Globale setzt und gleichnamige
 * Plugin-Variablen ueberschreiben wuerde.
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */

if (!function_exists('au_e')) {
    function au_e($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}

function au_paths()
{
    static $p = null;
    if ($p !== null) {
        return $p;
    }
    $home = getenv('LBHOMEDIR');
    if (!$home || !is_dir($home)) {
        foreach (array('/opt/loxberry', '/home/loxberry/loxberry') as $k) {
            if (is_dir($k)) {
                $home = $k;
                break;
            }
        }
    }
    // Der Pluginordner ergibt sich aus dem Ablageort dieser Datei. Der
    // MD5-Schluessel aus der plugindatabase.json wird bewusst NICHT benutzt -
    // er wird aus Autorenname, E-Mail und Plugin-Name gebildet und aendert
    // sich bei jedem Fork.
    $dir = basename(dirname(__FILE__));
    if ($home && !is_dir($home . '/config/plugins/' . $dir)) {
        foreach (array(getenv('LBPPLUGINDIR'), 'audiconnect') as $kand) {
            if ($kand && is_dir($home . '/config/plugins/' . $kand)) {
                $dir = $kand;
                break;
            }
        }
    }
    if ($home) {
        $p = array(
            'home'      => $home,
            'plugin'    => $dir,
            'configdir' => $home . '/config/plugins/' . $dir,
            'config'    => $home . '/config/plugins/' . $dir . '/audi.json',
            'zugang'    => $home . '/config/plugins/' . $dir . '/zugang.json',
            'sicherung' => $home . '/config/plugins/' . $dir . '.backup.audi.json',
            'datadir'   => $home . '/data/plugins/' . $dir,
            'bindir'    => $home . '/bin/plugins/' . $dir,
            'logdir'    => $home . '/log/plugins/' . $dir,
            'log'       => $home . '/log/plugins/' . $dir . '/audi.log',
        );
    } else {
        // Nicht installiert (Entwicklung, Attrappe): neben dem Plugin arbeiten.
        $basis = dirname(dirname(__DIR__));
        $p = array(
            'home'      => '',
            'plugin'    => $dir,
            'configdir' => $basis . '/config',
            'config'    => $basis . '/config/audi.json',
            'zugang'    => $basis . '/config/zugang.json',
            'sicherung' => $basis . '/config/vw.backup.json',
            'datadir'   => $basis . '/data',
            'bindir'    => $basis . '/bin',
            'logdir'    => $basis . '/log',
            'log'       => $basis . '/log/audi.log',
        );
    }
    return $p;
}

/** Voreinstellungen. Muessen zu VORGABEN in bin/audi.py passen. */
function au_vorgaben()
{
    return array(
        'intervall'         => 300,
        'takt_wartung'      => 12,
        'mqtt_ein'          => 0,
        'mqtt_topic'        => 'audi',
        'steuerung_ein'     => 0,
        'temp_min'          => 16,
        'temp_max'          => 29,
        'verlauf_tage'      => 8,
        'aktionstoken'      => '',
        'wartezeit'         => 8,
    );
}

function au_json_lesen($pfad)
{
    if (!is_file($pfad)) {
        return array();
    }
    $d = json_decode((string) @file_get_contents($pfad), true);
    return is_array($d) ? $d : array();
}

function au_config()
{
    $p = au_paths();
    // Selbstheilung: fehlende oder leere Konfiguration aus der Sicherung holen.
    $roh = is_file($p['config']) ? trim((string) @file_get_contents($p['config'])) : '';
    if (($roh === '' || $roh === '{}') && is_file($p['sicherung'])) {
        @mkdir($p['configdir'], 0775, true);
        @copy($p['sicherung'], $p['config']);
    }
    $cfg = au_json_lesen($p['config']);
    return array_merge(au_vorgaben(), $cfg);
}

/**
 * Eine Datei unteilbar schreiben: erst daneben, dann umbenennen.
 *
 * file_put_contents kuerzt die Zieldatei sofort auf null und fuellt sie erst
 * danach. Faellt der Strom dazwischen - oder stuerzt der LoxBerry ab -, ist
 * die Konfiguration weg, und bei zugang.json waeren das die myAudi-
 * Zugangsdaten. rename() ist auf demselben Dateisystem unteilbar: der Leser
 * sieht entweder die alte oder die neue Fassung, nie eine halbe.
 *
 * Die Rechte werden auf der Nebendatei gesetzt, bevor sie an ihren Platz
 * rueckt - sonst gaebe es einen Augenblick, in dem die Zugangsdaten mit den
 * Vorgaberechten dastuenden.
 */
function au_datei_schreiben($pfad, $inhalt, $rechte = 0644)
{
    if ($inhalt === false || $inhalt === null) {
        return false;
    }
    $neben = $pfad . '.' . getmypid() . '.neu';
    if (@file_put_contents($neben, $inhalt) !== strlen($inhalt)) {
        @unlink($neben);
        return false;
    }
    @chmod($neben, $rechte);
    if (!@rename($neben, $pfad)) {
        @unlink($neben);
        return false;
    }
    return true;
}

function au_config_speichern($cfg)
{
    $p = au_paths();
    if (!is_dir($p['configdir'])) {
        @mkdir($p['configdir'], 0775, true);
    }
    $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    // json_encode liefert bei ungueltigem UTF-8 false, und file_put_contents
    // schriebe dann eine Datei mit NULL Bytes - und meldete das als Erfolg.
    if (!au_datei_schreiben($p['config'], $json, 0644)) {
        return false;
    }
    @copy($p['config'], $p['sicherung']);
    return true;
}

/**
 * Zugangsdaten.
 *
 * Eigene Datei mit Rechten 0600, nicht in der Konfiguration, die die
 * Oberflaeche anzeigt. Passwort und S-PIN werden nie zurueckgegeben - nur
 * ihre Laenge.
 */
function au_zugang()
{
    $z = au_json_lesen(au_paths()['zugang']);
    return array(
        'email'       => isset($z['email']) ? (string) $z['email'] : '',
        'laenge'      => isset($z['passwort']) ? strlen((string) $z['passwort']) : 0,
        'spin_laenge' => isset($z['spin']) ? strlen((string) $z['spin']) : 0,
    );
}

/**
 * Speichert die Zugangsdaten.
 *
 * Ein leer zurueckgegebenes Passwortfeld loescht nichts: sonst stuende
 * irgendwann ein leeres Passwort in der Datei, ohne dass es jemand merkt.
 * Genau dieser Fehler hat im ACTi-Plugin 21 vergebliche Anmeldeversuche
 * verursacht.
 */
function au_zugang_speichern($email, $passwort, $spin)
{
    $p = au_paths();
    if (!is_dir($p['configdir'])) {
        @mkdir($p['configdir'], 0775, true);
    }
    $alt = au_json_lesen($p['zugang']);
    $neu = array(
        'email'    => $email !== null ? $email : (isset($alt['email']) ? $alt['email'] : ''),
        'passwort' => ($passwort !== null && $passwort !== '')
                      ? $passwort
                      : (isset($alt['passwort']) ? $alt['passwort'] : ''),
        'spin'     => ($spin !== null && $spin !== '')
                      ? $spin
                      : (isset($alt['spin']) ? $alt['spin'] : ''),
    );
    $json = json_encode($neu, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    // json_encode liefert bei ungueltigem UTF-8 false, und file_put_contents
    // schriebe dann eine LEERE Datei - hier waeren das die Zugangsdaten.
    // 0600 schon auf der Nebendatei - siehe au_datei_schreiben().
    return au_datei_schreiben($p['zugang'], $json, 0600);
}

/** Zufallstoken fuer den unangemeldeten Endpunkt. */
function au_token_erzeugen($laenge = 24)
{
    $zeichen = 'abcdefghijkmnpqrstuvwxyz23456789';
    $t = '';
    for ($i = 0; $i < $laenge; $i++) {
        $t .= $zeichen[random_int(0, strlen($zeichen) - 1)];
    }
    return $t;
}

/**
 * Sorgt dafuer, dass ein Token vorhanden ist, und gibt es zurueck.
 *
 * WARUM HIER EINE SPERRE STEHT
 * Nach einem Neustart des Miniservers laufen dessen virtuelle Eingaenge in
 * derselben Sekunde los - status, laden, wartung, position. Jeder Aufruf
 * landet in einem eigenen PHP-Prozess, und wenn noch kein Token in der
 * Konfiguration steht, wuerde jeder ein eigenes erzeugen und die Datei
 * ueberschreiben. Uebrig bliebe eines; die Adressen in Loxone Config trugen
 * dann teils ein anderes, und der Endpunkt antwortete mit 403. Ein Fehler,
 * den man beim Suchen nicht findet, weil das Token ja "da" ist.
 *
 * Die Sperre haelt genau einen Prozess in der erzeugenden Stelle. Wer
 * danach hereinkommt, liest die Konfiguration erneut und findet das gerade
 * geschriebene Token vor.
 */
function au_token()
{
    $cfg = au_config();
    if (trim((string) $cfg['aktionstoken']) !== '') {
        return (string) $cfg['aktionstoken'];
    }

    $p = au_paths();
    if (!is_dir($p['configdir'])) {
        @mkdir($p['configdir'], 0775, true);
    }
    $sperre = $p['configdir'] . '/token.lock';
    $fh = @fopen($sperre, 'c');
    if ($fh === false) {
        // Ohne Sperre lieber ein Token erzeugen als gar keines - ein
        // beschreibbares Verzeichnis vorausgesetzt, kommt dieser Fall nicht vor.
        $cfg['aktionstoken'] = au_token_erzeugen();
        au_config_speichern($cfg);
        return (string) $cfg['aktionstoken'];
    }
    @flock($fh, LOCK_EX);

    // Zweite Pruefung hinter der Sperre: waehrend des Wartens hat ein
    // anderer Prozess vermutlich schon eines geschrieben.
    $cfg = au_config();
    if (trim((string) $cfg['aktionstoken']) === '') {
        $cfg['aktionstoken'] = au_token_erzeugen();
        au_config_speichern($cfg);
    }

    @flock($fh, LOCK_UN);
    @fclose($fh);
    return (string) $cfg['aktionstoken'];
}

/* ---------------- Zwischenspeicher lesen ---------------- */

function au_loxone()
{
    return au_json_lesen(au_paths()['datadir'] . '/loxone.json');
}

function au_zustand()
{
    return au_json_lesen(au_paths()['datadir'] . '/zustand.json');
}

/** Fahrzeuge aus dem Abbild, 1-basiert. */
function au_fahrzeuge()
{
    $l = au_loxone();
    return isset($l['fahrzeuge']) && is_array($l['fahrzeuge']) ? $l['fahrzeuge'] : array();
}

/** Alter des Abbilds in Sekunden, oder -1 wenn es keines gibt. */
function au_alter()
{
    $l = au_loxone();
    return isset($l['ts']) ? max(0, time() - (int) $l['ts']) : -1;
}

/* ---------------- Dienst ---------------- */

function au_dienst_pid()
{
    $f = au_paths()['datadir'] . '/dienst.pid';
    if (!is_file($f)) {
        return 0;
    }
    $pid = (int) trim((string) @file_get_contents($f));
    if ($pid <= 0 || !is_dir('/proc/' . $pid)) {
        return 0;
    }
    // Nummernrecycling ausschliessen: der Prozess muss unser Skript sein.
    $cmd = (string) @file_get_contents('/proc/' . $pid . '/cmdline');
    return strpos($cmd, 'audi.py') !== false ? $pid : 0;
}

function au_dienst_soll()
{
    return is_file(au_paths()['datadir'] . '/soll_laufen') ? 1 : 0;
}

/** $befehl ist 'start', 'stop' oder 'restart'. Rueckgabe: array(ok, Ausgabe) */
function au_dienst($befehl)
{
    if (!in_array($befehl, array('start', 'stop', 'restart'), true)) {
        return array(0, 'Unbekannter Befehl.');
    }
    $skript = au_paths()['bindir'] . '/dienst.sh';
    if (!is_file($skript)) {
        return array(0, 'dienst.sh nicht gefunden: ' . $skript);
    }
    $ausgabe = array();
    $code = 0;
    // escapeshellarg statt escapeshellcmd: letzteres maskiert Metazeichen,
    // laesst Leerzeichen aber unberuehrt - ein Pfad mit Leerzeichen zerfiele
    // in zwei Argumente, und der Dienst startete stillschweigend nicht.
    @exec(escapeshellarg($skript) . ' ' . escapeshellarg($befehl) . ' 2>&1', $ausgabe, $code);
    return array($code === 0 ? 1 : 0, implode("\n", $ausgabe));
}

/**
 * Fassungen der beiden Python-Pakete in der virtuellen Umgebung.
 *
 * Es sind zwei: der Kern (carconnectivity) und der Audi-Connector. Beide
 * werden getrennt veroeffentlicht und koennen auseinanderlaufen - deshalb
 * stehen beide in der Oberflaeche.
 *
 * Rueckgabe: array('kern' => '0.11.10', 'connector' => '0.10.6'); nicht
 * ermittelbare Werte bleiben ''.
 */
function au_bibliothek_fassungen()
{
    $py = au_paths()['bindir'] . '/venv/bin/python3';
    $leer = array('kern' => '', 'connector' => '');
    if (!is_file($py)) {
        return $leer;
    }
    $ausgabe = array();
    @exec(escapeshellarg($py) . ' -c ' . escapeshellarg(
        'import importlib.metadata as m' . "\n"
        . 'for p in ("carconnectivity", "carconnectivity-connector-audi"):' . "\n"
        . '    try: print(m.version(p))' . "\n"
        . '    except Exception: print("")'
    ) . ' 2>/dev/null', $ausgabe);
    return array(
        'kern'      => isset($ausgabe[0]) ? trim($ausgabe[0]) : '',
        'connector' => isset($ausgabe[1]) ? trim($ausgabe[1]) : '',
    );
}

/** Kurzform fuer die Anzeige: 'Kern 0.11.10 / Connector 0.10.6' oder ''. */
function au_bibliothek_fassung()
{
    $f = au_bibliothek_fassungen();
    if ($f['kern'] === '' && $f['connector'] === '') {
        return '';
    }
    return trim(($f['kern'] !== '' ? $f['kern'] : '?') . ' / '
              . ($f['connector'] !== '' ? $f['connector'] : '?'));
}

/** Fassung des Python in der virtuellen Umgebung, oder ''. */
function au_python_fassung()
{
    $py = au_paths()['bindir'] . '/venv/bin/python3';
    if (!is_file($py)) {
        return '';
    }
    $ausgabe = array();
    @exec(escapeshellarg($py) . ' -c ' . escapeshellarg(
        'import sys; print("%d.%d.%d" % sys.version_info[:3])'
    ) . ' 2>/dev/null', $ausgabe);
    return trim(implode('', $ausgabe));
}

/** Ausgabe von audi.py --selbsttest. */
function au_selbsttest()
{
    $p = au_paths();
    $py = $p['bindir'] . '/venv/bin/python3';
    $skript = $p['bindir'] . '/audi.py';
    if (!is_file($py) || !is_file($skript)) {
        return "[FEHL] Die virtuelle Python-Umgebung oder audi.py fehlt.\n"
             . "       Erwartet: " . $py . "\n"
             . "                 " . $skript . "\n"
             . "       Abhilfe: Plugin neu installieren; die Installation legt beides an.";
    }
    $ausgabe = array();
    @exec(escapeshellarg($py) . ' ' . escapeshellarg($skript) . ' --selbsttest 2>&1', $ausgabe);
    return implode("\n", $ausgabe);
}

/* ---------------- Befehlswarteschlange ----------------
 *
 * Sowohl der Miniserver-Endpunkt als auch der Reiter Test setzen Befehle ueber
 * diese eine Funktion ab. Zwei Kopien derselben Logik laufen zwangslaeufig
 * auseinander.
 *
 * Rueckgabe: array(ok, meldung). ok = 1 erledigt, 0 abgelehnt,
 * 2 eingereiht, aber ohne Antwort in der Wartezeit - also Ergebnis unbekannt.
 * Es wird bewusst kein Erfolg gemeldet, den niemand geprueft hat.
 */
/**
 * Wie lange lohnt das Warten auf die Antwort - je nach Befehl?
 *
 * Ein Abruf ist in Sekunden durch. Das Aufwecken eines geparkten Fahrzeugs
 * und der Start der Klimatisierung dauern bei Audi aber regelmaessig zwanzig
 * bis fuenfundvierzig Sekunden - das Auto muss erst geweckt werden und
 * antwortet ueber Mobilfunk. Mit der Vorgabe von acht Sekunden gab die
 * Oberflaeche fast immer "Eingereiht, aber Ergebnis unbekannt" aus, obwohl
 * der Befehl kurz darauf sauber lief. Wer das zweimal erlebt, drueckt beim
 * dritten Mal mehrfach - und handelt sich bei Audi ein HTTP 429 ein.
 */
function au_wartezeit_fuer($aktion, $vorgabe)
{
    $lang = array('klima_start', 'klima_stop', 'wecken', 'standheizung_start',
                  'standheizung_stop', 'laden_start', 'laden_stop');
    if (in_array((string) $aktion, $lang, true)) {
        return max(30, (int) $vorgabe);
    }
    return (int) $vorgabe;
}

function au_befehl_absetzen($befehl, $wartezeit = null)
{
    $p = au_paths();
    $cfg = au_config();
    if ($wartezeit === null) {
        $wartezeit = au_wartezeit_fuer(isset($befehl['aktion']) ? $befehl['aktion'] : '',
                                       (int) $cfg['wartezeit']);
    }
    // Obergrenze 45 statt 30: siehe au_wartezeit_fuer().
    $wartezeit = max(0, min(45, (int) $wartezeit));

    $ordner = $p['datadir'] . '/befehle';
    if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) {
        return array(0, 'Der Ordner fuer die Warteschlange liess sich nicht anlegen: ' . $ordner);
    }
    $kennung = bin2hex(random_bytes(8));
    $datei = $ordner . '/' . $kennung . '.json';
    $tmp = $datei . '.tmp';
    if (@file_put_contents($tmp, json_encode($befehl)) === false || !@rename($tmp, $datei)) {
        @unlink($tmp);
        return array(0, 'Der Befehl liess sich nicht ablegen: ' . $datei);
    }
    $antwort = $p['datadir'] . '/antworten/' . $kennung . '.json';
    for ($i = 0; $i < $wartezeit * 10; $i++) {
        if (is_file($antwort)) {
            $a = au_json_lesen($antwort);
            return array((int) (isset($a['ok']) ? $a['ok'] : 0),
                         (string) (isset($a['meldung']) ? $a['meldung'] : ''));
        }
        usleep(100000);
    }
    return array(2, 'Eingereiht, aber der Dienst hat innerhalb von ' . $wartezeit . ' s nicht '
                  . 'geantwortet. Bei Audi dauert das Wecken eines geparkten Fahrzeugs oft laenger '
                  . '- der Befehl laeuft vermutlich trotzdem. Im Reiter Test zeigt "Zustand holen", '
                  . 'ob er angekommen ist. Bitte nicht mehrfach ausloesen: Audi sperrt das Konto '
                  . 'bei zu vielen Anfragen.');
}

/* ---------------- Verlauf ---------------- */

/** Messpunkte eines Tages: Array von array(ts, fuellstand, reichweite). */
function au_verlauf_lesen($nummer, $tag = '')
{
    if ($tag === '') {
        $tag = date('Ymd');
    }
    $f = au_paths()['datadir'] . '/verlauf/fahrzeug' . (int) $nummer . '_' . $tag . '.csv';
    $out = array();
    if (is_file($f)) {
        foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $zeile) {
            $c = explode(';', $zeile);
            if (count($c) >= 2) {
                $out[] = array((int) $c[0], (float) $c[1], isset($c[2]) && $c[2] !== '' ? (float) $c[2] : 0);
            }
        }
    }
    return $out;
}

/* ---------------- MQTT-Gateway ----------------
 *
 * Das MQTT-Gateway ist seit LoxBerry 3 Bestandteil des Systems, kein Plugin.
 * Es wird nicht nachinstalliert, sondern unter System -> MQTT Gateway
 * eingeschaltet.
 *
 * Mqtt.Brokerhost ist ab Werk auf 'localhost' gesetzt. Eine Pruefung darauf
 * beantwortet also NICHT die Frage, ob Nachrichten ankommen koennen.
 * Massgeblich ist Gatewayautostart.
 */
function au_mqtt_zustand()
{
    $p = au_paths();
    $leer = array('gefunden' => 0, 'autostart' => 0, 'udpport' => 0,
                  'broker' => '', 'brokerport' => '', 'websocket' => '');
    if ($p['home'] === '') {
        return $leer;
    }
    $gen = au_json_lesen($p['home'] . '/config/system/general.json');
    $m = array();
    if (isset($gen['Mqtt']) && is_array($gen['Mqtt'])) {
        $m = $gen['Mqtt'];
    } elseif (isset($gen['mqtt']) && is_array($gen['mqtt'])) {
        $m = $gen['mqtt'];
    }
    if (!$m) {
        return $leer;
    }
    $hol = function ($gross, $klein) use ($m) {
        if (isset($m[$gross])) {
            return $m[$gross];
        }
        return isset($m[$klein]) ? $m[$klein] : '';
    };
    $auto = $hol('Gatewayautostart', 'gatewayautostart');
    return array(
        'gefunden'   => 1,
        'autostart'  => in_array((string) $auto, array('1', 'true'), true) ? 1 : 0,
        'udpport'    => (int) $hol('Udpinport', 'udpinport'),
        'broker'     => (string) $hol('Brokerhost', 'brokerhost'),
        'brokerport' => (string) $hol('Brokerport', 'brokerport'),
        'websocket'  => (string) $hol('Websocketport', 'websocketport'),
    );
}

/** Alle Themen, die der Dienst veroeffentlicht, mit ihrer Bedeutung. */
function au_mqtt_themen()
{
    return array(
        'ok'                          => 'AU_MQTT.OK',
        'fahrzeuge'                   => 'AU_MQTT.FAHRZEUGE',
        'fahrzeugN/soc'               => 'AU_MQTT.SOC',
        'fahrzeugN/tank_prozent'      => 'AU_MQTT.TANK',
        'fahrzeugN/reichweite_km'     => 'AU_MQTT.REICHWEITE',
        'fahrzeugN/kilometerstand'    => 'AU_MQTT.KM',
        'fahrzeugN/verriegelt'        => 'AU_MQTT.VERRIEGELT',
        'fahrzeugN/tueren_offen'      => 'AU_MQTT.TUEREN',
        'fahrzeugN/fenster_offen'     => 'AU_MQTT.FENSTER',
        'fahrzeugN/licht_an'          => 'AU_MQTT.LICHT',
        'fahrzeugN/handbremse'        => 'AU_MQTT.HANDBREMSE',
        'fahrzeugN/zustand'           => 'AU_MQTT.ZUSTAND',
        'fahrzeugN/erreichbar'        => 'AU_MQTT.ERREICHBAR',
        'fahrzeugN/klima_an'          => 'AU_MQTT.KLIMA',
        'fahrzeugN/zieltemperatur'    => 'AU_MQTT.ZIELTEMP',
        'fahrzeugN/aussentemperatur'  => 'AU_MQTT.AUSSEN',
        'fahrzeugN/scheibenheizung'   => 'AU_MQTT.SCHEIBE',
        'fahrzeugN/laedt'             => 'AU_MQTT.LAEDT',
        'fahrzeugN/ladeleistung_kw'   => 'AU_MQTT.LADEKW',
        'fahrzeugN/ladetempo_kmh'     => 'AU_MQTT.TEMPO',
        'fahrzeugN/ladegrenze'        => 'AU_MQTT.LADEGRENZE',
        'fahrzeugN/ladestrom_a'       => 'AU_MQTT.LADESTROM',
        'fahrzeugN/kabel_verbunden'   => 'AU_MQTT.KABEL',
        'fahrzeugN/stecker_verriegelt' => 'AU_MQTT.STECKER',
        'fahrzeugN/laden_fertig_um'   => 'AU_MQTT.FERTIG',
        'fahrzeugN/breite'            => 'AU_MQTT.BREITE',
        'fahrzeugN/laenge'            => 'AU_MQTT.LAENGE',
        'fahrzeugN/inspektion_tage'   => 'AU_MQTT.INSP_TAGE',
        'fahrzeugN/inspektion_km'     => 'AU_MQTT.INSP_KM',
        'fahrzeugN/oelservice_tage'   => 'AU_MQTT.OEL_TAGE',
        'fahrzeugN/oelservice_km'     => 'AU_MQTT.OEL_KM',
    );
}

/* ==================================================================
 * Loxone-Vorlagen
 *
 * Nachbau der Bausteine aus LoxBerry::LoxoneTemplateBuilder; das Modul gibt es
 * nur in Perl. Attributreihenfolge, CRLF als Zeilenende und der Tabulator vor
 * den Kindelementen entsprechen dem Original. Wortgleich uebernommen aus
 * LoxBerry-Plugin-APC-UPS-1.0.0 (ap_xml_virtual_in_http) - nicht neu
 * geschrieben, weil die Fassung dort geprueft ist.
 * ================================================================== */

function au_x($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function au_xml_virtual_in_http($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInHttp ';
    $o .= 'Title="' . au_x($kopf['title']) . '" ';
    $o .= 'Comment="' . au_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . au_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'PollingTime="' . au_x(isset($kopf['polling']) ? $kopf['polling'] : '60') . '"';
    $o .= '>' . $crlf;
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualInHttpCmd ';
        $o .= 'Title="' . au_x($c['title']) . '" ';
        $o .= 'Comment="' . au_x(isset($c['comment']) ? $c['comment'] : '') . '" ';
        $o .= 'Check="' . au_x(isset($c['check']) ? $c['check'] : ' ') . '" ';
        $o .= 'Signed="true" ';
        $o .= 'Analog="true" ';
        $o .= 'SourceValLow="0" ';
        $o .= 'DestValLow="0" ';
        $o .= 'SourceValHigh="100" ';
        $o .= 'DestValHigh="100" ';
        $o .= 'DefVal="0" ';
        $o .= 'MinVal="-2147483647" ';
        $o .= 'MaxVal="2147483647"';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return $o;
}

/**
 * Die Werte des Status-Endpunkts mit Einheit und Bedeutung.
 *
 * Reihenfolge und Namen sind zugleich die Reihenfolge der Befehlserkennungen
 * in der Loxone-Vorlage. Wer hier etwas einfuegt, aendert die Vorlage mit.
 */
function au_status_felder()
{
    return array(
        'SOC'       => array('%',   'AU_FELD.SOC'),
        'TANK'      => array('%',   'AU_FELD.TANK'),
        'REICHW'    => array('km',  'AU_FELD.REICHW'),
        'KM'        => array('km',  'AU_FELD.KM'),
        'VERR'      => array('',    'AU_FELD.VERR'),
        'TUEREN'    => array('',    'AU_FELD.TUEREN'),
        'FENSTER'   => array('',    'AU_FELD.FENSTER'),
        'LICHT'     => array('',    'AU_FELD.LICHT'),
        'HANDBR'    => array('',    'AU_FELD.HANDBR'),
        'KLIMA'     => array('',    'AU_FELD.KLIMA'),
        'ZIELTEMP'  => array('&deg;C', 'AU_FELD.ZIELTEMP'),
        'AUSSEN'    => array('&deg;C', 'AU_FELD.AUSSEN'),
        'SCHEIBE'   => array('',    'AU_FELD.SCHEIBE'),
        'ZUSTAND'   => array('',    'AU_FELD.ZUSTAND'),
        'ERREICH'   => array('',    'AU_FELD.ERREICH'),
        'ALTER'     => array('s',   'AU_FELD.ALTER'),
        'OK'        => array('',    'AU_FELD.OK'),
    );
}

/** Die Werte des Lade-Endpunkts. */
function au_laden_felder()
{
    return array(
        'SOC'       => array('%',    'AU_LFELD.SOC'),
        'LAEDT'     => array('',     'AU_LFELD.LAEDT'),
        'LADEKW'    => array('kW',   'AU_LFELD.LADEKW'),
        'TEMPO'     => array('km/h', 'AU_LFELD.TEMPO'),
        'LADEGR'    => array('%',    'AU_LFELD.LADEGR'),
        'LADESTROM' => array('A',    'AU_LFELD.LADESTROM'),
        'KABEL'     => array('',     'AU_LFELD.KABEL'),
        'STECKER'   => array('',     'AU_LFELD.STECKER'),
        'REICHWBAT' => array('km',   'AU_LFELD.REICHWBAT'),
        'FERTIGMIN' => array('min',  'AU_LFELD.FERTIGMIN'),
        'OK'        => array('',     'AU_LFELD.OK'),
    );
}

/** Die Werte des Wartungs-Endpunkts. */
function au_wartung_felder()
{
    return array(
        'INSPTAGE'  => array('d',   'AU_WFELD.INSPTAGE'),
        'INSPKM'    => array('km',  'AU_WFELD.INSPKM'),
        'OELTAGE'   => array('d',   'AU_WFELD.OELTAGE'),
        'OELKM'     => array('km',  'AU_WFELD.OELKM'),
        'KM'        => array('km',  'AU_WFELD.KM'),
        'OK'        => array('',    'AU_WFELD.OK'),
    );
}

/** Vorlage fuer den Import in Loxone Config. Rueckgabe: array(name, inhalt) */
function au_vorlage($nummer = 1)
{
    $p = au_paths();
    $host = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
        ? preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST'])
        : (gethostname() ?: 'loxberry');
    $token = au_token();
    $cmds = array();
    foreach (au_status_felder() as $feld => $info) {
        // Der Text laeuft gleich durch au_x() und wuerde dort ein zweites Mal
        // maskiert. Deshalb erst Auszeichnung entfernen und Entitaeten
        // aufloesen - sonst stuende in Loxone Config wortwoertlich
        // 'l&auml;dt' statt 'laedt'.
        $bedeutung = trim(strip_tags(html_entity_decode(au_t($info[1]), ENT_QUOTES, 'UTF-8')));
        $einheit = trim(strip_tags(html_entity_decode($info[0], ENT_QUOTES, 'UTF-8')));
        $cmds[] = array(
            'title'   => 'AUDI_' . $nummer . '_' . $feld,
            'comment' => $bedeutung . ($einheit !== '' ? ' [' . $einheit . ']' : ''),
            'check'   => '\i' . $feld . '=\i\v',
        );
    }
    $adresse = 'http://' . $host . '/plugins/' . $p['plugin']
             . '/index.php?token=' . $token . '&aktion=status&fahrzeug=' . (int) $nummer;
    return array(
        'audi_fahrzeug' . (int) $nummer . '.xml',
        au_xml_virtual_in_http(array(
            'title'   => 'Audi ' . (int) $nummer,
            'address' => $adresse,
            'polling' => '300',
            'comment' => 'Erzeugt vom LoxBerry-Plugin Audi Connect (' . date('d.m.Y') . ')',
        ), $cmds),
    );
}

/* ==================================================================
 * Sprache (Pflicht: Deutsch und Englisch)
 *
 * Englisch ist die Rueckfallebene, nicht Deutsch: wer eine dritte Sprache
 * eingestellt hat, versteht eher Englisch. Deshalb muss language_en.ini immer
 * vollstaendig sein.
 *
 * Die Funktion setzt kein au_paths() voraus, damit derselbe Block in jedes
 * Plugin passt. Der Pfad wird zweistufig gesucht:
 *   installiert: <home>/templates/plugins/<ordner>/lang
 *   Archiv:      <pluginwurzel>/templates/lang
 * ================================================================== */

function au_sprache()
{
    $sprache = 'de';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'lblanguage')) {
        $sprache = LBSystem::lblanguage();
    } elseif (getenv('LBLANG')) {
        $sprache = getenv('LBLANG');
    }
    $sprache = strtolower(substr((string) $sprache, 0, 2));
    return in_array($sprache, array('de', 'en'), true) ? $sprache : 'en';
}

/**
 * Text zu einem Schluessel 'ABSCHNITT.SCHLUESSEL'.
 *
 * Ist der Schluessel unbekannt, wird er selbst zurueckgegeben - so faellt beim
 * Durchsehen sofort auf, was fehlt, statt dass die Seite leer bleibt.
 */
function au_t($schluessel)
{
    static $texte = null;
    if ($texte === null) {
        $home = getenv('LBHOMEDIR');
        if (!$home || !is_dir($home)) {
            foreach (array('/opt/loxberry', '/home/loxberry/loxberry') as $k) {
                if (is_dir($k)) {
                    $home = $k;
                    break;
                }
            }
        }
        $ordner = basename(dirname(__FILE__));
        $pfad = $home . '/templates/plugins/' . $ordner . '/lang';
        if (!is_dir($pfad)) {
            $pfad = dirname(dirname(dirname(__FILE__))) . '/templates/lang';
        }
        $texte = @parse_ini_file($pfad . '/language_' . au_sprache() . '.ini', true, INI_SCANNER_RAW);
        if (!is_array($texte)) {
            $texte = array();
        }
        $rueck = @parse_ini_file($pfad . '/language_en.ini', true, INI_SCANNER_RAW);
        if (is_array($rueck)) {
            $texte = array_replace_recursive($rueck, $texte);
        }
        // INI_SCANNER_RAW liefert die Werte samt der Anfuehrungszeichen
        // zurueck, in die sie in der Datei stehen muessen. Die gehoeren nicht
        // in die Ausgabe.
        foreach ($texte as $ab => $paare) {
            if (!is_array($paare)) {
                continue;
            }
            foreach ($paare as $s => $w) {
                $texte[$ab][$s] = trim((string) $w, '"');
            }
        }
    }
    $teile = array_pad(explode('.', $schluessel, 2), 2, '');
    $a = $teile[0];
    $s = $teile[1];
    return isset($texte[$a][$s]) ? $texte[$a][$s] : $schluessel;
}
