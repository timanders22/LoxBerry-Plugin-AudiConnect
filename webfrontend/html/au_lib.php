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
 *
 * ==========================================================================
 * NEU IN 0.9.8: EINE FELDLISTE STATT VIER
 * ==========================================================================
 * Bis 0.9.7 gab es au_status_felder(), au_laden_felder() und
 * au_wartung_felder() - und daneben, in webfrontend/html/index.php, drei
 * printf-Zeilen, die die Werte ausgaben. Die beiden Seiten sind
 * auseinandergelaufen: ALTER stand in der Lade- und der Wartungszeile, aber
 * in keiner der beiden Feldlisten; GRUND und FEHLERTEXT gingen an den
 * Miniserver hinaus und kamen in keiner Tabelle, keiner Sprachdatei und
 * keiner Loxone-Vorlage vor; fuer die Position gab es ueberhaupt keine Liste.
 *
 * Jetzt gibt es au_felder() als EINZIGE Quelle. Daraus entstehen die
 * Endpunktzeile (au_zeile()), die Tabellen der Oberflaeche und die
 * Loxone-Vorlagen. Eine Zeile, die es in die Antwort schafft, steht damit
 * zwangslaeufig auch in der Tabelle.
 */

if (!function_exists('au_e')) {
    function au_e($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}


/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins UND webfrontend enthaelt. Das trifft die uebliche
 * Installation genauso wie eine an einem anderen Ort - und es trifft auch
 * den Fall, dass das Plugin noch als entpacktes Archiv daliegt (dann findet
 * es nichts und gibt einen Leerstring zurueck, was der Aufrufer ohnehin
 * abfangen muss).
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert: zwei
 * Bibliotheken landen nie im selben Prozess, aber die Pruefung kostet nichts.
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

function au_paths()
{
    static $p = null;
    if ($p !== null) {
        return $p;
    }
    $home = getenv('LBHOMEDIR');
    if (!$home || !is_dir($home)) {
        foreach (array(lb_wurzel_ermitteln(), '/home/loxberry/loxberry') as $k) {
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
        //
        // Die Sicherung hiess hier bis 0.9.7 'vw.backup.json' - ein Rest der
        // Volkswagen-Schwesterlinie, aus der diese Datei stammt. Falsch war
        // sie nur im Entwicklungszweig, aber ein falscher Name ist eine
        // falsche Aussage, auch wenn ihn nie jemand liest.
        $basis = dirname(dirname(__DIR__));
        $p = array(
            'home'      => '',
            'plugin'    => $dir,
            'configdir' => $basis . '/config',
            'config'    => $basis . '/config/audi.json',
            'zugang'    => $basis . '/config/zugang.json',
            'sicherung' => $basis . '/config/audi.backup.json',
            'datadir'   => $basis . '/data',
            'bindir'    => $basis . '/bin',
            'logdir'    => $basis . '/log',
            'log'       => $basis . '/log/audi.log',
        );
    }
    return $p;
}

/**
 * Voreinstellungen. Muessen zu VORGABEN in bin/audi.py passen.
 *
 * Vier Schluessel gibt es NUR hier: aktionstoken, schalttoken, wartezeit und
 * nur_miniserver. Sie betreffen den Endpunkt und die Oberflaeche; der Dienst
 * liest sie nie.
 */
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
        // Eingreifende Befehle: ZWEITER Haken, ab Werk aus.
        'gefahr_ein'        => 0,
        'probe_ein'         => 0,
        'gps_ein'           => 1,
        'melden_ein'        => 1,
        // Drosselung
        'abruf_abstand'     => 60,
        'befehle_stunde'    => 30,
        'strom_abstand'     => 300,
        // Gerechnete Groessen
        'kapazitaet'        => 0,
        'heim_breite'       => '',
        'heim_laenge'       => '',
        'heim_radius'       => 150,
        // Vorklimatisierung am Abfahrtsassistenten
        'abfahrt_ein'       => 0,
        'abfahrt_praefix'   => 'abfahrt',
        'abfahrt_vorlauf'   => 20,
        'abfahrt_temp'      => 21,
        'abfahrt_alter'     => 300,
        'abfahrt_fahrzeug'  => 1,
        // Ladeempfehlung aus einem fremden Thema
        'ladeempf_ein'      => 0,
        'ladeempf_thema'    => '',
        'ladeempf_grenze'   => 0,
        'ladeempf_unter'    => 1,
        'ladeempf_alter'    => 900,
        // Nur hier, nicht im Dienst
        'aktionstoken'      => '',
        'schalttoken'       => '',
        'nur_miniserver'    => 0,
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
 *
 * Fuer die E-Mail gilt das NICHT in derselben Weise: sie steht sichtbar im
 * Formular, ein leeres Feld ist dort also eine Absicht. Damit aber ein
 * versehentlich geleertes Feld nicht das Konto verwaist, gibt der Aufrufer
 * null statt Leerstring, wenn er nichts aendern will.
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
 * Sorgt dafuer, dass beide Token vorhanden sind, und gibt das gewuenschte
 * zurueck. $art ist 'lesen' oder 'schalten'.
 *
 * WARUM ES SEIT 0.9.8 ZWEI SIND
 * Das Token steht in JEDER Adresse, die in Loxone Config eingetragen wird -
 * auch in den nur lesenden. Wer es dort abliest (und in Loxone Config liest
 * jeder mit, der die Datei bekommt), konnte damit bis 0.9.7 auch die
 * Klimaanlage starten und den Ladevorgang anhalten. Lesen und Eingreifen
 * haben jetzt getrennte Token: das Lesetoken darf herumliegen, das
 * Schalttoken gehoert nur in die virtuellen Ausgaenge.
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
 * danach hereinkommt, liest die Konfiguration erneut und findet die gerade
 * geschriebenen Token vor.
 */
function au_token($art = 'lesen')
{
    $schluessel = ($art === 'schalten') ? 'schalttoken' : 'aktionstoken';
    $cfg = au_config();
    if (trim((string) $cfg['aktionstoken']) !== ''
        && trim((string) $cfg['schalttoken']) !== '') {
        return (string) $cfg[$schluessel];
    }

    $p = au_paths();
    if (!is_dir($p['configdir'])) {
        @mkdir($p['configdir'], 0775, true);
    }
    $sperre = $p['configdir'] . '/token.lock';
    $fh = @fopen($sperre, 'c');
    if ($fh === false) {
        // Ohne Sperre lieber Token erzeugen als gar keine - ein
        // beschreibbares Verzeichnis vorausgesetzt, kommt dieser Fall nicht vor.
        $cfg = au_token_ergaenzen($cfg);
        au_config_speichern($cfg);
        return (string) $cfg[$schluessel];
    }
    @flock($fh, LOCK_EX);

    // Zweite Pruefung hinter der Sperre: waehrend des Wartens hat ein
    // anderer Prozess vermutlich schon geschrieben.
    $cfg = au_config();
    $vorher = $cfg['aktionstoken'] . '|' . $cfg['schalttoken'];
    $cfg = au_token_ergaenzen($cfg);
    if ($cfg['aktionstoken'] . '|' . $cfg['schalttoken'] !== $vorher) {
        au_config_speichern($cfg);
    }

    @flock($fh, LOCK_UN);
    @fclose($fh);
    return (string) $cfg[$schluessel];
}

/** Ergaenzt fehlende Token, ohne vorhandene anzufassen. */
function au_token_ergaenzen($cfg)
{
    if (trim((string) (isset($cfg['aktionstoken']) ? $cfg['aktionstoken'] : '')) === '') {
        $cfg['aktionstoken'] = au_token_erzeugen();
    }
    if (trim((string) (isset($cfg['schalttoken']) ? $cfg['schalttoken'] : '')) === '') {
        $cfg['schalttoken'] = au_token_erzeugen();
    }
    return $cfg;
}

/**
 * Ein Formulartoken gegen fremde Absender.
 *
 * Die Oberflaeche liegt hinter der LoxBerry-Anmeldung, aber ein Formular, das
 * jede POST-Anfrage ausfuehrt, laesst sich von einer anderen Seite aus
 * abschicken, solange der Browser noch angemeldet ist. Betroffen waeren hier
 * Dienststart, Tokenwechsel und - schlimmer - die schaltenden Knoepfe des
 * Reiters Test.
 *
 * Abgeleitet aus dem Aktionstoken, damit es keine Sitzungsverwaltung braucht:
 * dieselbe Anlage ergibt denselben Wert, eine fremde Seite kennt ihn nicht.
 */
function au_formtoken($cfg = null)
{
    if ($cfg === null) {
        $cfg = au_config();
    }
    $basis = (string) (isset($cfg['aktionstoken']) ? $cfg['aktionstoken'] : '');
    if ($basis === '') {
        return '';
    }
    return hash_hmac('sha256', 'formular-v1', $basis);
}

function au_formtoken_pruefen($cfg = null)
{
    $soll = au_formtoken($cfg);
    $ist = isset($_POST['formtoken']) ? (string) $_POST['formtoken'] : '';
    if ($soll === '' || $ist === '') {
        return false;
    }
    return hash_equals($soll, $ist);
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
    /* Nummernrecycling ausschliessen: der Prozess muss unser Skript sein.
     *
     * Bis 0.9.6 stand hier strpos($cmd, 'audi.py'). Der Rahmen war schon
     * richtig - geprueft wird nur die Nummer aus der eigenen PID-Datei, es
     * wird nichts gesucht -, aber die Pruefung selbst zu weich:
     * /proc/<pid>/cmdline enthaelt ALLE Argumente, durch Nullbytes getrennt.
     * Hat die wiederverwendete Nummer einen Editor mit geoeffneter audi.py
     * erwischt, galt der als laufender Dienst. Die Oberflaeche reihte dann
     * Befehle ein, die niemand abarbeitet, und meldete "eingereiht" statt
     * "laeuft nicht".
     *
     * Verglichen wird jetzt argumentweise gegen den vollen Pfad. Das trifft
     * auch den Fall zweier Exemplare des Plugins: LoxBerry haengt bei
     * Namenskonflikt 01, 02 ... an den Ordnernamen an.
     *
     * DIESELBE Pruefung steht seit 0.9.8 auch in bin/dienst.sh. Dort stand
     * bis dahin weiterhin das weiche grep - und weil dienst.sh den
     * minuetlichen Waechter traegt, entschied die WEICHE Fassung darueber,
     * ob ein toter Dienst neu gestartet wird. Die Oberflaeche zeigte
     * "gestoppt", der Waechter tat nichts. */
    $cmd = (string) @file_get_contents('/proc/' . $pid . '/cmdline');
    $argv = explode("\0", $cmd);
    $skript = au_paths()['bindir'] . '/audi.py';
    /* Zwei Bedingungen, nicht eine:
     *   argv[1] ist genau unser Skript UND
     *   argv[0] ist ein Python.
     * Die zweite braucht es, weil "nano /pfad/audi.py" ebenfalls den vollen
     * Pfad als zweites Argument fuehrt. Der Dienst wird immer als
     * "<venv>/bin/python3 <pfad>/audi.py" gestartet. */
    if (isset($argv[0], $argv[1])
        && $argv[1] === $skript
        && preg_match('#(^|/)python[0-9.]*$#', $argv[0])) {
        return $pid;
    }
    return 0;
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
 * Fassungen der Python-Pakete in der virtuellen Umgebung.
 *
 * Es sind drei: der Kern (carconnectivity), der Audi-Connector und - seit
 * 0.9.8 - paho-mqtt fuer den Horcher. Kern und Connector werden getrennt
 * veroeffentlicht und koennen auseinanderlaufen; paho fehlt, wenn die
 * Installation ohne Internetverbindung lief.
 *
 * Rueckgabe: array('kern' => '0.11.10', 'connector' => '0.3.2',
 *                  'paho' => '2.1.0'); nicht ermittelbare Werte bleiben ''.
 */
function au_bibliothek_fassungen()
{
    $py = au_paths()['bindir'] . '/venv/bin/python3';
    $leer = array('kern' => '', 'connector' => '', 'paho' => '');
    if (!is_file($py)) {
        return $leer;
    }
    $ausgabe = array();
    @exec(escapeshellarg($py) . ' -c ' . escapeshellarg(
        'import importlib.metadata as m' . "\n"
        . 'for p in ("carconnectivity", "carconnectivity-connector-audi", "paho-mqtt"):' . "\n"
        . '    try: print(m.version(p))' . "\n"
        . '    except Exception: print("")'
    ) . ' 2>/dev/null', $ausgabe);
    return array(
        'kern'      => isset($ausgabe[0]) ? trim($ausgabe[0]) : '',
        'connector' => isset($ausgabe[1]) ? trim($ausgabe[1]) : '',
        'paho'      => isset($ausgabe[2]) ? trim($ausgabe[2]) : '',
    );
}

/** Kurzform fuer die Anzeige: 'Kern 0.11.10 / Connector 0.3.2' oder ''. */
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

/**
 * Die letzten Zeilen der Logdatei - BLOCKWEISE vom Ende her.
 *
 * Bis 0.9.7 stand hier file(), also die ganze Datei im Arbeitsspeicher. Bei
 * 512 KB und einem LoxBerry auf einem Raspberry Pi ist das kein Absturz, aber
 * es ist unnoetig, und der Hausstandard verlangt ausdruecklich das Gegenteil.
 * exec("tail") kommt nicht in Frage - ein Prozessaufruf je Seitenaufbau.
 */
function au_log_ende($datei, $anzahl = 400, $block = 8192)
{
    if (!is_file($datei)) {
        return array();
    }
    $fh = @fopen($datei, 'rb');
    if ($fh === false) {
        return array();
    }
    $groesse = filesize($datei);
    $puffer = '';
    $pos = $groesse;
    $zeilen = 0;
    while ($pos > 0 && $zeilen <= $anzahl) {
        $lese = min($block, $pos);
        $pos -= $lese;
        if (fseek($fh, $pos, SEEK_SET) !== 0) {
            break;
        }
        $stueck = (string) fread($fh, $lese);
        $puffer = $stueck . $puffer;
        $zeilen = substr_count($puffer, "\n");
    }
    fclose($fh);
    $alle = preg_split('/\r\n|\r|\n/', $puffer);
    if (!is_array($alle)) {
        return array();
    }
    $alle = array_values(array_filter($alle, function ($z) { return trim($z) !== ''; }));
    return array_slice($alle, -$anzahl);
}

/* ---------------- Befehlskatalog ----------------
 *
 * EINE Liste fuer alles: die Weissliste des Endpunkts, die Tabelle im Reiter
 * "Einbindung in Loxone", die Vorlage des virtuellen Ausgangs und die Knoepfe
 * des Reiters Test. Bis 0.9.7 standen die Befehle an vier Stellen, und die
 * Tabelle der virtuellen Ausgaenge hatte drei davon verloren: scheibe_aus,
 * wecken und zieltemperatur fehlten dort.
 *
 * Je Eintrag:
 *   bez      Sprachschluessel der Bezeichnung
 *   zusatz   '', 'temp', 'prozent', 'ampere', 'einstellung', 'dauer'
 *   gefahr   1 = braucht ZUSAETZLICH den Haken 'Eingreifende Befehle'
 *   gegen    Aktion, die als Ausbefehl an denselben virtuellen Ausgang gehoert
 *   ohne_fz  1 = kennt keinen Fahrzeugparameter
 */
function au_befehle()
{
    return array(
        'abruf'          => array('bez' => 'BEFEHL.ABRUF',       'zusatz' => '',
                                  'gefahr' => 0, 'gegen' => '', 'ohne_fz' => 1),
        'klima_start'    => array('bez' => 'BEFEHL.KLIMA',       'zusatz' => 'temp',
                                  'gefahr' => 0, 'gegen' => 'klima_stop', 'ohne_fz' => 0),
        'klima_stop'     => array('bez' => 'BEFEHL.KLIMA_AUS',   'zusatz' => '',
                                  'gefahr' => 0, 'gegen' => '', 'ohne_fz' => 0),
        'zieltemperatur' => array('bez' => 'BEFEHL.ZIELTEMP',    'zusatz' => 'temp',
                                  'gefahr' => 0, 'gegen' => '', 'ohne_fz' => 0),
        'laden_start'    => array('bez' => 'BEFEHL.LADEN',       'zusatz' => '',
                                  'gefahr' => 0, 'gegen' => 'laden_stop', 'ohne_fz' => 0),
        'laden_stop'     => array('bez' => 'BEFEHL.LADEN_AUS',   'zusatz' => '',
                                  'gefahr' => 0, 'gegen' => '', 'ohne_fz' => 0),
        'ladegrenze'     => array('bez' => 'BEFEHL.LADEGRENZE',  'zusatz' => 'prozent',
                                  'gefahr' => 0, 'gegen' => '', 'ohne_fz' => 0),
        'ladestrom'      => array('bez' => 'BEFEHL.LADESTROM',   'zusatz' => 'ampere',
                                  'gefahr' => 0, 'gegen' => '', 'ohne_fz' => 0),
        'scheibe_ein'    => array('bez' => 'BEFEHL.SCHEIBE',     'zusatz' => '',
                                  'gefahr' => 0, 'gegen' => 'scheibe_aus', 'ohne_fz' => 0),
        'scheibe_aus'    => array('bez' => 'BEFEHL.SCHEIBE_AUS', 'zusatz' => '',
                                  'gefahr' => 0, 'gegen' => '', 'ohne_fz' => 0),
        'wecken'         => array('bez' => 'BEFEHL.WECKEN',      'zusatz' => '',
                                  'gefahr' => 0, 'gegen' => '', 'ohne_fz' => 0),
        'einstellung'    => array('bez' => 'BEFEHL.EINSTELLUNG', 'zusatz' => 'einstellung',
                                  'gefahr' => 0, 'gegen' => '', 'ohne_fz' => 0),
        'spin_pruefen'   => array('bez' => 'BEFEHL.SPIN',        'zusatz' => '',
                                  'gefahr' => 0, 'gegen' => '', 'ohne_fz' => 1),
        // Ab hier: eingreifend. Zweiter Haken noetig.
        'verriegeln'     => array('bez' => 'BEFEHL.VERRIEGELN',  'zusatz' => '',
                                  'gefahr' => 1, 'gegen' => 'entriegeln', 'ohne_fz' => 0),
        'entriegeln'     => array('bez' => 'BEFEHL.ENTRIEGELN',  'zusatz' => '',
                                  'gefahr' => 1, 'gegen' => '', 'ohne_fz' => 0),
        'hupe'           => array('bez' => 'BEFEHL.HUPE',        'zusatz' => 'dauer',
                                  'gefahr' => 1, 'gegen' => '', 'ohne_fz' => 0),
        'lichthupe'      => array('bez' => 'BEFEHL.LICHTHUPE',   'zusatz' => 'dauer',
                                  'gefahr' => 1, 'gegen' => '', 'ohne_fz' => 0),
    );
}

/** Die Ja/Nein-Einstellungen, die 'einstellung' setzen kann.
 *  Muss zu SCHALTER in bin/audi.py passen. */
function au_einstellungen()
{
    return array(
        'stecker_auto'     => 'EINSTELLUNG.STECKER_AUTO',
        'klima_unlock'     => 'EINSTELLUNG.KLIMA_UNLOCK',
        'scheibe_dauer'    => 'EINSTELLUNG.SCHEIBE_DAUER',
        'klima_ohne_strom' => 'EINSTELLUNG.KLIMA_OHNE_STROM',
        'sitzheizung'      => 'EINSTELLUNG.SITZHEIZUNG',
        'zone_vl'          => 'EINSTELLUNG.ZONE_VL',
        'zone_vr'          => 'EINSTELLUNG.ZONE_VR',
        'zone_hl'          => 'EINSTELLUNG.ZONE_HL',
        'zone_hr'          => 'EINSTELLUNG.ZONE_HR',
    );
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
 *
 * Dass die eingestellte Wartezeit hier UEBERSTIMMT wird, steht seit 0.9.8
 * auch im Hilfetext des Feldes - bis dahin stand es nur in diesem Kommentar,
 * und wer 0 einstellte, wartete trotzdem 30 Sekunden.
 */
function au_wartezeit_fuer($aktion, $vorgabe)
{
    // Ohne 'standheizung_start'/'standheizung_stop': diese beiden Aktionen
    // standen bis 0.9.7 hier, gab es aber weder im Endpunkt noch im Dienst
    // noch im Reiter Test. Ein toter Eintrag ist eine Behauptung ueber eine
    // Funktion, die es nicht gibt.
    $lang = array('klima_start', 'klima_stop', 'wecken', 'laden_start', 'laden_stop',
                  'verriegeln', 'entriegeln', 'hupe', 'lichthupe');
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

/* ---------------- Verlauf und Ladevorgaenge ---------------- */

/**
 * Messpunkte eines Tages: Array von array(ts, fuellstand, reichweite, km).
 *
 * Dateien aus 0.9.7 haben nur drei Spalten. Die vierte fehlt dann - das ist
 * kein Fehler, sondern das Alter der Datei, und deshalb wird sie zu null und
 * nicht zu 0.
 */
function au_verlauf_lesen($nummer, $tag = '')
{
    if ($tag === '') {
        $tag = date('Ymd');
    }
    $f = au_paths()['datadir'] . '/verlauf/fahrzeug' . (int) $nummer . '_'
       . preg_replace('/[^0-9]/', '', (string) $tag) . '.csv';
    $out = array();
    if (is_file($f)) {
        foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $zeile) {
            $c = explode(';', $zeile);
            if (count($c) >= 2) {
                $out[] = array(
                    (int) $c[0],
                    (float) $c[1],
                    isset($c[2]) && $c[2] !== '' ? (float) $c[2] : 0,
                    isset($c[3]) && $c[3] !== '' ? (float) $c[3] : null,
                );
            }
        }
    }
    return $out;
}

/** Welche Tage liegen fuer ein Fahrzeug vor? Neueste zuerst, als 'Ymd'. */
function au_verlauf_tage($nummer)
{
    $muster = au_paths()['datadir'] . '/verlauf/fahrzeug' . (int) $nummer . '_*.csv';
    $tage = array();
    foreach (glob($muster) ?: array() as $datei) {
        if (preg_match('/_([0-9]{8})\.csv$/', $datei, $m)) {
            $tage[] = $m[1];
        }
    }
    rsort($tage);
    return $tage;
}

/**
 * Die protokollierten Ladevorgaenge, neueste zuerst.
 *
 * Geschrieben von bin/audi.py, sobald ein Ladevorgang endet. Die Menge in kWh
 * entsteht nur, wenn eine Batteriekapazitaet hinterlegt ist - sonst bleibt
 * das Feld leer, und zwar leer und nicht 0.
 */
function au_ladungen_lesen($grenze = 200)
{
    $f = au_paths()['datadir'] . '/verlauf/ladungen.csv';
    if (!is_file($f)) {
        return array();
    }
    $aus = array();
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $zeile) {
        if ($zeile === '' || $zeile[0] === '#') {
            continue;
        }
        $c = explode(';', $zeile);
        if (count($c) < 8) {
            continue;
        }
        $aus[] = array(
            'fahrzeug'  => $c[0],
            'start'     => (int) $c[1],
            'ende'      => (int) $c[2],
            'dauer'     => $c[3] === '' ? null : (int) $c[3],
            'soc_start' => $c[4] === '' ? null : (float) $c[4],
            'soc_ende'  => $c[5] === '' ? null : (float) $c[5],
            'km'        => $c[6] === '' ? null : (float) $c[6],
            'kwh'       => $c[7] === '' ? null : (float) $c[7],
        );
    }
    // Neueste zuerst, und nur so viele, wie die Seite tragen kann.
    usort($aus, function ($a, $b) {
        return $b['start'] - $a['start'];
    });
    return array_slice($aus, 0, max(1, (int) $grenze));
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

/**
 * Welche FREMDEN Themen erwartet die Oberflaeche?
 *
 * Dieselbe Liste fuehrt der Dienst in horcher_themen(). Sie steht absichtlich
 * zweimal da: die Selbstpruefung vergleicht diese Erwartung mit dem, was der
 * Dienst wirklich abonniert hat. Ein Abo, das der Dienst nach einer Aenderung
 * nicht nachgezogen hat, ist unsichtbar, solange niemand beide Listen
 * nebeneinander legt - die Vorklimatisierung loest dann einfach nie aus, und
 * das sieht aus wie "der Assistent sendet nichts".
 */
function au_horcher_themen($cfg = null)
{
    if ($cfg === null) {
        $cfg = au_config();
    }
    $t = array();
    if (!empty($cfg['abfahrt_ein'])) {
        $pfad = trim((string) (isset($cfg['abfahrt_praefix']) ? $cfg['abfahrt_praefix'] : ''), '/');
        if ($pfad !== '') {
            $t[] = $pfad . '/ABFAHRT_IN';
            $t[] = $pfad . '/OK';
        }
    }
    if (!empty($cfg['ladeempf_ein'])) {
        $th = trim((string) (isset($cfg['ladeempf_thema']) ? $cfg['ladeempf_thema'] : ''));
        if ($th !== '') {
            $t[] = $th;
        }
    }
    sort($t);
    return $t;
}

/**
 * Der Zustand des Horchers, wie ihn der Dienst hinterlegt hat.
 *
 * Die Oberflaeche baut KEINE eigene Verbindung zum Broker auf: sie liest, was
 * der Dienst zuletzt gesehen hat. Zwei Verbindungen zum selben Broker aus
 * zwei Prozessen waeren zwei Stellen, die auseinanderlaufen - und die
 * Oberflaeche wird bei jedem Klick gerendert.
 */
function au_horcher_zustand()
{
    $z = au_zustand();
    return array(
        'themen'    => isset($z['horcher']) && is_array($z['horcher']) ? $z['horcher'] : array(),
        'verbunden' => !empty($z['horcher_verbunden']) ? 1 : 0,
        'fehler'    => isset($z['horcher_fehler']) ? (string) $z['horcher_fehler'] : '',
    );
}

/** Alle Themen, die der Dienst veroeffentlicht, mit ihrer Bedeutung.
 *  Entsteht aus der Feldliste - so kann die Tabelle nicht von dem abweichen,
 *  was MQTT_FELDER in bin/audi.py wirklich sendet. */
function au_mqtt_themen()
{
    $t = array(
        'ok'        => 'AU_MQTT.OK',
        'grund'     => 'AU_MQTT.GRUND',
        'fahrzeuge' => 'AU_MQTT.FAHRZEUGE',
    );
    foreach (au_felder() as $feld => $info) {
        if ($info['mqtt'] === '' || $info['mqtt'] === null) {
            continue;
        }
        $t['fahrzeugN/' . $info['mqtt']] = $info['bez'];
    }
    /* Zwei Werte gehen NUR ueber MQTT hinaus, und zwar als Unix-Zeit: an den
     * Endpunkten stehen sie als Restminuten (FERTIGMIN, KLIMAFERTIG), weil
     * Loxone mit einer Restzeit mehr anfangen kann als mit einem Zeitstempel.
     * Ueber MQTT ist der Zeitstempel dagegen brauchbar. Sie stehen deshalb
     * hier eigens - ein Thema, das der Dienst sendet und keine Tabelle nennt,
     * ist ein Wert, den niemand findet. */
    $t['fahrzeugN/laden_fertig_um'] = 'AU_MQTT.FERTIG';
    $t['fahrzeugN/klima_fertig_um'] = 'AU_MQTT.KLIMAFERTIG';
    foreach (array('zustand_text' => 'AU_MQTT.ZUSTAND_TEXT',
                   'klima_text' => 'AU_MQTT.KLIMA_TEXT',
                   'ladezustand_text' => 'AU_MQTT.LADE_TEXT',
                   'tueren_namen' => 'AU_MQTT.TUEREN_NAMEN',
                   'fenster_namen' => 'AU_MQTT.FENSTER_NAMEN',
                   'adresse' => 'AU_MQTT.ADRESSE',
                   'saeule_name' => 'AU_MQTT.SAEULE',
                   'modell' => 'AU_MQTT.MODELL',
                   'vin' => 'AU_MQTT.VIN') as $k => $b) {
        $t['fahrzeugN/' . $k] = $b;
    }
    return $t;
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
 * Der virtuelle AUSGANG - die Befehle zum Importieren.
 *
 * Bis 0.9.7 gab es das nicht: alle Schaltbefehle mussten aus einer Tabelle
 * abgetippt werden, jede Zeile mit einem 24-stelligen Token darin. Aufbau
 * uebernommen aus LoxBerry-Plugin-BYD-Autos-0.9.1 (by_xml_virtual_out).
 *
 * HintText am Wurzelelement und CmdInit gehoeren dazu - Loxone Config
 * schreibt sie beim Export ebenfalls, und eine Vorlage, die davon abweicht,
 * sieht nach dem ersten Speichern anders aus als beim Import.
 */
function au_xml_virtual_out($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualOut ';
    $o .= 'Title="' . au_x($kopf['title']) . '" ';
    $o .= 'Comment="' . au_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . au_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'CmdInit="" ';
    $o .= 'CloseAfterSend="false" ';
    $o .= 'CmdSep="" ';
    $o .= 'HintText="' . au_x(isset($kopf['hint']) ? $kopf['hint'] : '') . '"';
    $o .= '>' . $crlf;
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualOutCmd ';
        $o .= 'Title="' . au_x($c['title']) . '" ';
        $o .= 'Comment="' . au_x(isset($c['comment']) ? $c['comment'] : '') . '" ';
        $o .= 'CmdOnMethod="' . au_x(isset($c['on']) ? $c['on'] : '') . '" ';
        $o .= 'CmdOffMethod="' . au_x(isset($c['off']) ? $c['off'] : '') . '" ';
        $o .= 'Analog="' . (empty($c['analog']) ? 'false' : 'true') . '" ';
        $o .= 'Repeat="0" ';
        $o .= 'RepeatRate="0"';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualOut>' . $crlf;
    return $o;
}

/* ==================================================================
 * DIE FELDLISTE - eine einzige Quelle
 *
 * Je Eintrag:
 *   quelle_feld  Schluessel im Abbild (bin/audi.py), '' bei gerechneten
 *                Groessen, die erst hier entstehen
 *   einheit      wie sie in der Tabelle steht
 *   bez          Sprachschluessel der Bedeutung
 *   zeilen       an welchen Endpunkten das Feld erscheint
 *   herkunft     'connector' = kommt von Audi
 *                'gerechnet' = das Plugin bildet ihn selbst
 *                'bestand'   = ueber den Zwischenspeicher bekannt
 *                'leer'      = der Audi-Connector 0.3.2 fuellt ihn NIE
 *                              (gemessen, siehe Kopf von bin/audi.py)
 *   mqtt         Themenname unter fahrzeugN/, '' wenn nicht gesendet
 *
 * REIHENFOLGE: Neues gehoert ans ENDE der jeweiligen Zeile. Loxone sucht
 * zwar mit einer Befehlserkennung nach dem NAMEN und nicht nach der Position,
 * aber wer die Reihenfolge aendert, aendert auch die erzeugte Vorlage - und
 * dann sieht ein Vergleich zweier Vorlagen nach einer Aenderung aus, die
 * keine ist.
 * ================================================================== */
function au_felder()
{
    return array(
        // OK steht in JEDER Zeile an erster Stelle. Es hat kein Abbildfeld -
        // der Wert entsteht aus Zeitstempel und Fehlerlage - gehoert aber in
        // die Liste, damit es in den Tabellen und in der Loxone-Vorlage
        // erscheint. Ohne Eintrag waere es der einzige Wert, den der Endpunkt
        // sendet und keine Tabelle nennt.
        'OK'         => array('quelle_feld' => '', 'einheit' => '',
                              'bez' => 'AU_FELD.OK',
                              'zeilen' => array('status', 'laden', 'wartung', 'position'),
                              'herkunft' => 'bestand', 'mqtt' => ''),
        // ---- Status -------------------------------------------------------
        'SOC'        => array('quelle_feld' => 'soc', 'einheit' => '%',
                              'bez' => 'AU_FELD.SOC', 'zeilen' => array('status', 'laden'),
                              'herkunft' => 'connector', 'mqtt' => 'soc'),
        'TANK'       => array('quelle_feld' => 'tank_prozent', 'einheit' => '%',
                              'bez' => 'AU_FELD.TANK', 'zeilen' => array('status'),
                              'herkunft' => 'connector', 'mqtt' => 'tank_prozent'),
        'REICHW'     => array('quelle_feld' => 'reichweite_km', 'einheit' => 'km',
                              'bez' => 'AU_FELD.REICHW', 'zeilen' => array('status'),
                              'herkunft' => 'connector', 'mqtt' => 'reichweite_km'),
        // Beim Plug-in-Hybrid ist REICHW die Summe. Die elektrische Haelfte
        // steht als REICHWBAT in der Ladezeile; ohne diese hier liesse sich
        // die Summe nicht aufteilen.
        'REICHWVERBR' => array('quelle_feld' => 'reichweite_verbrenner_km', 'einheit' => 'km',
                              'bez' => 'AU_FELD.REICHWVERBR', 'zeilen' => array('status'),
                              'herkunft' => 'connector', 'mqtt' => 'reichweite_verbrenner_km'),
        'KM'         => array('quelle_feld' => 'kilometerstand', 'einheit' => 'km',
                              'bez' => 'AU_FELD.KM', 'zeilen' => array('status', 'wartung'),
                              'herkunft' => 'connector', 'mqtt' => 'kilometerstand'),
        'VERR'       => array('quelle_feld' => 'verriegelt', 'einheit' => '',
                              'bez' => 'AU_FELD.VERR', 'zeilen' => array('status'),
                              'herkunft' => 'connector', 'mqtt' => 'verriegelt'),
        'TUEREN'     => array('quelle_feld' => 'tueren_offen', 'einheit' => '',
                              'bez' => 'AU_FELD.TUEREN', 'zeilen' => array('status'),
                              'herkunft' => 'connector', 'mqtt' => 'tueren_offen'),
        'FENSTER'    => array('quelle_feld' => 'fenster_offen', 'einheit' => '',
                              'bez' => 'AU_FELD.FENSTER', 'zeilen' => array('status'),
                              'herkunft' => 'connector', 'mqtt' => 'fenster_offen'),
        'LICHT'      => array('quelle_feld' => 'licht_an', 'einheit' => '',
                              'bez' => 'AU_FELD.LICHT', 'zeilen' => array('status'),
                              'herkunft' => 'connector', 'mqtt' => 'licht_an'),
        'HANDBR'     => array('quelle_feld' => 'handbremse', 'einheit' => '',
                              'bez' => 'AU_FELD.HANDBR', 'zeilen' => array('status'),
                              'herkunft' => 'leer', 'mqtt' => 'handbremse'),
        'KLIMA'      => array('quelle_feld' => 'klima_an', 'einheit' => '',
                              'bez' => 'AU_FELD.KLIMA', 'zeilen' => array('status'),
                              'herkunft' => 'connector', 'mqtt' => 'klima_an'),
        'ZIELTEMP'   => array('quelle_feld' => 'zieltemperatur', 'einheit' => '&deg;C',
                              'bez' => 'AU_FELD.ZIELTEMP', 'zeilen' => array('status'),
                              'herkunft' => 'connector', 'mqtt' => 'zieltemperatur'),
        'AUSSEN'     => array('quelle_feld' => 'aussentemperatur', 'einheit' => '&deg;C',
                              'bez' => 'AU_FELD.AUSSEN', 'zeilen' => array('status'),
                              'herkunft' => 'connector', 'mqtt' => 'aussentemperatur'),
        'SCHEIBE'    => array('quelle_feld' => 'scheibenheizung', 'einheit' => '',
                              'bez' => 'AU_FELD.SCHEIBE', 'zeilen' => array('status'),
                              'herkunft' => 'connector', 'mqtt' => 'scheibenheizung'),
        'ZUSTAND'    => array('quelle_feld' => 'zustand', 'einheit' => '',
                              'bez' => 'AU_FELD.ZUSTAND', 'zeilen' => array('status'),
                              'herkunft' => 'connector', 'mqtt' => 'zustand'),
        'ERREICH'    => array('quelle_feld' => 'erreichbar', 'einheit' => '',
                              'bez' => 'AU_FELD.ERREICH', 'zeilen' => array('status'),
                              'herkunft' => 'connector', 'mqtt' => 'erreichbar'),
        // ---- Neu in 0.9.8, Status ----------------------------------------
        'TUERANZ'    => array('quelle_feld' => 'tueren_anzahl', 'einheit' => '',
                              'bez' => 'AU_FELD.TUERANZ', 'zeilen' => array('status'),
                              'herkunft' => 'connector', 'mqtt' => 'tueren_anzahl'),
        'FENSTERANZ' => array('quelle_feld' => 'fenster_anzahl', 'einheit' => '',
                              'bez' => 'AU_FELD.FENSTERANZ', 'zeilen' => array('status'),
                              'herkunft' => 'connector', 'mqtt' => 'fenster_anzahl'),
        'KLIMAART'   => array('quelle_feld' => 'klima_stufe', 'einheit' => '',
                              'bez' => 'AU_FELD.KLIMAART', 'zeilen' => array('status'),
                              'herkunft' => 'connector', 'mqtt' => 'klima_stufe'),
        'KLIMAFERTIG' => array('quelle_feld' => '', 'einheit' => 'min',
                              'bez' => 'AU_FELD.KLIMAFERTIG', 'zeilen' => array('status'),
                              'herkunft' => 'gerechnet', 'mqtt' => ''),
        'SITZHEIZ'   => array('quelle_feld' => 'sitzheizung_ein', 'einheit' => '',
                              'bez' => 'AU_FELD.SITZHEIZ', 'zeilen' => array('status'),
                              'herkunft' => 'connector', 'mqtt' => 'sitzheizung_ein'),
        'KLIMAUNLOCK' => array('quelle_feld' => 'klima_bei_entriegeln', 'einheit' => '',
                              'bez' => 'AU_FELD.KLIMAUNLOCK', 'zeilen' => array('status'),
                              'herkunft' => 'connector', 'mqtt' => 'klima_bei_entriegeln'),
        'AKTIV'      => array('quelle_feld' => 'aktiv', 'einheit' => '',
                              'bez' => 'AU_FELD.AKTIV', 'zeilen' => array('status'),
                              'herkunft' => 'connector', 'mqtt' => 'aktiv'),
        'STANDZEIT'  => array('quelle_feld' => 'standzeit_min', 'einheit' => 'min',
                              'bez' => 'AU_FELD.STANDZEIT', 'zeilen' => array('status'),
                              'herkunft' => 'gerechnet', 'mqtt' => 'standzeit_min'),
        'FZOK'       => array('quelle_feld' => 'ok', 'einheit' => '',
                              'bez' => 'AU_FELD.FZOK', 'zeilen' => array('status'),
                              'herkunft' => 'bestand', 'mqtt' => 'ok'),
        'AUSFALL'    => array('quelle_feld' => '', 'einheit' => '',
                              'bez' => 'AU_FELD.AUSFALL', 'zeilen' => array('status'),
                              'herkunft' => 'gerechnet', 'mqtt' => ''),
        'FEHLFOLGE'  => array('quelle_feld' => 'fehlfolge', 'einheit' => '',
                              'bez' => 'AU_FELD.FEHLFOLGE', 'zeilen' => array('status'),
                              'herkunft' => 'gerechnet', 'mqtt' => 'fehlfolge'),
        // ---- Laden --------------------------------------------------------
        'LAEDT'      => array('quelle_feld' => 'laedt', 'einheit' => '',
                              'bez' => 'AU_LFELD.LAEDT', 'zeilen' => array('laden'),
                              'herkunft' => 'connector', 'mqtt' => 'laedt'),
        'LADEKW'     => array('quelle_feld' => 'ladeleistung_kw', 'einheit' => 'kW',
                              'bez' => 'AU_LFELD.LADEKW', 'zeilen' => array('laden'),
                              'herkunft' => 'connector', 'mqtt' => 'ladeleistung_kw'),
        'TEMPO'      => array('quelle_feld' => 'ladetempo_kmh', 'einheit' => 'km/h',
                              'bez' => 'AU_LFELD.TEMPO', 'zeilen' => array('laden'),
                              'herkunft' => 'connector', 'mqtt' => 'ladetempo_kmh'),
        'LADEGR'     => array('quelle_feld' => 'ladegrenze', 'einheit' => '%',
                              'bez' => 'AU_LFELD.LADEGR', 'zeilen' => array('laden'),
                              'herkunft' => 'connector', 'mqtt' => 'ladegrenze'),
        'LADESTROM'  => array('quelle_feld' => 'ladestrom_a', 'einheit' => 'A',
                              'bez' => 'AU_LFELD.LADESTROM', 'zeilen' => array('laden'),
                              'herkunft' => 'connector', 'mqtt' => 'ladestrom_a'),
        'KABEL'      => array('quelle_feld' => 'kabel_verbunden', 'einheit' => '',
                              'bez' => 'AU_LFELD.KABEL', 'zeilen' => array('laden'),
                              'herkunft' => 'connector', 'mqtt' => 'kabel_verbunden'),
        'STECKER'    => array('quelle_feld' => 'stecker_verriegelt', 'einheit' => '',
                              'bez' => 'AU_LFELD.STECKER', 'zeilen' => array('laden'),
                              'herkunft' => 'connector', 'mqtt' => 'stecker_verriegelt'),
        'REICHWBAT'  => array('quelle_feld' => 'reichweite_elektro_km', 'einheit' => 'km',
                              'bez' => 'AU_LFELD.REICHWBAT', 'zeilen' => array('laden'),
                              'herkunft' => 'connector', 'mqtt' => 'reichweite_elektro_km'),
        'FERTIGMIN'  => array('quelle_feld' => '', 'einheit' => 'min',
                              'bez' => 'AU_LFELD.FERTIGMIN', 'zeilen' => array('laden'),
                              'herkunft' => 'gerechnet', 'mqtt' => ''),
        // ---- Neu in 0.9.8, Laden -----------------------------------------
        'LADESTUFE'  => array('quelle_feld' => 'lade_stufe', 'einheit' => '',
                              'bez' => 'AU_LFELD.LADESTUFE', 'zeilen' => array('laden'),
                              'herkunft' => 'connector', 'mqtt' => 'lade_stufe'),
        'LADEART'    => array('quelle_feld' => 'ladeart_zahl', 'einheit' => '',
                              'bez' => 'AU_LFELD.LADEART', 'zeilen' => array('laden'),
                              'herkunft' => 'connector', 'mqtt' => 'ladeart_zahl'),
        'EXTSTROM'   => array('quelle_feld' => 'externe_kraft', 'einheit' => '',
                              'bez' => 'AU_LFELD.EXTSTROM', 'zeilen' => array('laden'),
                              'herkunft' => 'connector', 'mqtt' => 'externe_kraft'),
        'STECKERAUTO' => array('quelle_feld' => 'stecker_entriegeln', 'einheit' => '',
                              'bez' => 'AU_LFELD.STECKERAUTO', 'zeilen' => array('laden'),
                              'herkunft' => 'connector', 'mqtt' => 'stecker_entriegeln'),
        'BATTTEMP'   => array('quelle_feld' => 'batterie_temp', 'einheit' => '&deg;C',
                              'bez' => 'AU_LFELD.BATTTEMP', 'zeilen' => array('laden'),
                              'herkunft' => 'connector', 'mqtt' => 'batterie_temp'),
        'VERBRAUCH'  => array('quelle_feld' => 'verbrauch', 'einheit' => 'kWh/100km',
                              'bez' => 'AU_LFELD.VERBRAUCH', 'zeilen' => array('laden'),
                              'herkunft' => 'gerechnet', 'mqtt' => 'verbrauch'),
        'LADEKWH'    => array('quelle_feld' => 'ladekwh', 'einheit' => 'kWh',
                              'bez' => 'AU_LFELD.LADEKWH', 'zeilen' => array('laden'),
                              'herkunft' => 'gerechnet', 'mqtt' => 'ladekwh'),
        'LADEEMPF'   => array('quelle_feld' => 'ladeempf', 'einheit' => '',
                              'bez' => 'AU_LFELD.LADEEMPF', 'zeilen' => array('laden'),
                              'herkunft' => 'gerechnet', 'mqtt' => 'ladeempf'),
        // ---- Wartung ------------------------------------------------------
        'INSPTAGE'   => array('quelle_feld' => 'inspektion_tage', 'einheit' => 'd',
                              'bez' => 'AU_WFELD.INSPTAGE', 'zeilen' => array('wartung'),
                              'herkunft' => 'connector', 'mqtt' => 'inspektion_tage'),
        'INSPKM'     => array('quelle_feld' => 'inspektion_km', 'einheit' => 'km',
                              'bez' => 'AU_WFELD.INSPKM', 'zeilen' => array('wartung'),
                              'herkunft' => 'connector', 'mqtt' => 'inspektion_km'),
        'OELTAGE'    => array('quelle_feld' => 'oelservice_tage', 'einheit' => 'd',
                              'bez' => 'AU_WFELD.OELTAGE', 'zeilen' => array('wartung'),
                              'herkunft' => 'connector', 'mqtt' => 'oelservice_tage'),
        'OELKM'      => array('quelle_feld' => 'oelservice_km', 'einheit' => 'km',
                              'bez' => 'AU_WFELD.OELKM', 'zeilen' => array('wartung'),
                              'herkunft' => 'connector', 'mqtt' => 'oelservice_km'),
        // ---- Neu in 0.9.8, Wartung ---------------------------------------
        'ADBLUE'     => array('quelle_feld' => 'adblue_km', 'einheit' => 'km',
                              'bez' => 'AU_WFELD.ADBLUE', 'zeilen' => array('wartung'),
                              'herkunft' => 'connector', 'mqtt' => 'adblue_km'),
        'OELSTAND'   => array('quelle_feld' => 'oelstand_prozent', 'einheit' => '%',
                              'bez' => 'AU_WFELD.OELSTAND', 'zeilen' => array('wartung'),
                              'herkunft' => 'leer', 'mqtt' => ''),
        // ---- Position -----------------------------------------------------
        'BREITE'     => array('quelle_feld' => 'breite', 'einheit' => '',
                              'bez' => 'AU_PFELD.BREITE', 'zeilen' => array('position'),
                              'herkunft' => 'connector', 'mqtt' => 'breite'),
        'LAENGE'     => array('quelle_feld' => 'laenge', 'einheit' => '',
                              'bez' => 'AU_PFELD.LAENGE', 'zeilen' => array('position'),
                              'herkunft' => 'connector', 'mqtt' => 'laenge'),
        'POSART'     => array('quelle_feld' => 'positionsart_zahl', 'einheit' => '',
                              'bez' => 'AU_PFELD.POSART', 'zeilen' => array('position'),
                              'herkunft' => 'connector', 'mqtt' => 'positionsart_zahl'),
        'ZUHAUSE'    => array('quelle_feld' => 'zuhause', 'einheit' => '',
                              'bez' => 'AU_PFELD.ZUHAUSE',
                              'zeilen' => array('status', 'position'),
                              'herkunft' => 'gerechnet', 'mqtt' => 'zuhause'),
        'ENTF'       => array('quelle_feld' => 'entfernung_m', 'einheit' => 'm',
                              'bez' => 'AU_PFELD.ENTF',
                              'zeilen' => array('status', 'position'),
                              'herkunft' => 'gerechnet', 'mqtt' => 'entfernung_m'),
        // ---- In JEDER Zeile, immer am Ende --------------------------------
        'ALTER'      => array('quelle_feld' => '', 'einheit' => 's',
                              'bez' => 'AU_FELD.ALTER',
                              'zeilen' => array('status', 'laden', 'wartung', 'position'),
                              'herkunft' => 'bestand', 'mqtt' => ''),
        'GRUND'      => array('quelle_feld' => '', 'einheit' => '',
                              'bez' => 'AU_FELD.GRUND',
                              'zeilen' => array('status', 'laden', 'wartung', 'position'),
                              'herkunft' => 'bestand', 'mqtt' => ''),
        'FEHLERTEXT' => array('quelle_feld' => '', 'einheit' => '',
                              'bez' => 'AU_FELD.FEHLERTEXT',
                              'zeilen' => array('status', 'laden', 'wartung', 'position'),
                              'herkunft' => 'bestand', 'mqtt' => ''),
    );
}

/** Die Felder einer Endpunktzeile, in der Reihenfolge der Ausgabe. */
function au_felder_von($zeile)
{
    $aus = array();
    foreach (au_felder() as $feld => $info) {
        if (in_array($zeile, $info['zeilen'], true)) {
            $aus[$feld] = $info;
        }
    }
    return $aus;
}

/* Die drei alten Namen bleiben, damit nichts bricht, was sie benutzt -
 * sie holen ihre Daten jetzt aber aus der einen Liste. */
function au_status_felder()  { return au_felder_von('status'); }
function au_laden_felder()   { return au_felder_von('laden'); }
function au_wartung_felder() { return au_felder_von('wartung'); }
function au_position_felder() { return au_felder_von('position'); }

/**
 * Der Suchtext eines Feldes fuer den virtuellen Eingang in Loxone.
 *
 * Das Semikolon gehoert DAZU. Ohne es nimmt Loxone die erste Fundstelle,
 * und die kann zu einem anderen Feld gehoeren, dessen Name auf diesen
 * endet. Gemessen an der Antwort des Wartungs-Endpunkts: das Muster
 * \iKM= trifft dort INSPKM=15000, nicht KM=48210. Beide Zahlen sehen aus
 * wie ein Kilometerstand - der Fehler faellt an keiner Stelle auf.
 *
 * Und es gibt diese Funktion, damit der Suchtext an EINER Stelle
 * entsteht. Vorher stand er fuenfmal woertlich da: einmal in der Vorlage
 * und viermal in der Oberflaeche. Vier Kopien einer Regel sind vier
 * Gelegenheiten, sie an einer Stelle zu vergessen.
 */
function au_check($feld)
{
    return '\i;' . $feld . '=\i\v';
}

/**
 * Einen Wert fuer die Endpunktzeile aufbereiten.
 *
 * Ein Strich bedeutet: dieser Wert liegt nicht vor. Es wird bewusst keine 0
 * gesendet - eine 0 waere eine stille Falschaussage. Loxone behaelt dann den
 * letzten gueltigen Wert; genau das ist bei einem fehlenden Messwert richtig.
 */
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

/**
 * Baut die Antwortzeile eines Endpunkts aus der Feldliste.
 *
 * DAS IST DER GANZE PUNKT DER UMSTELLUNG: Zeile und Tabelle entstehen aus
 * derselben Liste. Ein Feld, das hier hinausgeht, steht zwangslaeufig auch in
 * der Tabelle des Reiters "Einbindung in Loxone" und in der Loxone-Vorlage.
 *
 * $kopf   'AUDI', 'LADEN', 'WARTUNG', 'POSITION'
 * $zeile  'status', 'laden', 'wartung', 'position'
 */
function au_zeile($kopf, $zeile, $f, $ok, $alter, $grund, $fehlertext)
{
    $teile = array($kopf);
    foreach (au_felder_von($zeile) as $feld => $info) {
        if ($feld === 'OK') {
            $teile[] = 'OK=' . (int) $ok;
            continue;
        }
        if ($feld === 'ALTER') {
            $teile[] = 'ALTER=' . (int) $alter;
            continue;
        }
        if ($feld === 'GRUND') {
            $teile[] = 'GRUND=' . (int) $grund;
            continue;
        }
        if ($feld === 'FEHLERTEXT') {
            // Nur wenn es hakt: eine leere Textangabe in jeder Zeile ist
            // Ballast, und Loxone behaelt den letzten Wert ohnehin.
            if ((int) $grund !== 0) {
                $teile[] = 'FEHLERTEXT=' . $fehlertext;
            }
            continue;
        }
        $teile[] = $feld . '=' . au_w(au_zeile_wert($feld, $info, $f));
    }
    return implode(';', $teile) . "\n";
}

/** Den Wert eines Feldes aus dem Abbild holen - oder ihn hier ausrechnen. */
function au_zeile_wert($feld, $info, $f)
{
    if (!is_array($f)) {
        return null;
    }
    if ($info['quelle_feld'] !== '') {
        return isset($f[$info['quelle_feld']]) ? $f[$info['quelle_feld']] : null;
    }
    switch ($feld) {
        case 'FERTIGMIN':
            // Der Fertigzeitpunkt kommt als Unix-Zeit aus dem Dienst. Loxone
            // kann mit einer Restzeit in Minuten mehr anfangen als mit einem
            // Zeitstempel - und nur, wenn er in der Zukunft liegt.
            return au_restminuten(isset($f['laden_fertig_um']) ? $f['laden_fertig_um'] : null);
        case 'KLIMAFERTIG':
            return au_restminuten(isset($f['klima_fertig_um']) ? $f['klima_fertig_um'] : null);
        case 'AUSFALL':
            return (isset($f['ausfaelle']) && is_array($f['ausfaelle']))
                ? count($f['ausfaelle']) : null;
    }
    return null;
}

/** Restminuten bis zu einem Unix-Zeitpunkt, oder null. */
function au_restminuten($ts)
{
    if (!is_numeric($ts) || (int) $ts <= time()) {
        return null;
    }
    return (int) ceil(((int) $ts - time()) / 60);
}

/**
 * Kurzer Fehlergrund fuer Loxone - als Zahl UND als Text.
 *
 * BIS 0.9.7 WURDE HIER GERATEN, und zwar falsch. Die Funktion suchte im
 * Fehlertext nach "429", "too many", "timeout", "unauthorized". Im Text steht
 * aber die DEUTSCHE Meldung, die bin/audi.py erzeugt. Von den vierzehn
 * Meldungen, die der Dienst schreibt, trafen genau zwei - und die nur
 * zufaellig, weil sie das Wort "Zugangsdaten" enthalten. Ausgerechnet die
 * Unterscheidung, wegen der es die Zahl gibt - "Konto fuer 24 Stunden
 * gesperrt" gegen "Audi ist gestoert, warten genuegt" - war unerreichbar.
 * Dazu war strpos($klein,'5')===0 nicht "HTTP 5xx", sondern "der Text beginnt
 * mit der Ziffer 5".
 *
 * Seit 0.9.8 bestimmt bin/audi.py die Klasse an der Ausnahme selbst und legt
 * sie als fehler_code ab. Hier wird sie nur noch gelesen. Fehlt sie - eine
 * Datei aus 0.9.7 -, bleibt es bei 9, und das ist dann ehrlich.
 */
function au_fehlergrund($lox, $ok, $alter)
{
    if ($ok) {
        return array(0, '');
    }
    $text = trim((string) (isset($lox['fehler']) ? $lox['fehler'] : ''));
    $code = isset($lox['fehler_code']) ? (int) $lox['fehler_code'] : 9;
    if ($text === '' && $alter < 0) {
        $code = 1;                                        // noch nie gelaufen
        $text = 'Es hat noch kein Abruf stattgefunden. Laeuft der Dienst?';
    }
    if ($code === 0) {
        $code = 9;   // ok=0, aber keine Klasse: unbekannt, nicht "in Ordnung"
    }
    // Semikolon und Zeilenumbruch heraus: die Statuszeile trennt mit ';'.
    $text = str_replace(array(';', "\r", "\n"), array(',', ' ', ' '), $text);
    // mb_substr ist abgesichert - mb_strtolower stand bis 0.9.7 UNGESCHUETZT
    // in derselben Funktion. Ohne die Erweiterung mbstring starb damit jeder
    // Endpunktaufruf, bevor die abgesicherte Zeile ueberhaupt erreicht war.
    if (function_exists('mb_substr')) {
        $text = mb_substr($text, 0, 160, 'UTF-8');
    } else {
        $text = substr($text, 0, 160);
    }
    return array($code, trim($text));
}

/** Der Klartext einer Herkunft. */
function au_herkunft_text($quelle)
{
    $karte = array(
        'connector' => 'LOX.HERKUNFT_CONNECTOR',
        'gerechnet' => 'LOX.HERKUNFT_GERECHNET',
        'bestand'   => 'LOX.HERKUNFT_BESTAND',
        'leer'      => 'LOX.HERKUNFT_LEER',
    );
    $q = (string) $quelle;
    // Bewusst eine Zuordnung und kein zweiwertiger Ausdruck: eine unbekannte
    // Herkunft wird BENANNT und nicht der harmlosesten Klasse zugeschlagen.
    return isset($karte[$q]) ? au_t($karte[$q]) : sprintf(au_t('LOX.HERKUNFT_UNBEKANNT'), $q);
}

/* ---------------- Adressen ---------------- */

function au_host()
{
    if (isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== '') {
        return preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST']);
    }
    $h = gethostname();
    return $h ? $h : 'loxberry';
}

/**
 * Die Adressen der Miniserver, wie LoxBerry sie kennt.
 *
 * Fuer die Beschraenkung des Endpunkts. Findet sich keiner, wird die
 * Beschraenkung NICHT angewendet - eine leere Liste wuerde sonst jeden
 * Zugriff abweisen, auch den berechtigten.
 */
function au_miniserver_adressen()
{
    $p = au_paths();
    if ($p['home'] === '') {
        return array();
    }
    $gen = au_json_lesen($p['home'] . '/config/system/general.json');
    $ms = array();
    foreach (array('Miniserver', 'miniserver') as $k) {
        if (isset($gen[$k]) && is_array($gen[$k])) {
            $ms = $gen[$k];
            break;
        }
    }
    $aus = array();
    foreach ($ms as $eintrag) {
        if (!is_array($eintrag)) {
            continue;
        }
        foreach (array('Ipaddress', 'ipaddress', 'IPAddress') as $f) {
            if (!empty($eintrag[$f])) {
                $aus[] = (string) $eintrag[$f];
                break;
            }
        }
    }
    return array_values(array_unique($aus));
}

/**
 * Vorlage fuer den Import in Loxone Config - virtueller EINGANG.
 *
 * $art ist 'status', 'laden', 'wartung' oder 'position'.
 * Rueckgabe: array(name, inhalt)
 *
 * Bis 0.9.7 gab es nur die Statusvorlage, und nur fuer Fahrzeug 1 - die
 * Adressen der uebrigen Fahrzeuge standen in einer Tabelle daneben, ohne dass
 * man sie herunterladen konnte.
 */
function au_vorlage($nummer = 1, $art = 'status')
{
    $erlaubt = array('status' => 'AUDI', 'laden' => 'LADEN',
                     'wartung' => 'WARTUNG', 'position' => 'POSITION');
    if (!isset($erlaubt[$art])) {
        $art = 'status';
    }
    $p = au_paths();
    $cfg = au_config();
    $host = au_host();
    $token = au_token('lesen');
    $cmds = array();
    foreach (au_felder_von($art) as $feld => $info) {
        if ($feld === 'FEHLERTEXT') {
            // Ein Text laesst sich mit einer analogen Befehlserkennung nicht
            // lesen. Er geht trotzdem hinaus - fuer einen virtuellen
            // Texteingang, den man von Hand anlegt.
            continue;
        }
        // Der Text laeuft gleich durch au_x() und wuerde dort ein zweites Mal
        // maskiert. Deshalb erst Auszeichnung entfernen und Entitaeten
        // aufloesen - sonst stuende in Loxone Config wortwoertlich
        // 'l&auml;dt' statt 'laedt'.
        $bedeutung = au_klartext(au_t($info['bez']));
        $einheit = au_klartext($info['einheit']);
        if ($info['herkunft'] === 'leer') {
            $bedeutung .= ' [' . au_klartext(au_t('LOX.HERKUNFT_LEER')) . ']';
        }
        $cmds[] = array(
            'title'   => 'AUDI_' . (int) $nummer . '_' . $feld,
            'comment' => $bedeutung . ($einheit !== '' ? ' [' . $einheit . ']' : ''),
            'check'   => au_check($feld),
        );
    }
    $adresse = 'http://' . $host . '/plugins/' . $p['plugin']
             . '/index.php?token=' . $token . '&aktion=' . $art
             . '&fahrzeug=' . (int) $nummer;
    // Der Abholzyklus folgt dem eingestellten Takt. Bis 0.9.7 stand hier fest
    // 300 - bei einem Takt von 900 s fragte Loxone dreimal dieselben Werte ab,
    // bei 180 s hinkte es hinterher.
    $zyklus = max(60, min(3600, (int) $cfg['intervall']));
    return array(
        'VI_audi_' . (int) $nummer . '_' . $art . '.xml',
        au_xml_virtual_in_http(array(
            'title'   => 'Audi ' . (int) $nummer . ' ' . strtoupper($art),
            'address' => $adresse,
            'polling' => (string) $zyklus,
            'comment' => 'Erzeugt vom LoxBerry-Plugin Audi Connect (' . date('d.m.Y') . ')',
        ), $cmds),
    );
}

/**
 * Vorlage fuer den virtuellen AUSGANG - alle Schaltbefehle.
 *
 * Die eingreifenden Befehle (Ver-/Entriegeln, Hupe) kommen nur mit hinein,
 * wenn sie freigegeben sind. Eine Vorlage, die einen gesperrten Befehl
 * enthaelt, erzeugt in Loxone einen Ausgang, der jedes Mal HTTP 403
 * bekommt - und der Anwender sucht den Fehler bei sich.
 */
function au_vorlage_vo($nummer = 1)
{
    $p = au_paths();
    $cfg = au_config();
    $token = au_token('schalten');
    $basis = '/plugins/' . $p['plugin'] . '/index.php?token=' . $token;
    $fz = '&fahrzeug=' . (int) $nummer;
    $cmds = array();
    foreach (au_befehle() as $aktion => $eig) {
        if ($eig['gefahr'] && empty($cfg['gefahr_ein'])) {
            continue;
        }
        if ($aktion === 'einstellung' || $aktion === 'spin_pruefen') {
            continue;   // eigene Zeilen weiter unten
        }
        // Ein Zustand gehoert an EINEN Ausgang mit Ein- und Ausbefehl, nicht
        // an zwei Ausgaenge. Klima ein/aus ist genau so ein Fall.
        $gegen = (string) $eig['gegen'];
        if ($gegen === '' && au_ist_gegenstueck($aktion)) {
            continue;   // steht als Ausbefehl an seinem Partner
        }
        $titel = 'AUDI ' . (int) $nummer . ' ' . au_klartext(au_t($eig['bez']));
        $ziel = $basis . '&aktion=' . $aktion . ($eig['ohne_fz'] ? '' : $fz);
        if ($eig['zusatz'] === 'temp') {
            $ziel .= '&temp=<v>';
        } elseif ($eig['zusatz'] === 'prozent') {
            $ziel .= '&prozent=<v>';
        } elseif ($eig['zusatz'] === 'ampere') {
            $ziel .= '&ampere=<v>';
        }
        $cmds[] = array(
            'title'   => $titel,
            'comment' => 'AUDI_' . (int) $nummer . '_' . strtoupper($aktion),
            'on'      => $ziel,
            'off'     => $gegen === '' ? ''
                         : $basis . '&aktion=' . $gegen . ($eig['ohne_fz'] ? '' : $fz),
            'analog'  => in_array($eig['zusatz'], array('temp', 'prozent', 'ampere'), true) ? 1 : 0,
        );
    }
    // Die Ja/Nein-Einstellungen: je eine Zeile mit Ein- und Ausbefehl.
    foreach (au_einstellungen() as $name => $bez) {
        $cmds[] = array(
            'title'   => 'AUDI ' . (int) $nummer . ' ' . au_klartext(au_t($bez)),
            'comment' => 'AUDI_' . (int) $nummer . '_' . strtoupper($name),
            'on'      => $basis . '&aktion=einstellung' . $fz . '&name=' . $name . '&wert=1',
            'off'     => $basis . '&aktion=einstellung' . $fz . '&name=' . $name . '&wert=0',
            'analog'  => 0,
        );
    }
    return array(
        'VA_audi_' . (int) $nummer . '_steuern.xml',
        au_xml_virtual_out(array(
            'title'   => 'Audi ' . (int) $nummer . ' steuern',
            'address' => 'http://' . au_host(),
            'comment' => 'Audi Connect ' . (int) $nummer . ' Steuerbefehle',
            'hint'    => au_klartext(au_t('LOX.VORLAGE_VO_HINWEIS')),
        ), $cmds),
    );
}

/** Ist diese Aktion der Ausbefehl eines anderen? */
function au_ist_gegenstueck($aktion)
{
    foreach (au_befehle() as $eig) {
        if ((string) $eig['gegen'] === (string) $aktion) {
            return true;
        }
    }
    return false;
}

/** Auszeichnung heraus und Entitaeten aufloesen - fuer XML und Klartext. */
function au_klartext($s)
{
    return trim(strip_tags(html_entity_decode((string) $s, ENT_QUOTES, 'UTF-8')));
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
            foreach (array(lb_wurzel_ermitteln(), '/home/loxberry/loxberry') as $k) {
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
