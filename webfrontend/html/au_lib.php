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

/* Gemeinsame Sprachausgabe (Abschrift von Werkzeuge/gemeinsam/sprachausgabe.php, Nr. 36 b, seit
 * 0.9.25; seit 0.9.30 Stamm 1.2.1 mit weiteren Ausgabezielen, au_mit_zielen(), Welle Mehrfach-Ziele).
 * Liegt neben dieser Datei; sie schuetzt sich selbst gegen doppeltes Laden. */
require_once __DIR__ . '/sprachausgabe.php';


/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins, data/plugins UND config/system/general.json traegt. Das
 * trifft die uebliche Installation genauso wie eine an einem anderen Ort -
 * und es trifft auch den Fall, dass das Plugin noch als entpacktes Archiv
 * daliegt (dann findet es nichts und gibt einen Leerstring zurueck, was der
 * Aufrufer ohnehin abfangen muss).
 *
 * BERICHTIGT IN 0.9.20: bis dahin genuegten config/plugins und webfrontend.
 * Beide entstehen auf jedem Rechner, auf dem einmal ein Pruefstand ohne
 * LBHOMEDIR gelaufen ist; ein solcher Rest galt dann als Anlage
 * (Regeln/06, Raumklima 0.11.3). Eine Anlage hat immer eine general.json.
 * In WSL gemessen (Fall h_lib, 24.09.2026): au_paths()['home'] zeigte in
 * den fremden Baum.
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert: zwei
 * Bibliotheken landen nie im selben Prozess, aber die Pruefung kostet nichts.
 */
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

/* Die LoxBerry-Wurzel: gelesene Umgebung zuerst, dann die Suche.
 *
 * NEU IN 0.9.20 - vorher stand dieser Block zweimal im Klartext in der
 * Datei (au_paths() und au_t()), und beide Male mit einem festen Rueckfall
 * auf '/home/loxberry/loxberry'. Der ist fort: LoxBerry laesst sich
 * anderswohin installieren, und ein fester Pfad trifft dann eine FREMDE
 * Anlage. Findet sich keine Wurzel, ist die Antwort der Leerstring, und die
 * Aufrufer arbeiten neben dem Plugin statt in einem fremden Baum.
 * Vorbild: sp_lbhome() in Sprachsteuerung 0.11.9.
 */
function au_lbhome()
{
    $home = getenv('LBHOMEDIR');
    if ($home && is_dir($home . '/config/plugins') && is_dir($home . '/data/plugins')) {
        return $home;
    }
    return lb_wurzel_ermitteln();
}

function au_paths()
{
    static $p = null;
    if ($p !== null) {
        return $p;
    }
    $home = au_lbhome();
    // Der Pluginordner: $LBPPLUGINDIR ist die Auskunft von LoxBerry selbst
    // und hat deshalb den Vorrang - GEAENDERT IN 0.9.20; bis dahin trug der
    // Ablageort, und die Umgebung wurde nur befragt, wenn der so gefundene
    // Ordner unter config/plugins fehlte. Der MD5-Schluessel aus der
    // plugindatabase.json wird bewusst NICHT benutzt: er wird aus
    // Autorenname, E-Mail und Plugin-Name gebildet und aendert sich bei
    // jedem Fork.
    //
    // Der feste Name greift nur noch dort, wo der ermittelte nachweislich
    // kein Plugin-Ordner sein KANN: aus dem ausgepackten Archiv heraus
    // heisst er "html".
    $lbp = getenv('LBPPLUGINDIR');
    $dir = basename(dirname(__FILE__));
    if ($lbp) {
        $dir = basename(rtrim($lbp, '/'));
    } elseif ($dir === '' || $dir === '.' || $dir === '/' || $dir === 'html') {
        $dir = 'audiconnect';
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
 * liest sie nie. Seit 0.9.30 (Gruppe 41) ebenso befehle_mqtt_ein,
 * befehle_mqtt_steuern (Befehlsbruecke) und endpunkt_ein (Endpunkt).
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
        // Gruppe 41 (0.9.30): zweite Quelle der Ladeempfehlung - 'thema' (wie bisher) oder 'haus' (haus/strompreis).
        'ladeempf_quelle'   => 'thema',
        // Gruppe 41 (0.9.30): Haus-Themen (Regeln/07 Nr. 15), Vorklimatisierung nach MG-Regeln,
        // Ladekosten, SignalBot - alles ab Werk aus. Dieselben Werte wie VORGABEN in bin/audi.py.
        'haus_abfahrt_ein'      => 0,
        'vorklima_anwesend_ein' => 0,
        'vorklima_wetter_ein'   => 0,
        'vorklima_kalt_unter'   => 5,
        'vorklima_heiss_ueber'  => 25,
        'ankunft_ein'           => 0,
        'ankunft_quelle'        => '',
        'haus_strompreis_ein'   => 0,
        'signal_ein'            => 0,
        'signal_praefix'        => 'signalbot',
        'signal_laden_fertig'   => 1,
        'signal_laden_abbruch'  => 1,
        'signal_offen'          => 1,
        'signal_ausfall'        => 1,
        'signal_vorklima'       => 1,
        // Sammelthemen und Kabel-Warnung (0.9.32, Regeln/07): dieselben Werte wie VORGABEN im Dienst.
        'haus_fahrzeug_ein'      => 1,
        'kabel_soc'              => 30,
        'kabel_uhr'              => '20:00',
        'kabel_abfahrt_min'      => 60,
        'signal_kabel'           => 1,
        'vorklima_min_soc'       => 20,
        'vorklima_nur_kabel'     => 0,
        'ansage_kabel_vergessen' => 1,
        // Gruppe 41 (0.9.30): nur hier, nicht im Dienst. Befehle ueber die Befehlsbruecke (ab Werk und
        // beim Update aus); der HTTP-Endpunkt ab Werk aus, beim Update an (postinstall.sh, G3).
        'befehle_mqtt_ein'      => 0,
        'befehle_mqtt_steuern'  => 0,
        'endpunkt_ein'          => 0,
        // Nr. 36 b (seit 0.9.25): Ansageanlaesse, je einzeln abwaehlbar - dieselben Werte wie
        // VORGABEN in bin/audi.py. Gesprochen wird erst mit einer Ausgabeart (tts, ab Werk 'aus').
        'ansage_laden_fertig'  => 1,
        'ansage_laden_abbruch' => 1,
        'ansage_offen'         => 1,
        'ansage_licht'         => 1,
        'ansage_klima'         => 1,
        'ansage_ausfall'       => 1,
        // Nur hier, nicht im Dienst
        'tts'               => ansage_werk_block(),     // Stamm 1.3.2: ab Werk an, nur fuer einen nie gespeicherten Block
        'ansage_regeln' => ansage_regeln_vorgaben(),
        'ansage_token_herkunft' => array(),
        'aktionstoken'      => '',
        'schalttoken'       => '',
        // Grundlage des Formularmerkmals. NEU IN 0.9.12 und ein eigenes
        // Geheimnis - bis 0.9.11 entstand das Merkmal aus dem LESEtoken.
        // Das steht in jeder Loxone-Adresse und in jeder weitergegebenen
        // Projektdatei; wer es hatte, konnte das Merkmal ausrechnen und
        // damit jeden POST-Zweig der Oberflaeche bedienen - auch die
        // schaltenden Knoepfe des Reiters Test. Es wird nie angezeigt und
        // geht nicht in die Sicherung.
        'formgeheimnis'     => '',
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

/**
 * Eine Zeile ins Plugin-Protokoll.
 *
 * Form wie in der Oberflaeche (webfrontend/htmlauth/index.php:461). Ruft
 * NICHTS auf, was seinerseits die Konfiguration liest - diese Funktion wird
 * aus au_config() heraus gerufen.
 */
function au_log($text)
{
    $p = au_paths();
    if (!is_dir($p['logdir'])) {
        @mkdir($p['logdir'], 0775, true);
    }
    @file_put_contents($p['log'], '[' . date('Y-m-d H:i:s') . '] ' . $text . "\n", FILE_APPEND);
}

/**
 * DIE DREI GEHEIMNISSE DIESER LINIE - an genau einer Stelle.
 *
 *   aktionstoken    steht in jeder LESENDEN Loxone-Adresse
 *   schalttoken     steht in jeder SCHALTENDEN Loxone-Adresse (seit 0.9.8)
 *   formgeheimnis   Grundlage des Formularmerkmals der Oberflaeche (seit 0.9.12)
 *
 * Keines laesst sich zurueckrechnen; geht eines verloren, antwortet der
 * Endpunkt jedem virtuellen Eingang im Miniserver mit HTTP 403. Alles
 * andere in dieser Datei laesst sich in der Oberflaeche noch einmal
 * eintragen.
 */
function au_geheimnisse()
{
    return array('aktionstoken', 'schalttoken', 'formgeheimnis');
}

/**
 * Traegt dieser Stand das genannte Geheimnis?
 *
 * Unterschieden wird am WERT (leer nach trim), nicht mit empty() - ein
 * Token '0' waere sonst fuer immer 'fehlend'. Dieselbe Regel wie in
 * au_token_ergaenzen().
 */
function au_geheimnis_da($c, $k)
{
    // is_string SEIT 0.9.22 (U16): ein Token als Liste galt bis 0.9.21 als
    // "vorhanden" ((string) ergab "Array"), der Endpunkt nahm token=Array an.
    return is_array($c) && isset($c[$k]) && is_string($c[$k]) && trim($c[$k]) !== '';
}

/**
 * Traegt diese Datei ueberhaupt etwas?
 *
 * Nicht "ist sie leer?", sondern "laesst sie sich als JSON-Objekt mit
 * mindestens einem Schluessel lesen?". Der Unterschied ist an dieser Linie
 * gemessen (18.09.2026, WSL/Ubuntu PHP 8.3.6,
 * Pruefung-AudiConnect-0.9.18/Pruefstaende/messe_au_klasseA.sh, Fall
 * "kaputt"): eine ABGESCHNITTENE audi.json - nicht leer, nicht "{}", aber
 * fuer json_decode unbrauchbar - ging bis 0.9.18 an der Selbstheilung
 * vorbei. au_json_lesen() gab daraus array(), au_config() die blanken
 * Vorgaben, au_token() wuerfelte ALLE DREI Geheimnisse neu und
 * au_config_speichern() kopierte sie ueber die Zweitschrift. Gemessen:
 * aktionstoken, schalttoken und formgeheimnis waren in BEIDEN Dateien fort.
 *
 * Rueckgabe: die gelesenen Daten oder null, wenn die Datei nichts traegt.
 */
function au_inhalt_oder_null($pfad)
{
    if (!is_file($pfad)) {
        return null;
    }
    $roh = trim((string) @file_get_contents($pfad));
    if ($roh === '') {
        return null;
    }
    $d = json_decode($roh, true);
    if (!is_array($d) || $d === array()) {
        return null;
    }
    return $d;
}

/**
 * Traegt dieser Stand, was nur er tragen kann - alle drei Geheimnisse?
 *
 * Eine Konfiguration ohne sie gibt es auf keinem Weg der Oberflaeche:
 * au_token() fuellt alle drei beim ersten Seitenaufbau. Fehlt eines, ist
 * die Datei nicht aus einem gespeicherten Stand hervorgegangen.
 */
function au_config_hat_inhalt($c)
{
    if (!is_array($c) || $c === array()) {
        return false;
    }
    foreach (au_geheimnisse() as $k) {
        if (!au_geheimnis_da($c, $k)) {
            return false;
        }
    }
    return true;
}

/**
 * Welche Geheimnisse traegt die Zweitschrift, die der jetzige Stand nicht traegt?
 *
 * JEDES EINZELN, nicht "alle oder keines". Der Grund ist die Aufwaertslage:
 * eine Anlage, die zuletzt unter 0.9.11 gespeichert hat, fuehrt in BEIDEN
 * Dateien kein formgeheimnis - das gab es damals nicht. Wuerde hier "traegt
 * alle drei" verlangt, kaeme eine solche Zweitschrift nie zum Zuge, und ein
 * abgeschnittenes audi.json haette das aktionstoken doch verloren.
 */
function au_geheimnis_luecke($jetzt, $sicherung)
{
    $luecke = array();
    foreach (au_geheimnisse() as $k) {
        if (au_geheimnis_da($sicherung, $k) && !au_geheimnis_da($jetzt, $k)) {
            $luecke[] = $k;
        }
    }
    return $luecke;
}

/**
 * Die Konfiguration, mit den Vorgaben aufgefuellt.
 *
 * $heilen ist NEU IN 0.9.12 und ab Werk true - die angemeldete Oberflaeche
 * darf heilen. Der UNANGEMELDETE Endpunkt ruft au_config(false):
 * gemessen an 0.9.11 hat ein Aufruf ganz ohne Token die Konfiguration aus
 * der Zweitschrift zurueckgeschrieben (2 Byte wurden 105) und wurde
 * anschliessend korrekt mit GRUND=TOKEN abgewiesen. Wer seine Token
 * bewusst verwirft, bekam sie damit vom naechsten Fremdaufruf zurueck.
 * Der Hausstandard verlangt: der unangemeldete Bereich legt nichts an.
 *
 * GEHEILT WIRD NACH INHALT, NICHT NACH FORM. Bis 0.9.18 stand hier
 * ($roh === '' || $roh === '{}') - eine Frage nach der Form der Datei. Eine
 * abgeschnittene audi.json ist weder leer noch "{}" und kam damit nie in
 * diesen Zweig; siehe die Messung ueber au_inhalt_oder_null(). Jetzt
 * entscheidet, ob der Stand die drei Geheimnisse traegt, und geheilt wird
 * nur aus einer Zweitschrift, die selbst eines traegt, das hier fehlt. Was
 * vorher in der Datei stand, wird nicht weggeworfen, sondern liegt als
 * <datei>.kaputt daneben (0600 - es koennen Geheimnisse darin stehen).
 */
/**
 * U12 (Durchgang 29.09.2026): der Zustand der Konfiguration VOR der ersten
 * Selbstheilung dieses Aufrufs - array('zustand' => fehlt | leer | kaputt |
 * unvollstaendig | heil, 'geheilt' => bool, 'kaputt' => Pfad der .kaputt-Datei).
 * Festgehalten wird der ERSTE heilende Aufruf je Prozess; der Reiter Test
 * nennt ihn, sonst beseitigt die Heilung den Schaden unsichtbar (Regeln/05).
 * Bis 0.9.21 meldete keine Zeile eine abgeschnittene audi.json.
 */
function au_config_erstbefund($neu = null)
{
    static $b = null;
    if ($neu !== null && $b === null) {
        $b = $neu;
    }
    return $b;
}

function au_config($heilen = true)
{
    static $gemeldet = false;
    $p = au_paths();
    $jetzt = au_inhalt_oder_null($p['config']);
    $befund = null;
    if ($heilen) {
        $roh = is_file($p['config']) ? trim((string) @file_get_contents($p['config'])) : null;
        if ($roh === null) {
            $zustand = 'fehlt';
        } elseif ($roh === '' || $roh === '{}' || $roh === '[]') {
            $zustand = 'leer';
        } elseif ($jetzt === null) {
            $zustand = 'kaputt';
        } else {
            $zustand = au_config_hat_inhalt($jetzt) ? 'heil' : 'unvollstaendig';
        }
        $befund = array('zustand' => $zustand, 'geheilt' => false, 'kaputt' => '');
    }
    if ($heilen && !au_config_hat_inhalt($jetzt)) {
        $luecke = au_geheimnis_luecke($jetzt, au_inhalt_oder_null($p['sicherung']));
        if ($luecke) {
            if (!is_dir($p['configdir'])) {
                @mkdir($p['configdir'], 0775, true);
            }
            // Der verdraengte Stand bleibt liegen. Nur wenn wirklich etwas
            // darin stand - eine fehlende oder leere Datei ist nichts, was
            // sich aufzuheben lohnt.
            $alt = is_file($p['config']) ? (string) @file_get_contents($p['config']) : '';
            $rest = preg_replace('/\s+/', '', $alt);
            $verdraengt = ($rest !== '' && $rest !== '{}' && $rest !== '[]');
            /* C3/C4 (Durchgang 29.09.2026): 0600 und Rechte vor Inhalt. Bis
             * 0.9.21 kopierte die Selbstheilung mit der umask und setzte
             * danach 0644 - eine vorher 0600 gesetzte audi.json war danach
             * wieder fuer jeden lesbar, samt Schalttoken und
             * Formulargeheimnis. */
            if ($verdraengt) {
                au_datei_schreiben($p['config'] . '.kaputt', $alt, 0600);
            }
            if (au_datei_schreiben($p['config'], @file_get_contents($p['sicherung']), 0600)) {
                $befund['geheilt'] = true;
                $befund['kaputt'] = $verdraengt ? $p['config'] . '.kaputt' : '';
                if (!$gemeldet) {
                    $gemeldet = true;
                    au_log('Die Konfiguration trug nicht mehr ' . implode(', ', $luecke)
                        . ' und wurde aus der Zweitschrift wiederhergestellt: '
                        . $p['sicherung']
                        . ($verdraengt ? ' (der vorherige Inhalt liegt unter '
                                         . $p['config'] . '.kaputt)' : '') . '.');
                }
            }
        }
    }
    if ($befund !== null) {
        au_config_erstbefund($befund);
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
    $inhalt = (string) $inhalt;
    $neben = $pfad . '.' . getmypid() . '.neu';
    /* RECHTE VOR INHALT - BERICHTIGT C4 (Durchgang 29.09.2026). Bis 0.9.21
     * legte file_put_contents die Nebendatei mit der umask an und fuellte
     * sie, erst danach folgte chmod - entgegen dem Kommentar oben. In WSL
     * gemessen (umask 022, 3000 Schreibvorgaenge): 362-mal stand
     * zugang.json.<pid>.neu MIT Inhalt fuer andere lesbar da. Jetzt: leer
     * anlegen, Rechte setzen, dann fuellen, mit Laengenvergleich, fflush und
     * fclose (Regeln/03, "atomar schreiben"). */
    $fh = @fopen($neben, 'c');
    if ($fh === false) {
        return false;
    }
    if (!@chmod($neben, $rechte) || !@ftruncate($fh, 0)) {
        @fclose($fh);
        @unlink($neben);
        return false;
    }
    $n = @fwrite($fh, $inhalt);
    $gut = ($n === strlen($inhalt)) && @fflush($fh);
    $gut = @fclose($fh) && $gut;
    if (!$gut || !@rename($neben, $pfad)) {
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
    /* Leere Objekte bleiben leere Objekte, in JEDEM Speicherweg (seit 0.9.42, Entscheidung des Hausherrn
     * 11.11.2026). au_config() liest mit json_decode(..., true), dabei wird {} zu einem leeren Feld, und
     * json_encode() schrieb es als [] zurueck. Fuer tts hielt die Vorpruefung _ansage_aus() in audi.py bis
     * 0.9.42 ein [] fuer "an"; die Bruecke liest {} und [] beide als "aus" (gemessen 11.11.2026). Die Gestalt kommt aus dem bisherigen Rohtext der Datei; was
     * dort kein leeres Objekt war, bleibt so, wie es uebergeben wurde. */
    $au_roh = @file_get_contents($p['config']);
    if (is_array($cfg) && $cfg !== array() && is_string($au_roh) && $au_roh !== '') {
        $cfg = au_json_leere_objekte($cfg, json_decode($au_roh));
    }
    $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    // json_encode liefert bei ungueltigem UTF-8 false, und file_put_contents
    // schriebe dann eine Datei mit NULL Bytes - und meldete das als Erfolg.
    // 0600 seit C3 (Durchgang 29.09.2026): die Datei traegt Lese- und
    // Schalttoken und das Formulargeheimnis; bis 0.9.21 stand hier 0644.
    // Apache laeuft am Geraet als loxberry (gemessen 30.09.2026).
    if (!au_datei_schreiben($p['config'], $json, 0600)) {
        return false;
    }
    // Die Zweitschrift wird NICHT erneuert, wenn der neue Stand ein
    // Geheimnis nicht traegt, das dort steht. Gespeichert wird trotzdem -
    // nur der einzige Rueckweg bleibt stehen, und das Protokoll sagt es.
    au_zweitschrift_ziehen($p['config'], $p['sicherung'], (array) $cfg);
    return true;
}

/** Leere Objekte des Rohtextes ($o, json_decode ohne true) im Feld $a wieder als Objekt fuehren (11.11.2026),
 *  damit json_encode() sie als {} und nicht als [] schreibt - oben wie verschachtelt, auch unter fremden
 *  Schluesseln. Seit 0.9.42 ruft au_config_speichern() sie fuer jeden Speicherweg auf; der Aufruf in
 *  au_config_vervollstaendigen() bleibt stehen und aendert dort nichts mehr.
 *  Bauart Bewaesserung 0.9.56 (bw_json_leere_objekte()). */
function au_json_leere_objekte($a, $o)
{
    if (!is_array($a)) { return $a; }
    if (is_object($o)) {
        if (!get_object_vars($o) && $a === array()) { return new stdClass(); }
        foreach ($a as $k => $v) {
            $ks = (string) $k;
            if (is_array($v) && property_exists($o, $ks)) { $a[$k] = au_json_leere_objekte($v, $o->$ks); }
        }
    } elseif (is_array($o)) {
        foreach ($a as $k => $v) {
            if (is_array($v) && array_key_exists($k, $o)) { $a[$k] = au_json_leere_objekte($v, $o[$k]); }
        }
    }
    return $a;
}

/**
 * Konfiguration vervollstaendigen (seit 0.9.41, Regeln/05 "Die Konfiguration
 * wird vervollstaendigt, nicht nur ergaenzt"; Bauform Docker NG 1.3.7).
 *
 * Bis 0.9.40 ergaenzten au_config() und config() im Dienst fehlende
 * Schluessel bei jedem Lesen nur im Speicher; in audi.json kamen sie erst mit
 * dem naechsten Speichern in der Oberflaeche. Aufgerufen ueber
 * bin/au_vervollstaendigen.php beim Dienststart (audi.py main(), vor dem
 * ersten Lesen der Konfiguration in dienst()). Beim Update entfernt
 * postinstall.sh die Marke erst nach dem Nachtragen von endpunkt_ein und
 * startet den Dienst danach - die Reihenfolge haelt also. Weil nur bei
 * FEHLENDEN Schluesseln geschrieben wird, bleibt es bei einem Schreibvorgang.
 *
 * - Nur mit Wurzel, nicht waehrend einer Aktualisierung (au_marke(), gilt),
 *   und nur wenn audi.json ein lesbares Objekt ist, das alle drei Geheimnisse
 *   traegt (au_config_hat_inhalt(), das Merkwort der Selbstheilung) und dessen
 *   Aktionstoken taugt (au_token_taugt()). Fehlt ein Geheimnis, ist das Sache
 *   von au_config()/au_token() beim Oeffnen der Oberflaeche - ein leer
 *   nachgetragenes Geheimnis waere nur eine Annahme.
 * - Geschrieben werden die WERTE AUS DER DATEI, nicht die von au_config() bzw.
 *   config() im Dienst aufbereiteten; fremde Schluessel bleiben stehen.
 *   Nachgetragen werden nur Schluessel aus au_vorgaben(), mit deren Wert:
 *   au_config() kennt keine Lese-Regel fuer Bestandsdateien (endpunkt_ein
 *   traegt postinstall.sh beim Update fest ein), und die Vorgaben des Dienstes
 *   (VORGABEN in audi.py) stimmen in allen gemeinsamen Schluesseln ueberein.
 * - Geschrieben ueber au_config_speichern() (0600, Zweitschrift).
 * - Genau eine Protokollzeile mit den Namen, nie mit Werten.
 *
 * Rueckgabe: array('stand' => ruht|ohne_datei|ohne_merkwort|vollstaendig
 *                  |ergaenzt|fehler, 'schluessel' => array(...))
 */
function au_config_vervollstaendigen()
{
    $p = au_paths();
    $m = au_marke();
    if ($p['home'] === '' || ($m !== null && !empty($m['gilt']))) {
        return array('stand' => 'ruht', 'schluessel' => array());
    }
    clearstatcache(true, $p['config']);
    if (!is_file($p['config'])) {
        return array('stand' => 'ohne_datei', 'schluessel' => array());
    }
    $datei = au_inhalt_oder_null($p['config']);
    if (!au_config_hat_inhalt($datei) || !au_token_taugt($datei['aktionstoken'])) {
        return array('stand' => 'ohne_merkwort', 'schluessel' => array());
    }
    // Ein leeres Objekt der Datei ({}) bliebe nach json_decode(..., true) ein leeres Feld und kaeme beim
    // Schreiben als [] zurueck - der Rohwert wird erhalten, bevor ergaenzt wird (11.11.2026).
    $datei = au_json_leere_objekte($datei, json_decode((string) @file_get_contents($p['config'])));
    $fehlend = array();
    foreach (au_vorgaben() as $k => $w) {
        /* endpunkt_ein bleibt aussen vor (11.11.2026): den Uebergang fuer Bestandsanlagen setzt das
         * Update; ein hier festgeschriebenes "aus" koennte ein spaeteres Update nicht mehr berichtigen. */
        if ($k === 'endpunkt_ein') {
            continue;
        }
        if (!array_key_exists($k, $datei)) {
            $datei[$k] = $w;
            $fehlend[] = $k;
        }
    }
    if (!$fehlend) {
        return array('stand' => 'vollstaendig', 'schluessel' => array());
    }
    if (!au_config_speichern($datei)) {
        au_log('Konfiguration NICHT vervollstaendigt: audi.json liess sich nicht schreiben ('
            . count($fehlend) . ' fehlende Schluessel).');
        return array('stand' => 'fehler', 'schluessel' => $fehlend);
    }
    au_log('Konfiguration vervollstaendigt - ' . count($fehlend)
        . ' fehlende Vorgaben in audi.json geschrieben: ' . implode(', ', $fehlend));
    return array('stand' => 'ergaenzt', 'schluessel' => $fehlend);
}

/**
 * Welche Geheimnisse wuerde die Zweitschrift verlieren, wenn sie durch
 * $neu ersetzt wird?
 *
 * Gefragt wird JE GEHEIMNIS und nur nach denen, die die Zweitschrift
 * wirklich fuehrt: eine Zweitschrift aus der Zeit vor 0.9.12 kennt kein
 * formgeheimnis, und dessen Fehlen auf beiden Seiten ist kein Verlust.
 *
 * Bauart uebernommen aus Sprachsteuerung 0.11.8 (sp_zweitschrift_fehlt())
 * und Intercom 2.2.11: eine Zweitschrift MIT Geheimnis darf nie durch einen
 * Stand OHNE ersetzt werden - in keine der beiden Richtungen.
 */
function au_zweitschrift_fehlt($sicherung, array $neu)
{
    $z = au_inhalt_oder_null($sicherung);
    if ($z === null) {
        return array();
    }
    $fehlt = array();
    foreach (au_geheimnisse() as $k) {
        if (au_geheimnis_da($z, $k) && !au_geheimnis_da($neu, $k)) {
            $fehlt[] = $k;
        }
    }
    return $fehlt;
}

/** Die Zweitschrift erneuern - oder begruendet nicht. */
function au_zweitschrift_ziehen($quelle, $ziel, array $neu)
{
    static $gemeldet = false;
    $fehlt = au_zweitschrift_fehlt($ziel, $neu);
    if ($fehlt) {
        if (!$gemeldet) {
            $gemeldet = true;
            au_log('WARNUNG: Die Zweitschrift bleibt unveraendert - der gespeicherte '
                . 'Stand traegt nicht mehr, was dort steht ('
                . implode(', ', $fehlt) . '): ' . $ziel);
        }
        return false;
    }
    // Gleiche Rechte wie das Original (Hausstandard, CLAUDE.md Punkt 9): seit
    // C3 0600, und seit C4 stehen sie fest, bevor der Inhalt hineingeht.
    // Bis 0.9.21: copy() mit der umask, danach chmod 0644.
    return au_datei_schreiben($ziel, @file_get_contents($quelle), 0600);
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
    // Seit 0.9.12 haengt auch das Formulargeheimnis an dieser Sperre: es
    // entsteht genauso einmalig und darf genausowenig bei jedem Aufruf
    // neu gewuerfelt werden.
    if (trim((string) $cfg['aktionstoken']) !== ''
        && trim((string) $cfg['schalttoken']) !== ''
        && trim((string) (isset($cfg['formgeheimnis']) ? $cfg['formgeheimnis'] : '')) !== '') {
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
    $vorher = au_token_kennung($cfg);
    $cfg = au_token_ergaenzen($cfg);
    if (au_token_kennung($cfg) !== $vorher) {
        au_config_speichern($cfg);
    }

    @flock($fh, LOCK_UN);
    @fclose($fh);
    return (string) $cfg[$schluessel];
}

/** Die drei Geheimnisse in einer Zeichenkette - fuer den Vorher-Nachher-Vergleich. */
function au_token_kennung($cfg)
{
    $teil = array();
    foreach (au_geheimnisse() as $k) {
        $teil[] = (string) (isset($cfg[$k]) ? $cfg[$k] : '');
    }
    return implode('|', $teil);
}

/**
 * Ergaenzt fehlende Geheimnisse, ohne vorhandene anzufassen.
 *
 * Unterschieden wird am WERT (leer nach trim), nicht mit empty() - ein
 * Token '0' waere sonst fuer immer 'fehlend'. Dieselbe Frage stellt
 * au_geheimnis_da().
 */
function au_token_ergaenzen($cfg)
{
    foreach (au_geheimnisse() as $k) {
        if (!au_geheimnis_da($cfg, $k)) {
            $cfg[$k] = au_token_erzeugen();
        }
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
 * BERICHTIGT IN 0.9.12. Bis 0.9.11 entstand das Merkmal aus dem
 * AKTIONSTOKEN - und das ist in dieser Linie das LESEtoken. Es steht in
 * jeder erzeugten Loxone-Vorlage, in jeder Adresstabelle und im Klartext
 * auf dem Reiter; der Kommentar ueber au_token() sagt selbst, es duerfe
 * herumliegen. Ableitung und Festtext stehen im veroeffentlichten
 * Quelltext - wer das Lesetoken hatte, konnte das Merkmal ausrechnen und
 * damit ueber eine fremde Seite jeden POST-Zweig der angemeldeten
 * Oberflaeche bedienen, bis hin zu den schaltenden Knoepfen des Reiters
 * Test. Genau die Trennung, wegen der 0.9.8 zwei Token eingefuehrt hat,
 * war damit wieder aufgehoben.
 *
 * Grundlage ist jetzt ein eigenes Geheimnis, das nirgends angezeigt wird
 * und nicht in die Sicherung geht. Nicht das Schalttoken - auch das wird
 * ausgegeben.
 *
 * Der Hausstandard (Regeln/04) nennt weiterhin das Aktionstoken als
 * Grundlage. Fuer die uebrigen 48 Linien ist das richtig: sie fuehren nur
 * EIN Token. Wer eine Linie auf zwei Token umstellt, zieht diese Stelle
 * mit.
 */
function au_formtoken($cfg = null)
{
    if ($cfg === null) {
        $cfg = au_config();
    }
    $basis = (string) (isset($cfg['formgeheimnis']) ? $cfg['formgeheimnis'] : '');
    if (trim($basis) === '') {
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

/**
 * U16 (Durchgang 29.09.2026): taugt dieser GESPEICHERTE Wert als Token?
 *
 * Eine Zeichenkette aus 16 bis 64 Zeichen, die ohne Kodierung in eine Adresse
 * passt - der Erzeuger liefert 24 aus [a-z2-9]. Der Endpunkt nimmt nur ein
 * Token an, das hier besteht (fail closed), und das Zurueckspielen prueft
 * mit derselben Funktion, damit ein zurueckgespieltes Token nicht am
 * Endpunkt scheitert. Bis 0.9.21 nahm der Endpunkt ein Token als Liste an
 * (token=Array, gemessen p3c Fall 4).
 */
function au_token_taugt($t)
{
    return is_string($t) && preg_match('/^[A-Za-z0-9_.\-]{16,64}\z/', $t) === 1;
}

/**
 * U3/U9/M11 (Durchgang 29.09.2026): ist $s ein gueltiges MQTT-Thema bzw.
 * Praefix? EINE Funktion fuer Formular und Sicherung.
 *
 * Bis 0.9.21 stand im Formular '#^[A-Za-z0-9_/\-\.\+#]{1,128}$#' - der
 * Begrenzer # IN der Zeichenklasse. preg_match lieferte false samt Warnung,
 * und jedes Thema wurde abgewiesen, auch der Platzhalter der Seite selbst
 * (gemessen p3b/p3c). Die Sicherung pruefte mit einer anderen Menge. Und
 * mqtt_topic wie abfahrt_praefix wurden still zurechtgebogen (Anfuehrungs-
 * zeichen entfernt, Rand-Schraegstriche abgeschnitten), statt abgewiesen.
 *
 * Erlaubt: Ebenen aus Buchstaben, Ziffern, _ und - (mit $punkt auch .),
 * getrennt durch genau einen /; kein / am Rand, keine leere Ebene, keine
 * Platzhalter + und #, hoechstens $max Zeichen.
 */
function au_thema_gueltig($s, $max = 128, $punkt = true)
{
    if (!is_string($s) || $s === '' || strlen($s) > (int) $max) {
        return false;
    }
    $muster = $punkt ? '~^[A-Za-z0-9_.\-]+(/[A-Za-z0-9_.\-]+)*\z~'
                     : '~^[A-Za-z0-9_\-]+(/[A-Za-z0-9_\-]+)*\z~';
    return preg_match($muster, $s) === 1;
}

/**
 * U1 (Durchgang 29.09.2026): die Einmalmeldung. Jeder POST der Oberflaeche
 * endet mit 303; was er zu sagen hat, reist in dieser Datei (0600) und wird
 * beim naechsten GET einmal gezeigt und geloescht - aelter als 120 s gilt
 * sie nicht. Sie traegt nur die fertigen Meldungstexte, kein Kennwort und
 * kein Token (Regeln/04). Bauform eb_einmal_*() aus Einspeisebremse 0.9.28.
 */
function au_einmal_schreiben($daten)
{
    $p = au_paths();
    if (!is_dir($p['datadir'])) {
        return false;
    }
    $daten['zeit'] = time();
    $js = json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $js !== false && au_datei_schreiben($p['datadir'] . '/einmalmeldung.json', $js, 0600);
}

function au_einmal_lesen()
{
    $f = au_paths()['datadir'] . '/einmalmeldung.json';
    if (!is_file($f)) {
        return null;
    }
    $d = json_decode((string) @file_get_contents($f), true);
    @unlink($f);
    if (!is_array($d) || !isset($d['zeit']) || abs(time() - (int) $d['zeit']) > 120) {
        return null;
    }
    $aus = array();
    foreach (array('meldungen', 'fehler', 'stoerungen', 'hinweise') as $k) {
        $aus[$k] = array();
        if (isset($d[$k]) && is_array($d[$k])) {
            foreach ($d[$k] as $m) {
                if (is_string($m)) {
                    $aus[$k][] = $m;
                }
            }
        }
    }
    $aus['test'] = isset($d['test']) && is_string($d['test']) ? $d['test'] : '';
    // X-2 (B-Nachzug 01.10.2026): die Eingaben des beanstandeten Formulars.
    // Geprueft wird beim Setzen (index.php, au_eingaben()).
    $aus['eingaben'] = (isset($d['eingaben']) && is_array($d['eingaben'])) ? $d['eingaben'] : null;
    return $aus;
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

/**
 * C13 (Durchgang 29.09.2026): eine Abweisung des Endpunkts ins Protokoll.
 *
 * Bis 0.9.21 schrieb webfrontend/html/index.php keine einzige Zeile - ob
 * Loxone gar nicht anrief oder abgewiesen wurde, war nicht zu
 * unterscheiden (Regeln/03). Hoechstens eine Zeile je Minute und Grund:
 * gebremst wird am Protokoll selbst (die juengste Zeile desselben Grundes),
 * ohne eigene Merkdatei. Nie mit dem Token. Ohne Logordner bleibt es still -
 * angelegt wird nichts ausser der Protokollzeile.
 */
function au_endpunkt_abweisung($grund, $aktion)
{
    $p = au_paths();
    if (!is_dir($p['logdir'])) {
        return;
    }
    $grund = preg_replace('/[^A-Z_]/', '', (string) $grund);
    clearstatcache();
    $groesse = is_file($p['log']) ? (int) @filesize($p['log']) : 0;
    if ($groesse > 0) {
        $fh = @fopen($p['log'], 'rb');
        $ende = '';
        if ($fh !== false) {
            @fseek($fh, max(0, $groesse - 16384));
            $ende = (string) @fread($fh, 16384);
            @fclose($fh);
        }
        foreach (array_reverse(explode("\n", $ende)) as $z) {
            if (preg_match('/^\[(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)\] Endpunkt: Aufruf abgewiesen \(GRUND='
                           . $grund . ',/', $z, $m)) {
                $t = strtotime($m[1]);
                if ($t !== false && time() - $t < 60) {
                    return;
                }
                break;
            }
        }
    }
    $woher = isset($_SERVER['REMOTE_ADDR'])
        ? preg_replace('/[^0-9A-Fa-f:.]/', '', (string) $_SERVER['REMOTE_ADDR']) : '';
    $ak = is_string($aktion) ? preg_replace('/[^a-z_]/', '', substr($aktion, 0, 32)) : '';
    au_log('Endpunkt: Aufruf abgewiesen (GRUND=' . $grund . ', Aktion ' . ($ak !== '' ? $ak : '-')
        . ', von ' . ($woher !== '' ? $woher : '?') . '). Hoechstens eine Zeile je Minute und Grund.');
}

/** Alter des Abbilds in Sekunden, oder -1 wenn es keines gibt. */
function au_alter()
{
    $l = au_loxone();
    return isset($l['ts']) ? max(0, time() - (int) $l['ts']) : -1;
}

/* ---------------- Dienst ---------------- */

/**
 * Ist diese Prozessnummer ein laufender Dienst DIESES Plugins?
 *
 * Argumentweise, vier Bedingungen (NEU IN 0.9.20, wortgleich mit
 * bin/dienst.sh, preupgrade.sh und uninstall/uninstall):
 *   argv[0] ist ein Python, argv[1] ist zeichengenau unser Skript, es gibt
 *   KEIN drittes Argument (sonst ist es ein Einmallauf wie --selbsttest),
 *   und der Prozess gehoert dem Dienstbenutzer.
 */
function au_ist_dienst($pid, $skript)
{
    $pid = (int) $pid;
    if ($pid <= 0 || !is_dir('/proc/' . $pid)) {
        return false;
    }
    $uid = au_dienst_uid();
    if ($uid >= 0) {
        $o = @fileowner('/proc/' . $pid);
        if ($o === false || (int) $o !== $uid) {
            return false;
        }
    }
    $roh = @file_get_contents('/proc/' . $pid . '/cmdline');
    if (!is_string($roh) || $roh === '') {
        return false;
    }
    $teile = explode("\0", rtrim($roh, "\0"));
    if (count($teile) !== 2) {
        return false;
    }
    if (!preg_match('#(^|/)python[0-9.]*$#', $teile[0])) {
        return false;
    }
    return $teile[1] === $skript;
}

/**
 * Unter welcher Benutzernummer laeuft der Dienst? -1 heisst "unbekannt".
 *
 * Gestartet wird er als loxberry (bin/dienst.sh steigt dazu herunter).
 * Laesst sich die Nummer nicht ermitteln - ohne die POSIX-Erweiterung geht
 * es nicht -, wird NICHT gefiltert: ein uebersehener Dienst bekaeme sonst
 * beim naechsten Start einen zweiten danebengestellt, waehrend ein Beenden
 * ueber die Benutzergrenze hinweg ohnehin scheitert.
 */
function au_dienst_uid()
{
    if (function_exists('posix_getpwnam')) {
        $pw = @posix_getpwnam('loxberry');
        if (is_array($pw) && isset($pw['uid'])) {
            return (int) $pw['uid'];
        }
    }
    return -1;
}

/**
 * Alle laufenden Dienste dieses Plugins, aufsteigend nach Prozessnummer.
 *
 * Auch die OHNE PID-Datei. purge_installation loescht beim Upgrade den
 * Datenordner und mit ihm die PID-Datei; der Dienst laeuft weiter und war
 * bis 0.9.19 fuer die Oberflaeche unsichtbar (Fall g_waise_ui: sie meldete
 * "gestoppt", waehrend ein Dienst lief).
 */
function au_dienste_suchen()
{
    clearstatcache();
    $skript = au_paths()['bindir'] . '/audi.py';
    $treffer = array();
    // Erst nachsehen, ob es /proc ueberhaupt gibt - eine Warnung, die bei
    // jedem Aufruf kommt, liest am Ende niemand mehr.
    if (!@is_dir('/proc')) {
        return $treffer;
    }
    $dh = @opendir('/proc');
    if ($dh === false) {
        return $treffer;
    }
    while (($e = readdir($dh)) !== false) {
        if (!preg_match('/^[0-9]+$/', $e)) {
            continue;
        }
        if (au_ist_dienst($e, $skript)) {
            $treffer[] = (int) $e;
        }
    }
    closedir($dh);
    sort($treffer);
    return $treffer;
}

function au_dienst_pid()
{
    clearstatcache();
    $f = au_paths()['datadir'] . '/dienst.pid';
    if (!is_file($f)) {
        // Keine PID-Datei heisst NICHT "kein Dienst" - NEU IN 0.9.20.
        $alle = au_dienste_suchen();
        return $alle ? $alle[0] : 0;
    }
    $pid = (int) trim((string) @file_get_contents($f));
    if ($pid <= 0 || !is_dir('/proc/' . $pid)) {
        $alle = au_dienste_suchen();
        return $alle ? $alle[0] : 0;
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
    // Die Nummer aus der Datei taugt nicht - trotzdem kann ein Dienst
    // laufen (NEU IN 0.9.20).
    $alle = au_dienste_suchen();
    return $alle ? $alle[0] : 0;
}

function au_dienst_soll()
{
    return is_file(au_paths()['datadir'] . '/soll_laufen') ? 1 : 0;
}

/**
 * Die Marke "Aktualisierung laeuft".
 *
 * preupgrade.sh legt data/plugins/<ordner>.upgrade_laeuft als Erstes an,
 * postinstall.sh entfernt sie unmittelbar vor dem Dienststart. Sie liegt
 * NEBEN dem Datenordner, weil purge_installation den Ordner selbst loescht
 * (Regeln/06). bin/dienst.sh startet nicht, solange sie gilt.
 *
 * Diese Funktion ZEIGT sie nur an - der Reiter Test beantwortet damit die
 * Frage, ob gerade eine Installation laeuft oder eine abgebrochene ihre
 * Marke hat liegen lassen. Die Oberflaeche sperrt nicht: in der Luecke
 * geht hier nichts verloren. Gemessen (Pruefung-AudiConnect-0.9.19,
 * Faelle oberflaeche_get und oberflaeche_post): die drei Geheimnisse
 * kommen aus der Zweitschrift zurueck, und postinstall.sh holt zugang.json
 * hinterher. Eine Sperre ohne gemessenen Schaden naehme dem Anwender nur
 * die Seite (Regeln/06, Entscheidung vom 17.09.2026).
 *
 * Rueckgabe: null, wenn keine Marke liegt; sonst array mit
 *   'alter'  Sekunden seit ihrer Entstehung, oder null bei unlesbarem Inhalt
 *   'gilt'   true, solange sie den Dienststart wirklich sperrt
 * Dieselben Grenzen wie in bin/dienst.sh: aelter als 3600 s, aus der
 * Zukunft oder unlesbar - sie gilt nicht.
 */
function au_marke()
{
    $d = au_paths()['datadir'];
    $f = dirname($d) . '/' . basename($d) . '.upgrade_laeuft';
    if (!is_file($f)) {
        return null;
    }
    $roh = trim((string) @file_get_contents($f));
    // preg_match statt ctype_digit: ctype_* kommt aus einer Erweiterung, die
    // nicht auf jedem LoxBerry geladen ist (Regeln/02, Pflichtpruefung).
    // Diese Zeile entscheidet, ob eine Marke gilt - ein Aufruf ins Leere
    // waere ein toedlicher Fehler ausgerechnet im Reiter Test.
    if ($roh === '' || !preg_match('/^[0-9]+$/', $roh)) {
        return array('alter' => null, 'gilt' => false);
    }
    $alter = time() - (int) $roh;
    if ($alter < 0) {
        return array('alter' => $alter, 'gilt' => false);
    }
    return array('alter' => $alter, 'gilt' => $alter < 3600);
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

/* ---------------- G8-Zusatz: Testmeldung an SignalBot (Reiter Einstellungen; Ruecktragen aus VW 0.9.33) ---------------- */

/**
 * Die Zeile des Dienstes "SIGNAL_TEST;ERGEBNIS=<e>;TEXT=<t>" lesen (Rein). Rueckgabe
 * array(Ergebnis, Text) mit Ergebnis angenommen|abgelehnt|keine_antwort|aus|nicht_moeglich.
 * Unbekanntes oder fehlendes Ergebnis zaehlt als nicht_moeglich - nie als angenommen.
 */
function au_signal_test_lesen(array $zeilen)
{
    foreach ($zeilen as $z) {
        if (preg_match('/^SIGNAL_TEST;ERGEBNIS=([a-z_]+);TEXT=(.*)$/', trim((string) $z), $m)
            && in_array($m[1], array('angenommen', 'abgelehnt', 'keine_antwort', 'aus', 'nicht_moeglich'), true)) {
            return array($m[1], substr($m[2], 0, 200));
        }
    }
    return array('nicht_moeglich', '');
}

/**
 * Testmeldung an SignalBot: der Dienst (audi.py --signal-test) sendet einen festen Satz an
 * <signal_praefix>/befehl/senden und wartet bis 5 s auf die Quittung der SignalBot-Bruecke.
 * Ab Werk wirkungslos: ist "Meldungen an SignalBot" aus, wird nichts gesendet.
 * Rueckgabe array(Ergebnis, Satz fuer die Oberflaeche).
 */
function au_signal_test($cfg = null)
{
    if ($cfg === null) {
        $cfg = au_config(false);
    }
    if (empty($cfg['signal_ein'])) {
        return array('aus', au_t('SIGNAL.T_AUS'));
    }
    $p = au_paths();
    $py = $p['bindir'] . '/venv/bin/python3';
    $skript = $p['bindir'] . '/audi.py';
    if (!is_file($py) || !is_file($skript)) {
        return array('nicht_moeglich', au_t('SIGNAL.T_NICHT_MOEGLICH'));
    }
    $aus = array();
    @exec(escapeshellarg($py) . ' ' . escapeshellarg($skript) . ' --signal-test 2>/dev/null', $aus);
    list($e, $text) = au_signal_test_lesen($aus);
    $schl = array('angenommen' => 'SIGNAL.T_ANGENOMMEN', 'abgelehnt' => 'SIGNAL.T_ABGELEHNT',
                  'keine_antwort' => 'SIGNAL.T_KEINE_ANTWORT', 'aus' => 'SIGNAL.T_AUS',
                  'nicht_moeglich' => 'SIGNAL.T_NICHT_MOEGLICH');
    $satz = au_t($schl[$e]);
    return array($e, $e === 'abgelehnt' ? sprintf($satz, $text) : $satz);
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
        /* Welle Bild 17 (0.9.28, Entwurf Welle 17 au, O2): klima_start ohne Gegenbefehl. Die Klimatisierung
         * ist ein Analogbefehl (temp=<v.1>); der Ruhewert einer Formel (-1) und die Frage, ob Config beim Wert
         * 0 den Aus-Befehl schickt, machen einen gemeinsamen Ausgang unklar. klima_stop steht dadurch als
         * eigener Befehl 'AUDI n Klimatisierung aus' in der Vorlage. Endpunkt und Dienst unveraendert. */
        'klima_start'    => array('bez' => 'BEFEHL.KLIMA',       'zusatz' => 'temp',
                                  'gefahr' => 0, 'gegen' => '', 'ohne_fz' => 0),
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
        /* 'gefahr' => 1 SEIT 0.9.12. Dieser Befehl schickt die S-PIN zur
         * Pruefung an Audi. Bis 0.9.11 brauchte er nur den Steuerungshaken und
         * durfte bis zu befehle_stunde mal je Stunde hinausgehen - ein
         * flatternder Baustein in Loxone haette damit dreissig Fehlversuche je
         * Stunde gegen ein Konto abgesetzt, das dafuer sperrt. Das ist nicht
         * weniger eingreifend als eine Lichthupe. */
        'spin_pruefen'   => array('bez' => 'BEFEHL.SPIN',        'zusatz' => '',
                                  'gefahr' => 1, 'gegen' => '', 'ohne_fz' => 1),
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
        /* 'sitzheizung' ist in 0.9.12 entfallen. Der Audi-Connector 0.3.2
         * macht seat_heating nicht schreibbar - als einzige der neun
         * Einstellungen; jede Zuweisung wirft "TypeError: You cannot set this
         * attribute". Die Adresse haette dem Anwender einen Schalter
         * angeboten, der nie wirkt. Der WERT wird weiterhin gelesen und geht
         * als SITZHEIZ hinaus. */
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

/* ---------------- Befehlsbremse fuer Sollwerte (X-7, B-Nachzug 01.10.2026) ----------------
 *
 * Entscheidung Nr. 19 vom 01.10.2026: derselbe Sollwert innerhalb von 60 s
 * wird nicht noch einmal gesendet - der Endpunkt antwortet HTTP 200 mit
 * UNVERAENDERT=1 und reiht nichts ein. Kein zusaetzliches 429. Das gilt nur
 * fuer Sollwerte (Klima an/aus samt Temperatur, Laden an/aus, Ver-/
 * Entriegeln, Scheibenheizung, Zieltemperatur, Ladegrenze, die Ja/Nein-
 * Einstellungen), nicht fuer Ereignisse und Taster (Abruf, Wecken, Hupe,
 * Lichthupe, S-PIN-Pruefung). Der Ladestrom hat seine Bremse im Dienst.
 *
 * Warum: Loxone sendet einen Ausgang bei jeder Aenderung, und ein
 * flatternder Baustein sendet denselben Wert mehrmals. Jede Sendung weckt
 * das Fahrzeug und zaehlt bei Audi - bis zur Sperre des Kontos. Die
 * Stundenbremse im Dienst faengt das erst nach 30 Befehlen.
 *
 * Bauform EVCC 0.9.37 / Marstek 1.1.19: Merker unter flock, faellt
 * geschlossen aus (503). Anders als dort wird der Befehl VOR dem Absetzen
 * vorgemerkt und die Sperre nicht ueber das Warten auf den Dienst gehalten
 * (bis zu 45 s): ein zweiter gleicher Aufruf waehrend des Wartens ist damit
 * schon "unveraendert". Lehnt der Dienst ab (OK=0), wird der Eintrag wieder
 * verworfen. Auch OK=2 (eingereiht, Ergebnis unbekannt) bleibt gemerkt: der
 * Befehl laeuft dann meist noch, und ein zweiter waere genau die
 * Mehrfachanfrage, vor der die Meldung warnt. Der Trockenlauf (probe=1 oder
 * der Haken Probelauf) liest den Merker nur, er merkt nichts. Ein Befehl aus
 * dem Reiter Test geht an der Bremse vorbei und verwirft den Eintrag seiner
 * Gruppe (au_bremse_vergessen), sonst hielte die Bremse einen Wert fuer
 * "gleich", den das Fahrzeug nicht mehr hat.
 */
define('AU_BREMSE_GLEICH_S', 60);

/** Pfad des Merkers. */
function au_bremse_datei()
{
    return au_paths()['datadir'] . '/befehlsbremse.json';
}

/**
 * Welche Gruppe und welcher Sollwert? null = nicht gebremst.
 * $befehl ist der Auftrag, wie er in die Warteschlange ginge.
 */
function au_bremse_gruppe($aktion, array $befehl)
{
    $paare = array(
        'klima_start' => array('klima', 'an'), 'klima_stop' => array('klima', 'aus'),
        'laden_start' => array('laden', 'an'), 'laden_stop' => array('laden', 'aus'),
        'verriegeln'  => array('schloss', 'zu'), 'entriegeln' => array('schloss', 'auf'),
        'scheibe_ein' => array('scheibe', 'an'), 'scheibe_aus' => array('scheibe', 'aus'),
    );
    $aktion = (string) $aktion;
    if (isset($paare[$aktion])) {
        $g = $paare[$aktion];
        if ($aktion === 'klima_start') {
            // Die Temperatur gehoert zum Sollwert: 21 und 22 sind zwei Befehle.
            $g[1] .= '|' . (isset($befehl['temp']) ? (string) (float) $befehl['temp'] : '');
        }
        return $g;
    }
    if ($aktion === 'zieltemperatur') {
        return array('zieltemp', isset($befehl['temp']) ? (string) (float) $befehl['temp'] : '');
    }
    if ($aktion === 'ladegrenze') {
        return array('ladegrenze', isset($befehl['prozent']) ? (string) (int) $befehl['prozent'] : '');
    }
    if ($aktion === 'einstellung' && isset($befehl['name'])) {
        return array('einst_' . preg_replace('/[^a-z_]/', '', (string) $befehl['name']),
                     isset($befehl['wert']) ? (string) $befehl['wert'] : '');
    }
    return null;
}

/**
 * Der Schluessel des Fahrzeugs: die VIN, sonst die Nummer. So trifft ein
 * Aufruf mit fahrzeug=1 denselben Eintrag wie einer mit der VIN.
 */
function au_bremse_fz($fz, $ersatz)
{
    if (is_array($fz) && isset($fz['vin']) && is_string($fz['vin']) && trim($fz['vin']) !== '') {
        return strtoupper(trim($fz['vin']));
    }
    return 'nr' . preg_replace('/[^0-9A-Za-z]/', '', (string) $ersatz);
}

/**
 * Das Urteil - rein, ohne Datei (Selbsttest).
 * Rueckgabe: array('' | 'UNVERAENDERT', Sekunden seit dem gemerkten Befehl).
 */
function au_bremse_urteil(array $m, $schluessel, $wert, $jetzt)
{
    if (!isset($m[$schluessel]) || !is_array($m[$schluessel])
        || !isset($m[$schluessel]['w'], $m[$schluessel]['t']) || !is_scalar($m[$schluessel]['t'])) {
        return array('', 0);
    }
    $seit = (int) $jetzt - (int) $m[$schluessel]['t'];
    // Uhr zurueckgesprungen: der Merker sagt nichts mehr.
    if ($seit < 0) {
        return array('', 0);
    }
    if ((string) $m[$schluessel]['w'] === (string) $wert && $seit < AU_BREMSE_GLEICH_S) {
        return array('UNVERAENDERT', $seit);
    }
    return array('', $seit);
}

/**
 * Vor dem Absetzen. Rueckgabe: array(Urteil, Sekunden, Marke).
 *   'UNVERAENDERT' - derselbe Wert ging vor weniger als 60 s hinaus;
 *   'MERKER'       - der Merker laesst sich nicht oeffnen, sperren oder
 *                    schreiben: geschlossen ausfallen;
 *   ''             - senden. Ausser im Trockenlauf ist der Befehl dann unter
 *                    der Marke vorgemerkt.
 */
function au_bremse_pruefen($schluessel, $wert, $nur_lesen)
{
    $f = au_bremse_datei();
    if ($nur_lesen) {
        // Trockenlauf: nichts anlegen, nichts schreiben. Was den echten
        // Befehl mit 503 abweisen wuerde, weist auch den Trockenlauf ab.
        clearstatcache(true, $f);
        if (!file_exists($f)) {
            $ordner = dirname($f);
            return array((is_dir($ordner) ? is_writable($ordner) : is_writable(dirname($ordner)))
                         ? '' : 'MERKER', 0, '');
        }
        if (!is_file($f)) {
            return array('MERKER', 0, '');
        }
        $fh = @fopen($f, 'r');
        $sperre = LOCK_SH;
    } else {
        if (!is_dir(dirname($f))) {
            @mkdir(dirname($f), 0775, true);
        }
        $fh = @fopen($f, 'c+');
        $sperre = LOCK_EX;
    }
    if ($fh === false || !@flock($fh, $sperre)) {
        if (is_resource($fh)) {
            fclose($fh);
        }
        return array('MERKER', 0, '');
    }
    $m = json_decode((string) stream_get_contents($fh), true);
    if (!is_array($m)) {
        $m = array();
    }
    $jetzt = time();
    list($urteil, $seit) = au_bremse_urteil($m, $schluessel, $wert, $jetzt);
    if ($urteil !== '' || $nur_lesen) {
        flock($fh, LOCK_UN);
        fclose($fh);
        return array($urteil, $seit, '');
    }
    // Abgelaufene Eintraege fallen heraus - der Merker bleibt klein.
    foreach ($m as $k => $e) {
        if (!is_array($e) || !isset($e['t']) || !is_scalar($e['t'])
            || $jetzt - (int) $e['t'] >= AU_BREMSE_GLEICH_S || (int) $e['t'] > $jetzt) {
            unset($m[$k]);
        }
    }
    $marke = bin2hex(random_bytes(6));
    $m[$schluessel] = array('w' => (string) $wert, 't' => $jetzt, 'm' => $marke);
    $js = json_encode($m);
    $ok = $js !== false && ftruncate($fh, 0) && rewind($fh)
          && fwrite($fh, $js) === strlen($js) && fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    if (!$ok) {
        // Nicht vermerkt heisst: die Bremse wuerde den naechsten gleichen
        // Befehl nicht erkennen. Geschlossen ausfallen.
        return array('MERKER', 0, '');
    }
    return array('', 0, $marke);
}

/**
 * Einen Eintrag verwerfen - nach OK=0, oder weil ein Befehl an der Bremse
 * vorbei ging (Reiter Test). $marke '' verwirft jeden Eintrag des
 * Schluessels, sonst nur den eigenen. Rueckgabe: true, wenn danach kein
 * passender Eintrag mehr steht.
 */
function au_bremse_vergessen($schluessel, $marke = '')
{
    $f = au_bremse_datei();
    clearstatcache(true, $f);
    if (!is_file($f)) {
        return true;
    }
    $fh = @fopen($f, 'c+');
    if ($fh === false || !@flock($fh, LOCK_EX)) {
        if (is_resource($fh)) {
            fclose($fh);
        }
        return false;
    }
    $m = json_decode((string) stream_get_contents($fh), true);
    $ok = true;
    if (is_array($m) && isset($m[$schluessel]) && ($marke === ''
            || (is_array($m[$schluessel]) && isset($m[$schluessel]['m'])
                && (string) $m[$schluessel]['m'] === (string) $marke))) {
        unset($m[$schluessel]);
        $js = json_encode($m);
        $ok = $js !== false && ftruncate($fh, 0) && rewind($fh)
              && fwrite($fh, $js) === strlen($js) && fflush($fh);
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    return $ok;
}

function au_befehl_absetzen($befehl, $wartezeit = null)
{
    $p = au_paths();
    if ($wartezeit === null) {
        // Gruppe 41: die Konfiguration nur fuer die Wartezeit lesen - die Bruecke uebergibt 0
        // und darf nichts heilen (wie der unangemeldete Endpunkt).
        $cfg = au_config();
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
    /* C14 (Durchgang 29.09.2026): "=== false" hielt eine gekuerzte Datei fuer
     * geschrieben, und ein json_encode()-Fehler ergab eine leere. */
    $js = json_encode($befehl);
    if ($js === false || @file_put_contents($tmp, $js) !== strlen($js) || !@rename($tmp, $datei)) {
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
            // Gruppe 41 (G6): zwei Spalten hinten; Zeilen vor 0.9.30 haben sie nicht (unbekannt).
            'kosten'    => (isset($c[8]) && $c[8] !== '') ? (float) $c[8] : null,
            'preis'     => (isset($c[9]) && $c[9] !== '') ? (float) $c[9] : null,
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
    $leer = array('gefunden' => 0, 'autostart' => 0, 'fassung' => 0, 'udpport' => 0,
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
        /* Die FASSUNG des MQTT-Gateways, ab Werk 1. Sie entscheidet, was der
         * Anwender eintragen muss: unter V1 jedes Thema von Hand, ab V2
         * erscheint die Themengruppe von selbst in den Subscriptions.
         * 0 heisst "nicht feststellbar" - dann wird nichts behauptet,
         * sondern es werden beide Faelle genannt. */
        'fassung'    => (int) $hol('Gatewayversion', 'gatewayversion'),
        'udpport'    => (int) $hol('Udpinport', 'udpinport'),
        'broker'     => (string) $hol('Brokerhost', 'brokerhost'),
        'brokerport' => (string) $hol('Brokerport', 'brokerport'),
        'websocket'  => (string) $hol('Websocketport', 'websocketport'),
    );
}

/**
 * Der Hinweis zum MQTT-Abo - in der Fassung, die zum GATEWAY passt.
 *
 * Bis hierher stand an den Ausgabestellen unbedingt "Ohne diesen Eintrag
 * kommt am Miniserver nichts an". Das gilt fuer Gateway V1, wo jedes Thema
 * von Hand einzutragen ist. Ab V2 erscheint die Themengruppe von selbst in
 * den Subscriptions - der Satz schickte jeden V2-Anwender zu einem
 * Eingabeplatz, den es nicht gibt.
 *
 * Drei Ausgaenge, nicht zwei: ist die Fassung nicht feststellbar, werden
 * BEIDE Faelle genannt statt einer behauptet.
 */
function au_abo_text()
{
    $m = au_mqtt_zustand();
    $f = isset($m['fassung']) ? (int) $m['fassung'] : 0;
    if ($f <= 0) {
        return au_t('MQTT.ABO_UNBEKANNT');
    }
    $gemessen = ' <span class="sm-mono">'
              . sprintf(au_t('MQTT.ABO_GEMESSEN'), $f) . '</span>';
    return au_t($f >= 2 ? 'MQTT.ABO_V2' : 'MQTT.ABO_WARNUNG') . $gemessen;
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
    if (!empty($cfg['ladeempf_ein']) && (string) $cfg['ladeempf_quelle'] !== 'haus') {
        $th = trim((string) (isset($cfg['ladeempf_thema']) ? $cfg['ladeempf_thema'] : ''));
        if ($th !== '') {
            $t[] = $th;
        }
    }
    // Gruppe 41 (0.9.30): Haus-Themen nach Regeln/07 Nr. 15 - dieselbe Liste wie horcher_themen() im Dienst.
    foreach (au_haus_themen($cfg) as $x) {
        $t[] = $x;
    }
    $t = array_values(array_unique($t));
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

/**
 * Die Themen-Tabelle bin/au_themen.json - M1/M7, Durchgang 29.09.2026.
 *
 * BERICHTIGT: hier stand, die Liste entstehe aus der Feldliste und koenne
 * deshalb nicht von MQTT_FELDER in bin/audi.py abweichen. Das stimmte nicht:
 * die Python-Liste war eine zweite, eigene Liste (MQTT-Pruefer, M7), und
 * welches Thema retained ist, stand nirgends. Jetzt lesen Dienst und
 * Oberflaeche DIESELBE Datei; die Pruefzeile im Reiter Test haelt
 * "audi.py --themen" dagegen. au_felder()['mqtt'] wird nicht mehr gelesen.
 */
function au_themen_datei()
{
    foreach (array(au_paths()['bindir'] . '/au_themen.json',
                   dirname(dirname(__DIR__)) . '/bin/au_themen.json') as $f) {
        if (is_file($f)) {
            return $f;
        }
    }
    return '';
}

/** array('ok' => array('bez' => ..., 'retain' => 0|1), 'fahrzeugN/soc' => ...) */
function au_mqtt_tabelle()
{
    static $t = null;
    if ($t !== null) {
        return $t;
    }
    $t = array();
    $f = au_themen_datei();
    $d = $f !== '' ? json_decode((string) @file_get_contents($f), true) : null;
    if (!is_array($d)) {
        return $t;
    }
    foreach (array('dienst' => '', 'fahrzeug' => 'fahrzeugN/') as $ebene => $vor) {
        if (!isset($d[$ebene]) || !is_array($d[$ebene])) {
            continue;
        }
        foreach ($d[$ebene] as $e) {
            if (!is_array($e) || !isset($e['thema']) || !is_string($e['thema'])) {
                continue;
            }
            $t[$vor . $e['thema']] = array(
                'bez'    => isset($e['bez']) && is_string($e['bez']) ? $e['bez'] : '',
                'retain' => !empty($e['retain']) ? 1 : 0,
            );
        }
    }
    return $t;
}

/**
 * M10 (Durchgang 29.09.2026): config/plugins/<ordner>/mqtt_subscriptions.cfg
 * auf "<praefix>/#" nachfuehren. Das MQTT-Gateway V1 liest diese Datei und
 * abonniert daraus (Regeln/07). Geschrieben wird nur, wenn sie abweicht, mit
 * Protokollzeile; der Dienst tut dasselbe je Takt.
 */
function au_abo_datei_nachfuehren($praefix)
{
    $p = au_paths();
    if (!is_dir($p['configdir'])) {
        return false;
    }
    $f = $p['configdir'] . '/mqtt_subscriptions.cfg';
    $soll = $praefix . "/#\n";
    if (is_file($f) && (string) @file_get_contents($f) === $soll) {
        return true;
    }
    if (!au_datei_schreiben($f, $soll, 0644)) {
        return false;
    }
    au_log('Gateway-Abo nachgefuehrt: ' . $f . ' enthaelt jetzt ' . $praefix . '/#');
    return true;
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
    /* U7 (Durchgang 29.09.2026): HintText vorn, Info als erstes Kind, je
     * Befehl Unit und HintText - wie die Ausfuhr, die Regeln/07 festhaelt
     * (Bauform Einspeisebremse 0.9.28). Bis 0.9.21 fehlten alle vier; ohne
     * Unit steht in Loxone eine nackte Zahl am Eingang. */
    $o .= '<VirtualInHttp HintText="' . au_x(isset($kopf['hint']) ? $kopf['hint'] : '') . '" ';
    $o .= 'Title="' . au_x($kopf['title']) . '" ';
    $o .= 'Comment="' . au_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . au_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'PollingTime="' . au_x(isset($kopf['polling']) ? $kopf['polling'] : '60') . '"';
    $o .= '>' . $crlf;
    $o .= "\t" . '<Info templateType="2" minVersion="17010727"/>' . $crlf;
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
        $o .= 'MaxVal="2147483647" ';
        $o .= 'Unit="' . au_x('<v>' . ((isset($c['unit']) && $c['unit'] !== '') ? ' ' . $c['unit'] : '')) . '" ';
        $o .= 'HintText=""';
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
    $o .= '<VirtualOut HintText="' . au_x(isset($kopf['hint']) ? $kopf['hint'] : '') . '" ';
    $o .= 'Title="' . au_x($kopf['title']) . '" ';
    $o .= 'Comment="' . au_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . au_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'CmdInit="" ';
    $o .= 'CloseAfterSend="false" ';
    $o .= 'CmdSep=""';
    $o .= '>' . $crlf;
    $o .= "\t" . '<Info templateType="3" minVersion="17010727"/>' . $crlf;
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualOutCmd ';
        $o .= 'Title="' . au_x($c['title']) . '" ';
        $o .= 'Comment="' . au_x(isset($c['comment']) ? $c['comment'] : '') . '" ';
        /* U6 (Durchgang 29.09.2026): die Methode ist GET, die ADRESSE steht in
         * CmdOn/CmdOff - wie in der Referenz VQ_weissware_geraet1_befehle.xml
         * (Regeln/07:350-352). Bis 0.9.21 stand die Adresse in CmdOnMethod und
         * CmdOn fehlte ganz: nach dem Import haette kein Ausgang gesendet. */
        $o .= 'CmdOnMethod="GET" ';
        $o .= 'CmdOn="' . au_x(isset($c['on']) ? $c['on'] : '') . '" ';
        $o .= 'CmdOffMethod="GET" ';
        $o .= 'CmdOff="' . au_x(isset($c['off']) ? $c['off'] : '') . '" ';
        $o .= 'Analog="' . (empty($c['analog']) ? 'false' : 'true') . '" ';
        $o .= 'Repeat="0" ';
        $o .= 'RepeatRate="0" ';
        if (!empty($c['analog'])) {
            // Wie die Ausfuhr: zwischen RepeatRate und HintText (Regeln/07).
            $o .= 'SourceValLow="0" DestValLow="0" SourceValHigh="10" DestValHigh="10" ';
        }
        $o .= 'HintText=""';
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
                              'bez' => 'AU_FELD.OK', 'kurz' => 'AU_KURZ.OK',
                              'zeilen' => array('status', 'laden', 'wartung', 'position'),
                              'herkunft' => 'bestand', 'mqtt' => ''),
        // ---- Status -------------------------------------------------------
        'SOC'        => array('quelle_feld' => 'soc', 'einheit' => '%',
                              'bez' => 'AU_FELD.SOC', 'kurz' => 'AU_KURZ.SOC', 'zeilen' => array('status', 'laden'),
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
                              'bez' => 'AU_FELD.REICHWVERBR', 'kurz' => 'AU_KURZ.REICHWVERBR', 'zeilen' => array('status'),
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
                              'bez' => 'AU_FELD.KLIMA', 'kurz' => 'AU_KURZ.KLIMA', 'zeilen' => array('status'),
                              'herkunft' => 'connector', 'mqtt' => 'klima_an'),
        'ZIELTEMP'   => array('quelle_feld' => 'zieltemperatur', 'einheit' => '&deg;C',
                              'bez' => 'AU_FELD.ZIELTEMP', 'kurz' => 'AU_KURZ.ZIELTEMP', 'zeilen' => array('status'),
                              'herkunft' => 'connector', 'mqtt' => 'zieltemperatur'),
        'AUSSEN'     => array('quelle_feld' => 'aussentemperatur', 'einheit' => '&deg;C',
                              'bez' => 'AU_FELD.AUSSEN', 'kurz' => 'AU_KURZ.AUSSEN', 'zeilen' => array('status'),
                              'herkunft' => 'connector', 'mqtt' => 'aussentemperatur'),
        'SCHEIBE'    => array('quelle_feld' => 'scheibenheizung', 'einheit' => '',
                              'bez' => 'AU_FELD.SCHEIBE', 'zeilen' => array('status'),
                              'herkunft' => 'connector', 'mqtt' => 'scheibenheizung'),
        'ZUSTAND'    => array('quelle_feld' => 'zustand', 'einheit' => '',
                              'bez' => 'AU_FELD.ZUSTAND', 'kurz' => 'AU_KURZ.ZUSTAND', 'zeilen' => array('status'),
                              'herkunft' => 'connector', 'mqtt' => 'zustand'),
        'ERREICH'    => array('quelle_feld' => 'erreichbar', 'einheit' => '',
                              'bez' => 'AU_FELD.ERREICH', 'kurz' => 'AU_KURZ.ERREICH', 'zeilen' => array('status'),
                              'herkunft' => 'connector', 'mqtt' => 'erreichbar'),
        // ---- Neu in 0.9.8, Status ----------------------------------------
        'TUERANZ'    => array('quelle_feld' => 'tueren_anzahl', 'einheit' => '',
                              'bez' => 'AU_FELD.TUERANZ', 'zeilen' => array('status'),
                              'herkunft' => 'connector', 'mqtt' => 'tueren_anzahl'),
        'FENSTERANZ' => array('quelle_feld' => 'fenster_anzahl', 'einheit' => '',
                              'bez' => 'AU_FELD.FENSTERANZ', 'zeilen' => array('status'),
                              'herkunft' => 'connector', 'mqtt' => 'fenster_anzahl'),
        'KLIMAART'   => array('quelle_feld' => 'klima_stufe', 'einheit' => '',
                              'bez' => 'AU_FELD.KLIMAART', 'kurz' => 'AU_KURZ.KLIMAART', 'zeilen' => array('status'),
                              'herkunft' => 'connector', 'mqtt' => 'klima_stufe'),
        'KLIMAFERTIG' => array('quelle_feld' => '', 'einheit' => 'min',
                              'bez' => 'AU_FELD.KLIMAFERTIG', 'kurz' => 'AU_KURZ.KLIMAFERTIG', 'zeilen' => array('status'),
                              'herkunft' => 'gerechnet', 'mqtt' => ''),
        'SITZHEIZ'   => array('quelle_feld' => 'sitzheizung_ein', 'einheit' => '',
                              'bez' => 'AU_FELD.SITZHEIZ', 'kurz' => 'AU_KURZ.SITZHEIZ', 'zeilen' => array('status'),
                              'herkunft' => 'connector', 'mqtt' => 'sitzheizung_ein'),
        'KLIMAUNLOCK' => array('quelle_feld' => 'klima_bei_entriegeln', 'einheit' => '',
                              'bez' => 'AU_FELD.KLIMAUNLOCK', 'kurz' => 'AU_KURZ.KLIMAUNLOCK', 'zeilen' => array('status'),
                              'herkunft' => 'connector', 'mqtt' => 'klima_bei_entriegeln'),
        'AKTIV'      => array('quelle_feld' => 'aktiv', 'einheit' => '',
                              'bez' => 'AU_FELD.AKTIV', 'zeilen' => array('status'),
                              'herkunft' => 'connector', 'mqtt' => 'aktiv'),
        'STANDZEIT'  => array('quelle_feld' => 'standzeit_min', 'einheit' => 'min',
                              'bez' => 'AU_FELD.STANDZEIT', 'kurz' => 'AU_KURZ.STANDZEIT', 'zeilen' => array('status'),
                              'herkunft' => 'gerechnet', 'mqtt' => 'standzeit_min'),
        'FZOK'       => array('quelle_feld' => 'ok', 'einheit' => '',
                              'bez' => 'AU_FELD.FZOK', 'kurz' => 'AU_KURZ.FZOK', 'zeilen' => array('status'),
                              'herkunft' => 'bestand', 'mqtt' => 'ok'),
        'AUSFALL'    => array('quelle_feld' => '', 'einheit' => '',
                              'bez' => 'AU_FELD.AUSFALL', 'kurz' => 'AU_KURZ.AUSFALL', 'zeilen' => array('status'),
                              'herkunft' => 'gerechnet', 'mqtt' => ''),
        'FEHLFOLGE'  => array('quelle_feld' => 'fehlfolge', 'einheit' => '',
                              'bez' => 'AU_FELD.FEHLFOLGE', 'kurz' => 'AU_KURZ.FEHLFOLGE', 'zeilen' => array('status'),
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
                              'bez' => 'AU_LFELD.FERTIGMIN', 'kurz' => 'AU_KURZ.FERTIGMIN', 'zeilen' => array('laden'),
                              'herkunft' => 'gerechnet', 'mqtt' => ''),
        // ---- Neu in 0.9.8, Laden -----------------------------------------
        'LADESTUFE'  => array('quelle_feld' => 'lade_stufe', 'einheit' => '',
                              'bez' => 'AU_LFELD.LADESTUFE', 'kurz' => 'AU_KURZ.LADESTUFE', 'zeilen' => array('laden'),
                              'herkunft' => 'connector', 'mqtt' => 'lade_stufe'),
        'LADEART'    => array('quelle_feld' => 'ladeart_zahl', 'einheit' => '',
                              'bez' => 'AU_LFELD.LADEART', 'kurz' => 'AU_KURZ.LADEART', 'zeilen' => array('laden'),
                              'herkunft' => 'connector', 'mqtt' => 'ladeart_zahl'),
        'EXTSTROM'   => array('quelle_feld' => 'externe_kraft', 'einheit' => '',
                              'bez' => 'AU_LFELD.EXTSTROM', 'kurz' => 'AU_KURZ.EXTSTROM', 'zeilen' => array('laden'),
                              'herkunft' => 'connector', 'mqtt' => 'externe_kraft'),
        'STECKERAUTO' => array('quelle_feld' => 'stecker_entriegeln', 'einheit' => '',
                              'bez' => 'AU_LFELD.STECKERAUTO', 'kurz' => 'AU_KURZ.STECKERAUTO', 'zeilen' => array('laden'),
                              'herkunft' => 'connector', 'mqtt' => 'stecker_entriegeln'),
        'BATTTEMP'   => array('quelle_feld' => 'batterie_temp', 'einheit' => '&deg;C',
                              'bez' => 'AU_LFELD.BATTTEMP', 'zeilen' => array('laden'),
                              'herkunft' => 'connector', 'mqtt' => 'batterie_temp'),
        'VERBRAUCH'  => array('quelle_feld' => 'verbrauch', 'einheit' => 'kWh/100km',
                              'bez' => 'AU_LFELD.VERBRAUCH', 'kurz' => 'AU_KURZ.VERBRAUCH', 'zeilen' => array('laden'),
                              'herkunft' => 'gerechnet', 'mqtt' => 'verbrauch'),
        'LADEKWH'    => array('quelle_feld' => 'ladekwh', 'einheit' => 'kWh',
                              'bez' => 'AU_LFELD.LADEKWH', 'kurz' => 'AU_KURZ.LADEKWH', 'zeilen' => array('laden'),
                              'herkunft' => 'gerechnet', 'mqtt' => 'ladekwh'),
        'LADEEMPF'   => array('quelle_feld' => 'ladeempf', 'einheit' => '',
                              'bez' => 'AU_LFELD.LADEEMPF', 'kurz' => 'AU_KURZ.LADEEMPF', 'zeilen' => array('laden'),
                              'herkunft' => 'gerechnet', 'mqtt' => 'ladeempf'),
        // ---- Wartung ------------------------------------------------------
        'INSPTAGE'   => array('quelle_feld' => 'inspektion_tage', 'einheit' => 'd',
                              'bez' => 'AU_WFELD.INSPTAGE', 'zeilen' => array('wartung'),
                              'herkunft' => 'connector', 'mqtt' => 'inspektion_tage'),
        'INSPKM'     => array('quelle_feld' => 'inspektion_km', 'einheit' => 'km',
                              'bez' => 'AU_WFELD.INSPKM', 'kurz' => 'AU_KURZ.INSPKM', 'zeilen' => array('wartung'),
                              'herkunft' => 'connector', 'mqtt' => 'inspektion_km'),
        'OELTAGE'    => array('quelle_feld' => 'oelservice_tage', 'einheit' => 'd',
                              'bez' => 'AU_WFELD.OELTAGE', 'zeilen' => array('wartung'),
                              'herkunft' => 'connector', 'mqtt' => 'oelservice_tage'),
        'OELKM'      => array('quelle_feld' => 'oelservice_km', 'einheit' => 'km',
                              'bez' => 'AU_WFELD.OELKM', 'kurz' => 'AU_KURZ.OELKM', 'zeilen' => array('wartung'),
                              'herkunft' => 'connector', 'mqtt' => 'oelservice_km'),
        // ---- Neu in 0.9.8, Wartung ---------------------------------------
        'ADBLUE'     => array('quelle_feld' => 'adblue_km', 'einheit' => 'km',
                              'bez' => 'AU_WFELD.ADBLUE', 'kurz' => 'AU_KURZ.ADBLUE', 'zeilen' => array('wartung'),
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
                              'bez' => 'AU_PFELD.ZUHAUSE', 'kurz' => 'AU_KURZ.ZUHAUSE',
                              'zeilen' => array('status', 'position'),
                              'herkunft' => 'gerechnet', 'mqtt' => 'zuhause'),
        'ENTF'       => array('quelle_feld' => 'entfernung_m', 'einheit' => 'm',
                              'bez' => 'AU_PFELD.ENTF', 'kurz' => 'AU_KURZ.ENTF',
                              'zeilen' => array('status', 'position'),
                              'herkunft' => 'gerechnet', 'mqtt' => 'entfernung_m'),
        // ---- In JEDER Zeile, immer am Ende --------------------------------
        'ALTER'      => array('quelle_feld' => '', 'einheit' => 's',
                              'bez' => 'AU_FELD.ALTER', 'kurz' => 'AU_KURZ.ALTER',
                              'zeilen' => array('status', 'laden', 'wartung', 'position'),
                              'herkunft' => 'bestand', 'mqtt' => ''),
        'GRUND'      => array('quelle_feld' => '', 'einheit' => '',
                              'bez' => 'AU_FELD.GRUND', 'kurz' => 'AU_KURZ.GRUND',
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

/* Die vier Namen au_status_felder(), au_laden_felder(),
 * au_wartung_felder() und au_position_felder() sind in 0.9.12 entfallen.
 * Sie waren seit 0.9.8 ohne Aufrufer - ueber alle Dateien der Linie
 * gezaehlt kam jeder Name genau einmal vor, an seiner Definition. Der
 * Kommentar, der hier stand, sprach von 'den drei alten Namen'; es waren
 * vier, und au_position_felder() hat es vor 0.9.8 nie gegeben. Wer die
 * Felder einer Zeile braucht, ruft au_felder_von('status') unmittelbar. */

/* 'kurz' (seit dem B-Nachzug 01.10.2026): Sprachschluessel eines Kurztexts
 * fuer den Comment der Loxone-Vorlage (<= 40 Zeichen samt Einheit). Fehlt er,
 * nimmt die Vorlage die Bedeutung 'bez'. */

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
        /* Noch kein Abbild. SEIT 0.9.15 wird dann der Grund aus
         * zustand.json genommen, falls der Dienst einen hinterlassen hat.
         * Am Geraet gemessen (11.09.2026): der Dienst brach mit
         * "Zugangsdaten fehlen." (Klasse 7) ab, BEVOR er loxone.json
         * schrieb. Der Endpunkt meldete deshalb GRUND=1 "Laeuft der
         * Dienst?" - der Dienst lief durchaus an, nur eben nicht weit. */
        $zst = au_zustand();
        $ztext = trim((string) (isset($zst['fehler']) ? $zst['fehler'] : ''));
        if ($ztext !== '') {
            $text = $ztext;
            $code = isset($zst['fehler_code']) ? (int) $zst['fehler_code'] : 9;
        } else {
            $code = 1;                                    // noch nie gelaufen
            $text = 'Es hat noch kein Abruf stattgefunden. Laeuft der Dienst?';
        }
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
        /* Der Comment wird in Loxone Config zum Kachelnamen und soll nicht
         * laenger als 40 Zeichen sein (Regeln/07). Die Bedeutung der
         * Feldtabelle ist dafuer oft zu lang (bis 297 Zeichen); wo sie es ist,
         * traegt das Feld einen Kurztext (B-Nachzug 01.10.2026). */
        if (isset($info['kurz'])) {
            $bedeutung = au_klartext(au_t($info['kurz']));
        }
        $einheit = au_klartext($info['einheit']);
        if ($info['herkunft'] === 'leer') {
            $bedeutung .= ' [' . au_klartext(au_t('LOX.HERKUNFT_LEER')) . ']';
        }
        $cmds[] = array(
            'title'   => 'AUDI_' . (int) $nummer . '_' . $feld,
            'comment' => $bedeutung . ($einheit !== '' ? ' [' . $einheit . ']' : ''),
            'check'   => au_check($feld),
            'unit'    => $einheit,        // U7
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
 * Feste deutsche Titel der Ausgangsvorlage (Welle Bild 17, 0.9.28; Entwurf Welle 17 au, E14; Sprachregel
 * 13.10.2026, Regeln/07). Bis 0.9.27 kam der Titel ueber au_t() aus der Sprachdatei: eine englische Oberflaeche
 * legte "AUDI 1 Climatisation" an, Baustein-Liste, Bild und Musterprojekt passten dann nicht mehr. Die Werte sind
 * die deutschen [BEFEHL]/[EINSTELLUNG]-Texte (im Bauskript gegen die Sprachdatei geprueft); nur der Kommentar
 * (Comment) bleibt uebersetzt. Ein unbekannter Schluessel faellt auf den Text der Oberflaeche zurueck.
 */
function au_vo_titel($bez)
{
    $titel = array(
        'BEFEHL.ABRUF' => 'Sofortabruf',
        'BEFEHL.KLIMA' => 'Klimatisierung',
        'BEFEHL.KLIMA_AUS' => 'Klimatisierung aus',
        'BEFEHL.ZIELTEMP' => 'Zieltemperatur',
        'BEFEHL.LADEN' => 'Laden',
        'BEFEHL.LADEGRENZE' => 'Ladegrenze',
        'BEFEHL.LADESTROM' => 'Ladestrom',
        'BEFEHL.SCHEIBE' => 'Scheibenheizung',
        'BEFEHL.WECKEN' => 'Fahrzeug wecken',
        'BEFEHL.VERRIEGELN' => 'Verriegeln',
        'BEFEHL.HUPE' => 'Hupe und Lichthupe',
        'BEFEHL.LICHTHUPE' => 'Lichthupe',
        'EINSTELLUNG.STECKER_AUTO' => 'Stecker nach dem Laden entriegeln',
        'EINSTELLUNG.KLIMA_UNLOCK' => 'Klimatisierung beim Entriegeln beenden',
        'EINSTELLUNG.SCHEIBE_DAUER' => 'Scheibenheizung bei jeder Klimatisierung',
        'EINSTELLUNG.KLIMA_OHNE_STROM' => 'Klimatisieren ohne Netzanschluss erlauben',
        'EINSTELLUNG.ZONE_VL' => 'Klimazone vorne links',
        'EINSTELLUNG.ZONE_VR' => 'Klimazone vorne rechts',
        'EINSTELLUNG.ZONE_HL' => 'Klimazone hinten links',
        'EINSTELLUNG.ZONE_HR' => 'Klimazone hinten rechts',
    );
    return isset($titel[$bez]) ? $titel[$bez] : au_klartext(au_t($bez));
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
    /* Welle Bild 17 (0.9.28, Entwurf Welle 17 au, E14): Wertformat wie Regeln/07 ("Loxone schickt blanke
     * Analogwerte ueber <v.0>") - die Temperatur mit einer Nachkommastelle (das Endpunktmuster nimmt 21 und
     * 21.5), Ladegrenze und Ladestrom ganzzahlig. Bis 0.9.27 stand dort <v>; was Config dafuer sendet, ist
     * nicht gemessen, "21.50" wiese der Endpunkt mit HTTP 400 ab. */
    $format = array('temp' => '<v.1>', 'prozent' => '<v.0>', 'ampere' => '<v.0>');
    $cmds = array();
    foreach (au_befehle() as $aktion => $eig) {
        if ($eig['gefahr'] && empty($cfg['gefahr_ein'])) {
            continue;
        }
        if ($aktion === 'einstellung' || $aktion === 'spin_pruefen') {
            continue;   // eigene Zeilen weiter unten
        }
        // Ein Zustand gehoert an EINEN Ausgang mit Ein- und Ausbefehl, nicht
        // an zwei Ausgaenge (Laden, Scheibenheizung). Die Klimatisierung ist
        // seit 0.9.28 keiner mehr: siehe au_befehle(), klima_start.
        $gegen = (string) $eig['gegen'];
        if ($gegen === '' && au_ist_gegenstueck($aktion)) {
            continue;   // steht als Ausbefehl an seinem Partner
        }
        $ziel = $basis . '&aktion=' . $aktion . ($eig['ohne_fz'] ? '' : $fz);
        if (isset($format[$eig['zusatz']])) {
            $ziel .= '&' . $eig['zusatz'] . '=' . $format[$eig['zusatz']];
        }
        $cmds[] = array(
            'title'   => 'AUDI ' . (int) $nummer . ' ' . au_vo_titel($eig['bez']),
            'comment' => au_klartext(au_t($eig['bez'])),
            'on'      => $ziel,
            'off'     => $gegen === '' ? ''
                         : $basis . '&aktion=' . $gegen . ($eig['ohne_fz'] ? '' : $fz),
            'analog'  => isset($format[$eig['zusatz']]) ? 1 : 0,
        );
    }
    // Die Ja/Nein-Einstellungen: je eine Zeile mit Ein- und Ausbefehl.
    foreach (au_einstellungen() as $name => $bez) {
        $cmds[] = array(
            'title'   => 'AUDI ' . (int) $nummer . ' ' . au_vo_titel($bez),
            'comment' => au_klartext(au_t($bez)),
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
        // Wie in au_paths(): siehe au_lbhome(). Der feste Rueckfall
        // '/home/loxberry/loxberry' stand bis 0.9.19 auch hier.
        $home = au_lbhome();
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


/**
 * Eine Sicherungsdatei einlesen - und dabei NICHTS durchgehen lassen.
 *
 * Die sieben Punkte aus REGELN_2, und der wichtigste ist der dritte: eine
 * halb gueltige Datei ueberschreibt GAR NICHTS. Wer eine Sicherung
 * zurueckspielt, will entweder den ganzen Stand oder gar keinen - eine zur
 * Haelfte uebernommene Konfiguration ist schlimmer als die alte, und man
 * sieht es ihr nicht an.
 *
 * Unbekannte Schluessel sind eine Beanstandung, kein stiller Verlust: sie
 * stammen aus einer anderen Fassung oder einem anderen Plugin.
 *
 * Rueckgabe: array(Konfiguration|null, Beanstandungen[], uebernommene Werte).
 */
/**
 * Die Zahlenfelder mit ihren Grenzen - EINE Liste fuer Formular UND Sicherung.
 *
 * NEU IN 0.9.12. Bis 0.9.11 stand die Liste zweimal in index.php und gar
 * nicht in der Sicherung: die pruefte nur die Schluesselnamen. Eine Datei mit
 * intervall = "abc" wurde angenommen und geschrieben - unter 180 s wirft der
 * Connector beim Anlegen einen ValueError, der Dienst startet dann nicht mehr.
 */
function au_grenzen()
{
    return array(
        // Untergrenze 180 s: siehe bin/audi.py, Kopf der Vorgabeliste.
        'intervall'        => array(180, 3600),
        'takt_wartung'     => array(1, 240),
        'temp_min'         => array(10, 30),
        'temp_max'         => array(10, 30),
        'verlauf_tage'     => array(1, 90),
        'wartezeit'        => array(0, 30),
        'abruf_abstand'    => array(0, 3600),
        'befehle_stunde'   => array(0, 500),
        'strom_abstand'    => array(0, 3600),
        'abfahrt_vorlauf'  => array(1, 120),
        'abfahrt_temp'     => array(10, 30),
        'abfahrt_alter'    => array(60, 3600),
        'abfahrt_fahrzeug' => array(1, 99),
        'ladeempf_alter'   => array(60, 86400),
        'kapazitaet'       => array(0, 500),
        'heim_radius'      => array(20, 5000),
    );
}

/** Die Schluessel, die nur 0 oder 1 tragen. */
function au_haken()
{
    return array('mqtt_ein', 'steuerung_ein', 'gefahr_ein', 'probe_ein',
                 'gps_ein', 'melden_ein', 'nur_miniserver', 'abfahrt_ein',
                 'ladeempf_ein', 'ladeempf_unter',
                 'ansage_laden_fertig', 'ansage_laden_abbruch', 'ansage_offen', 'ansage_licht',
                 'ansage_klima', 'ansage_ausfall', 'ansage_kabel_vergessen',
                 // 0.9.32
                 'haus_fahrzeug_ein', 'signal_kabel', 'vorklima_nur_kabel',
                 // Gruppe 41 (0.9.30)
                 'befehle_mqtt_ein', 'befehle_mqtt_steuern', 'endpunkt_ein', 'haus_abfahrt_ein',
                 'vorklima_anwesend_ein', 'vorklima_wetter_ein', 'ankunft_ein', 'haus_strompreis_ein',
                 'signal_ein', 'signal_laden_fertig', 'signal_laden_abbruch', 'signal_offen',
                 'signal_ausfall', 'signal_vorklima');
}

/**
 * Was NIE in eine Sicherungsdatei gehoert.
 *
 * Das Formulargeheimnis ist das Sitzungsmerkmal gegen fremde Absender
 * (Regeln/05: "Der Formulartoken der Oberflaeche gehoert NICHT hinein").
 * Die beiden Aktionstoken dagegen SCHON - ohne sie waere die Datei nach dem
 * Zurueckspielen wertlos.
 */
function au_nicht_sichern()
{
    return array('formgeheimnis');
}

/**
 * Taugt der Wert ueberhaupt fuer eine Konfigurationsdatei?
 *
 * Feld, Objekt, Wahrheitswert und null sind hier keine Werte: die
 * Oberflaeche gibt sie an htmlspecialchars weiter, und PHP 8 macht daraus
 * "Array to string conversion" - achtunddreissigmal in einer Seite gemessen.
 */
function au_wert_taugt($v)
{
    if (is_array($v) || is_object($v) || is_bool($v) || is_null($v)) {
        return false;
    }
    $t = (string) $v;
    if (strlen($t) > 4096) {
        return false;
    }
    return preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $t) !== 1;
}

/**
 * Ist der Wert fuer DIESEN Schluessel zulaessig?
 *
 * Rueckgabe: Leerstring wenn ja, sonst der fertige Beanstandungstext.
 * Geprueft wird gegen dieselbe Positivliste, die das Formular benutzt -
 * es gibt nur eine.
 */
function au_wert_pruefen($k, $v)
{
    if (!au_wert_taugt($v)) {
        return sprintf(au_t('EINST.SICH_WERT_FORM'), au_e((string) $k));
    }
    $grenzen = au_grenzen();
    if (isset($grenzen[$k])) {
        $t = trim((string) $v);
        if (!preg_match('/^[0-9]+$/', $t)) {
            return sprintf(au_t('EINST.SICH_WERT_ZAHL'), au_e((string) $k));
        }
        $z = (int) $t;
        if ($z < $grenzen[$k][0] || $z > $grenzen[$k][1]) {
            return sprintf(au_t('EINST.SICH_WERT_BEREICH'), au_e((string) $k),
                           $grenzen[$k][0], $grenzen[$k][1]);
        }
        return '';
    }
    if (in_array($k, au_haken(), true)) {
        if (!in_array((string) $v, array('0', '1'), true)) {
            return sprintf(au_t('EINST.SICH_WERT_HAKEN'), au_e((string) $k));
        }
        return '';
    }
    if ($k === 'aktionstoken' || $k === 'schalttoken') {
        // Weit gefasst mit Absicht: ein zu enges Muster verwirft ein von Hand
        // gesetztes oder aus einer aelteren Fassung uebernommenes Token, und
        // der Schaden ist derselbe wie bei einem verlorenen. Zugelassen ist,
        // was ohne Kodierung in eine Adresse passt. Leer heisst "kein Token
        // gesichert" und ist kein unzulaessiger Wert.
        // SEIT 0.9.22 dieselbe Pruefung wie am Endpunkt (U16), damit ein
        // zurueckgespieltes Token dort nicht scheitert. Ein leeres Token faengt
        // au_sicherung_lesen() vorher ab: es heisst "nicht gesichert" (U4).
        if ((string) $v !== '' && !au_token_taugt((string) $v)) {
            return sprintf(au_t('EINST.SICH_WERT_TOKEN'), au_e((string) $k));
        }
        return '';
    }
    if ($k === 'mqtt_topic' || $k === 'abfahrt_praefix') {
        // U3/M11: dieselbe Funktion wie das Formular.
        if (!au_thema_gueltig((string) $v, 64, false)) {
            return sprintf(au_t('EINST.SICH_WERT_THEMA'), au_e((string) $k));
        }
        return '';
    }
    if ($k === 'ladeempf_thema') {
        // U3: dieselbe Funktion wie das Formular (index.php, save_automatik).
        if ((string) $v !== '' && !au_thema_gueltig((string) $v, 128, true)) {
            return sprintf(au_t('EINST.SICH_WERT_THEMA'), au_e((string) $k));
        }
        return '';
    }
    // Gruppe 41 (0.9.30): dieselben Pruefungen wie das Formular (au_wert_g41()).
    if (in_array($k, array('vorklima_kalt_unter', 'vorklima_heiss_ueber', 'ankunft_quelle',
                           'ladeempf_quelle', 'signal_praefix', 'kabel_soc', 'kabel_uhr', 'kabel_abfahrt_min', 'vorklima_min_soc'), true)) {
        return au_wert_g41($k, (string) $v) === null
            ? sprintf(au_t($k === 'ankunft_quelle' || $k === 'signal_praefix' || $k === 'ladeempf_quelle'
                ? 'EINST.SICH_WERT_THEMA' : 'EINST.SICH_WERT_ZAHL'), au_e((string) $k)) : '';
    }
    if ($k === 'heim_breite' || $k === 'heim_laenge' || $k === 'ladeempf_grenze') {
        // Duerfen negativ und gebrochen sein, auch mit Komma.
        if ((string) $v !== '' && preg_match('/^-?[0-9]{1,7}([.,][0-9]{1,8})?$/', (string) $v) !== 1) {
            return sprintf(au_t('EINST.SICH_WERT_ZAHL'), au_e((string) $k));
        }
        return '';
    }
    return '';
}

/* ==================================================================
 * Sprachausgabe (Nr. 36 b, Stufe 2, seit 0.9.25)
 *
 * Der Dienst bin/audi.py erkennt die Anlaesse und ruft bin/au_ansage.php;
 * dort entsteht der Satz aus der Sprachdatei, gesprochen wird mit der
 * gemeinsamen Sprachausgabe (sprachausgabe.php). Ab Werk ist die Ausgabe aus.
 * ================================================================== */

/** Erlaubte Ausgabearten: alle des Moduls ausser 'audioserver' (die Linie gibt keinen Text an Loxone). Weitere
 *  Ausgabeziele (Tabelle, 'ziele') seit 0.9.30 mit Stamm 1.2.1 - au_mit_zielen() unten; ohne Tabelle wie bisher. */
function au_ansage_modi()
{
    /* Gruppe 41 (0.9.30, Sprachmodul 1.2.0/1.2.1, E10): aus ansage_modi() abgeleitet statt abgeschrieben -
     * damit kommen sonos4lox und (ab Modul 1.1.4) sonox dazu. Bis 0.9.29 stand hier eine feste
     * Liste, und Sonos4Lox liess sich nicht waehlen. */
    $m = function_exists('ansage_modi') ? ansage_modi()
        : array('aus', 'musicserver', 'ms4h', 'custom', 'alexang', 'cc4lox');
    $aus = array();
    foreach ($m as $x) {
        if ($x === 'audioserver') {
            continue;
        }
        if ($x === 'sonox' && (!defined('ANSAGE_FASSUNG') || version_compare(ANSAGE_FASSUNG, '1.1.4', '<'))) {
            continue;
        }
        $aus[] = $x;
    }
    return $aus;
}

/** Welle Mehrfach-Ziele (Sprachmodul 1.2.1): bietet diese Linie die Tabelle der weiteren Ausgabeziele an? Nur, wenn die
 *  geladene Abschrift sie kann - laden zwei Abschriften im selben Prozess, gilt die zuerst geladene. */
function au_mit_zielen() {
    return version_compare(ANSAGE_FASSUNG, '1.2.0', '>=') && function_exists('ansage_ziele_html')
        && function_exists('ansage_ziele_pruefen') && function_exists('ansage_testknoepfe_html');
}

/** Welle Mehrfach-Ziele (Sprachmodul 1.2.1), X-2 mit flachen Schluesseln (name.N): die Felder der Tabelle heissen im
 *  Formular name[N] (N = 2..6, ansage_ziel_feldnamen()). au_ziele_x2_name() macht daraus name.N (Beanstandung und
 *  Markierung), au_ziele_x2_post() aus den gesammelten Eingaben wieder das POST der Tabelle (ziele_post). */
function au_ziele_x2_name($n) {
    return is_string($n) ? (string) preg_replace('/^(tts_ziel_[a-z]+)\[([2-9])\]\z/', '$1.$2', $n) : $n;
}
function au_ziele_x2_post(array $werte) {
    $p = array();
    foreach ($werte as $k => $v) {
        if (is_string($v) && preg_match('/^(tts_ziel_[a-z]+)\.([2-9])\z/', (string) $k, $m)) { $p[$m[1]][(int) $m[2]] = $v; }
    }
    return isset($p['tts_ziel_art']) ? $p : null;
}

/** Welle Mehrfach-Ziele (0.9.30): die Felder der Tabelle in der Einmalmeldung (X-2), flach name.N fuer N = 2..6 -
 *  nur, wenn die geladene Abschrift die Tabelle kann. */
function au_ziele_x2_felder()
{
    $f = array();
    if (au_mit_zielen()) {
        for ($i = 2; $i <= 6; $i++) {
            foreach (array('tts_ziel_art', 'tts_ziel_wo', 'tts_ziel_laut', 'tts_ziel_sprache', 'tts_ziel_ein',
                           'tts_ziel_loeschen') as $n) {
                $f[] = $n . '.' . $i;
            }
        }
    }
    return $f;
}

/** Optionen fuer Formular-Baustein und Formular-Lesen. */
function au_ansage_opt()
{
    return array('modi' => au_ansage_modi());
}

/**
 * Die Anlaesse: Name => array(Konfigschluessel, Beschriftung, Satz). Dieselbe
 * Liste wie ANSAGE_ANLAESSE in bin/audi.py; die Bruecke nimmt nur diese Namen an.
 */
function au_ansage_anlaesse()
{
    return array(
        'laden_fertig'  => array('ansage_laden_fertig', 'EINST.L_ANSAGE_LADEN_FERTIG', 'AU_ANSAGE.S_LADEN_FERTIG'),
        'laden_abbruch' => array('ansage_laden_abbruch', 'EINST.L_ANSAGE_LADEN_ABBRUCH', 'AU_ANSAGE.S_LADEN_ABBRUCH'),
        'offen'         => array('ansage_offen', 'EINST.L_ANSAGE_OFFEN', 'AU_ANSAGE.S_OFFEN'),
        'licht'         => array('ansage_licht', 'EINST.L_ANSAGE_LICHT', 'AU_ANSAGE.S_LICHT'),
        'klima'         => array('ansage_klima', 'EINST.L_ANSAGE_KLIMA', 'AU_ANSAGE.S_KLIMA'),
        'ausfall'       => array('ansage_ausfall', 'EINST.L_ANSAGE_AUSFALL', 'AU_ANSAGE.S_AUSFALL'),
        'kabel_vergessen' => array('ansage_kabel_vergessen', 'EINST.L_ANSAGE_KABEL', 'AU_ANSAGE.S_KABEL'),
    );
}

/** Die Schluessel, die mit 0.9.25 dazukamen - eine aeltere Sicherung kennt sie nicht. */
function au_ansage_neue_schluessel()
{
    $s = array('tts', 'ansage_regeln', 'ansage_token_herkunft');
    foreach (au_ansage_anlaesse() as $a) {
        $s[] = $a[0];
    }
    return $s;
}

/**
 * Der Block tts, geprueft und vervollstaendigt (ab Werk 'aus'). au_config()
 * prueft keine Einzelwerte; ein unzulaessiger Block (von Hand geschrieben)
 * gilt deshalb hier als "aus", und das eigene Zurueckspielen nennt ihn (X-3).
 * $heilen = false schreibt nichts (Bruecke).
 */
function au_tts($heilen = true)
{
    $c = au_config($heilen);
    $t = isset($c['tts']) && is_array($c['tts']) ? $c['tts'] : array();
    $g = '';
    if (ansage_wert_pruefen($t, $g, au_ansage_modi()) === null) {
        $t = array();
    }
    list($t) = ansage_vervollstaendigen($t, 'aus');
    return $t;
}

/** Der Kontext des Moduls: Webport, Kopfzeile, Ordner fuer <art>_letzte.json, Texte. */
/** Der Block tts aus einer schon gelesenen Konfiguration (Stamm 1.3.2): Ein gespeicherter bleibt, auch 'aus'; ein nie gespeicherter kommt aus den Vorgaben, also ab Werk an. */
function au_tts_von($cfg)
{
    list($t) = ansage_vervollstaendigen(isset($cfg['tts']) && is_array($cfg['tts']) ? $cfg['tts'] : ansage_werk_block(), 'aus');
    return $t;
}

/* ---------------- Sprachansagen: Regeln aus dem Stamm (0.9.32, Welle S2, Stamm sprachausgabe.php 1.3.2) ----------------
 *
 * Nachtruhe, Sperre und Schalter "Ansagen aus" stehen im Stamm. Ab Werk EIN: ansage_werk_block() in den Vorgaben, also nur fuer einen
 * nie gespeicherten Block; ein gespeicherter bleibt, auch 'aus'. Ein Minutenlauf und ein Dienst sprechen nie selbst: Sie legen den Anlass
 * in <Daten>/ansage_warte.json, ein abgekoppelter Sender (php -r, eigene Sperre) spricht und wartet auf die Lautsprecher.
 */

/** So alt darf ein wartender Anlass werden (nach einem Update oder Hang wird nichts Aufgestautes angesagt). */
if (!defined('AU_ANSAGE_HOECHSTALTER')) { define('AU_ANSAGE_HOECHSTALTER', 600); }

/** Die Regeln: gespeichert und gueltig, sonst aus einer alten Einstellung dieser Linie, sonst die Vorgabe (mit Nachtruhe). */
function au_ansage_regeln($cfg = null)
{
    if ($cfg === null) { $cfg = au_config(false); }
    $g = '';
    if (isset($cfg['ansage_regeln'])) {
        $r = ansage_regeln_wert($cfg['ansage_regeln'], $g);
        return $r === null ? ansage_regeln_vorgaben() : $r;
    }
    $r = ansage_regeln_vorgaben();
    $p = ansage_regeln_wert($r, $g);
    return $p === null ? ansage_regeln_vorgaben() : $p;
}

/** Die Warteliste unter einer Dateisperre aendern: $fn(Liste) -> array(neue Liste, Ergebnis). */
function au_ansage_warte($fn)
{
    $d = au_paths()['datadir'];
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
    $f = $d . '/ansage_warte.json';
    $h = @fopen($f, 'c+');
    if ($h === false) { return null; }
    if (!flock($h, LOCK_EX)) { fclose($h); return null; }
    $alt = json_decode((string) stream_get_contents($h), true);
    list($neu, $erg) = call_user_func($fn, is_array($alt) ? $alt : array());
    rewind($h);
    ftruncate($h, 0);
    fwrite($h, (string) json_encode($neu));
    fflush($h);
    flock($h, LOCK_UN);
    fclose($h);
    @chmod($f, 0600);
    return $erg;
}

/**
 * Anlaesse hinlegen und den Sender starten; wartet auf nichts. $l: Eintraege fuer ansage_regel_sprechen() (schluessel, text, dringend,
 * sperre_min, nur_nach). Die Schale schliesst vor dem exec jede Deskriptor-Nummer ueber 2, sonst erbte der Sender die Sperren des Rufers.
 */
function au_ansage_legen(array $l, $jetzt = null)
{
    if (!$l) { return 'nichts'; }
    $jetzt = $jetzt === null ? time() : (int) $jetzt;
    au_ansage_warte(function ($alt) use ($l, $jetzt) {
        foreach ($l as $e) { $alt[] = $e + array('zeit' => $jetzt); }
        return array(array_slice($alt, -50), null);
    });
    $php = (defined('PHP_BINARY') && PHP_BINARY !== '' && is_executable(PHP_BINARY) && stripos(basename(PHP_BINARY), 'php') === 0 && stripos(basename(PHP_BINARY), 'fpm') === false) ? PHP_BINARY : 'php';
    $befehl = 'for f in /proc/$$/fd/*; do n=${f##*/}; [ "$n" -gt 2 ] 2>/dev/null && eval "exec $n>&-"; done; '
            . 'exec ' . escapeshellarg($php) . ' -r ' . escapeshellarg('chdir(dirname($argv[1])); $_SERVER["SCRIPT_FILENAME"] = $_SERVER["PHP_SELF"] = $argv[1]; require $argv[1]; au_ansage_senden();') . ' -- ' . escapeshellarg(__FILE__);
    $aus = array();
    $rc = 1;
    @exec('bash -c ' . escapeshellarg($befehl) . ' </dev/null >/dev/null 2>&1 &', $aus, $rc);
    return $rc === 0 ? 'gestartet' : 'kein_start';
}

/** Das Sprechtoken aus Alexa-NG und Chromecast4lox uebernehmen (Stamm 1.3.1). Schreibt NUR bei einer Aenderung, nennt nie das Token. */
function au_ansage_token_pflegen($cfg = null)
{
    if ($cfg === null) { $cfg = au_config(false); }
    list($neu, $her2, $g) = ansage_token_abgleich(au_tts_von($cfg), isset($cfg['ansage_token_herkunft']) ? $cfg['ansage_token_herkunft'] : array(), au_ansage_k());
    if (!$g) { return false; }
    $cfg = au_config(false);
    $cfg['tts'] = $neu;
    $cfg['ansage_token_herkunft'] = $her2;
    if (!au_config_speichern($cfg)) { return false; }
    au_log('Ansage: Sprechtoken uebernommen (' . implode(', ', $g) . ').');
    return true;
}

/** Der Block fuer Testansage und Pruefzeile: uebernommene Token aufgeloest. Nur im Speicher. */
function au_ansage_vor_ort($cfg = null)
{
    if ($cfg === null) { $cfg = au_config(false); }
    return ansage_tafel_einreihen(ansage_token_fuer_lauf(au_tts_von($cfg), isset($cfg['ansage_token_herkunft']) ? $cfg['ansage_token_herkunft'] : array(), au_ansage_k()), au_ansage_regeln($cfg), au_ansage_k(), true);     // Stamm 1.4.0: die Tafel, wenn sie bereitsteht
}

/** Der Block fuer die Pruefzeile: nur die eingerichteten Ziele; ist keines eingerichtet, sagt die Zeile "aus" statt eines Kreuzes. */
function au_ansage_pruefblock($cfg = null)
{
    if ($cfg === null) { $cfg = au_config(false); }
    $t = au_tts_von($cfg);
    if ($t['mode'] === 'aus') { return $t; }
    list($b) = ansage_ziele_bereit(au_ansage_vor_ort($cfg), au_ansage_k());
    if ($b === null) { $t['mode'] = 'aus'; return $t; }
    return $b;
}

/** Die Lage im Reiter Test: Ziele, Nachtruhe, Schalter. Nur im geoeffneten Reiter (der Schalter fragt den Broker). */
function au_ansage_lage_html($cfg = null)
{
    if ($cfg === null) { $cfg = au_config(false); }
    $k = au_ansage_k();
    $o = array();
    foreach (ansage_regel_lage(au_tts_von($cfg), au_ansage_regeln($cfg), $k, true) as $z) {
        $o[] = '<div class="sm-hilfe">' . htmlspecialchars((string) $z[1], ENT_QUOTES, 'UTF-8') . '</div>';
    }
    return implode("\n", $o) . "\n";
}

/** Die Felder der Regeln fuer die Einmalmeldung (X-2). */
function au_ansage_regel_felder(array $anlaesse = array())
{
    return ansage_regeln_x2_felder($anlaesse);
}

/**
 * Im Vorgang sprechen, wo der Rufer das Ergebnis braucht (Antwort an Loxone, Befehl): Regeln aus dem Stamm mit "dringend, ohne Sperre" - es zaehlt nur der Schalter.
 * Schalter gesetzt: stand -1, Kennung SCHALTER. Nichts bereit oder Original-Audioserver: wie bisher ansage_sprechen(), damit die Antwort sagt, was fehlt.
 */
function au_ansage_direkt($text, array $e = array(), $tts = null)
{
    $conf = au_config(false);
    $k = au_ansage_k();
    $t = $tts === null ? au_tts_von($conf) : $tts;
    $a = ansage_regel_sprechen(array($e + array('schluessel' => 'direkt', 'text' => (string) $text, 'dringend' => true, 'sperre_min' => 0)), $t, au_ansage_regeln($conf), $k);
    if ($a['r'] !== null) { return $a['r']; }
    if ($a['regel'] === 'SCHALTER') {
        return array('stand' => -1, 'kennung' => 'SCHALTER', 'art' => isset($t['mode']) ? (string) $t['mode'] : '-', 'http' => 0, 'zeile' => '', 'zeichen' => 0);
    }
    return ansage_sprechen((string) $text, $t, $k);
}

/**
 * Der Sender: eigene Sperre, nimmt die Warteliste und spricht nach den Regeln. "aus" und "kein Ziel eingerichtet" hinterlassen keine Zeile;
 * ein gescheitertes Ziel genau eine je Aenderung (ab Werk EIN darf kein Protokoll vollaufen). Text und Token stehen nie im Protokoll.
 */
function au_ansage_senden($jetzt = null)
{
    $d = au_paths()['datadir'];
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
    $fh = @fopen($d . '/ansage_senden.lock', 'c');
    if ($fh === false || !flock($fh, LOCK_EX | LOCK_NB)) { return 'laeuft'; }
    $erg = 'nichts';
    $l = au_ansage_warte(function ($alt) { return array(array(), $alt); });
    $jetzt = $jetzt === null ? time() : (int) $jetzt;
    $frisch = array();
    foreach (is_array($l) ? $l : array() as $e) {
        if (is_array($e) && isset($e['zeit']) && $jetzt - (int) $e['zeit'] <= AU_ANSAGE_HOECHSTALTER) { $frisch[] = $e; }
    }
    if ($frisch) {
        $conf = au_config(false);
        au_ansage_token_pflegen($conf);
        $conf = au_config(false);
        $a = ansage_regel_sprechen($frisch, au_tts_von($conf), au_ansage_regeln($conf), au_ansage_k());
        $erg = $a['regel'] !== '' ? $a['regel'] : 'gesprochen';
        if ($a['r'] !== null) {
            $merk = $d . '/ansage_lage.txt';
            $lage = $a['r']['stand'] === 1 ? 'gesprochen' : ansage_kurz($a['r']);
            if ($a['r']['stand'] === 1 || (string) @file_get_contents($merk) !== $lage) { au_log('Ansage: ' . ansage_regel_kurz($a)); }
            @file_put_contents($merk, $lage);
        } elseif ($a['regel'] !== 'AUS' && $a['regel'] !== 'KEIN_ZIEL') {
            au_log('Ansage entfaellt: ' . ansage_regel_kurz($a));
        }
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    return $erg;
}

/** Der Kontext des Moduls. */
function au_ansage_k()
{
    $p = au_paths();
    return array(
        'werk' => ansage_werk_modus(),
        'port'   => ansage_webport(($p['home'] !== '' ? $p['home'] : dirname(dirname(__DIR__)))
                                   . '/config/system/general.json'),
        'kopf'   => array('User-Agent: LoxBerry AudiConnect'),
        'ordner' => @is_dir($p['datadir']) ? $p['datadir'] : '',
        't'      => function ($s) { return au_t($s); },
        /* B7 (Gruppe 41): Sprechfrist an das Modul 20 s = Frist des Dienstes (ANSAGE_FRIST_S 25 s in
         * bin/audi.py) minus 5 s. Modul 1.2.1 wendet sie in jedem Weg an (ansage_frist()), auch bei
         * Sonos4Lox/SonoX ohne Ziele; 1.2.0 tat es nur im Mehrziel-Weg. */
        'frist'  => 20,
        /* K_TTS_EINTRAG: den Satz bringt das Modul seit 1.1.2 selbst mit; die Umlenkung auf
         * EINST.SICH_TTS_EINTRAG ist seit 0.9.27 gestrichen (X-10). Ab Werk aus - kein 'werk'. */
    );
}

/**
 * Der Satz zu einem Anlass, aus der Sprachdatei. $name ist der Fahrzeugname
 * aus dem Konto; fehlt er, heisst es "Fahrzeug <nr>". Ladestand und
 * Ladegrenze nur, wenn sie bekannt sind.
 */
function au_ansage_satz($anlass, $nr, $name, $soc = null, $grenze = null)
{
    $a = au_ansage_anlaesse();
    if (!isset($a[$anlass])) {
        return '';
    }
    $wer = ($name !== '') ? $name : sprintf(au_t('AU_ANSAGE.FAHRZEUG_NR'), (int) $nr);
    if ($anlass === 'ausfall') {
        return au_t('AU_ANSAGE.S_AUSFALL');
    }
    if ($anlass === 'kabel_vergessen') {
        return sprintf(au_t('AU_ANSAGE.S_KABEL'), $wer, (int) $soc);
    }
    if ($anlass === 'laden_fertig') {
        $s = sprintf(au_t('AU_ANSAGE.S_LADEN_FERTIG'), $wer);
        return $soc === null ? $s : $s . ' ' . sprintf(au_t('AU_ANSAGE.S_LADESTAND'), (int) $soc);
    }
    if ($anlass === 'laden_abbruch') {
        if ($soc === null || $grenze === null) {
            return sprintf(au_t('AU_ANSAGE.S_LADEN_STOERUNG'), $wer);
        }
        return sprintf(au_t('AU_ANSAGE.S_LADEN_ABBRUCH'), $wer, (int) $soc, (int) $grenze);
    }
    return sprintf(au_t($a[$anlass][2]), $wer);
}

/**
 * Die Sicherungsdatei bauen.
 *
 * NEU IN 0.9.12: die Zugangsdaten sind DRIN. Der Hausstandard verlangt es
 * (Regeln/05: "Die Sicherungsdatei traegt den Aktionstoken und alle
 * Zugangsdaten - ohne sie ist sie nach dem Zurueckspielen wertlos"), und der
 * Warntext am Knopf hat es bis 0.9.11 auch behauptet, ohne dass es stimmte:
 * gemessen trug die Datei 34 Schluessel, aber weder E-Mail noch Passwort noch
 * S-PIN. Wer damit auf einen zweiten LoxBerry umzog, hatte alle Felder
 * richtig und kam trotzdem nicht an die Anlage.
 *
 * Das Formulargeheimnis bleibt draussen (au_nicht_sichern()).
 */
function au_sicherung_bauen($pruefen = true)
{
    $cfg = au_config();
    $aus = array(
        '_hinweis' => au_t('EINST.SICH_KOPF'),
        '_plugin'  => 'audiconnect',
        '_stand'   => date('Y-m-d H:i:s'),
    );
    foreach ($cfg as $k => $v) {
        if (in_array($k, au_nicht_sichern(), true)) {
            continue;
        }
        $aus[$k] = $v;
    }
    /* Nr. 36 b: die Sprechtoken der Sprachausgabe gehen nie in eine Sicherung (Entwurf 5). */
    if (isset($aus['tts']) && is_array($aus['tts'])) {
        $aus['tts'] = ansage_sicherung_bereinigen($aus['tts']);
    }
    unset($aus['ansage_token_herkunft']);     // Stamm 1.3.2: der Vermerk geht nie in eine Sicherung
    $z = au_json_lesen(au_paths()['zugang']);
    $aus['zugang'] = array(
        'email'    => isset($z['email']) ? (string) $z['email'] : '',
        'passwort' => isset($z['passwort']) ? (string) $z['passwort'] : '',
        'spin'     => isset($z['spin']) ? (string) $z['spin'] : '',
    );
    /* X-3 (B-Nachzug 01.10.2026): wuerde das eigene Zurueckspielen diese
     * Datei abweisen, sagt es der Kopf - nur Namen, nie Werte. Geliefert
     * wird sie trotzdem vollstaendig. */
    if ($pruefen) {
        $namen = au_rueckspiel_altwerte();
        if ($namen) {
            $aus = array('_warnung' => sprintf(au_t('EINST.SICH_WARN_KOPF'), implode(', ', $namen)))
                 + $aus;
        }
    }
    return $aus;
}

/**
 * X-3 (B-Nachzug 01.10.2026): Welche Einstellungen wuerden beim Zurueckspielen
 * der EIGENEN Sicherung abgewiesen? Gebaut wird genau die Datei, die
 * "Einstellungen sichern" liefert, und durch dieselbe Pruefung geschickt wie
 * beim Zurueckspielen (au_sicherung_lesen). Rueckgabe: Namen (nie Werte),
 * leer heisst "wuerde angenommen".
 *
 * Die Faelle, die hier anschlagen: ein Wert in audi.json, den das Formular
 * nie gespeichert haette (von Hand, aus einer anderen Fassung, eine Grenze,
 * die sich geaendert hat), oder ein unbekannter Schluessel. au_config()
 * setzt solche Werte beim Lesen nicht zurueck - sie stehen in der Sicherung,
 * und das Zurueckspielen weist die ganze Datei ab.
 */
function au_rueckspiel_altwerte()
{
    $js = json_encode(au_sicherung_bauen(false),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($js === false) {
        return array();     // Sichern meldet dann selbst SICH_SCHREIBFEHLER
    }
    $namen = array();
    list($neu) = au_sicherung_lesen($js, null, $namen);
    $namen = array_values(array_unique($namen));
    sort($namen);
    if ($neu === null && !$namen) {
        $namen[] = au_t('EINST.SICH_GANZE_DATEI');
    }
    return $namen;
}

/**
 * Eine Sicherungsdatei einlesen - und dabei NICHTS durchgehen lassen.
 *
 * BERICHTIGT IN 0.9.12, an drei Stellen. Bis 0.9.11 stand hier zwar dieser
 * Anspruch, geprueft wurden aber nur die SCHLUESSELNAMEN:
 *
 *   1. Jeder Wert wird jetzt geprueft, gegen dieselbe Liste wie das Formular
 *      (au_wert_pruefen). Gemessen an 0.9.11 wurde eine Datei mit
 *      intervall = "abc" angenommen und geschrieben.
 *   2. Fehlende Schluessel behalten ihren BISHERIGEN Wert. Bis 0.9.11 fing
 *      die Funktion mit au_vorgaben() an - eine Sicherung aus einer aelteren
 *      Fassung loeschte damit beide Token und setzte 32 Einstellungen auf
 *      Werk zurueck, bei gruener Meldung "2 Werte uebernommen".
 *   3. Der lesbare Kopf (Schluessel mit _ am Anfang) wird UEBERGANGEN, nicht
 *      beanstandet.
 *
 * Grundlage ist der uebergebene Bestand, nicht die Werkseinstellung.
 * Rueckgabe: array(Konfiguration|null, Zugang|null, Beanstandungen[],
 *                  uebernommen, nicht enthalten, behaltene Token[],
 *                  behaltene Spaetschluessel[] - seit 0.9.30, Welle Mehrfach-Ziele: tts.ziele).
 */
function au_sicherung_lesen($roh, $bestand = null, &$namen = null)
{
    $mangel = array();
    $namen = array();     // X-3: die Namen der beanstandeten Einstellungen, nie Werte
    $daten = json_decode((string) $roh, true);
    if (!is_array($daten)) {
        return array(null, null, array(au_t('EINST.SICH_KEIN_JSON')), 0, 0, array(), array());
    }
    $behalten = array();    // U4: leere Token - das geltende bleibt
    $spaet = array();       // Welle Mehrfach-Ziele (0.9.30): Spaetliste - fehlte in der Sicherung, gilt weiter
    if ($bestand === null) {
        $bestand = au_config();
    }
    $neu = array_merge(au_vorgaben(), $bestand);
    $bekannt = array_keys(au_vorgaben());
    $zugang = null;
    $anzahl = 0;
    foreach ($daten as $k => $w) {
        // Der lesbare Kopf wird UEBERGANGEN, nicht beanstandet.
        if ((string) $k !== '' && substr((string) $k, 0, 1) === '_') {
            continue;
        }
        if ((string) $k === 'zugang') {
            /* U5 (Durchgang 29.09.2026): eine Liste oder {} wird abgewiesen,
             * und ein fehlender Unterschluessel ist eine Beanstandung. Bis
             * 0.9.21 liess is_array() beides durch, fehlende Schluessel wurden
             * zu '', und die E-Mail des Kontos war still geloescht, bei
             * gruener Meldung (gemessen, p3c Fall 3). */
            if (!is_array($w) || $w === array() || array_keys($w) === range(0, count($w) - 1)) {
                $mangel[] = sprintf(au_t('EINST.SICH_WERT_FORM'), 'zugang');
                $namen[] = 'zugang';
                continue;
            }
            $z = array();
            foreach (array('email', 'passwort', 'spin') as $zk) {
                if (!array_key_exists($zk, $w)) {
                    $mangel[] = sprintf(au_t('EINST.SICH_ZUGANG_FEHLT'), 'zugang.' . $zk);
                    $namen[] = 'zugang.' . $zk;
                    continue 2;
                }
                $zw = $w[$zk];
                if (!is_string($zw) || !au_wert_taugt($zw)) {
                    $mangel[] = sprintf(au_t('EINST.SICH_WERT_FORM'), 'zugang.' . $zk);
                    $namen[] = 'zugang.' . $zk;
                    continue 2;
                }
                $z[$zk] = $zw;
            }
            if ($z['email'] !== '' && !filter_var($z['email'], FILTER_VALIDATE_EMAIL)) {
                $mangel[] = au_t('EINST.FEHLER_EMAIL');
                $namen[] = 'zugang.email';
                continue;
            }
            if ($z['spin'] !== '' && !preg_match('/^[0-9]{4}$/', $z['spin'])) {
                $mangel[] = au_t('EINST.FEHLER_SPIN');
                $namen[] = 'zugang.spin';
                continue;
            }
            $zugang = $z;
            $anzahl++;
            continue;
        }
        if (in_array($k, au_nicht_sichern(), true)) {
            // Kein Fehler des Anwenders: eine aeltere Sicherung kann es
            // enthalten. Es wird uebergangen, damit das Merkmal dieser
            // Anlage nicht durch ein fremdes ersetzt wird.
            continue;
        }
        if ((string) $k === 'tts') {
            /* Nr. 36 b (seit 0.9.25): eine Sicherung dieses Plugins traegt nie ein Sprechtoken -
             * traegt die Datei eines (auch als Liste, Zahl oder null), stammt sie nicht aus
             * "Einstellungen sichern" und wird abgewiesen; die geltenden Sprechtoken bleiben.
             * Ausgabeart, Adresse und Vorlage werden wie im Formular geprueft (Heimnetz). */
            $au_tm = ansage_sicherung_mangel($w);
            if ($au_tm) {
                $mangel[] = sprintf(au_t('EINST.SICH_TTS_TOKEN'), au_e(implode(', ', $au_tm)));
                $namen[] = 'tts';
                continue;
            }
            $au_tg = '';
            $au_tp = ansage_wert_pruefen($w, $au_tg, au_ansage_modi());
            if ($au_tp === null) {
                $mangel[] = sprintf(au_t('EINST.SICH_TTS'), au_e(ansage_kennung_text($au_tg, au_ansage_k())));
                $namen[] = 'tts';
                continue;
            }
            $au_tj = au_tts();
            /* Welle Mehrfach-Ziele (0.9.30): "$au_tp + $au_tj" behielt die geltenden weiteren Ziele einer Sicherung ohne
             * Liste schon bisher - still. Jetzt geht der Block vorher durch die Spaetliste, und der Hinweis nennt sie. */
            $au_tneu = array('tts' => $au_tp);
            $au_tjetzt = array('tts' => $au_tj);
            /* Welle Mehrfach-Ziele (Sprachmodul 1.2.1, Spaetliste): fehlt die Liste der weiteren Ziele in der Sicherung (Datei einer
             * aelteren Fassung), gelten die jetzigen weiteren Ziele weiter - genannt im Hinweis. Eine Sicherung MIT leerer Liste
             * heisst "keine weiteren Ziele" und wird so uebernommen. */
            $au_spaete = array();
            if (is_array($au_tneu['tts']) && function_exists('ansage_sicherung_spaet')) {
                list($au_tneu['tts'], $au_spaete) = ansage_sicherung_spaet($au_tneu['tts'],
                    (isset($au_tjetzt['tts']) && is_array($au_tjetzt['tts'])) ? $au_tjetzt['tts'] : null);
            }
            foreach ($au_spaete as $au_sn) { $spaet[] = $au_sn; }     // Welle Mehrfach-Ziele: tts.ziele (Spaetliste)
            list($au_tv) = ansage_vervollstaendigen($au_tneu['tts'] + $au_tj, 'aus');
            $neu['tts'] = ansage_sicherung_tokens_behalten($au_tv, $au_tj);
            $anzahl++;
            continue;
        }
        if ($k === 'ansage_token_herkunft') { continue; }     // S2: nie aus einer Datei
        if ($k === 'ansage_regeln') {     // S2: ungueltig = abgewiesen, gueltig = uebernommen
            $s2_g = '';
            $s2_r = ansage_regeln_wert($w, $s2_g);
            if ($s2_r === null) { $mangel[] = au_t('EINST.SICH_REGELN'); $namen[] = 'ansage_regeln'; continue; }
            $neu['ansage_regeln'] = $s2_r;
            $anzahl++;
            continue;
        }
        if (!in_array($k, $bekannt, true)) {
            $mangel[] = sprintf(au_t('EINST.SICH_FREMD'), au_e((string) $k));
            $namen[] = (string) $k;
            continue;
        }
        if (($k === 'aktionstoken' || $k === 'schalttoken') && $w === '') {
            /* U4 (Durchgang 29.09.2026): ein leeres Token heisst "nicht
             * gesichert" (Regeln/05) - das geltende bleibt, und die Meldung
             * sagt es. Bis 0.9.21 wurde '' geschrieben; die Zweitschrift blieb
             * deshalb stehen, und der naechste au_config() im selben
             * Seitenaufbau heilte den GANZEN zurueckgespielten Stand wieder
             * weg - bei gruener Meldung (gemessen, p3c Fall 2). */
            $behalten[] = $k;
            continue;
        }
        $grund = au_wert_pruefen($k, $w);
        if ($grund !== '') {
            $mangel[] = $grund;
            $namen[] = (string) $k;
            continue;
        }
        $neu[$k] = is_string($w) && isset(au_grenzen()[$k]) ? (int) $w : $w;
        $anzahl++;
    }
    if ($anzahl === 0) {
        $mangel[] = au_t('EINST.SICH_LEER');
    }
    if (isset($neu['temp_min'], $neu['temp_max']) && $neu['temp_min'] > $neu['temp_max']) {
        $mangel[] = au_t('EINST.FEHLER_TEMP_TAUSCH');
        $namen[] = 'temp_min';
        $namen[] = 'temp_max';
    }
    /* Fehlende Schluessel sind eine Beanstandung, kein stiller Rueckfall -
     * ueber den Bestand ausgerollt am 07.09.2026. Der Hausstandard sagt:
     * eine halb gueltige Datei aendert gar nichts.
     *
     * Bei DIESER Linie faellt nichts auf die Werkseinstellung zurueck: $neu
     * geht vom BESTAND aus (array_merge(au_vorgaben(), $bestand)), damit
     * 'formgeheimnis' den Wert DIESER Anlage behaelt. Eine unvollstaendige
     * Datei ergaebe aber einen halb zurueckgespielten Stand, in dem sich
     * Werte aus der Datei mit denen dieser Anlage mischen - ohne dass an der
     * Oberflaeche etwas davon zu sehen waere. Der Text sagt genau das; der
     * Satz von der Werkseinstellung waere hier eine Falschaussage.
     *
     * Verglichen wird gegen die VORGABEN. Was ausserhalb der
     * Konfigurationsdatei liegt, darf fehlen: die Zugangsdaten ('zugang',
     * eigene Datei mit 0600) stehen nicht in au_vorgaben(), und was diese
     * Anlage kennzeichnet, nennt au_nicht_sichern(). */
    $fehlend = array();
    foreach ($bekannt as $k) {
        if (!array_key_exists($k, $daten) && !in_array($k, au_nicht_sichern(), true)) {
            /* Nr. 36 b: eine Sicherung von 0.9.24 oder frueher kennt die Sprachausgabe noch
             * nicht. Sie bleibt zurueckspielbar; die neuen Schluessel behalten den Wert dieser
             * Anlage ($neu geht vom Bestand aus), und die Seite sagt es. */
            if (in_array($k, au_ansage_neue_schluessel(), true)
                || in_array($k, au_neue_schluessel_0930(), true)) {
                // Gruppe 41: ebenso die Schluessel von 0.9.30 (endpunkt_ein behaelt den Wert der Anlage).
                continue;
            }
            $fehlend[] = $k;
        }
    }
    if ($fehlend) {
        $mangel[] = sprintf(au_t('EINST.SICH_FEHLEND'), count($fehlend),
            au_e(implode(', ', $fehlend)));
        foreach ($fehlend as $k) {
            $namen[] = (string) $k;
        }
    }
    if ($mangel) {
        return array(null, null, $mangel, $anzahl, $fehlend, $behalten, $spaet);
    }
    return array($neu, $zugang, $mangel, $anzahl, $fehlend, $behalten, $spaet);
}

/* ==================================================================
 * Gruppe 41 (0.9.30): Hausform - Gateway V2, Befehlsbruecke 1.0.2,
 * endpunkt_ein, Haus-Themen. Bauvorschrift
 * Pruefung-Durchgang-2026-09-29/wz_bild/GRUPPE41_BAUVORSCHRIFT.md (G1-G3, 2.1),
 * Vorlage MGiSmart 1.1.28 (mg_v2_*, mg_bruecke_*) und Renault NG 2.1.20.
 * ================================================================== */


/* ---------------- Sammelthemen haus/fahrzeug (Regeln/07, Vorbild MGiSmart 1.1.31) ---------------- */

/** Die Werte des Formulars pruefen: normierter Wert oder null. kabel_soc 1..100, kabel_abfahrt_min 0..720, vorklima_min_soc 0..100, kabel_uhr HH:MM. Rein. */
function au_sammel_wert($k, $v)
{
    $v = trim((string) $v);
    if ($k === 'kabel_uhr') {
        return preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $v) ? $v : null;
    }
    $g = array('kabel_soc' => array(1, 100), 'kabel_abfahrt_min' => array(0, 720), 'vorklima_min_soc' => array(0, 100));
    if (!isset($g[$k]) || !preg_match('/^[0-9]{1,3}$/', $v) || (int) $v < $g[$k][0] || (int) $v > $g[$k][1]) {
        return null;
    }
    return (int) $v;
}

/** Die Zeile im Reiter Test: array(Stand 1|0|-1, Text). Liest nur die Merker des Dienstes, nennt keinen Wert. 0: fremder Zaehler unter einem eigenen Stamm. */
function au_sammel_lage($cfg)
{
    if (empty($cfg['haus_fahrzeug_ein'])) {
        return array(-1, au_t('EINST.A_HAUS_FAHRZEUG_AUS'));
    }
    $fr = array();
    foreach (array_keys(au_json_lesen(au_paths()['datadir'] . '/haus_fahrzeug_fremd.json')) as $s) {
        if (is_string($s) && strpos($s, 'haus/fahrzeug/') === 0) { $fr[] = substr($s, strlen('haus/fahrzeug/')); }
    }
    if ($fr) {
        return array(0, sprintf(au_t('EINST.A_HAUS_FAHRZEUG_FREMD'), implode(', ', $fr)));
    }
    $m = au_json_lesen(au_paths()['datadir'] . '/haus_fahrzeug.json');
    $namen = array();
    if (isset($m['fahrzeuge']) && is_array($m['fahrzeuge'])) {
        foreach ($m['fahrzeuge'] as $e) {
            if (is_array($e) && isset($e['stamm']) && is_string($e['stamm'])) { $namen[] = substr($e['stamm'], strlen('haus/fahrzeug/')); }
        }
    }
    return $namen ? array(1, sprintf(au_t('EINST.A_HAUS_FAHRZEUG'), implode(', ', $namen))) : array(-1, au_t('EINST.A_HAUS_FAHRZEUG_NOCH'));
}

/** Die Schluessel, die mit 0.9.30 dazukamen - eine aeltere Sicherung kennt sie nicht; die Anlage behaelt ihren Wert. */
function au_neue_schluessel_0930()
{
    return array('haus_fahrzeug_ein', 'kabel_soc', 'kabel_uhr', 'kabel_abfahrt_min', 'signal_kabel', 'vorklima_min_soc', 'vorklima_nur_kabel',     // 0.9.32
                 'befehle_mqtt_ein', 'befehle_mqtt_steuern', 'endpunkt_ein', 'haus_abfahrt_ein',
                 'vorklima_anwesend_ein', 'vorklima_wetter_ein', 'vorklima_kalt_unter', 'vorklima_heiss_ueber',
                 'ankunft_ein', 'ankunft_quelle', 'haus_strompreis_ein', 'ladeempf_quelle', 'signal_ein',
                 'signal_praefix', 'signal_laden_fertig', 'signal_laden_abbruch', 'signal_offen',
                 'signal_ausfall', 'signal_vorklima');
}

/** Sind Zugangsdaten eingetragen? (E-Mail und Passwort; die S-PIN ist fuer das Lesen nicht noetig.) */
function au_zugang_da()
{
    $z = au_zugang();
    return $z['email'] !== '' && $z['laenge'] > 0;
}

/* ---------------- G1: MQTT-Gateway V2 ---------------- */

/** Der Name, den das MQTT-Gateway vergibt: '/', '%' und Leerzeichen werden '_' (Regeln/07 :69). Rein. */
function au_gateway_name($thema)
{
    return str_replace(array('/', '%', ' '), '_', (string) $thema);
}

/** Die Themen-Tabelle bin/au_themen.json je Ebene: array('dienst' => [...], 'fahrzeug' => [...]) oder array(). */
function au_themen_roh()
{
    $f = au_themen_datei();
    $d = $f !== '' ? json_decode((string) @file_get_contents($f), true) : null;
    if (!is_array($d)) {
        return array();
    }
    $aus = array('dienst' => array(), 'fahrzeug' => array());
    foreach (array_keys($aus) as $ebene) {
        if (!isset($d[$ebene]) || !is_array($d[$ebene])) {
            continue;
        }
        foreach ($d[$ebene] as $e) {
            if (!is_array($e) || !isset($e['thema']) || !is_string($e['thema'])) {
                continue;
            }
            $aus[$ebene][] = array('thema' => $e['thema'],
                'art' => (isset($e['art']) && $e['art'] === 'text') ? 'text' : 'zahl',
                'bez' => isset($e['bez']) && is_string($e['bez']) ? $e['bez'] : '');
        }
    }
    return $aus;
}

/** Die Fahrzeugnummern fuer die Hakenliste: aus dem Abbild, ohne Abbild Fahrzeug 1. */
function au_v2_fahrzeugnummern()
{
    $n = array();
    foreach (array_keys(au_fahrzeuge()) as $k) {
        if (preg_match('/^[0-9]{1,2}$/', (string) $k) && (int) $k >= 1) {
            $n[] = (int) $k;
        }
    }
    sort($n);
    return $n ? array_values(array_unique($n)) : array(1);
}

/**
 * Namenstabelle und V2-Hakenliste - EINE Quelle fuer Reiter MQTT, Reiter Test,
 * Linienpruefer und Messskript (G1). Aus der Themen-Tabelle, nach der der Dienst
 * sendet: alle Zahlenthemen (keine Textthemen - das Musterprojekt hat keinen
 * Texteingang, nie vin/adresse), nicht status/ts (sekuendlich wechselnd, Regeln/07
 * :267), dazu status/befehle der Bruecke. Noncached genau: ok, status/zaehler,
 * fahrzeug<n>/ok, status/befehle. Rueckgabe Liste von array(thema, name, noncached, bez).
 */
function au_v2_haken($cfg = null)
{
    if ($cfg === null) {
        $cfg = au_config(false);
    }
    $pf = trim((string) (isset($cfg['mqtt_topic']) ? $cfg['mqtt_topic'] : ''), '/ ');
    if ($pf === '') {
        $pf = 'audi';
    }
    $d = au_themen_roh();
    $aus = array();
    if (!$d || !$d['fahrzeug']) {
        return $aus;   // leer -> "nicht feststellbar", nie "0 von 0"
    }
    foreach ($d['dienst'] as $e) {
        if ($e['art'] === 'text' || $e['thema'] === 'status/ts') {
            continue;
        }
        $t = $pf . '/' . $e['thema'];
        $aus[] = array('thema' => $t, 'name' => au_gateway_name($t),
                       'noncached' => in_array($e['thema'], array('ok', 'status/zaehler'), true), 'bez' => $e['bez']);
    }
    foreach (au_v2_fahrzeugnummern() as $nr) {
        foreach ($d['fahrzeug'] as $e) {
            if ($e['art'] === 'text') {
                continue;
            }
            $t = $pf . '/fahrzeug' . $nr . '/' . $e['thema'];
            $aus[] = array('thema' => $t, 'name' => au_gateway_name($t), 'noncached' => $e['thema'] === 'ok',
                           'bez' => $e['bez']);
        }
    }
    $t = $pf . '/status/befehle';
    $aus[] = array('thema' => $t, 'name' => au_gateway_name($t), 'noncached' => true, 'bez' => 'AU_MQTT.STATUS_BEFEHLE');
    return $aus;
}

/** Die angehakten Datenpunkte aus subscriptions.json (Rein): Id => noncached. null = unlesbar. Abschrift mg_v2_abos(). */
function au_v2_abos($daten)
{
    if (!is_array($daten)) {
        return null;
    }
    $liste = array();
    foreach (array('Subscriptions', 'subscriptions') as $s) {
        if (isset($daten[$s]) && is_array($daten[$s])) {
            $liste = $daten[$s];
            break;
        }
    }
    $abo = array();
    foreach ($liste as $e) {
        if (!is_array($e)) {
            continue;
        }
        $id = '';
        foreach (array('Id', 'id', 'Topic', 'topic') as $k) {
            if (isset($e[$k]) && is_string($e[$k])) {
                $id = $e[$k];
                break;
            }
        }
        if ($id === '') {
            continue;
        }
        $nc = false;
        foreach (array('Noncached', 'noncached') as $k) {
            if (isset($e[$k])) {
                $nc = ($e[$k] === true || in_array(strtolower(trim((string) $e[$k])), array('1', 'true', 'yes', 'on'), true));
            }
        }
        $abo[$id] = $nc;
    }
    return $abo;
}

/** Soll gegen Ist (Rein): array(1|0, fehlt[], noncached_falsch[], da, soll). Abschrift mg_v2_haken_abgleich(). */
function au_v2_haken_abgleich(array $soll, array $abo)
{
    $fehlt = array();
    $nc = array();
    $da = 0;
    foreach ($soll as $e) {
        if (!array_key_exists($e['thema'], $abo)) {
            $fehlt[] = $e['name'];
            continue;
        }
        $da++;
        if ((bool) $abo[$e['thema']] !== (bool) $e['noncached']) {
            $nc[] = $e['name'] . ($e['noncached'] ? ' (Noncached fehlt)' : ' (Noncached zu viel)');
        }
    }
    return array(($fehlt || $nc) ? 0 : 1, $fehlt, $nc, $da, count($soll));
}

/**
 * Selbsttestzeile "Gateway V2: alle Datenpunkte angehakt?" (einheitliche Pruefzeile, Nachtwelle 29.10.):
 * array(1|0|2, Text) - 2 = grau (nicht feststellbar, Gateway V1, noch nicht eingerichtet). Liest nur.
 * Die Argumente nach $cfg nur fuer Proben; sonst aus Tabelle, general.json und subscriptions.json.
 */
function au_v2_haken_lage($cfg = null, $soll = null, $abo = false, $fassung = null, $eingerichtet = null)
{
    if ($cfg === null) {
        $cfg = au_config(false);
    }
    if ($fassung === null) {
        $m = au_mqtt_zustand();
        $fassung = !empty($m['gefunden']) ? (int) $m['fassung'] : 0;
    }
    if ((int) $fassung <= 0) {
        return array(2, au_t('PRUEF.V2_UNKLAR'));
    }
    if ((int) $fassung < 2) {
        return array(2, au_t('PRUEF.V2_V1'));
    }
    if ($soll === null) {
        $soll = au_v2_haken($cfg);
    }
    if (!$soll) {
        return array(2, au_t('PRUEF.V2_LEER'));
    }
    if ($eingerichtet === null) {
        $eingerichtet = au_zugang_da();
    }
    if (!$eingerichtet) {
        return array(2, au_t('PRUEF.V2_NICHT_EINGERICHTET'));
    }
    if ($abo === false) {
        $p = au_paths();
        $f = $p['home'] !== '' ? $p['home'] . '/config/system/subscriptions.json' : '';
        $abo = ($f !== '' && is_file($f)) ? au_v2_abos(json_decode((string) @file_get_contents($f), true)) : null;
    }
    if ($abo === null) {
        return array(2, au_t('PRUEF.V2_UNKLAR'));
    }
    list($ok, $fehlt, $nc, $da, $zahl) = au_v2_haken_abgleich($soll, $abo);
    $txt = sprintf(au_t('PRUEF.V2_ZAHL'), $da, $zahl);
    if ($fehlt) {
        $txt .= ' ' . sprintf(au_t('PRUEF.V2_FEHLT'), implode(', ', array_slice($fehlt, 0, 12))
            . (count($fehlt) > 12 ? ' ...' : ''));
    }
    if ($nc) {
        $txt .= ' ' . sprintf(au_t('PRUEF.V2_NC'), implode(', ', $nc));
    }
    return array($ok, $txt);
}

/* ---------------- G2: eine Annahmefunktion fuer HTTP und MQTT ---------------- */

/** Fahrzeug zur laufenden Nummer oder VIN, wie au_waehlen() im Endpunkt. null = unbekannt. */
function au_fahrzeug_suchen(array $alle, $schluessel)
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

/** Die Muster der Zusatzwerte - dieselben wie au_param() im Endpunkt (abweisen, nie zurechtbiegen). */
function au_befehl_muster()
{
    return array('fahrzeug' => '/^([0-9]{1,2}|[A-Za-z0-9]{17})$/', 'temp' => '/^[0-9]{1,2}([.,][05])?$/',
                 'prozent' => '/^[0-9]{1,3}$/', 'ampere' => '/^[0-9]{1,2}$/', 'dauer' => '/^[0-9]{1,2}$/',
                 'name' => '/^[a-z_]{1,32}$/', 'wert' => '/^[01]$/', 'probe' => '/^[01]$/');
}

/**
 * Einen schaltenden Befehl annehmen - DIESELBE Funktion fuer den HTTP-Endpunkt und
 * die Befehlsbruecke (Bauvorschrift G41 G2, Regeln/07 Abschnitt 3a). Pruefreihenfolge
 * wie der Endpunkt bis 0.9.29 (dort inline): Aktion bekannt -> [MQTT: spin_pruefen nie]
 * -> Fahrzeug im Abbild -> Steuerung -> Eingriff -> Dienst laeuft -> Zusatzwerte ->
 * X-7-Gleichwertbremse -> Einreihen. HTTP wartet auf den Dienst wie bisher; MQTT wartet
 * nie (Quittung "uebergeben": eingereiht = angenommen, E3). Der Dienst prueft danach
 * ein zweites Mal (Steuerung, Gefahr, S-PIN-Sperre, Stundenbremse fuer alle Wege).
 *
 * $param: temp, prozent, ampere, dauer, name, wert, probe (Zeichenketten wie aus der Adresse).
 * Rueckgabe array('ok' => 0|1|2, 'grund', 'http' => Code, 'text' => Satz,
 *                 'art' => abgewiesen|unveraendert|gesetzt, ['kopf' => Zeile bei FAHRZEUG_UNBEKANNT]).
 */
function au_befehl_annehmen($aktion, array $param, $fahrzeug, $quelle)
{
    $mqtt = ($quelle === 'mqtt');
    $aktion = is_string($aktion) ? $aktion : '';
    // $melden: eine Protokollzeile wie bisher im Endpunkt (nicht fuer fehlende Zusatzwerte und unbekannte Fahrzeuge).
    $raus = function ($grund, $http, $text, $melden = true) use ($aktion, $mqtt) {
        if (!$mqtt && $melden) {
            au_endpunkt_abweisung($grund, $aktion);
        }
        return array('ok' => 0, 'grund' => $grund, 'http' => $http, 'text' => $text, 'art' => 'abgewiesen');
    };
    $alle_befehle = au_befehle();
    if (!isset($alle_befehle[$aktion])) {
        return $raus('UNBEKANNTE_AKTION', 400, 'Erlaubt sind: ' . implode(', ', array_keys($alle_befehle)));
    }
    $eig = $alle_befehle[$aktion];
    if ($mqtt && $aktion === 'spin_pruefen') {
        // E5: die S-PIN-Pruefung geht nie ueber MQTT (UDP 11884 ist im Heimnetz ohne Anmeldung erreichbar).
        return $raus('NICHT_UEBER_MQTT', 403, 'Die S-PIN-Pruefung geht nur ueber die Oberflaeche oder das Schalttoken.');
    }
    $muster = au_befehl_muster();
    $fahrzeug = is_scalar($fahrzeug) ? (string) $fahrzeug : '';
    if ($fahrzeug === '') {
        $fahrzeug = '1';
    }
    if (!preg_match($muster['fahrzeug'], $fahrzeug)) {
        return $raus('PARAMETER', 400, 'Der Wert von fahrzeug passt nicht ins erlaubte Muster.');
    }
    $alle = au_fahrzeuge();
    $f = au_fahrzeug_suchen($alle, $fahrzeug);
    if ($f === null && empty($eig['ohne_fz'])) {
        // Nie auf Fahrzeug 1 zurueckfallen (0.9.12: eine vertippte VIN entriegelte das falsche Auto).
        $r = $raus('FAHRZEUG_UNBEKANNT', 404, '', false);
        $r['kopf'] = sprintf('%s;OK=0;GRUND=FAHRZEUG_UNBEKANNT;N=%d;ALTER=%d', strtoupper($aktion), count($alle), au_alter());
        return $r;
    }
    $cfg = au_config(false);
    if ($aktion !== 'abruf' && empty($cfg['steuerung_ein'])) {
        return $raus('STEUERUNG_AUS', 403, "Schreibende Befehle sind gesperrt. Reiter Einstellungen, Haken 'Schreibende Befehle zulassen'.");
    }
    if ($eig['gefahr'] && empty($cfg['gefahr_ein'])) {
        /* Der ZWEITE Haken. Ver- und Entriegeln, Hupe und Lichthupe wirken auf ein Fahrzeug, das im
         * oeffentlichen Raum steht - ein versehentliches Entriegeln laesst es offen stehen. */
        return $raus('EINGRIFF_GESPERRT', 403, "Dieser Befehl greift in ein Fahrzeug ein, das im oeffentlichen Raum steht. "
            . "Reiter Einstellungen, zweiter Haken 'Eingreifende Befehle zulassen'.");
    }
    if (au_dienst_pid() === 0) {
        // Nicht stillschweigend einreihen: ohne laufenden Dienst passiert nichts.
        return $raus('DIENST_LAEUFT_NICHT', 503, "Der Abrufdienst laeuft nicht. Reiter Einstellungen, Knopf 'Dienst starten'.");
    }
    $w = function ($k) use ($param) {
        return (isset($param[$k]) && is_scalar($param[$k])) ? (string) $param[$k] : '';
    };
    foreach (array('temp', 'prozent', 'ampere', 'dauer', 'name', 'wert', 'probe') as $k) {
        if ($w($k) !== '' && !preg_match($muster[$k], $w($k))) {
            return $raus('PARAMETER', 400, 'Der Wert von ' . $k . ' passt nicht ins erlaubte Muster.');
        }
    }
    $befehl = array('aktion' => $aktion);
    if (empty($eig['ohne_fz'])) {
        $befehl['fahrzeug'] = $fahrzeug;
    }
    if ($w('probe') === '1') {
        $befehl['probe'] = 1;
    }
    if ($eig['zusatz'] === 'temp') {
        if ($w('temp') === '') {
            return $raus('TEMP_FEHLT', 400, 'Der Parameter temp fehlt (Zieltemperatur in Grad Celsius).', false);
        }
        $befehl['temp'] = str_replace(',', '.', $w('temp'));
    } elseif ($eig['zusatz'] === 'prozent') {
        if ($w('prozent') === '') {
            return $raus('PROZENT_FEHLT', 400, 'Der Parameter prozent fehlt (Ladegrenze, 10 bis 100).', false);
        }
        $befehl['prozent'] = (int) $w('prozent');
    } elseif ($eig['zusatz'] === 'ampere') {
        if ($w('ampere') === '') {
            return $raus('AMPERE_FEHLT', 400, 'Der Parameter ampere fehlt (zulaessig: 5, 6, 10, 13, 16 oder 32).', false);
        }
        $befehl['ampere'] = (int) $w('ampere');
    } elseif ($eig['zusatz'] === 'dauer') {
        if ($w('dauer') !== '') {
            $befehl['dauer'] = (int) $w('dauer');
        }
    } elseif ($eig['zusatz'] === 'einstellung') {
        $bekannt = au_einstellungen();
        if ($w('name') === '' || !isset($bekannt[$w('name')])) {
            return $raus('NAME_FEHLT', 400, 'Der Parameter name fehlt oder ist unbekannt. Bekannt sind: '
                . implode(', ', array_keys($bekannt)), false);
        }
        if ($w('wert') === '') {
            return $raus('WERT_FEHLT', 400, 'Der Parameter wert fehlt (0 oder 1).', false);
        }
        $befehl['name'] = $w('name');
        $befehl['wert'] = $w('wert');
    }
    /* X-7: Befehlsbremse fuer Sollwerte - erst hinter allen Wachen und Pruefungen. */
    $bgr = au_bremse_gruppe($aktion, $befehl);
    $bschl = '';
    $bmarke = '';
    if ($bgr !== null) {
        $trocken = ($w('probe') === '1' || !empty($cfg['probe_ein']));
        $bschl = au_bremse_fz($f, $fahrzeug) . '|' . $bgr[0];
        list($burteil, $bseit, $bmarke) = au_bremse_pruefen($bschl, $bgr[1], $trocken);
        if ($burteil === 'MERKER') {
            return $raus('BREMSE_MERKER', 503, 'Die Merkerdatei der Befehlsbremse laesst sich nicht oeffnen oder schreiben - '
                . 'Sollwert-Befehle werden abgewiesen, bis das behoben ist. Pruefen: Platz und '
                . 'Eigentuemer (loxberry) des Datenordners des Plugins.');
        }
        if ($burteil === 'UNVERAENDERT') {
            return array('ok' => 1, 'grund' => 'UNVERAENDERT', 'http' => 200, 'art' => 'unveraendert',
                'text' => 'Derselbe Befehl ging vor ' . (int) $bseit . ' s hinaus - es wurde '
                    . 'nichts gesendet. Gleiche Sollwerte innerhalb von ' . AU_BREMSE_GLEICH_S
                    . ' s gehen nur einmal an Audi.');
        }
    }
    // MQTT wartet nie (E3, Quittung "uebergeben"); HTTP wie bisher (au_wartezeit_fuer()).
    list($erg, $meldung) = au_befehl_absetzen($befehl, $mqtt ? 0 : null);
    if ($mqtt && (int) $erg === 2) {
        $meldung = 'Eingereiht - der Dienst arbeitet den Befehl ab; das Ergebnis steht im Protokoll und im Reiter Test.';
    }
    if ($bmarke !== '' && (int) $erg === 0) {
        // Abgelehnt: nicht gemerkt, der naechste gleiche Befehl geht hinaus.
        au_bremse_vergessen($bschl, $bmarke);
    }
    return array('ok' => (int) $erg, 'grund' => (int) $erg === 0 ? 'ABGELEHNT' : '', 'http' => (int) $erg === 0 ? 500 : 200,
                 'text' => (string) $meldung, 'art' => 'gesetzt');
}

/** Quittung der Bruecke: array(true, '') bei ok >= 1 (eingereiht oder UNVERAENDERT), sonst array(false, Grund). */
function au_befehl_quittung($erg)
{
    if (is_array($erg) && isset($erg['ok']) && (int) $erg['ok'] >= 1) {
        return array(true, '');
    }
    return array(false, (is_array($erg) && isset($erg['grund']) && $erg['grund'] !== '') ? (string) $erg['grund'] : 'FEHLER');
}

/** G5/E-E4: die Aussentemperatur aus Loxone merken (Bruecke <p>/befehl/aussentemp). Datei 0600, atomar. */
function au_aussentemp_merken($wert)
{
    $p = au_paths();
    if (!is_numeric($wert) || (float) $wert < -40 || (float) $wert > 60) {
        return array('ok' => 0, 'grund' => 'WERT', 'http' => 400, 'text' => '', 'art' => 'abgewiesen');
    }
    if (!is_dir($p['datadir'])) {
        return array('ok' => 0, 'grund' => 'DATENORDNER_FEHLT', 'http' => 503, 'text' => '', 'art' => 'abgewiesen');
    }
    $js = json_encode(array('wert' => round((float) $wert, 1), 't' => time()));
    if ($js === false || !au_datei_schreiben($p['datadir'] . '/aussentemp.json', $js, 0600)) {
        return array('ok' => 0, 'grund' => 'MERKER', 'http' => 503, 'text' => '', 'art' => 'abgewiesen');
    }
    return array('ok' => 1, 'grund' => '', 'http' => 200, 'text' => 'gemerkt', 'art' => 'gesetzt');
}

/* ---------------- G2: Befehlsbruecke fuer die Oberflaeche (Abschrift mg_bruecke_*) ---------------- */

/** Die Bruecke als Bibliothek laden (BB_NUR_LADEN). Rueckgabe true, wenn bb_waechter() danach da ist. */
function au_bruecke_laden()
{
    if (function_exists('bb_waechter')) {
        return true;
    }
    $p = au_paths();
    $kand = array();
    if ($p['home'] !== '') {
        $kand[] = $p['home'] . '/bin/plugins/' . $p['plugin'] . '/befehlsbruecke.php';
    }
    $kand[] = dirname(dirname(__DIR__)) . '/bin/befehlsbruecke.php';
    foreach ($kand as $k) {
        if (is_file($k)) {
            if (!defined('BB_NUR_LADEN')) {
                define('BB_NUR_LADEN', true);
            }
            require_once $k;
            return function_exists('bb_waechter');
        }
    }
    return false;
}

/** Die Befehlsliste der Bruecke (bb_liste()). array(Liste oder null, Fehlertext). */
function au_bruecke_liste()
{
    if (!au_bruecke_laden()) {
        return array(null, au_t('BEFEHL.T_NICHT_DA'));
    }
    list($z, $fehler) = bb_zuordnung_laden();
    if ($z === null) {
        return array(null, $fehler);
    }
    $GLOBALS['bb_zuordnung'] = $z;
    list($fehl) = bb_zuordnung_pruefen($z);
    if ($fehl) {
        return array(null, $fehl[0]);
    }
    return array(bb_liste($z, bb_einstellungen($z)), '');
}

/** Nach dem Speichern im Reiter MQTT: start (Haken an) bzw. stop. array(rc|null, start|stop|'', Ausgabe). */
function au_bruecke_schalten($cfg = null)
{
    if (!is_array($cfg)) {
        $cfg = au_config();
    }
    if (!au_bruecke_laden()) {
        return array(1, '', au_t('BEFEHL.T_NICHT_DA'));
    }
    $p = bb_pfade();
    if (!$p) {
        return array(1, '', 'keine LoxBerry-Installation erkannt');
    }
    if (!empty($cfg['befehle_mqtt_ein'])) {
        list($rc, $aus) = bb_sh($p, 'start');
        return array($rc, 'start', $aus);
    }
    if (!bb_dienste()) {
        return array(null, '', '');
    }
    list($rc, $aus) = bb_sh($p, 'stop');
    return array($rc, 'stop', $aus);
}

/** Lage der Bruecke (Reiter Test, Reiter MQTT): array(true|false|null, Text); null = aus (Haken). */
function au_bruecke_lage()
{
    if (!au_bruecke_laden()) {
        return array(false, au_t('BEFEHL.T_NICHT_DA'));
    }
    list($soll, $grund) = bb_soll_ermitteln();
    if ($soll === 1) {
        return array(null, au_t('BEFEHL.T_AUS'));
    }
    if ($soll !== 0) {
        return array(false, sprintf(au_t('BEFEHL.T_DEFEKT'), $grund));
    }
    $zu = bb_zustand_lesen();
    $pids = bb_dienste();
    $alter = (is_array($zu) && isset($zu['ts'])) ? time() - (int) $zu['ts'] : null;
    if ($pids && $alter !== null && $alter <= BB_WACHE_GRENZE && !empty($zu['abo'])) {
        $zl = (isset($zu['zaehler']) && is_array($zu['zaehler'])) ? $zu['zaehler'] : array();
        $txt = sprintf(au_t('BEFEHL.T_LAEUFT'), count($pids),
            isset($zl['angenommen']) ? (int) $zl['angenommen'] : 0, isset($zl['abgelehnt']) ? (int) $zl['abgelehnt'] : 0);
        $lz = isset($zu['letzter']) && is_array($zu['letzter']) ? $zu['letzter'] : null;
        if ($lz !== null && isset($lz['name'])) {
            $txt .= ' ' . sprintf(au_t('BEFEHL.T_LETZTER'), (string) $lz['name'],
                isset($lz['ergebnis']) ? (string) $lz['ergebnis'] : '-',
                isset($lz['zeit']) ? max(0, time() - (int) $lz['zeit']) : 0);
        } else {
            $txt .= ' ' . au_t('BEFEHL.T_KEINER');
        }
        return array(true, $txt);
    }
    $wie = !$pids ? 'kein Dienst' : ($alter === null ? 'kein Zustand' : ($alter > BB_WACHE_GRENZE
        ? 'Zustand ' . $alter . ' s alt' : 'ohne Abo'));
    return array(false, sprintf(au_t('BEFEHL.T_STEHT'), $wie));
}

/** befehlsbruecke.php --pruefen: array(rc, Zeilen). */
function au_bruecke_pruefen()
{
    if (!au_bruecke_laden()) {
        return array(1, array(au_t('BEFEHL.T_NICHT_DA')));
    }
    $p = bb_pfade();
    $skript = $p ? $p['bin'] . '/befehlsbruecke.php' : dirname(dirname(__DIR__)) . '/bin/befehlsbruecke.php';
    $php = (defined('PHP_BINARY') && PHP_BINARY !== '') ? PHP_BINARY : 'php';
    $aus = array();
    $rc = 1;
    @exec(escapeshellarg($php) . ' ' . escapeshellarg($skript) . ' --pruefen 2>&1', $aus, $rc);
    return array((int) $rc, $aus);
}

/** Der Rechnername fuer UDP-Adressen: nie die Rueckschleife (Regeln/07). */
function au_rechnername()
{
    $h = au_host();
    if (substr_count($h, ':') === 1) {
        $h = substr($h, 0, strpos($h, ':'));
    }
    if (in_array(strtolower($h), array('', '127.0.0.1', 'localhost', '::1', '[::1]'), true)) {
        $g = gethostname();
        $h = ($g !== false && $g !== '') ? $g : 'loxberry';
    }
    return $h;
}

/** Die Vorlage "Audi MQTT-Befehle" aus bb_vorlage() (CRLF, /dev/udp/<rechner>/<Udpinport>). array(Name, Inhalt|''). */
function au_vorlage_befehle()
{
    list($l) = au_bruecke_liste();
    if ($l === null) {
        return array('VQ_audiconnect_mqtt.xml', '');
    }
    $bp = bb_pfade();
    $port = $bp ? bb_broker($bp)['udpin'] : 11884;
    return array('VQ_audiconnect_mqtt.xml', bb_vorlage($l, au_rechnername(), $port));
}

/* ---------------- G4/G5: Haus-Quellen fuer den Reiter Test ---------------- */

/** Thema fahrzeug<n>/vorklima aus einem Merker (wie vorklima_wert() in bin/audi.py): 0 nichts/aus, 1 gesendet,
 *  2 mild, 3 wartet auf Wiederholung, 4 wartet auf Anwesenheit, -1 gescheitert. Rein. */
function au_vorklima_wert($m, $jetzt)
{
    if (!is_array($m) || !isset($m['kennung'])) {
        return 0;
    }
    if ((int) $m['kennung'] + 1800 < (int) $jetzt) {
        return 0;
    }
    if (!empty($m['aufgegeben'])) {
        return -1;
    }
    if (!empty($m['erfolg'])) {
        return 1;
    }
    if (!empty($m['mild'])) {
        return 2;
    }
    if (!empty($m['sperre'])) {     // 0.9.32
        return $m['sperre'] === 'akku' ? 5 : 6;
    }
    if (!empty($m['warten_anwesend'])) {
        return 4;
    }
    return !empty($m['versuche']) ? 3 : 0;
}

/** Welche Haus-Quellen liest der Dienst bei dieser Konfiguration? Name => Satz des Rueckfalls. */
function au_haus_quellen($cfg = null)
{
    if ($cfg === null) {
        $cfg = au_config(false);
    }
    $q = array();
    if (!empty($cfg['abfahrt_ein']) && !empty($cfg['haus_abfahrt_ein'])) {
        $q['abfahrt'] = 'HAUS.RUECK_ABFAHRT';
    }
    if ((!empty($cfg['abfahrt_ein']) && !empty($cfg['vorklima_anwesend_ein']))
        || (!empty($cfg['ankunft_ein']) && trim((string) $cfg['ankunft_quelle']) !== '')) {
        $q['anwesenheit'] = 'HAUS.RUECK_ANWESENHEIT';
    }
    if (!empty($cfg['haus_strompreis_ein']) || (!empty($cfg['ladeempf_ein']) && $cfg['ladeempf_quelle'] === 'haus')) {
        $q['strompreis'] = 'HAUS.RUECK_STROMPREIS';
    }
    return $q;
}

/**
 * Die Zeilen der Haus-Quellen aus dem Zustand des Dienstes (zustand.json 'haus'):
 * Liste von array(Bezeichnung, 1|0, Text). Die Oberflaeche liest nur, was der Dienst
 * gesehen hat (dieselbe Regel wie beim Horcher).
 */
function au_haus_pruefzeilen($cfg = null)
{
    $z = au_zustand();
    $h = isset($z['haus']) && is_array($z['haus']) ? $z['haus'] : array();
    $aus = array();
    foreach (au_haus_quellen($cfg) as $q => $rueck) {
        $e = isset($h[$q]) && is_array($h[$q]) ? $h[$q] : array();
        $bez = sprintf(au_t('HAUS.ZEILE'), 'haus/' . $q);
        if (!empty($e['frisch'])) {
            $aus[] = array($bez, 1, sprintf(au_t('HAUS.FRISCH'), isset($e['alter']) ? (int) $e['alter'] : 0));
        } elseif (isset($e['seit']) && (int) $e['seit'] > 0) {
            $aus[] = array($bez, 0, sprintf(au_t('HAUS.SCHWEIGT'), max(0, (int) round((time() - (int) $e['seit']) / 60)))
                . ' ' . au_t($rueck));
        } else {
            $aus[] = array($bez, 0, au_t('HAUS.NIE') . ' ' . au_t($rueck));
        }
    }
    return $aus;
}

/**
 * Die Haus-Themen, die der Dienst bei dieser Konfiguration abonniert - dieselbe Liste wie
 * haus_themen() in bin/audi.py (der Reiter Test legt beide nebeneinander).
 */
function au_haus_themen($cfg)
{
    $t = array();
    if (!empty($cfg['abfahrt_ein']) && !empty($cfg['haus_abfahrt_ein'])) {
        $t[] = 'haus/abfahrt/in_min';
        $t[] = 'haus/abfahrt/zaehler';
    }
    $anw = false;
    if (!empty($cfg['abfahrt_ein']) && !empty($cfg['vorklima_anwesend_ein'])) {
        $t[] = 'haus/anwesenheit/jemand';
        $anw = true;
    }
    if (!empty($cfg['ankunft_ein'])) {
        foreach (au_ankunft_themen(isset($cfg['ankunft_quelle']) ? $cfg['ankunft_quelle'] : '') as $x) {
            $t[] = $x;
            $anw = true;
        }
    }
    if ($anw) {
        $t[] = 'haus/anwesenheit/zaehler';
    }
    if (!empty($cfg['haus_strompreis_ein']) || (!empty($cfg['ladeempf_ein']) && (string) $cfg['ladeempf_quelle'] === 'haus')) {
        foreach (array('vorschau', 'ab', 'raster', 'aktuell', 'zaehler') as $x) {
            $t[] = 'haus/strompreis/' . $x;
        }
    }
    return $t;
}

/** 'tag:<name>,person:<name>' -> Liste der Themen (haus/tag/<name>/da, haus/anwesenheit/<name>). Rein. */
function au_ankunft_themen($quelle)
{
    $t = array();
    if (!is_string($quelle) || au_wert_g41('ankunft_quelle', $quelle) === null) {
        return $t;
    }
    foreach (array_filter(explode(',', $quelle), 'strlen') as $q) {
        list($art, $name) = explode(':', $q, 2);
        $t[] = $art === 'tag' ? 'haus/tag/' . $name . '/da' : 'haus/anwesenheit/' . $name;
    }
    return $t;
}

/**
 * Die neuen Werte von 0.9.30 pruefen (Formular und Zurueckspielen): normierter Wert oder null.
 * vorklima_kalt_unter/heiss_ueber ganze Grad -30..45; ankunft_quelle bis vier Eintraege tag:/person:
 * (reserviert jemand und zaehler); ladeempf_quelle thema|haus; signal_praefix wie SignalBot.
 */
function au_wert_g41($k, $v)
{
    $v = trim((string) $v);
    if (in_array($k, array('kabel_soc', 'kabel_uhr', 'kabel_abfahrt_min', 'vorklima_min_soc'), true)) {     // 0.9.32
        $s = au_sammel_wert($k, $v);
        return $s === null ? null : (string) $s;
    }
    if ($k === 'vorklima_kalt_unter' || $k === 'vorklima_heiss_ueber') {
        return (preg_match('/^-?[0-9]{1,2}$/', $v) && (int) $v >= -30 && (int) $v <= 45) ? (string) (int) $v : null;
    }
    if ($k === 'ankunft_quelle') {
        if ($v === '') {
            return '';
        }
        if (!preg_match('/^(tag|person):[A-Za-z0-9_-]{1,40}(,(tag|person):[A-Za-z0-9_-]{1,40}){0,3}$/', $v)) {
            return null;
        }
        if (preg_match('/(^|,)person:(jemand|zaehler)(,|$)/', $v)) {
            return null;
        }
        return $v;
    }
    if ($k === 'ladeempf_quelle') {
        return in_array($v, array('thema', 'haus'), true) ? $v : null;
    }
    if ($k === 'signal_praefix') {
        return preg_match('/^[A-Za-z0-9_-]{1,40}$/', $v) ? $v : null;
    }
    return $v;
}


/* ---------------- Healthcheck (Nachtrag 29.10.2026, bin/healthcheck) ---------------- */

/**
 * Das Urteil des LoxBerry-Healthchecks (bin/healthcheck), rein - Form von Renault NG 2.1.20
 * (rn_gesund_urteil/rn_gesund_fakten). Regeln/03 :73 (Skala 3 Fehler, 4 Warnung, 5 in Ordnung, 6 Hinweis),
 * Regeln/04 :809 (nie auf einen einzelnen Abruf: geurteilt wird ueber das ALTER von Lebenszeichen und letztem
 * gelungenem Abruf), Regeln/04 :826 (ein Text nennt nur Zustaende, die in derselben Verzweigung gemessen sind).
 * $f: eingerichtet (bool), soll (bool, Sollmerker), pid (int; 0 = kein Prozess des Plugins - gefunden ueber
 * /proc/<pid>/cmdline, die PID-Datei allein genuegt nicht), start_alter_s (int, -1 = unbekannt),
 * leben_alter_s (int, -1 = keins), takt_s (int), erfolg_alter_s (int, -1 = noch nie), fehler_folge (int),
 * fahrzeuge (int), mqtt_ein (bool), gateway (array gefunden/autostart/udpport), broker_fehler (bool|null,
 * null = keine Aussage), v2 (array(lage, Text)), bruecke_soll (bool), bruecke_laeuft (bool|null).
 * Rueckgabe array(status, Text); der Text traegt keine Zugangsdaten, VIN, Kennzeichen oder Fahrzeugnamen.
 */
function au_gesund_urteil(array $f)
{
    $f += array('eingerichtet' => false, 'soll' => false, 'pid' => 0, 'start_alter_s' => -1, 'leben_alter_s' => -1,
                'takt_s' => 300, 'erfolg_alter_s' => -1, 'fehler_folge' => 0, 'fahrzeuge' => 0, 'mqtt_ein' => false,
                'gateway' => array(), 'broker_fehler' => null, 'v2' => array(null, ''), 'bruecke_soll' => false,
                'bruecke_laeuft' => null);
    if (!$f['eingerichtet']) {
        return array(6, 'nicht eingerichtet - Zugangsdaten im Reiter Einstellungen eintragen');
    }
    $pid = (int) $f['pid'];
    if (!$f['soll'] && $pid <= 0) {
        return array(6, 'Abrufdienst angehalten (so eingestellt, kein Fehler) - Start im Reiter Einstellungen');
    }
    $befunde = array();
    $status = 5;
    $setze = function ($s, $t) use (&$status, &$befunde) {
        if ($s < $status) {
            $status = $s;
        }
        $befunde[] = $t;
    };
    $takt = max(60, (int) $f['takt_s']);
    if ($pid <= 0) {
        $setze(3, 'Abrufdienst soll laufen, es laeuft aber keiner');
    } else {
        $a = (int) $f['leben_alter_s'];
        $g = max(600, 3 * $takt);
        if ($a < 0) {
            $setze(4, 'Abrufdienst laeuft, hat aber noch kein Lebenszeichen geschrieben');
        } elseif ($a > $g) {
            $setze(3, sprintf('Abrufdienst laeuft, meldet sich aber seit %d min nicht mehr (erwartet hoechstens %d min)',
                (int) round($a / 60), (int) round($g / 60)));
        }
    }
    /* Nach einem Neustart (auch cron.reboot) zaehlt die Zeit SEIT DEM START: ein Erfolg von vor dem Herunterfahren
     * ist kein Befund ueber den laufenden Dienst. */
    $e = (int) $f['erfolg_alter_s'];
    $s0 = (int) $f['start_alter_s'];
    $seit_start = $pid > 0 && $s0 >= 0 && ($e < 0 || $e > $s0);
    $e_eff = $seit_start ? $s0 : $e;
    $folge = (int) $f['fehler_folge'] > 0 ? sprintf(' (%d Fehlversuche in Folge)', (int) $f['fehler_folge']) : '';
    $satz = $seit_start ? 'seit dem Start vor %d min kein gelungener Abruf' : 'letzter gelungener Abruf vor %d min';
    if ($e_eff < 0) {
        $setze(4, 'noch kein gelungener Abruf' . $folge);
    } elseif ($e_eff > max(3600, 6 * $takt)) {
        $setze(3, sprintf($satz, (int) round($e_eff / 60)) . $folge);
    } elseif ($e_eff > max(900, 3 * $takt)) {
        $setze(4, sprintf($satz, (int) round($e_eff / 60)) . $folge);
    }
    if ($f['mqtt_ein']) {
        $gw = is_array($f['gateway']) ? $f['gateway'] : array();
        if (empty($gw['gefunden']) || empty($gw['udpport'])) {
            $setze(4, 'MQTT eingeschaltet, aber kein MQTT-Gateway mit UDP-Eingang eingerichtet - es wird nichts gesendet');
        } elseif (empty($gw['autostart'])) {
            $setze(4, 'MQTT eingeschaltet, aber das MQTT-Gateway steht nicht auf Autostart');
        }
    }
    if ($f['broker_fehler'] === true) {
        $setze(4, 'keine Verbindung zum MQTT-Broker - Haus-Themen (Abfahrt, Anwesenheit, Strompreis) kommen nicht an');
    }
    if (is_array($f['v2']) && isset($f['v2'][0]) && $f['v2'][0] === 0) {
        $setze(4, 'Gateway V2: ' . (isset($f['v2'][1]) ? (string) $f['v2'][1] : ''));
    }
    if ($f['bruecke_soll'] && $f['bruecke_laeuft'] === false) {
        $setze(4, 'Befehle ueber MQTT eingeschaltet, aber die Befehlsbruecke steht');
    }
    if (!$befunde) {
        if ($seit_start) {
            return array(5, sprintf('in Ordnung - Abrufdienst laeuft seit %d min, %d Fahrzeug(e), seit dem Start noch '
                . 'kein gelungener Abruf', (int) round($s0 / 60), (int) $f['fahrzeuge']));
        }
        return array(5, sprintf('in Ordnung - Abrufdienst laeuft, %d Fahrzeug(e), letzter gelungener Abruf vor %d min',
            (int) $f['fahrzeuge'], (int) round($e / 60)));
    }
    return array($status, implode('; ', $befunde));
}

/** Die Fakten des Healthchecks - nur lesend: kein Netz, kein Broker, keine Anmeldung, keine Datei, kein Protokoll. */
function au_gesund_fakten(array $cfg)
{
    clearstatcache();
    $p = au_paths();
    $jetzt = time();
    $f = array();
    $f['eingerichtet'] = (bool) au_zugang_da();
    $f['soll'] = (int) au_dienst_soll() === 1;
    $f['pid'] = (int) au_dienst_pid();
    /* Der Start des laufenden Dienstes: die PID-Datei, wenn sie genau diesen Prozess nennt. */
    $start = 0;
    $pf = $p['datadir'] . '/dienst.pid';
    if ($f['pid'] > 0 && is_file($pf) && (int) trim((string) @file_get_contents($pf)) === $f['pid']) {
        $start = (int) @filemtime($pf);
    }
    $f['start_alter_s'] = $start > 0 ? max(0, $jetzt - $start) : -1;
    $zu = json_decode((string) @file_get_contents($p['datadir'] . '/zustand.json'), true);
    $zu = is_array($zu) ? $zu : array();
    $zts = isset($zu['ts']) && is_numeric($zu['ts']) ? (int) $zu['ts'] : 0;
    $zu_frisch = $zts > 0 && $zts >= $start;          // vom laufenden Dienst geschrieben, nicht von einem frueheren
    $lox = json_decode((string) @file_get_contents($p['datadir'] . '/loxone.json'), true);
    $lox = is_array($lox) ? $lox : array();
    $f['takt_s'] = isset($cfg['intervall']) && is_numeric($cfg['intervall']) ? (int) $cfg['intervall'] : 300;
    $lz = preg_split('/\s+/', trim((string) @file_get_contents($p['datadir'] . '/lebenszeichen')));
    $lts = (isset($lz[0]) && preg_match('/^[0-9]+$/', $lz[0])) ? (int) $lz[0] : 0;
    if (isset($lz[1]) && preg_match('/^[0-9]+$/', $lz[1])) {
        $f['takt_s'] = (int) $lz[1];
    }
    $lts = max($lts, $start);
    $f['leben_alter_s'] = $lts > 0 ? max(0, $jetzt - $lts) : -1;
    $ts = isset($lox['ts']) && is_numeric($lox['ts']) ? (int) $lox['ts'] : 0;
    $f['erfolg_alter_s'] = $ts > 0 ? max(0, $jetzt - $ts) : -1;
    $f['fahrzeuge'] = isset($lox['fahrzeuge']) && is_array($lox['fahrzeuge']) ? count($lox['fahrzeuge']) : 0;
    $f['fehler_folge'] = ($zu_frisch && isset($zu['fehler_folge']) && is_numeric($zu['fehler_folge']))
        ? (int) $zu['fehler_folge'] : 0;
    $f['mqtt_ein'] = !empty($cfg['mqtt_ein']);
    $f['gateway'] = au_mqtt_zustand();
    $f['broker_fehler'] = null;
    if ($f['pid'] > 0 && $zu_frisch) {
        $fe = isset($zu['horcher_fehler']) ? trim((string) $zu['horcher_fehler']) : '';
        $th = (isset($zu['horcher']) && is_array($zu['horcher'])) ? $zu['horcher'] : array();
        $f['broker_fehler'] = $fe !== '' || ($th && empty($zu['horcher_verbunden']));
    }
    $f['v2'] = au_v2_haken_lage($cfg);
    $f['bruecke_soll'] = !empty($cfg['befehle_mqtt_ein']);
    $f['bruecke_laeuft'] = null;
    if ($f['bruecke_soll'] && au_bruecke_laden()) {
        $l = au_bruecke_lage();
        $f['bruecke_laeuft'] = $l[0] === true;
    }
    return $f;
}
