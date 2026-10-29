<?php
/**
 * Plugin Name: 1AB Affiliate Help
 * Description: Kleiner Ersatz für AAWP. Zeigt Amazon-Produktbilder, Namen und Textlinks über die Creators API. Bilder kommen vom eigenen Server. Erkennt ausgefallene Produkte und setzt den nächsten Ersatz aus einer Liste ein.
 * Version: 0.93.4
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) { exit; }

/*
 * Zugangsdaten: am einfachsten unter Seitenleiste > 1AB Affiliate Help eintragen.
 * Sicherer (nicht in der Datenbank) ist die wp-config.php, sie hat dann Vorrang:
 *
 * define('SAB_CLIENT_ID',     'Credential ID');
 * define('SAB_CLIENT_SECRET', 'Credential Secret');
 * define('SAB_TAGS', ['es' => 'ayuba09-21']);   // Partner-ID je Land
 * define('SAB_DEFAULT_LAND', 'es');
 *
 * Shortcodes:
 *   [amz_produkt slot="kupferband"]                     Bild + Name + Textlink aus einer Ersatzliste (empfohlen)
 *   [amz_bild asin="B0XXXXXXXX" land="es" breite="300"]  nur Bild, verlinkt
 *   [amz_link asin="B0XXXXXXXX" land="es"]Text[/amz_link] nur Textlink
 *   land="auto" (bei allen drei): Land aus der Browsersprache (Accept-Language), ohne Cookie und ohne IP.
 *               Achtung: Mit Seiten-Cache (WP Rocket u. ä.) bekommen alle Besucher das Land des ersten Abrufs.
 *
 * Rückfall: Steht zwischen [amz_produkt]...[/amz_produkt] bzw. [amz_bild]...[/amz_bild] der bisherige HTML-Code,
 * erscheint genau dieser Code, wenn Amazon nicht antwortet oder kein Produkt der Liste brauchbar ist.
 *
 * Einstellungen: Seitenleiste > 1AB Affiliate Help (Produkte mit Kurznamen, Ersatzlisten, Ausfälle).
 */

if (!class_exists('SAB', false)) {   // Doppel-Ladeschutz: alte und neue Fassung dürfen nebeneinander aktiv sein, die zuerst geladene gewinnt

final class SAB {

    // Land => [Marketplace, Token-Server (Version 3.1 NA, 3.2 EU, 3.3 FE)]
    const LAENDER = [
        'de'    => ['www.amazon.de',    'https://api.amazon.co.uk/auth/o2/token'],
        'fr'    => ['www.amazon.fr',    'https://api.amazon.co.uk/auth/o2/token'],
        'es'    => ['www.amazon.es',    'https://api.amazon.co.uk/auth/o2/token'],
        'it'    => ['www.amazon.it',    'https://api.amazon.co.uk/auth/o2/token'],
        'co.uk' => ['www.amazon.co.uk', 'https://api.amazon.co.uk/auth/o2/token'],
        'com'   => ['www.amazon.com',   'https://api.amazon.com/auth/o2/token'],
    ];
    const PARTNER_SHOPS = ['de' => 'Amazon.de', 'co.uk' => 'Amazon.co.uk', 'com' => 'Amazon.com (USA)', 'ca' => 'Amazon.ca (Kanada)', 'com.au' => 'Amazon.com.au (Australien)', 'fr' => 'Amazon.fr', 'es' => 'Amazon.es', 'it' => 'Amazon.it'];
    const API      = 'https://creatorsapi.amazon/catalog/v1/getItems';
    const TTL      = DAY_IN_SECONDS;      // Amazon: Bilder und Links höchstens 1 Tag zwischenspeichern
    const BILD_MAX = DAY_IN_SECONDS + HOUR_IN_SECONDS; // Amazon-Regel: Bilder höchstens 1 Tag; ältere werden nie gezeigt (1 h Puffer für den Cron)

    const VERSION = '0.93.4';
    const NAME = '1AB Affiliate Help';   // Anzeigename (Menü, Überschrift, Mails); Ordner, Slug und Update-Weg bleiben bei „schlanke-amazon-box“, damit die Auto-Updates weiterlaufen
    const FAV_TEXT = ['de' => 'Unsere Empfehlung', 'es' => 'Nuestra elección', 'fr' => 'Notre choix', 'it' => 'La nostra scelta', 'co.uk' => 'Our pick', 'com' => 'Our pick'];
    const PREIS_STAND = ['de' => 'Stand', 'es' => 'a fecha', 'fr' => 'au', 'it' => 'al', 'co.uk' => 'as of', 'com' => 'as of'];
    /** Größte Bildbreite für Bilder aus [aawp image_width=…]: 500 px wirkten am Desktop zu groß. */
    const AAWP_BILD_MAX = 360;
    const BUTTON_TEXT = ['de' => 'Angebot bei Amazon ansehen', 'es' => 'Ver oferta en Amazon', 'fr' => "Voir l'offre sur Amazon", 'it' => "Vedi l'offerta su Amazon", 'co.uk' => 'See offer on Amazon', 'com' => 'See offer on Amazon'];
    // Selbst-Aktualisierung: WordPress fragt zweimal täglich (nur Verwaltung/Cron, nie beim Seitenaufruf) diese Datei ab
    const UPDATE_REPO = 'https://github.com/Aleeeb/schlanke-amazon-box-/';
    const UPDATE_JSON = 'https://raw.githubusercontent.com/Aleeeb/schlanke-amazon-box-/main/update.json';
    const LINK_WARNUNG = 15; // ab so vielen Partnerlinks auf einer Seite warnt das Plugin (nur Verwaltung)

    // Pflichthinweis je Land (Amazon-Partnerprogramm)
    const HINWEIS = [
        'de'    => 'Als Amazon-Partner verdiene ich an qualifizierten Verkäufen.',
        'es'    => 'Como afiliado de Amazon, obtengo ingresos por las compras adscritas que cumplen los requisitos aplicables.',
        'fr'    => 'En tant que partenaire Amazon, je réalise un bénéfice sur les achats remplissant les conditions requises.',
        'it'    => 'In qualità di Affiliato Amazon ricevo un guadagno dagli acquisti idonei.',
        'co.uk' => 'As an Amazon Associate I earn from qualifying purchases.',
        'com'   => 'As an Amazon Associate I earn from qualifying purchases.',
    ];

    public static function init(): void {
        add_shortcode('amz_produkt', [__CLASS__, 'sc_produkt']);
        add_shortcode('amz_bild',    [__CLASS__, 'sc_bild']);
        add_shortcode('amz_link',    [__CLASS__, 'sc_link']);
        add_shortcode('amz_titel',   [__CLASS__, 'sc_titel']);
        add_shortcode('amz_ebay',    [__CLASS__, 'sc_ebay']);
        add_shortcode('amz_karten',  [__CLASS__, 'sc_karten']);
        add_shortcode('amz_rezension', [__CLASS__, 'sc_rezension']);
        add_shortcode('amz_karte',   [__CLASS__, 'sc_karte']);
        add_shortcode('amz_hinweis', [__CLASS__, 'sc_hinweis']);
        add_shortcode('amz_widget',  [__CLASS__, 'sc_widget']);
        add_shortcode('amz_startseite', [__CLASS__, 'sc_startseite']);
        add_shortcode('aawp',        [__CLASS__, 'sc_aawp']);
        add_shortcode('amazon',      [__CLASS__, 'sc_aawp']);   // Alias wie bei AAWP (AAWP gewinnt, solange es aktiv ist)
        add_action('sab_pruefen',    [__CLASS__, 'pruefen']);
        add_action('sab_auffrischen', [__CLASS__, 'auffrischen']);
        add_action('admin_notices', [__CLASS__, 'alarm_hinweis']);
        add_filter('auto_plugin_update_send_email', [__CLASS__, 'update_mail_filter'], 10, 2);
        add_filter('auto_theme_update_send_email', [__CLASS__, 'update_mail_filter'], 10, 2);   // WordPress-Mail „Plugins automatisch aktualisiert“: standardmäßig aus
        add_action('wp_ajax_sab_pruef_start',   [__CLASS__, 'ajax_pruef_start']);
        add_action('wp_ajax_sab_scan_start',    [__CLASS__, 'ajax_scan_start']);
        add_action('wp_ajax_sab_scan_id',       [__CLASS__, 'ajax_scan_id']);
        add_action('wp_ajax_sab_scan_schritt',  [__CLASS__, 'ajax_scan_schritt']);
        add_action('admin_post_sab_scan_csv',   [__CLASS__, 'scan_csv']);
        add_action('wp_ajax_sab_pruef_schritt', [__CLASS__, 'ajax_pruef_schritt']);
        add_action('sab_holen',      [__CLASS__, 'holen'], 10, 2);
        add_action('sab_uebersicht_holen', [__CLASS__, 'uebersicht_holen'], 10, 1);
        add_action('sab_bild_holen', [__CLASS__, 'bild_holen'], 10, 3);
        add_action('admin_menu',     [__CLASS__, 'menue']);
        add_action('wp_dashboard_setup', [__CLASS__, 'dashboard_widget']);
        add_action('admin_notices',  [__CLASS__, 'bild_hinweis']);
        add_action('init',           [__CLASS__, 'block_registrieren']);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [__CLASS__, 'aktionslinks']);
        add_action('rest_api_init',  [__CLASS__, 'rest']);
        add_action('wp_footer',      [__CLASS__, 'messskript']);
        add_action('wp_footer',      [__CLASS__, 'zeige_skript'], 99);
        add_action('wp_footer',      [__CLASS__, 'stil_skript'], 9);
        add_action('admin_footer-post.php', [__CLASS__, 'zeige_editor_skript']);
        add_filter('pre_do_shortcode_tag', [__CLASS__, 'ord_pre'], 10, 4);
        add_filter('do_shortcode_tag',     [__CLASS__, 'ord_post'], 10, 4);
        add_filter('the_content',          [__CLASS__, 'ord_reset'], 9);
        add_filter('the_content',          [__CLASS__, 'attr_reparieren'], 8);
        add_filter('the_content',          [__CLASS__, 'ab_inhalt'], 7);
        add_action('wp_footer',            [__CLASS__, 'stellen_skript'], 97);
        add_action('wp_footer',            [__CLASS__, 'widget_zeige_skript'], 100);
        add_action('wp_footer',            [__CLASS__, 'geo_skript'], 98);
        add_action('wp_ajax_sab_stelle_kand',    [__CLASS__, 'ajax_stelle_kand']);
        add_action('wp_ajax_sab_ab_schalten',    [__CLASS__, 'ajax_ab_schalten']);
        add_action('wp_ajax_sab_ab_einzel',      [__CLASS__, 'ajax_ab_einzel']);
        add_action('wp_ajax_sab_ab_kand',        [__CLASS__, 'ajax_ab_kand']);
        add_action('wp_ajax_sab_ab_blockkand',   [__CLASS__, 'ajax_ab_blockkand']);
        add_action('wp_ajax_sab_ab_block',       [__CLASS__, 'ajax_ab_block']);
        add_action('wp_ajax_sab_stelle_tausch',  [__CLASS__, 'ajax_stelle_tausch']);
        add_action('wp_ajax_sab_stelle_karte',   [__CLASS__, 'ajax_stelle_karte']);
        add_action('wp_ajax_sab_stelle_zurueck', [__CLASS__, 'ajax_stelle_zurueck']);
        add_action('wp_ajax_sab_kurz_ok',        [__CLASS__, 'ajax_kurz_ok']);
        add_action('wp_ajax_sab_stelle_ok',      [__CLASS__, 'ajax_stelle_ok']);
        add_action('wp_footer',      [__CLASS__, 'fuss'], 5);
        add_action('wp_footer',      [__CLASS__, 'widget_css_fuss'], 6);
        add_filter('pre_set_site_transient_update_plugins', [__CLASS__, 'update_pruefen']);
        add_action('sab_update_check', [__CLASS__, 'update_cron']);
        add_action('admin_init',     [__CLASS__, 'update_admin']);
        add_action('admin_init',     [__CLASS__, 'tabelle']);
        add_action('admin_init',     [__CLASS__, 'einmal_importe']);
        if (!wp_next_scheduled('sab_pruefen')) { wp_schedule_event(time() + 300, 'daily', 'sab_pruefen'); }
        if (!wp_next_scheduled('sab_auffrischen')) { wp_schedule_event(time() + 600, 'hourly', 'sab_auffrischen'); }   // Produkte und Bilder rechtzeitig vor Ablauf erneuern, damit nie ein Bild fehlt
    }

    // ---------- Zugang ----------

    // Zugangsdaten: wp-config.php hat Vorrang, sonst die Eingabemaske unter Seitenleiste > 1AB Affiliate Help

    private static function zugang(): array {
        return [
            defined('SAB_CLIENT_ID')     ? SAB_CLIENT_ID     : (string) get_option('sab_client_id', ''),
            defined('SAB_CLIENT_SECRET') ? SAB_CLIENT_SECRET : (string) get_option('sab_client_secret', ''),
        ];
    }

    /** Ländercode tolerant lesen: „couk“, „co-uk“, „UK“, „GB“ => co.uk; „comau“, „AU“ => com.au; „us“ => com; „.de“ => de. */
    public static function land_schluessel(string $k): string {
        $k = strtolower(trim($k, " \t.\"'")); $k = preg_replace('/^(?:https?:\/\/)?(?:www\.)?(?:amazon\.)?/', '', $k); $k = trim($k, "./ "); $n = preg_replace('/[^a-z]/', '', $k);   // auch „.co.uk“, „amazon.co.uk“, „www.amazon.de“
        $m = ['couk' => 'co.uk', 'uk' => 'co.uk', 'gb' => 'co.uk', 'comau' => 'com.au', 'au' => 'com.au', 'us' => 'com', 'usa' => 'com'];
        return $m[$n] ?? $k;
    }

    /** Zeilen "land=partner-id" aus der Eingabemaske => [land => id] */
    private static function tags(): array {
        if (defined('SAB_TAGS')) { return SAB_TAGS; }
        $out = [];
        foreach (preg_split('/\R/', (string) get_option('sab_tags', '')) as $z) {
            $z = trim($z, " \t\"'");
            if ($z === '') { continue; }
            $t = array_map(fn($v) => trim($v, " \t\"'"), preg_split('/\s*[=:]\s*/', $z, 2));
            if (count($t) === 2 && $t[0] !== '' && $t[1] !== '') { $out[self::land_schluessel($t[0])] = $t[1]; }
            elseif (count($t) === 1 && preg_match('/^[A-Za-z0-9][A-Za-z0-9\-]{2,39}$/', $t[0])) { $out[self::standardland_roh()] = $out[self::standardland_roh()] ?? $t[0]; }   // nur die ID ohne „de=“: gilt für das Standardland
        }
        return $out;
    }

    private static function standardland_roh(): string {
        return self::land_schluessel(defined('SAB_DEFAULT_LAND') ? SAB_DEFAULT_LAND : (string) get_option('sab_default_land', 'de'));   // „couk“ (früher ohne Punkt gespeichert) wird zu co.uk
    }

    private static function standardland(): string {
        $l = self::standardland_roh();
        $t = self::tags();
        if (!isset($t[$l]) && isset(self::LAENDER[$l])) {   // Standardland ohne Partner-ID (z. B. internationale Seite, nur co.uk eingetragen): erstes Land mit Partner-ID nehmen
            foreach (array_keys($t) as $k) { if (isset(self::LAENDER[$k])) { return $k; } }
        }
        return $l;
    }

    /** Land aus Accept-Language (erste passende Sprache). Kein Cookie, keine IP. */
    private static function land_erkennen(): string {
        $h = strtolower((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
        foreach (explode(',', $h) as $teil) {
            $c = trim(explode(';', $teil)[0]);
            if ($c === '') { continue; }
            if (in_array($c, ['en-gb', 'en-ie'], true)) { return 'co.uk'; }
            if (in_array($c, ['en-us', 'en'], true))    { return 'com'; }
            if (strpos($c, 'es') === 0)                 { return 'es'; }
            if (strpos($c, 'de') === 0)                 { return 'de'; }
            if (strpos($c, 'fr') === 0)                 { return 'fr'; }
            if (strpos($c, 'it') === 0)                 { return 'it'; }
        }
        return self::standardland();
    }

    private static function land(string $land): string {
        $land = strtolower(trim($land));
        if ($land === 'auto') { $land = self::land_erkennen(); }
        $land = $land ?: self::standardland();
        return isset(self::LAENDER[$land]) ? $land : 'de';
    }

    private static $token_fehler = '';

    /** Genaue Meldung, warum für ein Land nichts abgerufen werden kann (statt „Zugangsdaten oder Partner-ID fehlen“ für alles). */
    private static function zugang_fehler(string $land, bool $token_ok): string {
        [$id, $secret] = self::zugang();
        if (!$id || !$secret) { return 'Zugangsdaten fehlen: ' . (!$id ? 'Credential ID leer' : '') . (!$id && !$secret ? ', ' : '') . (!$secret ? 'Credential Secret leer' : '') . ' (Einstellungen).'; }
        if (!self::tag($land)) { $b = array_keys(self::tags()); return 'Für das Land „' . $land . '“ ist keine Partner-ID eingetragen' . ($b ? ' (eingetragen sind: ' . implode(', ', $b) . ')' : '') . '. Bitte in den Einstellungen eine Zeile <' . $land . '=deine-id-21> anlegen.'; }
        if (!$token_ok) { return 'Amazon hat die Zugangsdaten abgelehnt' . (self::$token_fehler !== '' ? ' (' . self::$token_fehler . ')' : '') . '. Bitte Credential ID/Secret prüfen (Creators-API, keine PA-API-Schlüssel).'; }
        return '';
    }

    private static function tag(string $land): string {
        return self::tags()[$land] ?? (self::slughelp_ids()[$land] ?? '');
    }

    private static function token(string $land): string {
        [$id, $secret] = self::zugang();
        if (!$id || !$secret) { return ''; }
        $server = self::LAENDER[$land][1];
        $key    = 'sab_token_' . md5($server);
        $t      = get_transient($key);
        if ($t) { return $t; }

        $r = wp_remote_post($server, [
            'timeout' => 10,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode([
                'grant_type'    => 'client_credentials',
                'client_id'     => $id,
                'client_secret' => $secret,
                'scope'         => 'creatorsapi::default',
            ]),
        ]);
        if (is_wp_error($r)) { self::$token_fehler = 'Verbindung: ' . $r->get_error_message(); return ''; }
        if (wp_remote_retrieve_response_code($r) !== 200) { self::$token_fehler = 'HTTP ' . wp_remote_retrieve_response_code($r) . ' ' . mb_substr(preg_replace('/\s+/', ' ', wp_strip_all_tags((string) wp_remote_retrieve_body($r))), 0, 160); return ''; }
        $j = json_decode(wp_remote_retrieve_body($r), true);
        if (empty($j['access_token'])) { self::$token_fehler = 'keine Antwort mit Zugriffsschlüssel'; return ''; }
        set_transient($key, $j['access_token'], max(60, (int) ($j['expires_in'] ?? 3600) - 120));
        return $j['access_token'];
    }

    // ---------- Einstellungen ----------

    private static function asin(string $a): string {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $a));
    }

    /** Zeilen "ASIN | Kurzname" => [ASIN => Kurzname] */
    private static function produkte(): array {
        $out = [];
        foreach (preg_split('/\R/', (string) get_option('sab_produkte', '')) as $z) {
            $t = array_map('trim', explode('|', $z));
            if ($t[0] !== '' && self::asin($t[0])) { $out[self::asin($t[0])] = $t[1] ?? ''; }
        }
        return $out;
    }

    /** Zeilen "slot: ASIN1, ASIN2" => [slot => [ASIN1, ASIN2]]. Erste ASIN = bevorzugt, Rest = Ersatz in dieser Reihenfolge. */
    /** Produktgruppen: Zeilen „Name: ASIN1, ASIN2, …“ (ähnliche Produkte, z. B. alle Kupferbänder). */
    private static function gruppen(): array {
        $out = [];
        foreach (preg_split('/\R/', (string) get_option('sab_gruppen', '')) as $z) {
            if (strpos($z, ':') === false) { continue; }
            [$name, $liste] = array_map('trim', explode(':', $z, 2));
            $asins = array_values(array_unique(array_filter(array_map([__CLASS__, 'asin'], explode(',', $liste)))));
            if ($name && $asins) { $out[$name] = $asins; }
        }
        return $out;
    }

    /**
     * Ersatz-Datenbank: eine Zeile pro Ersatzprodukt „Produktart | Land | ASIN | Status (neu/geprüft) | Stand | Quelle“.
     * Produktart = Name einer Produktgruppe oder eine ASIN (Ersatz nur für dieses Produkt). Land wie bei den Partner-IDs (co.uk, com, de …).
     * Handdaten (Sterne, Provision, Lieferzeit, Lieferkosten) stehen wie immer bei den Handdaten. @return array<int,array{art:string,land:string,asin:string,status:string,stand:string,quelle:string}>
     */
    public static function ersatz_liste(): array {
        $out = [];
        foreach (preg_split('/\R/', (string) get_option('sab_ersatz', '')) as $z) {
            $t = array_map('trim', explode('|', $z));
            $a = self::asin($t[2] ?? ''); if (strlen($a) !== 10 || ($t[0] ?? '') === '') { continue; }
            $st = mb_strtolower($t[3] ?? ''); $out[] = ['art' => $t[0], 'land' => self::land_schluessel($t[1] ?? ''), 'asin' => $a, 'status' => in_array($st, ['geprüft', 'geprueft', 'ok', 'ja'], true) ? 'geprüft' : 'neu', 'stand' => $t[4] ?? '', 'quelle' => $t[5] ?? ''];
        }
        return $out;
    }

    /** Setzt den Status (neu/geprüft) einer Zeile der Ersatz-Datenbank; übrige Zeilen bleiben unverändert. @return array{0:string,1:bool} [neuer Text, geändert] */
    public static function ersatz_status_setzen(string $text, string $art, string $land, string $asin, string $status): array {
        $zeilen = preg_split('/\R/', $text); $geaendert = false;
        foreach ($zeilen as $i => $z) {
            $t = array_map('trim', explode('|', $z));
            if (($t[0] ?? '') === $art && self::land_schluessel($t[1] ?? '') === $land && self::asin($t[2] ?? '') === $asin) {
                while (count($t) < 4) { $t[] = ''; }
                $t[3] = $status; if (($t[4] ?? '') === '' ) { $t[4] = gmdate('Y-m-d'); } $zeilen[$i] = implode(' | ', $t); $geaendert = true;
            }
        }
        return [implode("\n", $zeilen), $geaendert];
    }

    // ---------- Seite scannen: alle Amazon-Links der Artikel, Partner-IDs je Land, Ausreißer (nur auf Klick im Backend, in Etappen) ----------

    const SCAN_SCHRITT = 25;   // Artikel je Etappe

    /** Erwartete Partner-IDs je Land (Einstellungen und, auf Slughelp, die bekannten acht). @return array<string,string[]> */
    public static function scan_erwartet(): array {
        $o = [];
        foreach (array_merge(self::slughelp_ids(), self::tags()) as $l => $t) { if ($t !== '') { $o[(string) $l][$t] = $t; } }
        foreach (preg_split('/\R/', (string) get_option('sab_tags_extra', '')) as $z) { $z = trim(preg_split('/[\s|=,]+/', trim($z))[0] ?? ''); if ($z !== '') { $o['*'][$z] = $z; } }   // eigene Zusatz-IDs: gelten in jedem Shop als in Ordnung
        return array_map('array_values', $o);
    }

    private static function scan_neu(): array {
        return ['artikel' => 0, 'links' => 0, 'laender' => [], 'ids' => [], 'kurz' => ['n' => 0, 'posts' => []], 'probleme' => [], 'asin' => []];
    }

    /** Wertet einen Artikeltext aus und ergänzt $e. Ausreißer: ID eines anderen Landes, unbekannte ID, Link ohne ID, ungewöhnliche ASIN-Länge. Kurzlinks (amzn.to …) zählen als eigene Gruppe ohne Alarm. */
    public static function scan_text(string $c, int $pid, array $erwartet, array &$e): void {
        $e['artikel']++; $std = self::land('');
        $land_von_tag = []; foreach ($erwartet as $l => $ts) { if ($l === '*') { continue; } foreach ($ts as $t) { $land_von_tag[$t][] = $l; } }
        $hist = [];   // bisher gesehene Link-Adressen dieses Artikels: daraus ergibt sich, die wievielte Fundstelle das ist (Sprung im Editor)
        $buche = function (string $land, string $tag, string $asin, string $art, string $url = '') use (&$e, $pid, $erwartet, $land_von_tag, &$hist) {
            $e['links']++; $e['laender'][$land] = ($e['laender'][$land] ?? 0) + 1;
            $klasse = 'ok'; $grund = '';
            if ($tag === '') { $klasse = 'ohne'; $grund = 'Link ohne Partner-ID'; }
            elseif (in_array($tag, $erwartet[$land] ?? [], true) || in_array($tag, $erwartet['*'] ?? [], true)) { $klasse = 'ok'; }
            elseif (isset($land_von_tag[$tag])) { $klasse = 'fremdland'; $grund = 'ID ' . $tag . ' gehört zu ' . implode('/', $land_von_tag[$tag]) . ', der Link steht auf amazon.' . $land; }
            else { $klasse = 'unbekannt'; $grund = 'unbekannte ID ' . $tag . ' (nicht in den Einstellungen)'; }
            $k = $tag === '' ? '(ohne)' : $tag; $e['ids'][$k] = $e['ids'][$k] ?? ['n' => 0, 'land' => $land, 'klasse' => $klasse]; $e['ids'][$k]['n']++;
            $such = $tag !== '' ? $tag : $asin; $nr = 1; if ($such !== '') { foreach ($hist as $hu) { if (strpos($hu, $such) !== false) { $nr++; } } }
            if ($klasse !== 'ok' && count($e['probleme']) < 400) { $e['probleme'][] = ['typ' => $klasse, 'grund' => $grund, 'asin' => $asin, 'tag' => $tag, 'land' => $land, 'pid' => $pid, 'such' => $such, 'nr' => $nr]; }
            if ($url !== '') { $hist[] = $url; }
            if ($asin !== '' && (isset($e['asin'][$asin]) || count($e['asin']) < 1500)) { $a = &$e['asin'][$asin]; $a['n'] = ($a['n'] ?? 0) + 1; $a['laender'][$land] = 1; if (count($a['posts'] ?? []) < 12) { $a['posts'][$pid] = 1; } unset($a); }
        };
        if (preg_match_all('#<a\b[^>]*?href=(["\'])(.*?)\1#is', $c, $m)) {
            foreach ($m[2] as $u) {
                $u = html_entity_decode($u);
                if (preg_match('#^https?://(?:amzn\.to|amzn\.eu|a\.co|link\.amazon)/#i', $u)) { $e['kurz']['n']++; if (count($e['kurz']['posts']) < 100) { $e['kurz']['posts'][$pid] = ($e['kurz']['posts'][$pid] ?? 0) + 1; } continue; }
                if (!preg_match('#^https?://(?:[a-z0-9-]+\.)?amazon\.(co\.uk|com\.au|com|ca|de|fr|it|es)(/[^?\\#]*)?(\?[^\\#]*)?#i', $u, $um)) { continue; }
                $land = strtolower($um[1]); $pfad = $um[2] ?? ''; $query = $um[3] ?? '';
                $tag = preg_match('#[?&]tag=([^&\\#]*)#', $query, $tm) ? rawurldecode($tm[1]) : '';
                $asin = ''; $laenge = 0;
                if (preg_match('#/(?:dp|gp/product|product-reviews|product)/([A-Za-z0-9]+)#', $pfad, $am)) { $laenge = strlen($am[1]); $asin = strtoupper($am[1]); }
                if ($tag === '' && (self::seite_art($u) === 'liste' || preg_match('#/gp/help|/help/|/gp/css|/privacy|nodeId=|/gp/customer-reviews/guidelines|/conditionsofuse|/gp/aw/help#i', $pfad . '?' . $query))) { $e['ausnahmen'] = ($e['ausnahmen'] ?? 0) + 1; continue; }   // Hilfe-, Datenschutz- und Ausnahmeseiten brauchen keine Partner-ID
                $buche($land, $tag, $asin, 'link', $u);
                if ($asin !== '' && $laenge !== 10 && $laenge >= 11 && $laenge <= 16 && count($e['probleme']) < 400) { $e['probleme'][] = ['typ' => 'asin', 'grund' => 'ungewöhnliche ASIN mit ' . $laenge . ' Zeichen', 'asin' => $asin, 'tag' => $tag, 'land' => $land, 'pid' => $pid, 'such' => $asin, 'nr' => 1]; }
            }
        }
        if (preg_match_all('#\[(amz_(?:bild|link|produkt|karte|rezension)|aawp|amazon)\b([^\]]*)\]#i', $c, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $at = html_entity_decode($x[2]);
                $asin = preg_match('#\b(?:asin|fields|box|link)=["\']?([A-Za-z0-9]{10})#i', $at, $am) ? strtoupper($am[1]) : '';
                $land = preg_match('#\bland=["\']?([a-z.]+)#i', $at, $lm) ? self::land_schluessel($lm[1]) : $std; if (!isset($erwartet[$land]) && !isset(self::LAENDER[$land])) { $land = $std; }
                $tag = preg_match('#\b(?:tag|tracking_id)=["\']([^"\']+)#i', $at, $tm) ? $tm[1] : (($erwartet[$land][0] ?? '') ?: self::tag($land));
                $buche($land, $tag, $asin, 'platzhalter');
            }
        }
    }

    private static function scan_ajax_ok(): void {
        if (!current_user_can('manage_options') || !check_ajax_referer('sab_scan', 'n', false)) { wp_send_json_error(['text' => 'Keine Berechtigung.'], 403); }
        if (function_exists('set_time_limit')) { @set_time_limit(60); }
    }

    /** Scan: „Als eigene ID hinzufügen“. Trägt die ID bei den weiteren Partner-IDs ein und nimmt sie aus dem gespeicherten Ergebnis. */
    public static function ajax_scan_id(): void {
        self::scan_ajax_ok();
        $tag = (string) wp_unslash($_POST['tag'] ?? '');
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{2,39}$/', $tag)) { wp_send_json_error(['text' => 'Ungültige ID.']); return; }
        $ex = (string) get_option('sab_tags_extra', ''); $da = false;
        foreach (preg_split('/\R/', $ex) as $z) { if (trim(preg_split('/[\s|=,]+/', trim($z))[0] ?? '') === $tag) { $da = true; } }
        if (!$da) { update_option('sab_tags_extra', trim($ex . "\n" . $tag), false); }
        $s = (array) get_option('sab_scan', []); $n = 0;
        if (!empty($s['erg']['probleme'])) {
            $neu = []; foreach ((array) $s['erg']['probleme'] as $p) { if (($p['typ'] ?? '') === 'unbekannt' && ($p['tag'] ?? '') === $tag) { $n++; } else { $neu[] = $p; } }
            $s['erg']['probleme'] = $neu; if (isset($s['erg']['ids'][$tag])) { $s['erg']['ids'][$tag]['klasse'] = 'ok'; }
            update_option('sab_scan', $s, false);
        }
        wp_send_json_success(['n' => $n]);
    }

    public static function ajax_scan_start(): void {
        self::scan_ajax_ok(); global $wpdb;
        $ids = array_map('intval', (array) $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_status IN ('publish','future') AND post_type IN ('post','page') AND (post_content LIKE '%amazon.%' OR post_content LIKE '%amzn.%' OR post_content LIKE '%[amz\\_%' OR post_content LIKE '%[aawp%' OR post_content LIKE '%[amazon %' OR post_content LIKE '%a.co/%') ORDER BY ID"));
        update_option('sab_scan', ['ids' => $ids, 'pos' => 0, 'erg' => self::scan_neu(), 'ts' => time(), 'fertig' => false], false);
        wp_send_json_success(['pos' => 0, 'gesamt' => count($ids), 'fertig' => !$ids]);
    }

    public static function ajax_scan_schritt(): void {
        self::scan_ajax_ok();
        $s = (array) get_option('sab_scan', []); if (empty($s['ids']) || !empty($s['fertig'])) { wp_send_json_success(['pos' => count((array) ($s['ids'] ?? [])), 'gesamt' => count((array) ($s['ids'] ?? [])), 'fertig' => true]); }
        $erw = self::scan_erwartet(); $e = (array) $s['erg']; $pos = (int) $s['pos'];
        foreach (array_slice($s['ids'], $pos, self::SCAN_SCHRITT) as $pid) { $c = (string) get_post_field('post_content', (int) $pid); self::scan_text($c, (int) $pid, $erw, $e); $pos++; }
        $fertig = $pos >= count($s['ids']);
        update_option('sab_scan', ['ids' => $s['ids'], 'pos' => $pos, 'erg' => $e, 'ts' => $s['ts'], 'fertig' => $fertig], false);
        wp_send_json_success(['pos' => $pos, 'gesamt' => count($s['ids']), 'fertig' => $fertig]);
    }

    /** CSV-Export (Semikolon, drei Tabellen: Top-Probleme, ASIN-Details, Fundstellen). */
    public static function scan_csv(): void {
        if (!current_user_can('manage_options') || !wp_verify_nonce((string) ($_GET['_wpnonce'] ?? ''), 'sab_scan_csv')) { wp_die('Keine Berechtigung.'); }
        $s = (array) get_option('sab_scan', []); $e = (array) ($s['erg'] ?? self::scan_neu());
        header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="scan-' . gmdate('Y-m-d') . '.csv"');
        echo "\xEF\xBB\xBF" . self::scan_csv_text($e);
        exit;
    }

    public static function scan_csv_text(array $e): string {
        $z = function (array $f) { return implode(';', array_map(fn($v) => '"' . str_replace('"', '""', (string) $v) . '"', $f)) . "\n"; };
        $o = $z(['Top-Probleme']) . $z(['Art', 'Grund', 'ASIN', 'Partner-ID', 'Shop', 'Artikel-ID']);
        foreach ((array) ($e['probleme'] ?? []) as $p) { $o .= $z([$p['typ'], $p['grund'], $p['asin'], $p['tag'], $p['land'], $p['pid']]); }
        $o .= "\n" . $z(['ASIN-Details']) . $z(['ASIN', 'Links', 'Shops', 'Artikel']);
        foreach ((array) ($e['asin'] ?? []) as $a => $x) { $o .= $z([$a, $x['n'], implode(',', array_keys((array) $x['laender'])), implode(',', array_keys((array) $x['posts']))]); }
        $o .= "\n" . $z(['Partner-IDs']) . $z(['Partner-ID', 'Links', 'Shop', 'Einstufung']);
        foreach ((array) ($e['ids'] ?? []) as $t => $x) { $o .= $z([$t, $x['n'], $x['land'], $x['klasse']]); }
        $o .= "\n" . $z(['Kurzlinks (amzn.to u. a.), kein Alarm']) . $z(['Links', 'Artikel']);
        foreach ((array) ($e['kurz']['posts'] ?? []) as $pid => $n) { $o .= $z([$n, $pid]); }
        return $o;
    }

    private static function scan_html(): string {
        $s = (array) get_option('sab_scan', []); $e = isset($s['erg']) ? (array) $s['erg'] : null; $fertig = !empty($s['fertig']);
        $nonce = wp_create_nonce('sab_scan'); $gesamt = count((array) ($s['ids'] ?? [])); $pos = (int) ($s['pos'] ?? 0);
        $h = '<h2>Seite scannen</h2><p class="description">Geht alle Artikel mit Amazon-Links durch (in Etappen zu je ' . self::SCAN_SCHRITT . ' Artikeln, nur auf Klick, ohne Last für Besucher). Gezeigt werden nur die Ausreißer; normale Partner-IDs je Land aus den Einstellungen gelten als in Ordnung, Kurzlinks stehen in einer eigenen Gruppe ohne Alarm.</p>'
            . '<p><button type="button" class="button button-primary button-hero" id="sab-scan-knopf">' . ($e && !$fertig && $gesamt ? 'Scan fortsetzen (' . $pos . ' von ' . $gesamt . ')' : 'Seite jetzt scannen') . '</button> <span id="sab-scan-t" style="font-weight:600"></span></p><progress id="sab-scan-p" max="100" value="0" style="width:100%;max-width:760px;height:22px;display:none"></progress>'
            . '<script>(function(){var k=document.getElementById("sab-scan-knopf"),p=document.getElementById("sab-scan-p"),t=document.getElementById("sab-scan-t"),A=' . wp_json_encode(admin_url('admin-ajax.php')) . ',N=' . wp_json_encode($nonce) . ',weiter=' . ($e && !$fertig && $gesamt ? 'true' : 'false') . ',f=0;'
            . 'function post(a,ok,bad){var x=new XMLHttpRequest();x.open("POST",A);x.setRequestHeader("Content-Type","application/x-www-form-urlencoded");x.timeout=90000;x.onload=function(){var j=null;try{j=JSON.parse(x.responseText)}catch(e){}if(x.status===200&&j&&j.success){ok(j.data)}else{bad()}};x.onerror=bad;x.ontimeout=bad;x.send("action="+a+"&n="+encodeURIComponent(N))}'
            . 'function zeig(s){p.style.display="block";p.max=Math.max(1,s.gesamt);p.value=s.pos;t.textContent="Artikel "+s.pos+" von "+s.gesamt}'
            . 'function schritt(){post("sab_scan_schritt",function(s){f=0;zeig(s);if(s.fertig){t.textContent="Fertig, Ergebnis wird geladen …";setTimeout(function(){location.reload()},800)}else{schritt()}},function(){f++;if(f<4){setTimeout(schritt,2000)}else{t.textContent="Unterbrochen. Bitte auf Fortsetzen klicken.";k.disabled=false;weiter=true;k.textContent="Scan fortsetzen"}})}'
            . 'k.onclick=function(){k.disabled=true;f=0;if(weiter){schritt()}else{post("sab_scan_start",function(s){zeig(s);if(s.fertig){location.reload()}else{schritt()}},function(){t.textContent="Start nicht möglich.";k.disabled=false})}};})();</script>';
        if (!$e || (!$fertig && !$e['artikel'])) { return $h . '<p>Noch kein Scan vorhanden.</p>'; }
        $erw = self::scan_erwartet(); $namen = self::produkte();
        $nprob = count($e['probleme']); [$nbild, $nbild_st] = self::bildfehler_zaehlen();
        $karte = function (string $zahl, string $text, string $farbe = '#1d2327') { return '<div style="flex:1 1 150px;max-width:220px;background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:12px 16px"><div style="font-size:30px;font-weight:700;color:' . $farbe . '">' . $zahl . '</div><div style="color:#50575e">' . $text . '</div></div>'; };
        $h .= '<p class="description">Stand: ' . esc_html(wp_date('d.m.Y H:i', (int) ($s['ts'] ?? time()))) . ($fertig ? '' : ' (unvollständig: Scan nicht zu Ende gelaufen)') . '</p><div style="display:flex;flex-wrap:wrap;gap:12px;margin:12px 0">'
            . $karte((string) (int) $e['artikel'], 'Artikel gescannt') . $karte((string) (int) $e['links'], 'Amazon-Links') . $karte((string) count($e['asin']), 'verschiedene Produkte')
            . $karte((string) (int) $e['kurz']['n'], 'Kurzlinks (kein Alarm)', '#50575e') . ((int) ($e['ausnahmen'] ?? 0) ? $karte((string) (int) $e['ausnahmen'], 'Hilfe-/Datenschutzlinks (ohne ID ok)', '#50575e') : '') . $karte((string) $nprob, 'Ausreißer', $nprob ? '#b32d2e' : '#1a7f37') . $karte((string) $nbild, 'Produkte ohne Bild' . ($nbild ? ' (auf ' . (int) $nbild_st . ' Stellen)' : ''), $nbild ? '#b32d2e' : '#1a7f37') . '</div>';
        // Balken je Partner-ID
        $max = 1; foreach ($e['ids'] as $x) { $max = max($max, (int) $x['n']); }
        $farbe = ['ok' => '#1a7f37', 'fremdland' => '#dba617', 'unbekannt' => '#b32d2e', 'ohne' => '#b32d2e'];
        $h .= '<h3>Partner-IDs</h3><table class="widefat" style="max-width:820px"><tbody>';
        uasort($e['ids'], fn($a, $b) => $b['n'] <=> $a['n']);
        foreach ($e['ids'] as $t => $x) { $h .= '<tr><td style="width:190px"><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' . $farbe[$x['klasse']] . ';margin-right:6px"></span><code>' . esc_html((string) $t) . '</code> <small>amazon.' . esc_html((string) $x['land']) . '</small></td><td><div style="background:#f0f0f1;border-radius:4px"><div style="width:' . max(2, (int) round(100 * $x['n'] / $max)) . '%;background:' . $farbe[$x['klasse']] . ';color:#fff;font-size:12px;padding:1px 6px;border-radius:4px;white-space:nowrap">' . (int) $x['n'] . '</div></div></td></tr>'; }
        $h .= '</tbody></table><p class="description"><span style="color:#1a7f37">&#9679;</span> erwartete ID des Landes · <span style="color:#dba617">&#9679;</span> ID eines anderen Landes · <span style="color:#b32d2e">&#9679;</span> unbekannt oder ohne ID.</p>';
        // Balken je Shop
        arsort($e['laender']); $mx = max(1, max($e['laender'] ?: [1]));
        $h .= '<h3>Links je Amazon-Shop</h3><table class="widefat" style="max-width:820px"><tbody>';
        foreach ($e['laender'] as $l => $n) { $h .= '<tr><td style="width:190px">amazon.' . esc_html((string) $l) . '</td><td><div style="background:#f0f0f1;border-radius:4px"><div style="width:' . max(2, (int) round(100 * $n / $mx)) . '%;background:#2271b1;color:#fff;font-size:12px;padding:1px 6px;border-radius:4px">' . (int) $n . '</div></div></td></tr>'; }
        $h .= '</tbody></table>';
        // Ausreißer
        $h .= '<h3>Ausreißer (' . $nprob . ')</h3>';
        if (!$e['probleme']) { $h .= '<p style="color:#1a7f37;font-size:16px"><strong>&#10003; Keine Ausreißer: alle Links tragen die erwartete Partner-ID ihres Landes.</strong></p>'; }
        else {
            $h .= '<table class="widefat striped" style="max-width:1100px"><thead><tr><th></th><th>Problem</th><th>Produkt</th><th>Artikel</th></tr></thead><tbody>';
            foreach (array_slice($e['probleme'], 0, 150) as $p) {
                $titel = (string) get_the_title((int) $p['pid']); $fe = add_query_arg('sab_zeige', $p['asin'], (string) get_permalink((int) $p['pid']));
                $su = (string) ($p['such'] ?? ''); $ed = admin_url('post.php?post=' . (int) $p['pid'] . '&action=edit' . ($su !== '' ? '&sab_suche=' . rawurlencode($su) . '&sab_nr=' . max(1, (int) ($p['nr'] ?? 1)) : ''));
                $h .= '<tr data-tag="' . esc_attr($p['typ'] === 'unbekannt' ? (string) $p['tag'] : '') . '"><td><span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:' . ($p['typ'] === 'fremdland' ? '#dba617' : '#b32d2e') . '"></span></td><td>' . esc_html($p['grund']) . ($p['typ'] === 'unbekannt' && (string) $p['tag'] !== '' ? '<br><button type="button" class="button button-small sab-id-ok" data-tag="' . esc_attr((string) $p['tag']) . '">Als eigene ID hinzufügen</button>' : '') . '</td><td>' . ($p['asin'] !== '' ? '<code>' . esc_html($p['asin']) . '</code> ' . esc_html((string) ($namen[$p['asin']] ?? '')) : '–') . '</td><td><a href="' . esc_url($fe) . '" target="_blank" rel="noopener">' . esc_html($titel !== '' ? $titel : ('Beitrag ' . $p['pid'])) . '</a> · <a href="' . esc_url($ed) . '" target="_blank" rel="noopener" title="Öffnet den Artikel und springt zur Stelle">bearbeiten</a></td></tr>';
            }
            $h .= '</tbody></table><script>(function(){var A=' . wp_json_encode(admin_url('admin-ajax.php')) . ',N=' . wp_json_encode($nonce) . ',b=document.querySelectorAll(".sab-id-ok");for(var i=0;i<b.length;i++){b[i].onclick=function(){var t=this.getAttribute("data-tag"),me=this;me.disabled=true;me.textContent="…";var fd=new FormData();fd.append("action","sab_scan_id");fd.append("n",N);fd.append("tag",t);fetch(A,{method:"POST",body:fd,credentials:"same-origin"}).then(function(r){return r.json()}).then(function(j){if(j&&j.success){var r=document.querySelectorAll("tr[data-tag]");for(var k=0;k<r.length;k++){if(r[k].getAttribute("data-tag")===t){r[k].remove()}}}else{me.disabled=false;me.textContent="Nicht möglich, nochmal versuchen"}}).catch(function(){me.disabled=false;me.textContent="Verbindungsfehler"})}}})();</script>' . (count($e['probleme']) > 150 ? '<p class="description">Die ersten 150 sind gezeigt, der CSV-Export enthält alle.</p>' : '');
        }
        if ($e['kurz']['n']) { $h .= '<h3>Kurzlinks (amzn.to u. a.): ' . (int) $e['kurz']['n'] . ' in ' . count($e['kurz']['posts']) . ' Artikeln, kein Alarm</h3><p class="description">Sie tragen die Partner-ID im Ziel und werden hier nicht bewertet. Auflösen und ersetzen: Reiter Umbau.</p>'; }
        return $h . '<p><a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=sab_scan_csv'), 'sab_scan_csv')) . '">Ergebnis als CSV herunterladen</a></p>';
    }

    // ---------- Lernen mit Mensch im Kreis: Ausfall simulieren, Ja/Nein, Gewichte der Merkmale verschieben (keine externe KI, keine Besucherdaten) ----------

    const LERN_STANDARD = ['titel' => 1.5, 'art' => 1.0, 'preis' => 0.5, 'score' => 1.0, 'prov' => 0.5, 'liefer' => 0.5];
    const LERN_NAMEN = ['titel' => 'Titelähnlichkeit', 'art' => 'Produktart', 'preis' => 'Preisnähe', 'score' => 'Score', 'prov' => 'Provision', 'liefer' => 'Lieferung/Prime'];
    const LERN_RATE = 0.1;       // Schrittweite je Entscheidung
    const LERN_AKTIV_AB = 10;    // erst ab so vielen Runden beeinflussen die Gewichte die echte Reihenfolge im Austausch

    /** Merkmale eines Kandidaten, jeweils 0 bis 1. */
    public static function lern_merkmale(float $sim, int $passt, ?float $preis_alt, ?float $preis_c, int $score, ?float $pv, array $x): array {
        $liefer = (($x['prime'] ?? null) === true ? 0.5 : 0.0) + (isset($x['liefertage']) && $x['liefertage'] !== null ? 0.5 * max(0.0, min(1.0, (7 - (float) $x['liefertage']) / 6)) : 0.25);
        return [
            'titel' => max(0.0, min(1.0, $sim)), 'art' => max(0.0, min(1.0, $passt / 3)),
            'preis' => $preis_alt && $preis_c ? max(0.0, 1 - min(1.0, abs(log($preis_c / $preis_alt)))) : 0.5,
            'score' => max(0.0, min(1.0, $score / 100)), 'prov' => $pv !== null ? max(0.0, min(1.0, $pv / 10)) : 0.5, 'liefer' => max(0.0, min(1.0, $liefer)),
        ];
    }

    public static function lern_daten(): array {
        $o = (array) get_option('sab_lern_w', []); $w = self::LERN_STANDARD;
        foreach ($w as $k => $std) { if (isset($o['w'][$k]) && is_numeric($o['w'][$k])) { $w[$k] = max(0.0, min(3.0, (float) $o['w'][$k])); } }
        return ['w' => $w, 'runden' => (int) ($o['runden'] ?? 0), 'log' => (array) ($o['log'] ?? [])];
    }

    public static function lern_aktiv(): bool { return self::lern_daten()['runden'] >= self::LERN_AKTIV_AB; }

    public static function lern_summe(array $f, array $w): float {
        $s = 0.0; foreach ($w as $k => $g) { $s += $g * (float) ($f[$k] ?? 0); } return $s;
    }

    /** Ein Lernschritt: Gewählter Kandidat gegen die abgelehnten (höher gereihten); Gewichte wandern zu den Merkmalen, in denen der Gewählte besser ist. Grenzen 0 bis 3. */
    public static function lern_schritt(array $w, array $gewaehlt, array $abgelehnte, float $rate = self::LERN_RATE): array {
        if (!$abgelehnte) { return $w; }
        foreach ($w as $k => $g) {
            $diff = 0.0; foreach ($abgelehnte as $a) { $diff += (float) ($gewaehlt[$k] ?? 0) - (float) ($a[$k] ?? 0); }
            $w[$k] = max(0.0, min(3.0, $g + $rate * $diff / count($abgelehnte)));
        }
        return $w;
    }

    /** „Keiner davon passt“: Merkmale, in denen die gezeigten Kandidaten überdurchschnittlich waren, verlieren leicht Gewicht. */
    public static function lern_ablehnen(array $w, array $gezeigte, float $rate = self::LERN_RATE): array {
        if (!$gezeigte) { return $w; }
        foreach ($w as $k => $g) {
            $m = 0.0; foreach ($gezeigte as $f) { $m += (float) ($f[$k] ?? 0); } $m /= count($gezeigte);
            $w[$k] = max(0.0, min(3.0, $g - $rate * 0.5 * ($m - 0.5)));
        }
        return $w;
    }

    const LERN_URL = 'https://raw.githubusercontent.com/Aleeeb/schlanke-amazon-box-/main/lernen.json';

    /** Export: nur Gewichte, Rundenzahl und Version, keine ASINs, Artikel oder Besucherdaten. */
    public static function lern_export(): string {
        $ld = self::lern_daten();
        return (string) wp_json_encode(['format' => 1, 'stand' => gmdate('Y-m-d'), 'plugin' => self::VERSION, 'runden' => $ld['runden'], 'w' => array_map(fn($g) => round($g, 3), $ld['w'])]);
    }

    /** Import: Gewichte der anderen Seiten werden nach Rundenzahl gemischt (gewichteter Mittelwert), die Runden zählen zusammen. @return array{0:bool,1:string} */
    public static function lern_import(string $json): array {
        $in = json_decode($json, true);
        if (!is_array($in) || (int) ($in['format'] ?? 0) !== 1 || !is_array($in['w'] ?? null)) { return [false, 'Das ist keine gültige Lerndatei.']; }
        $r_in = max(0, min(1000, (int) ($in['runden'] ?? 0)));
        if ($r_in < 1) { return [false, 'Die Lerndatei hat noch keine Runden.']; }
        $ld = self::lern_daten(); $w = $ld['w']; $r0 = $ld['runden'];
        foreach ($w as $k => $g) { if (isset($in['w'][$k]) && is_numeric($in['w'][$k])) { $w[$k] = max(0.0, min(3.0, ($g * max(1, $r0) + (float) $in['w'][$k] * $r_in) / (max(1, $r0) + $r_in))); } }
        $log = $ld['log']; $log[] = ['t' => gmdate('Y-m-d'), 'import' => $r_in];
        self::lern_speichern($w, $r0 + $r_in, $log);
        return [true, 'Übernommen: ' . $r_in . ' Runden der anderen Seiten eingemischt.'];
    }

    /** Holt (nur mit Haken in den Einstellungen) die gemeinsame Lerndatei aus dem Repo; jede Fassung nur einmal. Es werden keine Daten gesendet. */
    public static function lern_abruf(bool $erzwingen = false): string {
        if (!$erzwingen && !get_option('sab_lern_repo')) { return ''; }
        $r = wp_remote_get(self::LERN_URL, ['timeout' => 8]);
        if (is_wp_error($r) || (int) wp_remote_retrieve_response_code($r) !== 200) { return 'Lerndatei nicht abrufbar.'; }
        $body = (string) wp_remote_retrieve_body($r); $in = json_decode($body, true);
        $marke = md5($body);
        if (!is_array($in) || $marke === get_option('sab_lern_marke')) { return is_array($in) ? 'Diese Fassung wurde schon übernommen.' : 'Lerndatei ungültig.'; }
        [$ok, $t] = self::lern_import($body); if ($ok) { update_option('sab_lern_marke', $marke, false); }
        return $t;
    }

    private static function lern_speichern(array $w, int $runden, array $log): void {
        update_option('sab_lern_w', ['w' => $w, 'runden' => $runden, 'log' => array_slice($log, -200)], false);
    }

    /** Reiter „Lernen“: Statistik, Gewichte, Ausfallsimulation. */
    private static function lernen_html(): string {
        $ld = self::lern_daten(); $w = $ld['w']; $land = self::land(''); $d = (array) self::uebersicht_daten($land, false); $namen = self::produkte();
        $sim = (array) get_option('sab_lern_sim', []);
        $pl = array_values(array_filter(array_column($ld['log'], 'p'), fn($p) => is_numeric($p))); $letzte = array_slice($pl, -30); $e1 = count(array_filter($letzte, fn($p) => (int) $p === 1));
        $h = '<h2>Lernen: Ausfall simulieren</h2><p class="description">Ein Produkt wird nur zum Üben als ausgefallen angenommen (es wird nichts geändert). Du sagst, welcher Vorschlag passt; daraus verschiebt das Plugin die Gewichte seiner Auswahl (Titelähnlichkeit, Produktart, Preis, Score, Provision, Lieferung). Gespeichert werden nur diese Gewichte, keine Besucherdaten. Ab ' . self::LERN_AKTIV_AB . ' Runden fließen sie in die Reihenfolge im Austausch ein.</p>';
        $h .= '<p><strong>Runden:</strong> ' . $ld['runden'] . ' · <strong>Platz 1 gewählt (letzte ' . count($letzte) . '):</strong> ' . ($letzte ? round(100 * $e1 / count($letzte)) . ' %' : '–') . ' · <strong>Einfluss auf den Austausch:</strong> ' . (self::lern_aktiv() ? '<span style="color:#1a7f37">aktiv</span>' : 'noch nicht aktiv') . '</p><table class="widefat striped" style="max-width:560px"><tbody>';
        foreach ($w as $k => $g) { $h .= '<tr><td style="width:180px">' . esc_html(self::LERN_NAMEN[$k]) . '</td><td><span style="display:inline-block;height:12px;width:' . (int) round(100 * $g) . 'px;background:#2271b1"></span> ' . esc_html(str_replace('.', ',', (string) round($g, 2))) . '</td></tr>'; }
        $h .= '</tbody></table><form method="post" style="margin:8px 0">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="lernen"><input type="hidden" name="sab_form" value="lernen_reset"><button class="button button-small">Gewichte zurücksetzen</button></form>';
        $h .= '<h3>Lernstand teilen</h3><p class="description">Export enthält nur die sechs Gewichte, die Rundenzahl und die Version, keine Produkte, Artikel oder Besucherdaten. Eine fremde Lerndatei einfügen mischt ihre Gewichte nach Rundenzahl ein.</p><textarea readonly rows="3" class="large-text code" onclick="this.select()">' . esc_textarea(self::lern_export()) . '</textarea>'
            . '<form method="post">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="lernen"><input type="hidden" name="sab_form" value="lernen_import"><textarea name="sab_lern_json" rows="3" class="large-text code" placeholder="Lerndatei der anderen Seite hier einfügen"></textarea><p><button class="button">Importieren</button></p></form>'
            . '<form method="post">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="lernen"><input type="hidden" name="sab_form" value="lernen_repo"><p><label><input type="checkbox" name="sab_lern_repo" value="1"' . (get_option('sab_lern_repo') ? ' checked' : '') . '> Gemeinsame Lerndatei aus dem Repo täglich abrufen (reiner Download, es werden keine Daten gesendet)</label> <button class="button">Speichern</button> <button class="button" name="sab_jetzt" value="1">Jetzt abrufen</button></p></form>';
        $fm = function (string $form, array $extra = [], string $text = '', string $klasse = 'button') { $i = ''; foreach ($extra as $k => $v) { $i .= '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr((string) $v) . '">'; } return '<form method="post" style="display:inline">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="lernen"><input type="hidden" name="sab_form" value="' . $form . '">' . $i . '<button class="' . $klasse . '">' . esc_html($text) . '</button></form> '; };
        if (empty($sim['alt'])) { return $h . '<p>' . $fm('lernen_start', [], 'Ausfall simulieren', 'button button-primary button-hero') . '</p>'; }
        $alt = (string) $sim['alt']; $liste = (array) $sim['liste']; $pos = (int) ($sim['pos'] ?? 0);
        $h .= '<div style="border:2px solid #2271b1;background:#fff;padding:8px 16px;margin:12px 0"><h3>Angenommen, dieses Produkt fällt aus:</h3><p><strong>' . esc_html(self::anzeige_name($alt, $d, [])) . '</strong> <code>' . esc_html($alt) . '</code>' . (isset($d['items'][$alt]['preis']) ? ' · ' . esc_html((string) $d['items'][$alt]['preis']) : '') . '</p><div style="display:flex;flex-wrap:wrap;gap:12px">';
        $gezeigt = 0;
        foreach (array_slice($liste, $pos, 4, true) as $i => $c) {
            $a = (string) $c['asin']; $x = (array) ($d['items'][$a] ?? []); $gezeigt++;
            $h .= '<div style="border:1px solid #c3c4c7;padding:8px;width:260px"><div style="display:flex;gap:8px;align-items:center">' . self::mini_bild($a, $x) . '<span><strong>' . esc_html(($namen[$a] ?? '') ?: self::kurzname_aus_titel((string) ($x['titel'] ?? ''))) . '</strong><br><small>Score ' . (int) ($c['score'] ?? 0) . ' · ' . esc_html((string) ($x['preis'] ?? '')) . ' · <code>' . esc_html($a) . '</code></small></span></div><p style="margin:6px 0 0">' . $fm('lernen_passt', ['sab_rang' => $i], 'passt', 'button button-primary') . '</p></div>';
        }
        $h .= '</div><p>' . ($gezeigt ? $fm('lernen_keiner', ['sab_pos' => $pos], 'keiner davon passt, weitere zeigen') : '<em>Keine weiteren Vorschläge.</em> ') . $fm('lernen_ende', [], 'Simulation beenden') . '</p></div>';
        return $h;
    }

    const ERSATZ_SCORE = 50;   // darunter gilt ein oft verwendetes Produkt als Kandidat für gezielte Ersatzsuche

    /** Produkte, die auf mehr als einer Seite stehen und einen niedrigen Score haben (schwächste zuerst). @param array<string,int> $scores @param array<string,int> $seiten @return string[] */
    public static function ersatz_auftraege(array $scores, array $seiten, int $schwelle = self::ERSATZ_SCORE): array {
        $o = [];
        foreach ($scores as $a => $sc) { if (($seiten[$a] ?? 0) >= 2 && $sc < $schwelle) { $o[(string) $a] = (int) $sc; } }
        asort($o);
        return array_keys($o);
    }

    /** Suchauftrag für die PC-/Mac-Sitzung: oft verwendete Produkte mit niedrigem Score; Ergebnis im Format der Ersatz-Datenbank plus Handdaten (8 Felder). */
    private static function ersatz_auftrag_html(array $d): string {
        $land = self::land(''); $trotz = (array) get_option('sab_trotz', []); $namen = self::produkte(); $sc = []; $alle = self::alle_asins();
        foreach ($alle as $a) { if (isset($d['items'][$a])) { $sc[$a] = (int) self::score(array_merge($d['items'][$a], ['trotz' => isset($trotz[$a])]))[0]; } }
        $fund = self::fundstellen(array_keys($sc)); $seiten = []; foreach ($fund as $a => $f) { $seiten[$a] = count($f); }
        $liste = self::ersatz_auftraege($sc, $seiten);
        if (!$liste) { return '<p class="description">Gezielte Ersatzsuche: keine Produkte auf mehr als einer Seite mit Score unter ' . self::ERSATZ_SCORE . '.</p>'; }
        $host = preg_replace('#^www\.#', '', strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST))); $dat = gmdate('Y-m-d');
        $p = "Aufgabe: Suche bei Amazon (" . self::LAENDER[$land][0] . ") für die folgenden Produkte der Seite $host je 3 bis 5 Ersatzprodukte gleicher Produktart, die besser sind (mehr Sterne, mehr Bewertungen, Prime oder schnelle Lieferung, niedrige Lieferkosten, hohe Provision). Regeln: mindestens ca. 4 Sterne und 30 Bewertungen, lieferbar, keine tötenden oder verletzenden Produkte (keine Netze, Fallen, Gift, Kieselgur, Eierschalen), möglichst auch in UK/USA (ggf. CA/AU) erhältlich. Lies für jeden Ersatz die Handdaten und die Provision (SiteStripe) aus.\n\n"
           . "Ausgabe 1 (Ersatz-Datenbank), je Ersatz eine Zeile: `Produktart | $land | ASIN | neu | $dat | Quelle`. Produktart = die ALTE ASIN des Produkts unten.\nAusgabe 2 (Handdaten), je Ersatz eine Zeile: `ASIN | Sterne | Anzahl | Prime ja/nein | Lieferzeit je Stadt | $dat | Provision % | Lieferkosten (Betrag, 0 oder gratis)`.\nNichts live ändern; die Zeilen gehen an den Plugin-Thread.\n\nProdukte (" . count($liste) . "):\n";
        foreach (array_slice($liste, 0, 40) as $i => $a) { $p .= ($i + 1) . '. ' . $a . ' | ' . trim(preg_replace('/\s+/', ' ', ($namen[$a] ?? '') ?: mb_substr((string) ($d['items'][$a]['titel'] ?? ''), 0, 90))) . ' | Score ' . $sc[$a] . ' | auf ' . $seiten[$a] . " Seiten\n"; }
        return '<h4>Gezielte Ersatzsuche (' . count($liste) . ' Produkte)</h4><p class="description">Produkte, die auf mehr als einer Seite stehen und einen Score unter ' . self::ERSATZ_SCORE . ' haben. Auftrag für die PC-/Mac-Sitzung, zum Kopieren:</p><textarea readonly rows="8" class="large-text code">' . esc_textarea($p) . '</textarea>';
    }

    /** Übersicht der Ersatz-Datenbank: Score und Handdaten je Eintrag, Knopf „geprüft“, und Lücken (Produktarten mit weniger als zwei Ersatzprodukten im Land dieser Webseite). */
    private static function ersatz_html(): string {
        $liste = self::ersatz_liste(); $land = self::land(''); $d = (array) self::uebersicht_daten($land, false); $namen = self::produkte(); $trotz = (array) get_option('sab_trotz', []);
        $h = '<h4>Stand der Ersatz-Datenbank (' . count($liste) . ' Einträge, Land dieser Webseite: ' . esc_html($land) . ')</h4>';
        if ($liste) {
            $h .= '<table class="widefat striped" style="max-width:980px"><thead><tr><th>Produktart</th><th>Land</th><th>Produkt</th><th>Score</th><th>Daten</th><th>Status</th></tr></thead><tbody>';
            foreach ($liste as $e) {
                $x = $d['items'][$e['asin']] ?? null; $sc = $x ? self::score(array_merge($x, ['trotz' => isset($trotz[$e['asin']])]))[0] : null; $t = [];
                if ($x) {
                    if (isset($x['sterne']) && $x['sterne'] !== null) { $t[] = '&#9733; ' . esc_html(str_replace('.', ',', (string) $x['sterne'])) . ' (' . (int) ($x['anzahl'] ?? 0) . ')'; } else { $t[] = 'Sterne fehlen'; }
                    $t[] = self::prime_zeichen($x['prime'] ?? null, !empty($x['prime_unsicher']));
                    $t[] = isset($x['liefertage']) && $x['liefertage'] !== null ? '&#128666; ' . esc_html(str_replace('.', ',', (string) round((float) $x['liefertage'], 1))) . ' T' : 'Lieferzeit fehlt';
                    $t[] = isset($x['lieferkosten']) && $x['lieferkosten'] !== null ? ((float) $x['lieferkosten'] <= 0 ? 'Versand gratis' : 'Versand ' . esc_html(str_replace('.', ',', (string) $x['lieferkosten']))) : 'Versand ?';
                    $pv = self::provision_satz($x); $t[] = $pv !== null ? esc_html(str_replace('.', ',', (string) $pv)) . ' % Prov.' : 'Provision fehlt';
                }
                $knopf = '<form method="post" style="display:inline">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="score"><input type="hidden" name="sab_form" value="ersatz_pruef"><input type="hidden" name="sab_art" value="' . esc_attr($e['art']) . '"><input type="hidden" name="sab_land" value="' . esc_attr($e['land']) . '"><input type="hidden" name="sab_asin" value="' . esc_attr($e['asin']) . '"><input type="hidden" name="sab_status" value="' . ($e['status'] === 'geprüft' ? 'neu' : 'geprüft') . '"><button class="button button-small">' . ($e['status'] === 'geprüft' ? 'Prüfung zurücknehmen' : 'als geprüft markieren') . '</button></form>';
                $h .= '<tr><td>' . esc_html($e['art']) . '</td><td>' . esc_html($e['land']) . '</td><td><code>' . esc_html($e['asin']) . '</code> ' . esc_html(($namen[$e['asin']] ?? '') ?: mb_substr((string) ($x['titel'] ?? ''), 0, 60)) . '</td><td>' . ($sc === null ? '<em>Amazon-Daten fehlen noch</em>' : (int) $sc) . '</td><td><small>' . ($t ? implode(' · ', $t) : '') . '</small></td><td>' . ($e['status'] === 'geprüft' ? '<strong style="color:#1a7f37">geprüft</strong> ' : 'neu ') . $knopf . '</td></tr>';
            }
            $h .= '</tbody></table>';
        }
        $luecken = [];
        foreach (self::gruppen() as $name => $asins) {
            $n = 0; foreach ($liste as $e) { if ($e['land'] === $land && $e['status'] === 'geprüft' && mb_strtolower($e['art']) === mb_strtolower($name)) { $n++; } }
            if ($n < 2) { $luecken[] = '<li><strong>' . esc_html($name) . '</strong>: ' . $n . ' geprüft' . ($n === 0 ? ' (keine Reserve)' : ' (nur eine Reserve)') . '</li>'; }
        }
        $h .= self::ersatz_auftrag_html($d);
        if ($luecken) { $h .= '<p><strong>Lücken</strong> (Produktarten mit weniger als zwei geprüften Ersatzprodukten im Land ' . esc_html($land) . '):</p><ul style="columns:2;max-width:860px">' . implode('', array_slice($luecken, 0, 60)) . '</ul>'; }
        return $h;
    }

    /** Ersatzprodukte aus der Datenbank für ein Produkt im Land der Webseite: gleiche Produktgruppe oder ausdrücklich für diese ASIN. @return array<string,string> ASIN => Status */
    public static function ersatz_fuer(string $alt, ?string $land = null): array {
        $land = $land ?? self::land(''); $gr = [];
        foreach (self::gruppen() as $name => $liste) { if (in_array($alt, $liste, true)) { $gr[mb_strtolower($name)] = 1; } }
        $out = [];
        foreach (self::ersatz_liste() as $e) {
            if ($e['land'] !== $land || $e['asin'] === $alt) { continue; }
            if (strtoupper($e['art']) === $alt || isset($gr[mb_strtolower($e['art'])])) { if (!isset($out[$e['asin']]) || $e['status'] === 'geprüft') { $out[$e['asin']] = $e['status']; } }
        }
        return $out;
    }

    private static function slots(): array {
        $out = [];
        foreach (preg_split('/\R/', (string) get_option('sab_slots', '')) as $z) {
            if (strpos($z, ':') === false) { continue; }
            [$name, $liste] = array_map('trim', explode(':', $z, 2));
            $asins = array_values(array_filter(array_map([__CLASS__, 'asin'], explode(',', $liste))));
            if ($name && $asins) { $out[$name] = $asins; }
        }
        return $out;
    }

    /** Zeilen "ALT => NEU" => [ALT => NEU]: seitenweiter Produkttausch */
    private static function tausch_regeln(): array {
        $out = [];
        foreach (preg_split('/\R/', (string) get_option('sab_tausch', '')) as $z) {
            if (strpos($z, '=>') === false) { continue; }
            [$a, $b] = array_map('trim', explode('=>', $z, 2));
            $a = self::asin($a); $b = self::asin(explode('|', $b)[0]);
            if (strlen($a) === 10 && strlen($b) === 10 && $a !== $b) { $out[$a] = $b; }
        }
        return $out;
    }

    /** Wendet die Austausch-Regeln an (Ketten bis 5 Schritte). */
    private static function getauscht(string $asin): string {
        $r = self::tausch_regeln();
        for ($i = 0; $i < 5 && isset($r[$asin]); $i++) { $asin = $r[$asin]; }
        return $asin;
    }

    /** Favoriten nach vorn, „Ersetzen“ ans Ende, sonst Reihenfolge unverändert. */
    private static function nach_marken(array $asins): array {
        $m = (array) get_option('sab_marken', []);
        $f = $n = $e = [];
        foreach ($asins as $a) {
            $t = $m[$a] ?? '';
            if ($t === 'fav') { $f[] = $a; } elseif ($t === 'ers') { $e[] = $a; } else { $n[] = $a; }
        }
        return array_merge($f, $n, $e);
    }

    /** Zeilen "slot: ASIN-A | ASIN-B" => [slot => [ASIN-A, ASIN-B]] (2 bis 4 Varianten) */
    private static function tests(): array {
        $out = [];
        foreach (preg_split('/\R/', (string) get_option('sab_tests', '')) as $z) {
            if (strpos($z, ':') === false) { continue; }
            [$name, $liste] = array_map('trim', explode(':', $z, 2));
            $asins = array_values(array_filter(array_map([__CLASS__, 'asin'], explode('|', $liste))));
            if ($name && count($asins) >= 2 && count($asins) <= 4) { $out[$name] = $asins; }
        }
        return $out;
    }

    // ---------- Daten holen (mit Cache) ----------

    /**
     * @return array{titel:string,bild:string,link:string,weg:bool,verfuegbar:bool}|null
     * null = Amazon gerade nicht erreichbar und nichts Gespeichertes da.
     */
    private static $sofort_produkte = 0;

    private static function produkt(string $asin, string $land, bool $frisch = false, bool $warten = false): ?array {
        if (!$asin) { return null; }
        if ($frisch) { delete_transient("sab_{$land}_{$asin}"); }
        $c = get_transient("sab_{$land}_{$asin}");
        if ($c) {
            // Besucher warten nie auf Amazon: kurz vor Ablauf im Hintergrund auffrischen, bis dahin den Stand zeigen
            if (!$frisch && !$warten && !defined('SAB_SYNC') && time() - (int) ($c['ts'] ?? 0) > self::TTL - 2 * HOUR_IN_SECONDS) { self::im_hintergrund('sab_holen', [$asin, $land]); }
            return $c;
        }
        if (!$warten && function_exists('current_user_can') && current_user_can('edit_posts')) { $warten = true; }   // Redakteure warten kurz, damit sie nie eine leere Stelle sehen
        if (!$frisch && !$warten && !defined('SAB_SYNC')) {
            if (self::$sofort_produkte < 2 && !defined('SAB_KEIN_SOFORT')) { self::$sofort_produkte++; }   // nichts im Speicher (Update, Ablauf): die ersten zwei Produkte je Seitenaufruf gleich holen, damit kein Bild fehlt
            else { self::im_hintergrund('sab_holen', [$asin, $land]); return null; }   // sonst Hintergrundabruf, die Seite nutzt den Rückfall (schlichter Link)
        }

        $alt = get_option("sab_alt_{$land}_{$asin}");
        $hl = self::heimat_land($asin, $land);   // Produkt gibt es nur in einem anderen Store (z. B. .com): Bild und Link kommen von dort
        $p = $hl !== $land ? self::abrufen($asin, $hl, $alt ?: []) : null;
        if ($p === null || !empty($p['weg'])) { $p = self::abrufen($asin, $land, $alt ?: []); }
        if ($p !== null && (!empty($p['weg']) || empty($p['bild']))) { $q = self::heimat_suchen($asin, $land); if ($q) { $p = $q; } }
        if ($p !== null) {
            set_transient("sab_{$land}_{$asin}", $p, self::TTL);
            update_option("sab_alt_{$land}_{$asin}", $p, false);
            return $p;
        }
        // API gerade nicht erreichbar: letzter Stand nur, solange er höchstens 1 Tag alt ist (Amazon-Caching-Regel)
        return ($alt && (time() - (int) ($alt['ts'] ?? 0)) <= self::TTL) ? $alt : null;
    }

    /** Gemerkter Heimat-Store eines Produkts (ASIN => Land), wenn es im Standardstore der Seite nicht zu haben ist. */
    public static function heimat_land(string $asin, string $land): string {
        $m = array_filter(self::heimat_liste()) + (array) get_option('sab_asin_land', []);
        return isset($m[$asin]) && isset(self::LAENDER[$m[$asin]]) && self::tag((string) $m[$asin]) !== '' ? (string) $m[$asin] : $land;
    }

    /** Eigene Liste „nur in einem anderen Store“ (Einstellungen): ASIN [= Land]; ohne Land wird gesucht. @return array<string,string> ASIN => Land oder '' */
    private static function heimat_liste(): array {
        $o = [];
        foreach (preg_split('/\R/', (string) get_option('sab_heimat_liste', '')) as $z) {
            $t = preg_split('/[\s=:,;|]+/', trim($z), -1, PREG_SPLIT_NO_EMPTY); $a = strtoupper((string) ($t[0] ?? ''));
            if (strlen(self::asin($a)) === 10) { $l = self::land_schluessel((string) ($t[1] ?? '')); $o[$a] = isset(self::LAENDER[$l]) && ($t[1] ?? '') !== '' ? $l : ''; }
        }
        return $o;
    }

    /** Das Produkt ist im angefragten Store unbekannt oder ohne Bild: in den anderen Stores mit eigener Partner-ID suchen (höchstens 4 Abrufe), Treffer als Heimat-Store merken. Es wird nichts gespeichert außer dem Land. */
    private static function heimat_suchen(string $asin, string $land): ?array {
        if (!array_key_exists($asin, self::heimat_liste())) { return null; }   // nur für Produkte, die du ausdrücklich eingetragen hast (sonst würde ein ausgefallenes Produkt nie ersetzt)
        $n = 0;
        foreach (['com', 'co.uk', 'ca', 'com.au', 'de', 'fr', 'it', 'es'] as $k) {
            if ($k === $land || self::tag($k) === '') { continue; }
            if (++$n > 4) { break; }
            $q = self::abrufen($asin, $k, []);
            if ($q !== null && empty($q['weg']) && !empty($q['bild']) && !empty($q['link'])) {
                $m = (array) get_option('sab_asin_land', []); if (($m[$asin] ?? '') !== $k && count($m) < 500) { $m[$asin] = $k; update_option('sab_asin_land', $m, false); }
                return $q;
            }
        }
        return null;
    }

    /** Plant einen einmaligen Abruf im WP-Cron (ohne Doppelungen), damit Seitenaufrufe nie auf Amazon warten. */
    private static function im_hintergrund(string $hook, array $args): void {
        if (!function_exists('wp_schedule_single_event') || wp_next_scheduled($hook, $args)) { return; }
        wp_schedule_single_event(time(), $hook, $args);
        if (function_exists('spawn_cron')) { spawn_cron(); }
    }

    /**
     * Stündlich: Produkte und Bilder, die fehlen oder in den nächsten Stunden ablaufen, rechtzeitig frisch holen (höchstens 12 je Lauf).
     * So ist der Zwischenspeicher (Amazon: höchstens 1 Tag) nie leer, auch wenn lange kein Besucher da war oder ein Update stattfand.
     */
    public static function auffrischen(): void {
        $land = self::land(''); $n = 0;
        foreach (self::alle_asins() as $asin) {
            if ($n >= 12) { break; }
            $asin = (string) $asin; if (!self::asin($asin)) { continue; }
            $c = get_transient("sab_{$land}_{$asin}");
            if (!$c || time() - (int) ($c['ts'] ?? 0) > self::TTL - 6 * HOUR_IN_SECONDS) { self::holen($asin, $land); $n++; continue; }
            if (!empty($c['bild']) && !empty($c['link'])) {
                $f = self::bild_pfad($land, $asin, 0);
                if (!file_exists($f) || time() - filemtime($f) > self::TTL - 6 * HOUR_IN_SECONDS) { self::bild_url($c, $asin, $land, 0, true); $n++; }
            }
        }
    }

    public static function holen($asin, $land): void {
        $asin = (string) $asin; $land = (string) $land;
        if (!isset(self::LAENDER[$land]) || !self::asin($asin)) { return; }
        $p = self::produkt($asin, $land, true);
        if ($p && !empty($p['bild'])) { self::bild_url($p, $asin, $land, 0, true); }
    }

    public static function bild_holen($land, $asin, $idx): void {
        $p = self::produkt((string) $asin, (string) $land, false, true);
        if ($p) { self::bild_url($p, (string) $asin, (string) $land, (int) $idx, true); }
    }

    private static function abrufen(string $asin, string $land, array $alt): ?array {
        $token = self::token($land);
        $tag   = self::tag($land);
        if (!$token || !$tag) { return null; }
        $mp = self::LAENDER[$land][0];

        $r = self::api_post([
            'timeout' => 10,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'x-marketplace' => $mp,
            ],
            'body' => wp_json_encode([
                'itemIds'     => [(string) $asin],
                'itemIdType'  => 'ASIN',
                'marketplace' => $mp,
                'partnerTag'  => $tag,
                'resources'   => ['images.primary.large', 'images.variants.large', 'itemInfo.title', 'offersV2.listings.availability', 'offersV2.listings.price', 'offersV2.listings.isBuyBoxWinner'],
            ]),
        ]);
        if (is_wp_error($r) || wp_remote_retrieve_response_code($r) !== 200) { return null; }
        $j  = json_decode(wp_remote_retrieve_body($r), true);
        $it = $j['itemsResult']['items'][0] ?? null;
        if (!$it || empty($it['detailPageURL'])) {
            // „Unbekannt“ nur bei eindeutiger Antwort von Amazon (Produkt nicht gefunden/nicht zugänglich); jede andere Fehlermeldung zählt als „nicht erreichbar“, nie als „Produkt weg“
            $codes = []; foreach ((array) ($j['errors'] ?? []) as $e_) { $codes[] = (string) ($e_['code'] ?? '') . ' ' . (string) ($e_['message'] ?? ''); }
            if ($codes && !preg_match('/ItemNotAccessible|InvalidParameterValue|ItemNotFound|NoResults|NotFound|not\s+(?:found|accessible)/i', implode(' ', $codes))) { return null; }
            // Amazon antwortet, kennt das Produkt aber nicht (mehr)
            return ['ts' => time(), 'titel' => $alt['titel'] ?? '', 'bild' => '', 'link' => '', 'weg' => true, 'verfuegbar' => false];
        }
        $l = self::bestes_angebot($it);
        return [
            'ts'         => time(),
            'preis'      => self::preis_von($l),
            'titel'      => $it['itemInfo']['title']['displayValue'] ?? ($alt['titel'] ?? ''),
            'bild'       => $it['images']['primary']['large']['url'] ?? '',
            'bilder'     => self::bilderliste($it),
            'link'       => $it['detailPageURL'], // von Amazon geliefert, enthält Partner-ID, nicht verändern
            'weg'        => false,
            'status'     => (string) ($l['availability']['type'] ?? ''),
            // Amazon prüft für einen Standard-Lieferort: OUT_OF_STOCK heißt nicht, dass es für Spanien nicht lieferbar ist
            'verfuegbar' => !empty($it['offersV2']['listings']) && (get_option('sab_strikt') !== '1' || isset(((array) get_option('sab_trotz', []))[$asin]) || (string) ($l['availability']['type'] ?? '') !== 'OUT_OF_STOCK'),
        ];
    }

    /** Preis aus einem Angebot; probiert mehrere Stellen, weil nicht jedes Angebot dieselbe Form hat. */
    private static function preis_von(?array $l): string {
        if (!$l) { return ''; }
        $p = $l['price'] ?? [];
        if (!empty($p['money']['displayAmount'])) { return (string) $p['money']['displayAmount']; }
        if (!empty($p['displayAmount']))          { return (string) $p['displayAmount']; }
        if (isset($p['money']['amount']))         { return number_format((float) $p['money']['amount'], 2, ',', '.') . ' ' . ($p['money']['currency'] ?? ''); }
        if (!empty($p['pricePerUnit']['displayAmount'])) { return (string) $p['pricePerUnit']['displayAmount'] . ' (Einheit)'; }
        return '';
    }

    /** Amazon erlaubt anfangs 1 Abfrage pro Sekunde (sonst 429): Mindestabstand einhalten und bei 429 einmal nach kurzer Pause wiederholen. */
    private static function api_post(array $args) {
        static $zuletzt = 0.0;
        for ($versuch = 0; $versuch < 4; $versuch++) {
            $warte = 1.1 - (microtime(true) - $zuletzt);
            if ($warte > 0 && !defined('SAB_SYNC')) { usleep((int) ($warte * 1000000)); }
            $r = wp_remote_post(self::API, $args);
            $zuletzt = microtime(true);
            $c = is_wp_error($r) ? 0 : (int) wp_remote_retrieve_response_code($r);
            if ($c !== 0 && $c !== 429 && $c < 500) { break; }   // Erfolg oder eindeutige Ablehnung: fertig; Drosselung, Serverfehler und Netzfehler wiederholen
            if (!defined('SAB_SYNC')) { usleep((2 << $versuch) * 1000000); }   // 2, 4, 8 Sekunden
        }
        return $r;
    }

    /** Streichpreis und Ersparnis aus dem Angebot (Felder price.savingBasis / price.savings). */
    private static function spar_von(?array $l): array {
        $p = (array) ($l['price'] ?? []);
        $basis = (string) ($p['savingBasis']['money']['displayAmount'] ?? ($p['savingBasis']['displayAmount'] ?? ''));
        $ers   = (string) ($p['savings']['money']['displayAmount'] ?? ($p['savings']['displayAmount'] ?? ''));
        $proz  = isset($p['savings']['percentage']) ? (int) $p['savings']['percentage'] : null;
        return ['streich' => $basis, 'ersparnis' => $ers, 'prozent' => $proz];
    }

    /** Sucht einen Schlüssel beliebig tief (für Felder, deren genaue Lage Amazon ändern kann, z. B. isPrimeEligible). */
    private static function finde(array $a, string $key) {
        foreach ($a as $k => $v) {
            if ($k === $key) { return $v; }
            if (is_array($v) && ($r = self::finde($v, $key)) !== null) { return $r; }
        }
        return null;
    }

    /** Aktion (Deal/Coupon-Typ) aus irgendeinem Angebot des Produkts. */
    private static function deal_von(array $it): string {
        foreach ((array) ($it['offersV2']['listings'] ?? []) as $l) {
            $d = (array) ($l['dealDetails'] ?? []);
            if ($d) { $t = trim((string) ($d['badge'] ?? '') . ' ' . (string) ($l['type'] ?? '') . ' ' . (string) ($d['accessType'] ?? '')); return $t !== '' ? $t : 'Aktion'; }
            if (in_array((string) ($l['type'] ?? ''), ['LIGHTNING_DEAL', 'SUBSCRIBE_AND_SAVE'], true)) { return (string) $l['type']; }
        }
        return '';
    }

    /** [Kategorie, Verkaufsrang] aus browseNodeInfo (zuerst der Rang der Webseite, sonst der des ersten Knotens mit Rang). */
    private static function rang_von(array $it): array {
        $wr = (array) ($it['browseNodeInfo']['websiteSalesRank'] ?? []);
        $kat = (string) ($wr['contextFreeName'] ?? ($wr['displayName'] ?? ''));
        $rang = isset($wr['salesRank']) ? (int) $wr['salesRank'] : null;
        foreach ((array) ($it['browseNodeInfo']['browseNodes'] ?? []) as $bn) {
            if ($kat === '') { $kat = (string) ($bn['contextFreeName'] ?? ($bn['displayName'] ?? '')); }
            if ($rang === null && isset($bn['salesRank'])) { $rang = (int) $bn['salesRank']; }
        }
        return [$kat, $rang];
    }

    private static function verkaeufer_von(?array $l): string {
        if (!$l) { return ''; }
        $m = $l['merchantInfo'] ?? [];
        return (string) ($m['name'] ?? ($m['id'] ?? ''));
    }

    /** Kurzer Befund, warum Preis oder Verkäufer fehlen (nur für die Verwaltung). */
    private static function diagnose(array $it): string {
        $ls = (array) ($it['offersV2']['listings'] ?? []);
        if (!isset($it['offersV2'])) { return 'Amazon lieferte keinen Angebotsblock (offersV2).'; }
        if (!$ls) { return 'Angebotsblock leer: kein Angebot für dein Land / diesen Zugang.'; }
        $l = self::bestes_angebot($it);
        $t = count($ls) . ' Angebot(e)';
        if (self::preis_von($l) === '') { $t .= ', ohne Preisfeld (Felder: ' . implode(', ', array_keys((array) $l)) . ')'; }
        if (self::verkaeufer_von($l) === '') { $t .= ', ohne Verkäufer'; }
        return $t;
    }

    /** Standard-Gewichte des Scores (Summe 100). „prime“ bleibt 0, solange die Schnittstelle Prime nicht liefert. */
    const SCORE_STANDARD = ['rang' => 27, 'buybox' => 20, 'angebot' => 13, 'preis' => 20, 'streich' => 20, 'deal' => 10, 'bewertung' => 100, 'konstanz' => 10, 'prime' => 42, 'liefer' => 42, 'provision' => 50, 'versand' => 25];
    /** Kategorien, in denen ein Verkaufsrang für Garten-Produkte zählt (Hauptkategorie und typische Unterkategorien). */
    const GARTEN_KAT = '/jard[ií]n|garten|giardin|garden|lawn|c[eé]sped|outdoor|plaga|control de (?:aves|animales|plagas|insectos|roedores|caracoles)|babosa|caracol|\baves?\b|cerca|valla|huerto|plantas?\b|riego|cultivo|abono|maceta|invernadero|semill|poda|jardiner/iu';
    const SCORE_NAMEN = [
        'versand' => 'Lieferkosten (hoch = Abzug)',
        'rang'    => 'Verkaufsrang',
        'buybox'  => 'Buy Box (Kaufen-Button)',
        'angebot' => 'Angebot verfügbar',
        'preis'   => 'Günstiger Preis (im Vergleich zur Gruppe)',
        'streich' => 'Streichpreis / Ersparnis',
        'deal'    => 'Aktion / Deal',
        'bewertung' => 'Bewertung (Sterne und Anzahl, von Hand)',
        'konstanz' => 'Konstanz (Tage mit gutem Score)',
        'prime'   => 'Prime (von Hand)',
        'liefer'  => 'Lieferzeit (von Hand, Ø Hauptstädte)',
        'provision' => 'Provision der Kategorie (Tabelle im Reiter Score)',
    ];

    /** Gewichte aus den Einstellungen; Fehlendes und Ungültiges fällt auf den Standard zurück. @return array<string,int> */
    private static function score_gewichte(): array {
        $w = (array) get_option('sab_score_w', []); $out = [];
        foreach (self::SCORE_STANDARD as $k => $std) { $out[$k] = isset($w[$k]) && is_numeric($w[$k]) ? max(0, min(1000000, (int) $w[$k])) : $std; }
        return array_sum($out) > 0 ? $out : self::SCORE_STANDARD;
    }

    const K_SCHWELLE = 60;   // ab diesem Score gilt ein Tag als „gut“
    const K_MIN = 5;         // so viele aufgezeichnete Tage braucht die Konstanz
    const K_FENSTER = 30;    // Tage, die zählen

    /** Hält pro Produkt und Tag den Score (ohne Konstanz) fest, höchstens 60 Tage; Anteil guter Tage der letzten 30 Tage kommt zurück in die Daten. */
    private static function mit_verlauf(array $d, bool $speichern = false): array {
        if (empty($d['items'])) { return $d; }
        $v = (array) get_option('sab_verlauf', []); $heute = gmdate('Y-m-d'); $neu = false;
        foreach ($d['items'] as $a => $x) {
            $x['trotz'] = isset(((array) get_option('sab_trotz', []))[$a]);
            $x['bonus'] = (int) (((array) get_option('sab_bonus', []))[$a] ?? 0);
            if ($speichern && empty($d['fehler'])) {
                $sc = self::score($x, true)[0];
                if (($v[$a][$heute] ?? null) !== $sc) { $v[$a][$heute] = $sc; $neu = true; }
                if (count($v[$a]) > 60) { ksort($v[$a]); $v[$a] = array_slice($v[$a], -60, null, true); }
            }
            $tage = array_filter((array) ($v[$a] ?? []), fn($k) => $k >= gmdate('Y-m-d', time() - (self::K_FENSTER - 1) * DAY_IN_SECONDS), ARRAY_FILTER_USE_KEY);
            unset($d['items'][$a]['k_anteil'], $d['items'][$a]['k_tage'], $d['items'][$a]['k_reihe']);
            if (count($tage) >= self::K_MIN) {
                $gut = count(array_filter($tage, fn($t) => $t >= self::K_SCHWELLE));
                $d['items'][$a]['k_anteil'] = $gut / count($tage); $d['items'][$a]['k_tage'] = count($tage);
            }
            if ($tage) { ksort($tage); $d['items'][$a]['k_reihe'] = array_values($tage); }
        }
        if ($neu) { update_option('sab_verlauf', $v, false); }
        return $d;
    }

    const ALARM_FALL = 20;   // so viele Score-Punkte unter dem Mittel der Vortage gelten als Absturz

    /** Absturz im Score-Verlauf: letzter Wert mindestens ALARM_FALL unter dem Mittel der bis zu 7 Tage davor (mindestens 2 Vortage nötig). @param array<string,int> $reihe Datum => Score @return array{0:int,1:int}|null [Mittel davor, jetzt] */
    public static function alarm_fall(array $reihe): ?array {
        ksort($reihe); $jetzt = end($reihe); if ($jetzt === false) { return null; }
        $davor = array_slice(array_slice($reihe, 0, -1, true), -7, null, true);
        if (count($davor) < 2) { return null; }
        $m = (int) round(array_sum($davor) / count($davor));
        return $m - (int) $jetzt >= self::ALARM_FALL ? [$m, (int) $jetzt] : null;
    }

    /** Prüft alle verwendeten Produkte auf Absturz oder Nicht-Lieferbarkeit und merkt sich die Alarme samt bereitstehendem Ersatz. @return array<string,array> */
    public static function alarm_berechnen(): array {
        $land = self::land(''); $d = (array) self::uebersicht_daten($land, false); $v = (array) get_option('sab_verlauf', []); $trotz = (array) get_option('sab_trotz', []); $alt = (array) get_option('sab_alarm', []); $neu = []; $abfall = [];
        foreach (self::alle_asins() as $a) {
            if (!empty($d['fehler'])) { continue; }
            // Rot oben nur, wenn Amazon das Produkt nicht mehr kennt (Bildausfall meldet bild_hinweis). Score-Abfall und „nicht lieferbar“ sind nur Anlass zum Tausch und laufen über den Reiter Austausch.
            $grund = isset($d['items'][$a]) || !self::asin_weg((string) $a, $land) ? '' : 'Amazon kennt das Produkt nicht mehr';
            if (isset($d['items'][$a]) && ($f = self::alarm_fall((array) ($v[$a] ?? []))) !== null) { $abfall[$a] = $f[1]; }   // nur als kleiner Hinweis im Plugin
            if ($grund === '') { continue; }
            $er = self::ersatz_fuer($a, $land); arsort($er); $er = array_keys($er);   // „neu“ vor „geprüft“ sortiert alphabetisch, daher unten gezielt geprüfte zuerst
            $ge = array_values(array_filter($er, fn($b) => (self::ersatz_fuer($a, $land)[$b] ?? '') === 'geprüft'));
            $neu[$a] = ['seit' => $alt[$a]['seit'] ?? time(), 'grund' => $grund, 'ersatz' => array_slice(array_merge($ge, array_diff($er, $ge)), 0, 3)];
        }
        update_option('sab_alarm', $neu, false); update_option('sab_score_abfall', $abfall, false);
        return $neu;
    }

    /** Roter Hinweis auf den Seiten des Plugins, solange Produkte offen sind, die Amazon nicht mehr kennt. */
    public static function alarm_hinweis(): void {
        if (!function_exists('current_user_can') || !current_user_can('manage_options') || strpos((string) ($_GET['page'] ?? ''), 'sab') !== 0) { return; }
        $ab_ = (array) get_option('sab_score_abfall', []);
        if ($ab_) { echo '<div class="notice notice-info inline" style="padding:4px 12px;margin:6px 0"><p style="margin:2px 0"><small>' . count($ab_) . ' Produkt(e) mit Score-Abfall (nur ein Hinweis, kein Muss): <a href="' . esc_url(admin_url('admin.php?page=sab-austausch')) . '">im Austausch ansehen</a></small></p></div>'; }
        $al = (array) get_option('sab_alarm', []); if (!$al) { return; }
        echo '<div class="notice notice-info inline" style="padding:4px 12px;margin:6px 0"><p style="margin:2px 0"><small>' . count($al) . ' Produkt(e), die Amazon nicht mehr kennt (nur ein Hinweis): <a href="' . esc_url(admin_url('admin.php?page=sab-austausch')) . '">im Austausch ansehen</a></small></p></div>';
    }

    /** Produkte ohne Handdaten oder mit Handdaten älter als 30 Tage: [ASIN => Hinweis]. */
    private static function handdaten_fehlen(array $d): array {
        $m = self::manuell_daten(); $out = [];
        $erledigt = array_flip(array_merge(array_keys(self::tausch_regeln()), array_column((array) get_option('sab_tausch_log', []), 'alt')));   // schon ersetzt: nicht mehr messen
        foreach (self::alle_asins() as $a) {
            if (isset($erledigt[$a])) { continue; }
            $it = $d['items'][$a] ?? null;
            $weg = !$it || (empty($it['lieferbar']) && empty($it['trotz'])) || ($it['status'] ?? '') === 'OUT_OF_STOCK' || ($it['preis'] ?? '') === '';
            if (isset($m[$a]) && (!empty($m[$a]['gelesen_weg']) || ($weg && (string) ($m[$a]['manuell_stand'] ?? '') !== ''))) { continue; }   // einmal gelesen und bei Amazon nicht verfügbar: bleibt weg, nicht erneut suchen
            if (!isset($m[$a])) { $out[$a] = 'keine Handdaten'; continue; }
            $st = (string) ($m[$a]['manuell_stand'] ?? ''); $t = $st !== '' ? strtotime($st) : false;
            if (!$t) { $out[$a] = 'ohne Datum'; }
            elseif (time() - $t > 30 * DAY_IN_SECONDS) { $out[$a] = 'Stand ' . $st . ', älter als 30 Tage'; }
            else {
                $fehlt = [];
                if ($m[$a]['sterne'] === null && empty($m[$a]['ohne_bewertung'])) { $fehlt[] = 'Sterne'; }
                if ($m[$a]['liefertage'] === null) { $fehlt[] = 'Lieferzeit'; }
                if ($fehlt) { $out[$a] = 'es fehlen: ' . implode(', ', $fehlt); }
            }
        }
        return $out;
    }

    /** Zeichnet den Verlauf der Scores als kleine Balken (Sparkline) nach Farbe. */
    private static function sparkline(array $reihe): string {
        if (!$reihe) { return ''; }
        $h = '<span class="sab-spark" title="Score pro Tag, letzte ' . count($reihe) . ' Tage">';
        foreach (array_slice($reihe, -30) as $v) { $h .= '<i style="height:' . max(2, (int) round(max(0, min(100, $v)) * 0.16)) . 'px;background:' . self::score_farbe((int) $v) . '"></i>'; }
        return $h . '</span>';
    }

    /** Farbverlauf des Scores: rot bis 39, orange bis 59, gelbgrün bis 79, grün ab 80. */
    private static function score_farbe(int $p): string {
        return $p >= 80 ? '#1a7f37' : ($p >= 60 ? '#6a9a1f' : ($p >= 40 ? '#b36b00' : '#a00'));
    }

    /** Schätzt die Größe einer Kategorie aus den eigenen Daten: Hauptkategorie Jardín fest 1,5 Mio., sonst doppelter größter beobachteter Rang (mindestens 2000). Eingetragene Größen haben Vorrang. */
    private static function mit_kat_schaetzung(array $d): array {
        if (empty($d['items'])) { return $d; }
        $max = [];
        foreach ($d['items'] as $x) { $k = mb_strtolower((string) ($x['kategorie'] ?? '')); if ($k !== '' && isset($x['rang'])) { $max[$k] = max($max[$k] ?? 0, (int) $x['rang']); } }
        foreach ($d['items'] as $a => $x) {
            unset($d['items'][$a]['kat_groesse_s']);
            $k = mb_strtolower((string) ($x['kategorie'] ?? ''));
            if ($k === '' || !isset($max[$k])) { continue; }
            $d['items'][$a]['kat_groesse_s'] = preg_match('/^jard[ií]n$/iu', $k) ? 1500000 : max(2000, 2 * $max[$k]);
        }
        return $d;
    }

    /** Provisionssätze je Kategorie (Einstellung, eine Zeile „Kategorie | Prozent“; „*“ = Standardsatz); Schlüssel klein geschrieben. */
    private static function provisionen(): array {
        $out = [];
        foreach (preg_split('/\R/', (string) get_option('sab_provision', '')) as $z) {
            $t = array_map('trim', explode('|', $z));
            if (count($t) >= 2 && $t[0] !== '' && preg_match('/\d/', $t[1])) { $out[mb_strtolower($t[0])] = (float) str_replace(',', '.', preg_replace('/[^\d,.]/', '', $t[1])); }
        }
        return $out;
    }

    /** Provisionssatz in Prozent: der am Produkt gelesene Wert (7. Feld der Handdaten), sonst die Kategorie: genauer Name, sonst Namensteil, sonst „*“, sonst null. */
    private static function provision_satz(array $x): ?float {
        if (isset($x['provision']) && $x['provision'] !== null) { return (float) $x['provision']; }   // am Produkt selbst gelesen (SiteStripe), hat Vorrang
        return self::provision_kategorie($x);
    }

    /** Satz nur aus der Kategorietabelle (Reiter Score): genauer Name, sonst Namensteil, sonst „*“, sonst null. */
    private static function provision_kategorie(array $x): ?float {
        $t = self::provisionen(); if (!$t) { return null; }
        $k = mb_strtolower(trim((string) ($x['kategorie'] ?? '')));
        if ($k !== '') {
            if (isset($t[$k])) { return $t[$k]; }
            foreach ($t as $name => $satz) { if ($name !== '*' && (mb_strpos($k, $name) !== false || mb_strpos($name, $k) !== false)) { return $satz; } }
        }
        return $t['*'] ?? null;
    }

    /** Zahl der Produkte je Kategorie (Einstellung, eine Zeile „Kategorie | Anzahl“); Schlüssel klein geschrieben. */
    private static function kat_groessen(): array {
        $out = [];
        foreach (preg_split('/\R/', (string) get_option('sab_kat_groesse', '')) as $z) {
            $t = array_map('trim', explode('|', $z));
            if (count($t) >= 2 && $t[0] !== '' && (int) preg_replace('/\D/', '', $t[1]) > 0) { $out[mb_strtolower($t[0])] = (int) preg_replace('/\D/', '', $t[1]); }
        }
        return $out;
    }

    /** Zahl aus einem Preistext wie „12,99 €“ oder „€1.299,00“. */
    private static function preiszahl(string $p): ?float {
        if (!preg_match('/\d[\d.,]*/', $p, $m)) { return null; }
        $z = $m[0];
        if (strpos($z, ',') !== false && strpos($z, '.') !== false) { $z = strrpos($z, ',') > strrpos($z, '.') ? str_replace(',', '.', str_replace('.', '', $z)) : str_replace(',', '', $z); }
        elseif (strpos($z, ',') !== false) { $z = str_replace(',', '.', $z); }
        elseif (preg_match('/\.\d{3}$/', $z)) { $z = str_replace('.', '', $z); }
        return (float) $z;
    }

    /**
     * Von Hand gepflegte Daten, eine Zeile pro Produkt: „ASIN | Sterne | Anzahl | Prime (ja/nein) | Lieferzeit je Stadt | Datum“,
     * z. B. „B001AUKTYG | 4,1 | 588 | ja | Madrid 2, Barcelona 3, Sevilla 2 | 2026-10-24“. Leere Felder bleiben leer. @return array<string,array>
     */
    /** Lieferkosten-Feld (8. Feld der Handdaten): „gratis“/„0“/„Prime“ = 0, Betrag in der Landeswährung, leer/„?“ = unbekannt (null). */
    public static function lieferkosten_wert(string $v): ?float {
        $v = mb_strtolower(trim($v));
        if ($v === '' || $v === '?' || $v === '-') { return null; }
        if (preg_match('/gratis|kostenlos|free|frei|prime|^0+(?:[.,]0+)?$/u', $v)) { return 0.0; }
        return preg_match('/\d/', $v) ? (float) str_replace(',', '.', preg_replace('/[^\d,.]/', '', $v)) : null;
    }

    private static function manuell_daten(): array {
        $out = [];
        foreach (preg_split('/\R/', (string) get_option('sab_manuell', '')) as $z) {
            $t = array_map('trim', explode('|', $z));
            $a = self::asin($t[0] ?? ''); if (strlen($a) !== 10) { continue; }
            $zahl = fn($v) => $v !== '' && preg_match('/\d/', $v) ? (float) str_replace(',', '.', preg_replace('/[^\d,.]/', '', $v)) : null;
            $tage = null;
            if (preg_match_all('/(\d+(?:[.,]\d+)?)\s*(?:T|Tage?|d)?\b/u', preg_replace('/\p{L}+/u', ' ', (string) ($t[4] ?? '')), $m) && $m[1]) {
                $v = array_map(fn($n) => (float) str_replace(',', '.', $n), $m[1]); $tage = array_sum($v) / count($v);
            }
            $p = mb_strtolower($t[3] ?? '');
            $out[$a] = [
                'sterne' => $zahl($t[1] ?? ''), 'anzahl' => isset($t[2]) && $t[2] !== '' ? (int) preg_replace('/\D/', '', $t[2]) : null,
                'prime' => $p === '' || in_array($p, ['-', '?', 'nv', 'n/v', 'weg', 'nicht verfügbar'], true) ? null : in_array($p, ['ja', 'yes', 'j', '1', 'prime'], true),   // ja = Prime, nein = gelesen und kein Prime, „-“ = nicht von Amazon versendet, Prime über Händler möglich (unsicher, neutral gewertet), „nv“/„weg“ = bei Amazon nicht verfügbar, leer = unbekannt/ungeprüft
                'ohne_bewertung' => trim((string) ($t[1] ?? '')) === '' && trim((string) ($t[2] ?? '')) === '' && trim((string) ($t[5] ?? '')) !== '',
                'gelesen_weg' => in_array($p, ['nv', 'n/v', 'weg', 'nicht verfügbar'], true), 'prime_unsicher' => $p === '-', 'liefertage' => $tage,
                'lieferkosten' => self::lieferkosten_wert((string) ($t[7] ?? '')), 'liefertext' => $t[4] ?? '', 'manuell_stand' => $t[5] ?? '', 'provision' => isset($t[6]) && preg_match('/\\d/', $t[6]) ? (float) str_replace(',', '.', preg_replace('/[^\\d,.]/', '', $t[6])) : null,
            ];
        }
        return $out;
    }

    private static function mit_manuell(array $d): array {
        if (empty($d['items'])) { return $d; }
        foreach (self::manuell_daten() as $a => $m) { if (isset($d['items'][$a])) { $d['items'][$a] = array_merge($d['items'][$a], $m); } }
        return $d;
    }

    /** Preis im Vergleich: Verhältnis zum mittleren Preis der Produktgruppe (sonst der Kategorie), ab drei Produkten mit Preis. */
    private static function mit_preisindex(array $d): array {
        if (empty($d['items'])) { return $d; }
        $gruppen = self::gruppen(); $preise = [];
        foreach ($d['items'] as $a => $x) { $z = self::preiszahl((string) ($x['preis'] ?? '')); if ($z) { $preise[$a] = $z; } }
        $median = function (array $v): float { sort($v); $n = count($v); return $n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2; };
        foreach ($d['items'] as $a => &$x) {
            unset($x['preisrel']);
            if (!isset($preise[$a])) { continue; }
            $vgl = [];
            foreach ($gruppen as $liste) { if (in_array((string) $a, $liste, true)) { foreach ($liste as $b) { if (isset($preise[$b])) { $vgl[$b] = $preise[$b]; } } } }
            if (count($vgl) < 3) {
                $vgl = [];
                $kat = (string) ($x['kategorie'] ?? '');
                if ($kat !== '') { foreach ($d['items'] as $b => $y) { if ((string) ($y['kategorie'] ?? '') === $kat && isset($preise[$b])) { $vgl[$b] = $preise[$b]; } } }
            }
            if (count($vgl) >= 3) { $m = $median(array_values($vgl)); if ($m > 0) { $x['preisrel'] = round($preise[$a] / $m, 2); } }
        }
        unset($x);
        return $d;
    }

    /**
     * Produktscore 0 bis 100 aus den Amazon-Daten, gewichtet nach den Einstellungen (Reiter „Score“).
     * Der Rang zählt nur in Garten-Kategorien. Ohne Rang ist der Score auf 60 begrenzt.
     * @return array{0:int,1:string,2:array} [Score, Hinweis, Einzelposten [Name, Gewicht, Faktor 0..1, Punkte, Erklärung]]
     */
    private static function score(array $x, bool $ohne_k = false): array {
        $w = self::score_gewichte(); if ($ohne_k || !isset($x['k_anteil'])) { $w['konstanz'] = 0; } $summe = array_sum($w);   // ohne genug Verlauf zählt die Konstanz nicht
        $r = $x['rang'] ?? null; $fremd = false;
        $kat = (string) ($x['kategorie'] ?? '');
        $groesse = $kat !== '' ? (self::kat_groessen()[mb_strtolower($kat)] ?? null) : null; $geschaetzt = false;
        if ($groesse === null && isset($x['kat_groesse_s'])) { $groesse = (int) $x['kat_groesse_s']; $geschaetzt = true; }
        if ($r !== null && $kat !== '' && $groesse === null && !preg_match(self::GARTEN_KAT, $kat)) { $fremd = true; }   // andere Kategorie ohne bekannte Größe: Rang zählt abgeschwächt
        $f = [];
        if ($r === null) { $f['rang'] = [0.5, 'ohne Verkaufsrang']; }   // neue Produkte haben noch keinen Rang: neutral, nicht schlecht
        elseif ($groesse) {   // Rang im Verhältnis zur Zahl der Produkte der Kategorie
            $pz = max(1, $r) / max(1, $groesse);
            $f['rang'] = [max(0.0, min(1.0, 1 - log10(max($pz, 1e-4) / 1e-4) / log10(5000))), 'Platz ' . number_format((int) $r, 0, ',', '.') . ' von ' . ($geschaetzt ? 'ca. ' : '') . number_format((int) $groesse, 0, ',', '.') . ' (' . round(100 * $pz, 2) . ' %)'];
        } else {
            $f['rang'] = [max(0.0, min(1.0, 1 - log10(max(1, $r) / 100) / log10(5000))) * ($fremd ? 0.7 : 1.0), 'Platz ' . number_format((int) $r, 0, ',', '.') . ($fremd ? ' in ' . $kat . ' (Kategoriegröße unbekannt, 70 % gewertet)' : '')];
        }
        $f['buybox'] = !empty($x['kaufen']) ? [1.0, 'ja'] : [0.0, 'nein'];
        $st = (string) ($x['status'] ?? '');
        if (!empty($x['trotz']) || $st === 'IN_STOCK' || ($st === '' && !empty($x['lieferbar']))) { $f['angebot'] = [1.0, 'lieferbar']; }
        elseif ($st === 'IN_STOCK_SCARCE') { $f['angebot'] = [0.7, 'wenige Stück']; }
        elseif (!empty($x['lieferbar']) && $st !== 'OUT_OF_STOCK') { $f['angebot'] = [0.5, 'eingeschränkt']; }
        else { $f['angebot'] = [0.0, 'kein Angebot']; }
        if (($x['preis'] ?? '') === '') { $f['preis'] = [0.0, 'kein Preis']; }
        elseif (isset($x['preisrel'])) { $f['preis'] = [max(0.0, min(1.0, (1.5 - (float) $x['preisrel']) / 0.8)), round(100 * (float) $x['preisrel']) . ' % des Gruppenpreises']; }
        else { $f['preis'] = [0.5, 'kein Vergleich möglich']; }
        $f['streich'] = !empty($x['spar']['streich']) ? [1.0, 'vorhanden'] : [0.0, 'keiner'];
        $f['deal'] = !empty($x['deal']) ? [1.0, (string) $x['deal']] : [0.0, 'keine'];
        if (isset($x['sterne']) && $x['sterne'] !== null) {   // vorsichtig bewertet: wenige Bewertungen ziehen zum Mittelwert 4,0
            $n = (int) ($x['anzahl'] ?? 0); $st5 = ((float) $x['sterne'] * $n + 3.9 * 30) / ($n + 30);   // wenige Bewertungen ziehen stark zum Mittelwert 3,9, viele zählen voll
            // 3,6 Sterne = 0, 4,8 = 1: hohe Vierer-Werte werden deutlich besser eingestuft
            $f['bewertung'] = [max(0.0, min(1.0, ($st5 - 3.6) / 1.2)), str_replace('.', ',', (string) $x['sterne']) . ' ★ (' . $n . ')'];
        } else { $f['bewertung'] = [0.5, 'unbekannt']; }
        $f['konstanz'] = isset($x['k_anteil']) ? [max(0.0, min(1.0, (float) $x['k_anteil'])), round(100 * (float) $x['k_anteil']) . ' % der letzten ' . (int) ($x['k_tage'] ?? 0) . ' Tage mit Score ab ' . self::K_SCHWELLE] : [0.5, 'noch zu wenig Daten (ab ' . self::K_MIN . ' Tagen)'];
        $f['prime'] = isset($x['prime']) && $x['prime'] !== null ? [$x['prime'] ? 1.0 : 0.0, $x['prime'] ? 'ja' : 'nein'] : [0.5, 'unbekannt'];
        $f['liefer'] = isset($x['liefertage']) && $x['liefertage'] !== null ? [max(0.0, min(1.0, (7 - (float) $x['liefertage']) / 6)), 'Ø ' . str_replace('.', ',', (string) round((float) $x['liefertage'], 1)) . ' Tage'] : [0.5, 'unbekannt'];
        $lk = isset($x['lieferkosten']) && $x['lieferkosten'] !== null ? (float) $x['lieferkosten'] : null;   // Lieferkosten: gratis/Prime = voll, ab etwa 8 Geldeinheiten 0
        $f['versand'] = $lk !== null ? [max(0.0, min(1.0, 1 - $lk / 8)), $lk <= 0 ? 'gratis' : str_replace('.', ',', (string) round($lk, 2)) . ' Versand'] : (($x['prime'] ?? null) === true ? [1.0, 'Prime'] : [0.5, 'unbekannt']);
        $pv = self::provision_satz($x);
        $f['provision'] = $pv !== null ? [max(0.0, min(1.0, $pv / 10)), str_replace('.', ',', (string) $pv) . ' %'] : [0.5, 'unbekannt'];
        $roh = 0.0; $det = [];
        foreach (self::SCORE_STANDARD as $k => $_) {
            $pt = $summe > 0 ? 100 * $w[$k] * $f[$k][0] / $summe : 0;
            $roh += $pt; $det[] = [self::SCORE_NAMEN[$k], $w[$k], $f[$k][0], round($pt, 1), $f[$k][1]];
        }
        $bonus = (int) ($x['bonus'] ?? 0);
        $score = (int) round(50 + ($roh - 45) * 2.2) + $bonus;   // gespreizt um die Mitte: statt 30 bis 60 etwa 15 bis 85, Ausreißer dürfen leicht unter 0 oder über 100 liegen
        $hinweis = '';
        if ($r === null) { $hinweis = $f['rang'][1]; } elseif ($fremd) { $hinweis = 'Kategoriegröße von „' . $kat . '“ fehlt (Reiter Score)'; }
        if ($bonus !== 0 && $hinweis === '') { $hinweis = 'inkl. Bonus ' . ($bonus > 0 ? '+' : '') . $bonus; }
        if (self::unklar($x) && empty($x['trotz']) && $r !== null && $r <= 20000) { $hinweis = 'Schnittstelle prüfen: guter Rang, aber kein Angebot'; }
        return [max(-10, min(110, $score)), $hinweis, $det];
    }

    /**
     * Wie dringend ein Produkt getauscht werden muss (0 bis 100), unabhängig davon, wie oft es verlinkt ist: nicht lieferbar, kein Preis oder Prime-Status
     * unklar = 100; sonst bei schwachem Score bis etwa 70, bei sehr langer Lieferzeit 40, bei unter 3,5 Sternen 30; gute Produkte 0. Mal Seitenzahl ergibt die Priorität.
     */
    /** Potenzial zum Ausbauen: guter Score, sauber lieferbar, wenige Seiten; Favoriten zählen mehr. [Zahl, Begründung] */
    private static function potenzial(array $x, int $score, int $seiten, bool $fav): array {
        $nicht = empty($x['trotz']) && (empty($x['lieferbar']) || ($x['status'] ?? '') === 'OUT_OF_STOCK' || ($x['preis'] ?? '') === '');
        if ($nicht || $score < 60) { return [0, '']; }
        if (array_key_exists('manuell_stand', $x) && ($x['prime'] ?? null) === null && empty($x['prime_unsicher'])) { return [0, '']; }
        if (isset($x['liefertage']) && $x['liefertage'] !== null && (float) $x['liefertage'] > 7) { return [0, '']; }
        if ($seiten >= 10) { return [0, 'kommt schon auf ' . $seiten . ' Seiten vor']; }
        $p = (int) round(($score - 50) * (10 - $seiten) / 10 * ($fav ? 1.5 : 1));
        return [$p, 'Score ' . $score . ', erst auf ' . $seiten . ' Seiten' . ($fav ? ', Favorit' : '')];
    }

    const DRINGLICHKEIT_STANDARD = ['schwelle' => 35, 'faktor' => 2.0, 'tage' => 14.0, 'tage_dr' => 40, 'sterne' => 3.5, 'sterne_dr' => 30];

    /** Einstellbare Werte der Dringlichkeit (Reiter Score). */
    private static function dringlichkeit_werte(): array {
        $o = (array) get_option('sab_dringlichkeit', []); $out = [];
        foreach (self::DRINGLICHKEIT_STANDARD as $k => $std) { $out[$k] = isset($o[$k]) && is_numeric($o[$k]) ? (float) $o[$k] : $std; }
        return $out;
    }

    /** Braucht das Produkt an irgendeiner Stelle ein Bild? Steht es nur als Textlink im Artikel (z. B. unter einem Video), ist ein fehlendes Amazon-Bild kein Austauschgrund. Ohne Fundstelle: ja (vorsichtig). */
    public static function braucht_bild(string $asin): bool {
        static $menge = null, $gesehen = null;
        if ($menge === null) {
            $menge = []; $gesehen = []; global $wpdb;
            if (isset($wpdb) && method_exists($wpdb, 'get_col')) {
                $rows = (array) $wpdb->get_col("SELECT post_content FROM {$wpdb->posts} WHERE post_status IN ('publish','future') AND post_type IN ('post','page') AND (post_content LIKE '%amazon.%' OR post_content LIKE '%[amz\\_%' OR post_content LIKE '%[aawp%' OR post_content LIKE '%[amazon %')");
                foreach ($rows as $c) { [$m_, $g_] = self::bild_bedarf_text((string) $c); $menge += $m_; $gesehen += $g_; }
            }
        }
        return isset($menge[$asin]) || !isset($gesehen[$asin]);
    }

    /** Aus einem Artikeltext: welche ASINs mit Bild verwendet werden (Bild-/Produkt-/Kartenplatzhalter, AAWP-Bild, Bildlink) und welche überhaupt vorkommen. @return array{0:array<string,bool>,1:array<string,bool>} */
    public static function bild_bedarf_text(string $c): array {
        $mit = []; $alle = [];
        if (preg_match_all('/\b(?:asin|fields|box|link|data-sab-asin)\s*=\s*["\']?([A-Za-z0-9]{10})\b|\/(?:dp|gp\/product|product)\/([A-Za-z0-9]{10})\b/i', $c, $m, PREG_SET_ORDER)) { foreach ($m as $x) { $alle[strtoupper(($x[1] ?? '') !== '' ? $x[1] : ($x[2] ?? ''))] = true; } }
        if (preg_match_all('/\[amz_(?:bild|produkt|karte)\b[^\]]*?\basin=["\']([A-Za-z0-9]{10})["\']/i', $c, $m)) { foreach ($m[1] as $a) { $mit[strtoupper($a)] = true; } }
        if (preg_match_all('/\[(?:aawp|amazon)\b[^\]]*\b(?:fields|box)=["\']([^"\']+)["\'][^\]]*\]/i', $c, $m)) { foreach ($m[0] as $i => $whole) { if (preg_match('/box=|thumb|image/i', $whole)) { foreach (explode(',', $m[1][$i]) as $a) { $mit[strtoupper(trim($a))] = true; } } } }
        if (preg_match_all('#<a\b[^>]*?(?:/dp/|/gp/product/|/product/)([A-Za-z0-9]{10})[^>]*>\s*(?:<[^>]+>\s*)*<img\b#i', $c, $m)) { foreach ($m[1] as $a) { $mit[strtoupper($a)] = true; } }
        return [$mit, $alle];
    }

    private static function tausch_dringlichkeit(array $x, int $score, bool $braucht_bild = true): int {
        $nicht = empty($x['trotz']) && (empty($x['lieferbar']) || ($x['status'] ?? '') === 'OUT_OF_STOCK' || ($x['preis'] ?? '') === '');
        if ($nicht || ($braucht_bild && array_key_exists('bilder', $x) && empty($x['bilder']) && empty($x['bild']))) { return 100; }   // nicht lieferbar, kein Preis oder kein Bild
        $q = self::dringlichkeit_werte();
        $primeUnklar = array_key_exists('manuell_stand', $x) && ($x['prime'] ?? null) === null;
        if ($primeUnklar && (($x['preis'] ?? '') === '' || ($x['kaufen'] ?? null) === false)) { return 100; }   // ohne Preis oder Buy Box und ohne Prime: wohl nicht mehr zu haben
        $d = max(0, $score < $q['schwelle'] ? (int) round(($q['schwelle'] - $score) * $q['faktor']) : 0);
        if (isset($x['liefertage']) && $x['liefertage'] !== null && (float) $x['liefertage'] > $q['tage']) { $d = max($d, $q['tage_dr']); }
        if (isset($x['sterne']) && $x['sterne'] !== null && (float) $x['sterne'] < $q['sterne']) { $d = max($d, $q['sterne_dr']); }
        return min(100, $d);
    }

    /** Kleine Balkengrafik der Score-Bestandteile (ein Balken je Faktor, Höhe = Wert 0 bis 1) und die schwächsten Gründe als Text. Rot = schwach, grün = stark. */
    private static function score_grafik(array $det): string {
        $b = ''; $schwach = [];
        foreach ($det as [$name, $gew, $wert, $pkt, $text]) {
            if ((float) $gew <= 0) { continue; }
            $hoehe = max(2, (int) round(18 * (float) $wert));
            $farbe = $wert < 0.35 ? '#d63638' : ($wert < 0.65 ? '#dba617' : '#2e9a47');
            $b .= '<span title="' . esc_attr($name . ': ' . $text . ' (' . round((float) $wert * 100) . ' von 100)') . '" style="display:inline-block;width:7px;height:18px;margin-right:2px;background:#eee;position:relative;vertical-align:bottom"><span style="position:absolute;left:0;right:0;bottom:0;height:' . $hoehe . 'px;background:' . $farbe . '"></span></span>';
            if ($wert < 0.35) { $schwach[] = [$wert * $gew, $name . ' (' . $text . ')']; }
        }
        usort($schwach, fn($p, $q) => $p[0] <=> $q[0]);
        $g = $schwach ? '<br><small style="color:#a00" title="Diese Bestandteile drücken den Score am stärksten">schwach: ' . esc_html(implode(', ', array_map(fn($z) => $z[1], array_slice($schwach, 0, 3)))) . '</small>' : '';
        return '<span style="display:inline-block;line-height:1">' . $b . '</span>' . $g;
    }

    /** Rechts neben dem zu tauschenden Produkt: Score (farbig), Preis, Sterne, Prime, Lieferzeit, Rang, Provision und die Grafik der Score-Bestandteile. */
    private static function alt_kennzahlen(array $x, string $asin, int $seiten): string {
        if (!$x) { return '<div style="margin-left:auto;color:#a00"><small>Amazon liefert keine Daten (mehr)</small></div>'; }
        $trotz = (array) get_option('sab_trotz', []); $x['trotz'] = isset($trotz[$asin]);
        [$sc, , $det] = self::score($x); $pv = self::provision_satz($x);
        $t = [];
        $t[] = ($x['preis'] ?? '') !== '' ? esc_html((string) $x['preis']) : '<span style="color:#a00">kein Preis</span>';
        if (isset($x['sterne']) && $x['sterne'] !== null) { $t[] = '&#9733; ' . esc_html(str_replace('.', ',', (string) $x['sterne'])) . ' (' . (int) ($x['anzahl'] ?? 0) . ')'; }
        $t[] = self::prime_zeichen(isset($x['prime']) ? $x['prime'] : null, !empty($x['prime_unsicher']));
        if (isset($x['liefertage']) && $x['liefertage'] !== null) { $t[] = '&#128666; ' . esc_html(str_replace('.', ',', (string) round((float) $x['liefertage'], 1))) . ' T'; }
        if (isset($x['lieferkosten']) && $x['lieferkosten'] !== null) { $t[] = (float) $x['lieferkosten'] <= 0 ? 'Versand gratis' : 'Versand ' . esc_html(str_replace('.', ',', (string) round((float) $x['lieferkosten'], 2))); }
        if (isset($x['rang']) && $x['rang'] !== null) { $t[] = 'Rang ' . number_format((int) $x['rang'], 0, ',', '.'); }
        $t[] = $pv !== null ? esc_html(str_replace('.', ',', (string) $pv)) . ' % Prov.' : 'Prov. ?';
        return '<div style="margin-left:auto;text-align:right;min-width:260px"><span style="font-size:20px;font-weight:700;color:' . self::score_farbe($sc) . '" title="Score des alten Produkts">Score ' . (int) $sc . '</span><br><small>' . implode(' · ', $t) . '</small><br>' . self::score_grafik($det) . '</div>';
    }

    /** Prime als Schildchen: blau mit Haken, bei „kein Prime“ grau und durchgestrichen, bei unbekannt gestrichelt (eigene Gestaltung, nicht das Amazon-Logo). */
    private static function prime_zeichen($prime, bool $unsicher = false): string {
        $b = 'display:inline-block;padding:0 7px;border-radius:4px;font-size:11px;font-weight:700;font-style:italic;line-height:17px;vertical-align:baseline;';
        if ($prime === null && $unsicher) { return '<span style="' . $b . 'border:1px dashed #b36b00;color:#b36b00" title="Nicht von Amazon versendet; Prime über den Händler ist möglich, aber nicht sicher">prime unsicher</span>'; }
        if ($prime === null) { return '<span style="' . $b . 'border:1px dashed #999;color:#888" title="Prime unbekannt">prime ?</span>'; }
        return $prime
            ? '<span style="' . $b . 'background:#0a6fb7;color:#fff" title="Prime">&#10003; prime</span>'
            : '<span style="' . $b . 'background:#eee;color:#999;text-decoration:line-through" title="kein Prime">prime</span>';
    }

    private static function scorezelle(array $x): string {
        [$p, $h] = self::score($x);
        $farbe = self::score_farbe($p);
        return '<strong style="color:' . $farbe . ';font-size:1.2em">' . $p . '</strong>' . ($h !== '' ? '<br><small>' . esc_html($h) . '</small>' : '');
    }

    /** Sortieren per Klick auf die Überschrift und feststehende Kopfzeile für alle Tabellen mit der Klasse „sab-sort2“. */
    private static function tabellenhilfe(): string {
        return '<style>table.sab-sort2 thead th{position:sticky;top:32px;z-index:3;background:#f6f7f7;cursor:pointer;box-shadow:0 1px 0 #c3c4c7}table.sab-sort2 thead th.sab-auf:after{content:" \\25B2"}table.sab-sort2 thead th.sab-ab:after{content:" \\25BC"}</style>'
             . '<script>(function(){var sp=new URLSearchParams(location.search),sk=sp.get("sab_sort"),sd=sp.get("sab_dir")||"desc";function v(c){if(!c)return "";var d=c.getAttribute("data-v");if(d!==null)return d===""?"":parseFloat(d);var i=c.querySelector("input[type=checkbox]");if(i)return i.checked?1:0;var t=c.textContent.trim().toLowerCase();var n=parseFloat(t.replace(/\\./g,"").replace(",",".").replace(/[^0-9.\\-]/g,""));return isNaN(n)||!/^[^a-z]*[0-9]/.test(t)?t:n}'
             . 'document.querySelectorAll("table.sab-sort2").forEach(function(T){var H=T.tHead&&T.tHead.rows[0];if(!H)return;T.sabK=[];[].forEach.call(H.cells,function(th,i){if(sk&&th.getAttribute("data-sort")===sk){th.classList.add(sd==="asc"?"sab-auf":"sab-ab")}th.addEventListener("click",function(){if(th.getAttribute("data-sort")){var u=new URL(location.href),nd=(u.searchParams.get("sab_sort")===th.getAttribute("data-sort"))?(sd==="desc"?"asc":"desc"):"desc";u.searchParams.set("sab_sort",th.getAttribute("data-sort"));u.searchParams.set("sab_dir",nd);u.searchParams.set("sab_s","1");location.href=u.toString();return}var b=T.tBodies[0],r=[].slice.call(b.rows),d;'
             . 'var num=r.some(function(e){var x=v(e.cells[i]);return typeof x==="number"});var alt=T.sabK.filter(function(k){return k.i===i})[0];d=alt?-alt.d:(num?-1:1);'
             . '[].forEach.call(H.cells,function(x){x.classList.remove("sab-auf","sab-ab")});th.classList.add(d===1?"sab-auf":"sab-ab");'
             . 'T.sabK=[{i:i,d:d}].concat(T.sabK.filter(function(k){return k.i!==i}));'   // frühere Sortierungen bleiben als Zweit- und Drittordnung erhalten
             . 'r.sort(function(x,y){for(var q=0;q<T.sabK.length;q++){var k=T.sabK[q],a=v(x.cells[k.i]),c=v(y.cells[k.i]);if(a===""&&c!=="")return 1;if(c===""&&a!=="")return -1;if(a<c)return -k.d;if(a>c)return k.d}return 0});r.forEach(function(e){b.appendChild(e)})})})})})();</script>';
    }

    private static function sparzeile(array $x): string {
        $sp = (array) ($x['spar'] ?? []);
        if (empty($sp['streich']) && empty($sp['ersparnis'])) { return ''; }
        return '<br><small><s>' . esc_html($sp['streich'] ?: '') . '</s> ' . ($sp['ersparnis'] ? 'spart ' . esc_html($sp['ersparnis']) : '') . (isset($sp['prozent']) ? ' (' . (int) $sp['prozent'] . ' %)' : '') . '</small>';
    }

    private static function primezelle(array $x): string {
        return !empty($x['deal']) ? '<strong style="color:#c7511f">' . esc_html($x['deal']) . '</strong>' : '–';
    }

    /** Verkaufsrang je Kategorie: niedrig = wird oft gekauft. Grobe Farbe als Hinweis (Rang gilt nur innerhalb der Kategorie). */
    private static function rangzelle(array $x): string {
        $r = $x['rang'] ?? null; $k = (string) ($x['kategorie'] ?? '');
        if ($r === null && $k === '') { return '–'; }
        $farbe = $r === null ? '#555' : ($r <= 5000 ? '#1a7f37' : ($r <= 50000 ? '#b36b00' : '#a00'));
        return esc_html($k) . ($r !== null ? '<br><strong style="color:' . $farbe . '">Platz ' . number_format((int) $r, 0, ',', '.') . '</strong>' : '');
    }

    /** „Nicht auf Lager“ ohne Preis und ohne Verkäufer: Amazons Schnittstelle liefert dann oft nur ein Rest-Angebot, auf Amazon selbst ist das Produkt trotzdem kaufbar. */
    private static function unklar(array $x): bool {
        return ($x['status'] ?? '') === 'OUT_OF_STOCK' && ($x['preis'] ?? '') === '' && ($x['verkaeufer'] ?? '') === '';
    }

    /** Zelle „Lieferbar“: klare Aussage aus der Amazon-Verfügbarkeit (type/message), nicht nur „es gibt ein Angebot“. */
    /** Zelle „Prov.“ der Übersicht: Satz der Kategorie und geschätzte Provision pro Verkauf. */
    private static function provisionszelle(array $x): string {
        $pv = self::provision_satz($x);
        if ($pv === null) { return '<td class="sab-zahl" data-v=""><span style="display:inline-block;padding:2px 8px;border-radius:10px;background:#fbe3e3;color:#a00;font-weight:700" title="Weder am Produkt (Handdaten, 7. Feld) noch in der Kategorietabelle (Reiter Score) steht ein Satz">fehlt</span></td>'; }
        [$bg, $fg] = $pv >= 7 ? ['#e3f4e7', '#1a7f37'] : ($pv >= 4 ? ['#fdf0d8', '#8a5a00'] : ['#fbe3e3', '#a00']);
        $pz = self::preiszahl((string) ($x['preis'] ?? '')); preg_match('/[€£$]/u', (string) ($x['preis'] ?? ''), $w);
        return '<td class="sab-zahl" data-v="' . $pv . '"><span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:15px;font-weight:700;background:' . $bg . ';color:' . $fg . '" title="' . (isset($x['provision']) && $x['provision'] !== null ? 'am Produkt gelesen (SiteStripe)' : 'laut Kategorie') . '">' . esc_html(str_replace('.', ',', (string) $pv)) . ' %</span>'
             . ($pz !== null ? '<br><small>≈ ' . esc_html(number_format($pz * $pv / 100, 2, ',', '.') . ' ' . ($w[0] ?? '')) . ' je Verkauf</small>' : '')
             . (isset($x['provision']) && $x['provision'] !== null && ($pk = self::provision_kategorie($x)) !== null && abs($pk - (float) $x['provision']) > 0.001 ? '<br><small style="color:#a00" title="Der am Produkt gelesene Satz weicht von der Kategorietabelle ab: Amazon hat Sätze geändert oder das Produkt ist eine Ausnahme">≠ Kategorie ' . esc_html(str_replace('.', ',', (string) $pk)) . ' %</small>' : '') . '</td>';
    }

    private static function lieferzelle(array $x): string {
        $st = (string) ($x['status'] ?? ''); $msg = trim((string) ($x['meldung'] ?? ''));
        if (!empty($x['trotz']) && ($st === 'OUT_OF_STOCK' || empty($x['lieferbar']))) { return '<strong style="color:#1a7f37">ja</strong><br><small>von dir auf Amazon geprüft (Schnittstelle meldet: ' . esc_html($msg ?: 'nicht auf Lager') . ')</small>'; }
        $zusatz = $msg !== '' ? '<br><small>' . esc_html($msg) . '</small>' : '';
        if (empty($x['lieferbar'])) { return '<strong style="color:#a00">nein</strong><br><small>Amazon liefert kein Angebot</small>'; }
        if (self::unklar($x)) {
            return '<strong style="color:#b36b00">unklar</strong><br><small>Amazon meldet „' . esc_html($msg ?: 'nicht auf Lager') . '“, aber ohne Preis und Verkäufer. Bitte auf Amazon selbst prüfen; das Plugin zeigt das Produkt weiter.' . (isset($x['rang']) && $x['rang'] <= 20000 ? ' Der Verkaufsrang ' . number_format((int) $x['rang'], 0, ',', '.') . ' spricht dafür, dass es lieferbar ist.' : '') . '</small>'
                 . '<details><summary><small>Rohdaten</small></summary><code style="font-size:10px;word-break:break-all">' . esc_html((string) ($x['roh'] ?? '')) . '</code></details>';
        }
        if ($st === 'OUT_OF_STOCK') {
            return '<strong style="color:#a00">nein</strong><br><small>Amazon: nicht auf Lager (Standard-Lieferort)</small>' . $zusatz
                 . '<details><summary><small>Rohdaten</small></summary><code style="font-size:10px;word-break:break-all">' . esc_html((string) ($x['roh'] ?? '')) . '</code></details>';
        }
        if ($st === 'IN_STOCK' || $st === '') { return 'ja' . $zusatz; }
        return '<strong style="color:#b36b00">eingeschränkt</strong><br><small>' . esc_html($st) . '</small>' . $zusatz; // z. B. Lieferzeit, Vorbestellung
    }

    /** Angebot mit Kaufen-Button, sonst das erste mit Preis, sonst das erste. */
    private static function bestes_angebot(array $it): ?array {
        $ls = (array) ($it['offersV2']['listings'] ?? []);
        // Angebote, die Amazon als nicht auf Lager meldet, nur nehmen, wenn es kein anderes gibt (sonst falsches „nicht verfügbar“)
        $da = fn($l) => (string) ($l['availability']['type'] ?? '') !== 'OUT_OF_STOCK';
        foreach ($ls as $l) { if ($da($l) && !empty($l['isBuyBoxWinner']) && !empty($l['price']['money']['displayAmount'])) { return $l; } }
        foreach ($ls as $l) { if ($da($l) && !empty($l['price']['money']['displayAmount'])) { return $l; } }
        foreach ($ls as $l) { if ($da($l)) { return $l; } }
        foreach ($ls as $l) { if (!empty($l['isBuyBoxWinner']) && !empty($l['price']['money']['displayAmount'])) { return $l; } }
        foreach ($ls as $l) { if (!empty($l['price']['money']['displayAmount'])) { return $l; } }
        return $ls[0] ?? null;
    }

    /** Hauptbild zuerst, dann die weiteren Bilder (höchstens 6), nur Adressen. */
    private static function bilderliste(array $it): array {
        $out = [];
        if (!empty($it['images']['primary']['large']['url'])) { $out[] = $it['images']['primary']['large']['url']; }
        foreach ((array) ($it['images']['variants'] ?? []) as $v) {
            if (!empty($v['large']['url'])) { $out[] = $v['large']['url']; }
        }
        return array_slice($out, 0, 6);
    }

    /** Zeilen "ASIN: 1 | 3" => [ASIN => [1, 3]] (Bildnummern, 1 = Hauptbild; 2 bis 4 Bilder) */
    private static function bildtests(): array {
        $out = [];
        foreach (preg_split('/\R/', (string) get_option('sab_bildtests', '')) as $z) {
            if (strpos($z, ':') === false) { continue; }
            [$a, $l] = array_map('trim', explode(':', $z, 2));
            $a = self::asin($a);
            $n = array_values(array_unique(array_filter(array_map('intval', explode('|', $l)), fn($x) => $x >= 1 && $x <= 6)));
            if (strlen($a) === 10 && count($n) >= 2 && count($n) <= 4) { $out[$a] = $n; }
        }
        return $out;
    }

    /** Bildvariante für diesen Artikel: [Bildindex, Buchstabe]; ohne Test [0, '']. Stabil je Artikel, ohne Cookie. */
    private static function bildvariante(string $asin, array $p): array {
        $t = self::bildtests()[$asin] ?? null;
        if (!$t) { return [0, '']; }
        $pid = function_exists('get_the_ID') ? (int) get_the_ID() : 0;
        $i = (int) (abs(crc32($pid . '|bild-' . $asin)) % count($t));
        $idx = $t[$i] - 1;
        if ($idx > 0 && empty($p['bilder'][$idx])) { return [0, '']; }
        return [$idx, chr(65 + $i)];
    }

    /** Produkt hat Bild und Link (auch wenn Amazon es gerade nicht liefern kann): dann zeigen wir die Karte trotzdem, statt dass sie verschwindet. */
    private static function hat_bild(?array $p): bool {
        return $p && !empty($p['bild']) && !empty($p['link']);
    }

    /** Neue Produkte aus dem Tausch, deren Kurzname noch der automatische (oder keiner) ist. Erledigte verschwinden von selbst. */
    private static function name_offen_html(): string {
        $of = (array) get_option('sab_name_offen', []); if (!$of) { return ''; }
        $prod = self::produkte(); $rest = []; $zeilen = [];
        foreach ($of as $a => $auto) {
            $akt = (string) ($prod[$a] ?? '');
            if ($akt !== '' && $akt !== (string) $auto) { continue; }   // inzwischen ein eigener Name vergeben
            $rest[$a] = $auto;
            $zeilen[] = '<li><a href="' . esc_url('https://' . self::LAENDER[self::land('')][0] . '/dp/' . $a) . '" target="_blank" rel="noopener"><code>' . esc_html((string) $a) . '</code></a> · jetzt: ' . ($akt !== '' ? esc_html($akt) : '<em>kein Name</em>') . '</li>';
        }
        if (count($rest) !== count($of)) { update_option('sab_name_offen', $rest, false); }
        if (!$rest) { return ''; }
        return '<div class="notice notice-warning inline"><p><strong>Kurzname prüfen (' . count($rest) . '):</strong> Diese neu getauschten Produkte haben noch keinen eigenen Kurznamen (Stil „Produktart | Merkmal“). Die Mac-Sitzung trägt ihn bei „Produkte und Ersatzlisten“ ein.</p><ul style="margin-left:18px;list-style:disc">' . implode('', array_slice($zeilen, 0, 40)) . '</ul></div>';
    }

    /** Steht dieses Produktbild wirklich noch aus? Frisch eingetragene Produkte werden zuerst sofort abgerufen (höchstens $budget je Aufruf, je ASIN nur alle 5 Minuten), nie nur wegen eines noch fehlenden Zwischenspeichers gemeldet. */
    private static function bild_noch_offen(array $e, string $land, int &$budget): ?bool {   // true = fehlt, false = behoben, null = Abruf läuft noch (merken, nicht melden)
        $asin = (string) $e['asin']; $p = get_transient("sab_{$land}_" . $asin); $p = is_array($p) ? $p : null;
        if (self::hat_bild($p)) { return false; }
        if (!self::braucht_bild($asin)) { return false; }   // steht nur als Textlink im Artikel: kein Bild nötig
        if (self::getauscht($asin) !== $asin) { return false; }   // inzwischen durch ein anderes Produkt ersetzt (Austausch-Regel): die Meldung gilt dem neuen
        $pc = (int) ($e['post'] ?? 0); if ($pc > 0 && function_exists('get_post_field') && strpos((string) get_post_field('post_content', $pc), $asin) === false) { return false; }   // steht gar nicht mehr im Artikel (schon getauscht oder gelöscht)
        $bf = self::bild_pfad($land, $asin, 0); if (is_file($bf) && time() - (int) filemtime($bf) <= self::BILD_MAX) { return false; }   // Bild liegt in der Mediathek: kein Fehler
        if ($p === null) {
            $v = "sab_bf_{$land}_" . $asin;
            if ($budget > 0 && !get_transient($v)) { $budget--; set_transient($v, 1, 5 * MINUTE_IN_SECONDS); $p = self::produkt($asin, $land, false, true); if (self::hat_bild($p)) { return false; } }
            if ($p === null && time() - (int) ($e['ts'] ?? 0) < 15 * MINUTE_IN_SECONDS) { return null; }   // gerade erst eingetragen, Abruf läuft noch: noch kein Alarm
        }
        return true;
    }

    private static function kurz_wort(string $t, int $n = 3): string {
        $w = preg_split('/\s+/u', trim(strip_tags($t)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return implode(' ', array_slice($w, 0, $n)) . (count($w) > $n ? ' …' : '');
    }

    /** Rote Warnung über fehlenden Produktbildern (Priorität vor allem anderen). Behobene Fälle verschwinden von selbst. */
    private static function bildfehler_html(): string {
        $l = (array) get_option('sab_bildfehler', []); if (!$l) { return ''; }
        $land = self::land(''); $rest = []; $nach = []; $budget = 4;
        foreach ($l as $k => $e) {
            $of_ = self::bild_noch_offen($e, $land, $budget); if ($of_ === false) { continue; }   // Bild ist inzwischen da
            if ($of_ === null) { $rest[$k] = $e; continue; }   // Abruf läuft noch: behalten, aber noch nicht melden
            if ((int) $e['post'] <= 0 || get_post_status((int) $e['post']) !== 'publish') { continue; }   // Vorschau außerhalb eines Artikels oder Artikel nicht mehr veröffentlicht: keine Warnung
            $rest[$k] = $e; $nach[$e['asin']][] = (int) $e['post'];
        }
        if (count($rest) !== count($l)) { update_option('sab_bildfehler', $rest, false); }
        if (!$rest) { return ''; }
        uasort($nach, fn($x, $y) => count($y) <=> count($x));   // die auf den meisten Seiten stehen zuerst
        $zeilen = [];
        foreach (array_slice($nach, 0, 40, true) as $asin => $posts) {
            $links = [];
            foreach (array_slice($posts, 0, 4) as $pid) {
                $c_ = (string) get_post_field('post_content', $pid); $o_ = self::stellen_gruppen($c_, (string) $asin); $nr = (int) ($o_[0] ?? 1);
                $tt = self::kurz_wort((string) (get_the_title($pid) ?: ('Beitrag ' . $pid)));
                $links[] = '<a href="' . esc_url(add_query_arg(['sab_zeige' => $asin, 'sab_nr' => $nr], (string) get_permalink($pid))) . '" target="_blank" rel="noopener" title="Seite ansehen, springt zur Stelle">' . esc_html($tt) . '</a> (<a href="' . esc_url(admin_url('post.php?post=' . (int) $pid . '&action=edit&sab_zeige=' . rawurlencode((string) $asin) . '&sab_nr=' . $nr)) . '" target="_blank" rel="noopener">Editor</a>)';
            }
            $alt_ = get_option("sab_alt_{$land}_{$asin}"); $ti = (string) (self::produkte()[$asin] ?? '') ?: (string) (is_array($alt_) ? ($alt_['titel'] ?? '') : '');
            $zeilen[] = '<li><a href="' . esc_url('https://' . self::LAENDER[$land][0] . '/dp/' . $asin) . '" target="_blank" rel="noopener"><code>' . esc_html($asin) . '</code></a>' . ($ti !== '' ? ' ' . esc_html(self::kurz_wort($ti, 8)) : '') . ' auf ' . count($posts) . ' Seite(n): ' . implode(', ', $links) . (count($posts) > 4 ? ' …' : '') . '</li>';
        }
        return '<div class="notice notice-error" style="border-left-width:8px"><p><strong>Produktbilder fehlen (' . count($nach) . ' Produkte):</strong> An diesen Stellen bekommen Besucher kein Bild. Das hat Vorrang vor allem anderen: Produkt ersetzen oder Bild bei Amazon prüfen.</p><ul style="margin-left:18px;list-style:disc">' . implode('', $zeilen) . '</ul><p><a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=sab-austausch')) . '">Zum Austausch: dringend tauschen</a></p></div>';
    }

    /** Erklärtext, den jeder Benutzer ausblenden (und wieder einblenden) kann. $schluessel: kurzer Name des Texts, $reiter: Reiter für den Rücksprung. */
    private static function hinweis_box(string $schluessel, string $reiter, string $html): string {
        $uid = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        $aus = (array) (((array) get_option('sab_hinweis_aus', []))[$uid] ?? []);
        // Die Knöpfe stehen oft in einem schon vorhandenen Formular des Reiters (keine verschachtelten Formulare): sie senden nur sab_hinweis=aus|an:Schlüssel.
        $f = static function (string $akt, string $text) use ($schluessel): string {
            return '<button class="button button-small" name="sab_hinweis" value="' . $akt . ':' . esc_attr($schluessel) . '">' . $text . '</button>';
        };
        if (in_array($schluessel, $aus, true)) { return '<p style="margin:4px 0"><small class="description">Erklärung ausgeblendet.</small> ' . $f('an', 'Erklärung einblenden') . '</p>'; }
        return '<div style="max-width:900px;padding:2px 12px;background:#f6f7f7;border-left:4px solid #2271b1;margin:8px 0">' . $html . '<p style="margin:4px 0 8px">' . $f('aus', 'Verstanden, ausblenden') . '</p></div>';
    }

    private static function hinweis_setzen(): void {
        if (!preg_match('/^(aus|an):([a-z0-9_]{1,30})$/', (string) wp_unslash($_POST['sab_hinweis'] ?? ''), $m)) { return; }
        $uid = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        $alle = (array) get_option('sab_hinweis_aus', []); $l = (array) ($alle[$uid] ?? []);
        $l = $m[1] === 'aus' ? array_values(array_unique(array_merge($l, [$m[2]]))) : array_values(array_diff($l, [$m[2]]));
        $alle[$uid] = $l; update_option('sab_hinweis_aus', $alle, false);
    }

    /** Fehlende Produktbilder zählen (nur Artikel, die noch veröffentlicht sind und deren Bild noch fehlt): [Produkte, Stellen]. */
    public static function bildfehler_zaehlen(): array {
        $l = (array) get_option('sab_bildfehler', []); if (!$l) { return [0, 0]; }
        $land = self::land(''); $asins = []; $stellen = 0; $budget = 1;
        foreach ($l as $e) {
            if (self::bild_noch_offen($e, $land, $budget) !== true) { continue; }
            if ((int) $e['post'] <= 0 || get_post_status((int) $e['post']) !== 'publish') { continue; }
            $asins[$e['asin']] = 1; $stellen++;
        }
        return [count($asins), $stellen];
    }

    /** Aus dem frischen Amazon-Stand: Produkte, die Amazon nicht kennt oder ohne Bild, soweit sie in Artikeln stehen, kommen sofort in die Warnliste (auch ohne Besucheraufruf). */
    private static function bildfehler_aus_stand(array $items, array $ungueltig = []): void {
        $asins = array_values(array_filter(self::alle_asins(), fn($a) => (!isset($items[$a]) && in_array((string) $a, $ungueltig, true)) || (isset($items[$a]) && empty($items[$a]['bilder']) && empty($items[$a]['veraltet']))));   // nur eindeutig Abgelehnte oder Produkte ohne Bild; ein gescheiterter Abruf zählt nie
        if (!$asins) { return; }
        $fund = self::fundstellen($asins); $l = (array) get_option('sab_bildfehler', []); $neu = false;
        foreach ($asins as $a) {
            foreach (array_keys($fund[$a] ?? []) as $pid) {
                $k = $a . '|' . (int) $pid;
                if (!isset($l[$k]) && count($l) < 300) { $l[$k] = ['asin' => $a, 'post' => (int) $pid, 'ts' => time(), 'art' => 'Prüfung']; $neu = true; }
            }
        }
        if ($neu) { update_option('sab_bildfehler', $l, false); }
    }

    /** Dashboard-Kasten in WordPress: fehlende Produktbilder auf einen Blick. */
    public static function dashboard_widget(): void {
        if (!current_user_can('edit_posts')) { return; }
        wp_add_dashboard_widget('sab_bilder', self::NAME . ': Produktbilder', [__CLASS__, 'dashboard_inhalt']);
    }

    public static function dashboard_inhalt(): void {
        $h = self::bildfehler_html();
        echo $h !== '' ? $h : '<p style="color:#1a7f37"><strong>Alle Produktbilder sind da.</strong></p>';
        echo '<p><a href="' . esc_url(admin_url('admin.php?page=sab-austausch')) . '">Zum Austausch</a> · <a href="' . esc_url(admin_url('admin.php?page=sab')) . '">Zur Übersicht</a></p>';
    }

    /** Roter Hinweis in jedem Admin-Bildschirm, solange irgendwo ein Produktbild fehlt (im Plugin selbst und im Dashboard steht die ausführliche Warnung). */
    public static function bild_hinweis(): void {
        if (!current_user_can('edit_posts')) { return; }
        $s = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($s && (strpos((string) $s->id, 'sab') !== false || $s->id === 'dashboard')) { return; }
        [$pr, $st] = self::bildfehler_zaehlen(); if ($pr === 0) { return; }
        echo '<div class="notice notice-error"><p><strong>' . esc_html(self::NAME) . ':</strong> ' . (int) $pr . ' Produkt(e) ohne Bild auf ' . (int) $st . ' Stelle(n) in Artikeln. <a href="' . esc_url(admin_url('admin.php?page=sab-austausch')) . '">Jetzt ansehen</a></p></div>';
    }

    /** Merkt sich ein fehlendes Produktbild (ASIN, Artikel) für die Warnliste; Besucher sehen nichts, angemeldete Redakteure einen roten Hinweis. */
    private static function bild_fehlt(string $asin, string $art = 'Bild'): string {
        $pid = function_exists('get_the_ID') ? (int) get_the_ID() : 0;
        $l = (array) get_option('sab_bildfehler', []); $k = $asin . '|' . $pid;
        if ($pid > 0 && !isset($l[$k]) && count($l) < 300) { $l[$k] = ['asin' => $asin, 'post' => $pid, 'ts' => time(), 'art' => $art]; update_option('sab_bildfehler', $l, false); }
        if (function_exists('current_user_can') && current_user_can('edit_posts')) {
            return '<span data-sab-asin="' . esc_attr($asin) . '" style="display:inline-block;padding:6px 10px;background:#fcf0f1;border:2px solid #d63638;color:#8a1f1f;font:600 13px sans-serif">Produktbild fehlt: ' . esc_html($asin) . ' (nur für Redakteure sichtbar)</span>';
        }
        return '<span class="sab-weg" style="display:inline-flex;align-items:center;justify-content:center;box-sizing:border-box;width:100%;max-width:240px;min-height:120px;padding:10px;text-align:center;background:#f3f4f4;border:1px dashed #b6babd;border-radius:6px;color:#555;font:600 13px/1.3 sans-serif">Momentan nicht verfügbar</span>';
    }

    private static function brauchbar(?array $p): bool {
        return $p && empty($p['weg']) && !empty($p['verfuegbar']) && !empty($p['link']) && !empty($p['bild']);
    }

    // ---------- Ausfälle und Ersatz ----------

    private static function ausfall_setzen(string $asin, string $land, ?array $p, string $ersatz = '', string $seite = ''): void {
        $liste = get_option('sab_ausfall', []);
        $alt = $liste[$asin] ?? [];
        $liste[$asin] = [
            'land'   => $land,
            'name'   => (self::produkte()[$asin] ?? '') ?: ($p['titel'] ?? ($alt['name'] ?? '')),
            'seit'   => $alt['seit'] ?? time(),
            'ersatz' => $ersatz ?: ($alt['ersatz'] ?? ''),
            'seite'  => $seite ?: ($alt['seite'] ?? ''),
            'grund'  => !empty($p['weg']) ? 'Amazon kennt das Produkt nicht mehr' : (empty($p['verfuegbar']) ? 'nicht lieferbar' : 'kein Bild'),
        ];
        update_option('sab_ausfall', $liste, false);
    }

    private static function ausfall_loeschen(string $asin): void {
        $liste = get_option('sab_ausfall', []);
        if (isset($liste[$asin])) { unset($liste[$asin]); update_option('sab_ausfall', $liste, false); }
    }

    /** Alle Produkte, die der tägliche Lauf und „Jetzt alle prüfen“ einzeln bei Amazon abfragen. */
    private static function pruef_liste(): array {
        $alle = array_keys(self::produkte());
        foreach (self::slots() as $liste) { $alle = array_merge($alle, $liste); }
        foreach (self::tests() as $liste) { $alle = array_merge($alle, $liste); }
        $alle = array_merge($alle, array_values(self::tausch_regeln()));
        $alle = array_merge($alle, self::alle_asins());   // auch alle Produkte, die in Artikeln stehen (feste Links, Platzhalter, AAWP), nicht nur die der Produktliste
        return array_values(array_unique(array_map('strval', $alle)));
    }

    /** Ein Produkt frisch holen und als Ausfall eintragen oder löschen; zählt im Lauf mit. */
    private static function pruef_eins(string $asin, array &$lauf): void {
        $p = self::produkt($asin, $lauf['land'], true);
        if (!$p) { $lauf['unerreichbar']++; return; } // Amazon nicht erreichbar: nichts ändern
        if (self::brauchbar($p)) { self::ausfall_loeschen($asin); $lauf['ok']++; return; }
        self::ausfall_setzen($asin, $lauf['land'], $p);
        $lauf['schlecht']++;
        if (!isset($lauf['vorher'][$asin])) { $lauf['neu'][] = (self::produkte()[$asin] ?? '') ?: ($p['titel'] ?? '') ?: $asin; }
    }

    private static function pruef_neuer_lauf(): array {
        return ['id' => time(), 'land' => self::land(''), 'liste' => self::pruef_liste(), 'pos' => 0, 'neu' => [], 'unerreichbar' => 0, 'ok' => 0, 'schlecht' => 0,
                'vorher' => (array) get_option('sab_ausfall', []), 'phase' => 'produkte', 'fertig' => false];
    }

    /** Mails nach dem Lauf: neue Ausfälle, Schnittstelle tot, Wochenbericht. */
    private static function pruef_abschluss(array $lauf): void {
        $gesamt = count($lauf['liste']);
        if ($lauf['neu']) { self::alarm('ausfall', 'Neue Produkte nicht mehr verfügbar', "Seit dem letzten Lauf ausgefallen:\n  " . implode("\n  ", $lauf['neu']) . "\n\nDas Plugin setzt, wo eine Ersatzliste hinterlegt ist, automatisch den nächsten Ersatz ein.\nÜbersicht: Seitenleiste > " . self::NAME . " > Ausfälle und Wartung"); }
        if ($gesamt > 0 && $lauf['unerreichbar'] === $gesamt) { self::alarm('api', 'Amazon-Schnittstelle antwortet nicht', "Beim täglichen Lauf konnte kein einziges Produkt von Amazon abgerufen werden.\nMögliche Ursachen: Zugangsdaten (Credentials) abgelaufen, Amazon-Konto gesperrt oder Amazon vorübergehend nicht erreichbar.\nDie Seiten zeigen weiter die zwischengespeicherten Daten, solange sie vorhanden sind.\nPrüfen: Seitenleiste > " . self::NAME . " > Übersicht."); }
        self::auto_tausch();
        self::wochenmail();
    }

    /** Täglicher Lauf: alle bekannten Produkte frisch holen, Ausfälle eintragen oder löschen. */
    public static function pruefen(): void {
        self::alte_bilder_loeschen();
        $land = self::land('');
        self::uebersicht_holen($land);   // hält den täglichen Score-Verlauf fest
        try { self::lern_abruf(); } catch (\Throwable $e) { /* optional */ }
        try { self::alarm_berechnen(); } catch (\Throwable $e) { /* der Alarm darf die Prüfung nie stören */ }
        $lauf = self::pruef_neuer_lauf();
        foreach ($lauf['liste'] as $asin) { self::pruef_eins($asin, $lauf); }
        self::pruef_abschluss($lauf);
    }

    // ---------- „Jetzt alle prüfen“ mit Fortschrittsbalken (Schritt für Schritt per AJAX, jeder Schritt höchstens etwa 15 Sekunden) ----------

    /** Kurze Zeiten in Sekunden, ab einer Minute in Minuten. */
    private static function dauer_text(float $sek): string {
        return $sek < 60 ? (int) round($sek) . ' Sek.' : str_replace('.', ',', (string) round($sek / 60, 1)) . ' Min.';
    }

    private static function pruef_stand(array $lauf): array {
        $n = count($lauf['liste']); $pos = (int) $lauf['pos'];
        $e = $lauf['ergebnis'] ?? null;
        if (!empty($lauf['fertig'])) { $text = 'Fertig. ' . ($e['text'] ?? ''); }
        elseif ($lauf['phase'] === 'produkte') { $asin = (string) ($lauf['liste'][$pos] ?? ''); $name = ($asin !== '' ? (self::produkte()[$asin] ?? '') : ''); $text = 'Prüfe Produkt ' . min($n, $pos + 1) . ' von ' . $n . ($name !== '' ? ': ' . $name : ($asin !== '' ? ': ' . $asin : '')); }
        elseif ($lauf['phase'] === 'uebersicht') { $text = 'Alle ' . $n . ' Produkte sind geprüft. Jetzt wird die Übersicht bei Amazon aktualisiert (kann eine halbe Minute dauern) …'; }
        else { $text = 'Fast fertig, Ergebnis wird gespeichert …'; }
        return ['pos' => ($lauf['phase'] === 'produkte' ? $pos : ($lauf['phase'] === 'uebersicht' ? $n : $n + 1)), 'gesamt' => $n + 1, 'fertig' => !empty($lauf['fertig']), 'text' => $text];
    }

    private static function pruef_ajax_ok(): void {
        if (!current_user_can('manage_options') || !check_ajax_referer('sab_pruef', 'n', false)) { wp_send_json_error(['text' => 'Keine Berechtigung.'], 403); }
        if (function_exists('ignore_user_abort')) { ignore_user_abort(true); }
        if (function_exists('set_time_limit')) { @set_time_limit(120); }
    }

    public static function ajax_pruef_start(): void {
        self::pruef_ajax_ok();
        self::alte_bilder_loeschen();
        delete_transient('sab_alle_asins');
        $lauf = self::pruef_neuer_lauf();
        if (!$lauf['liste']) { wp_send_json_error(['text' => 'Es wurden keine Produkte zum Prüfen gefunden. Geprüft wird Amazon ' . self::LAENDER[$lauf['land']][0] . ' (Standardland in den Einstellungen): In den Artikeln müssen Links zu diesem Shop stehen oder Produkte in der Produktliste. Bitte Standardland und Partner-IDs prüfen.']); }
        update_option('sab_pruef_lauf', $lauf, false);
        wp_send_json_success(self::pruef_stand($lauf));
    }

    public static function ajax_pruef_schritt(): void {
        self::pruef_ajax_ok();
        $lauf = get_option('sab_pruef_lauf');
        if (!is_array($lauf) || !isset($lauf['liste'])) { wp_send_json_error(['text' => 'Keine Prüfung gestartet.']); }
        if (empty($lauf['fertig'])) {
            if ($lauf['phase'] === 'produkte') {
                $ende = microtime(true) + 15; $n = count($lauf['liste']);
                while ($lauf['pos'] < $n && microtime(true) < $ende) { self::pruef_eins((string) $lauf['liste'][$lauf['pos']], $lauf); $lauf['pos']++; }
                if ($lauf['pos'] >= $n) { $lauf['phase'] = 'uebersicht'; }
            } elseif ($lauf['phase'] === 'uebersicht') {
                self::uebersicht_holen($lauf['land']);   // hält den täglichen Score-Verlauf fest
                $lauf['phase'] = 'abschluss';
            } else {
                self::pruef_abschluss($lauf);
                $lauf['fertig'] = true;
                $text = count($lauf['liste']) . ' Produkte geprüft, ' . $lauf['ok'] . ' in Ordnung, ' . $lauf['schlecht'] . ' ausgefallen' . ($lauf['neu'] ? ' (neu: ' . count($lauf['neu']) . ')' : '') . ($lauf['unerreichbar'] ? ', ' . $lauf['unerreichbar'] . ' von Amazon nicht erreichbar' : '') . '.';
                $lauf['ergebnis'] = ['ts' => time(), 'dauer' => time() - (int) $lauf['id'], 'text' => $text];
                update_option('sab_pruef_ergebnis', $lauf['ergebnis'], false);
            }
            update_option('sab_pruef_lauf', $lauf, false);
        }
        wp_send_json_success(self::pruef_stand($lauf));
    }

    /** Oberer Teil des Reiters „Ausfälle und Wartung“: Prüfung mit Fortschritt, von dir markierte Produkte, Seiten mit vielen Links, Liste der Ausfälle. */
    private static function ausfall_html(): string {
        ob_start();
        echo self::pruef_html();
        $ers = array_keys(array_filter((array) get_option('sab_marken', []), fn($t) => $t === 'ers'));
        if ($ers) {   // schon getauscht (Regel oder Festschreibung) oder in keinem Artikel mehr: nicht mehr aufführen
            $erledigt = array_merge(array_keys(self::tausch_regeln()), array_column((array) get_option('sab_tausch_log', []), 'alt'));
            $ers = array_values(array_filter($ers, fn($a) => !in_array($a, $erledigt, true)));
            $fs = $ers ? self::fundstellen($ers) : [];
            $ers = array_values(array_filter($ers, fn($a) => !empty($fs[$a])));
        }
        if ($ers) {
            $namen = self::produkte();
            echo '<h2>Bitte austauschen (von dir markiert)</h2><ul>';
            foreach ($ers as $a) { echo '<li><code>' . esc_html($a) . '</code> ' . esc_html($namen[$a] ?? '') . ' <small>(auf ' . count($fs[$a]) . ' Seiten)</small></li>'; }
            echo '</ul><p class="description">Nimm den Reiter „Austausch“, dann gilt der Ersatz überall. Getauschte Produkte und solche, die in keinem Artikel mehr stehen, verschwinden aus dieser Liste.</p>';
        }
        $lw = array_filter((array) get_option('sab_linkwarnung', []), fn($x) => ($x['n'] ?? 0) >= self::LINK_WARNUNG);
        if ($lw) {
            echo '<div class="notice notice-warning inline"><p><strong>Seiten mit ' . self::LINK_WARNUNG . ' oder mehr Partnerlinks:</strong></p><ul>';
            foreach ($lw as $pid => $x) {
                echo '<li><a href="' . esc_url((string) get_permalink((int) $pid)) . '">' . esc_html((string) get_the_title((int) $pid)) . '</a>: ' . (int) $x['n'] . ' Links</li>';
            }
            echo '</ul></div>';
        }
        echo '<h2>Ausgefallene Produkte</h2>';
        $liste = (array) get_option('sab_ausfall', []);
        $st = get_option('sab_uebersicht_stand_' . self::land('')); $nm = self::produkte();
        if (is_array($st) && !empty($st['items'])) {   // auch Produkte, die Amazon gar nicht mehr kennt, stehen hier (nach dem letzten Abruf der Übersicht)
            foreach (self::alle_asins() as $a) {
                if (!isset($st['items'][$a]) && !isset($liste[$a]) && self::amazon_unbekannt((string) $a, $st)) { $liste[$a] = ['name' => ($nm[$a] ?? ''), 'grund' => 'Amazon kennt das Produkt nicht mehr', 'seit' => (int) ($st['ts'] ?? time()), 'ersatz' => '', 'seite' => '']; }
            }
        }
        if (!$liste) { echo '<p>Keine.</p>'; }
        else {
            echo '<table class="widefat striped"><thead><tr><th>ASIN</th><th>Name</th><th>Grund</th><th>Seit</th><th>Ersatz</th><th>Artikel</th></tr></thead><tbody>';
            foreach ($liste as $asin => $x) {
                echo '<tr><td>' . esc_html($asin) . '</td><td>' . esc_html($x['name']) . '</td><td>' . esc_html($x['grund']) . '</td><td>'
                   . esc_html(wp_date('d.m.Y', $x['seit'])) . '</td><td>' . esc_html($x['ersatz'] ? $x['ersatz'] . ' (bitte Passung prüfen)' : '–')
                   . '</td><td>' . ($x['seite'] ? '<a href="' . esc_url($x['seite']) . '">' . esc_html($x['seite']) . '</a>' : '–') . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        return (string) ob_get_clean();
    }

    /** Knopf, Fortschrittsbalken und Ergebnis der letzten Prüfung (Reiter „Ausfälle und Wartung“). */
    private static function pruef_html(): string {
        $lauf = get_option('sab_pruef_lauf'); $erg = get_option('sab_pruef_ergebnis');
        $offen = is_array($lauf) && isset($lauf['liste']) && empty($lauf['fertig']) && time() - (int) $lauf['id'] < 2 * HOUR_IN_SECONDS;
        $h = '<div id="sab-pruef" style="max-width:760px;margin:12px 0 18px"><p><button type="button" class="button button-primary button-hero" id="sab-pruef-knopf" data-offen="' . ($offen ? '1' : '') . '">'
           . ($offen ? 'Prüfung fortsetzen (' . (int) $lauf['pos'] . ' von ' . count($lauf['liste']) . ')' : 'Jetzt alle prüfen') . '</button></p>'
           . '<div id="sab-pruef-balken" style="display:none"><progress id="sab-pruef-p" max="100" value="0" style="width:100%;height:28px"></progress>'
           . '<p id="sab-pruef-t" style="font-size:16px;font-weight:600;margin:6px 0"></p><p class="description">Bitte diese Seite offen lassen. Die Prüfung dauert etwa 1 bis 3 Minuten. Das Ergebnis steht danach hier.</p></div>';
        if (is_array($erg) && !empty($erg['ts'])) {
            $h .= '<div class="notice notice-info inline" style="margin:8px 0"><p style="font-size:14px"><strong>Letzte Prüfung:</strong> ' . esc_html(wp_date('d.m.Y H:i', (int) $erg['ts'])) . ' (Dauer ' . self::dauer_text((float) ($erg['dauer'] ?? 0)) . '). ' . esc_html((string) ($erg['text'] ?? '')) . '</p></div>';
        }
        $h .= '</div><script>(function(){var k=document.getElementById("sab-pruef-knopf");if(!k)return;var A=' . wp_json_encode(admin_url('admin-ajax.php')) . ',N=' . wp_json_encode(wp_create_nonce('sab_pruef')) . ',b=document.getElementById("sab-pruef-balken"),p=document.getElementById("sab-pruef-p"),t=document.getElementById("sab-pruef-t"),f=0;'
           . 'function post(a,ok,bad){var x=new XMLHttpRequest();x.open("POST",A);x.setRequestHeader("Content-Type","application/x-www-form-urlencoded");x.timeout=120000;x.onload=function(){var j=null;try{j=JSON.parse(x.responseText)}catch(e){}if(x.status===200&&j&&j.success){ok(j.data)}else{bad()}};x.onerror=bad;x.ontimeout=bad;x.send("action="+a+"&n="+encodeURIComponent(N))}'
           . 'function zeig(s){b.style.display="block";p.max=s.gesamt;p.value=s.pos;t.textContent=s.text}'
           . 'function schritt(){post("sab_pruef_schritt",function(s){f=0;zeig(s);if(s.fertig){setTimeout(function(){location.reload()},1500)}else{schritt()}},function(){f++;if(f<4){setTimeout(schritt,2000)}else{t.textContent="Unterbrochen. Bitte auf Fortsetzen klicken.";k.disabled=false;k.setAttribute("data-offen","1");k.textContent="Prüfung fortsetzen"}})}'
           . 'k.onclick=function(){k.disabled=true;f=0;b.style.display="block";t.textContent="Starte …";if(k.getAttribute("data-offen")){schritt()}else{post("sab_pruef_start",function(s){zeig(s);schritt()},function(){t.textContent="Start nicht möglich. Bitte Seite neu laden.";k.disabled=false})}};})();</script>';
        return $h;
    }

    /** Sofortmeldung per Mail (an dieselbe Adresse wie der Wochenbericht), je Art höchstens einmal in 20 Stunden. */
    private static function alarm(string $art, string $betreff, string $text): void {
        $an = trim((string) get_option('sab_mail_an', ''));
        if ($an === '') { return; }
        $z = (array) get_option('sab_alarm_zuletzt', []);
        if (time() - (int) ($z[$art] ?? 0) < 20 * HOUR_IN_SECONDS) { return; }
        if (wp_mail($an, self::NAME . ': ' . $betreff, $text)) { $z[$art] = time(); update_option('sab_alarm_zuletzt', $z, false); }
    }

    /** Wöchentliche Mail (Adresse unter Einstellungen): Klicks der letzten 7 Tage, Ausfälle, Seiten mit vielen Links. Läuft im täglichen Lauf, nie beim Seitenaufruf. */
    private static function wochenmail(): void {
        $an = trim((string) get_option('sab_mail_an', ''));
        if ($an === '' || time() - (int) get_option('sab_mail_zuletzt', 0) < 7 * DAY_IN_SECONDS - HOUR_IN_SECONDS) { return; }
        global $wpdb;
        $k = [];
        if (isset($wpdb)) {
            $t = self::klicktabelle();
            $k = (array) $wpdb->get_results($wpdb->prepare("SELECT asin, COUNT(*) n FROM $t WHERE ts >= %s GROUP BY asin ORDER BY n DESC", gmdate('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS)), ARRAY_A);
        }
        $warn = array_filter((array) get_option('sab_linkwarnung', []), fn($x) => ($x['n'] ?? 0) >= self::LINK_WARNUNG);
        $text = self::wochenmail_text($k, (array) get_option('sab_ausfall', []), $warn);
        if (wp_mail($an, self::NAME . ': Wochenbericht', $text)) { update_option('sab_mail_zuletzt', time(), false); }
    }

    private static function wochenmail_text(array $klicks, array $ausfall, array $warn): string {
        $namen = self::produkte();
        $z = [self::NAME . ': die letzten 7 Tage', ''];
        $z[] = 'Klicks auf Amazon-Links: ' . array_sum(array_map(fn($r) => (int) $r['n'], $klicks));
        foreach (array_slice($klicks, 0, 5) as $r) { $z[] = '  ' . (int) $r['n'] . ' x ' . (($namen[$r['asin']] ?? '') ?: $r['asin']); }
        $z[] = '';
        if ($ausfall) {
            $z[] = 'Ausgefallene Produkte (nicht lieferbar oder bei Amazon weg):';
            foreach ($ausfall as $asin => $x) { $z[] = '  ' . (($x['name'] ?? '') ?: $asin) . ' (' . $asin . ')'; }
        } else { $z[] = 'Keine ausgefallenen Produkte.'; }
        if ($warn) {
            $z[] = '';
            $z[] = 'Seiten mit ' . self::LINK_WARNUNG . ' oder mehr Partnerlinks:';
            foreach ($warn as $pid => $x) { $z[] = '  ' . (function_exists('get_permalink') ? get_permalink((int) $pid) : $pid) . ': ' . (int) $x['n'] . ' Links'; }
        }
        return implode("\n", $z);
    }

    /** Bilder auf dem Server, die älter als 2 Tage sind, werden gelöscht (nichts bleibt dauerhaft gespeichert). */
    private static function alte_bilder_loeschen(): void {
        $up = wp_upload_dir();
        foreach ((array) glob($up['basedir'] . '/sab/*.jpg') as $f) {
            if (time() - filemtime($f) > 2 * DAY_IN_SECONDS) { @unlink($f); }
        }
    }

    /** Erstes brauchbares Produkt der Liste; ausgefallene davor werden gemeldet. */
    private static function waehlen(array $asins, string $land): ?array {
        $ausgefallen = [];
        foreach ($asins as $asin) {
            $p = self::produkt($asin, $land);
            if (self::brauchbar($p)) {
                $seite = function_exists('get_permalink') ? (string) get_permalink() : '';
                foreach ($ausgefallen as [$a, $ap]) { self::ausfall_setzen($a, $land, $ap, $asin, $seite); }
                return ['asin' => $asin, 'p' => $p, 'ersatz' => (bool) $ausgefallen];
            }
            if ($p) { $ausgefallen[] = [$asin, $p]; } // nur melden, wenn Amazon geantwortet hat
        }
        foreach ($asins as $asin) {   // Notfall: nichts ist lieferbar, aber ein Produkt hat Bild und Link: lieber das zeigen als eine leere Stelle
            $p = self::produkt($asin, $land);
            if (self::hat_bild($p) && empty($p['weg'])) { return ['asin' => $asin, 'p' => $p, 'ersatz' => false]; }
        }
        return null;
    }

    // ---------- Bild vom eigenen Server ----------

    /** Lädt das Amazon-Bild höchstens 1x pro Tag und liefert die eigene Adresse zurück. */
    private static function bild_pfad(string $land, string $asin, int $idx): string {
        return wp_upload_dir()['basedir'] . '/sab/' . "{$land}-{$asin}" . ($idx > 0 ? "-{$idx}" : '') . '.jpg';
    }

    private static $sofort_bilder = 0;

    /** Liegt ein nicht zu altes Bild des Produkts (Hauptbild) auf dem eigenen Server? */
    private static function bild_frisch(string $land, string $asin): bool {
        $f = self::bild_pfad($land, $asin, 0);
        return is_file($f) && time() - filemtime($f) <= self::BILD_MAX;
    }

    /**
     * Knopf „Alle Bilder jetzt prüfen/nachladen“: holt für jedes Produkt der Seite ohne frisches Bild das Produkt neu (höchstens ~20 Sekunden je Klick)
     * und bereinigt Warnlisten-Einträge, die damit erledigt sind. @return string Meldung
     */
    private static function bilder_nachladen(): string {
        $land = self::land(''); $ende = microtime(true) + 20; $geholt = 0; $fehlt = 0; $offen = 0;
        delete_transient('sab_alle_asins');
        foreach (self::alle_asins() as $asin) {
            $asin = (string) $asin; if (!self::asin($asin) || self::bild_frisch($land, $asin)) { continue; }
            if (microtime(true) > $ende) { $offen++; continue; }
            self::holen($asin, $land);
            if (self::bild_frisch($land, $asin)) { $geholt++; } else { $fehlt++; }
        }
        $bf = (array) get_option('sab_bildfehler', []);
        foreach ($bf as $k => $e) { $a = (string) ($e['asin'] ?? strtok((string) $k, '|')); if ($a !== '' && self::bild_frisch($land, $a)) { unset($bf[$k]); } }
        update_option('sab_bildfehler', $bf, false);
        foreach (array_keys(self::LAENDER) as $l_) { delete_transient("sab_uebersicht_{$l_}"); }
        return $geholt . ' Bilder nachgeladen, ' . $fehlt . ' Produkte haben bei Amazon weiter kein Bild' . ($offen ? ', ' . $offen . ' noch offen: bitte noch einmal klicken' : ' (alles geprüft)') . '.';
    }

    private static function bild_url(array $p, string $asin, string $land, int $idx = 0, bool $laden = false): string {
        $quelle = $idx > 0 ? ($p['bilder'][$idx] ?? '') : ($p['bild'] ?? '');
        if (!$quelle) { return ''; }
        $up   = wp_upload_dir();
        $dir  = $up['basedir'] . '/sab';
        $name = "{$land}-{$asin}" . ($idx > 0 ? "-{$idx}" : '') . '.jpg';
        $file = "$dir/$name";
        $alter = file_exists($file) ? time() - filemtime($file) : PHP_INT_MAX;

if ($alter > self::TTL && !$laden && !defined('SAB_SYNC') && self::$sofort_bilder < 2) { $laden = true; self::$sofort_bilder++; }   // noch nie geladen oder abgelaufen: jetzt sofort holen statt „Bild fehlt“, höchstens 2 je Seitenaufruf; schlägt das fehl, bleibt das alte Bild bis BILD_MAX
        if ($alter > self::TTL && !$laden && !defined('SAB_SYNC')) { self::im_hintergrund('sab_bild_holen', [$land, $asin, $idx]); }
        if ($alter > self::TTL && ($laden || defined('SAB_SYNC'))) {
            wp_mkdir_p($dir);
            $r = wp_remote_get($quelle, ['timeout' => $alter === PHP_INT_MAX ? 6 : 10]);
            $ok = !is_wp_error($r) && wp_remote_retrieve_response_code($r) === 200
               && strpos((string) wp_remote_retrieve_header($r, 'content-type'), 'image/') === 0;
            if ($ok) {
                file_put_contents($file, wp_remote_retrieve_body($r));
                $alter = 0;
            }
        }
        if ($alter > self::BILD_MAX) { return ''; } // zu alt (oder nie geladen): lieber kein Bild
        return $up['baseurl'] . '/sab/' . $name;
    }

    // ---------- Ausgabe ----------

    /** Gemeinsames Handy-CSS für alle Admin-Reiter: Tabellen werden zu gestapelten Karten, große Knöpfe, kein seitliches Scrollen. */
    const ADMIN_MOBIL_CSS = '@media(max-width:782px){.sab-admin{overflow-x:hidden}.sab-admin table,.sab-admin thead,.sab-admin tbody,.sab-admin tr,.sab-admin td,.sab-admin th{display:block;width:auto!important;max-width:100%!important;min-width:0!important;box-sizing:border-box}.sab-admin thead{display:none}.sab-admin tr{border:1px solid #c3c4c7;border-radius:8px;margin:0 0 10px;padding:6px 10px;background:#fff}.sab-admin td{border:0!important;padding:4px 0!important;overflow-wrap:anywhere}.sab-admin td[data-l]::before{content:attr(data-l);display:block;font-size:11px;font-weight:600;color:#666}.sab-admin .nav-tab-wrapper{display:flex;flex-wrap:wrap;gap:4px}.sab-admin .nav-tab{flex:1 1 auto;text-align:center;margin:0;padding:10px 8px}.sab-admin .button,.sab-admin .button-primary{min-height:44px;line-height:42px;padding:0 16px;white-space:normal}.sab-admin .button-small{min-height:40px;line-height:38px}.sab-admin input[type=text],.sab-admin input[type=number],.sab-admin input[type=url],.sab-admin textarea,.sab-admin select{width:100%!important;max-width:100%!important;box-sizing:border-box;font-size:16px;min-height:40px}.sab-admin pre,.sab-admin code{white-space:pre-wrap;overflow-wrap:anywhere}.sab-admin img,.sab-admin svg{max-width:100%;height:auto}.sab-admin [style*="min-width"],.sab-admin [style*="max-width"]{min-width:0!important;max-width:100%!important}.sab-admin form,.sab-admin p,.sab-admin div{max-width:100%}.sab-admin div[style*="display:flex"]{flex-wrap:wrap}}';


    private static $genutzt = false;
    private static $links = 0;
    private static $hinweis_gezeigt = false;

    private static function a_attr(string $asin, string $slot = '', string $var = ''): string {
        self::$genutzt = true;
        self::$links++;
        if ($slot === '' && $var === '' && self::$ab_ctx) { $slot = self::$ab_ctx['slot']; $var = self::$ab_ctx['var']; }   // Karte gehört zu einem Viererblock-Test: Klick zählt für Paar A oder B
        // data-Attribute: die Mini-Statistik kann Klicks damit dem Produkt und der Ersatzliste zuordnen
        $gt = '';
        $nm = (string) (self::produkte()[$asin] ?? ''); if ($nm === '') { $pp = self::produkt($asin, self::land('')); $nm = (string) ($pp['titel'] ?? ''); }
        if ($nm !== '') { $tn = esc_attr(mb_substr(trim(preg_replace('/\s*\|\s*/u', ' ', $nm)), 0, 80)); $gt = ' data-sab-t="' . $tn . '" data-aawp-product-title="' . $tn . '"'; }   // Suchbegriff für die Länder-Umschaltung; das zweite Attribut kennt auch die ältere, fremde Umschaltung (sonst sucht sie nach „dp“)
        if (get_option('sab_geo') === '1') { $lmap = (array) get_option('sab_laender_map', []); $lm = (array) ($lmap[$asin] ?? []); $lz = []; foreach ($lm as $l_ => $e_) { if (!empty($e_['asin'])) { $lz[] = $l_ . ':' . $e_['asin']; } } if ($lz) { $gt .= ' data-sab-l="' . esc_attr(implode(',', $lz)) . '"'; } }   // freigegebene ASINs in den anderen Ländern
        if (array_key_exists($asin, self::heimat_liste()) || isset(((array) get_option('sab_asin_land', []))[$asin])) { $gt .= ' data-sab-fix="1"'; }   // Heimat-Store ist ein anderer: die Länder-Umschaltung lässt den Link in Ruhe
        return ' data-sab-asin="' . esc_attr($asin) . '"' . ($slot ? ' data-sab-slot="' . esc_attr($slot) . '"' : '') . ($var ? ' data-sab-var="' . esc_attr($var) . '"' : '') . $gt
             . ' target="_blank" rel="nofollow sponsored noopener"';
    }

    private static function bild_html(array $p, string $asin, string $land, string $alt, int $w, string $slot = '', string $cls = '', int $idx = 0, string $var = ''): string {
        $src = self::bild_url($p, $asin, $land, $idx);
        if (!$src) { return ''; }
        $f  = self::bild_pfad($land, $asin, $idx);
        $gs = function_exists('getimagesize') && file_exists($f) ? @getimagesize($f) : false;
        $img = '<img src="' . esc_url($src) . '" alt="' . esc_attr($alt) . '" loading="lazy" decoding="async"'
             . ($gs ? ' width="' . (int) $gs[0] . '" height="' . (int) $gs[1] . '"' : '')
             . ($w ? ' style="max-width:' . $w . 'px;height:auto"' : ' style="height:auto"') . '>';
        return '<a class="sab-bild' . ($cls ? ' ' . esc_attr($cls) : '') . '" href="' . esc_url($p['link']) . '"' . self::a_attr($asin, $slot, $var) . '>' . $img . '</a>';
    }

    public static function sc_produkt($a, $inhalt = ''): string {
        $a = shortcode_atts(['slot' => '', 'asin' => '', 'land' => '', 'breite' => '', 'text' => '', 'tag' => ''], $a);
        $slots = self::slots(); $tests = self::tests();
        $sn = $a['slot'];
        if ($a['asin'] !== '' && strlen(self::asin($a['asin'])) === 10) { $sn = 'asin-' . self::asin($a['asin']); $slots[$sn] = [self::asin($a['asin'])]; }  // [amz_produkt asin="..."] ohne Slot
        if (!isset($slots[$sn]) && !isset($tests[$sn])) { return (string) $inhalt; }  // Rückfall: bisheriger HTML-Code
        $land = self::land($a['land']);
        $liste = self::nach_marken(array_map([__CLASS__, 'getauscht'], $slots[$sn] ?? []));
        $var = ''; $erste = '';
        if (isset($tests[$sn])) {
            // A/B-Test: jeder Artikel bekommt fest eine Variante (ohne Cookie, ohne IP); die Ersatzliste steht dahinter
            $t = array_map([__CLASS__, 'getauscht'], $tests[$sn]);
            $pid = function_exists('get_the_ID') ? (int) get_the_ID() : 0;
            $i = (int) (abs(crc32($pid . '|' . $sn)) % count($t));
            $erste = $t[$i]; $var = chr(65 + $i);
            $liste = array_values(array_unique(array_merge([$erste], $t, $liste)));
        }
        $w = self::waehlen($liste, $land);
        if (!$w) { return (string) $inhalt !== '' ? (string) $inhalt : self::bild_fehlt((string) ($liste[0] ?? $sn), 'Karte'); }
        $asin = $w['asin']; $p = $w['p'];
        $p['link'] = self::lnk((string) $p['link'], (string) $a['tag']);
        if ($var && $asin !== $erste) { $var = ''; } // Variante ausgefallen: nicht als Variante zählen
        $name = (self::produkte()[$asin] ?? '') ?: $p['titel'];
        // Ein Produkt = ein Link: Bild, Name und Text stecken in EINEM <a>
        [$bi, $bv] = self::bildvariante($asin, $p);
        $slotattr = $sn;
        if ($var === '' && $bv !== '') { $var = $bv; $slotattr = 'bild-' . $asin; } // Bildtest zählt, wenn kein Produkttest läuft
        // A/B-Test "mit/ohne Preis" (Einstellungen): jeder Artikel bekommt fest eine Variante; A ohne, B mit Preis. Hat Vorrang vor dem Button-Test.
        $preis = '';
        $preistest = get_option('sab_preis_test') === '1' && !empty($p['preis']);
        if ($preistest) {
            $pi = abs(crc32((function_exists('get_the_ID') ? (int) get_the_ID() : 0) . '|preis')) % 2;
            if ($pi) { $preis = '<span class="sab-preis">' . esc_html($p['preis']) . '<small> ' . esc_html(self::PREIS_STAND[$land] ?? self::PREIS_STAND['de']) . ' ' . esc_html(wp_date('d.m.Y', (int) ($p['ts'] ?? time()))) . '</small></span>'; }
            if ($var === '') { $var = $pi ? 'B' : 'A'; $slotattr = 'preis-test'; }
        }
        if ($a['text'] === '') {
            $a['text'] = self::BUTTON_TEXT[$land] ?? self::BUTTON_TEXT['de'];
            // A/B-Test der Button-Texte (Einstellungen): jeder Artikel bekommt fest eine Variante; zählt nur, wenn kein anderer Test läuft
            $ta = trim((string) get_option('sab_btn_a', '')); $tb = trim((string) get_option('sab_btn_b', ''));
            if ($ta !== '' && $tb !== '' && !$preistest) {
                $pid = function_exists('get_the_ID') ? (int) get_the_ID() : 0;
                $bi2 = abs(crc32($pid . '|button')) % 2;
                $a['text'] = $bi2 ? $tb : $ta;
                if ($var === '') { $var = $bi2 ? 'B' : 'A'; $slotattr = 'button-test'; }
            }
        }
        $src = self::bild_url($p, $asin, $land, $bi) ?: ($bi ? self::bild_url($p, $asin, $land) : '');
        $img = $src ? '<img src="' . esc_url($src) . '" alt="' . esc_attr($name) . '" loading="lazy" decoding="async"'
             . ($a['breite'] ? ' style="max-width:' . (int) $a['breite'] . 'px;height:auto"' : ' style="height:auto"') . '>' : '';
        $fav = (((array) get_option('sab_marken', []))[$asin] ?? '') === 'fav';
        return '<div class="sab-produkt"><a class="sab-link" href="' . esc_url($p['link']) . '"' . self::a_attr($asin, $slotattr, $var) . '>'
             . ($fav ? '<span class="sab-fav">' . esc_html(self::FAV_TEXT[$land] ?? self::FAV_TEXT['de']) . '</span>' : '')
             . ($img ? '<span class="sab-bildbox">' . $img . '</span>' : '')
             . '<span class="sab-info"><span class="sab-name">' . esc_html($name) . '</span>' . $preis
             . '<span class="sab-text">' . esc_html($a['text']) . '</span></span></a></div>';
    }

    /** Pflichthinweis des Partnerprogramms, einmal pro Seite z. B. im Fuß: [amz_hinweis text="..."] */
    public static function sc_hinweis($a): string {
        $a = shortcode_atts(['text' => '', 'land' => ''], $a);
        self::$hinweis_gezeigt = true;
        if ($a['text'] === '') { $a['text'] = self::hinweis_text(self::land($a['land'])); }
        return '<p class="sab-hinweis"><small>' . esc_html($a['text']) . '</small></p>';
    }

    // ---------- Update-Prüfung mit wechselndem Abstand ----------

    /** Alter der letzten Neuerung (Sekunden) => Abstand der Prüfung (Sekunden); danach nur noch einmal am Tag. Viele Updates nacheinander: Minutentakt. */
    const UPD_STUFEN = [[1800, 60], [7200, 180], [21600, 600], [86400, 1800], [259200, 7200]];

    public static function update_intervall(): int {
        $st = (array) get_option('sab_upd_stat', []); $alter = time() - (int) ($st['neu'] ?? 0);
        foreach (self::UPD_STUFEN as [$bis, $abstand]) { if ($alter <= $bis) { return $abstand; } }
        return DAY_IN_SECONDS;
    }

    /** Merkt, wann zuletzt eine neue Version zu sehen war (bei GitHub oder frisch installiert): davon hängt der Prüfabstand ab. */
    private static function update_merken(string $json_version): void {
        $st = (array) get_option('sab_upd_stat', []); $neu = false;
        if ($json_version !== '' && ($st['json'] ?? '') !== $json_version) { $st['json'] = $json_version; $neu = true; }
        if (($st['inst'] ?? '') !== self::VERSION) { $st['inst'] = self::VERSION; $neu = true; }
        if ($neu) { $st['neu'] = time(); update_option('sab_upd_stat', $st, false); }
    }

    /** Fragt GitHub jetzt (oder wenn der Abstand um ist) nach einer neuen Version und trägt sie sofort bei WordPress ein. @return string neue Version oder leer */
    /** WordPress verschickt nach automatischen Plugin-Updates eine Mail. Standard: aus (Haken unter Einstellungen schaltet sie wieder ein). Gilt für die ganze Webseite. */
    public static function update_mail_filter($senden, $ergebnisse = []) {
        if (get_option('sab_update_mail') === '1') { return $senden; }
        foreach ((array) $ergebnisse as $liste) { foreach ((array) $liste as $e) { $r = is_object($e) ? ($e->result ?? null) : null; if ($r === false || (is_object($r) && function_exists('is_wp_error') && is_wp_error($r))) { return $senden; } } }   // Fehlermeldungen bleiben an
        return false;
    }

    public static function update_nachsehen(bool $jetzt = false): string {
        if (!$jetzt && time() < (int) get_option('sab_upd_naechste', 0)) { return ''; }
        delete_transient('sab_update_json');
        $key = plugin_basename(__FILE__);
        $t = get_site_transient('update_plugins'); if (!is_object($t)) { $t = new stdClass(); }
        if (empty($t->checked) || !is_array($t->checked)) { $t->checked = []; }
        if (!isset($t->checked[$key])) { $t->checked[$key] = self::VERSION; }
        if (empty($t->last_checked)) { $t->last_checked = time(); }
        foreach (['response', 'no_update'] as $f) { if (!isset($t->$f) || !is_array($t->$f)) { $t->$f = []; } }
        unset($t->response[$key], $t->no_update[$key]);
        $t = self::update_pruefen($t);
        set_site_transient('update_plugins', $t);   // WordPress zeigt es sofort an
        update_option('sab_upd_naechste', time() + self::update_intervall(), false);
        return isset($t->response[$key]) ? (string) $t->response[$key]->new_version : '';
    }

    /** Nur im Admin: Abstand um? Dann nachsehen (ein Optionswert pro Aufruf, im Frontend läuft nichts). Dazu die Kette der Hintergrundläufe am Leben halten. */
    public static function update_admin(): void {
        if (!current_user_can('update_plugins') || (defined('DOING_AJAX') && DOING_AJAX)) { return; }
        self::update_nachsehen(false);
        if (!wp_next_scheduled('sab_update_check')) { wp_schedule_single_event(time() + max(60, self::update_intervall()), 'sab_update_check'); }
    }

    /** Hintergrundlauf: nachsehen, bei neuer Version und eingeschalteten Auto-Updates nur dieses Plugin gleich einspielen lassen, nächsten Lauf planen. */
    public static function update_cron(): void {
        $neu = self::update_nachsehen(true); $key = plugin_basename(__FILE__);
        if ($neu !== '' && in_array($key, (array) get_site_option('auto_update_plugins', []), true) && function_exists('wp_maybe_auto_update')) {
            $nur = fn($u, $i) => is_object($i) && (string) ($i->plugin ?? '') === $key;
            add_filter('auto_update_plugin', $nur, 99999, 2); wp_maybe_auto_update(); remove_filter('auto_update_plugin', $nur, 99999);
        }
        wp_schedule_single_event(time() + max(60, self::update_intervall()), 'sab_update_check');
    }

    /** Meldet WordPress eine neuere Version aus update.json im GitHub-Repo; das ZIP muss aus diesem Repo stammen. */
    public static function update_pruefen($t) {
        if (!is_object($t) || empty($t->checked)) { return $t; }
        if (isset($_GET['force-check'])) { delete_transient('sab_update_json'); }  // WordPress: Dashboard > Aktualisierungen > "Erneut prüfen"
        $m = get_transient('sab_update_json');
        if ($m === false) {
            $r = wp_remote_get(self::UPDATE_JSON . '?t=' . time(), ['timeout' => 8]);  // Zeitstempel umgeht den Zwischenspeicher von GitHub
            $code = is_wp_error($r) ? $r->get_error_message() : wp_remote_retrieve_response_code($r);
            $m = (!is_wp_error($r) && $code === 200) ? json_decode((string) wp_remote_retrieve_body($r), true) : [];
            update_option('sab_update_status', ['ts' => time(), 'code' => (string) $code, 'version' => is_array($m) ? (string) ($m['version'] ?? '') : ''], false);
            self::update_merken(is_array($m) ? (string) ($m['version'] ?? '') : '');
            set_transient('sab_update_json', is_array($m) ? $m : [], $m ? max(60, self::update_intervall()) : 5 * MINUTE_IN_SECONDS);  // Zwischenspeicher so lang wie der Prüfabstand; bei Fehlern in 5 Minuten wieder
        }
        $v = (string) ($m['version'] ?? ''); $zip = (string) ($m['zip'] ?? '');
        if ($v === '' || strpos($zip, self::UPDATE_REPO) !== 0) { return $t; }
        $key = plugin_basename(__FILE__);
        // Eintrag auch ohne neue Version (no_update): nur dann bietet WordPress den Schalter „Automatische Updates“ an
        $e = (object) ['id' => 'github.com/Aleeeb/schlanke-amazon-box-', 'slug' => 'schlanke-amazon-box', 'plugin' => $key, 'new_version' => $v, 'package' => $zip, 'url' => self::UPDATE_REPO];
        if (version_compare($v, self::VERSION, '<=')) { $t->no_update[$key] = $e; } else { $t->response[$key] = $e; }
        return $t;
    }

    private static function hinweis_text(string $land): string {
        $eigen = trim((string) get_option('sab_hinweis_text', ''));
        return $eigen !== '' ? $eigen : (self::HINWEIS[$land] ?? self::HINWEIS['de']);
    }

    /**
     * Im Fuß, nur auf Seiten mit Partnerlinks: (1) Pflichthinweis automatisch, falls aktiviert und nicht schon per
     * [amz_hinweis] gezeigt, (2) Warnung für angemeldete Administratoren ab LINK_WARNUNG Links; die Zahl wird für die Verwaltung gemerkt.
     */
    const KARTEN_CSS = '.sab-produkt{margin:1.4em 0;--sab-akzent:#ffa41c;--sab-akzent-dunkel:#fa8900}.sab-link{position:relative;display:flex;flex-direction:column;align-items:center;gap:14px;max-width:560px;margin:0 auto;padding:18px;border:1px solid #e3e6e6;border-radius:12px;text-decoration:none!important;color:inherit;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.06);transition:box-shadow .15s,transform .15s}.sab-link:hover{box-shadow:0 6px 18px rgba(0,0,0,.12);transform:translateY(-1px)}.sab-bildbox{flex:0 0 auto;display:flex;align-items:center;justify-content:center;width:100%;max-width:150px}.sab-bildbox img{max-width:100%;max-height:150px;width:auto;height:auto;object-fit:contain}.sab-info{display:flex;flex-direction:column;align-items:center;gap:8px;text-align:center;min-width:0;flex:1}.sab-name{font-weight:600;line-height:1.35;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}.sab-preis{font-size:1.3em;font-weight:700;color:#b12704}.sab-preis small{font-size:.6em;font-weight:400;color:#666}.sab-text{display:inline-block;padding:.6em 1.3em;border:1px solid #e08a00;border-radius:999px;background:var(--sab-akzent);color:#111;font-weight:600;line-height:1.2;transition:background .15s}.sab-link:hover .sab-text{background:var(--sab-akzent-dunkel)}.sab-fav{position:absolute;top:-10px;left:14px;padding:2px 10px;border-radius:999px;background:#2e7d32;color:#fff;font-size:.78em;font-weight:600;box-shadow:0 1px 3px rgba(0,0,0,.2)}@media(min-width:560px){.sab-link{flex-direction:row;text-align:left;gap:22px}.sab-bildbox{width:170px}.sab-info{align-items:flex-start;text-align:left}}.sab-hinweis{color:#666}';

    public static function fuss(): void {
        if (!self::$genutzt) { return; }
        if (get_option('sab_karten_css') !== '0') { $bm = max(120, min(800, (int) (get_option('sab_bild_max') ?: 240))); echo '<style id="sab-karten">' . self::KARTEN_CSS . 'a.sab-bild img{max-width:' . $bm . 'px;max-height:' . $bm . 'px;object-fit:contain}</style>'; }
        if (self::$karten && get_option('sab_karten_css') !== '0') { $kd_ = self::kd_opt(); echo '<style id="sab-karte">' . self::KARTE_CSS . ($kd_ ? self::KD_CSS . '.sab-karte{' . self::kd_vars($kd_) . '}' : '') . '</style>'; }
        if (get_option('sab_hinweis_auto') === '1' && !self::$hinweis_gezeigt) {
            echo '<p class="sab-hinweis"><small>' . esc_html(self::hinweis_text(self::land(''))) . '</small></p>';
        }
        $n = self::$links;
        if ($n >= self::LINK_WARNUNG && function_exists('get_queried_object_id')) {
            $id = (int) get_queried_object_id();
            if ($id) {
                $w = (array) get_option('sab_linkwarnung', []);
                if (($w[$id]['n'] ?? 0) !== $n) {
                    $w[$id] = ['n' => $n, 'ts' => time()];
                    update_option('sab_linkwarnung', $w, false);
                }
            }
            // gelber Hinweis im Frontend entfernt (Shogen 27.10.2026); die Zahl wird weiter für die Verwaltung gemerkt
        }
    }


    private static $tag_ueber = '';

    /** Zusätzliche Partner-IDs (Feld „Weitere Partner-IDs“), die ein Platzhalter per tag="…" / tracking_id="…" verwenden darf. */
    private static function tag_erlaubt(string $t): string {
        $t = trim($t);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9\-]{2,39}$/', $t)) { return ''; }
        $ok = array_values(self::tags());
        foreach (preg_split('/\R/', (string) get_option('sab_tags_extra', '')) as $z) { $z = trim(preg_split('/[\s|=,]+/', trim($z))[0] ?? ''); if ($z !== '') { $ok[] = $z; } }
        return in_array($t, $ok, true) ? $t : '';
    }

    /** Amazon-Link mit anderer Partner-ID als der Standard (nur erlaubte IDs). */
    private static function lnk(string $link, string $t = ''): string {
        $t = self::tag_erlaubt($t !== '' ? $t : self::$tag_ueber);
        if ($t === '' || $link === '') { return $link; }
        if (preg_match('/([?&])tag=[^&#]*/', $link)) { return preg_replace('/([?&])tag=[^&#]*/', '${1}tag=' . $t, $link, 1); }
        return $link . (strpos($link, '?') === false ? '?' : '&') . 'tag=' . rawurlencode($t);
    }

    public static function sc_bild($a, $inhalt = ''): string {
        $a = shortcode_atts(['asin' => '', 'land' => '', 'breite' => '', 'alt' => '', 'class' => '', 'tag' => '', 'bild' => ''], $a);
        $land = self::land($a['land']);
        $asin = self::getauscht(self::asin($a['asin']));
        $p = self::produkt($asin, $land);
        if (!self::brauchbar($p) && !self::hat_bild($p)) { return (string) $inhalt !== '' ? (string) $inhalt : self::bild_fehlt($asin); }  // Rückfall: bisheriger HTML-Code; ohne Bild nie still verschwinden
        $p['link'] = self::lnk((string) $p['link'], (string) $a['tag']);
        [$bi, $bv] = self::bildvariante($asin, $p);
        if (ctype_digit((string) $a['bild']) && (int) $a['bild'] >= 2 && !empty($p['bilder'][(int) $a['bild'] - 1])) { $bi = (int) $a['bild'] - 1; $bv = ''; }   // image="2" / "3" aus AAWP: zweites/drittes Bild
        $cls = trim(preg_replace('/[^A-Za-z0-9 _-]/', '', (string) $a['class']));
        return self::bild_html($p, $asin, $land, $a['alt'] ?: $p['titel'], (int) $a['breite'], $bv !== '' ? 'bild-' . $asin : '', $cls, $bi, $bv)
            ?: ($bi ? self::bild_html($p, $asin, $land, $a['alt'] ?: $p['titel'], (int) $a['breite'], '', $cls) : '')
            ?: ((string) $inhalt !== '' ? (string) $inhalt : self::bild_fehlt($asin));
    }

    /**
     * AAWP-Kompatibilität: bestehende [aawp ...]-Shortcodes laufen weiter über dieses Plugin.
     *   [aawp fields="ASIN" value="thumb|image"]  => Bild      (image_size wird ignoriert, image_width wird als Höchstbreite übernommen)
     *   [aawp fields="ASIN" value="title"]        => Titel als Link;  value="button" => Button-Link
     *   [aawp box="ASIN"]                          => Karte
     *   [aawp link="ASIN"]Text[/aawp]             => Textlink
     * Mehrere ASINs mit Komma. Alles andere (Preis, Bewertung, Tabellen usw.) bleibt absichtlich leer.
     * Tippfehler wie ein überzähliges Zeichen am Ende der ASIN werden abgeschnitten.
     */
    public static function sc_aawp($a, $inhalt = ''): string {
        $a = shortcode_atts(['fields' => '', 'box' => '', 'link' => '', 'value' => 'thumb', 'image_size' => '', 'image_width' => '', 'image_height' => '', 'class' => '', 'land' => '', 'title' => '', 'tracking_id' => '', 'image' => ''], (array) $a);
        $tg = self::tag_erlaubt((string) $a['tracking_id']);
        $quelle = $a['box'] !== '' ? 'box' : ($a['link'] !== '' ? 'link' : 'fields');
        $out = '';
        foreach (explode(',', (string) $a[$quelle]) as $roh) {
            $asin = substr(self::asin($roh), 0, 10);
            if (strlen($asin) !== 10) { if ($quelle === 'link' && trim((string) $inhalt) !== '') { $out .= esc_html((string) $inhalt); } continue; }   // der Text bleibt wenigstens stehen
            if ($quelle === 'box') {
                $out .= self::sc_produkt(['asin' => $asin, 'land' => $a['land'], 'tag' => $tg], '');
            } elseif ($quelle === 'link') {
                $out .= self::sc_link(['asin' => $asin, 'land' => $a['land'], 'class' => $a['class'], 'tag' => $tg], (string) ($inhalt ?: $a['title']));
            } else {
                $v = strtolower(trim((string) $a['value']));
                if (in_array($v, ['thumb', 'image', 'thumbnail'], true)) {
                    $out .= self::sc_bild(['asin' => $asin, 'land' => $a['land'], 'class' => $a['class'], 'tag' => $tg, 'bild' => (string) $a['image'], 'breite' => (string) min(self::AAWP_BILD_MAX, (int) $a['image_width'])], '');
                } elseif ($v === 'title') {
                    $out .= self::sc_link(['asin' => $asin, 'land' => $a['land'], 'class' => $a['class'], 'tag' => $tg], '');
                } elseif ($v === 'button') {
                    $out .= self::sc_link(['asin' => $asin, 'land' => $a['land'], 'class' => 'sab-text', 'tag' => $tg], self::BUTTON_TEXT[self::land($a['land'])] ?? self::BUTTON_TEXT['de']);
                }
            }
        }
        return $out;
    }

    /** Steht im Linktext der Name des alten Produkts (Kurzname oder Amazon-Titel), wird er beim Austausch durch den Namen des neuen ersetzt. Sonstige Texte („Hier ansehen“) bleiben. */
    private static function name_tauschen(string $text, string $alt, string $neu, string $land, ?array $p): string {
        $namen = self::produkte(); $altp = (array) get_option("sab_alt_{$land}_{$alt}", []);
        $alte = array_filter([trim((string) ($namen[$alt] ?? '')), trim((string) ($altp['titel'] ?? ''))]);
        $nn = trim((string) ($namen[$neu] ?? '')) ?: trim((string) ($p['titel'] ?? ''));
        if ($nn === '') { return $text; }
        $t = trim(strip_tags($text));
        foreach ($alte as $n) {
            if (mb_strtolower($t) === mb_strtolower($n)) { return $nn; }
            if (mb_strlen($n) >= 6 && mb_stripos($text, $n) !== false) { return str_ireplace($n, $nn, $text); }
        }
        return $text;
    }

    /** [amz_titel asin="…"]: Produktname als reiner Text (Kurzname, sonst Amazon-Titel); folgt automatisch einem Austausch. */
    /** Markenmüll am Anfang eines Amazon-Titels entfernen: Wörter in Fremdschrift (z. B. chinesisch), GROSSBUCHSTABEN-Marken, Buchstaben-Zahlen-Codes. Sonderzeichen werden zu Leerzeichen. */
    public static function titel_ohne_marke(string $t): string {
        $t = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($t), ENT_QUOTES, 'UTF-8')));
        $t = trim((string) preg_replace('/[^\p{Latin}\p{N}\p{P}\p{S}\s]+/u', ' ', $t));   // Fremdschrift überall raus
        $t = trim((string) preg_replace('/\s+/u', ' ', $t));
        $w = $t === '' ? [] : preg_split('/\s+/u', $t);
        while (count($w) > 2) {
            $f = trim($w[0], " ,.;:-–|()[]„“\"'*®™");
            $marke = $f === '' || (preg_match('/^[A-Z0-9]{3,9}$/u', $f) === 1 && preg_match('/[A-Z]/', $f) === 1) || (preg_match('/\d/u', $f) && preg_match('/[A-Za-z]/', $f) && mb_strlen($f) <= 8 && !preg_match('/^\d+[\p{L}]{0,3}$/u', $f));
            if (!$marke) { break; }
            array_shift($w);
        }
        return implode(' ', $w);
    }

    /** Kurztitel für Karten, Widgets und Tauschvorschläge: ohne Markenmüll, auf die Produktart gekürzt, am Ende wenn vorhanden eine Zahl, z. B. „Schneckenzaun-Set, 14-teilig“. */
    public static function kurztitel(string $t, int $max = 48): string {
        $e = explode(' | ', self::kurzname_aus_titel($t), 2); $art = $e[0]; $zahl = $e[1] ?? '';
        $platz = $max - ($zahl !== '' ? mb_strlen($zahl) + 2 : 0);
        if (mb_strlen($art) > $platz) { $art = rtrim(mb_substr($art, 0, max(12, $platz)), " ,.-–"); $art = preg_replace('/[\s,]+\S*$/u', '', $art) ?: $art; }
        return trim($art . ($zahl !== '' ? ', ' . $zahl : ''), " ,.;:-–");
    }

    public static function sc_titel($a, $inhalt = ''): string {
        $a = shortcode_atts(['asin' => '', 'land' => '', 'kurz' => '', 'titel' => ''], $a);
        $orig = self::asin($a['asin']); $asin = self::getauscht($orig);
        if ($asin === $orig && trim((string) $a['titel']) !== '') { return esc_html(trim((string) $a['titel'])); }
        if (strlen($asin) !== 10) { return esc_html((string) $inhalt); }
        $name = self::produkte()[$asin] ?? '';
        if ($name === '') {
            $p = self::produkt($asin, self::land($a['land']));
            $name = trim((string) ($p['titel'] ?? ''));
            $n = (int) $a['kurz'];
            if ($n > 0 && mb_strlen($name) > $n) { $kt = self::kurztitel($name, $n); $name = $kt !== '' ? $kt : rtrim(mb_substr($name, 0, $n), " ,.-–") . '…'; }
        }
        return esc_html($name !== '' ? $name : (string) $inhalt);
    }

    /** Schlichte Karten, auch am Handy zwei nebeneinander (nur CSS, HTML bleibt gleich). */
    const EINZEL_TEXT = ['de' => ['Klick auf das Bild zum', 'Angebot bei Amazon'], 'es' => ['Haz clic en la imagen para ver la', 'oferta en Amazon'], 'fr' => ["Cliquez sur l'image pour voir l'", 'offre sur Amazon'], 'it' => ["Clicca sull'immagine per vedere l'", 'offerta su Amazon'], 'co.uk' => ['Click the image to see the', 'offer on Amazon'], 'com' => ['Click the image to see the', 'offer on Amazon']];
    const KARTE_CSS = '.sab-karte-einzel{max-width:340px;margin-left:auto;margin-right:auto;box-shadow:0 1px 4px rgba(0,0,0,.06)}.sab-karte-einzel .sab-karte-titel{display:block;-webkit-line-clamp:unset;font-size:1.05em;font-weight:700;margin:0 0 2px;color:#1a1a1a}.sab-karte-einzel a.sab-bild{gap:6px}.sab-karte-cta{display:block;font-size:.88em;line-height:1.35;color:#555}.sab-karte-cta-l{color:#1a6b3c;font-weight:700;text-decoration:underline}.sab-karten{display:grid;gap:10px;margin:1.2em 0;align-items:stretch}.sab-karte{display:flex;flex-direction:column;align-items:center;gap:8px;padding:10px;border:1px solid #e6e8e8;border-radius:10px;background:#fff;text-align:center;min-width:0}.sab-karte .sab-bild{display:block;width:100%}.sab-karte .sab-bild img{display:block;width:100%;max-height:200px;object-fit:contain;margin:0 auto}.sab-karte-titel{font-size:.9em;line-height:1.3;color:#333;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;margin-top:auto}.sab-karte-btn{display:block;width:100%;box-sizing:border-box;padding:.55em .4em;min-height:40px;border:1px solid #d9dcdc;border-radius:8px;background:#f7f8f8;color:#1a1a1a!important;font-size:.88em;font-weight:600;text-decoration:none!important}.sab-karte-btn:hover{background:#eef0f0}.sab-karte-ebay{font-size:.8em;color:#666!important}.sab-karte a.sab-bild{display:flex;flex-direction:column;align-items:center;gap:8px;text-decoration:none!important;color:inherit}span.sab-karte-btn{display:block}@media(min-width:600px){.sab-karte{padding:14px;gap:10px}.sab-karte-titel{font-size:.95em}}@media(max-width:320px){.sab-karten{grid-template-columns:1fr!important}}.sab-karten[style*="repeat(1,"]{max-width:300px;margin-left:auto;margin-right:auto}';

    public static function sc_karten($a, $inhalt = ''): string {
        self::$genutzt = true; self::$karten = true;
        // wpautop macht aus den Zeilenumbrüchen zwischen den Karten <br> und <p>: das würde im Raster zusätzliche Spalten erzeugen
        $inhalt = preg_replace('#</?p\b[^>]*>|<br\s*/?>#i', '', (string) $inhalt);
        $n = max(1, min(4, substr_count($inhalt, '[amz_karte')));
        if (!self::$ab_in && !self::$ab_ctx && (!is_array($a) || empty($a['ab'])) && ($za = self::ab_karten_asins_roh($inhalt)) && count($za) === 2 && ($zz = self::ab_zweier($za))) { $zh = self::ab_zweier_html($a, $inhalt, $zz, $za); if ($zh !== '') { return $zh; } }
        $ab = self::ab_karten_start($a);
        $abx = is_array($a) && !empty($a['abx']) && preg_match('/^[a-f0-9]{8}$/', (string) $a['abx']) ? (string) $a['abx'] : '';
        if ($ab) { self::$ab_ctx = ['slot' => 'ab-' . $ab['id'], 'var' => $ab['v']]; }
        $html = do_shortcode(trim($inhalt));
        self::$ab_ctx = null;
        return '<div class="sab-karten" style="grid-template-columns:repeat(' . $n . ',minmax(0,1fr))"' . ($ab ? ' data-sab-ab="' . esc_attr($ab['id']) . '" data-sab-abv="' . $ab['v'] . '" data-sab-abo="' . (int) $ab['o'] . '" data-sab-abi="' . (int) $ab['i'] . '"' : '') . ($abx !== '' ? ' data-sab-abx="' . $abx . '"' : '') . '>' . $html . '</div>' . ($ab && $ab['i'] === 2 ? self::ab_skript($ab) : '');
    }

    /** [amz_karte asin="…" land="es" titel="" ebay_host/campid/suche/customid="…" text="Ver en Amazon" ebay_text="o en eBay"] */
    public static function sc_karte($a, $inhalt = ''): string {
        $a = shortcode_atts(['asin' => '', 'land' => '', 'titel' => '', 'text' => '', 'ebay' => '', 'host' => 'www.ebay.es', 'campid' => '', 'suche' => '', 'customid' => '', 'ebay_text' => '', 'tag' => '', 'stil' => ''], $a);
        $orig = self::asin($a['asin']); $asin = self::getauscht($orig); $land = self::land($a['land']);
        if (strlen($asin) !== 10) { return ''; }
        self::$genutzt = true; self::$karten = true;
        if (!self::$ab_in && !self::$ab_ctx && ($ez = self::ab_einzel($orig))) { $eh = self::ab_einzel_html($a, $ez); if ($eh !== '') { return $eh; } }
        $p = self::produkt($asin, $land);
        // dein Titel aus dem Artikel bleibt stehen; nach einem Austausch kommt der Kurzname des neuen Produkts
        $titel = ($asin === $orig && trim((string) $a['titel']) !== '') ? esc_html(trim((string) $a['titel'])) : self::sc_titel(['asin' => $asin, 'land' => $land, 'kurz' => 70], '');
        if ($asin === $orig && trim((string) $a['titel']) === '' && !isset(self::produkte()[$asin])) { $tk = self::kurztitel(html_entity_decode($titel, ENT_QUOTES, 'UTF-8')); if ($tk !== '') { $titel = esc_html($tk); } }   // nie der lange Amazon-Titel
        $bild = self::brauchbar($p) ? self::sc_bild(['asin' => $asin, 'land' => $land, 'tag' => $a['tag']], '') : self::bild_fehlt($asin, 'Karte');   // nie still ohne Bild: Platzhalter, für Redakteure rot, und Eintrag in der Warnliste
        $btn = self::sc_link(['asin' => $asin, 'land' => $land, 'class' => 'sab-karte-btn', 'tag' => $a['tag']], $a['text'] !== '' ? $a['text'] : (self::BUTTON_TEXT[$land] ?? self::BUTTON_TEXT['de']));
        $ebay = $a['ebay'] !== '' || $a['campid'] !== '' ? self::sc_ebay(['asin' => $orig, 'land' => $land, 'host' => $a['host'], 'campid' => $a['campid'], 'suche' => $a['suche'], 'customid' => $a['customid']], $a['ebay_text'] !== '' ? $a['ebay_text'] : 'eBay') : '';
        if ($ebay !== '') { $ebay = str_replace('<a ', '<a class="sab-karte-ebay" ', $ebay); }
        $tt = $titel !== '' ? '<div class="sab-karte-titel">' . $titel . '</div>' : '';
        $kd = self::kd_opt(); $kc = $kd ? ' sab-kd-' . $kd['stil'] : ''; $ex = '';
        if ($kd && self::brauchbar($p)) {   // Preis und Verfügbarkeit nur aus der Amazon-Abfrage (höchstens 1 Tag alt), mit Stand und Hinweis; Bewertungen werden nie angezeigt
            if ($kd['preis'] && !empty($p['preis'])) { $ex .= '<div class="sab-karte-preis">' . esc_html((string) $p['preis']) . '<small>Stand ' . esc_html(date_i18n('d.m.Y', (int) ($p['ts'] ?? time()))) . ', Preis kann sich ändern</small></div>'; }
            if ($kd['daten']) { $ex .= '<div class="sab-karte-daten">✓ Lieferbar bei Amazon</div>'; }
        }
        if ($a['stil'] === 'einzel' && preg_match('#^(<a\b[^>]*\bclass="sab-bild[^"]*"[^>]*>)(.*)</a>$#s', $bild, $mb)) {   // Einzelprodukt-Karte: Titel über dem Bild, darunter „Klick auf das Bild zum Angebot bei Amazon“, alles EIN Link
            $et = self::EINZEL_TEXT[$land] ?? self::EINZEL_TEXT['de'];
            $cta = '<span class="sab-karte-cta">' . esc_html($et[0]) . ' <span class="sab-karte-cta-l">' . esc_html($a['text'] !== '' ? $a['text'] : $et[1]) . '</span></span>';
            return '<div class="sab-karte sab-karte-einzel' . $kc . '">' . $mb[1] . $tt . $mb[2] . $ex . $cta . '</a>' . $ebay . '</div>';
        }
        // Bild, Titel und Button sind EIN Link (für Crawler zählt das als ein Affiliate-Link); data-sab-asin/-slot/-var bleiben für Statistik und Austausch am Link
        if (preg_match('#^(<a\b[^>]*\bclass="sab-bild[^"]*"[^>]*>)(.*)</a>$#s', $bild, $mb) && preg_match('#^<a\b[^>]*>(.*)</a>$#s', $btn, $mt)) {
            return '<div class="sab-karte' . $kc . '">' . $mb[1] . $mb[2] . $tt . $ex . '<span class="sab-karte-btn">' . $mt[1] . '</span></a>' . $ebay . '</div>';
        }
        return '<div class="sab-karte' . $kc . '">' . $bild . $tt . $ex . $btn . $ebay . '</div>';
    }

    /** [amz_ebay asin="…" host="www.ebay.es" campid="…" suche="…"]Text[/amz_ebay]: eBay-Suchlink; nach einem Austausch sucht er mit dem Namen des neuen Produkts. */
    public static function sc_ebay($a, $inhalt = ''): string {
        $a = shortcode_atts(['asin' => '', 'land' => '', 'host' => 'www.ebay.es', 'campid' => '', 'suche' => '', 'customid' => ''], $a);
        $orig = self::asin($a['asin']); $asin = self::getauscht($orig);
        $suche = trim((string) $a['suche']);
        if ($asin !== $orig || $suche === '') {
            $suche = trim((string) (self::produkte()[$asin] ?? ''));
            if ($suche === '') { $p = self::produkt($asin, self::land($a['land'])); $suche = trim(mb_substr((string) ($p['titel'] ?? ''), 0, 60)); }
        }
        if ($suche === '') { return esc_html((string) $inhalt); }
        $host = preg_match('/^(?:www\.)?ebay\.[a-z.]+$/i', $a['host']) ? $a['host'] : 'www.ebay.es';
        $q = ['_nkw' => $suche, 'mkcid' => 1, 'mkevt' => 1, 'toolid' => 10001];
        $camp = $a['campid'] !== '' ? $a['campid'] : (preg_split('/[\s|=,]+/', trim((string) get_option('sab_ebay_ids', '')))[0] ?? '');
        if (preg_replace('/\D/', '', (string) $camp) !== '') { $q['campid'] = preg_replace('/\D/', '', (string) $camp); }
        if ($a['customid'] !== '') { $q['customid'] = preg_replace('/[^A-Za-z0-9_-]/', '', $a['customid']); }
        return '<a href="' . esc_url('https://' . $host . '/sch/i.html?' . http_build_query($q)) . '" target="_blank" rel="nofollow sponsored noopener">' . esc_html((string) ($inhalt !== '' ? $inhalt : 'eBay')) . '</a>';
    }

    /** Für angemeldete Redakteure: ein totes Produkt (Amazon kennt es nicht mehr) bleibt im Text auffindbar, der Link „Stelle ansehen“ im Umbau-Reiter springt dorthin. */
    private static function weg_marker(string $asin, string $html): string {
        if (!function_exists('is_user_logged_in') || !is_user_logged_in() || !current_user_can('edit_posts')) { return $html; }
        return '<span data-sab-weg="' . esc_attr($asin) . '" data-sab-asin="' . esc_attr($asin) . '" title="Amazon kennt dieses Produkt nicht mehr">' . $html . '</span>';
    }

    /** Im Bearbeiten-Fenster (klassischer Editor): ?sab_zeige=ASIN schaltet auf „Text“, markiert die Stelle im Code und scrollt direkt dorthin. */
    public static function zeige_editor_skript(): void {
        $a = isset($_GET['sab_zeige']) ? preg_replace('/[^A-Za-z0-9]/', '', (string) wp_unslash($_GET['sab_zeige'])) : '';
        $su = isset($_GET['sab_suche']) ? preg_replace('/[^A-Za-z0-9_.:\/=?&%-]/', '', (string) wp_unslash($_GET['sab_suche'])) : '';   // freier Suchtext (z. B. Partner-ID mit Bindestrich) aus dem Scan
        if ($su !== '' && strlen($su) >= 3 && strlen($su) <= 80) { $a = $su; }
        if (strlen($a) < 3 || strlen($a) > 80 || !current_user_can('edit_posts')) { return; }
        $k = max(0, (int) ($_GET['sab_nr'] ?? 1) - 1);   // 1. , 2. … Stelle desselben Produkts im Artikel
        echo '<script>(function(){var a=' . wp_json_encode($a) . ',k=' . $k . ';'
           . 'function ban(t){var b=document.createElement("div");b.style.cssText="position:fixed;left:180px;right:0;top:32px;z-index:99999;background:#d63638;color:#fff;padding:8px 16px;font:600 14px sans-serif;text-align:center";b.textContent=t;b.onclick=function(){b.remove()};document.body.appendChild(b)}'
           . 'function gb(n){n=n||0;var wd=window.wp&&wp.data;var be=wd&&wd.select("core/block-editor");var bl=be?be.getBlocks():[];if(!bl.length){if(n<25){setTimeout(function(){gb(n+1)},400)}else{ban("Der Editor ist nicht geladen; bitte mit Strg+F nach "+a+" suchen.")}return}'
           . 'function fi(l,o){for(var i=0;i<l.length;i++){var b=l[i],t=JSON.stringify(b.attributes||{})+String(b.originalContent||"");if(t.indexOf(a)>=0){o.push(b)}fi(b.innerBlocks||[],o)}return o}'
           . 'var fl=fi(bl,[]),f=fl[k]||fl[0];if(!f){ban("Im Text habe ich "+a+" nicht gefunden (vielleicht über einen Platzhalter oder eine Austausch-Regel).");return}'
           . 'wd.dispatch("core/block-editor").selectBlock(f.clientId);setTimeout(function(){var e=document.getElementById("block-"+f.clientId);if(!e){var fr=document.querySelector("iframe[name=editor-canvas]");e=fr&&fr.contentDocument&&fr.contentDocument.getElementById("block-"+f.clientId)}if(e){e.scrollIntoView({block:"center"})}},300);ban(a+" steht im markierten Block ("+f.name+"). Zum Schließen klicken.")}'
           . 'function hl(el){var o=el.style.cssText;el.style.cssText+=";background:#fff3a3;outline:3px solid #d63638";setTimeout(function(){el.style.cssText=o},6000)}'
           . 'function vis(n){n=n||0;var ed=window.tinyMCE&&tinyMCE.get&&tinyMCE.get("content");if(!ed||!ed.getBody()||!ed.getBody().innerHTML){if(n<30){setTimeout(function(){vis(n+1)},400)}else{txt()}return}'
           . 'var body=ed.getBody(),w=ed.getDoc().createTreeWalker(body,NodeFilter.SHOW_TEXT,null),nd,hit=null,c=0,h1=null;while((nd=w.nextNode())){if(nd.nodeValue.indexOf(a)>=0){if(!h1){h1=nd}if(c++===k){hit=nd;break}}}if(!hit){hit=h1}'
           . 'var qa=body.querySelectorAll("[href*=\""+a+"\"],[data-mce-href*=\""+a+"\"],[src*=\""+a+"\"]");var el=hit?hit.parentNode:(qa[k]||qa[0]);'
           . 'if(!el){txt();return}'
           . 'if(hit){var r=ed.getDoc().createRange(),i=hit.nodeValue.indexOf(a);r.setStart(hit,i);r.setEnd(hit,i+a.length);ed.selection.setRng(r)}'
           . 'var fr=document.getElementById("content_ifr"),rc=el.getBoundingClientRect();'
           . 'try{el.scrollIntoView({block:"center"})}catch(e){}'
           . 'if(fr){window.scrollTo(0,Math.max(0,fr.getBoundingClientRect().top+window.pageYOffset+rc.top-220))}'
           . 'hl(el);ban(a+" steht hier (gelb markiert, Ansicht Visuell). Zum Schließen klicken.")}'
           . 'function txt(){var t=document.getElementById("content");if(!t){return}'
           . 'if(window.switchEditors&&document.getElementById("wp-content-wrap")&&document.getElementById("wp-content-wrap").className.indexOf("tmce-active")>=0){try{switchEditors.go("content","html")}catch(e){}}'
           . 'setTimeout(function(){var v=t.value,i=v.indexOf(a),p=i,c=0;while(i>=0&&c<k){var j=v.indexOf(a,p+1);if(j<0){break}p=j;i=j;c++}if(i<0){ban("Im Text habe ich "+a+" nicht gefunden (vielleicht über einen Platzhalter oder eine Austausch-Regel).");return}'
           . 'var z=v.slice(0,i).split("\n").length,tz=v.split("\n").length;'
           . 'var m=document.createElement("div"),cs=getComputedStyle(t);m.style.cssText="position:absolute;visibility:hidden;white-space:pre-wrap;word-wrap:break-word;top:0;left:-9999px;width:"+t.clientWidth+"px;font:"+cs.font+";padding:"+cs.padding+";letter-spacing:"+cs.letterSpacing;m.textContent=v.slice(0,i);document.body.appendChild(m);var h=m.offsetHeight;m.remove();'
           . 'document.getElementById("wp-content-editor-container").scrollIntoView({block:"start"});t.focus();t.setSelectionRange(i,i+a.length);t.scrollTop=Math.max(0,h-t.clientHeight/2);'
           . 'ban(a+" steht in Zeile "+z+" von "+tz+" (markiert). Zum Schließen klicken.")},600)}'
           . 'function los(){var ta=document.getElementById("content");if(!ta){gb();return}'
           . 'var wrap=document.getElementById("wp-content-wrap");if(wrap&&wrap.className.indexOf("html-active")>=0&&window.switchEditors){try{switchEditors.go("content","tmce")}catch(e){}}'
           . 'vis()}'
           . 'if(document.readyState==="complete"){setTimeout(los,600)}else{window.addEventListener("load",function(){setTimeout(los,600)})}})();</script>';
    }


    // ---------- Produkt an einer Stelle im Artikel tauschen (nur für angemeldete Redakteure, im Frontend) ----------

    const ORD_TAGS = ['amz_produkt', 'amz_bild', 'amz_link', 'amz_karte', 'aawp', 'amazon'];
    private static $ord = 0;
    private static $ord_stack = [];

    private static function ord_aktiv(): bool {
        return function_exists('is_user_logged_in') && is_user_logged_in() && current_user_can('edit_posts') && is_singular() && in_the_loop() && is_main_query();
    }

    public static function ord_reset($c) { self::$ord = 0; self::$ord_stack = []; return $c; }

    public static function ord_pre($r, $tag, $attr, $m) {
        if (in_array($tag, self::ORD_TAGS, true) && !self::$ord_aus && self::ord_aktiv()) { self::$ord++; self::$ord_stack[] = self::$ord; }
        return $r;
    }

    public static function ord_post($out, $tag, $attr, $m) {
        if (!in_array($tag, self::ORD_TAGS, true) || self::$ord_aus || !self::$ord_stack) { return $out; }
        $n = array_pop(self::$ord_stack);
        return str_replace('data-sab-asin="', 'data-sab-ord="' . (int) $n . '" data-sab-asin="', (string) $out);
    }

    // ---------- Besucherland im Browser erkennen (Zeitzone + Browsersprache, keine IP, kein Cookie, keine Übertragung) ----------

    /** Vorgabe-IDs für Slughelp (aus dem bisherigen Seiten-Skript); auf anderen Seiten leer, dort zählen nur die eigenen Partner-IDs. */
    /** Vorbelegte Partner-IDs der Slughelp-Seite (nur dort); fehlt in den Einstellungen ein Land, gilt diese ID. */
    private static function slughelp_ids(): array {
        if (function_exists('home_url') && stripos((string) home_url(), 'slughelp') !== false) {
            return ['co.uk' => 'schnectest08-21', 'com' => 'sh04a0-20', 'ca' => 'sh09cc-20', 'com.au' => 'slughelp00-22', 'de' => 'schnectest-21', 'fr' => 'schnectest03-21', 'it' => 'happy0be-21', 'es' => 'babosa-21'];
        }
        return [];
    }

    private static function geo_ids(): array {
        $vor = self::slughelp_ids();
        $out = [];
        foreach (array_merge($vor, self::tags()) as $l => $t) { if (preg_match('/^(co\.uk|com\.au|com|ca|de|fr|it|es)$/', (string) $l) && preg_match('/^[A-Za-z0-9\-]{3,40}$/', (string) $t)) { $out[(string) $l] = (string) $t; } }
        return $out;
    }

    public static function geo_skript(): void {
        if (get_option('sab_geo') !== '1' || (function_exists('is_admin') && is_admin()) || (function_exists('is_feed') && is_feed())) { return; }
        $ids = self::geo_ids(); if (count($ids) < 2) { return; }
        $js = <<<'JS'
(function(){try{
var T=__T__;
function pick(tz,lang){var r=(String(lang||'').split('-')[1]||'').toUpperCase();
if(/^(Australia\/|Pacific\/Auckland|Pacific\/Chatham)/.test(tz))return 'com.au';
if(/^America\/(Toronto|Vancouver|Edmonton|Winnipeg|Halifax|St_Johns|Regina|Montreal|Moncton|Glace_Bay|Iqaluit|Whitehorse|Yellowknife|Cambridge_Bay|Rankin_Inlet|Resolute|Thunder_Bay|Nipigon|Dawson|Dawson_Creek|Fort_Nelson|Creston|Swift_Current|Goose_Bay|Atikokan|Blanc-Sablon|Inuvik|Pangnirtung|Rainy_River)$/.test(tz))return 'ca';
if(/^(America\/(New_York|Chicago|Denver|Los_Angeles|Phoenix|Anchorage|Detroit|Boise|Juneau|Sitka|Nome|Adak|Menominee|Yakutat|Metlakatla|Indiana\/.*|Kentucky\/.*|North_Dakota\/.*)|Pacific\/Honolulu)$/.test(tz))return 'com';
if(r==='CA'&&/^America\//.test(tz))return 'ca';
if(/^America\//.test(tz))return 'com';
if(/^Europe\/(London|Belfast|Dublin|Jersey|Guernsey|Isle_of_Man)$/.test(tz))return 'co.uk';
if(/^Europe\/(Berlin|Vienna|Zurich|Busingen)$/.test(tz))return 'de';
if(/^Europe\/Paris$/.test(tz))return 'fr';
if(/^Europe\/Rome$/.test(tz))return 'it';
if(/^(Europe\/Madrid|Atlantic\/Canary)$/.test(tz))return 'es';
var m={US:'com',CA:'ca',AU:'com.au',NZ:'com.au',GB:'co.uk',IE:'co.uk',DE:'de',AT:'de',CH:'de',FR:'fr',IT:'it',ES:'es',MX:'com',BR:'com',AR:'com',CL:'com',CO:'com',PE:'com',VE:'com',EC:'com',UY:'com',PY:'com',BO:'com',CR:'com',PA:'com',GT:'com',DO:'com'};
return m[r]||null}
function pre(a){var s='',n=a.previousSibling,l=0,B=/^(P|DIV|LI|TD|TH|TR|H[1-6]|UL|OL|TABLE|SECTION|ARTICLE|BR|FIGURE|BLOCKQUOTE)$/i;
while(n&&l<400){var x=null;if(n.nodeType===3)x=n.textContent;else if(n.nodeType===1&&!B.test(n.nodeName))x=n.textContent;if(x===null)break;s=x+s;l+=x.length;n=n.previousSibling}
s=s.replace(/\s+/g,' ').trim().replace(/[\s.!?:;,—–-]+$/,'');var q=s.split(/[.!?:;—]\s+/);s=(q[q.length-1]||'').trim();
var F=/^(here|hier|now|jetzt|there|dort|click|klick|link|ici|aqui|aquí|qui|on|at|bei|auf|via|amazon|offer|angebot|the|a|an|and|or|und|oder|der|die|das|ein|eine|el|la|le)$/i,w=s.split(' ');
while(w.length&&F.test(w[w.length-1].replace(/[^\wÀ-ɏ.]/g,'')))w.pop();s=w.join(' ');
var T=/\b(order|buy|get|find|purchase|shop|kaufen|kauf|bestellen|bestellst|findest|erhältst|erhaeltst|comprar|compras|encuentras|acheter|trouver|trouve|ordina|compra|trovi)\b/ig,m,e=-1,i0=0;while((m=T.exec(s))){e=m.index+m[0].length;i0=m.index}
if(e>=0&&e<s.length)s=s.slice(e).trim();else if(e>0&&i0>0)s=s.slice(0,i0).trim();else if(e<0){var z=s.split(' ');s=z.slice(-4).join(' ')}
return s.replace(/^(the|a|an|some|our|ein|eine|einen|den|die|das|frische|el|la|los|las|un|una|le|les|des)\s+/i,function(x){return /^(frische)\s/i.test(x)?x:''})}
function run(tz,lang){var w=pick(tz,lang);if(!w||!T[w])return 0;var n=0,all=document.querySelectorAll('a[href*="amazon."]');
for(var i=0;i<all.length;i++){var a=all[i],u;try{u=new URL(a.href)}catch(e){continue}
var h=u.hostname.match(/(?:^|\.)amazon\.(co\.uk|com\.au|com|ca|de|fr|it|es)$/);if(!h||h[1]===w)continue;
if(a.hasAttribute('data-sab-fix'))continue;var tag=u.searchParams.get('tag'),ok=false;for(var k in T){if(T[k]===tag)ok=true}if(!ok)continue;
if(a.hasAttribute('data-sab-home')){a.setAttribute('data-sab-z',w+':h');a.href='https://www.amazon.'+w+'/?tag='+T[w];n++;continue}
var t=a.getAttribute('data-sab-t')||a.getAttribute('data-aawp-product-title')||a.getAttribute('title')||'';
if(!t){var sg=(u.pathname.split('/')[1]||'');if(!/^(dp|gp|product|s|exec|d|o|ref|stores|hz|b)$/i.test(sg)&&!/^[A-Z0-9]{10}$/.test(sg))t=sg.replace(/-/g,' ')}
if(!t)t=pre(a);t=t.replace(/[^\wÀ-ɏ ]+/g,' ').replace(/\s+/g,' ').trim().split(' ').slice(0,8).join(' ');if(!t)continue;
var ma=null,lm=a.getAttribute('data-sab-l');if(lm){lm.split(',').forEach(function(x){var q=x.split(':');if(q[0]===w&&/^[A-Z0-9]{10}$/.test(q[1]||''))ma=q[1]})}
a.setAttribute('data-sab-z',w+':'+(ma?'a':'s'));a.href=ma?'https://www.amazon.'+w+'/dp/'+ma+'?tag='+T[w]:'https://www.amazon.'+w+'/s?k='+encodeURIComponent(t)+'&tag='+T[w];
if(a.hasAttribute('data-aawp-tracking-id'))a.setAttribute('data-aawp-tracking-id',T[w]);n++}
return n}
window.sabGeoPick=pick;window.sabGeoRun=run;
function go(){try{var tz='';try{tz=Intl.DateTimeFormat().resolvedOptions().timeZone||''}catch(e){}
var l=(navigator.languages&&navigator.languages[0])||navigator.language||'';run(tz,l)}catch(e){}}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',go);else go();
}catch(e){}})();
JS;
        echo '<script>' . str_replace('__T__', wp_json_encode($ids), $js) . '</script>';
    }

    /** Alle gezählten Platzhalter eines Artikeltexts in der Reihenfolge, in der WordPress sie ausführt. @return array<int,array{0:string,1:int}> [Text, Position] */
    private static function stellen_finden(string $c): array {
        $out = [];
        if (!preg_match_all('/' . get_shortcode_regex(self::ORD_TAGS) . '/', $c, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) { return $out; }
        foreach ($m as $x) {
            if (($x[1][0] ?? '') === '[' && ($x[6][0] ?? '') === ']') { continue; }   // [[amz_…]] wird von WordPress nicht ausgeführt
            $out[] = [$x[0][0], (int) $x[0][1]];
        }
        return $out;
    }

    private static function stelle_pruefen(): array {
        check_ajax_referer('sab_stelle', 'n');
        $pid = (int) ($_POST['post'] ?? 0);
        if (!$pid || !current_user_can('edit_post', $pid)) { wp_send_json_error('Keine Berechtigung.'); }
        $ord = (int) ($_POST['ord'] ?? 0); $asin = self::asin((string) ($_POST['asin'] ?? ''));
        $post = get_post($pid); if (!$post) { wp_send_json_error('Artikel nicht gefunden.'); }
        $c = (string) $post->post_content; $st = self::stellen_finden($c);
        $nth = (int) ($_POST['nth'] ?? 0);
        if ($ord < 1 && $nth > 0 && strlen($asin) === 10) {   // fest eingetragener Textlink (<a href=…/dp/ASIN>) ohne Platzhalter: n-ter solcher Link, Linktext muss stimmen
            if (!preg_match_all('#<a\b[^>]*\bhref=(["\'])[^"\']*(?<![A-Za-z0-9])' . preg_quote($asin, '#') . '(?![A-Za-z0-9])[^"\']*\1[^>]*>(.*?)</a>#is', $c, $hm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) || !isset($hm[$nth - 1])) { wp_send_json_error('Der Link passt nicht mehr zum Artikeltext. Bitte die Seite neu laden.'); }
            $h = $hm[$nth - 1]; $norm = fn($t) => trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $t))));
            if ($norm($h[2][0]) !== $norm(wp_unslash((string) ($_POST['txt'] ?? '')))) { wp_send_json_error('Der Link passt nicht mehr zum Artikeltext. Bitte die Seite neu laden.'); }
            return [$post, $c, $st, 0, $h[0][0], (int) $h[0][1], $asin, $asin];
        }
        if ($ord < 1 || !isset($st[$ord - 1])) { wp_send_json_error('Die Stelle passt nicht mehr zum Artikeltext. Bitte die Seite neu laden.'); }
        [$tag, $pos] = $st[$ord - 1];
        $quelle = '';
        if (preg_match_all('/\b[A-Z0-9]{10}\b/', $tag, $tm)) { foreach (array_unique($tm[0]) as $t) { if (self::getauscht($t) === $asin) { $quelle = $t; break; } } }
        if ($quelle === '') { wp_send_json_error('An dieser Stelle steht keine feste ASIN (vermutlich eine Ersatzliste/Slot). Bitte im Reiter „Produkte und Ersatzlisten“ ändern.'); }
        return [$post, $c, $st, $ord, $tag, $pos, $quelle, $asin];
    }

    /** Produkttitel direkt an der Stelle: bei einer Einzelbox steht er vor dem Bildlink (Überschrift/Zeile), bei einer Tabellen-Box in der Zelle nach dem Bildlink. Leer, wenn nichts Brauchbares dasteht. */
    /** Nach titel_an_stelle(): lag die Stelle in einer Tabellen-/Spaltenzelle? */
    public static bool $tas_zelle = false;

    public static function titel_an_stelle(string $c, int $pos, int $len): string {
        $zeile = function (string $t): string {
            $t = preg_replace('/\[[^\]]*\]/', ' ', $t); $t = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($t), ENT_QUOTES, 'UTF-8')));
            if ($t === '' || mb_strlen($t) > 140 || mb_strlen($t) < 3) { return ''; }
            if (preg_match('/^(angebot|jetzt|zum angebot|preis|bei amazon|ansehen|hier|mehr|link|button|ab\s+[\d.,]+|[\d.,\s€$£]+$)/iu', $t) || self::generischer_linktext($t)) { return ''; }
            return trim(implode(' ', array_slice(preg_split('/\s+/u', $t), 0, 10)), " :–-|");
        };
        $blk = '#</?(?:p|h[1-6]|div|li|td|th|tr|br|figure|figcaption|ul|ol|table)\b[^>]*>#i';
        $zelle = '#</?(?:td|th|tr|table|tbody|thead)\b[^>]*>|<!--\s*/?wp:(?:column|columns|table)\b#i';   // Zellen-/Spaltengrenzen: Titel anderer Zellen zählen nie
        $kopf = substr($c, max(0, $pos - 3000), min(3000, $pos)); $nach = substr($c, $pos + $len, 3000);   // weit genug, damit Anfang und Ende der Zelle auch bei viel Markup gefunden werden
        $in_zelle = false; self::$tas_zelle = false;
        if (preg_match_all($zelle, $kopf, $zm, PREG_OFFSET_CAPTURE)) { $l_ = end($zm[0]); $kopf = substr($kopf, $l_[1] + strlen($l_[0])); $in_zelle = !preg_match('#^</(?:td|th|tr|table)#i', $l_[0]) || false; if (preg_match('#^<(?:td|th)\b#i', $l_[0]) || preg_match('#wp:column#i', $l_[0])) { $in_zelle = true; } }
        if (preg_match($zelle, $nach, $zn, PREG_OFFSET_CAPTURE)) { $nach = substr($nach, 0, $zn[0][1]); }
        self::$tas_zelle = $in_zelle;
        if ($in_zelle) { $kopf = substr($kopf, -1500); if (preg_match('#<a\b|\[amz_#i', $nach, $nl, PREG_OFFSET_CAPTURE)) { $nach = substr($nach, 0, $nl[0][1]); } }   // in der Zelle steht der Titel zwischen Bildlink und Textlink, nie hinter dem nächsten Link (eBay) und nie außerhalb der Zelle
        // Steht vor der Stelle ein Link/Platzhalter desselben Produkts (Bild- oder Produktlink, auch wenn Amazon kein Bild mehr liefert), gilt zuerst der Text dazwischen (Zweier-Tabelle: Titel zwischen Bild- und Textlink). Andere Bilder in der Nähe zählen nie.
        if (preg_match('/\b([A-Z0-9]{10})\b/', substr($c, $pos, $len), $am)) {
            $as = preg_quote($am[1], '#'); $ende = -1;
            if (preg_match_all('#<a\b[^>]*' . $as . '[^>]*>.*?</a>#is', $kopf, $m1, PREG_OFFSET_CAPTURE)) { $l_ = end($m1[0]); $ende = max($ende, $l_[1] + strlen($l_[0])); }
            if (preg_match_all('#\[amz_[a-z]+\b[^\]]*' . $as . '[^\]]*\](?:(?:(?!\[amz_).){0,600}?\[/amz_[a-z]+\])?#is', $kopf, $m2, PREG_OFFSET_CAPTURE)) { $l_ = end($m2[0]); $ende = max($ende, $l_[1] + strlen($l_[0])); }
            if ($ende >= 0) { foreach (array_slice(preg_split($blk, substr($kopf, $ende)), 0, 4) as $teil) { $z = $zeile((string) $teil); if ($z !== '') { return $z; } } }
        }
        if (!$in_zelle) {   // Textlink mitten im Absatz: der Satz direkt davor erklärt, was man unter dem Link findet
            $sp_ = preg_split($blk, $kopf); $seg = (string) end($sp_);
            $txt = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(preg_replace('/\[[^\]]*\]/', ' ', $seg)), ENT_QUOTES, 'UTF-8')));
            if ($txt !== '' && (preg_match('/[.!?:;,]/u', rtrim($txt, '.!?:;, ')) || count(preg_split('/\s+/u', $txt)) > 6)) { $z_ = self::satz_titel($txt); if ($z_ !== '') { return $z_; } }
        }
        $vor = array_values(array_filter(array_reverse(preg_split($blk, $kopf)), fn($x) => trim(strip_tags((string) $x)) !== '')); $dahinter = array_values(array_filter(preg_split($blk, $nach), fn($x) => trim(strip_tags((string) $x)) !== ''));
        $reihen = $in_zelle ? [$dahinter] : [$vor, $dahinter];   // Tabellen-/Spaltenzelle: Titel steht nach dem Bild; nur bei Einzelprodukten davor
        foreach ($reihen as $liste) { foreach (array_slice($liste, 0, 2) as $teil) { $z = $zeile((string) $teil); if ($z !== '') { return $z; } } }
        return '';
    }

    /** Suchtitel für „Bei Amazon suchen“: derselbe wie im Reiter Austausch (bekannter Produktname), sonst der Titel an der Stelle, sonst Schlüsselbegriffe aus dem Satz davor. */
    private static function suche_titel(string $asin, array $d, array $fund, string $an_stelle, bool $zelle = false): string {
        if ($zelle && $an_stelle !== '') { return $an_stelle; }   // Tabellenzelle: Titel zwischen Bildlink und Textlink derselben Zelle ist maßgeblich
        $nm = self::anzeige_name($asin, $d, $fund);
        if (trim($nm) !== '' && !self::generischer_linktext($nm)) { return $nm; }
        return $an_stelle !== '' ? $an_stelle : self::suchtext_aus_satz('', $asin, self::text_vor_stelle($asin, $fund[$asin] ?? []));
    }

    /** Frontend-Stelle mit nicht auflösbarem Kurzlink: n-ter Link mit genau dieser Adresse, Linktext muss stimmen. @return array{0:WP_Post,1:string,2:string,3:int,4:string} [Beitrag, Inhalt, Link-HTML, Position, Adresse] */
    private static function kurz_stelle(): array {
        check_ajax_referer('sab_stelle', 'n');
        $pid = (int) ($_POST['post'] ?? 0);
        if (!$pid || !current_user_can('edit_post', $pid)) { wp_send_json_error('Keine Berechtigung.'); }
        $kurz = html_entity_decode((string) wp_unslash($_POST['kurz'] ?? '')); $nth = max(1, (int) ($_POST['nth'] ?? 1));
        if (!preg_match('#^https?://(?:amzn\.to|amzn\.eu|a\.co|link\.amazon)/#i', $kurz)) { wp_send_json_error('Das ist kein Kurzlink.'); }
        $post = get_post($pid); if (!$post) { wp_send_json_error('Artikel nicht gefunden.'); }
        $c = (string) $post->post_content; $hit = [];
        if (preg_match_all('#<a\b[^>]*\bhref=(["\'])(.*?)\1[^>]*>(.*?)</a>#is', $c, $hm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) { foreach ($hm as $h) { if (html_entity_decode($h[2][0]) === $kurz) { $hit[] = $h; } } }
        if (!isset($hit[$nth - 1])) { wp_send_json_error('Der Link passt nicht mehr zum Artikeltext. Bitte die Seite neu laden.'); }
        $h = $hit[$nth - 1]; $norm = fn($t) => trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $t))));
        if ($norm($h[3][0]) !== $norm(wp_unslash((string) ($_POST['txt'] ?? '')))) { wp_send_json_error('Der Link passt nicht mehr zum Artikeltext. Bitte die Seite neu laden.'); }
        return [$post, $c, $h[0][0], (int) $h[0][1], $kurz];
    }

    public static function ajax_stelle_kand(): void {
        if (!empty($_POST['kurz'])) {
            [$post, $c_, $tag_, $pos_] = self::kurz_stelle();
            $land = self::land(''); $d = self::uebersicht_daten($land, false); $namen = self::produkte(); $trotz = (array) get_option('sab_trotz', []);
            $vor = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(preg_replace('/\[[^\]]*\]/', ' ', substr($c_, max(0, $pos_ - 500), min(500, $pos_)))))));
            $teile = preg_split('/(?<=[.!?:;])\s+/u', $vor); $w = array_values(array_filter(preg_split('/\s+/u', trim((string) end($teile), " .!?:;–-"))));
            $suche = count($w) >= 2 ? implode(' ', array_slice($w, -8)) : trim(html_entity_decode(strip_tags($tag_)));
            $ps = []; foreach (self::alle_asins() as $a_) { $ps[] = [(string) $a_, (string) ($namen[$a_] ?? ''), mb_substr((string) ($d['items'][$a_]['titel'] ?? ''), 0, 90), isset($d['items'][$a_]) ? (int) self::score(array_merge((array) $d['items'][$a_], ['trotz' => isset($trotz[$a_])]))[0] : -1]; }
            wp_send_json_success(['alt' => 'Kurzlink „' . mb_substr(trim(strip_tags($tag_)), 0, 40) . '“', 'asin' => '', 'suche' => $suche, 'host' => self::LAENDER[$land][0], 'liste' => [], 'ps' => $ps, 'zurueck' => (bool) get_post_meta($post->ID, '_sab_stelle_backup', true)]);
        }
        [$post, $c_, , , $tag_, $pos_, , $asin] = self::stelle_pruefen();
        $land = self::land(''); $d = self::uebersicht_daten($land, false); $trotz = (array) get_option('sab_trotz', []);
        $fund = self::fundstellen([$asin]); $namen = self::produkte();
        $name = ($namen[$asin] ?? '') ?: self::kurzname_aus_titel((string) ($d['items'][$asin]['titel'] ?? ''));
        $k = self::austausch_kandidaten($asin, (string) $name, (array) $d, $trotz, $fund, 8);
        $out = [];
        foreach ($k['liste'] as $c) {
            $x = (array) $c['x'];
            $img = !empty($x['bilder'][0]) ? (string) $x['bilder'][0] : '';
            $out[] = ['asin' => $c['asin'], 'name' => ($namen[$c['asin']] ?? '') ?: self::kurzname_aus_titel((string) ($x['titel'] ?? '')), 'score' => $c['score'], 'preis' => (string) ($x['preis'] ?? ''), 'img' => $img, 'quelle' => $c['quelle'], 'ok' => (bool) $c['ok']];
        }
        $ps = []; foreach (self::alle_asins() as $a_) { $ps[] = [(string) $a_, (string) ($namen[$a_] ?? ''), mb_substr((string) ($d['items'][$a_]['titel'] ?? ''), 0, 90), isset($d['items'][$a_]) ? (int) self::score(array_merge((array) $d['items'][$a_], ['trotz' => isset($trotz[$a_])]))[0] : -1]; }
        wp_send_json_success(['alt' => $name ?: $asin, 'asin' => $asin, 'suche' => self::suche_titel($asin, (array) $d, $fund, $ts_ = self::titel_an_stelle($c_, $pos_, strlen($tag_)), self::$tas_zelle), 'host' => self::LAENDER[$land][0], 'liste' => $out, 'ps' => $ps, 'zurueck' => (bool) get_post_meta($post->ID, '_sab_stelle_backup', true)]);
    }

    /**
     * Titel des alten Produkts an der Stelle durch den des neuen ersetzen, nur wenn er eindeutig erkannt ist:
     * Einzelprodukt = ein fetter Text (strong/b) unmittelbar vor dem Bild(link); Zelle = Text zwischen Bild(link) und Textlink derselben Zelle.
     * Alles andere bleibt unverändert. @return array{0:string,1:bool} [neuer Text, ersetzt]
     */
    public static function titel_ersetzen(string $c, string $neutitel, bool $zelle): array {
        $neutitel = trim(preg_replace('/\s+/u', ' ', strip_tags($neutitel)));
        if ($neutitel === '' || mb_strlen($neutitel) > 140) { return [$c, false]; }
        $nt = htmlspecialchars($neutitel, ENT_NOQUOTES, 'UTF-8');
        if ($zelle) {
            $re = '#(\[/amz_bild\]|\[amz_produkt\b[^\]]*\]|<img\b[^>]*>\s*</a>)(\s*(?:<br\s*/?>\s*)+(?:<(?:strong|b)\b[^>]*>)?)([^<\[\]]{5,140})((?:</(?:strong|b)>)?\s*(?:<br\s*/?>\s*)+)(?=<a\b|\[amz_link)#i';
            if (preg_match_all($re, $c) !== 1) { return [$c, false]; }
            return [preg_replace_callback($re, fn($m) => $m[1] . $m[2] . $nt . $m[4], $c, 1), true];
        }
        $re = '#(<(strong|b)\b[^>]*>)([^<\[\]]{5,140})(</\2>)((?:\s|<br\s*/?>|</p>|</div>|<p\b[^>]*>|<div\b[^>]*>)*)$#i';
        if (!preg_match($re, $c, $m, PREG_OFFSET_CAPTURE)) { return [$c, false]; }
        if (preg_match('#https?://#', $m[3][0])) { return [$c, false]; }
        return [substr($c, 0, $m[3][1]) . $nt . substr($c, $m[3][1] + strlen($m[3][0])), true];
    }

    // ---------- Kartendesign ----------
    const KD_PRESETS = ['dezent' => ['Dezent', 'dezent', 0, 0], 'auffaellig' => ['Auffällig', 'auffaellig', 0, 0], 'dezent_preis' => ['Dezent mit Preis', 'dezent', 1, 0], 'auffaellig_preis' => ['Auffällig mit Preis', 'auffaellig', 1, 0], 'daten' => ['Mit Produktdaten', 'dezent', 1, 1]];
    const KD_FARBEN = ['Dezent' => ['#6b7f8c', '#7d8f6a', '#9a8878'], 'Mittel' => ['#2271b1', '#2e8b57', '#c26a00'], 'Auffällig' => ['#ff6a00', '#e0245e', '#7c3aed']];
    const KD_GIMMICKS = ['' => 'keins', '🐌' => 'Schnecke', '🦔' => 'Igel', '🐦' => 'Vogel', '🐭' => 'Maus', '🐛' => 'Raupe', '🐝' => 'Biene', '🦋' => 'Schmetterling', '🐸' => 'Frosch'];
    const KD_CSS = '.sab-karte{position:relative;border-radius:var(--kr,10px)}.sab-karte::before{content:var(--kg,none);position:absolute;top:-16px;right:var(--kgr,12px);left:var(--kgl,auto);font-size:28px;line-height:1;pointer-events:none}.sab-kd-dezent .sab-karte-btn{background:#fff;border:1px solid var(--kd);color:var(--kd)!important}.sab-kd-auffaellig{border:2px solid var(--kd);background:var(--kdh);box-shadow:0 4px 14px rgba(0,0,0,.12)}.sab-kd-auffaellig .sab-karte-btn{background:var(--kd);border-color:var(--kd);color:var(--kdt)!important}.sab-kd-auffaellig .sab-karte-titel{font-weight:700}.sab-karte-btn{border-radius:calc(var(--kr,10px) * .7)}.sab-karte-preis{font-weight:700;font-size:1.15em;color:#111}.sab-karte-preis small{display:block;font-weight:400;font-size:.62em;color:#666}.sab-karte-daten{font-size:.82em;color:#1a7f37}';

    /** @var array|null|false Zwischenspeicher je Aufruf (false = noch nicht gelesen) */
    public static $kd_cache = false;

    /** Gespeichertes Kartendesign oder null (dann bleibt die Karte wie bisher). */
    public static function kd_opt(): ?array {
        if (self::$kd_cache !== false) { return self::$kd_cache; }
        $o = &self::$kd_cache;
        $r = get_option('sab_kd'); if (!is_array($r) || empty($r['preset']) || !isset(self::KD_PRESETS[$r['preset']])) { return $o = null; }
        $pr = self::KD_PRESETS[$r['preset']];
        return $o = ['preset' => $r['preset'], 'stil' => $pr[1], 'preis' => (bool) $pr[2], 'daten' => (bool) $pr[3], 'farbe' => preg_match('/^#[0-9a-f]{6}$/i', (string) ($r['farbe'] ?? '')) ? strtolower($r['farbe']) : '#2271b1', 'ecken' => max(0, min(28, (int) ($r['ecken'] ?? 10))), 'gimmick' => isset(self::KD_GIMMICKS[(string) ($r['gimmick'] ?? '')]) ? (string) $r['gimmick'] : '', 'seite' => ($r['seite'] ?? 'r') === 'l' ? 'l' : 'r'];
    }

    /** Lesbare Textfarbe auf der Akzentfarbe. */
    public static function kd_textfarbe(string $hex): string {
        $r = hexdec(substr($hex, 1, 2)); $g = hexdec(substr($hex, 3, 2)); $b = hexdec(substr($hex, 5, 2));
        return (0.299 * $r + 0.587 * $g + 0.114 * $b) > 150 ? '#1a1a1a' : '#ffffff';
    }

    /** CSS-Variablen des Designs (gelten für .sab-karte). */
    public static function kd_vars(array $o): string {
        return '--kd:' . $o['farbe'] . ';--kdh:' . $o['farbe'] . '18;--kdt:' . self::kd_textfarbe($o['farbe']) . ';--kr:' . (int) $o['ecken'] . 'px;'
            . ($o['gimmick'] !== '' ? '--kg:"' . $o['gimmick'] . '";' . ($o['seite'] === 'l' ? '--kgl:12px;--kgr:auto;' : '--kgr:12px;--kgl:auto;') : '');
    }

    private static function kd_speichern(): string {
        self::$kd_cache = false;
        if (!empty($_POST['kd_reset'])) { delete_option('sab_kd'); return 'Kartendesign zurückgesetzt: Karten sehen wieder aus wie vorher.'; }
        $pre = sanitize_key(wp_unslash($_POST['kd_preset'] ?? ''));
        if (!isset(self::KD_PRESETS[$pre])) { return 'Bitte ein Design wählen.'; }
        $f = (string) wp_unslash($_POST['kd_farbe'] ?? ''); $f = preg_match('/^#[0-9a-f]{6}$/i', $f) ? strtolower($f) : '#2271b1';
        $g = (string) wp_unslash($_POST['kd_gimmick'] ?? ''); if (!isset(self::KD_GIMMICKS[$g])) { $g = ''; }
        update_option('sab_kd', ['preset' => $pre, 'farbe' => $f, 'ecken' => max(0, min(28, (int) ($_POST['kd_ecken'] ?? 10))), 'gimmick' => $g, 'seite' => (($_POST['kd_seite'] ?? 'r') === 'l') ? 'l' : 'r'], false);
        return 'Kartendesign gespeichert. Es gilt für alle Karten (einzeln und mehrere) auf der ganzen Website.';
    }

    /** Reiter „Kartendesign“: fertige Designs mit Vorschau, Farbe, Ecken und Tierchen; gilt für alle Karten. */
    private static function kd_html(): string {
        $o = self::kd_opt() ?: ['preset' => 'dezent', 'stil' => 'dezent', 'preis' => false, 'daten' => false, 'farbe' => '#2271b1', 'ecken' => 10, 'gimmick' => '', 'seite' => 'r'];
        $aktiv = self::kd_opt() !== null;
        $h = '<h2>Kartendesign</h2><p class="description">Wähle ein Design, eine Farbe und die Rundung der Ecken. Die Vorschau ändert sich sofort; erst „Speichern“ übernimmt es für alle Karten (einzeln und nebeneinander). Preis und „Lieferbar“ kommen nur aus der Amazon-Abfrage (höchstens 1 Tag alt, mit Datum); Bewertungen zeigen wir nicht an, das erlaubt Amazon nicht.' . ($aktiv ? '' : ' <strong>Noch nichts gespeichert: die Karten sehen aus wie bisher.</strong>') . '</p>';
        $h .= '<style>' . self::KARTE_CSS . self::KD_CSS . '.kd-vor{margin:0 0 14px;padding:12px;border:2px solid #dcdcde;border-radius:8px;background:#f6f7f7;max-width:520px}.kd-vor label{display:block;font-weight:600;margin-bottom:6px}.kd-vor .sab-karten{margin:14px 0 0}.kd-ph{height:70px;width:100%;background:#e3e6e6;border-radius:6px;display:flex;align-items:center;justify-content:center;color:#777;font-size:12px}.kd-sw{width:30px;height:30px;border-radius:50%;border:2px solid #fff;box-shadow:0 0 0 1px #888;cursor:pointer;padding:0;margin:0 4px 4px 0}.kd-gim label{display:inline-block;margin:0 10px 6px 0}</style>';
        $h .= '<form method="post" id="kd-form">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="kartendesign"><input type="hidden" name="sab_form" value="kartendesign">';
        $h .= '<h3>1. Design</h3><div style="display:flex;flex-wrap:wrap;gap:0 18px">';
        foreach (self::KD_PRESETS as $k => $pr) {
            $h .= '<div class="kd-vor"><label><input type="radio" name="kd_preset" value="' . esc_attr($k) . '"' . ($o['preset'] === $k ? ' checked' : '') . '> ' . esc_html($pr[0]) . '</label><div class="kd-box" style="' . esc_attr(self::kd_vars($o)) . '"><div class="sab-karten" style="grid-template-columns:repeat(2,minmax(0,1fr))">';
            for ($i = 1; $i <= 2; $i++) {
                $h .= '<div class="sab-karte sab-kd-' . $pr[1] . '"><div class="kd-ph">Produktbild</div><div class="sab-karte-titel">Beispielprodukt ' . $i . ' für den Garten</div>'
                    . ($pr[2] ? '<div class="sab-karte-preis">19,99 €<small>Stand ' . esc_html(date_i18n('d.m.Y')) . ', Preis kann sich ändern</small></div>' : '') . ($pr[3] ? '<div class="sab-karte-daten">✓ Lieferbar bei Amazon</div>' : '') . '<span class="sab-karte-btn">Bei Amazon ansehen</span></div>';
            }
            $h .= '</div></div></div>';
        }
        $h .= '</div><h3>2. Farbe</h3><p>';
        foreach (self::KD_FARBEN as $gruppe => $fs) {
            $h .= '<strong>' . esc_html($gruppe) . ':</strong> ';
            foreach ($fs as $f) { $h .= '<button type="button" class="kd-sw" data-f="' . esc_attr($f) . '" style="background:' . esc_attr($f) . '" title="' . esc_attr($f) . '"></button>'; }
            $h .= ' &nbsp; ';
        }
        $h .= '<br>Eigene Farbe: <input type="color" name="kd_farbe" value="' . esc_attr($o['farbe']) . '"></p>';
        $h .= '<h3>3. Ecken</h3><p><input type="range" name="kd_ecken" min="0" max="28" value="' . (int) $o['ecken'] . '"> <span id="kd-ev">' . (int) $o['ecken'] . '</span> px (0 = eckig)</p>';
        $h .= '<h3>4. Tierchen (optional)</h3><p class="kd-gim">';
        foreach (self::KD_GIMMICKS as $g => $n) { $h .= '<label><input type="radio" name="kd_gimmick" value="' . esc_attr($g) . '"' . ($o['gimmick'] === $g ? ' checked' : '') . '> ' . ($g !== '' ? $g . ' ' : '') . esc_html($n) . '</label>'; }
        $h .= '<br>Seite: <label><input type="radio" name="kd_seite" value="l"' . ($o['seite'] === 'l' ? ' checked' : '') . '> links</label> <label><input type="radio" name="kd_seite" value="r"' . ($o['seite'] !== 'l' ? ' checked' : '') . '> rechts</label></p>';
        $h .= '<p><button class="button button-primary">Speichern</button> <button class="button" name="kd_reset" value="1" onclick="return confirm(\'Wieder wie vorher?\')">Zurücksetzen (wie bisher)</button></p></form>';
        $h .= '<script>(function(){var f=document.getElementById("kd-form");function lum(h){var r=parseInt(h.substr(1,2),16),g=parseInt(h.substr(3,2),16),b=parseInt(h.substr(5,2),16);return .299*r+.587*g+.114*b>150?"#1a1a1a":"#ffffff"}'
           . 'function upd(){var c=f.kd_farbe.value,r=f.kd_ecken.value,g=f.kd_gimmick.value,l=f.kd_seite.value==="l";document.getElementById("kd-ev").textContent=r;var v="--kd:"+c+";--kdh:"+c+"18;--kdt:"+lum(c)+";--kr:"+r+"px;"+(g?"--kg:\\""+g+"\\";"+(l?"--kgl:12px;--kgr:auto;":"--kgr:12px;--kgl:auto;"):"");var b=f.querySelectorAll(".kd-box");for(var i=0;i<b.length;i++){b[i].style.cssText=v}}'
           . 'f.addEventListener("input",upd);f.addEventListener("change",upd);var s=f.querySelectorAll(".kd-sw");for(var i=0;i<s.length;i++){s[i].onclick=function(){f.kd_farbe.value=this.getAttribute("data-f");upd()}}upd()})();</script>';
        return $h;
    }

    /** Frontend „＋ Karte“: setzt hinter den Absatz mit dem Textlink eine einzelne Karte (Bild + Textlink) desselben Produkts. Mit Sicherung, Rückgängig wie beim Tausch. */
    public static function ajax_stelle_karte(): void {
        [$post, $c, $st, $ord, $tag, $pos, $quelle, $asin] = self::stelle_pruefen();
        $fest = $ord < 1 && stripos($tag, '<a') === 0;
        $ph_bild = $ord > 0 && preg_match('/^\[amz_bild\b/i', $tag) === 1;
        $ph_text = $ord > 0 && preg_match('/^\[amz_link\b/i', $tag) === 1;
        if (!$fest && !$ph_bild && !$ph_text) { wp_send_json_error('Hier steht schon eine Karte oder Box. „Karte“ gibt es nur für Bild- und Textlinks.'); }
        if (!self::hat_bild(self::produkt($asin, self::land(''))) && !self::neues_produkt_vorladen($asin)) { wp_send_json_error('Amazon liefert zu ' . $asin . ' gerade kein Bild. Keine Karte eingefügt. Bitte später noch einmal versuchen.'); }
        $land = '';
        if ($fest && preg_match('/\bhref=(["\'])(.*?)\1/is', $tag, $hm)) { foreach (self::LAENDER as $k => $v) { if (stripos($hm[2], $v[0]) !== false) { $land = $k; break; } } }
        elseif (!$fest && preg_match('/\bland=["\']([a-z.]+)["\']/i', $tag, $lm) && isset(self::LAENDER[strtolower($lm[1])])) { $land = strtolower($lm[1]); }
        $kopf = static function (string $titel, bool $einzel = false) use ($asin, $land): string { return '[amz_karten][amz_karte asin="' . $asin . '"' . ($land !== '' && $land !== self::land('') ? ' land="' . $land . '"' : '') . ($titel !== '' ? ' titel="' . $titel . '"' : '') . ($einzel ? ' stil="einzel"' : '') . '][/amz_karten]'; };
        $pb = self::partner_bereich($c, $pos, strlen($tag), $quelle);
        if ($pb) {   // getrennter Bild- und Textlink desselben Produkts: beide werden zu EINER Karte (ein Affiliate-Link)
            $gap = ''; $inner = substr($c, $pb[0], $pb[1] - $pb[0]);
            $gt = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(preg_replace('/\[[^\]]*\]/', ' ', preg_replace(['#<a\b[^>]*>.*?</a>#is', '#\[amz_(bild|link)\b[^\]]*\].*?\[/amz_\1\]#is'], ' ', $inner))))));
            if (preg_match('/^(?:klick(?:e)?\s+auf\s+das\s+bild|zum|zur|hier|angebot|mehr|bei amazon|ansehen)\b/iu', $gt) || self::generischer_linktext($gt)) { $gt = ''; }
            if ($gt !== '' && mb_strlen($gt) <= 90) { $gap = str_replace(['"', '[', ']'], '', $gt); }
            $vor = substr($c, 0, $pb[0]);
            // Produkttitel steht als eigene Zeile über dem Bild (Einzelprodukt): wird zum Kartentitel und aus dem Artikel genommen, damit er nicht doppelt steht
            if ($gap === '' && preg_match('#(?:<!--\s*wp:(?:paragraph|heading)[^>]*-->\s*)?<(p|h[1-6])\b[^>]*>((?:(?!</?(?:p|h[1-6])\b).)*?)</\1>\s*(?:<!--\s*/wp:(?:paragraph|heading)\s*-->\s*)?$#is', $vor, $tm0, PREG_OFFSET_CAPTURE) && !preg_match('#<a\b|\[|<img#i', $tm0[2][0])) {
                $tt0 = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($tm0[2][0]), ENT_QUOTES, 'UTF-8')));
                if (mb_strlen($tt0) >= 3 && mb_strlen($tt0) <= 90 && !preg_match('/[.!?]\s*$/u', $tt0) && !self::generischer_linktext($tt0)) { $gap = str_replace(['"', '[', ']'], '', $tt0); $vor = substr($vor, 0, $tm0[0][1]); }
            }
            $c2 = $vor . $kopf($gap, true) . substr($c, $pb[1]);
        } elseif ($ph_bild) {
            $c2 = substr($c, 0, $pos) . $kopf('') . substr($c, $pos + strlen($tag));
        } else {
            $sc = $kopf('');
            if (strpos($c, '<!-- wp:') !== false) { $sc = "<!-- wp:shortcode -->\n" . $sc . "\n<!-- /wp:shortcode -->"; }
            $ende = $pos + strlen($tag); $ins = $ende;
            $pe = stripos($c, '</p>', $ende);
            if ($pe !== false && $pe - $ende < 3000) {
                $zw = substr($c, $ende, $pe - $ende);
                if (!preg_match('#<p[\s>]|</?li\b|</?td\b|</?div\b#i', $zw)) { $ins = $pe + 4; if (preg_match('#\G\s*<!-- /wp:paragraph -->#', $c, $m2, 0, $ins)) { $ins += strlen($m2[0]); } }
            }
            $c2 = substr($c, 0, $ins) . "\n\n" . $sc . "\n\n" . substr($c, $ins);
        }
        $b = (array) get_post_meta($post->ID, '_sab_stelle_backup', true);
        $b = array_slice(array_merge($b, [['ts' => time(), 'hash' => md5($c2), 'vorher' => $c, 'alt' => $asin, 'neu' => $asin]]), -5);
        update_post_meta($post->ID, '_sab_stelle_backup', wp_slash($b));
        wp_update_post(['ID' => $post->ID, 'post_content' => wp_slash($c2)]);
        delete_transient('sab_alle_asins');
        wp_send_json_success(['asin' => $asin, 'nr' => max(1, (int) ($_POST['nth'] ?? 1))]);
    }

    /**
     * Bild und Textlink desselben Produkts, die direkt nebeneinander stehen (nur kurzer Titel/Zeilenumbruch dazwischen, kein Absatz-, Listen-, Zellen-, Spalten- oder Überschriftenwechsel),
     * gehören zusammen und werden zusammen getauscht. Im Zweifel (anderes Produkt dazwischen, zwei mögliche Partner) wird NICHT gekoppelt.
     * @return array{0:int,1:int}|null [von, bis] des gemeinsamen Bereichs
     */
    public static function partner_bereich(string $c, int $pos, int $len, string $asin): ?array {
        $el = [];
        foreach (self::stellen_finden($c) as [$t, $p]) { $el[$p] = [$p, $p + strlen($t), $t]; }
        if (preg_match_all('#<a\b[^>]*>.*?</a>#is', $c, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$t, $p]) { $drin = false; foreach ($el as $e) { if ($p >= $e[0] && $p < $e[1]) { $drin = true; break; } } if (!$drin) { $el[$p] = [$p, $p + strlen($t), $t]; } }
        }
        ksort($el); $el = array_values($el); $i = -1;
        foreach ($el as $k => $e) { if ($e[0] === $pos) { $i = $k; break; } }
        if ($i < 0 || $asin === '') { return null; }
        $bild = fn(string $t): bool => stripos($t, '<img') !== false || preg_match('/^\[amz_(?:bild|produkt)\b/', $t) === 1 || preg_match('/^\[(?:aawp|amazon)\b[^\]]*value=["\'](?:thumb|image|thumbnail)/i', $t) === 1;
        if (preg_match('/^\[amz_karte\b/', $el[$i][2])) { return null; }
        $ich = $bild($el[$i][2]); $kand = [];
        foreach ([$i - 1, $i + 1] as $j) {
            if (!isset($el[$j]) || strpos($el[$j][2], $asin) === false || preg_match('/^\[amz_karte\b/', $el[$j][2]) || $bild($el[$j][2]) === $ich) { continue; }
            [$a, $b] = $j < $i ? [$el[$j][1], $el[$i][0]] : [$el[$i][1], $el[$j][0]];
            $gap = substr($c, $a, $b - $a);
            if (strlen($gap) >= 600 || preg_match('#</?(?:h[1-6]|li|ul|ol|td|th|tr|table|hr|section|blockquote)\b|<!--\s*/?wp:(?:column|columns|table|heading|list|group)#i', $gap)) { continue; }   // Absatz-, Zeilen- und Bildumbruch zwischen Bild und Text (Einzelprodukt: Bild, darunter „Klick auf das Bild zum“ + Textlink) gehört noch zum Paar
            $txt = trim(str_replace("\xC2\xA0", ' ', html_entity_decode(strip_tags(preg_replace('/\[[^\]]*\]/', '', $gap)))));
            if (mb_strlen($txt) > 160 || preg_match('#[.!?]\s*$#u', $txt) || preg_match('#[A-Z0-9]{10}#', $gap)) { continue; }
            $kand[] = $j;
        }
        if (count($kand) !== 1) { return null; }
        $j = $kand[0];
        return [min($el[$i][0], $el[$j][0]), max($el[$i][1], $el[$j][1])];
    }

    public static function ajax_stelle_tausch(): void {
        if (!empty($_POST['kurz'])) {
            [$post, $c, $tag, $pos, $kurz] = self::kurz_stelle();
            $neu = self::asin_aus_text((string) wp_unslash($_POST['neu'] ?? ''));
            if (strlen($neu) !== 10) { wp_send_json_error('Bitte eine gültige ASIN oder einen Amazon-Produktlink (mit /dp/…) angeben. Kurzlinks wie amzn.to enthalten keine ASIN.'); }
            if (!self::neues_produkt_vorladen($neu)) { wp_send_json_error('Amazon liefert zu ' . $neu . ' gerade kein Bild oder keine Daten. Nicht getauscht. Bitte Link prüfen oder in ein paar Minuten noch einmal versuchen.'); }
            $u = self::umbau_url((string) wp_unslash($_POST['neu'] ?? '')); $land = $u ? $u[0] : self::land('');
            if (!isset(self::LAENDER[$land])) { $land = self::land(''); }
            $neu_tag = preg_replace('#(\bhref=(["\']))(.*?)(\2)#is', '${1}https://' . self::LAENDER[$land][0] . '/dp/' . $neu . '${4}', $tag, 1);
            if ($neu_tag === null || $neu_tag === $tag) { wp_send_json_error('Nichts geändert.'); }
            $c2 = substr($c, 0, $pos) . $neu_tag . substr($c, $pos + strlen($tag));
            $b = (array) get_post_meta($post->ID, '_sab_stelle_backup', true);
            $b = array_slice(array_merge($b, [['ts' => time(), 'hash' => md5($c2), 'vorher' => $c, 'alt' => 'Kurzlink', 'neu' => $neu]]), -5);
            update_post_meta($post->ID, '_sab_stelle_backup', wp_slash($b));
            wp_update_post(['ID' => $post->ID, 'post_content' => wp_slash($c2)]);
            delete_transient('sab_alle_asins');
            $kk = (array) get_option('sab_kontrolle', []);
            $kk[(string) time() . substr(md5($kurz . $neu), 0, 4)] = ['ts' => time(), 'art' => 'stelle', 'pid' => (int) $post->ID, 'alt' => 'Kurzlink', 'neu' => $neu, 'vorher' => mb_substr($tag, 0, 900), 'nachher' => mb_substr($neu_tag, 0, 900), 'stellen' => [['pid' => (int) $post->ID, 'titel' => (string) $post->post_title, 'satz' => '', 'n' => 1, 'ords' => []]]];
            update_option('sab_kontrolle', array_slice($kk, -20, null, true), false);
            wp_send_json_success(['n' => 1, 'nr' => 1, 'neu' => $neu, 'titel' => 'unverändert (Kurzlink)']);
        }
        [$post, $c, $st, $ord, $tag, $pos, $quelle, $asin] = self::stelle_pruefen();
        $neu = self::asin_aus_text((string) wp_unslash($_POST['neu'] ?? ''));
        if (strlen($neu) !== 10 || $neu === $asin) { wp_send_json_error('Bitte eine gültige, andere ASIN oder einen Amazon-Produktlink (mit /dp/…) angeben. Kurzlinks wie amzn.to enthalten keine ASIN.'); }
        if (!self::neues_produkt_vorladen($neu)) { wp_send_json_error('Amazon liefert zu ' . $neu . ' gerade kein Bild oder keine Daten, oder das Bild ließ sich nicht speichern. Nicht getauscht. Bitte Link prüfen oder in ein paar Minuten noch einmal versuchen.'); }
        $bf_ = (array) get_option('sab_bildfehler', []); foreach (array_keys($bf_) as $k_) { if (strpos((string) $k_, $neu . '|') === 0) { unset($bf_[$k_]); } } update_option('sab_bildfehler', $bf_, false);
        $namen = self::produkte(); $neuer = (string) ($namen[$neu] ?? '');
        $alte = array_filter([(string) ($namen[$asin] ?? ''), (string) ($namen[$quelle] ?? '')]);
        // gleiche Tabellenzelle: alle zählenden Platzhalter desselben Produkts mitnehmen (Bild, Titel, Link)
        $von = $pos; $bis = $pos + strlen($tag); $in_td = false;
        $td0 = strripos(substr($c, 0, $pos), '<td'); $td1 = strripos(substr($c, 0, $pos), '</td>');
        if ($td0 !== false && ($td1 === false || $td1 < $td0)) {
            $e = stripos($c, '</td>', $pos);
            if ($e !== false) { $von = $td0; $bis = $e; $in_td = true; }
        }
        // Titel mit tauschen (nur eindeutig erkannte): neuer Produktname, sonst Kurzname aus dem Amazon-Titel
        $pn = self::produkt($neu, self::land(''), false, true); $ntitel = $neuer !== '' ? $neuer : self::kurzname_aus_titel((string) ($pn['titel'] ?? ''));
        $titel_ok = false; $c_vor = $c;
        if (!$in_td && (preg_match('/^\[amz_(?:bild|produkt)\b/', $tag) || stripos($tag, '<img') !== false)) {   // Einzelprodukt: fetter Text direkt vor dem Bild
            [$pre, $titel_ok] = self::titel_ersetzen(substr($c, 0, $pos), $ntitel, false);
            if ($titel_ok) { $d_ = strlen($pre) - $pos; $c = $pre . substr($c, $pos); $von += $d_; $bis += $d_; $pos += $d_; }
        }
        if (!$in_td && ($pb = self::partner_bereich($c, $pos, strlen($tag), $quelle))) { $von = $pb[0]; $bis = $pb[1]; }   // Bild und Textlink desselben Produkts direkt nebeneinander: zusammen tauschen
        $seg = substr($c, $von, $bis - $von);
        $seg2 = preg_replace('/\btitel="[^"]*"/u', '', $seg);   // dein alter Titel passt nicht zum neuen Produkt
        if ($in_td) { [$seg2, $titel_ok] = self::titel_ersetzen($seg2, $ntitel, true); }
        [$seg3, $n] = self::tausch_text($seg2, $quelle, $neu, $alte, $neuer);
        if ($n === 0 || $seg3 === $seg) { wp_send_json_error('Nichts geändert.'); }
        $c2 = substr($c, 0, $von) . $seg3 . substr($c, $bis);
        $b = (array) get_post_meta($post->ID, '_sab_stelle_backup', true);
        $b = array_slice(array_merge($b, [['ts' => time(), 'hash' => md5($c2), 'vorher' => $c_vor, 'alt' => $asin, 'neu' => $neu]]), -5);
        update_post_meta($post->ID, '_sab_stelle_backup', wp_slash($b));
        wp_update_post(['ID' => $post->ID, 'post_content' => wp_slash($c2)]);
        delete_transient('sab_alle_asins');
        $sj0 = self::satz_mit_link($c2, $neu); $kk = (array) get_option('sab_kontrolle', []);   // auch Tausch an einer Stelle im Frontend kommt in die Kontrollliste
        $kk[(string) time() . substr(md5($asin . $neu), 0, 4)] = ['ts' => time(), 'art' => 'stelle', 'pid' => (int) $post->ID, 'alt' => $asin, 'neu' => $neu, 'vorher' => mb_substr($seg, 0, 900), 'nachher' => mb_substr($seg3, 0, 900), 'stellen' => [['pid' => (int) $post->ID, 'titel' => (string) $post->post_title, 'satz' => $sj0 ? mb_substr(str_replace('【LINK】', '【' . $sj0['linktext'] . '】', $sj0['maske']), 0, 300) : '', 'n' => count(self::stellen_gruppen($c2, $neu)), 'ords' => self::stellen_gruppen($c2, $neu)]]];
        update_option('sab_kontrolle', array_slice($kk, -20, null, true), false);
        wp_send_json_success(['n' => $n, 'nr' => substr_count(substr($c2, 0, $von), $neu) + 1, 'neu' => $neu, 'titel' => $titel_ok ? 'ersetzt' : 'nicht eindeutig erkannt, unverändert']);
    }

    /** Rohtext der getauschten Stelle vorher/nachher (zum Prüfen und zum Weitergeben, wenn etwas nicht aussieht wie vorher). */
    private static function kontrolle_code(int $pid, string $neu): array {
        foreach ((array) get_option('sab_kontrolle', []) as $e) { if (($e['art'] ?? '') === 'stelle' && (int) ($e['pid'] ?? 0) === $pid && (string) ($e['neu'] ?? '') === $neu && isset($e['nachher'])) { return ['vorher' => (string) $e['vorher'], 'nachher' => (string) $e['nachher']]; } }
        return [];
    }

    /** Kontrolleinträge einer Frontend-Tausch-Stelle (Artikel + neue ASIN) in der Kontrollliste. @return string[] Schlüssel */
    private static function kontrolle_stellen_ids(int $pid, string $neu): array {
        $out = [];
        foreach ((array) get_option('sab_kontrolle', []) as $id => $e) {
            if (($e['art'] ?? '') === 'stelle' && (int) ($e['pid'] ?? 0) === $pid && (string) ($e['neu'] ?? '') === $neu) { $out[] = (string) $id; }
            elseif (($e['art'] ?? '') !== 'stelle' && (string) ($e['neu'] ?? '') === $neu && in_array($pid, array_map('intval', array_column((array) ($e['stellen'] ?? []), 'pid')), true)) { $out[] = (string) $id; }   // Tausch aus dem Reiter Austausch (eigene ASIN/Link): gleiche Kontrolle an der Stelle
        }
        return $out;
    }

    /** Ist die Kontrolle ein Tausch aus dem Reiter (gilt für mehrere Artikel)? Dann gilt „Rückgängig“ für den ganzen Tausch. */
    private static function kontrolle_tab_id(int $pid, string $neu): string {
        $kk = (array) get_option('sab_kontrolle', []);
        foreach (self::kontrolle_stellen_ids($pid, $neu) as $id) { if (($kk[$id]['art'] ?? '') !== 'stelle') { return (string) $id; } }
        return '';
    }

    public static function ajax_stelle_ok(): void {
        check_ajax_referer('sab_stelle', 'n');
        $pid = (int) ($_POST['post'] ?? 0); $neu = self::asin((string) ($_POST['neu'] ?? ''));
        if (!$pid || !current_user_can('edit_post', $pid)) { wp_send_json_error('Keine Berechtigung.'); }
        $kk = (array) get_option('sab_kontrolle', []); foreach (self::kontrolle_stellen_ids($pid, $neu) as $id) { unset($kk[$id]); }
        update_option('sab_kontrolle', $kk, false);
        wp_send_json_success(['weiter' => admin_url('admin.php?page=sab-austausch')]);
    }

    public static function ajax_stelle_zurueck(): void {
        check_ajax_referer('sab_stelle', 'n');
        $pid = (int) ($_POST['post'] ?? 0);
        if (!$pid || !current_user_can('edit_post', $pid)) { wp_send_json_error('Keine Berechtigung.'); }
        $tab = self::kontrolle_tab_id($pid, self::asin((string) ($_POST['neu'] ?? '')));
        if ($tab !== '') {   // Tausch aus dem Reiter Austausch: ganzen Tausch zurücknehmen (alle betroffenen Artikel)
            [$ok, $skip] = self::tausch_zurueck($tab);
            if ($ok < 1) { wp_send_json_error('Nichts zurückgesetzt: ' . ($skip ? 'die Artikel wurden seitdem geändert (bitte unter „Versionen“ zurückgehen).' : 'keine Sicherung gefunden.')); }
            $kk = (array) get_option('sab_kontrolle', []); unset($kk[$tab]); update_option('sab_kontrolle', $kk, false);
            wp_send_json_success(['weiter' => admin_url('admin.php?page=sab-austausch')]);
        }
        $b = (array) get_post_meta($pid, '_sab_stelle_backup', true); $post = get_post($pid);
        $l = end($b);
        if (!$post || !$l) { wp_send_json_error('Es gibt nichts rückgängig zu machen.'); }
        if (md5((string) $post->post_content) !== ($l['hash'] ?? '')) { wp_send_json_error('Der Artikel wurde seitdem geändert; Rückgängig ist zu riskant. Bitte über „Bearbeiten“ prüfen.'); }
        wp_update_post(['ID' => $pid, 'post_content' => wp_slash((string) $l['vorher'])]);
        array_pop($b); update_post_meta($pid, '_sab_stelle_backup', wp_slash($b));
        $kk = (array) get_option('sab_kontrolle', []); foreach (self::kontrolle_stellen_ids($pid, (string) ($l['neu'] ?? '')) as $id) { unset($kk[$id]); } update_option('sab_kontrolle', $kk, false);
        wp_send_json_success(['alt' => $l['alt'] ?? '', 'neu' => $l['neu'] ?? '', 'weiter' => admin_url('admin.php?page=sab-austausch')]);
    }

    public static function stellen_skript(): void {
        if (!function_exists('is_user_logged_in') || !is_user_logged_in() || !current_user_can('edit_posts') || !is_singular()) { return; }
        $pid_ = (int) get_queried_object_id();
        $cfg = wp_json_encode(['u' => admin_url('admin-ajax.php'), 'n' => wp_create_nonce('sab_stelle'), 'p' => $pid_, 'e' => $pid_ && current_user_can('edit_post', $pid_) ? admin_url('post.php?post=' . $pid_ . '&action=edit&sab_zeige=') : '', 'ko' => array_values(array_slice((array) get_option('sab_kurz_ok', []), -200))]);
        echo '<script>(function(){var C=' . $cfg . ';var seen={};var cur={nth:0,txt:""};'
           . 'function post(a,d,f){var fd=new FormData();fd.append("action",a);fd.append("n",C.n);fd.append("post",C.p);fd.append("nth",cur.nth);fd.append("txt",cur.txt);fd.append("kurz",cur.kurz||"");for(var k in d){fd.append(k,d[k])}fetch(C.u,{method:"POST",body:fd,credentials:"same-origin"}).then(function(r){return r.json()}).then(f).catch(function(){f({success:false,data:"Verbindungsfehler"})})}'
           . 'function el(t,s,x){var e=document.createElement(t);if(s){e.style.cssText=s}if(x!==undefined){e.textContent=x}return e}'
           . 'function oeffne(o,asin){var ov=el("div","position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.55);display:flex;align-items:flex-start;justify-content:center;padding:40px 12px;overflow:auto");'
           . 'var bx=el("div","background:#fff;color:#222;max-width:640px;width:100%;border-radius:10px;padding:18px;font:14px/1.4 sans-serif;box-shadow:0 8px 30px rgba(0,0,0,.4)");ov.appendChild(bx);'
           . 'ov.onclick=function(e){if(e.target===ov){ov.remove()}};document.body.appendChild(ov);'
           . 'bx.appendChild(el("h3","margin:0 0 6px;font-size:18px","Produkt an dieser Stelle tauschen"));var inf=el("p","margin:0 0 10px;color:#555","Lade Vorschläge …");bx.appendChild(inf);'
           . 'var lst=el("div","");bx.appendChild(lst);'
           . 'function tausche(neu){inf.textContent="Tausche …";post("sab_stelle_tausch",{ord:o,asin:asin,neu:neu},function(r){if(r.success){inf.textContent="Getauscht. Seite wird neu geladen, die Stelle ist markiert …";location.href=location.pathname+"?sab_zeige="+encodeURIComponent(r.data.neu)+"&sab_nr="+r.data.nr}else{inf.textContent="Nicht möglich: "+r.data;inf.style.color="#a00"}})}'
           . 'post("sab_stelle_kand",{ord:o,asin:asin},function(r){if(!r.success){inf.textContent=r.data;inf.style.color="#a00";return}'
           . 'inf.textContent="Jetzt: "+r.data.alt+". Nur diese Stelle im Artikel wird getauscht (mit Sicherung)."+(r.data.asin?"":" Der Kurzlink wird durch die Produktadresse der eingegebenen ASIN ersetzt.");var H="https://"+r.data.host,lk=function(t,u){var a=el("a","display:inline-block;margin:4px 12px 0 0;color:#2271b1",t);a.href=u;a.target="_blank";a.rel="noopener";return a};var ar=el("div","");if(r.data.asin){ar.appendChild(lk("Dieses Produkt bei Amazon ansehen ↗",H+"/dp/"+r.data.asin))}ar.appendChild(lk("Bei Amazon suchen: „"+(r.data.suche||r.data.alt)+"“ ↗",H+"/s?k="+encodeURIComponent(r.data.suche||r.data.alt)));inf.parentNode.insertBefore(ar,inf.nextSibling);'
           . 'r.data.liste.forEach(function(c){var row=el("div","display:flex;gap:10px;align-items:center;padding:8px 0;border-top:1px solid #eee");'
           . 'if(c.img){var im=el("img","width:56px;height:56px;object-fit:contain;flex:none");im.src=c.img;row.appendChild(im)}'
           . 'var tx=el("div","flex:1;min-width:0");tx.appendChild(el("strong","",c.name||c.asin));tx.appendChild(el("div","font-size:12px;color:#555","Score "+(c.score===null?"?":c.score)+(c.preis?" · "+c.preis:"")+" · "+c.quelle+(c.ok?"":" · nicht lieferbar")));tx.appendChild(lk("bei Amazon ansehen ↗",H+"/dp/"+c.asin));row.appendChild(tx);'
           . 'var bt=el("button","padding:6px 12px;border:0;border-radius:6px;background:#2271b1;color:#fff;cursor:pointer;font-weight:600","Einsetzen");bt.onclick=function(){tausche(c.asin)};row.appendChild(bt);lst.appendChild(row)});'
           . 'if(!r.data.liste.length){lst.appendChild(el("p","","Keine passenden Vorschläge. Gib unten eine ASIN ein."))}'
           . 'var sw=el("div","padding-top:10px;margin-top:6px;border-top:1px solid #eee");sw.appendChild(el("strong","","Im Bestand suchen:"));var si=el("input","display:block;width:100%;box-sizing:border-box;margin-top:4px;padding:6px;border:1px solid #bbb;border-radius:6px");si.placeholder="Name, Stichwort oder ASIN, schon ab zwei Buchstaben";si.type="search";var so=el("div","");sw.appendChild(si);sw.appendChild(so);bx.appendChild(sw);'
           . 'function nz(x){return x.toLowerCase().replace(/(\d+(?:[.,]\d+)?)[\s-]*(volt|amperestunden?|ah|mah|watt|liter|kg|ml|cm|mm|v|w|l)(?![a-zäöüß])/g,function(a,n,u){var m={volt:"v",amperestunde:"ah",amperestunden:"ah",watt:"w",liter:"l"};return n+(m[u]||u)})}'
           . 'si.oninput=function(){var q=nz(si.value).trim().split(/\s+/).filter(Boolean);so.textContent="";if(!q.length||si.value.trim().length<2){return}var rs=[];(r.data.ps||[]).forEach(function(p){var h=nz(p[0]+" "+p[1]+" "+p[2]),c=0;q.forEach(function(w){if(h.indexOf(w)>=0){c++}});if(c&&p[0]!==asin){rs.push([c,p])}});'
           . 'rs.sort(function(a,b){return b[0]-a[0]||b[1][3]-a[1][3]});rs.slice(0,8).forEach(function(x){var p=x[1],row=el("div","display:flex;gap:8px;align-items:center;padding:5px 0;border-bottom:1px solid #f0f0f0");var tx=el("div","flex:1;min-width:0");tx.appendChild(el("strong","",p[1]||p[2]||p[0]));tx.appendChild(el("div","font-size:12px;color:#555",(p[3]>=0?"Score "+p[3]+" · ":"")+p[0]));tx.appendChild(lk("bei Amazon ansehen ↗",H+"/dp/"+p[0]));row.appendChild(tx);'
           . 'var bt=el("button","padding:6px 12px;border:0;border-radius:6px;background:#2271b1;color:#fff;cursor:pointer;font-weight:600","Einsetzen");bt.type="button";bt.onclick=function(){tausche(p[0])};row.appendChild(bt);so.appendChild(row)});if(!rs.length){so.appendChild(el("p","margin:6px 0;color:#555","Kein Treffer im Bestand."))}};'
           . 'var fr=el("div","display:flex;gap:8px;align-items:center;padding:8px 0 10px;margin-bottom:6px;border-bottom:1px solid #eee");var ip=el("input","flex:1;padding:6px;border:1px solid #bbb;border-radius:6px");ip.placeholder="Eigene ASIN oder Amazon-Produktlink einfügen";var b2=el("button","padding:6px 12px;border:0;border-radius:6px;background:#2271b1;color:#fff;cursor:pointer;font-weight:600","Einsetzen");b2.onclick=function(){tausche(ip.value.trim())};var b4=el("button","padding:6px 10px;border:1px solid #2271b1;border-radius:6px;background:#fff;color:#2271b1;cursor:pointer","Bei Amazon ansehen");b4.type="button";b4.onclick=function(){var v=ip.value.trim(),m=/(?:\/dp\/|\/gp\/product\/|\/product\/)([A-Za-z0-9]{10})/.exec(v);v=m?m[1]:v.toUpperCase();if(v){window.open(H+"/dp/"+encodeURIComponent(v),"_blank","noopener")}};fr.appendChild(ip);fr.appendChild(b4);fr.appendChild(b2);bx.insertBefore(fr,lst);'
           . 'if(r.data.zurueck){var zu=el("a","display:inline-block;margin-top:10px;color:#a00;cursor:pointer;text-decoration:underline","Letzten Tausch in diesem Artikel rückgängig machen");zu.onclick=function(){post("sab_stelle_zurueck",{},function(q){if(q.success){location.reload()}else{inf.textContent=q.data;inf.style.color="#a00"}})};bx.appendChild(zu)}'
           . '});}'
           . 'function mk(e){var o=e.getAttribute("data-sab-ord");if(!o||seen[o]){return}seen[o]=1;var box=e.closest(".sab-karte,.sab-produkt,td")||e.parentNode;if(getComputedStyle(box).position==="static"){box.style.position="relative"}'
           . 'var b=el("button","position:absolute;top:4px;right:4px;z-index:50;font:600 12px sans-serif;padding:3px 8px;border:1px solid #2271b1;border-radius:12px;background:#fff;color:#2271b1;cursor:pointer;opacity:.9","⇄ tauschen");b.type="button";b.className="sab-tb";b.setAttribute("data-o",o);b.title="Nur für dich sichtbar (angemeldet)";b.onclick=function(ev){ev.preventDefault();ev.stopPropagation();oeffne(o,e.getAttribute("data-sab-asin"))};box.appendChild(b);'
           . 'if(C.e){var b3=el("button","position:absolute;top:30px;right:4px;z-index:50;font:600 12px sans-serif;padding:3px 8px;border:1px solid #2271b1;border-radius:12px;background:#fff;color:#2271b1;cursor:pointer;opacity:.9","✎ bearbeiten");b3.type="button";b3.title="Öffnet den Editor an dieser Stelle (neuer Tab)";b3.onclick=function(ev){ev.preventDefault();ev.stopPropagation();window.open(C.e+encodeURIComponent(e.getAttribute("data-sab-asin")),"_blank")};box.appendChild(b3)}'
           . 'if(!e.closest(".sab-karte")){var b5=el("button","position:absolute;top:"+(C.e?56:30)+"px;right:4px;z-index:50;font:600 12px sans-serif;padding:3px 8px;border:1px solid #2271b1;border-radius:12px;background:#fff;color:#2271b1;cursor:pointer;opacity:.9","▦ Karte");b5.type="button";b5.title="Macht aus Bild und Textlink dieses Produkts eine Karte (ein Link, mit Sicherung)";b5.onclick=function(ev){ev.preventDefault();ev.stopPropagation();b5.textContent="⏳ Karte wird gebaut …";b5.disabled=true;post("sab_stelle_karte",{ord:o,asin:e.getAttribute("data-sab-asin")},function(r){if(r.success){location.href=location.pathname+"?sab_zeige="+encodeURIComponent(r.data.asin)+"&sab_nr="+r.data.nr}else{b5.disabled=false;b5.textContent="▦ Karte";alert(r.data)}})};box.appendChild(b5)}}'
           . 'var es=document.querySelectorAll("[data-sab-ord]");for(var i=0;i<es.length;i++){mk(es[i])}'
           . 'var hs=document.querySelectorAll("a[href]"),cn={},al={},kz={};for(var j=0;j<hs.length;j++){var a=hs[j],hr=a.getAttribute("href")||"",m=/(?:\/dp\/|\/gp\/product\/|\/product\/)([A-Z0-9]{10})(?![A-Za-z0-9])/.exec(hr);var as=m?m[1]:a.getAttribute("data-sab-asin");if(!as&&/^https?:\/\/(?:amzn\.to|amzn\.eu|a\.co|link\.amazon)\//i.test(hr)&&!a.closest("#wpadminbar")&&a.closest("article,.entry-content,.hentry,main,.post")){kz[hr]=(kz[hr]||0)+1;(function(a,hr,nth){var sp=el("span","display:inline-block;margin-left:4px;white-space:nowrap");function kn2(t,f,tb){var b=el("button","font:600 11px sans-serif;padding:1px 7px;margin-right:3px;border:1px solid #2271b1;border-radius:10px;background:#fff;color:#2271b1;cursor:pointer",t);b.type="button";if(tb){b.className="sab-tb"}b.onclick=function(ev){ev.preventDefault();ev.stopPropagation();f()};sp.appendChild(b)}kn2("⇄ tauschen",function(){cur={nth:nth,txt:a.textContent.replace(/\s+/g," ").trim(),kurz:hr};oeffne(0,"")},1);if(C.e){kn2("✎ bearbeiten",function(){window.open(C.e+encodeURIComponent(hr.replace(/[?#].*$/,"").replace(/\/+$/,"").split("/").pop()),"_blank")})}var ik=C.ko.indexOf(hr)>=0;kn2(ik?"✓ so gelassen – wieder prüfen":"✓ okay, so lassen",function(){var fd=new FormData();fd.append("action","sab_kurz_ok");fd.append("n",C.n);fd.append("post",C.p);fd.append("kurz",hr);fd.append("ok",ik?"0":"1");fetch(C.u,{method:"POST",body:fd,credentials:"same-origin"}).then(function(r){return r.json()}).then(function(r){if(r.success){location.reload()}})});a.parentNode.insertBefore(sp,a.nextSibling)})(a,hr,kz[hr]);continue}if(!as){continue}al[as]=(al[as]||0)+1;'
           . 'if(a.hasAttribute("data-sab-asin")||a.closest("[data-sab-ord],#wpadminbar,.sab-karte,.sab-produkt")||!a.closest("article,.entry-content,.hentry,main,.post")){continue}cn[as]=(cn[as]||0)+1;'
           . '(function(a,as,nth,nr){var sp=el("span","display:inline-block;margin-left:4px;white-space:nowrap");function kn(t,f){var b=el("button","font:600 11px sans-serif;padding:1px 7px;margin-right:3px;border:1px solid #2271b1;border-radius:10px;background:#fff;color:#2271b1;cursor:pointer",t);b.type="button";if(t.indexOf("tauschen")>0){b.className="sab-tb";b.setAttribute("data-o","h"+nth+as)}b.onclick=function(ev){ev.preventDefault();ev.stopPropagation();f()};sp.appendChild(b);return b}'
           . 'kn("⇄ tauschen",function(){cur={nth:nth,txt:a.textContent.replace(/\s+/g," ").trim()};oeffne(0,as)});'
           . 'var kb=kn("＋ Karte",function(){cur={nth:nth,txt:a.textContent.replace(/\s+/g," ").trim()};kb.textContent="⏳ Karte wird gebaut …";kb.disabled=true;post("sab_stelle_karte",{asin:as,ord:0},function(r){if(r.success){kb.textContent="✓ Karte da";location.href=location.pathname+"?sab_zeige="+encodeURIComponent(r.data.asin)+"&sab_nr="+r.data.nr}else{kb.disabled=false;kb.textContent="＋ Karte";alert(r.data)}})});kb.title="Fügt unter dem Absatz eine Karte mit Bild und Link ein (mit Sicherung, rückgängig im Tausch-Fenster)";'
           . 'if(C.e){kn("✎ bearbeiten",function(){window.open(C.e+encodeURIComponent(as)+"&sab_nr="+nr,"_blank")})}'
           . 'a.parentNode.insertBefore(sp,a.nextSibling)})(a,as,cn[as],al[as])}'
           . 'function abBtn(box,top,left,t,title,f){var b=el("button","position:absolute;top:"+top+"px;"+(left?"left":"right")+":4px;z-index:50;font:600 12px sans-serif;padding:3px 8px;border:1px solid #8a5a00;border-radius:12px;background:#fff;color:#8a5a00;cursor:pointer;opacity:.9",t);b.type="button";b.title=title;b.onclick=function(ev){ev.preventDefault();ev.stopPropagation();f(b)};if(getComputedStyle(box).position==="static"){box.style.position="relative"}box.appendChild(b);return b}'
           . 'function abAus(id,b){b.textContent="…";post("sab_ab_schalten",{id:id,an:0},function(r){if(r.success){location.reload()}else{b.textContent="Fehler";alert(r.data)}})}'
           . 'var xs=document.querySelectorAll(".sab-karten[data-sab-abx]"),sx={};for(var xi=0;xi<xs.length;xi++){var x=xs[xi],xid=x.getAttribute("data-sab-abx");if(sx[xid]){continue}sx[xid]=1;(function(x,xid,on){abBtn(x,4,1,on?"⚖ A/B-Test läuft: beenden":"⚖ A/B-Test starten","Viererblock als A/B-Test: Besucher sehen nur eines der beiden Paare (nur für dich sichtbar)",function(b){if(on){if(confirm("A/B-Test beenden? Die Zahlen bleiben gespeichert.")){abAus(xid,b)}return}if(!confirm("A/B-Test starten? Besucher sehen dann je zur Hälfte nur das erste oder das zweite Paar. Ziel: 1000 Besuche.")){return}b.textContent="…";post("sab_ab_schalten",{id:xid,an:1},function(r){if(r.success){location.reload()}else{b.textContent="⚖ A/B-Test starten";alert(r.data)}})})})(x,xid,x.hasAttribute("data-sab-ab"))}'
           . 'function abOeffne(o,asin,id){var ov=el("div","position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.55);display:flex;align-items:flex-start;justify-content:center;padding:40px 12px;overflow:auto");'
           . 'var bx=el("div","background:#fff;color:#222;max-width:640px;width:100%;border-radius:10px;padding:18px;font:14px/1.4 sans-serif;box-shadow:0 8px 30px rgba(0,0,0,.4)");ov.appendChild(bx);ov.onclick=function(e){if(e.target===ov){ov.remove()}};document.body.appendChild(ov);'
           . 'var BS="padding:6px 12px;border:0;border-radius:6px;background:#8a5a00;color:#fff;cursor:pointer;font-weight:600";bx.appendChild(el("h3","margin:0 0 6px;font-size:18px","A/B-Test: dieses Produkt gegen ein anderes"));var inf=el("p","margin:0 0 10px;color:#555","");bx.appendChild(inf);'
           . 'if(id){inf.textContent="Für dieses Produkt läuft ein Test. Zahlen: Plugin, Reiter A/B-Tests und Statistik.";var st=el("button",BS,"Test beenden");st.type="button";st.onclick=function(){abAus(id,st)};bx.appendChild(st);return}'
           . 'inf.textContent="Lade Vorschläge …";var lst=el("div","");bx.appendChild(lst);'
           . 'function los(neu){if(!confirm("A/B-Test starten? Besucher sehen dann je zur Hälfte das jetzige Produkt oder dieses. Ziel: 1000 Besuche.")){return}inf.style.color="#555";inf.textContent="Starte …";post("sab_ab_einzel",{asin:asin,neu:neu},function(r){if(r.success){location.reload()}else{inf.textContent="Nicht möglich: "+r.data;inf.style.color="#a00"}})}'
           . 'function zeile(c,sub){var row=el("div","display:flex;gap:10px;align-items:center;padding:8px 0;border-top:1px solid #eee");if(c.img){var im=el("img","width:56px;height:56px;object-fit:contain;flex:none");im.src=c.img;row.appendChild(im)}var tx=el("div","flex:1;min-width:0");tx.appendChild(el("strong","",c.name||c.asin));tx.appendChild(el("div","font-size:12px;color:#555",sub));row.appendChild(tx);var bt=el("button",BS,"Dagegen testen");bt.type="button";bt.onclick=function(){los(c.asin)};row.appendChild(bt);return row}'
           . 'post("sab_ab_kand",{ord:o,asin:asin},function(r){if(!r.success){inf.textContent=r.data;inf.style.color="#a00";return}inf.textContent="Jetzt: "+r.data.alt+". Vorgeschlagen werden nur Produkte, die noch nicht in diesem Artikel stehen, ohne tötende oder verletzende Produkte. Der Artikel bleibt unverändert; beide Produkte zählen Klicks, danach siehst du den Sieger.";'
           . 'var fr=el("div","display:flex;gap:8px;padding:0 0 10px");var ip=el("input","flex:1;padding:6px;border:1px solid #bbb;border-radius:6px");ip.placeholder="Eigene ASIN oder Amazon-Produktlink einfügen";var b2=el("button",BS,"Starten");b2.type="button";b2.onclick=function(){los(ip.value)};fr.appendChild(ip);fr.appendChild(b2);lst.appendChild(fr);'
           . 'r.data.liste.forEach(function(c){lst.appendChild(zeile(c,"Warum: "+c.warum+(c.preis?" · "+c.preis:"")))});'
           . 'var si=el("input","display:block;width:100%;box-sizing:border-box;margin-top:8px;padding:6px;border:1px solid #bbb;border-radius:6px");si.type="search";si.placeholder="Im Bestand suchen: Name, Stichwort oder ASIN";var so=el("div","");bx.appendChild(si);bx.appendChild(so);'
           . 'si.oninput=function(){var q=si.value.toLowerCase().trim().split(/\s+/).filter(Boolean);so.textContent="";if(!q.length||si.value.trim().length<2){return}var rs=[];(r.data.ps||[]).forEach(function(p){var h=(p[0]+" "+p[1]+" "+p[2]).toLowerCase(),c=0;q.forEach(function(w){if(h.indexOf(w)>=0){c++}});if(c&&p[0]!==asin){rs.push([c,p])}});rs.sort(function(a,b){return b[0]-a[0]||b[1][3]-a[1][3]});rs.slice(0,8).forEach(function(x){var p=x[1];so.appendChild(zeile({asin:p[0],name:p[1]||p[2]||p[0]},(p[3]>=0?"Score "+p[3]+" · ":"")+p[0]))});if(!rs.length){so.appendChild(el("p","margin:6px 0;color:#555","Kein Treffer im Bestand."))}}})}'
           . 'function abBlock(k,as,id){var ov=el("div","position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.55);display:flex;align-items:flex-start;justify-content:center;padding:40px 12px;overflow:auto");'
           . 'var bx=el("div","background:#fff;color:#222;max-width:680px;width:100%;border-radius:10px;padding:18px;font:14px/1.4 sans-serif;box-shadow:0 8px 30px rgba(0,0,0,.4)");ov.appendChild(bx);ov.onclick=function(e){if(e.target===ov){ov.remove()}};document.body.appendChild(ov);'
           . 'var BS="padding:6px 12px;border:0;border-radius:6px;background:#8a5a00;color:#fff;cursor:pointer;font-weight:600";bx.appendChild(el("h3","margin:0 0 6px;font-size:18px","A/B-Test: diesen Zweierblock gegen einen anderen"));var inf=el("p","margin:0 0 10px;color:#555","");bx.appendChild(inf);'
           . 'if(id){inf.textContent="Für diesen Block läuft ein Test. Zahlen: Plugin, Reiter A/B-Tests und Statistik.";var st=el("button",BS,"Test beenden");st.type="button";st.onclick=function(){abAus(id,st)};bx.appendChild(st);return}'
           . 'inf.textContent="Lade Vorschläge …";var lst=el("div","");bx.appendChild(lst);'
           . 'function los(b){if(!confirm("A/B-Test starten? Besucher sehen dann je zur Hälfte den jetzigen oder den anderen Zweierblock. Ziel: 1000 Besuche.")){return}inf.style.color="#555";inf.textContent="Starte …";post("sab_ab_block",{a:as.join(","),b:b.join(",")},function(r){if(r.success){location.reload()}else{inf.textContent="Nicht möglich: "+r.data;inf.style.color="#a00"}})}'
           . 'post("sab_ab_blockkand",{},function(r){if(!r.success){inf.textContent=r.data;inf.style.color="#a00";return}inf.textContent=r.data.liste.length?"Vorschläge: Zweierblöcke, die auf deinen Seiten schon vorkommen, mit gutem Score, nicht schon in diesem Artikel, ohne tötende oder verletzende Produkte.":"Keine passenden Blöcke gefunden. Trage unten zwei ASINs ein.";'
           . 'r.data.liste.forEach(function(c){var row=el("div","display:flex;gap:10px;align-items:center;padding:8px 0;border-top:1px solid #eee");c.prod.forEach(function(p){if(p.img){var im=el("img","width:56px;height:56px;object-fit:contain;flex:none");im.src=p.img;row.appendChild(im)}});var tx=el("div","flex:1;min-width:0");tx.appendChild(el("strong","",c.prod.map(function(p){return p.name||p.asin}).join(" + ")));tx.appendChild(el("div","font-size:12px;color:#555","Warum: "+c.warum));row.appendChild(tx);var bt=el("button",BS,"Dagegen testen");bt.type="button";bt.onclick=function(){los(c.asins)};row.appendChild(bt);lst.appendChild(row)});'
           . 'var fr=el("div","display:flex;gap:8px;padding:10px 0 0");var ip=el("input","flex:1;padding:6px;border:1px solid #bbb;border-radius:6px");ip.placeholder="Zwei ASINs oder Amazon-Links, mit Komma getrennt";var b2=el("button",BS,"Starten");b2.type="button";b2.onclick=function(){los(ip.value.split(","))};fr.appendChild(ip);fr.appendChild(b2);bx.appendChild(fr)})}'
           . 'var zb=document.querySelectorAll(".sab-karten");for(var zi=0;zi<zb.length;zi++){(function(k){if(k.hasAttribute("data-sab-abx")||(k.hasAttribute("data-sab-ab")&&!k.closest(".sab-ab-e"))){return}var cs=k.querySelectorAll(":scope > .sab-karte");if(cs.length!==2){return}var v=k.getAttribute("data-sab-abv");if(v==="B"){return}var as=[];for(var ci=0;ci<2;ci++){var l=cs[ci].querySelector("a[data-sab-asin]");if(l){as.push(l.getAttribute("data-sab-asin"))}}if(as.length!==2){return}var w=k.closest(".sab-ab-e");abBtn(k,4,1,w?"⚖ Block-Test läuft":"⚖ Block testen","Diesen Zweierblock gegen einen anderen testen (nur für dich sichtbar)",function(){abBlock(k,as,w?w.getAttribute("data-sab-abe"):"")})})(zb[zi])}'
           . 'var ks=document.querySelectorAll(".sab-karte");for(var qi=0;qi<ks.length;qi++){(function(k){var l=k.querySelector("a[data-sab-ord]");if(!l||k.closest(".sab-karten[data-sab-ab]")){return}var vb=k.closest(".sab-ab-ep");if(vb&&vb.getAttribute("data-v")==="B"){return}var w=k.closest(".sab-ab-e"),o=l.getAttribute("data-sab-ord"),as=l.getAttribute("data-sab-asin");if(getComputedStyle(k).position==="static"){k.style.position="relative"}abBtn(k,56,0,w?"⚖ A/B läuft":"⚖ A/B-Test","Dieses Produkt gegen ein anderes testen (nur für dich sichtbar)",function(){abOeffne(o,as,w?w.getAttribute("data-sab-abe"):"")})})(ks[qi])}'
           . '})();</script>';
    }


    /** Durchsucht alle Artikel nach Partner-IDs (Amazon: tag=, tracking_id; eBay: campid) und zählt Stellen und Artikel. @return array{amazon:array,ebay:array} */
    private static function ids_scan(): array {
        global $wpdb; $out = ['amazon' => [], 'ebay' => [], 'ohne' => []];
        if (!isset($wpdb)) { return $out; }
        $rows = $wpdb->get_results("SELECT ID, post_title, post_content FROM {$wpdb->posts} WHERE post_status IN ('publish','future') AND post_type IN ('post','page') AND (post_content LIKE '%tag=%' OR post_content LIKE '%tracking_id%' OR post_content LIKE '%campid%' OR post_content LIKE '%amazon.%' OR post_content LIKE '%amzn.%')", ARRAY_A);
        foreach ((array) $rows as $r) {
            $c = html_entity_decode((string) $r['post_content']);
            $f = [];
            if (preg_match_all('/\btracking_id=["\']?([A-Za-z0-9][A-Za-z0-9\-]{2,39})/', $c, $m)) { foreach ($m[1] as $i) { $f['amazon'][$i][] = (int) $r['ID']; } }
            if (preg_match_all('/\btag=["\']([A-Za-z0-9][A-Za-z0-9\-]{2,39}-\d{2})["\']/', $c, $m)) { foreach ($m[1] as $i) { $f['amazon'][$i][] = (int) $r['ID']; } }
            if (preg_match_all('/[?&;]tag=([A-Za-z0-9][A-Za-z0-9\-]{2,39}-\d{2})\b/', $c, $m)) { foreach ($m[1] as $i) { $f['amazon'][$i][] = (int) $r['ID']; } }
            if (preg_match_all('/\bcampid=["\']?(\d{8,12})/', $c, $m)) { foreach ($m[1] as $i) { $f['ebay'][$i][] = (int) $r['ID']; } }
            if (preg_match_all('#https?://(?:www\.)?(?:amazon\.[a-z.]{2,6}|amzn\.(?:to|eu))/[^\s"\'<>\]]*#i', $c, $m)) {
                foreach ($m[0] as $u) { if (!preg_match('/[?&;]tag=[A-Za-z0-9\-]+-\d{2}/', $u)) { $out['ohne'][(int) $r['ID']] = ['titel' => (string) $r['post_title'], 'n' => ($out['ohne'][(int) $r['ID']]['n'] ?? 0) + 1, 'bsp' => $out['ohne'][(int) $r['ID']]['bsp'] ?? $u]; } }
            }
            foreach ($f as $art => $ids) { foreach ($ids as $i => $pids) { $o = &$out[$art][$i]; $o = $o ?? ['stellen' => 0, 'artikel' => []]; $o['stellen'] += count($pids); foreach ($pids as $pid) { $o['artikel'][$pid] = (string) $r['post_title']; } unset($o); } }
        }
        foreach (['amazon','ebay'] as $k) { $l = &$out[$k]; uasort($l, fn($a, $b) => $b['stellen'] <=> $a['stellen']); unset($l); }
        return $out;
    }

    /** Liste der im Inhalt gefundenen IDs; markiert, welche schon in den Einstellungen stehen, und übernimmt die übrigen auf Knopfdruck. */
    private static function ids_html(): string {
        $sc = self::ids_scan();
        $am_bekannt = array_values(self::tags());
        foreach (preg_split('/\R/', (string) get_option('sab_tags_extra', '')) as $z) { $z = trim(preg_split('/[\s|=,]+/', trim($z))[0] ?? ''); if ($z !== '') { $am_bekannt[] = $z; } }
        $eb_bekannt = [];
        foreach (preg_split('/\R/', (string) get_option('sab_ebay_ids', '')) as $z) { $z = trim(preg_split('/[\s|=,]+/', trim($z))[0] ?? ''); if ($z !== '') { $eb_bekannt[] = $z; } }
        $h = '<h2>Im Inhalt gefundene Partner-IDs</h2><p class="description">Das Plugin hat alle Artikel und Seiten nach Partner-IDs durchsucht. Eingetragene IDs sind grün markiert. „Übernehmen“ trägt die übrigen unten bei „Weitere Partner-IDs“ beziehungsweise „eBay-Kampagnen-IDs“ ein; die Standard-ID bleibt unverändert.</p>';
        $h .= '<form method="post">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="einstellungen"><input type="hidden" name="sab_form" value="ids_uebernehmen">';
        $neu = 0;
        foreach (['amazon' => 'Amazon (tag, tracking_id)', 'ebay' => 'eBay (campid)'] as $art => $titel) {
            $h .= '<h3>' . esc_html($titel) . '</h3>';
            if (!$sc[$art]) { $h .= '<p>Keine gefunden.</p>'; continue; }
            $h .= '<table class="widefat striped" style="max-width:760px"><thead><tr><th>ID</th><th>Stellen</th><th>Artikel</th><th>Stand</th></tr></thead><tbody>';
            foreach ($sc[$art] as $id => $d) {
                $bek = in_array((string) $id, $art === 'amazon' ? $am_bekannt : $eb_bekannt, true); if (!$bek) { $neu++; }
                $h .= '<tr><td><code>' . esc_html((string) $id) . '</code></td><td>' . (int) $d['stellen'] . '</td><td>' . count($d['artikel']) . '</td><td>' . ($bek ? '<span style="color:#1a7f37;font-weight:700">eingetragen</span>' : '<span style="color:#b36b00;font-weight:700">noch nicht eingetragen</span>') . '</td></tr>';
            }
            $h .= '</tbody></table>';
        }
        $h .= '<h3>Amazon-Links ohne Partner-ID</h3>';
        if (!$sc['ohne']) { $h .= '<p>Keine gefunden: Alle fest eingetragenen Amazon-Links tragen eine ID.</p>'; }
        else {
            $h .= '<p class="description">Diese Artikel enthalten Amazon-Links, die kein tag= haben und so keine Provision bringen. Am besten durch ein Plugin-Shortcode ersetzen (Reiter Umbau).</p><table class="widefat striped" style="max-width:900px"><thead><tr><th>Artikel</th><th>Links</th><th>Beispiel</th></tr></thead><tbody>';
            foreach ($sc['ohne'] as $pid => $d) { $h .= '<tr><td><a href="' . esc_url(get_edit_post_link($pid)) . '">' . esc_html($d['titel']) . '</a></td><td>' . (int) $d['n'] . '</td><td><code style="word-break:break-all">' . esc_html(mb_substr($d['bsp'], 0, 90)) . '</code></td></tr>'; }
            $h .= '</tbody></table>';
        }
        $h .= '<p><button class="button"' . ($neu ? '' : ' disabled') . '>Fehlende IDs übernehmen' . ($neu ? ' (' . $neu . ')' : '') . '</button></p></form>';
        return $h;
    }

    /** Neu einlesen: Artikel und Zwischenspeicher frisch prüfen, Warnungen zu Produkten entfernen, die in keinem Artikel mehr stehen. */
    private static function umbau_scan(): string {
        delete_transient('sab_alle_asins'); delete_transient('sab_such_pruefung');
        foreach (array_keys(self::LAENDER) as $l_) { delete_transient("sab_uebersicht_{$l_}"); delete_transient("sab_luecke_{$l_}"); }
        $alle = array_flip(self::alle_asins());
        $bf = (array) get_option('sab_bildfehler', []); $bw = 0;
        foreach ($bf as $k => $e) {
            $pid = (int) ($e['post'] ?? 0); $po = $pid > 0 ? get_post($pid) : null;
            if (!$po || !in_array($po->post_status, ['publish', 'future'], true) || (string) ($e['asin'] ?? '') === '' || strpos((string) $po->post_content, (string) $e['asin']) === false) { unset($bf[$k]); $bw++; }
        }
        update_option('sab_bildfehler', $bf, false);
        $no = (array) get_option('sab_name_offen', []); $nw = 0;
        foreach (array_keys($no) as $k) { if (!isset($alle[(string) $k])) { unset($no[$k]); $nw++; } }
        update_option('sab_name_offen', $no, false);
        $al = (array) get_option('sab_alarm', []); $aw = 0;
        foreach (array_keys($al) as $k) { if (!isset($alle[(string) $k])) { unset($al[$k]); $aw++; } }
        update_option('sab_alarm', $al, false);
        $ar = count(self::umbau_artikelliste());
        return 'Neu eingelesen: ' . $ar . ' Artikel mit Amazon-Links, ' . count($alle) . ' Produkte in Artikeln und Listen. Entfernt: ' . $bw . ' veraltete Bildwarnung(en), ' . $nw . ' Namenshinweis(e), ' . $aw . ' Alarm(e) zu Produkten, die in keinem Artikel mehr stehen. Die Amazon-Daten werden beim nächsten Aufruf frisch geholt.';
    }

    /** Knopf „Neu scannen“ oben auf Umbau und Austausch. */
    private static function scan_knopf_html(string $tab): string {
        return '<form method="post" style="margin:8px 0 12px">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="' . esc_attr($tab) . '"><input type="hidden" name="sab_form" value="umbau_scan"><button class="button button-primary">&#8635; Neu scannen</button> <span class="description">Liest alle Artikel und die Produktliste neu ein und entfernt Anzeigen zu Produkten, die in keinem Artikel mehr stehen (nach Änderungen am Artikel).</span></form>';
    }

    /** Entfernt ASINs aus allen Listen (Produkte, Handdaten, Slots, Tests, Gruppen, Tausch, Alternativen, Kandidaten, Markierungen); Rückgabe: Zahl der entfernten ASINs. */
    private static function verwaiste_entfernen(array $asins): int {
        if (!$asins) { return 0; }
        $set = array_flip($asins);
        $schluessel = ['sab_produkte', 'sab_manuell', 'sab_ersatz', 'sab_amz_alt', 'sab_such_kand', 'sab_auch_gekauft'];   // erste Spalte = ASIN: ganze Zeile weg
        foreach ($schluessel as $o) {
            $neu = [];
            foreach (preg_split('/\R/', (string) get_option($o, '')) as $z) { $k = self::asin(trim(explode('|', $z)[0])); if ($k !== '' && isset($set[$k])) { continue; } $neu[] = $z; }
            update_option($o, implode("\n", $neu), false);
        }
        $neu = [];
        foreach (preg_split('/\R/', (string) get_option('sab_tausch', '')) as $z) { if (preg_match('/\b(' . implode('|', array_map('preg_quote', $asins)) . ')\b/i', $z)) { continue; } $neu[] = $z; }
        update_option('sab_tausch', implode("\n", $neu), false);
        foreach (['sab_slots' => ',', 'sab_tests' => '|', 'sab_gruppen' => ','] as $o => $tr) {   // Liste hinter dem Namen: ASIN herausnehmen, leere Zeilen weg
            $neu = [];
            foreach (preg_split('/\R/', (string) get_option($o, '')) as $z) {
                if (strpos($z, ':') === false) { $neu[] = $z; continue; }
                [$name, $liste] = explode(':', $z, 2);
                $teile = array_values(array_filter(array_map('trim', explode($tr, $liste)), fn($t) => $t !== '' && !isset($set[self::asin($t)])));
                if ($teile) { $neu[] = trim($name) . ': ' . implode($tr === '|' ? ' | ' : ', ', $teile); }
            }
            update_option($o, implode("\n", $neu), false);
        }
        foreach (['sab_marken', 'sab_trotz', 'sab_bonus'] as $o) { update_option($o, array_diff_key((array) get_option($o, []), $set), false); }
        delete_transient('sab_alle_asins');
        return count($asins);
    }

    /** Sprung zur Stelle: ?sab_zeige=ASIN öffnet den Artikel und markiert die Stelle (nur für angemeldete Redakteure). */
    public static function zeige_skript(): void {
        $a = isset($_GET['sab_zeige']) ? preg_replace('/[^A-Za-z0-9]/', '', (string) wp_unslash($_GET['sab_zeige'])) : '';   // ASIN oder Kurzlink-Kennung (Groß- und Kleinschreibung zählt)
        if (strlen($a) < 5 || strlen($a) > 12 || !function_exists('is_user_logged_in') || !is_user_logged_in() || !current_user_can('edit_posts')) { return; }
        $eid = function_exists('get_queried_object_id') ? (int) get_queried_object_id() : 0;
        $ed = $eid && current_user_can('edit_post', $eid) ? admin_url('post.php?post=' . $eid . '&action=edit&sab_zeige=' . rawurlencode($a)) : '';
        $nr = max(1, (int) ($_GET['sab_nr'] ?? 1));
        $kc = $eid && current_user_can('edit_post', $eid) && self::kontrolle_stellen_ids($eid, strtoupper($a)) ? wp_json_encode(['u' => admin_url('admin-ajax.php'), 'n' => wp_create_nonce('sab_stelle'), 'p' => $eid, 'a' => strtoupper($a), 'w' => admin_url('admin.php?page=sab-austausch'), 'v' => self::kontrolle_code($eid, strtoupper($a)), 't' => self::kontrolle_tab_id($eid, strtoupper($a)) !== '']) : 'null';   // gerade im Frontend getauscht und noch nicht bestätigt
        echo '<script>(function(){var KC=' . $kc . ',ed=' . wp_json_encode($ed) . ',a=' . wp_json_encode($a) . ',idx=' . ($nr - 1) . ',s=document.querySelectorAll("[data-sab-weg=\\""+a+"\\"],[data-sab-asin=\\""+a+"\\"],.entry-content a[href*=\\""+a+"\\"]");'
           . 's=(function(l){var o=[],bk="p,div,td,li,figure,h1,h2,h3,h4,h5,h6,section",ck="td,li,.sab-karte,.sab-produkt,.wp-block-column",last=null,lc=null;for(var i=0;i<l.length;i++){var b=l[i].closest(bk)||l[i].parentNode,c=l[i].closest(ck);if(last&&(b===last||(c&&c===lc))){continue}last=b;lc=c;o.push(l[i])}return o})(Array.prototype.slice.call(s));'
           . 'var b=document.createElement("div");b.style.cssText="position:fixed;left:0;right:0;top:0;z-index:99999;background:#d63638;color:#fff;padding:8px 16px;font:600 14px/1.4 sans-serif;text-align:center;display:flex;flex-wrap:wrap;gap:8px 10px;justify-content:center;align-items:center;max-height:45vh;overflow:auto;box-sizing:border-box";'
           . 'var t=document.createElement("span");t.style.cssText="flex:1 1 100%";b.appendChild(t);document.body.appendChild(b);'
           . 'function btn(x,f){var e=document.createElement("a");e.textContent=x;e.href="#";e.style.cssText="background:#fff;color:#d63638;padding:5px 12px;border-radius:4px;text-decoration:none;white-space:nowrap";e.onclick=function(ev){ev.preventDefault();f(e)};b.appendChild(e);return e}'
           . 'if(!s.length){t.textContent="Im sichtbaren Text steht "+a+" nicht"+(ed?"; ich öffne den Editor an der Stelle ...":" (vielleicht in einem Bild oder Code-Block).");if(ed){setTimeout(function(){location.href=ed},1200)}return}'
           . 'if(idx>=s.length){idx=0}'
           . 'for(var i=0;i<s.length;i++){s[i].style.outline="4px solid #d63638";s[i].style.background="#fde8e8";}'
           . 'var le=null;function zeige(n){idx=(n+s.length)%s.length;t.textContent="Hier steht "+a+(s.length>1?" (Stelle "+(idx+1)+" von "+s.length+")":" (1 Stelle)");'
           . 'for(var i=0;i<s.length;i++){s[i].style.outlineColor=i===idx?"#000":"#d63638"}s[idx].scrollIntoView({block:"center"});if(le){le.href=ed+"&sab_nr="+(idx+1)}}'
           . 'if(s.length>1){btn("◀ vorige",function(){zeige(idx-1)});btn("nächste ▶",function(){zeige(idx+1)})}'
           . 'btn("⇄ Hier tauschen",function(){var o=s[idx].closest("[data-sab-ord]"),q=o?document.querySelector("button.sab-tb[data-o=\""+o.getAttribute("data-sab-ord")+"\"]"):null;if(!q){var bx=s[idx].closest(".sab-karte,.sab-produkt,td")||s[idx].parentNode;q=bx&&bx.querySelector("button.sab-tb")}if(q){q.scrollIntoView({block:"center"});q.click()}else{t.textContent="Hier gibt es keinen Tauschen-Knopf (feste Stelle im Text): bitte im Editor bearbeiten."}});'
           . 'function kpost(act,f){var fd=new FormData();fd.append("action",act);fd.append("n",KC.n);fd.append("post",KC.p);fd.append("neu",KC.a);fetch(KC.u,{method:"POST",body:fd,credentials:"same-origin"}).then(function(r){return r.json()}).then(f).catch(function(){t.textContent="Verbindungsfehler"})}'
           . 'if(KC&&KC.v&&KC.v.nachher){btn("Code anzeigen",function(e){var d=document.getElementById("sab-code");if(d){d.remove();return}d=document.createElement("textarea");d.id="sab-code";d.readOnly=true;d.style.cssText="position:fixed;left:8px;right:8px;top:56px;height:42vh;z-index:99998;font:12px monospace;padding:8px";d.value="VORHER:\n"+KC.v.vorher+"\n\nNACHHER:\n"+KC.v.nachher;document.body.appendChild(d);d.select()})}'
           . 'if(KC){var ok=btn("✓ Kontrolliert, passt",function(){kpost("sab_stelle_ok",function(r){if(r.success){location.href=r.data.weiter}else{t.textContent=r.data}})});ok.style.cssText+=";font-weight:700;font-size:16px;padding:8px 18px;order:-2";var rg=btn("✗ Passt nicht, rückgängig"+(KC.t?" (alle Artikel dieses Tauschs)":""),function(){kpost("sab_stelle_zurueck",function(r){if(r.success){location.href=r.data.weiter}else{t.textContent=r.data}})});rg.style.cssText+=";font-weight:700;font-size:16px;padding:8px 18px;order:-2"}'
           . 'if(ed){le=btn("Im Editor bearbeiten",function(e){window.open(e.href,"_blank")});le.href=ed+"&sab_nr="+(idx+1);le.target="_blank";le.onclick=null}'
           . 'var x=btn("×",function(){b.remove()});setTimeout(function(){zeige(idx)},300)})();</script>';
    }

    /** [amz_startseite land="es"]Bei Amazon stöbern[/amz_startseite]: Link zur Amazon-Startseite mit der Partner-ID des Landes (Länder-Umschaltung und Klickstatistik laufen mit, Kennung STARTSEITE). */
    public static function sc_startseite($a, $inhalt = ''): string {
        $a = shortcode_atts(['land' => '', 'class' => '', 'tag' => ''], (array) $a);
        $land = self::land($a['land']); $tag = self::tag_erlaubt((string) $a['tag']) ?: self::tag($land);
        if (!isset(self::LAENDER[$land])) { return esc_html((string) $inhalt); }
        $link = 'https://' . self::LAENDER[$land][0] . '/' . ($tag !== '' ? '?tag=' . rawurlencode($tag) : '');
        $cls = trim(preg_replace('/[^A-Za-z0-9 _-]/', '', (string) $a['class']));
        self::$genutzt = true; self::$links++;
        return '<a' . ($cls ? ' class="' . esc_attr($cls) . '"' : '') . ' href="' . esc_url($link) . '" data-sab-asin="STARTSEITE" data-sab-home="1" target="_blank" rel="nofollow sponsored noopener">' . esc_html(trim((string) $inhalt) !== '' ? (string) $inhalt : 'Amazon') . '</a>';
    }

    public static function sc_link($a, $inhalt = ''): string {
        $a = shortcode_atts(['asin' => '', 'land' => '', 'class' => '', 'tag' => ''], $a);
        $orig = self::asin($a['asin']);
        $asin = self::getauscht($orig);
        $p = self::produkt($asin, self::land($a['land']));
        if ($asin !== $orig && trim((string) $inhalt) !== '') { $inhalt = self::name_tauschen((string) $inhalt, $orig, $asin, self::land($a['land']), $p); }
        $weg_ = $p && !empty($p['weg']);   // Amazon kennt das Produkt (angeblich) nicht: der Link bleibt trotzdem klickbar (Besucher nie ohne Link), Redakteure sehen die Markierung
        $link = $p['link'] ?? '';
        if (!$link) { // Amazon nicht erreichbar und kein frischer Stand: schlichter Link mit Partner-ID
            $land = self::land($a['land']); $tag = self::tag($land);
            if (strlen($asin) !== 10) { return esc_html($inhalt); }   // ohne ASIN gibt es nichts zu verlinken
            $link = 'https://' . self::LAENDER[$land][0] . '/dp/' . $asin . ($tag !== '' ? '?tag=' . rawurlencode($tag) : '');   // auch ohne Partner-ID für dieses Land bleibt der Textlink klickbar (der Scan meldet die fehlende ID)
        }
        if (trim(strip_tags((string) $inhalt)) === '') { $inhalt = (string) ((self::produkte()[$asin] ?? '') ?: ($p['titel'] ?? '') ?: (self::BUTTON_TEXT[self::land($a['land'])] ?? self::BUTTON_TEXT['de'])); }   // nie ein Link ohne sichtbaren Text
        $link = self::lnk($link, (string) $a['tag']);
        $cls = trim(preg_replace('/[^A-Za-z0-9 _-]/', '', (string) $a['class']));
        $a_html = '<a' . ($cls ? ' class="' . esc_attr($cls) . '"' : '') . ' href="' . esc_url($link) . '"' . self::a_attr($asin) . '>' . esc_html($inhalt ?: ($p['titel'] ?? '')) . '</a>';
        return $weg_ ? self::weg_marker($asin, $a_html) : $a_html;
    }

    /** [amz_rezension asin="…" land="co.uk"]Rezensionen ansehen[/amz_rezension]: Link auf die Rezensionsseite des Produkts bei Amazon (Partner-ID des Landes). Es werden keine Rezensionstexte gespeichert oder angezeigt. */
    public static function sc_rezension($a, $inhalt = ''): string {
        $a = shortcode_atts(['asin' => '', 'land' => '', 'class' => ''], $a);
        $asin = self::getauscht(self::asin($a['asin'])); $land = self::land($a['land']); $tag = self::tag($land);
        if (strlen($asin) !== 10 || !isset(self::LAENDER[$land])) { return esc_html((string) $inhalt); }
        $cls = trim(preg_replace('/[^A-Za-z0-9 _-]/', '', (string) $a['class']));
        $link = 'https://' . self::LAENDER[$land][0] . '/product-reviews/' . $asin . ($tag !== '' ? '?tag=' . rawurlencode($tag) : '');
        self::$genutzt = true; self::$links++;
        return '<a' . ($cls ? ' class="' . esc_attr($cls) . '"' : '') . ' href="' . esc_url($link) . '"' . self::a_attr($asin) . '>' . esc_html(trim((string) $inhalt) !== '' ? (string) $inhalt : 'Rezensionen ansehen') . '</a>';
    }

    /** Art einer Amazon-Adresse ohne Produktseite: „startseite“ (Amazon-Startseite), „rezension“ (/product-reviews/ASIN, /dp/ASIN#customerReviews), „liste“ (steht in der Ausnahmeliste „Amazon-Seite, kein Produkt“), sonst leer. */
    public static function seite_art(string $url): string {
        $url = html_entity_decode(trim($url));
        if (!preg_match('#^https?://(?:www\.)?amazon\.(es|de|fr|it|co\.uk|com|ca|com\.au)(/[^?\#]*)?(\?[^\#]*)?(\#.*)?$#i', $url, $m)) { return ''; }
        $pfad = $m[2] ?? '';
        if (preg_match('#^/product-reviews/[A-Za-z0-9]{10}(?:/|$)#i', $pfad) || (preg_match('#/dp/[A-Za-z0-9]{10}#i', $pfad) && stripos($m[4] ?? '', 'customerReviews') !== false)) { return 'rezension'; }
        if ($pfad === '' || $pfad === '/') { return 'startseite'; }
        if (preg_match('#^/s/?$#', $pfad) && preg_match('#[?&](?:k|field-keywords)=#', $m[3] ?? '')) { return 'suche'; }
        foreach (preg_split('/\R/', (string) get_option('sab_amz_seiten', '')) as $z) {
            $z = trim($z); if ($z === '') { continue; }
            $kern = preg_replace('#[?\#].*$#', '', $url);
            if ($z === $url || $z === $kern || (substr($z, -1) === '*' && strpos($url, rtrim($z, '*')) === 0)) { return 'liste'; }
        }
        return '';
    }

    /** Ergänzt an Amazon-Startseiten- und Rezensionslinks fehlende Partner-ID des Landes und rel="sponsored nofollow noopener". @return array{0:string,1:int} */
    public static function seitenlinks_ergaenzen(string $c): array {
        $n = 0;
        $c2 = preg_replace_callback('#<a\b([^>]*)>#i', function ($m) use (&$n) {
            if (!preg_match('#href=(["\'])(.*?)\1#is', $m[1], $h)) { return $m[0]; }
            $u = html_entity_decode($h[2]); $art = self::seite_art($u);
            if ($art !== 'startseite' && $art !== 'rezension' && $art !== 'suche') { return $m[0]; }
            $land = preg_match('#amazon\.(es|de|fr|it|co\.uk|com\.au|com|ca)#i', $u, $lm) ? strtolower($lm[1]) : ''; $tag = $land !== '' ? self::tag($land) : '';
            $neu_u = $u;
            if ($tag !== '' && !preg_match('#[?&]tag=#', $u)) { $anker = ''; if (strpos($u, '#') !== false) { [$u0, $anker] = explode('#', $u, 2); $anker = '#' . $anker; } else { $u0 = $u; } $neu_u = $u0 . (strpos($u0, '?') === false ? '?' : '&') . 'tag=' . rawurlencode($tag) . $anker; }
            $attr = $m[1];
            if ($neu_u !== $u) { $attr = str_replace($h[0], 'href=' . $h[1] . esc_url_raw($neu_u) . $h[1], $attr); }
            if (preg_match('#\brel=(["\'])(.*?)\1#i', $attr, $r)) { $rel = $r[2]; foreach (['sponsored', 'nofollow', 'noopener'] as $w) { if (!preg_match('#\b' . $w . '\b#i', $rel)) { $rel .= ' ' . $w; } } $attr = str_replace($r[0], 'rel=' . $r[1] . trim($rel) . $r[1], $attr); }
            else { $attr .= ' rel="sponsored nofollow noopener"'; }
            if ($attr === $m[1]) { return $m[0]; }
            $n++; return '<a' . $attr . '>';
        }, $c);
        return [is_string($c2) ? $c2 : $c, $n];
    }

    // ---------- A/B-Test der Viererblöcke (zwei Zweierblöcke gegeneinander, ohne Cookie, ohne IP) ----------

    const AB_ZIEL = 1000;   // Standardziel: Besuche (Einblendungen) insgesamt je Test
    private static int $ab_n = 0;
    private static ?array $ab_ctx = null;
    private static array $ab_gesehen = [];

    private static function ab_optionen(): array { $o = get_option('sab_ab', []); return is_array($o) ? $o : []; }

    private static function ab_editor(): bool { return function_exists('is_user_logged_in') && is_user_logged_in() && current_user_can('edit_posts'); }

    private static function ab_aktiv(): array {
        $out = [];
        foreach (self::ab_optionen() as $id => $o) { if (!empty($o['an']) && preg_match('/^[a-f0-9]{8}$/', (string) $id)) { $out[(string) $id] = $o; } }
        return $out;
    }

    private static function seiten_tabelle(): string { global $wpdb; return $wpdb->prefix . 'sab_seiten'; }

    private static function ab_tabelle(): string { global $wpdb; return $wpdb->prefix . 'sab_ab_ansicht'; }

    /** ASINs aus einer Liste von [amz_karte]-Platzhaltern. */
    private static function ab_karten_asins(array $karten): array {
        $as = [];
        foreach ($karten as $k) { if (preg_match('/\basin\s*=\s*["\']?([A-Za-z0-9]{10})/', $k, $m)) { $as[] = strtoupper($m[1]); } }
        return $as;
    }

    /**
     * Viererblöcke eines Artikels: zwei benachbarte Kartenblöcke mit je zwei Produkten oder ein Kartenblock mit vier Produkten.
     * Das Paar mit dem kleineren ASIN-Schlüssel ist immer „A“, so zählen gleiche Blöcke auf mehreren Seiten zusammen.
     * @return array<int,array{s:int,e:int,id:string,p:array}> p = zwei Einträge in Artikelreihenfolge [v => Buchstabe, k => Karten, asins => …]
     */
    public static function ab_finde(string $c): array {
        $out = [];
        if (stripos($c, '[amz_karten') === false || !preg_match_all('#\[amz_karten(?:\s[^\]]*)?\](.*?)\[/amz_karten\]#is', $c, $km, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) { return $out; }
        $bl = [];
        foreach ($km as $m) {
            preg_match_all('#\[amz_karte\b[^\]]*\]#i', $m[1][0], $kk);
            $bl[] = ['s' => $m[0][1], 'e' => $m[0][1] + strlen($m[0][0]), 'k' => $kk[0], 'ab' => stripos($m[0][0], ' ab=') !== false];
        }
        $n = count($bl);
        for ($i = 0; $i < $n; $i++) {
            $b = $bl[$i];
            if ($b['ab']) { continue; }
            $f = null;
            if (count($b['k']) === 4) {
                $f = self::ab_einheit($b['s'], $b['e'], array_slice($b['k'], 0, 2), array_slice($b['k'], 2, 2));
            } elseif (count($b['k']) === 2 && isset($bl[$i + 1]) && count($bl[$i + 1]['k']) === 2 && !$bl[$i + 1]['ab']) {
                $gap = substr($c, $b['e'], $bl[$i + 1]['s'] - $b['e']);
                if (preg_match('#^(?:\s|<p[^>]*>|</p>|<br\s*/?>|&nbsp;|<!--.*?-->)*$#is', $gap)) {
                    $f = self::ab_einheit($b['s'], $bl[$i + 1]['e'], $b['k'], $bl[$i + 1]['k']);
                    if ($f) { $i++; }
                }
            }
            if ($f) { $out[] = $f; }
        }
        return $out;
    }

    private static function ab_einheit(int $s, int $e, array $ka, array $kb): ?array {
        $a = self::ab_karten_asins($ka); $b = self::ab_karten_asins($kb);
        if (count($a) !== 2 || count($b) !== 2 || count(array_unique($a)) !== 2 || count(array_unique($b)) !== 2 || array_intersect($a, $b)) { return null; }
        $sa = $a; sort($sa); $sb = $b; sort($sb);
        $ka_ = implode(',', $sa); $kb_ = implode(',', $sb);
        $tausch = strcmp($ka_, $kb_) > 0;
        return ['s' => $s, 'e' => $e, 'id' => substr(md5($tausch ? $kb_ . '|' . $ka_ : $ka_ . '|' . $kb_), 0, 8),
                'p' => [['v' => $tausch ? 'B' : 'A', 'k' => $ka, 'asins' => $a], ['v' => $tausch ? 'A' : 'B', 'k' => $kb, 'asins' => $b]]];
    }

    /** the_content (vor den Platzhaltern): Kartenblöcke eines laufenden Tests bekommen die Test-Kennung; Inhalt und Datenbank bleiben unverändert. */
    public static function ab_inhalt($c) {
        if (!is_string($c) || stripos($c, '[amz_karten') === false || (function_exists('is_feed') && is_feed())) { return $c; }
        $akt = self::ab_aktiv(); $ed = self::ab_editor();
        if (!$akt && !$ed) { return $c; }
        $neu = ''; $pos = 0;
        foreach (self::ab_finde($c) as $f) {
            $neu .= substr($c, $pos, $f['s'] - $pos); $pos = $f['e'];
            if (empty($akt[$f['id']])) {   // kein Test: nur für angemeldete Redakteure eine Markierung, damit der Knopf „A/B-Test starten“ erscheint
                $neu .= $ed ? preg_replace('#\[amz_karten\]#i', '[amz_karten abx="' . $f['id'] . '"]', substr($c, $f['s'], $f['e'] - $f['s'])) : substr($c, $f['s'], $f['e'] - $f['s']);
                continue;
            }
            $o = ++self::$ab_n;
            foreach ($f['p'] as $i => $p) { $neu .= '[amz_karten ab="' . $f['id'] . '" abv="' . $p['v'] . '" abo="' . $o . '" abi="' . ($i + 1) . '"' . ($ed ? ' abx="' . $f['id'] . '"' : '') . ']' . implode('', $p['k']) . '[/amz_karten]'; }
        }
        return $pos ? $neu . substr($c, $pos) : $c;
    }

    /** Kontext für sc_karten: Kennung des Tests, Zweier-Block und das Skript, das einen der beiden zeigt. */
    private static function ab_karten_start($a): ?array {
        if (!is_array($a) || empty($a['ab']) || !preg_match('/^[a-f0-9]{8}$/', (string) $a['ab']) || !in_array($a['abv'] ?? '', ['A', 'B'], true)) { return null; }
        return ['id' => (string) $a['ab'], 'v' => (string) $a['abv'], 'o' => max(1, (int) ($a['abo'] ?? 1)), 'i' => ((int) ($a['abi'] ?? 1)) === 2 ? 2 : 1];
    }

    private static function ab_skript(array $ab): string {
        $css = '';
        if (!self::$ab_gesehen) { $css = '<style>.sab-karten[data-sab-abi="2"]{display:none}</style><noscript><style>.sab-karten[data-sab-abi="2"]{display:grid!important}</style></noscript>'; }
        self::$ab_gesehen[$ab['id']] = 1;
        $ed = self::ab_editor() ? 'true' : 'false';
        return $css . '<script>(function(o,id,ed){var p=document.querySelectorAll(\'.sab-karten[data-sab-abo="\'+o+\'"]\');if(p.length!==2)return;'
            . 'var f=(location.search.match(/[?&]sab_ab=([AB])/)||[])[1],i=f?(p[0].getAttribute("data-sab-abv")===f?0:1):(ed?0:(Math.random()<.5?0:1));'
            . 'p[1-i].style.setProperty("display","none","important");p[i].style.setProperty("display","grid","important");'
            . 'if(!f&&!ed){(window.sabAB=window.sabAB||[]).push({b:id,v:p[i].getAttribute("data-sab-abv")})}})(' . (int) $ab['o'] . ',' . wp_json_encode($ab['id']) . ',' . $ed . ');</script>';
    }

    // ---- Mathe: zwei Klickraten vergleichen ----

    /** Standardnormalverteilung, Verteilungsfunktion (Abramowitz/Stegun 26.2.17, Fehler < 1e-7). */
    private static function ab_phi(float $x): float {
        $t = 1 / (1 + 0.2316419 * abs($x)); $d = 0.3989423 * exp(-$x * $x / 2);
        $p = $d * $t * (0.3193815 + $t * (-0.3565638 + $t * (1.781478 + $t * (-1.821256 + $t * 1.330274))));
        return $x > 0 ? 1 - $p : $p;
    }

    /** Wilson-Bereich (95 %) für eine Rate. @return array{0:float,1:float} */
    private static function ab_wilson(int $k, int $n): array {
        if ($n < 1) { return [0.0, 0.0]; }
        $z = 1.959964; $p = $k / $n; $z2 = $z * $z;
        $mitte = ($p + $z2 / (2 * $n)) / (1 + $z2 / $n);
        $halb = $z * sqrt($p * (1 - $p) / $n + $z2 / (4 * $n * $n)) / (1 + $z2 / $n);
        return [max(0.0, $mitte - $halb), min(1.0, $mitte + $halb)];
    }

    /** Besuche je Variante, damit eine Rate $p1 gegen $p2 mit 80 % Trefferquote (5 % Fehlalarm) erkannt wird. */
    private static function ab_bedarf(float $p1, float $p2): ?int {
        $d = abs($p1 - $p2);
        if ($d < 1e-9 || $p1 <= 0 && $p2 <= 0) { return null; }
        $n = pow(1.959964 + 0.841621, 2) * ($p1 * (1 - $p1) + $p2 * (1 - $p2)) / ($d * $d);
        return $n > 5000000 ? null : (int) ceil($n);
    }

    /**
     * Kleinster Unterschied (absolut), den ein Test mit $n Besuchen je Variante bei der Rate $p mit 80 % Trefferquote erkennt.
     */
    private static function ab_kleinster_unterschied(float $p, int $n): float {
        if ($n < 1 || $p <= 0 || $p >= 1) { return 0.0; }
        return (1.959964 + 0.841621) * sqrt(2 * $p * (1 - $p) / $n);
    }

    /**
     * Auswertung eines Tests. n = Einblendungen, k = Klicks je Variante; $ziel = Besuche insgesamt.
     * @return array<string,mixed>
     */
    public static function ab_bewerten(int $nA, int $kA, int $nB, int $kB, int $ziel = self::AB_ZIEL): array {
        $pA = $nA > 0 ? min(1, $kA / $nA) : 0.0; $pB = $nB > 0 ? min(1, $kB / $nB) : 0.0;
        $r = ['nA' => $nA, 'kA' => $kA, 'nB' => $nB, 'kB' => $kB, 'pA' => $pA, 'pB' => $pB, 'ziel' => $ziel, 'gesamt' => $nA + $nB];
        $r['sdA'] = sqrt($pA * (1 - $pA)); $r['sdB'] = sqrt($pB * (1 - $pB));   // Streuung eines einzelnen Besuchs (Klick ja/nein)
        $r['seA'] = $nA > 0 ? sqrt($pA * (1 - $pA) / $nA) : 0.0; $r['seB'] = $nB > 0 ? sqrt($pB * (1 - $pB) / $nB) : 0.0;   // Standardfehler der Rate
        $r['ciA'] = self::ab_wilson($kA, $nA); $r['ciB'] = self::ab_wilson($kB, $nB);
        $r['fortschritt'] = $ziel > 0 ? min(1, ($nA + $nB) / $ziel) : 1.0;
        $r['status'] = 'leer'; $r['sieger'] = ''; $r['p_wert'] = null; $r['wahrsch_A'] = null; $r['diff'] = $pA - $pB; $r['diff_ci'] = [0.0, 0.0]; $r['noch'] = null; $r['kleinster'] = null;
        if ($nA < 1 || $nB < 1) { return $r; }
        $sd = sqrt($r['seA'] ** 2 + $r['seB'] ** 2);
        $r['diff_ci'] = [$r['diff'] - 1.959964 * $sd, $r['diff'] + 1.959964 * $sd];
        // Zweiseitiger Test auf gleiche Raten (gemeinsame Rate unter der Annahme „kein Unterschied“)
        $pg = ($kA + $kB) / ($nA + $nB); $se0 = sqrt($pg * (1 - $pg) * (1 / $nA + 1 / $nB));
        if ($se0 > 0) { $z = $r['diff'] / $se0; $r['z'] = $z; $r['p_wert'] = min(1.0, 2 * (1 - self::ab_phi(abs($z)))); }
        // Wahrscheinlichkeit „A ist wirklich besser“: Beta-Verteilungen (Gleichverteilung als Startwissen), Normalnäherung der Differenz
        $aA = 1 + $kA; $bA = 1 + $nA - $kA; $aB = 1 + $kB; $bB = 1 + $nB - $kB;
        $mA = $aA / ($aA + $bA); $mB = $aB / ($aB + $bB);
        $vA = $aA * $bA / ((($aA + $bA) ** 2) * ($aA + $bA + 1)); $vB = $aB * $bB / ((($aB + $bB) ** 2) * ($aB + $bB + 1));
        $r['wahrsch_A'] = self::ab_phi(($mA - $mB) / sqrt($vA + $vB));
        $mehr = max($r['wahrsch_A'], 1 - $r['wahrsch_A']); $vorn = $r['wahrsch_A'] >= 0.5 ? 'A' : 'B';
        $r['sicherheit'] = $mehr; $r['vorn'] = $vorn;
        $nm = min($nA, $nB);
        $nb = self::ab_bedarf($pA, $pB);
        $r['noch'] = $nb === null ? null : max(0, $nb - $nm);
        $r['bedarf'] = $nb;
        $pm = ($pA + $pB) / 2;
        $r['kleinster'] = $pm > 0 ? self::ab_kleinster_unterschied($pm, (int) max(1, $ziel / 2)) : null;   // bei Ziel-Besuchen, je Hälfte
        if ($nA + $nB < $ziel) { $r['status'] = 'laeuft'; return $r; }
        if ($kA + $kB < 10) { $r['status'] = 'wenig'; return $r; }
        if ($r['p_wert'] !== null && $r['p_wert'] < 0.05) { $r['status'] = 'klar'; $r['sieger'] = $vorn; }
        elseif ($mehr >= 0.90) { $r['status'] = 'tendenz'; $r['sieger'] = $vorn; }
        else { $r['status'] = 'gleich'; }
        return $r;
    }


    // ---- Einzelprodukt-Test: eine Karte gegen ein anderes Produkt (nur [amz_karte]) ----
    private static bool $ab_in = false;

    /** Laufender Test für diese ASIN: ['id' => …, 'b' => ASIN] oder null. */
    private static function ab_einzel(string $asin): ?array {
        foreach (self::ab_aktiv() as $id => $o) {
            if (!empty($o['e']) && ($o['a'][0] ?? '') === $asin && strlen((string) ($o['b'][0] ?? '')) === 10) { return ['id' => $id, 'b' => (string) $o['b'][0]]; }
        }
        return null;
    }

    /** Zwei Karten in einer Hülle (display:contents, bleibt im Raster eine Zelle); das Skript zeigt eine davon. */
    private static function ab_einzel_html(array $a, array $ez): string {
        self::$ab_in = true; $o = ++self::$ab_n;
        self::$ab_ctx = ['slot' => 'ab-' . $ez['id'], 'var' => 'A']; $ha = self::sc_karte($a);
        $b = $a; $b['asin'] = $ez['b']; $b['titel'] = ''; $b['ebay'] = ''; $b['campid'] = '';
        self::$ab_ctx = ['slot' => 'ab-' . $ez['id'], 'var' => 'B']; $hb = self::sc_karte($b);
        self::$ab_ctx = null; self::$ab_in = false;
        if ($ha === '' || $hb === '') { return ''; }
        return self::ab_huelle($ez['id'], [$ha, $hb], $o);
    }

    /** Zwei Varianten in einer Hülle (display:contents, bleibt im Raster oder in der Seitenleiste eine Zelle); das Skript zeigt eine davon. */
    private static function ab_huelle(string $id, array $panes, int $o): string {
        $ed = self::ab_editor() ? 'true' : 'false'; $h = '';
        foreach (array_values($panes) as $i => $x) { $h .= '<div class="sab-ab-ep" data-v="' . chr(65 + $i) . '" style="display:' . ($i === 0 ? 'contents' : 'none') . '">' . $x . '</div>'; }
        return '<div class="sab-ab-e" data-sab-abe="' . esc_attr($id) . '" data-sab-abo="' . $o . '" style="display:contents">' . $h . '</div>'
            . '<script>(function(o,id,ed){var w=document.querySelector(\'.sab-ab-e[data-sab-abo="\'+o+\'"]\');if(!w)return;var p=w.children,n=p.length,f=(location.search.match(/[?&]sab_ab=([ABC])/)||[])[1],i=f?Math.min(n-1,f.charCodeAt(0)-65):(ed?0:Math.floor(Math.random()*n)),j;'
            . 'for(j=0;j<n;j++){p[j].style.setProperty("display",j===i?"contents":"none","important")}'
            . 'if(!f&&!ed){(window.sabAB=window.sabAB||[]).push({b:id,v:p[i].getAttribute("data-v")})}})(' . $o . ',' . wp_json_encode($id) . ',' . $ed . ');</script>';
    }

    // ---- Widget-Test (Seitenleiste): Buch-Karte gegen ein anderes Produkt ----
    private static function ab_widget(string $asin): ?array {
        foreach (self::ab_aktiv() as $id => $o) {
            if (!empty($o['w']) && ($o['a'][0] ?? '') === $asin && strlen((string) ($o['b'][0] ?? '')) === 10) { return ['id' => $id, 'f' => (array) ($o['wf'] ?? []), 'b' => (string) $o['b'][0], 'c' => strlen((string) ($o['c'][0] ?? '')) === 10 ? (string) $o['c'][0] : '', 'fc' => (array) ($o['wfc'] ?? [])]; }
        }
        return null;
    }

    private static function ab_widget_html(array $a, array $wz): string {
        self::$ab_in = true; $o = ++self::$ab_n;
        self::$ab_ctx = ['slot' => 'ab-' . $wz['id'], 'var' => 'A']; $ha = self::sc_widget($a);
        $b = $a; $b['asin'] = $wz['b']; $b['alt_text'] = ''; $b['alt_url'] = '';
        foreach (['titel', 'text', 'marke', 'knopf'] as $k) { $b[$k] = (string) ($wz['f'][$k] ?? ''); if ($b[$k] === '' && ($k === 'marke' || $k === 'knopf')) { $b[$k] = (string) $a[$k]; } }   // Etikett und Knopftext gleich wie bei A: die Varianten sollen sich nur im Inhalt unterscheiden
        self::$ab_ctx = ['slot' => 'ab-' . $wz['id'], 'var' => 'B']; $hb = self::sc_widget($b);
        $panes = [$ha, $hb];
        if ($wz['c'] !== '') {
            $c = $a; $c['asin'] = $wz['c']; $c['alt_text'] = ''; $c['alt_url'] = '';
            foreach (['titel', 'text', 'marke', 'knopf'] as $k) { $c[$k] = (string) ($wz['fc'][$k] ?? ''); if ($c[$k] === '' && ($k === 'marke' || $k === 'knopf')) { $c[$k] = (string) $a[$k]; } }
            self::$ab_ctx = ['slot' => 'ab-' . $wz['id'], 'var' => 'C']; $panes[] = self::sc_widget($c);
        }
        self::$ab_ctx = null; self::$ab_in = false;
        foreach ($panes as $x) { if ($x === '') { return ''; } }
        return self::ab_huelle($wz['id'], $panes, $o);
    }

    /** Formular im Umbau-Reiter unter einem fertigen Widget: Test starten oder beenden. */
    private static function ab_widget_form_html(string $id, array $w): string {
        if (!preg_match('#\[amz_widget\b[^\]]*\btyp="(?:buch|produkt)"[^\]]*\basin="([A-Za-z0-9]{10})"#', (string) $w['inhalt'], $m) && !preg_match('#\[amz_widget\b[^\]]*\basin="([A-Za-z0-9]{10})"[^\]]*\btyp="(?:buch|produkt)"#', (string) $w['inhalt'], $m)) { return ''; }
        $asin = strtoupper($m[1]); $lauf = null;
        foreach (self::ab_aktiv() as $tid => $o) { if (!empty($o['w']) && ($o['a'][0] ?? '') === $asin) { $lauf = [$tid, $o]; } }
        $kopf = '<form method="post" action="' . esc_url(admin_url('admin.php?page=sab-umbau#sab-w-' . $id)) . '" style="margin-top:8px;border-top:1px solid #eee;padding-top:8px">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="umbau"><input type="hidden" name="sab_form" value="ab_widget"><input type="hidden" name="sab_wid" value="' . esc_attr($id) . '"><input type="hidden" name="sab_abw_asin" value="' . esc_attr($asin) . '">';
        if ($lauf) {
            return $kopf . '<strong>&#9878; A/B-Test läuft:</strong> ' . esc_html($asin) . ' gegen ' . esc_html((string) $lauf[1]['b'][0]) . (!empty($lauf[1]['c'][0]) ? ' gegen ' . esc_html((string) $lauf[1]['c'][0]) : '') . ' (Zahlen im Reiter A/B-Tests und in der Statistik) <button class="button" name="sab_abw" value="stop:' . esc_attr($lauf[0]) . '" onclick="return confirm(\'Test beenden? Die Zahlen bleiben gespeichert.\')">Test beenden</button></form>';
        }
        return $kopf . '<details><summary><strong>&#9878; A/B-Test: dieses Widget gegen ein anderes Produkt</strong></summary><p class="description">Besucher sehen je zur Hälfte (mit dritter Variante je zu einem Drittel) diese Karte oder die Karte mit dem anderen Produkt. Das Widget selbst bleibt unverändert. Leere Felder bleiben leer (außer dem Titel, der dann der Kurzname des Produkts ist).</p>'
            . '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px">'
            . '<label>ASIN oder Amazon-Link des anderen Produkts<br><input type="text" name="sab_abw_neu" style="width:100%"></label><label>Etikett<br><input type="text" name="sab_abw_marke" style="width:100%"></label><label>Titel<br><input type="text" name="sab_abw_titel" style="width:100%"></label><label>Kurztext<br><input type="text" name="sab_abw_text" style="width:100%"></label><label>Knopftext (leer = Standard)<br><input type="text" name="sab_abw_knopf" style="width:100%"></label></div>'
            . '<p class="description" style="margin-top:10px"><strong>Dritte Variante (optional, A/B/C-Test):</strong> ASIN leer lassen für einen reinen A/B-Test.</p><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px">'
            . '<label>ASIN oder Amazon-Link<br><input type="text" name="sab_abw_c_neu" style="width:100%"></label><label>Etikett<br><input type="text" name="sab_abw_c_marke" style="width:100%"></label><label>Titel<br><input type="text" name="sab_abw_c_titel" style="width:100%"></label><label>Kurztext<br><input type="text" name="sab_abw_c_text" style="width:100%"></label><label>Knopftext (leer = Standard)<br><input type="text" name="sab_abw_c_knopf" style="width:100%"></label></div>'
            . '<p><button class="button button-primary" name="sab_abw" value="start" onclick="return confirm(\'A/B-Test starten? Besucher sehen dann je zur Hälfte die eine oder die andere Karte. Ziel: 1000 Besuche.\')">Test starten</button></p></details></form>';
    }

    /** Speichern aus dem Umbau-Reiter. @return string Meldung */
    private static function ab_widget_speichern(): string {
        $akt = (string) ($_POST['sab_abw'] ?? ''); $opt = self::ab_optionen();
        if (strpos($akt, 'stop:') === 0) {
            $tid = substr($akt, 5);
            if (!isset($opt[$tid])) { return 'Test nicht gefunden.'; }
            $opt[$tid]['an'] = 0; update_option('sab_ab', $opt, false); delete_transient('sab_alle_asins');
            return 'A/B-Test beendet, die Zahlen bleiben gespeichert.';
        }
        $asin = self::asin((string) ($_POST['sab_abw_asin'] ?? '')); $neu = self::asin_aus_text((string) wp_unslash($_POST['sab_abw_neu'] ?? ''));
        if (strlen($asin) !== 10 || strlen($neu) !== 10 || $neu === $asin) { return 'Bitte ein anderes Produkt eintragen (gültige ASIN oder Amazon-Link mit /dp/…). Nichts gestartet.'; }
        foreach (self::ab_aktiv() as $o) { if (!empty($o['w']) && ($o['a'][0] ?? '') === $asin) { return 'Für dieses Widget läuft schon ein Test.'; } }
        if (!self::neues_produkt_vorladen($neu)) { return 'Amazon liefert zu ' . $neu . ' gerade kein Bild oder keine Daten. Nichts gestartet.'; }
        $feld = function (string $pre) use ($neu): array {
            $t = sanitize_text_field(wp_unslash($_POST[$pre . 'titel'] ?? ''));
            return ['titel' => $t, 'text' => sanitize_text_field(wp_unslash($_POST[$pre . 'text'] ?? '')), 'marke' => sanitize_text_field(wp_unslash($_POST[$pre . 'marke'] ?? '')), 'knopf' => sanitize_text_field(wp_unslash($_POST[$pre . 'knopf'] ?? ''))];
        };
        $wf = $feld('sab_abw_'); if ($wf['titel'] === '') { $wf['titel'] = (string) (self::produkte()[$neu] ?? ''); }
        $c = ''; $wfc = [];
        if (trim((string) wp_unslash($_POST['sab_abw_c_neu'] ?? '')) !== '') {
            $c = self::asin_aus_text((string) wp_unslash($_POST['sab_abw_c_neu']));
            if (strlen($c) !== 10 || $c === $asin || $c === $neu) { return 'Die dritte Variante braucht ein weiteres, anderes Produkt (gültige ASIN oder Amazon-Link mit /dp/…). Nichts gestartet.'; }
            if (!self::neues_produkt_vorladen($c)) { return 'Amazon liefert zu ' . $c . ' gerade kein Bild oder keine Daten. Nichts gestartet.'; }
            $wfc = $feld('sab_abw_c_'); if ($wfc['titel'] === '') { $wfc['titel'] = (string) (self::produkte()[$c] ?? ''); }
        }
        $id = substr(md5('w|' . $asin . '|' . $neu . ($c !== '' ? '|' . $c : '')), 0, 8);
        $opt[$id] = ['an' => 1, 'ziel' => self::AB_ZIEL, 'start' => gmdate('Y-m-d'), 'a' => [$asin], 'b' => [$neu], 'w' => 1, 'wf' => $wf];
        if ($c !== '') { $opt[$id]['c'] = [$c]; $opt[$id]['wfc'] = $wfc; }
        update_option('sab_ab', $opt, false); delete_transient('sab_alle_asins');
        return 'A/B' . ($c !== '' ? '/C' : '') . '-Test gestartet: ' . $asin . ' gegen ' . $neu . ($c !== '' ? ' gegen ' . $c : '') . '. Die Zahlen erscheinen im Reiter A/B-Tests.';
    }


    // ---- Zweierblock-Test: ein Zweierblock gegen einen anderen Zweierblock (nicht im Artikel, nur zur Ausgabe) ----
    private static bool $ord_aus = false;

    private static function ab_karten_asins_roh(string $inhalt): array {
        preg_match_all('#\[amz_karte\b[^\]]*\]#i', $inhalt, $m);
        return self::ab_karten_asins($m[0]);
    }

    private static function ab_zweier(array $asins): ?array {
        sort($asins);
        foreach (self::ab_aktiv() as $id => $o) {
            if (empty($o['z'])) { continue; }
            $a = (array) ($o['a'] ?? []); sort($a);
            if ($a === $asins && count((array) ($o['b'] ?? [])) === 2) { return ['id' => $id, 'b' => array_values((array) $o['b'])]; }
        }
        return null;
    }

    private static function ab_zweier_html($a, string $inhalt, array $zz, array $asins): string {
        self::$ab_in = true; $o = ++self::$ab_n;
        $ha = self::sc_karten(['ab' => $zz['id'], 'abv' => 'A', 'abo' => (string) $o, 'abi' => '1'], $inhalt);
        $ib = ''; foreach ($zz['b'] as $x) { $ib .= '[amz_karte asin="' . $x . '"]'; }
        self::$ord_aus = true;   // die eingeblendeten Karten zählen nicht in der Reihenfolge der Stellen im Artikel
        $hb = self::sc_karten(['ab' => $zz['id'], 'abv' => 'B', 'abo' => (string) $o, 'abi' => '1'], $ib);
        self::$ord_aus = false; self::$ab_in = false;
        return $ha === '' || $hb === '' ? '' : self::ab_huelle($zz['id'], [$ha, $hb], $o);
    }

    /** Grund, warum ein Produkt als Gegner vorgeschlagen wird (aus Quelle, Passung, Score, eigenen Klicks). */
    private static function ab_warum(array $c, array $kz, array $gruppe): string {
        $q = (string) ($c['quelle'] ?? ''); $t = [];
        if (in_array($c['asin'], $gruppe, true)) { $t[] = 'gehört zur selben Produktgruppe'; }
        elseif ((int) ($c['passt'] ?? 0) >= 2) { $t[] = 'gleiche Produktart'; }
        elseif ((int) ($c['passt'] ?? 0) === 1) { $t[] = 'ähnlicher Name'; }
        $t[] = 'Quelle: ' . $q;
        if (isset($c['score']) && $c['score'] !== null) { $t[] = 'Score ' . (int) $c['score'] . ' von 100'; }
        $k = (int) ($kz[$c['asin']]['n'] ?? 0);
        $t[] = $k > 0 ? $k . ' Klicks bisher auf deinen Seiten' : 'noch keine Klicks bei dir (neu im Test)';
        $t[] = !empty($c['ok']) ? 'lieferbar' : 'Lieferbarkeit unklar';
        return implode(' · ', $t);
    }

    /** Frontend: Gegner für ein einzelnes Produkt vorschlagen (wie beim Tauschen, aber ohne Produkte, die schon im Artikel stehen, mit Begründung). */
    public static function ajax_ab_kand(): void {
        [$post, $c_, , , $tag_, $pos_, , $asin] = self::stelle_pruefen();
        $land = self::land(''); $d = self::uebersicht_daten($land, false); $trotz = (array) get_option('sab_trotz', []);
        $fund = self::fundstellen([$asin]); $namen = self::produkte();
        $name = ($namen[$asin] ?? '') ?: self::kurzname_aus_titel((string) ($d['items'][$asin]['titel'] ?? ''));
        $k = self::austausch_kandidaten($asin, (string) $name, (array) $d, $trotz, $fund, 12);
        $kz = self::klickzahlen(); $out = [];
        $gruppe = []; foreach (self::gruppen() as $liste) { if (in_array($asin, $liste, true)) { $gruppe = array_merge($gruppe, $liste); } }
        foreach ($k['liste'] as $c) {
            if (stripos($c_, (string) $c['asin']) !== false) { continue; }   // steht schon im Artikel: kein Gegner
            $x = (array) $c['x'];
            $out[] = ['asin' => $c['asin'], 'name' => ($namen[$c['asin']] ?? '') ?: self::kurzname_aus_titel((string) ($x['titel'] ?? '')), 'score' => $c['score'], 'preis' => (string) ($x['preis'] ?? ''), 'img' => !empty($x['bilder'][0]) ? (string) $x['bilder'][0] : '', 'quelle' => $c['quelle'], 'ok' => (bool) $c['ok'], 'warum' => self::ab_warum($c, $kz, $gruppe)];
        }
        $ps = []; foreach (self::alle_asins() as $a_) { if (stripos($c_, (string) $a_) !== false) { continue; } $ps[] = [(string) $a_, (string) ($namen[$a_] ?? ''), mb_substr((string) ($d['items'][$a_]['titel'] ?? ''), 0, 90), isset($d['items'][$a_]) ? (int) self::score(array_merge((array) $d['items'][$a_], ['trotz' => isset($trotz[$a_])]))[0] : -1]; }
        wp_send_json_success(['alt' => $name ?: $asin, 'liste' => $out, 'ps' => $ps]);
    }

    /** Frontend: Gegner für einen Zweierblock vorschlagen: andere Zweierblöcke, die auf deiner Webseite schon vorkommen, mit gutem Score. */
    public static function ajax_ab_blockkand(): void {
        check_ajax_referer('sab_stelle', 'n');
        $pid = (int) ($_POST['post'] ?? 0);
        if (!$pid || !current_user_can('edit_post', $pid)) { wp_send_json_error('Keine Berechtigung.'); }
        $post = get_post($pid); if (!$post) { wp_send_json_error('Artikel nicht gefunden.'); }
        $c_ = (string) $post->post_content; $land = self::land(''); $d = self::uebersicht_daten($land, false); $items = (array) ($d['items'] ?? []); $namen = self::produkte(); $trotz = (array) get_option('sab_trotz', []); $kz = self::klickzahlen();
        $bl = self::bloecke_daten(); $out = [];
        foreach ((array) ($bl['bloecke'][2] ?? []) as $b) {
            $as = (array) $b['asins']; if (count($as) !== 2) { continue; }
            $skip = false; $sum = 0; $kl = 0; $prod = [];
            foreach ($as as $a) {
                $x = $items[$a] ?? null; $tc = ($namen[$a] ?? '') . ' ' . (string) ($x['titel'] ?? '');
                if (!$x || stripos($c_, (string) $a) !== false || self::toetend($tc) || empty($x['lieferbar'])) { $skip = true; break; }
                $sc = (int) self::score(array_merge((array) $x, ['trotz' => isset($trotz[$a])]))[0]; $sum += $sc; $kl += (int) ($kz[$a]['n'] ?? 0);
                $prod[] = ['asin' => $a, 'name' => ($namen[$a] ?? '') ?: self::kurzname_aus_titel((string) ($x['titel'] ?? '')), 'img' => !empty($x['bilder'][0]) ? (string) $x['bilder'][0] : '', 'score' => $sc];
            }
            if ($skip) { continue; }
            $out[] = ['asins' => $as, 'prod' => $prod, 'rang' => $sum / 2 + min(10, (int) $b['n']), 'warum' => 'Steht als Zweierblock schon in ' . count((array) $b['posts']) . ' Artikel(n) · Ø Score ' . (int) round($sum / 2) . ' von 100 · beide lieferbar · ' . $kl . ' Klicks bisher · keines steht schon in diesem Artikel'];
        }
        usort($out, fn($p, $q) => $q['rang'] <=> $p['rang']);
        wp_send_json_success(['liste' => array_slice($out, 0, 6)]);
    }

    /** Frontend: Zweierblock-Test starten. */
    public static function ajax_ab_block(): void {
        check_ajax_referer('sab_stelle', 'n');
        $pid = (int) ($_POST['post'] ?? 0);
        if (!$pid || !current_user_can('edit_post', $pid)) { wp_send_json_error('Keine Berechtigung.'); }
        $post = get_post($pid);
        $a = array_values(array_unique(array_filter(array_map([__CLASS__, 'asin'], explode(',', (string) wp_unslash($_POST['a'] ?? ''))))));
        $b = array_values(array_unique(array_filter(array_map([__CLASS__, 'asin_aus_text'], array_map('trim', explode(',', (string) wp_unslash($_POST['b'] ?? '')))))));
        if (!$post || count($a) !== 2 || count($b) !== 2 || array_intersect($a, $b)) { wp_send_json_error('Bitte zwei andere Produkte für den zweiten Block wählen.'); }
        foreach ($a as $x) { if (stripos((string) $post->post_content, $x) === false) { wp_send_json_error('Der Block steht nicht mehr so im Artikel. Bitte die Seite neu laden.'); } }
        if (self::ab_zweier($a)) { wp_send_json_error('Für diesen Block läuft schon ein Test.'); }
        foreach ($b as $x) { if (!self::neues_produkt_vorladen($x)) { wp_send_json_error('Amazon liefert zu ' . $x . ' gerade kein Bild oder keine Daten. Nicht gestartet.'); } }
        $sa = $a; sort($sa); $sb = $b; sort($sb);
        $id = substr(md5('z|' . implode(',', $sa) . '|' . implode(',', $sb)), 0, 8);
        $opt = self::ab_optionen();
        $opt[$id] = ['an' => 1, 'ziel' => self::AB_ZIEL, 'start' => gmdate('Y-m-d'), 'a' => $a, 'b' => $b, 'z' => 1];
        update_option('sab_ab', $opt, false); delete_transient('sab_alle_asins');
        wp_send_json_success(['id' => $id]);
    }

    /** Frontend-Knopf: Test an oder aus. Viererblock starten (Block muss im Artikel stehen) oder einen laufenden Test beenden. */
    public static function ajax_ab_schalten(): void {
        check_ajax_referer('sab_stelle', 'n');
        $pid = (int) ($_POST['post'] ?? 0);
        if (!$pid || !current_user_can('edit_post', $pid)) { wp_send_json_error('Keine Berechtigung.'); }
        $id = (string) ($_POST['id'] ?? '');
        if (!preg_match('/^[a-f0-9]{8}$/', $id)) { wp_send_json_error('Ungültiger Test.'); }
        $opt = self::ab_optionen(); $o = $opt[$id] ?? [];
        if (empty($_POST['an'])) {
            if (!$o) { wp_send_json_error('Test nicht gefunden.'); }
            $o['an'] = 0; $opt[$id] = $o; update_option('sab_ab', $opt, false); delete_transient('sab_alle_asins');
            wp_send_json_success(['an' => 0]);
        }
        if (!$o) {
            $post = get_post($pid); $f = null;
            if ($post) { foreach (self::ab_finde((string) $post->post_content) as $x) { if ($x['id'] === $id) { $f = $x; break; } } }
            if (!$f) { wp_send_json_error('Der Viererblock steht nicht mehr so im Artikel.'); }
            $pa = $f['p'][0]['v'] === 'A' ? $f['p'][0] : $f['p'][1]; $pb = $f['p'][0]['v'] === 'A' ? $f['p'][1] : $f['p'][0];
            $o = ['a' => $pa['asins'], 'b' => $pb['asins'], 'ziel' => self::AB_ZIEL];
        }
        if (empty($o['an']) || empty($o['start'])) { $o['start'] = gmdate('Y-m-d'); }
        $o['an'] = 1; $opt[$id] = $o; update_option('sab_ab', $opt, false); delete_transient('sab_alle_asins');
        wp_send_json_success(['an' => 1]);
    }

    /** Frontend-Knopf an einer Karte: dieses Produkt tritt gegen ein anderes an. */
    public static function ajax_ab_einzel(): void {
        check_ajax_referer('sab_stelle', 'n');
        $pid = (int) ($_POST['post'] ?? 0);
        if (!$pid || !current_user_can('edit_post', $pid)) { wp_send_json_error('Keine Berechtigung.'); }
        $asin = self::asin((string) wp_unslash($_POST['asin'] ?? '')); $neu = self::asin_aus_text((string) wp_unslash($_POST['neu'] ?? ''));
        $post = get_post($pid);
        if (strlen($asin) !== 10 || strlen($neu) !== 10 || $neu === $asin) { wp_send_json_error('Bitte ein anderes Produkt wählen (gültige ASIN oder Amazon-Link mit /dp/…).'); }
        if (!$post || stripos((string) $post->post_content, $asin) === false) { wp_send_json_error('Das Produkt steht nicht mehr so im Artikel. Bitte die Seite neu laden.'); }
        if (self::ab_einzel($asin)) { wp_send_json_error('Für dieses Produkt läuft schon ein Test.'); }
        if (!self::neues_produkt_vorladen($neu)) { wp_send_json_error('Amazon liefert zu ' . $neu . ' gerade kein Bild oder keine Daten. Nicht gestartet.'); }
        $id = substr(md5('e|' . $asin . '|' . $neu), 0, 8);
        $opt = self::ab_optionen();
        $opt[$id] = ['an' => 1, 'ziel' => self::AB_ZIEL, 'start' => gmdate('Y-m-d'), 'a' => [$asin], 'b' => [$neu], 'e' => 1];
        update_option('sab_ab', $opt, false); delete_transient('sab_alle_asins');
        wp_send_json_success(['id' => $id]);
    }


    /**
     * Auswertung mit drei Varianten (A, B, C). Gesamttest auf gleiche Klickraten (Chi-Quadrat, 2 Freiheitsgrade, p = e^(−χ²/2)),
     * danach die beste Variante gegen jede andere (Zweistichproben-z, Bonferroni: p mal 2) und die Wahrscheinlichkeit, die beste zu sein (Simulation).
     * @param array<string,array{0:int,1:int}> $g Buchstabe => [Einblendungen, Klicks]
     * @return array<string,mixed>
     */
    public static function ab_bewerten_n(array $g, int $ziel = self::AB_ZIEL, string $saat = 'x'): array {
        $r = ['multi' => true, 'ziel' => $ziel, 'v' => [], 'gesamt' => 0, 'status' => 'leer', 'sieger' => '', 'chi_p' => null, 'vorn' => '', 'bestwahrsch' => [], 'vgl' => [], 'noch' => null, 'kleinster' => null];
        $N = $K = 0; $min = PHP_INT_MAX;
        foreach ($g as $b => [$n, $k]) {
            $p = $n > 0 ? min(1, $k / $n) : 0.0;
            $r['v'][$b] = ['n' => $n, 'k' => $k, 'p' => $p, 'sd' => sqrt($p * (1 - $p)), 'se' => $n > 0 ? sqrt($p * (1 - $p) / $n) : 0.0, 'ci' => self::ab_wilson($k, $n)];
            $N += $n; $K += $k; $min = min($min, $n);
        }
        $r['gesamt'] = $N; $r['fortschritt'] = $ziel > 0 ? min(1, $N / $ziel) : 1.0;
        if ($min < 1) { return $r; }
        $pg = $K / $N; $chi = 0.0;
        if ($pg > 0 && $pg < 1) { foreach ($r['v'] as $x) { $chi += pow($x['k'] - $x['n'] * $pg, 2) / ($x['n'] * $pg * (1 - $pg)); } $r['chi'] = $chi; $r['chi_p'] = exp(-$chi / 2); }
        uasort($r['v'], fn($a, $b) => $b['p'] <=> $a['p']);   // beste zuerst
        $letters = array_keys($r['v']); $best = $letters[0]; $r['vorn'] = $best; $zweite = $letters[1];
        foreach (array_slice($letters, 1) as $b) {
            $x = $r['v'][$b]; $y = $r['v'][$best];
            $pp = ($x['k'] + $y['k']) / ($x['n'] + $y['n']); $se0 = sqrt($pp * (1 - $pp) * (1 / $x['n'] + 1 / $y['n']));
            $z = $se0 > 0 ? ($y['p'] - $x['p']) / $se0 : 0.0; $pw = $se0 > 0 ? 2 * (1 - self::ab_phi(abs($z))) : 1.0;
            $r['vgl'][$b] = ['p' => min(1.0, $pw), 'p_korr' => min(1.0, $pw * (count($letters) - 1)), 'diff' => $y['p'] - $x['p']];
        }
        // Wahrscheinlichkeit, die beste zu sein (Normalnäherung der Beta-Verteilungen, feste Saat => stabile Anzeige)
        mt_srand(crc32($saat)); $zaehl = array_fill_keys($letters, 0); $T = 6000;
        $par = [];
        foreach ($r['v'] as $b => $x) { $a1 = 1 + $x['k']; $b1 = 1 + $x['n'] - $x['k']; $par[$b] = [$a1 / ($a1 + $b1), sqrt($a1 * $b1 / ((($a1 + $b1) ** 2) * ($a1 + $b1 + 1)))]; }
        for ($t = 0; $t < $T; $t++) {
            $bm = -1.0; $bb = '';
            foreach ($par as $b => [$m, $sd]) { $u1 = (mt_rand(1, 2147483646)) / 2147483647; $u2 = mt_rand(1, 2147483646) / 2147483647; $v = $m + $sd * sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2); if ($v > $bm) { $bm = $v; $bb = $b; } }
            $zaehl[$bb]++;
        }
        foreach ($zaehl as $b => $c) { $r['bestwahrsch'][$b] = $c / $T; }
        $r['sicherheit'] = $r['bestwahrsch'][$best];
        $za = 2.241403; $zb = 0.841621;   // zweiseitig 5 % geteilt durch 2 Vergleiche, Trefferquote 80 %
        $p1 = $r['v'][$best]['p']; $p2 = $r['v'][$zweite]['p'];
        $d = abs($p1 - $p2);
        $r['noch'] = null;
        if ($d > 1e-9) { $nb = pow($za + $zb, 2) * ($p1 * (1 - $p1) + $p2 * (1 - $p2)) / ($d * $d); if ($nb < 5000000) { $r['bedarf'] = (int) ceil($nb); $r['noch'] = max(0, (int) ceil($nb) - $r['v'][$zweite]['n']); } }
        $pm = $pg; $nj = max(1, (int) ($ziel / 3));
        $r['kleinster'] = $pm > 0 && $pm < 1 ? ($za + $zb) * sqrt(2 * $pm * (1 - $pm) / $nj) : null;
        if ($N < $ziel) { $r['status'] = 'laeuft'; return $r; }
        if ($K < 10) { $r['status'] = 'wenig'; return $r; }
        $alle = true; $einer = false; foreach ($r['vgl'] as $q) { if ($q['p_korr'] >= 0.05) { $alle = false; } else { $einer = true; } }
        if ($r['chi_p'] !== null && $r['chi_p'] < 0.05 && $alle) { $r['status'] = 'klar'; $r['sieger'] = $best; }
        elseif ($r['chi_p'] !== null && $r['chi_p'] < 0.05 && $einer) { $r['status'] = 'teils'; }
        elseif ($r['sicherheit'] >= 0.90) { $r['status'] = 'tendenz'; $r['sieger'] = $best; }
        else { $r['status'] = 'gleich'; }
        return $r;
    }

    /** Einblendungen und Klicks aller Tests, je Test ab dessen Startdatum. @return array<string,array> */
    private static function ab_zahlen(): array {
        global $wpdb;
        $opt = self::ab_optionen(); $out = [];
        if (!isset($wpdb) || !$opt) { return $out; }
        $min = gmdate('Y-m-d');
        foreach ($opt as $id => $o) { $min = min($min, (string) ($o['start'] ?? $min)); }
        $ta = self::ab_tabelle(); $tk = self::klicktabelle();
        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT block, var, pfad, tag, SUM(n) n FROM $ta WHERE tag >= %s GROUP BY block, var, pfad, tag", $min), ARRAY_A) as $r) {
            $id = (string) $r['block']; if (!isset($opt[$id]) || (string) $r['tag'] < (string) ($opt[$id]['start'] ?? '')) { continue; }
            $v = in_array($r['var'], ['A', 'B', 'C'], true) ? (string) $r['var'] : 'A';
            $out[$id]['v'][$v]['n'] = ($out[$id]['v'][$v]['n'] ?? 0) + (int) $r['n'];
            $out[$id]['s'][$r['pfad']][$v]['n'] = ($out[$id]['s'][$r['pfad']][$v]['n'] ?? 0) + (int) $r['n'];
            $out[$id]['t'][$r['tag']][$v]['n'] = ($out[$id]['t'][$r['tag']][$v]['n'] ?? 0) + (int) $r['n'];
        }
        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT slot, var, pfad, DATE(ts) d, COUNT(*) n, SUM(zurueck_s IS NOT NULL) z FROM $tk WHERE slot LIKE 'ab-%%' AND ts >= %s GROUP BY slot, var, pfad, DATE(ts)", $min . ' 00:00:00'), ARRAY_A) as $r) {
            $id = substr((string) $r['slot'], 3); if (!isset($opt[$id]) || (string) $r['d'] < (string) ($opt[$id]['start'] ?? '')) { continue; }
            $v = in_array($r['var'], ['A', 'B', 'C'], true) ? (string) $r['var'] : 'A';
            $out[$id]['v'][$v]['k'] = ($out[$id]['v'][$v]['k'] ?? 0) + (int) $r['n'];
            $out[$id]['v'][$v]['z'] = ($out[$id]['v'][$v]['z'] ?? 0) + (int) $r['z'];
            $out[$id]['s'][$r['pfad']][$v]['k'] = ($out[$id]['s'][$r['pfad']][$v]['k'] ?? 0) + (int) $r['n'];
            $out[$id]['t'][$r['d']][$v]['k'] = ($out[$id]['t'][$r['d']][$v]['k'] ?? 0) + (int) $r['n'];
        }
        foreach ($out as $id => &$d) { if (!empty($opt[$id]['c'])) { $d['r'] = self::ab_bewerten_n(['A' => [(int) ($d['v']['A']['n'] ?? 0), (int) ($d['v']['A']['k'] ?? 0)], 'B' => [(int) ($d['v']['B']['n'] ?? 0), (int) ($d['v']['B']['k'] ?? 0)], 'C' => [(int) ($d['v']['C']['n'] ?? 0), (int) ($d['v']['C']['k'] ?? 0)]], max(1, (int) ($opt[$id]['ziel'] ?? self::AB_ZIEL)), (string) $id); continue; } $d['r'] = self::ab_bewerten((int) ($d['v']['A']['n'] ?? 0), (int) ($d['v']['A']['k'] ?? 0), (int) ($d['v']['B']['n'] ?? 0), (int) ($d['v']['B']['k'] ?? 0), max(1, (int) ($opt[$id]['ziel'] ?? self::AB_ZIEL))); } unset($d);
        return $out;
    }

    /** Alle Viererblöcke der Webseite (Cache 15 Min.): id => [asins => [A, B], seiten => [post_id => Titel]] */
    private static function ab_scan(bool $neu = false): array {
        $c = get_transient('sab_ab_scan');
        if (is_array($c) && !$neu) { return $c; }
        global $wpdb; $out = [];
        if (isset($wpdb)) {
            foreach ((array) $wpdb->get_results("SELECT ID, post_title, post_content FROM {$wpdb->posts} WHERE post_status='publish' AND post_type IN ('post','page') AND post_content LIKE '%[amz\\_karten%'", ARRAY_A) as $r) {
                foreach (self::ab_finde((string) $r['post_content']) as $f) {
                    $a = $f['p'][0]['v'] === 'A' ? $f['p'][0] : $f['p'][1]; $b = $f['p'][0]['v'] === 'A' ? $f['p'][1] : $f['p'][0];
                    $out[$f['id']]['asins'] = ['A' => $a['asins'], 'B' => $b['asins']];
                    $out[$f['id']]['seiten'][(int) $r['ID']] = (string) $r['post_title'];
                }
            }
        }
        set_transient('sab_ab_scan', $out, 15 * MINUTE_IN_SECONDS);
        return $out;
    }

    private static function ab_pfad_von(int $id): string { $l = get_permalink($id); return $l ? (string) wp_parse_url($l, PHP_URL_PATH) : ''; }

    private static function ab_produkt_html(string $asin): string {
        $namen = self::produkte(); $land = self::land('');
        $p = self::produkt($asin, $land, false, false);
        $nm = (string) ($namen[$asin] ?? ($p['titel'] ?? $asin));
        $bild = $p ? self::bild_url($p, $asin, $land) : '';
        return '<span class="sab-ab-prod">' . ($bild !== '' ? '<img src="' . esc_url($bild) . '" alt="" loading="lazy">' : '<span class="sab-ab-leer"></span>') . '<span>' . esc_html(mb_substr($nm, 0, 60)) . '<br><code>' . esc_html($asin) . '</code></span></span>';
    }

    private static function ab_pct(float $x, int $dez = 1): string { return number_format($x * 100, $dez, ',', '.') . ' %'; }

    private static function ab_status_text(array $r): array {
        switch ($r['status']) {
            case 'klar': return ['ok', 'Klarer Sieger: Paar ' . $r['sieger'], 'Der Unterschied ist so groß, dass er sehr wahrscheinlich nicht vom Zufall kommt.'];
            case 'tendenz': return ['mid', 'Tendenz zu Paar ' . $r['sieger'], 'Paar ' . $r['sieger'] . ' liegt vorn, aber noch nicht sicher genug. Weiter messen.'];
            case 'teils': return ['mid', 'Teilweise klar: Variante ' . $r['vorn'] . ' liegt vorn', 'Die beste Variante ist von mindestens einer anderen sicher besser, aber nicht von allen. Weiter messen oder die schwächste Variante streichen.'];
            case 'gleich': return ['mid', 'Kein erkennbarer Unterschied', 'Beide Paare sind gleich gut oder der Unterschied ist kleiner als messbar. Du kannst das Paar nehmen, das dir besser gefällt.'];
            case 'wenig': return ['mid', 'Zu wenige Klicks', 'Das Besuchsziel ist erreicht, aber es gab weniger als 10 Klicks. Erst mehr Besuche abwarten.'];
            case 'laeuft':
                $h = !empty($r['vorn']) && ($r['sicherheit'] ?? 0) >= 0.8 ? ' Bisher liegt Paar ' . $r['vorn'] . ' vorn.' : '';
                return ['run', 'Läuft', 'Noch ' . max(0, $r['ziel'] - $r['gesamt']) . ' Besuche bis zum Ziel.' . $h];
            default: return ['run', 'Noch keine Daten', 'Sobald Besucher die Seite aufrufen, erscheinen hier die Zahlen.'];
        }
    }

    private static function ab_css(): string {
        return '<style>.sab-ab-karte{border:1px solid #dcdcde;border-radius:10px;background:#fff;padding:14px 16px;margin:14px 0;max-width:1100px}'
            . '.sab-ab-kopf{display:flex;flex-wrap:wrap;gap:8px 16px;align-items:center;justify-content:space-between;margin:0 0 10px}'
            . '.sab-ab-badge{display:inline-block;padding:3px 10px;border-radius:999px;font-weight:600;font-size:12px}.sab-ab-badge.ok{background:#d9f2e0;color:#0a5c25}.sab-ab-badge.mid{background:#fff1cc;color:#6b4b00}.sab-ab-badge.run{background:#e1ecf7;color:#17446d}'
            . '.sab-ab-paare{display:grid;grid-template-columns:1fr 1fr;gap:12px}@media(max-width:782px){.sab-ab-paare{grid-template-columns:1fr}}'
            . '.sab-ab-paar{border:1px solid #e0e0e0;border-radius:8px;padding:10px;border-left-width:6px}.sab-ab-paar.a{border-left-color:#2271b1}.sab-ab-paar.b{border-left-color:#e68a00}.sab-ab-paar.c{border-left-color:#2f9e55}.sab-ab-bereich i.c{background:#2f9e55}.sab-ab-balken .c{background:#2f9e55}.sab-ab-paar.sieg{background:#f4fbf6;box-shadow:0 0 0 2px #46b450 inset}'
            . '.sab-ab-paar h4{margin:0 0 6px;font-size:14px}.sab-ab-prod{display:flex;gap:8px;align-items:center;margin:4px 0;font-size:12px}.sab-ab-prod img{width:44px;height:44px;object-fit:contain;background:#fff;border:1px solid #eee;border-radius:4px}.sab-ab-leer{width:44px;height:44px;background:#f0f0f1;border-radius:4px;display:inline-block}'
            . '.sab-ab-zahl{font-size:24px;font-weight:700;line-height:1.1;margin:8px 0 0}.sab-ab-klein{color:#50575e;font-size:12px}'
            . '.sab-ab-balken{height:12px;background:#eef0f2;border-radius:6px;overflow:hidden;margin:6px 0}.sab-ab-balken i{display:block;height:100%}.sab-ab-balken .a{background:#2271b1}.sab-ab-balken .b{background:#e68a00}.sab-ab-balken .z{background:#46b450}'
            . '.sab-ab-bereich{position:relative;height:14px;background:#f0f0f1;border-radius:7px;margin:6px 0 2px}.sab-ab-bereich i{position:absolute;top:3px;height:8px;border-radius:4px;opacity:.85}.sab-ab-bereich i.a{background:#2271b1}.sab-ab-bereich i.b{background:#e68a00}'
            . '.sab-ab-erkl{background:#f6f7f7;border-radius:8px;padding:8px 12px;margin:10px 0 0;font-size:13px}.sab-ab-erkl p{margin:.4em 0}</style>';
    }

    /** Eine Test-Karte: Produkte beider Paare, Score, Fortschritt, Bereiche, Urteil, Seiten und Verlauf. */
    // ---- Stil-Experimente: dasselbe Widget in drei Aufmachungen (A/B/C), Variante wählt der Browser per Zufall ----

    /** Katalog der Experimente. Jede Variante setzt nur eine CSS-Klasse am Widget. */
    private static function stil_katalog(): array {
        return [
            'bewegung' => ['id' => 'b3e60001', 'name' => 'Bewegung im Buch-Widget', 'ziel' => 3000,
                'text' => 'Zeigt dasselbe Buch-Widget in drei Aufmachungen und misst, bei welcher mehr Besucher auf den Knopf klicken.',
                'v' => ['A' => ['Wippen und Glanz', 'sab-m-a'], 'B' => ['Tierchen mit Blüte', 'sab-m-b'], 'C' => ['Ruhig, ohne Bewegung', 'sab-m-c']]],
            'knopftext' => ['id' => 'b3e60002', 'name' => 'Knopftext in Artikeln', 'typ' => 'text', 'ziel' => 3000,
                'text' => 'Wechselt den Text aller Kartenknöpfe in einem Artikel: Ein Besucher sieht in einem Artikel immer denselben Text, zwischen Besuchern wechselt er. Gemessen wird die Klickrate je Text über alle Artikel. Gilt für die Karten-Knöpfe in Artikeln, nicht für die Widgets.',
                'v' => [
                    'A' => ['Bisherige Texte (Standard)', []],
                    'B' => ['„Angebot bei Amazon“', ['de' => 'Angebot bei Amazon', 'es' => 'Oferta en Amazon', 'fr' => 'Offre sur Amazon', 'it' => 'Offerta su Amazon', 'co.uk' => 'Offer on Amazon', 'com' => 'Offer on Amazon']],
                    'C' => ['„Preis bei Amazon prüfen“', ['de' => 'Preis bei Amazon prüfen', 'es' => 'Ver precio en Amazon', 'fr' => 'Voir le prix sur Amazon', 'it' => 'Vedi il prezzo su Amazon', 'co.uk' => 'Check price on Amazon', 'com' => 'Check price on Amazon']]]],
        ];
    }

    /** Laufende Stil-Experimente (nur die mit gesetztem Haken). @return array<string,array> Kennung => Katalogeintrag */
    private static function stil_aktiv(): array {
        $out = [];
        foreach (self::ab_aktiv() as $id => $o) {
            $k = self::stil_katalog()[(string) ($o['s'] ?? '')] ?? null;
            if ($k && $k['id'] === (string) $id) { $out[(string) $id] = $k; }
        }
        return $out;
    }

    private static bool $stil_n = false;

    /** Im Fuß: weist jedem sichtbaren Widget eine Variante zu (Zufall, ohne Cookie/IP) und meldet die Einblendung. Redakteure sehen immer die Grundfassung. */
    public static function stil_skript(): void {
        if (self::ab_editor()) { return; }
        $akt = self::stil_aktiv(); if (!$akt) { return; }
        $land = self::land(''); $e = [];
        foreach ($akt as $id => $k) {
            if (($k['typ'] ?? '') === 'text') {
                $m = []; foreach ($k['v'] as $b => $v) { $m[$b] = (string) ($v[1][$land] ?? ''); }
                if (count(array_filter($m)) < 1) { continue; }   // für diese Sprache gibt es keine Textvarianten
                $e[] = ['id' => $id, 'x' => $m];
            } elseif (self::$stil_n) {
                $m = []; foreach ($k['v'] as $b => $v) { $m[$b] = $v[1]; }
                $e[] = ['id' => $id, 'k' => $m];
            }
        }
        if (!$e) { return; }
        echo '<script>(function(T){var w=document.querySelectorAll(".sab-wb"),i,j,t,v,c,b,k;(window.sabAB=window.sabAB||[]);'
           . 'for(j=0;j<T.length;j++){t=T[j];v=Object.keys(t.k||t.x);'
           . 'if(t.k){for(i=0;i<w.length;i++){if(!w[i].getClientRects().length||w[i].hasAttribute("data-sab-xs"))continue;c=v[Math.floor(Math.random()*v.length)];w[i].classList.add(t.k[c]);w[i].setAttribute("data-sab-xs","ab-"+t.id);w[i].setAttribute("data-sab-xv",c);window.sabAB.push({b:t.id,v:c})}}'
           . 'else{b=document.querySelectorAll(".sab-karte-btn");if(!b.length||document.documentElement.hasAttribute("data-sab-xs"))continue;c=v[Math.floor(Math.random()*v.length)];'
           . 'document.documentElement.setAttribute("data-sab-xs","ab-"+t.id);document.documentElement.setAttribute("data-sab-xv",c);'
           . 'if(t.x[c]){for(k=0;k<b.length;k++){if(!b[k].children.length)b[k].textContent=t.x[c]}}window.sabAB.push({b:t.id,v:c})}}})(' . wp_json_encode($e) . ');</script>';
    }

    /** Reiter „A/B-Tests“: Abschnitt „Experimente“ mit Start- und Stopp-Knopf je Experiment. */
    private static function stil_tab_html(): string {
        $opt = self::ab_optionen();
        $h = '<h3 style="margin-top:2em">Experimente mit der Aufmachung (A/B/C)</h3><p class="description">Hier wird nicht das Produkt getestet, sondern die Aufmachung. Jeder Besucher bekommt per Zufall eine von drei Varianten, ohne Cookie und ohne IP. Der Test läuft über alle Seiten mit dem Widget und zählt Einblendungen und Klicks auf den Knopf.</p>';
        foreach (self::stil_katalog() as $key => $k) {
            $an = !empty($opt[$k['id']]['an']);
            $h .= '<form method="post" style="margin:0 0 10px;padding:10px 12px;background:#fff;border:1px solid #dcdcde;border-radius:6px;max-width:900px">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false)
               . '<input type="hidden" name="sab_tab" value="tests"><input type="hidden" name="sab_form" value="ab_stil"><input type="hidden" name="sab_stil" value="' . esc_attr($key) . '">'
               . '<strong>' . esc_html($k['name']) . '</strong> · ' . ($an ? '<span style="color:#1a7f37">läuft</span>' : '<span style="color:#666">aus</span>') . '<br><span class="description">' . esc_html($k['text']) . '</span><br>';
            foreach ($k['v'] as $b => $v) { $h .= '<small><strong>' . $b . ':</strong> ' . esc_html($v[0]) . '</small> &nbsp; '; }
            $h .= '<br><button class="button' . ($an ? '' : ' button-primary') . '" name="sab_stil_an" value="' . ($an ? '0' : '1') . '" style="margin-top:6px">' . ($an ? 'Experiment beenden' : 'Experiment starten (Ziel ' . (int) $k['ziel'] . ' Einblendungen)') . '</button>'
               . ($an ? ' <button class="button" name="sab_stil_an" value="2" onclick="return confirm(\'Zähler dieses Experiments auf null setzen und neu starten?\')" style="margin-top:6px">Zähler neu starten</button>' : '') . '</form>';
        }
        return $h;
    }

    private static function stil_speichern(): string {
        $key = (string) ($_POST['sab_stil'] ?? ''); $k = self::stil_katalog()[$key] ?? null;
        if (!$k) { return 'Unbekanntes Experiment.'; }
        $opt = self::ab_optionen(); $id = $k['id']; $m = (string) ($_POST['sab_stil_an'] ?? '');
        $o = $opt[$id] ?? ['a' => [], 'b' => [], 'c' => ['x'], 's' => $key, 'ziel' => (int) $k['ziel']];
        if ($m === '1' || $m === '2') { $o['an'] = 1; if ($m === '2' || empty($o['start'])) { $o['start'] = gmdate('Y-m-d'); } }
        else { $o['an'] = 0; if (empty($o['start'])) { return 'Das Experiment lief nicht.'; } }
        $opt[$id] = $o; update_option('sab_ab', $opt, false);
        return $m === '2' ? 'Neu gestartet, gezählt wird ab heute.' : ($m === '1' ? 'Experiment gestartet. Beobachte die Zahlen oben unter „Laufende Tests“.' : 'Experiment beendet, die Zahlen bleiben stehen.');
    }

    private static function ab_karte_html(string $id, array $o, array $z, bool $voll): string {
        if (!empty($o['c'])) { return self::ab_karte_multi_html($id, $o, $z, $voll); }
        $r = $z['r'] ?? self::ab_bewerten(0, 0, 0, 0, max(1, (int) ($o['ziel'] ?? self::AB_ZIEL)));
        [$kl, $titel, $erkl] = self::ab_status_text($r);
        $h = '<div class="sab-ab-karte"><div class="sab-ab-kopf"><strong>Test ' . esc_html($id) . '</strong><span class="sab-ab-badge ' . $kl . '">' . esc_html($titel) . '</span></div>';
        $h .= '<p class="sab-ab-klein" style="margin:0 0 8px">' . esc_html($erkl) . '</p>';
        $h .= '<div class="sab-ab-balken" title="Fortschritt zum Ziel"><i class="z" style="width:' . round($r['fortschritt'] * 100) . '%"></i></div>';
        $h .= '<p class="sab-ab-klein" style="margin:0 0 10px">' . (int) $r['gesamt'] . ' von ' . (int) $r['ziel'] . ' Besuchen (' . round($r['fortschritt'] * 100) . ' %) · Start ' . esc_html(date_i18n('d.m.Y', strtotime((string) ($o['start'] ?? 'now')))) . '</p>';
        $h .= '<div class="sab-ab-paare">';
        $maxp = max(0.0001, $r['pA'], $r['pB'], $r['ciA'][1], $r['ciB'][1]);
        foreach (['A', 'B'] as $v) {
            $l = strtolower($v); $asins = (array) ($o[$l] ?? []);
            $n = $r['n' . $v]; $k = $r['k' . $v]; $p = $r['p' . $v]; $ci = $r['ci' . $v];
            $sieg = $r['status'] === 'klar' && $r['sieger'] === $v;
            $h .= '<div class="sab-ab-paar ' . $l . ($sieg ? ' sieg' : '') . '"><h4>Paar ' . $v . ($sieg ? ' &#127942;' : '') . '</h4>';
            foreach ($asins as $a) { $h .= self::ab_produkt_html((string) $a); }
            $h .= '<div class="sab-ab-zahl">' . self::ab_pct($p) . '</div><div class="sab-ab-klein">Klickrate: ' . (int) $k . ' Klicks bei ' . (int) $n . ' Einblendungen</div>';
            $h .= '<div class="sab-ab-bereich" title="95 %-Bereich der wahren Klickrate"><i class="' . $l . '" style="left:' . round(100 * $ci[0] / $maxp / 1.05, 1) . '%;width:' . max(1, round(100 * ($ci[1] - $ci[0]) / $maxp / 1.05, 1)) . '%"></i></div>';
            $h .= '<div class="sab-ab-klein">Wahrscheinlich zwischen ' . self::ab_pct($ci[0]) . ' und ' . self::ab_pct($ci[1]) . ' (±' . self::ab_pct($r['se' . $v], 2) . ' Streuung)</div></div>';
        }
        $h .= '</div>';
        if ($r['p_wert'] !== null) {
            $h .= '<p style="margin:12px 0 0"><strong>Verlässlichkeit:</strong> ' . ($r['wahrsch_A'] >= 0.5 ? 'Paar A' : 'Paar B') . ' ist mit <strong>' . self::ab_pct($r['sicherheit'], 0) . '</strong> Wahrscheinlichkeit wirklich besser'
               . ' · Zufallswert (p) ' . number_format((float) $r['p_wert'], 3, ',', '.') . ' · Unterschied A minus B: ' . ($r['diff'] >= 0 ? '+' : '−') . number_format(abs($r['diff']) * 100, 2, ',', '.') . ' Prozentpunkte (95 %-Bereich ' . number_format($r['diff_ci'][0] * 100, 2, ',', '.') . ' bis ' . number_format($r['diff_ci'][1] * 100, 2, ',', '.') . ')</p>';
            if ($r['noch'] !== null && $r['status'] !== 'klar') { $h .= '<p class="sab-ab-klein" style="margin:4px 0 0">Wenn der heutige Abstand bleibt, braucht der Test noch etwa ' . (int) $r['noch'] . ' Besuche je Paar, um ihn sicher zu erkennen.</p>'; }
            if ($r['kleinster'] !== null) { $h .= '<p class="sab-ab-klein" style="margin:4px 0 0">Mit ' . (int) $r['ziel'] . ' Besuchen erkennt der Test nur Unterschiede ab etwa ' . number_format($r['kleinster'] * 100, 1, ',', '.') . ' Prozentpunkten (bei dieser Klickrate).</p>'; }
        }
        if ($voll) {
            if (!empty($z['s'])) {
                $h .= '<details style="margin:10px 0 0"><summary><strong>Seiten, auf denen der Test läuft (' . count($z['s']) . ')</strong></summary><table class="widefat striped" style="margin-top:6px"><thead><tr><th>Seite</th><th>A: Einbl.</th><th>A: Klicks</th><th>B: Einbl.</th><th>B: Klicks</th></tr></thead><tbody>';
                foreach ($z['s'] as $pf => $d) { $h .= '<tr><td>' . esc_html((string) $pf) . '</td><td>' . (int) ($d['A']['n'] ?? 0) . '</td><td>' . (int) ($d['A']['k'] ?? 0) . '</td><td>' . (int) ($d['B']['n'] ?? 0) . '</td><td>' . (int) ($d['B']['k'] ?? 0) . '</td></tr>'; }
                $h .= '</tbody></table></details>';
            }
            if (!empty($z['t']) && count($z['t']) > 1) { $h .= self::ab_verlauf_svg($z['t']); }
        }
        return $h . '</div>';
    }

    /** Test-Karte mit drei Varianten. */
    private static function ab_karte_multi_html(string $id, array $o, array $z, bool $voll): string {
        $ziel = max(1, (int) ($o['ziel'] ?? self::AB_ZIEL));
        $r = $z['r'] ?? self::ab_bewerten_n(['A' => [0, 0], 'B' => [0, 0], 'C' => [0, 0]], $ziel, $id);
        [$kl, $titel, $erkl] = self::ab_status_text($r);
        $h = '<div class="sab-ab-karte"><div class="sab-ab-kopf"><strong>' . (!empty($o['s']) ? esc_html((string) (self::stil_katalog()[(string) $o['s']]['name'] ?? $id)) : 'Test ' . esc_html($id) . ' (drei Varianten)') . '</strong><span class="sab-ab-badge ' . $kl . '">' . esc_html($titel) . '</span></div>';
        $h .= '<p class="sab-ab-klein" style="margin:0 0 8px">' . esc_html($erkl) . '</p>';
        $h .= '<div class="sab-ab-balken" title="Fortschritt zum Ziel"><i class="z" style="width:' . round(($r['fortschritt'] ?? 0) * 100) . '%"></i></div>';
        $h .= '<p class="sab-ab-klein" style="margin:0 0 10px">' . (int) $r['gesamt'] . ' von ' . $ziel . ' Besuchen (' . round(($r['fortschritt'] ?? 0) * 100) . ' %) · Start ' . esc_html(date_i18n('d.m.Y', strtotime((string) ($o['start'] ?? 'now')))) . '</p>';
        $h .= '<div class="sab-ab-paare" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr))">';
        $max = 0.0001; foreach ($r['v'] as $x) { $max = max($max, $x['p'], $x['ci'][1]); }
        foreach (['A', 'B', 'C'] as $b) {
            $l = strtolower($b); $x = $r['v'][$b] ?? ['n' => 0, 'k' => 0, 'p' => 0.0, 'se' => 0.0, 'ci' => [0.0, 0.0]];
            $sieg = $r['status'] === 'klar' && $r['sieger'] === $b;
            $h .= '<div class="sab-ab-paar ' . $l . ($sieg ? ' sieg' : '') . '"><h4>Variante ' . $b . ($sieg ? ' &#127942;' : '') . '</h4>';
            if (!empty($o['s'])) { $h .= '<p style="margin:0 0 6px"><strong>' . esc_html((string) (self::stil_katalog()[(string) $o['s']]['v'][$b][0] ?? '')) . '</strong></p>'; } else { foreach ((array) ($o[$l] ?? []) as $a) { $h .= self::ab_produkt_html((string) $a); } }
            $h .= '<div class="sab-ab-zahl">' . self::ab_pct($x['p']) . '</div><div class="sab-ab-klein">Klickrate: ' . (int) $x['k'] . ' Klicks bei ' . (int) $x['n'] . ' Einblendungen</div>'
               . '<div class="sab-ab-bereich" title="95 %-Bereich der wahren Klickrate"><i class="' . $l . '" style="left:' . round(100 * $x['ci'][0] / $max / 1.05, 1) . '%;width:' . max(1, round(100 * ($x['ci'][1] - $x['ci'][0]) / $max / 1.05, 1)) . '%"></i></div>'
               . '<div class="sab-ab-klein">Wahrscheinlich zwischen ' . self::ab_pct($x['ci'][0]) . ' und ' . self::ab_pct($x['ci'][1]) . ' (±' . self::ab_pct($x['se'], 2) . ' Streuung)</div>'
               . (isset($r['bestwahrsch'][$b]) ? '<div class="sab-ab-klein"><strong>' . self::ab_pct($r['bestwahrsch'][$b], 0) . '</strong> Wahrscheinlichkeit, die beste zu sein</div>' : '') . '</div>';
        }
        $h .= '</div>';
        if ($r['chi_p'] !== null) {
            $h .= '<p style="margin:12px 0 0"><strong>Verlässlichkeit:</strong> Gesamttest „sind die drei Varianten überhaupt verschieden?“: Zufallswert p = ' . number_format((float) $r['chi_p'], 3, ',', '.') . ($r['chi_p'] < 0.05 ? ' (ja, Unterschiede sind wahrscheinlich echt)' : ' (nein, das kann Zufall sein)') . '.</p>';
            foreach ($r['vgl'] as $b => $q) { $h .= '<p class="sab-ab-klein" style="margin:3px 0 0">Beste (' . esc_html($r['vorn']) . ') gegen ' . esc_html($b) . ': Vorsprung ' . number_format($q['diff'] * 100, 2, ',', '.') . ' Prozentpunkte, p = ' . number_format($q['p'], 3, ',', '.') . ', nach Korrektur für zwei Vergleiche ' . number_format($q['p_korr'], 3, ',', '.') . ($q['p_korr'] < 0.05 ? ' (sicher besser)' : ' (noch nicht sicher)') . '</p>'; }
            if ($r['noch'] !== null && $r['status'] !== 'klar') { $h .= '<p class="sab-ab-klein" style="margin:4px 0 0">Wenn der heutige Abstand bleibt, braucht der Test noch etwa ' . (int) $r['noch'] . ' Besuche je Variante, um die beste von der zweitbesten sicher zu trennen.</p>'; }
            if ($r['kleinster'] !== null) { $h .= '<p class="sab-ab-klein" style="margin:4px 0 0">Mit ' . $ziel . ' Besuchen (etwa ' . (int) ($ziel / 3) . ' je Variante) erkennt der Test nur Unterschiede ab etwa ' . number_format($r['kleinster'] * 100, 1, ',', '.') . ' Prozentpunkten.</p>'; }
        }
        if ($voll) {
            if (!empty($z['s'])) {
                $h .= '<details style="margin:10px 0 0"><summary><strong>Seiten, auf denen der Test läuft (' . count($z['s']) . ')</strong></summary><table class="widefat striped" style="margin-top:6px"><thead><tr><th>Seite</th><th>A: Einbl./Klicks</th><th>B: Einbl./Klicks</th><th>C: Einbl./Klicks</th></tr></thead><tbody>';
                foreach ($z['s'] as $pf => $d) { $h .= '<tr><td>' . esc_html((string) $pf) . '</td>'; foreach (['A', 'B', 'C'] as $b) { $h .= '<td>' . (int) ($d[$b]['n'] ?? 0) . ' / ' . (int) ($d[$b]['k'] ?? 0) . '</td>'; } $h .= '</tr>'; }
                $h .= '</tbody></table></details>';
            }
            if (!empty($z['t']) && count($z['t']) > 1) { $h .= self::ab_verlauf_svg($z['t']); }
        }
        return $h . '</div>';
    }

    /** Verlauf: Klickrate A und B über die Tage (aufsummiert). */
    private static function ab_verlauf_svg(array $t): string {
        ksort($t); $nA = $kA = $nB = $kB = $nC = $kC = 0; $pts = [];
        foreach ($t as $d => $x) {
            $nA += (int) ($x['A']['n'] ?? 0); $kA += (int) ($x['A']['k'] ?? 0); $nB += (int) ($x['B']['n'] ?? 0); $kB += (int) ($x['B']['k'] ?? 0); $nC += (int) ($x['C']['n'] ?? 0); $kC += (int) ($x['C']['k'] ?? 0);
            $pts[] = [$nA > 0 ? $kA / $nA : 0, $nB > 0 ? $kB / $nB : 0, $nC > 0 ? $kC / $nC : 0];
        }
        $max = max(0.001, max(array_map('max', $pts))); $n = count($pts); $w = 560; $hh = 110;
        $lin = ['', '', '']; $hatC = $nC > 0;
        foreach ($pts as $i => $p) { $x = 8 + ($w - 16) * ($n > 1 ? $i / ($n - 1) : 0); foreach ([0, 1, 2] as $j) { $lin[$j] .= round($x, 1) . ',' . round($hh - 8 - ($hh - 24) * $p[$j] / $max, 1) . ' '; } }
        return '<details open style="margin:10px 0 0"><summary><strong>Verlauf der Klickrate</strong> <span class="sab-ab-klein">(aufsummiert, höchster Wert ' . self::ab_pct($max) . ')</span></summary><svg viewBox="0 0 ' . $w . ' ' . $hh . '" style="width:100%;max-width:700px;height:auto;background:#fff;border:1px solid #e0e0e0;border-radius:6px" role="img" aria-label="Verlauf der Klickrate"><polyline fill="none" stroke="#2271b1" stroke-width="2.5" points="' . $lin[0] . '"/><polyline fill="none" stroke="#e68a00" stroke-width="2.5" points="' . $lin[1] . '"/>' . ($hatC ? '<polyline fill="none" stroke="#2f9e55" stroke-width="2.5" points="' . $lin[2] . '"/>' : '') . '</svg><p class="sab-ab-klein" style="margin:2px 0 0"><span style="color:#2271b1">&#9632;</span> Paar A &nbsp; <span style="color:#e68a00">&#9632;</span> Paar B' . ($hatC ? ' &nbsp; <span style="color:#2f9e55">&#9632;</span> Variante C' : '') . '</p></details>';
    }

    private static function ab_erklaerung_html(): string {
        return '<details class="sab-ab-erkl"><summary><strong>So lese ich die Zahlen</strong></summary>'
            . '<p><strong>Drei Varianten (A/B/C):</strong> Zuerst prüft ein Gesamttest, ob die drei Klickraten überhaupt verschieden sind (Zufallswert unter 0,05). Dann wird die beste Variante gegen jede andere verglichen; weil dabei zwei Vergleiche laufen, wird der Zufallswert verdoppelt, damit der Test nicht zu leicht „Sieger“ ruft. „Klarer Sieger“ gibt es nur, wenn die beste von beiden anderen sicher besser ist. Außerdem steht dort, mit welcher Wahrscheinlichkeit jede Variante die beste ist. Drei Varianten brauchen mehr Besuche als zwei; bei 1000 Besuchen (etwa 333 je Variante) sieht man nur große Unterschiede.</p>'
            . '<p><strong>Klickrate</strong> = Klicks geteilt durch Einblendungen. Eine Einblendung zählt, wenn ein Besucher die Seite mit dem Block öffnet und ihm ein Paar gezeigt wird (ohne Cookie, ohne IP).</p>'
            . '<p><strong>Streuung (±)</strong> ist der Standardfehler: So weit kann die gemessene Rate vom wahren Wert abweichen. Er sinkt mit der Wurzel der Besuche: Mit viermal so vielen Besuchen halbiert sich die Streuung. Die Standardabweichung eines einzelnen Besuchs (Klick ja oder nein) ist √(Rate × (1 − Rate)); bei 4 % Klickrate sind das rund 20 Prozentpunkte, deshalb braucht man viele Besuche.</p>'
            . '<p><strong>Bereich (95 %)</strong>: Der wahre Wert liegt mit 95 % Sicherheit in diesem Bereich. Überlappen sich die Bereiche stark, ist noch nichts entschieden.</p>'
            . '<p><strong>Verlässlichkeit</strong>: Die Prozentzahl sagt, wie wahrscheinlich das vorne liegende Paar wirklich besser ist. Ab 95 % und erreichtem Besuchsziel zeigt der Test „klarer Sieger“, ab 90 % „Tendenz“. Der Zufallswert p ist die Gegenprobe: Ist p unter 0,05, ist ein Zufall unwahrscheinlich.</p>'
            . '<p><strong>Wichtig:</strong> Nicht zwischendurch aufhören, weil gerade ein Paar vorne liegt. Wer oft nachschaut und bei Glück stoppt, bekommt zu oft falsche Sieger. Deshalb wird erst nach dem Besuchsziel ein Sieger erklärt (Start: 1000 Besuche insgesamt, also etwa 500 je Paar). Bei Klickraten um 3 bis 5 % erkennen 1000 Besuche nur große Unterschiede (ungefähr doppelt so viele Klicks); kleinere brauchen mehrere tausend Besuche. Die Zeile „Mit … Besuchen erkennt der Test nur Unterschiede ab …“ zeigt das für jeden Test.</p>'
            . '<p>Ein Besucher kann mehrere Karten anklicken; gezählt werden Klicks. Beide Paare haben gleich viele Karten, daher bleibt der Vergleich fair.</p></details>';
    }

    /** Reiter „A/B-Tests“: laufende Tests, alle gefundenen Viererblöcke zum Einschalten, Ziele. */
    private static function ab_tab_html(): string {
        $opt = self::ab_optionen(); $zahlen = self::ab_zahlen(); $scan = self::ab_scan(!empty($_POST['sab_ab_neu']));
        $h = self::ab_css() . '<h2>A/B-Test der Viererblöcke</h2>'
           . '<p>Ein Viererblock (zwei Zweierblöcke nebeneinander) wird geteilt: Jeder Besucher sieht nur eins der beiden Paare, per Zufall (etwa 50:50), ohne Cookie und ohne IP. Gezählt werden Einblendungen und Klicks je Paar. Im HTML stehen beide Paare, der Artikel in der Datenbank bleibt unverändert. Zum Ansehen: <code>?sab_ab=A</code> oder <code>?sab_ab=B</code> an die Adresse hängen (zählt nicht mit).</p>';
        $lauf = []; $andere = [];
        foreach ($opt as $id => $o) { if (!empty($o['an'])) { $lauf[$id] = $o; } else { $andere[$id] = $o; } }
        $h .= '<h3>Laufende Tests (' . count($lauf) . ')</h3>';
        if (!$lauf) { $h .= '<p>Noch kein Test läuft. Unten die Blöcke auswählen und speichern.</p>'; }
        uasort($lauf, fn($a, $b) => strcmp((string) ($b['start'] ?? ''), (string) ($a['start'] ?? '')));
        foreach ($lauf as $id => $o) {
            $h .= self::ab_karte_html((string) $id, $o, $zahlen[$id] ?? [], true);
            $s = $scan[$id]['seiten'] ?? [];
            if ($s) { $h .= '<p class="sab-ab-klein" style="margin:-8px 0 0 4px">Gefunden in: ' . self::bloecke_artikel_links($s, 8) . '</p>'; }
        }
        $h .= self::ab_erklaerung_html();
        $h .= self::stil_tab_html();
        $h .= self::ab_seiten_html();
        $h .= '<h3 style="margin-top:2em">Alle Viererblöcke (' . count($scan) . ')</h3><p class="description">Gefunden in veröffentlichten Artikeln: zwei benachbarte Kartenblöcke mit je zwei Produkten oder ein Kartenblock mit vier. Neu eingefügte Blöcke erscheinen nach „Neu suchen“. Ziel = Besuche insgesamt.</p>'
           . '<form method="post" style="margin:0 0 8px">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="tests"><button class="button" name="sab_ab_neu" value="1">Neu suchen</button></form>'
           . '<form method="post">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="tests"><input type="hidden" name="sab_form" value="ab">';
        if (!$scan && !$opt) { $h .= '<p>Keine Viererblöcke gefunden. Sie entstehen im Reiter Umbau, wenn Tabellen zu Karten werden.</p>'; }
        $ids = array_values(array_filter(array_unique(array_merge(array_keys($scan), array_keys($opt))), fn($i) => empty($opt[$i]['s'])));
        $h .= '<table class="widefat striped" style="max-width:1100px"><thead><tr><th style="width:60px">Test</th><th>Paar A</th><th>Paar B</th><th>Seiten</th><th style="width:90px">Ziel</th></tr></thead><tbody>';
        foreach ($ids as $id) {
            $o = $opt[$id] ?? []; $asins = $scan[$id]['asins'] ?? ['A' => (array) ($o['a'] ?? []), 'B' => (array) ($o['b'] ?? [])];
            $h .= '<tr><td data-l="Test"><label><input type="checkbox" name="sab_ab_an[' . esc_attr($id) . ']" value="1"' . (!empty($o['an']) ? ' checked' : '') . '> <strong>an</strong></label><br><small><code>' . esc_html($id) . '</code></small>' . (!empty($o['e']) ? '<br><small>Einzelprodukt</small>' : '') . (!empty($o['w']) ? '<br><small>Widget</small>' : '') . (!empty($o['z']) ? '<br><small>Zweierblock</small>' : '') . '</td>';
            foreach (['A', 'B'] as $v) { $h .= '<td data-l="Paar ' . $v . '">'; foreach ($asins[$v] as $a) { $h .= self::ab_produkt_html((string) $a); } if ($v === 'B' && !empty($o['c'])) { $h .= '<p class="sab-ab-klein" style="margin:6px 0 2px"><strong>Variante C:</strong></p>'; foreach ((array) $o['c'] as $a) { $h .= self::ab_produkt_html((string) $a); } } $h .= '</td>'; }
            $s = $scan[$id]['seiten'] ?? [];
            $h .= '<td data-l="Seiten">' . ($s ? self::bloecke_artikel_links($s, 6) : '<em>nicht mehr gefunden</em>') . '</td>'
               . '<td data-l="Ziel"><input type="number" min="100" step="100" name="sab_ab_ziel[' . esc_attr($id) . ']" value="' . (int) ($o['ziel'] ?? self::AB_ZIEL) . '" style="width:80px"></td></tr>';
        }
        $h .= '</tbody></table><p><button class="button button-primary">Speichern</button> <button class="button" name="sab_ab_null" value="1" onclick="return confirm(\'Zähler der angehakten Tests auf null setzen und neu starten?\')">Zähler der angehakten Tests neu starten</button></p></form>';
        return $h;
    }

    /** Seiten mit Aufrufen, Klicks und Klickrate (eigene Zählung, ohne Cookie/IP); schwache Seiten mit vielen Besuchen zuerst im Blick. */
    private static function ab_seiten_html(): string {
        global $wpdb;
        if (!isset($wpdb)) { return ''; }
        $tage = in_array((int) ($_GET['tage'] ?? 30), [7, 30, 90, 365], true) ? (int) ($_GET['tage'] ?? 30) : 30;
        $ab = gmdate('Y-m-d', time() - ($tage - 1) * DAY_IN_SECONDS);
        $tp = self::seiten_tabelle(); $tk = self::klicktabelle(); $z = [];
        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT pfad, SUM(n) n FROM $tp WHERE tag >= %s GROUP BY pfad", $ab), ARRAY_A) as $r) { $z[(string) $r['pfad']]['v'] = (int) $r['n']; }
        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT pfad, COUNT(*) n FROM $tk WHERE ts >= %s GROUP BY pfad", $ab . ' 00:00:00'), ARRAY_A) as $r) { $z[(string) $r['pfad']]['k'] = (int) $r['n']; }
        $fr = (string) get_option('sab_seiten_start', '');
        if ($fr === '') { $fr = gmdate('Y-m-d'); update_option('sab_seiten_start', $fr, false); }
        $lauf = [];
        foreach (self::ab_scan() as $id => $d) { if (!empty(self::ab_aktiv()[$id])) { foreach ($d['seiten'] as $pid => $t) { $pf = self::ab_pfad_von((int) $pid); if ($pf !== '') { $lauf[$pf] = ($lauf[$pf] ?? 0) + 1; } } } }
        $v = $k = 0; foreach ($z as $d) { $v += (int) ($d['v'] ?? 0); $k += (int) ($d['k'] ?? 0); }
        $mittel = $v > 0 ? $k / $v : 0;
        $h = '<h3 style="margin-top:2em">Seiten: Aufrufe und Klickrate</h3><p class="description">Das Plugin zählt selbst, wie oft eine Seite mit Produkten aufgerufen wird (nur Summe je Seite und Tag, ohne Cookie und IP) und wie viele Klicks auf Produkte kommen. Die Zählung der Aufrufe läuft seit ' . esc_html(date_i18n('d.m.Y', strtotime($fr))) . '; davor gibt es nur Klicks. Aufrufe von Redakteuren und Robotern zählen nicht.</p><p>Zeitraum: ';
        foreach ([7 => '7 Tage', 30 => '30 Tage', 90 => '90 Tage', 365 => 'Jahr'] as $t => $l) { $h .= ($t === $tage ? '<strong>' . esc_html($l) . '</strong>' : '<a href="' . esc_url(add_query_arg(['page' => 'sab-tests', 'tage' => $t], admin_url('admin.php'))) . '">' . esc_html($l) . '</a>') . ' &nbsp; '; }
        if (!$z) { return $h . '</p><p>Noch keine Daten.</p>'; }
        $h .= '</p><p><strong>' . (int) $v . '</strong> Aufrufe, <strong>' . (int) $k . '</strong> Klicks, Klickrate im Schnitt <strong>' . self::ab_pct($mittel) . '</strong>. Rot: mindestens 100 Aufrufe und weniger als die Hälfte der durchschnittlichen Klickrate. Dort lohnt sich ein A/B-Test am meisten (Seite öffnen, als Redakteur die Knöpfe nutzen).</p>';
        $h .= '<table class="widefat striped sab-sort2" style="max-width:1000px"><thead><tr><th>Seite</th><th>Aufrufe</th><th>Klicks</th><th>Klickrate</th><th>Läufe Tests</th><th>Hinweis</th></tr></thead><tbody>';
        uasort($z, fn($a, $b) => ((int) ($b['v'] ?? 0)) <=> ((int) ($a['v'] ?? 0)));
        foreach (array_slice($z, 0, 300, true) as $pf => $d) {
            $vv = (int) ($d['v'] ?? 0); $kk = (int) ($d['k'] ?? 0); $r = $vv > 0 ? $kk / $vv : null;
            $schwach = $vv >= 100 && $r !== null && $r < $mittel * 0.5;
            $h .= '<tr><td data-l="Seite"><a href="' . esc_url(home_url((string) $pf)) . '" target="_blank" rel="noopener">' . esc_html((string) $pf ?: '/') . '</a></td><td data-l="Aufrufe" data-v="' . $vv . '">' . $vv . '</td><td data-l="Klicks" data-v="' . $kk . '">' . $kk . '</td>'
               . '<td data-l="Klickrate" data-v="' . ($r === null ? '' : round($r * 100, 3)) . '"' . ($schwach ? ' style="color:#a00;font-weight:700"' : '') . '>' . ($r === null ? '–' : self::ab_pct($r)) . '</td><td data-l="Tests" data-v="' . (int) ($lauf[$pf] ?? 0) . '">' . (int) ($lauf[$pf] ?? 0) . '</td>'
               . '<td data-l="Hinweis">' . ($schwach ? '<span style="color:#a00">schwach bei vielen Besuchen: A/B-Test starten</span>' : ($vv < 100 ? '<span class="sab-ab-klein">noch wenig Aufrufe</span>' : '')) . '</td></tr>';
        }
        return $h . '</tbody></table>' . self::tabellenhilfe();
    }

    /** Statistik-Reiter: Zwischenstand aller Tests mit Zahlen, gut sichtbar über den Klickzahlen. */
    private static function ab_statistik_html(): string {
        $opt = self::ab_optionen(); if (!$opt) { return ''; }
        $zahlen = self::ab_zahlen(); $zeilen = [];
        foreach ($opt as $id => $o) { if (empty($o['an']) && empty($zahlen[$id])) { continue; } $zeilen[$id] = $o; }
        if (!$zeilen) { return ''; }
        $h = self::ab_css() . '<h2>A/B-Tests: Zwischenstand</h2><p class="description">Viererblöcke als Test. Mehr Einstellungen im Reiter A/B-Tests.</p>';
        foreach ($zeilen as $id => $o) { $h .= self::ab_karte_html((string) $id, $o, $zahlen[$id] ?? [], false); }
        return $h . self::ab_erklaerung_html() . '<hr style="margin:20px 0">';
    }

    /** Speichern im Reiter: Haken, Ziele, Neustart. Merkt die ASINs der Paare, damit die Karte auch bleibt, wenn der Block aus dem Artikel verschwindet. */
    private static function ab_speichern(): string {
        $opt = self::ab_optionen(); $scan = self::ab_scan();
        $an = (array) ($_POST['sab_ab_an'] ?? []); $zl = (array) ($_POST['sab_ab_ziel'] ?? []);
        $null = !empty($_POST['sab_ab_null']);
        foreach (array_unique(array_merge(array_keys($scan), array_keys($opt))) as $id) {
            if (!preg_match('/^[a-f0-9]{8}$/', (string) $id) || !empty($opt[$id]['s'])) { continue; }
            $o = $opt[$id] ?? [];
            $neu = isset($an[$id]);
            $o['ziel'] = max(100, (int) ($zl[$id] ?? ($o['ziel'] ?? self::AB_ZIEL)));
            if ($neu && (empty($o['an']) || empty($o['start']))) { $o['start'] = gmdate('Y-m-d'); }
            elseif ($neu && $null) { $o['start'] = gmdate('Y-m-d'); }
            if (isset($scan[$id]['asins'])) { $o['a'] = $scan[$id]['asins']['A']; $o['b'] = $scan[$id]['asins']['B']; }
            $o['an'] = $neu ? 1 : 0;
            if (!$neu && empty($o['start'])) { continue; }   // nie gestartet: nicht speichern
            $opt[$id] = $o;
        }
        update_option('sab_ab', $opt, false);
        return $null ? 'Gespeichert, Zähler der angehakten Tests neu gestartet (gezählt wird ab heute).' : 'Gespeichert.';
    }

    /** REST: Einblendungen (Browser hat per Zufall ein Paar gewählt). Nur Summen je Test, Paar, Tag und Seite. */
    private static function ab_ansichten_zaehlen(array $liste, string $pfad): void {
        global $wpdb;
        $akt = self::ab_aktiv(); if (!$akt) { return; }
        $t = self::ab_tabelle(); $pf = substr((string) wp_parse_url($pfad, PHP_URL_PATH), 0, 190); $tag = gmdate('Y-m-d'); $gesehen = [];
        $zeilen = [];
        foreach (array_slice($liste, 0, 8) as $e) {
            $b = (string) ($e['b'] ?? ''); $v = (string) ($e['v'] ?? '');
            if (!preg_match('/^[a-f0-9]{8}$/', $b) || !in_array($v, ['A', 'B', 'C'], true) || empty($akt[$b])) { continue; }
            $zeilen[] = $wpdb->prepare("(%s, %s, %s, %s, 1)", $b, $v, $tag, $pf);
        }
        if ($zeilen) { $wpdb->query("INSERT INTO $t (block, var, tag, pfad, n) VALUES " . implode(', ', $zeilen) . " ON DUPLICATE KEY UPDATE n = n + 1"); }   // eine Abfrage für alle Tests der Seite
    }

    // ---------- Eigene Klickmessung (ohne Cookies, ohne IP, ohne Besucher-Kennung) ----------

    private static function klicktabelle(): string { global $wpdb; return $wpdb->prefix . 'sab_klicks'; }

    public static function tabelle(): void {
        global $wpdb;
        if (!isset($wpdb) || get_option('sab_db_version') === '7') { return; }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $t = self::klicktabelle();
        dbDelta("CREATE TABLE $t (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ts DATETIME NOT NULL,
            kid CHAR(8) NOT NULL,
            asin CHAR(10) NOT NULL,
            slot VARCHAR(40) NOT NULL DEFAULT '',
            typ VARCHAR(5) NOT NULL DEFAULT '',
            var CHAR(1) NOT NULL DEFAULT '',
            pfad VARCHAR(190) NOT NULL DEFAULT '',
            anker VARCHAR(80) NOT NULL DEFAULT '',
            ziel VARCHAR(10) NOT NULL DEFAULT '',
            herkunft CHAR(2) NOT NULL DEFAULT '',
            ziel_land VARCHAR(6) NOT NULL DEFAULT '',
            ziel_asin CHAR(10) NOT NULL DEFAULT '',
            zurueck_s INT UNSIGNED NULL,
            PRIMARY KEY (id),
            KEY asin (asin),
            KEY kid (kid)
        ) {$wpdb->get_charset_collate()};");
        $ta = self::ab_tabelle();
        dbDelta("CREATE TABLE $ta (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            block CHAR(8) NOT NULL,
            var CHAR(1) NOT NULL,
            tag DATE NOT NULL,
            pfad VARCHAR(190) NOT NULL DEFAULT '',
            n INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY zaehler (block, var, tag, pfad)
        ) {$wpdb->get_charset_collate()};");
        $tp = self::seiten_tabelle();
        dbDelta("CREATE TABLE $tp (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tag DATE NOT NULL,
            pfad VARCHAR(190) NOT NULL DEFAULT '',
            n INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY zaehler (tag, pfad)
        ) {$wpdb->get_charset_collate()};");
        update_option('sab_db_version', '7', false);
    }

    public static function rest(): void {
        register_rest_route('sab/v1', '/m', [
            'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => [__CLASS__, 'messen'],
        ]);
    }

    /** Nimmt Klick (asin, typ, ...) oder Rückkehr (kid, s) entgegen. Antwortet immer leer. */
    /** Gehört der Besucher zu einem angemeldeten Redakteur oder Admin? Auch bei zwischengespeicherten Seiten, denn das Anmelde-Cookie kommt mit der Messung mit. */
    private static function ist_redaktion(): bool {
        if (function_exists('is_user_logged_in') && is_user_logged_in() && current_user_can('edit_posts')) { return true; }
        if (function_exists('wp_validate_auth_cookie') && function_exists('user_can')) {
            $uid = (int) wp_validate_auth_cookie('', 'logged_in');
            if ($uid > 0 && user_can($uid, 'edit_posts')) { return true; }
        }
        return false;
    }

    public static function messen($req) {
        global $wpdb;
        if (self::ist_redaktion()) { return new WP_REST_Response(null, 204); }   // eigene Klicks verzerren die Statistik
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        if (!isset($wpdb) || $ua === '' || preg_match('/bot|crawl|spider|slurp|headless/i', $ua)) { return new WP_REST_Response(null, 204); }
        $d = json_decode((string) $req->get_body(), true);
        if (!is_array($d) || !preg_match('/^[a-z0-9]{6,8}$/', (string) ($d['id'] ?? ''))) { return new WP_REST_Response(null, 204); }
        $t = self::klicktabelle();
        if (isset($d['ab']) && is_array($d['ab'])) {
            self::ab_ansichten_zaehlen($d['ab'], (string) ($d['pfad'] ?? ''));
        }
        if (isset($d['pv'])) {   // Seitenaufruf mit Produkten: nur Summe je Seite und Tag, ohne Besucherkennung
            $wpdb->query($wpdb->prepare("INSERT INTO " . self::seiten_tabelle() . " (tag, pfad, n) VALUES (%s, %s, 1) ON DUPLICATE KEY UPDATE n = n + 1", gmdate('Y-m-d'), substr((string) wp_parse_url((string) ($d['pfad'] ?? ''), PHP_URL_PATH), 0, 190)));
        } elseif (isset($d['s'])) {
            $sek = (int) $d['s'];
            if ($sek > 0 && $sek <= 3 * HOUR_IN_SECONDS) {
                $wpdb->query($wpdb->prepare("UPDATE $t SET zurueck_s=%d WHERE kid=%s AND zurueck_s IS NULL", $sek, $d['id']));
            }
        } else {
            $asin = self::asin((string) ($d['asin'] ?? ''));
            if (strlen($asin) === 10 && ($asin === 'STARTSEITE' || in_array($asin, self::alle_asins(), true))) {
                $typ = in_array($d['typ'] ?? '', ['bild', 'box', 'text'], true) ? $d['typ'] : 'text';
                $wpdb->insert($t, [
                    'ts' => gmdate('Y-m-d H:i:s'), 'kid' => $d['id'], 'asin' => $asin,
                    'slot' => substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($d['slot'] ?? '')), 0, 40),
                    'var' => substr(preg_replace('/[^A-D]/', '', (string) ($d['var'] ?? '')), 0, 1),
                    'typ' => $typ, 'pfad' => substr((string) wp_parse_url((string) ($d['pfad'] ?? ''), PHP_URL_PATH), 0, 190),
                    'anker' => substr(preg_replace('/[^A-Za-z0-9_.:-]/', '', (string) ($d['anker'] ?? '')), 0, 80),
                    'herkunft' => preg_match('/^[A-Z]{2}$/', (string) ($d['h'] ?? '')) ? (string) $d['h'] : '',   // Land des Besuchers, nur aus Zeitzone/Browsersprache (keine IP, kein Cookie)
                    'ziel_land' => preg_match('/^(?:de|es|fr|it|co\.uk|com|ca|com\.au)$/', (string) ($d['zl'] ?? '')) ? (string) $d['zl'] : '',   // Amazon-Land, in das der Klick wirklich ging
                    'ziel_asin' => preg_match('/^[A-Z0-9]{10}$/', (string) ($d['za'] ?? '')) ? (string) $d['za'] : '',   // tatsächlich ausgelieferte ASIN (leer bei Suchlink)
                    'ziel' => preg_match('/^(com|ca|com\.au):[ash]$/', (string) ($d['z'] ?? '')) ? (string) $d['z'] : '',   // Länder-Umschaltung: Zielstore und a = ASIN im Land, s = Suche
                ]);
            }
        }
        return new WP_REST_Response(null, 204);
    }

    /** Kleines Skript, nur auf Seiten mit Produkten. Misst Klick und die Zeit bis zur Rückkehr in den Tab. */
    public static function messskript(): void {
        if (function_exists('is_user_logged_in') && is_user_logged_in() && current_user_can('edit_posts')) { return; }
        if (!self::$genutzt) {   // Seite ohne Produkte: nur Aufruf zählen, wenn das Widget „Beliebte Artikel“ im Einsatz ist
            if (get_option('sab_beliebt_an') !== '1' || !function_exists('is_singular') || !is_singular()) { return; }
            echo '<script>(function(){function Z(){try{navigator.sendBeacon(' . wp_json_encode(esc_url_raw(rest_url('sab/v1/m'))) . ',new Blob([JSON.stringify({id:Math.random().toString(36).slice(2,10),pv:1,pfad:location.pathname})],{type:"text/plain"}))}catch(e){}}'
               . 'function W(){(window.requestIdleCallback||function(f){setTimeout(f,1500)})(Z)}if(document.readyState==="complete"){W()}else{addEventListener("load",W)}})();</script>';
            return;
        }   // angemeldete Redakteure werden nicht gemessen
        $url = esc_url_raw(rest_url('sab/v1/m'));
        echo '<script>(function(){var U=' . wp_json_encode($url) . ',p=null;'
           . 'function S(o){try{navigator.sendBeacon(U,new Blob([JSON.stringify(o)],{type:"text/plain"}))}catch(e){}}'
           . 'function L(){var t="",l=(navigator.languages&&navigator.languages[0])||navigator.language||"",x=l.split("-")[1]||"";try{t=Intl.DateTimeFormat().resolvedOptions().timeZone||""}catch(e){}'
           . 'var m={"Europe/Berlin":"DE","Europe/Vienna":"AT","Europe/Zurich":"CH","Europe/Madrid":"ES","Atlantic/Canary":"ES","Europe/Paris":"FR","Europe/Rome":"IT","Europe/London":"GB","Europe/Dublin":"IE","Europe/Amsterdam":"NL","Europe/Brussels":"BE","Europe/Luxembourg":"LU","Europe/Lisbon":"PT","Europe/Warsaw":"PL","Europe/Prague":"CZ","Europe/Stockholm":"SE","Europe/Copenhagen":"DK","Europe/Oslo":"NO","Europe/Helsinki":"FI","Europe/Budapest":"HU","Europe/Athens":"GR","America/New_York":"US","America/Chicago":"US","America/Denver":"US","America/Los_Angeles":"US","America/Toronto":"CA","America/Vancouver":"CA","America/Mexico_City":"MX","America/Sao_Paulo":"BR","America/Argentina/Buenos_Aires":"AR","America/Bogota":"CO","America/Santiago":"CL","America/Lima":"PE","Australia/Sydney":"AU","Australia/Melbourne":"AU","Australia/Brisbane":"AU","Australia/Perth":"AU","Pacific/Auckland":"NZ"};'
           . 'return m[t]||(/^[A-Za-z]{2}$/.test(x)?x.toUpperCase():"")}'
           . 'function H(a){var r="",h=document.querySelectorAll("h2[id],h3[id],h4[id]"),i;for(i=0;i<h.length;i++){if(h[i].compareDocumentPosition(a)&4)r=h[i].id}return r}'
           . 'function Z(){var o={id:Math.random().toString(36).slice(2,10),pv:1,pfad:location.pathname};if(window.sabAB&&window.sabAB.length){o.ab=window.sabAB}S(o)}'
           . 'function W(){(window.requestIdleCallback||function(f){setTimeout(f,1500)})(Z)}'
           . 'if(document.readyState==="complete"){W()}else{addEventListener("load",W)}'
           . 'document.addEventListener("click",function(e){var a=e.target.closest&&e.target.closest("a[data-sab-asin]");if(!a)return;'
           . 'var id=Math.random().toString(36).slice(2,10),c=a.classList;'
           . 'var X=a.closest("[data-sab-xs]");S({id:id,asin:a.getAttribute("data-sab-asin"),slot:a.getAttribute("data-sab-slot")||(X?X.getAttribute("data-sab-xs"):""),var:a.getAttribute("data-sab-var")||(X?X.getAttribute("data-sab-xv"):""),typ:(e.target.closest&&e.target.closest(".sab-karte-btn"))?"text":c.contains("sab-bild")?"bild":c.contains("sab-link")?"box":"text",pfad:location.pathname,anker:H(a),z:a.getAttribute("data-sab-z")||"",h:L(),zl:(a.href.match(/amazon\.(co\.uk|com\.au|com|ca|de|fr|it|es)\//i)||[0,""])[1].toLowerCase(),za:((a.href.match(/\/(?:dp|gp\/product)\/([A-Za-z0-9]{10})/)||[0,""])[1]).toUpperCase()});'
           . 'p={id:id,t:Date.now(),h:false}},true);'
           . 'document.addEventListener("visibilitychange",function(){if(!p)return;'
           . 'if(document.visibilityState==="hidden"){p.h=true;return}'
           . 'if(p.h){S({id:p.id,s:Math.round((Date.now()-p.t)/1000)});p=null}});})();</script>';
    }

    /** Kennzahlen je ASIN: Klicks gesamt, Bild/Text, Rückkehr, mittlere Zeit bis zur Rückkehr. */
    private static function klickzahlen(): array {
        global $wpdb;
        if (!isset($wpdb)) { return []; }
        $t = self::klicktabelle();
        $out = [];
        foreach ((array) $wpdb->get_results("SELECT asin, COUNT(*) n, SUM(typ='bild') b, SUM(typ<>'bild') x, SUM(zurueck_s IS NOT NULL) z, AVG(zurueck_s) m FROM $t GROUP BY asin", ARRAY_A) as $r) {
            $out[(string) ($r['asin'] ?? '')] = $r;
        }
        return $out;
    }

    /** Klicks je Test-Variante */
    private static function testzahlen(): array {
        global $wpdb;
        if (!isset($wpdb)) { return []; }
        $t = self::klicktabelle();
        return (array) $wpdb->get_results("SELECT slot, var, COUNT(*) n, SUM(zurueck_s IS NOT NULL) z FROM $t WHERE var<>'' GROUP BY slot, var ORDER BY slot, var", ARRAY_A);
    }

    // ---------- Übersicht (nur Verwaltung, eigene Abfrage, beeinflusst die Seitenausgabe nicht) ----------

    /** Alle bekannten ASINs: Produktliste, Ersatzlisten und ASINs aus Shortcodes in veröffentlichten Beiträgen. */
    /** ASINs, die in diesem Artikel NUR als Textlink direkt unter dem Bild eines anderen Produkts stehen (dasselbe Produktfeld, anderes Produkt): Das Bild bestimmt das Produkt, ein kaputter Textlink löst keinen Austausch aus. */
    public static function nur_textlink_neben_bild(string $c): array {
        $bild = []; $text = [];
        if (preg_match_all('#\[amz_bild\b[^\]]*\basin="([A-Za-z0-9]{10})"#i', $c, $m, PREG_OFFSET_CAPTURE)) { foreach ($m[1] as [$a, $o]) { $bild[] = [$o, strtoupper($a)]; } }
        if (preg_match_all('#<a\b[^>]*(?:dp|gp/product)/([A-Za-z0-9]{10})[^>]*>\s*<img#i', $c, $m, PREG_OFFSET_CAPTURE)) { foreach ($m[1] as [$a, $o]) { $bild[] = [$o, strtoupper($a)]; } }
        if (preg_match_all('#\[amz_link\b[^\]]*\basin="([A-Za-z0-9]{10})"#i', $c, $m, PREG_OFFSET_CAPTURE)) { foreach ($m[1] as [$a, $o]) { $text[] = [$o, strtoupper($a)]; } }
        if (preg_match_all('#<a\b[^>]*(?:dp|gp/product)/([A-Za-z0-9]{10})[^>]*>(?!\s*<img)#i', $c, $m, PREG_OFFSET_CAPTURE)) { foreach ($m[1] as [$a, $o]) { $text[] = [$o, strtoupper($a)]; } }
        if (!$bild || !$text) { return []; }
        usort($bild, fn($x, $y) => $x[0] <=> $y[0]);
        $bildAsins = array_column($bild, 1); $nurText = []; $auchBild = [];
        foreach ($text as [$o, $a]) {
            if (in_array($a, $bildAsins, true)) { continue; }
            $vor = null;
            foreach ($bild as [$bo, $ba]) { if ($bo < $o) { $vor = [$bo, $ba]; } else { break; } }
            if (!$vor || $o - $vor[0] > 1200) { continue; }
            if (strripos(substr($c, $vor[0], $o - $vor[0]), '<hr') !== false) { continue; }   // anderer Abschnitt
            $nurText[$a] = ($nurText[$a] ?? 0) + 1;
        }
        return array_keys($nurText);
    }

    private static function alle_asins(): array {
        $c = get_transient('sab_alle_asins');
        if (is_array($c)) { return $c; }
        $alle = array_keys(self::produkte());
        foreach (self::ab_optionen() as $o_) { foreach (['a', 'b', 'c'] as $l_) { foreach ((array) ($o_[$l_] ?? []) as $x_) { $alle[] = (string) $x_; } } }   // A/B-Test: beide Seiten, damit Klicks gezählt werden
        foreach (self::slots() as $liste) { $alle = array_merge($alle, $liste); }
        foreach (self::tests() as $liste) { $alle = array_merge($alle, $liste); }
        $alle = array_merge($alle, array_values(self::tausch_regeln()));
        foreach (self::gruppen() as $liste) { $alle = array_merge($alle, $liste); }
        foreach (self::amz_alternativen() as $l) { $alle = array_merge($alle, $l['liste']); }   // Amazons Alternativen mit abfragen (Score)
        foreach (self::such_kandidaten() as $l) { $alle = array_merge($alle, $l['liste']); }
        foreach (self::auch_gekauft() as $l) { $alle = array_merge($alle, $l['liste']); }   // Mitgekauftes mit abfragen (Score)
        foreach (self::claude_empfehlungen() as $l) { $alle[] = $l[0]; }
        foreach (self::ersatz_liste() as $e) { $alle[] = $e['asin']; }   // Ersatz-Datenbank mit abfragen (Score)
        global $wpdb;
        if (isset($wpdb) && method_exists($wpdb, 'get_col')) {
            $host = preg_quote(preg_replace('#^www\.#', '', self::LAENDER[self::land('')][0]), '#');   // nur Links ins Land dieser Webseite, sonst sähen Produkte anderer Länder „unbekannt“ aus
            $rows = $wpdb->get_col("SELECT post_content FROM {$wpdb->posts} WHERE post_status='publish' AND post_type IN ('post','page') AND (post_content LIKE '%[amz\\_%' OR post_content LIKE '%[aawp%' OR post_content LIKE '%[amazon %' OR post_content LIKE '%amazon.%')");
            foreach ((array) $rows as $c) {
                $tx = self::nur_textlink_neben_bild((string) $c); $vor_n = count($alle);
                if (preg_match_all('#https?://(?:www\.)?' . $host . '/(?:[^"\'\s<>]*/)?(?:dp|gp/product)/([A-Za-z0-9]{10})#', $c, $m)) { $alle = array_merge($alle, array_map('strtoupper', $m[1])); }   // feste Links
                // alle Plugin-Shortcodes mit asin="..." (bild, link, produkt) und die AAWP-Zeilen (fields/box/link, mehrere ASINs mit Komma)
                if (preg_match_all('/\[amz_(?:bild|link|produkt|karte|titel|hinweis|rezension)\b[^\]]*?\basin=["\']([A-Za-z0-9]{10})["\']/', $c, $m)) { $alle = array_merge($alle, array_map([__CLASS__, 'asin'], $m[1])); }   // auch Karten ([amz_karte]) und Titel, einfache Anführungszeichen
                if (preg_match_all('/\[(?:aawp|amazon)\b[^\]]*\b(?:fields|box|link)=["\']([^"\']+)["\']/', $c, $m)) {
                    foreach ($m[1] as $liste) { foreach (explode(',', $liste) as $a) { $a = substr(self::asin($a), 0, 10); if (strlen($a) === 10) { $alle[] = $a; } } }
                }
                if ($tx) { $alle = array_merge(array_slice($alle, 0, $vor_n), array_diff(array_slice($alle, $vor_n), $tx)); }   // Textlink-Reste neben einem funktionierenden Bild lösen keinen Austausch aus
            }
        }
        $alle = array_map('strval', array_values(array_unique(array_filter($alle))));   // reine Ziffern (Bücher) werden von PHP zu Zahlen: Amazon lehnt sie dann als ungültig ab
        set_transient('sab_alle_asins', $alle, 10 * MINUTE_IN_SECONDS);
        return $alle;
    }

    /** Holt Details für die Übersicht (höchstens 1 Stunde zwischengespeichert, Amazon-Regel für Angebote). */
    /**
     * Daten für Übersicht, Problemprodukte und Umbau. Es wird immer der letzte Stand sofort gezeigt; ist er älter als eine Stunde oder wird
     * „Neu laden“ geklickt, holt ein Hintergrundauftrag (WP-Cron) die neuen Daten. Nur beim allerersten Mal wartet die Seite auf Amazon.
     */
    /** Fehlen im letzten Abruf Produkte, die inzwischen in Artikeln stehen (z. B. nach einem Tausch)? Dann einmal frisch holen, höchstens alle 10 Minuten. */
    private static function stand_luecke(string $land): bool {
        $st = get_option("sab_uebersicht_stand_{$land}");
        if (!is_array($st) || empty($st['items']) || get_transient('sab_luecke_' . $land)) { return false; }
        foreach (self::alle_asins() as $a) {
            if (!isset($st['items'][$a])) { set_transient('sab_luecke_' . $land, 1, 10 * MINUTE_IN_SECONDS); return true; }
        }
        return false;
    }

    private static function uebersicht_daten(string $land, bool $frisch = false): array {
        if (!$frisch && !defined('SAB_SYNC') && self::stand_luecke($land)) { $frisch = true; }
        return self::mit_verlauf(self::mit_preisindex(self::mit_kat_schaetzung(self::mit_manuell(self::uebersicht_daten_roh($land, $frisch)))));
    }

    private static function uebersicht_daten_roh(string $land, bool $frisch = false): array {
        $key = "sab_uebersicht_{$land}";
        $c = get_transient($key);
        if (is_array($c) && !$frisch) { return $c; }
        $stand = get_option("sab_uebersicht_stand_{$land}");
        if (is_array($stand) && !empty($stand['items']) && !defined('SAB_SYNC')) {
            self::im_hintergrund('sab_uebersicht_holen', [$land]);
            $stand['im_hintergrund'] = true;
            return $stand;
        }
        return self::uebersicht_holen($land);
    }

    /** Hintergrundauftrag: neue Daten bei Amazon holen und als Stand speichern. */
    public static function uebersicht_holen($land): array {
        $land = (string) $land;
        if (!isset(self::LAENDER[$land])) { return ['fehler' => 'Land unbekannt.', 'items' => []]; }
        $key = "sab_uebersicht_{$land}";
        delete_transient($key);
        if (function_exists('set_time_limit') && !defined('SAB_SYNC')) { @set_time_limit(300); }   // 380 Produkte = 38 Anfragen im Sekundentakt: das braucht länger als die üblichen 30 Sekunden
        $token = self::token($land); $tag = self::tag($land);
        if (!$token || !$tag) { return ['fehler' => self::zugang_fehler($land, (bool) $token) ?: 'Zugangsdaten oder Partner-ID fehlen.', 'items' => []]; }
        $mp = self::LAENDER[$land][0]; $items = []; $fehler = '';
        $ressourcen = ['images.primary.large', 'images.variants.large', 'itemInfo.title',
                        'offersV2.listings.availability', 'offersV2.listings.price', 'offersV2.listings.isBuyBoxWinner',
                        'offersV2.listings.merchantInfo', 'offersV2.listings.dealDetails', 'offersV2.listings.type',
                        'browseNodeInfo.browseNodes', 'browseNodeInfo.websiteSalesRank'];
        $abfrage = function (array $paket) use ($token, $mp, $tag, $ressourcen) {
            return self::api_post([
                'timeout' => 15,
                'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json', 'x-marketplace' => $mp],
                'body'    => wp_json_encode(['itemIds' => array_map('strval', $paket), 'itemIdType' => 'ASIN', 'marketplace' => $mp, 'partnerTag' => $tag, 'resources' => $ressourcen]),
            ]);
        };
        $ok_pakete = 0; $ungueltig = []; $unsicher = [];   // ungültig = Amazon weist die ASIN eindeutig ab; unsicher = Abruf gescheitert oder ohne Antwort (nie als „Amazon kennt es nicht“ werten)
        $alt_stand = get_option("sab_uebersicht_stand_{$land}"); $alt_items = is_array($alt_stand) ? (array) ($alt_stand['items'] ?? []) : [];
        $code_von = fn($r) => is_wp_error($r) ? 0 : (int) wp_remote_retrieve_response_code($r);
        foreach (array_chunk(self::alle_asins(), 10) as $paket) {
            $r = $abfrage($paket); $code = $code_von($r);
            $antworten = [];
            if ($code === 200) { $antworten[] = json_decode(wp_remote_retrieve_body($r), true); }
            elseif (count($paket) > 1 && in_array($code, [400, 404, 422], true)) {
                // ein ungültiger Eintrag lässt das ganze Paket scheitern: einzeln nachfragen, damit der Rest nicht verloren geht
                foreach ($paket as $einzeln) {
                    $r1 = $abfrage([$einzeln]); $c1 = $code_von($r1);
                    if ($c1 === 200) { $antworten[] = json_decode(wp_remote_retrieve_body($r1), true); }
                    elseif (in_array($c1, [400, 404, 422], true)) { $ungueltig[] = (string) $einzeln; }
                    else { $unsicher[] = (string) $einzeln; $fehler = 'Amazon-Abfrage fehlgeschlagen (Code ' . ($c1 ?: 'Netz') . ')'; }
                }
                if (!$antworten) { continue; }
            } else {
                foreach ($paket as $a_) { $unsicher[] = (string) $a_; }
                $fehler = 'Amazon-Abfrage fehlgeschlagen (Code ' . ($code ?: 'Netz') . '): ' . (is_wp_error($r) ? $r->get_error_message() : mb_substr((string) wp_remote_retrieve_body($r), 0, 900));
                continue;
            }
            $ok_pakete++;
            foreach ($antworten as $j) {
            foreach ((array) ($j['itemsResult']['items'] ?? []) as $it) {
                $l = self::bestes_angebot($it);
                $bilder = [];
                if (!empty($it['images']['primary']['large']['url'])) { $bilder[] = $it['images']['primary']['large']['url']; }
                foreach ((array) ($it['images']['variants'] ?? []) as $v) { if (!empty($v['large']['url'])) { $bilder[] = $v['large']['url']; } }
                $items[self::asin($it['asin'] ?? '')] = [
                    'titel'    => $it['itemInfo']['title']['displayValue'] ?? '',
                    'bilder'   => $bilder,
                    'lieferbar'=> (bool) $l,
                    'status'   => (string) ($l['availability']['type'] ?? ''),
                    'meldung'  => (string) ($l['availability']['message'] ?? ''),
                    'kaufen'   => $l ? (isset($l['isBuyBoxWinner']) ? (bool) $l['isBuyBoxWinner'] : null) : false,
                    'preis'    => self::preis_von($l),
                    'verkaeufer' => self::verkaeufer_von($l),
                    'diag'     => self::diagnose($it),
                    'spar'     => self::spar_von($l),
                    'deal'     => self::deal_von($it),
                    'kategorie' => self::rang_von($it)[0], 'rang' => self::rang_von($it)[1],
                    'roh'      => mb_substr((string) wp_json_encode($it['offersV2'] ?? null), 0, 1800),
                ];
            }
            // Fehlermeldungen innerhalb einer 200er-Antwort: nur „ungültig/nicht zugänglich“ gilt als eindeutig, alles andere (Drosselung, Störung) als unsicher
            foreach ((array) ($j['errors'] ?? []) as $er) {
                $ec = (string) ($er['code'] ?? '');
                if (preg_match_all('/\b[0-9A-Z]{10}\b/', (string) ($er['message'] ?? ''), $am)) {
                    foreach ($am[0] as $a_) { if (in_array($a_, array_map('strval', $paket), true) && !isset($items[$a_])) { if (preg_match('/Invalid|NotAccessible|NoResults|NotFound|NotExist/i', $ec)) { $ungueltig[] = $a_; } else { $unsicher[] = $a_; } } }
                }
            }
            }
            foreach ($paket as $a_) { $a_ = (string) $a_; if (!isset($items[$a_]) && !in_array($a_, $ungueltig, true) && !in_array($a_, $unsicher, true)) { $unsicher[] = $a_; } }   // keine Antwort zu diesem Produkt: unsicher, nicht „unbekannt“
        }
        $unsicher = array_values(array_unique($unsicher)); $ungueltig = array_values(array_unique($ungueltig));
        foreach ($unsicher as $a_) { if (isset($alt_items[$a_])) { $items[$a_] = $alt_items[$a_]; $items[$a_]['veraltet'] = (int) ($alt_stand['ts'] ?? 0); } }   // letzten bekannten Stand behalten
        update_option('sab_ungueltige_asins', $ungueltig, false);
        update_option('sab_unsicher_asins', $unsicher, false);
        $teil = '';
        if ($fehler !== '' && $ok_pakete > 0) { $teil = $fehler; $fehler = ''; }
        if ($unsicher && $teil === '' && $ok_pakete > 0) { $teil = count($unsicher) . ' Produkt(e) lieferte Amazon beim letzten Abruf keine Antwort (Drosselung oder Störung). Der letzte bekannte Stand bleibt stehen.'; }
        if ($teil !== '') { update_option('sab_uebersicht_teilfehler', $teil . ($unsicher ? ' Neuer Versuch in 5 Minuten.' : ''), false); } else { delete_option('sab_uebersicht_teilfehler'); }
        if ($unsicher && function_exists('wp_schedule_single_event') && !wp_next_scheduled('sab_uebersicht_holen', [$land])) { wp_schedule_single_event(time() + 300, 'sab_uebersicht_holen', [$land]); }
        // Bewertungen liefert diese Schnittstelle nicht (Antwort immer ohne Werte): keine Abfrage mehr, das spart Abfragen (Limit 1 pro Sekunde)
        $bew = ['fehler' => '', 'werte' => []];
        $out = ['fehler' => $fehler, 'items' => $items, 'bewertungen' => $bew];
        $out['ts'] = time();
        if (!$fehler) { self::bildfehler_aus_stand($items, $ungueltig); set_transient($key, $out, HOUR_IN_SECONDS); update_option("sab_uebersicht_stand_{$land}", $out, false); self::mit_verlauf(self::mit_preisindex(self::mit_kat_schaetzung(self::mit_manuell($out))), true); }
        return $out;
    }

    /** Reiter „Score“: wie der Score berechnet wird, Gewichte ändern, Rechenbeispiele mit echten Produkten. */
    /** Ganz oben im Reiter Score: wie die Priorität (Reihenfolge in Übersicht und Austausch) entsteht, mit echtem Beispiel, Live-Rechner und einstellbarer Dringlichkeit. */
    private static function prio_erklaerung_html(array $w): string {
        $land = self::land(''); $d = self::uebersicht_daten($land, false); $q = self::dringlichkeit_werte();
        $bsp = null;
        foreach ((array) ($d['items'] ?? []) as $asin => $x) {
            $x['trotz'] = false; [$sc, , $det] = self::score($x); $dr = self::tausch_dringlichkeit($x, $sc, self::braucht_bild((string) $asin));
            if ($dr > 0 && $dr < 100 && (!$bsp || $dr > $bsp['dr'])) { $bsp = ['asin' => (string) $asin, 'x' => $x, 'sc' => $sc, 'det' => $det, 'dr' => $dr]; }
        }
        $h = '<div style="background:#fff;border:2px solid #2271b1;border-radius:8px;padding:14px 18px;margin:10px 0 20px;max-width:1000px"><h2 style="margin-top:0">So entsteht die Priorität (Reihenfolge in Übersicht und Austausch)</h2>'
           . '<p style="font-size:15px"><strong>Priorität = Dringlichkeit × Zahl der Seiten, auf denen das Produkt steht.</strong> Wer oben steht, wird zuerst getauscht.</p>'
           . '<ol><li><strong>Dringlichkeit (0 bis 100):</strong> Nicht lieferbar, ohne Preis oder Status unklar = 100. Sonst gilt der Score: Liegt er unter <strong>' . (int) $q['schwelle'] . '</strong>, wächst die Dringlichkeit um <strong>' . str_replace('.', ',', (string) $q['faktor']) . '</strong> Punkte je fehlendem Score-Punkt. Dazu kommen Untergrenzen: Lieferzeit über <strong>' . str_replace('.', ',', (string) $q['tage']) . '</strong> Tage = mindestens <strong>' . (int) $q['tage_dr'] . '</strong>, Sterne unter <strong>' . str_replace('.', ',', (string) $q['sterne']) . '</strong> = mindestens <strong>' . (int) $q['sterne_dr'] . '</strong>.</li>'
           . '<li><strong>Score (0 bis 100):</strong> gewichtete Summe aus Rang, Buy Box, Angebot, Preis, Streichpreis, Aktion, Bewertung, Konstanz, Prime, Lieferzeit und Provision. <a href="#sab-gewichte">Die Gewichte stellst du weiter unten ein.</a></li>'
           . '<li><strong>Seiten:</strong> Wie oft das Produkt in veröffentlichten Artikeln vorkommt. Ein Produkt auf 20 Seiten ist 20-mal wichtiger als eines auf einer Seite.</li></ol>';
        if ($bsp) {
            $ns = count(self::fundstellen([$bsp['asin']])[$bsp['asin']] ?? []); $nm = (self::produkte()[$bsp['asin']] ?? '') ?: self::kurzname_aus_titel((string) ($bsp['x']['titel'] ?? ''));
            $faktoren = array_map(fn($z) => ['n' => $z[0], 'f' => (float) $z[2]], $bsp['det']);
            $bonus = (int) ($bsp['x']['bonus'] ?? 0);
            $h .= '<h3>Beispiel mit einem echten Produkt: ' . esc_html($nm ?: $bsp['asin']) . ' <small>(' . esc_html($bsp['asin']) . ')</small></h3>'
               . '<p>Score <strong id="sab-bsp-score">' . (int) $bsp['sc'] . '</strong> → Dringlichkeit <strong id="sab-bsp-dr">' . (int) $bsp['dr'] . '</strong> (' . (int) $q['schwelle'] . ' − Score) × ' . str_replace('.', ',', (string) $q['faktor']) . ' · steht auf <strong>' . $ns . '</strong> Seite(n) → <strong>Priorität <span id="sab-bsp-prio">' . ($bsp['dr'] * $ns) . '</span></strong>.</p>'
               . '<p><strong>Live-Rechner:</strong> Ändere hier ein Gewicht, und du siehst sofort, wie sich der Score dieses Produkts und seine Priorität ändern. Gespeichert wird erst weiter unten mit „Gewichte speichern“.</p><div style="display:flex;flex-wrap:wrap;gap:8px 16px">';
            foreach (self::SCORE_NAMEN as $k => $name) {
                $h .= '<label style="width:210px;font-size:12px">' . esc_html($name) . ' <input type="number" min="0" class="sab-lw" data-k="' . esc_attr($k) . '" value="' . (int) ($w[$k] ?? 0) . '" style="width:70px"></label>';
            }
            $keys = array_keys(self::SCORE_STANDARD);
            $fj = [];
            foreach ($bsp['det'] as $i => $z) { $fj[$keys[$i] ?? $i] = (float) $z[2]; }
            $h .= '</div><script>(function(){var F=' . wp_json_encode($fj) . ',B=' . $bonus . ',NS=' . max(1, $ns) . ',SCH=' . (float) $q['schwelle'] . ',FAK=' . (float) $q['faktor'] . ';var els=document.querySelectorAll(".sab-lw");function rechne(){var sum=0,roh=0;els.forEach(function(e){var k=e.getAttribute("data-k"),g=Math.max(0,+e.value||0);if(F.hasOwnProperty(k)){sum+=g;roh+=g*F[k]}else{sum+=g}});'
               . 'var r=sum>0?100*roh/sum:0,sc=Math.round(50+(r-45)*2.2)+B;sc=Math.max(-10,Math.min(110,sc));var dr=sc<SCH?Math.round((SCH-sc)*FAK):0;dr=Math.min(100,dr);document.getElementById("sab-bsp-score").textContent=sc;document.getElementById("sab-bsp-dr").textContent=dr;document.getElementById("sab-bsp-prio").textContent=dr*NS}'
               . 'els.forEach(function(e){e.addEventListener("input",rechne)});})();</script><p class="description">Der Rechner nutzt die Faktoren dieses Produkts von heute (ohne Konstanz, falls sie fehlt) und gilt ungefähr genauso wie die echte Rechnung. Er zeigt nur die Wirkung.</p>';
        } else { $h .= '<p><em>Zurzeit gibt es kein Produkt mit mittlerer Dringlichkeit für ein Beispiel.</em></p>'; }
        $h .= '<h3>Dringlichkeit einstellen</h3><form method="post">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="score"><input type="hidden" name="sab_form" value="dringlichkeit"><div style="display:flex;flex-wrap:wrap;gap:8px 18px">';
        $lab = ['schwelle' => 'Score-Schwelle', 'faktor' => 'Punkte je fehlendem Score-Punkt', 'tage' => 'Lieferzeit ab (Tage)', 'tage_dr' => 'dann Dringlichkeit mindestens', 'sterne' => 'Sterne unter', 'sterne_dr' => 'dann Dringlichkeit mindestens'];
        foreach ($lab as $k => $t) { $h .= '<label style="font-size:12px">' . esc_html($t) . '<br><input type="text" name="sab_dr[' . esc_attr($k) . ']" value="' . esc_attr(str_replace('.', ',', (string) $q[$k])) . '" size="6"></label>'; }
        $h .= '</div><p><button class="button button-primary">Dringlichkeit speichern</button> <button class="button" name="sab_dr_std" value="1">Standard</button></p></form></div>';
        return $h;
    }

    private static function score_html(): string {
        $land = self::land(''); $w = self::score_gewichte(); $summe = array_sum($w);
        $h = self::prio_erklaerung_html($w) . '<h2 id="sab-gewichte">Score: so wird gerechnet</h2><p>Der Score (0 bis 100) vergleicht ähnliche Produkte. Er sagt nicht, wie gut ein Produkt ist, sondern wie gut es sich nach den Amazon-Daten verkauft und wie verlässlich es lieferbar ist. Jeder Posten bekommt einen Faktor von 0 (schlecht) bis 1 (sehr gut) und wird mit seinem Gewicht multipliziert. Die Summe wird auf 100 umgerechnet, damit die Gewichte keine feste Summe brauchen.</p>'
           . '<form method="post">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="score"><input type="hidden" name="sab_form" value="score">'
           . '<table class="widefat striped" style="max-width:860px"><thead><tr><th>Posten</th><th style="width:110px">Gewicht</th><th>Wie der Faktor entsteht</th></tr></thead><tbody>';
        $erkl = [
            'rang'    => '1 bei Platz 100 oder besser, 0 ab Platz 500.000, dazwischen gleichmäßig auf der Zehnerlogarithmus-Skala (Platz 10.000 ist deutlich besser als 100.000). Mit bekannter Kategoriegröße zählt der Anteil (Platz geteilt durch Zahl der Produkte), sonst der Platz selbst; in Nicht-Garten-Kategorien ohne bekannte Größe zu 70 %. Ohne Rang ist der Score auf 60 begrenzt.',
            'buybox'  => '1, wenn Amazon einen Kaufen-Button (Buy Box) für dieses Produkt vergibt, sonst 0.',
            'angebot' => '1 lieferbar (oder von dir als lieferbar bestätigt), 0,7 wenige Stück, 0,5 eingeschränkt, 0 kein Angebot.',
            'preis'   => 'Preis geteilt durch den mittleren Preis der Produktgruppe (sonst der Kategorie, mindestens drei Produkte): 1 bei 70 % oder weniger, 0 bei 150 % oder mehr. Ohne Vergleich 0,5.',
            'streich' => '1, wenn Amazon einen durchgestrichenen Preis (Ersparnis) zeigt.',
            'deal'    => '1 bei einer Aktion, einem Coupon oder einem Deal.',
            'bewertung' => 'Sterne und Anzahl von Hand eingetragen (unten). Wenige Bewertungen zählen vorsichtig: 4,0 Sterne ist der Mittelwert. 3 Sterne ergibt 0, 5 Sterne 1. Ohne Angabe 0,5.',
            'konstanz' => 'Das Plugin hält jeden Tag den Score (ohne diesen Posten) fest. Zählt der Anteil der Tage mit Score ab 60 in den letzten 30 Tagen: 100 % ergibt 1, 0 % ergibt 0. Ein Produkt, das nur an einem guten Tag glänzt, kommt so nicht nach oben. Erst ab 5 aufgezeichneten Tagen, vorher 0,5.',
            'prime'   => 'Prime von Hand eingetragen (die Amazon-Schnittstelle liefert es nicht): ja 1, nein 0, unbekannt 0,5.',
            'liefer'  => 'Durchschnittliche Lieferzeit in Tagen zu den eingetragenen Hauptstädten: 1 Tag oder weniger ergibt 1, 7 Tage oder mehr 0. Ohne Angabe 0,5.',
        ];
        foreach (self::SCORE_NAMEN as $k => $name) {
            $h .= '<tr><td><strong>' . esc_html($name) . '</strong></td><td><input type="number" min="0" max="1000000" name="sab_w[' . esc_attr($k) . ']" value="' . (int) ($w[$k] ?? 0) . '" style="width:100px"> <small>(' . round(100 * ($w[$k] ?? 0) / max(1, $summe)) . ' %)</small></td><td><small>' . esc_html($erkl[$k] ?? '') . '</small></td></tr>';
        }
        $h .= '</tbody></table><p><button class="button button-primary">Gewichte speichern</button> <button class="button" name="sab_score_std" value="1">Auf Standard zurücksetzen</button> <span class="description">Standard: Rang 27, Buy Box 18, Angebot 13, Preis 9, Streichpreis 9, Deal 4, Bewertung 10, Konstanz 10.</span></p></form>';
        $h .= '<h3 style="margin-top:2em">Größe der Kategorien</h3><p class="description">Ein Rang zählt im Verhältnis zur Zahl der Produkte der Kategorie (Platz 69 von 800 ist weniger als Platz 69 von 500.000). Amazon liefert die Größe nicht, sie lässt sich aber auf der Kategorie-Seite („x Ergebnisse“) ablesen. Eine Zeile pro Kategorie: <code>Kategoriename | Anzahl Produkte</code>, z. B. <code>Jardín | 1500000</code>. Ohne Angabe schätzt das Plugin die Größe: Jardín fest 1,5 Mio., andere Kategorien aus dem doppelten größten Rang deiner Produkte (mindestens 2000). Eingetragene Zahlen haben Vorrang.</p>'
           . '<form method="post">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="score"><input type="hidden" name="sab_form" value="katgroesse">'
           . '<textarea name="sab_kat_groesse" rows="5" class="large-text code">' . esc_textarea((string) get_option('sab_kat_groesse', '')) . '</textarea><p><button class="button button-primary">Kategoriegrößen speichern</button></p></form>';
        $h .= '<h3 style="margin-top:2em">Provision je Kategorie</h3><p class="description">Amazon zahlt je Kategorie einen anderen Satz (Partnerkonto, „Gebührentabelle“). Eine Zeile pro Kategorie: <code>Kategoriename | Prozent</code>, z. B. <code>Jardín | 7</code>. Eine Zeile <code>* | 3</code> gilt für alle anderen. Die Übersicht zeigt dann den Satz und die geschätzte Provision pro Verkauf. Im Score zählt sie erst, wenn du oben das Gewicht „Provision“ über 0 setzt (10 % und mehr = volle Punktzahl).</p>'
           . '<form method="post">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="score"><input type="hidden" name="sab_form" value="provision">'
           . '<textarea name="sab_provision" rows="5" class="large-text code">' . esc_textarea((string) get_option('sab_provision', '')) . '</textarea><p><button class="button button-primary">Provisionen speichern</button></p></form>';
        $d0 = self::uebersicht_daten($land, false); $fehlt = self::handdaten_fehlen($d0); $nm0 = self::produkte();
        if ($fehlt) {
            $h .= '<h3 style="margin-top:2em">Handdaten fehlen (' . count($fehlt) . ')</h3><p class="description">Für diese Produkte fehlen Sterne, Prime oder Lieferzeit oder sie sind älter als 30 Tage. Der montägliche Rundgang sammelt sie für ' . (self::land('') === 'de' ? 'Berlin (10115) und München (80331)' : 'Madrid und Barcelona') . '.</p><ul style="columns:2;max-width:860px">';
            foreach ($fehlt as $a => $t) { $h .= '<li><code>' . esc_html($a) . '</code> ' . esc_html(($nm0[$a] ?? '') ?: mb_substr((string) ($d0['items'][$a]['titel'] ?? ''), 0, 40)) . ' <small>(' . esc_html($t) . ')</small></li>'; }
            $h .= '</ul>';
            $zl = ''; $host_ = preg_replace('#^www\.#', '', strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST)));
            foreach ($fehlt as $a => $t) { $zl .= $a . ' | ' . $host_ . ' | ' . trim(str_replace('|', ' ', ($nm0[$a] ?? '') ?: mb_substr((string) ($d0['items'][$a]['titel'] ?? ''), 0, 70))) . ' | ' . self::land('') . "\n"; }
            $h .= '<p class="description"><strong>Liste für die Helfersitzung</strong> (ASIN | Seite | Titel | Land), zum Kopieren:</p><textarea readonly rows="' . min(10, count($fehlt) + 1) . '" class="large-text code">' . esc_textarea($zl) . '</textarea>';
        }
        $h .= '<h3 style="margin-top:2em">Von Hand gepflegte Daten</h3><p class="description">Die Amazon-Schnittstelle liefert keine Sterne, kein Prime und keine Lieferzeit. Trag sie hier ein, eine Zeile pro Produkt: <code>ASIN | Sterne | Anzahl Bewertungen | Prime (ja/nein) | Lieferzeit in Tagen je Hauptstadt | Datum | Provision in % | Lieferkosten (Betrag, „gratis“ oder leer)</code>, zum Beispiel <code>B001AUKTYG | 4,1 | 588 | ja | ' . (self::land('') === 'de' ? 'Berlin 2, München 3, Hamburg 2' : 'Madrid 2, Barcelona 3, Sevilla 2') . ' | 2026-10-24 | 3</code>. Die Provision steht in der SiteStripe auf der Amazon-Seite (Tarif); sie gilt für dieses Produkt und geht der Kategorietabelle vor. Leere Felder sind erlaubt. Hohe Lieferkosten ziehen den Score nach unten, gratis oder Prime nicht. Die Angaben erscheinen als Chips in der Übersicht und fließen in den Score ein (Gewicht oben).</p>'
           . '<form method="post">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="score"><input type="hidden" name="sab_form" value="manuell">'
           . '<textarea name="sab_manuell" rows="10" class="large-text code">' . esc_textarea((string) get_option('sab_manuell', '')) . '</textarea><p><button class="button button-primary">Handdaten speichern</button></p></form>';
        $h .= '<h3 style="margin-top:2em">Ersatz-Datenbank</h3><p class="description">Geprüfte Ersatzprodukte, aus denen der Austausch zuerst schöpft. Eine Zeile pro Ersatzprodukt: <code>Produktart | Land | ASIN | Status (neu/geprüft) | Stand | Quelle</code>, z. B. <code>Schneckenzaun | co.uk | B0EXAMPLE1 | geprüft | 2026-10-27 | PC-Sitzung</code>. „Produktart“ ist der Name einer Produktgruppe (Reiter Gruppen) oder eine ASIN (Ersatz nur für dieses Produkt). Sterne, Provision, Lieferzeit und Lieferkosten der Ersatzprodukte stehen oben bei den Handdaten. Geprüfte Einträge werden im Austausch bevorzugt; es zählt nur das Land dieser Webseite (' . esc_html(self::land('')) . ').</p>'
           . '<form method="post">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="score"><input type="hidden" name="sab_form" value="ersatz">'
           . '<textarea name="sab_ersatz" rows="8" class="large-text code">' . esc_textarea((string) get_option('sab_ersatz', '')) . '</textarea><p><button class="button button-primary">Ersatz-Datenbank speichern</button></p></form>' . self::ersatz_html();
        $d = self::uebersicht_daten($land, false);
        $h .= self::stand_hinweis($d);
        if (!empty($d['items'])) {
            $liste = [];
            foreach ($d['items'] as $a => $x) { $x['trotz'] = isset(((array) get_option('sab_trotz', []))[$a]); $liste[$a] = [self::score($x), $x]; }
            uasort($liste, fn($p, $q) => $q[0][0] <=> $p[0][0]);
            $auswahl = [];
            if ($liste) { $k = array_keys($liste); $auswahl[] = $k[0]; $auswahl[] = $k[intdiv(count($k), 2)]; $auswahl[] = $k[count($k) - 1]; }
            $namen = self::produkte();
            $h .= '<h3 style="margin-top:2em">Rechenbeispiele mit echten Produkten</h3><p class="description">Ein sehr gutes, ein mittleres und ein schwaches Produkt aus deiner Liste, mit den aktuellen Gewichten.</p>';
            foreach (array_unique($auswahl) as $a) {
                [$sc, $sch, $det] = $liste[$a][0];
                $h .= '<h4>' . esc_html(($namen[$a] ?? '') ?: mb_substr((string) ($liste[$a][1]['titel'] ?? ''), 0, 60)) . ' <code>' . esc_html($a) . '</code> &rarr; Score <strong>' . $sc . '</strong></h4>'
                    . '<table class="widefat striped" style="max-width:860px"><thead><tr><th>Posten</th><th>Daten</th><th>Faktor</th><th>Gewicht</th><th>Punkte</th></tr></thead><tbody>';
                foreach ($det as [$n, $g, $fa, $pt, $info]) { $h .= '<tr><td>' . esc_html($n) . '</td><td>' . esc_html($info) . '</td><td>' . number_format($fa, 2, ',', '') . '</td><td>' . (int) $g . '</td><td>' . number_format($pt, 1, ',', '') . '</td></tr>'; }
                $h .= '</tbody></table>' . ($sch !== '' ? '<p class="description">' . esc_html($sch) . '</p>' : '');
            }
        }
        return $h;
    }

    /** Hinweiszeile zum Alter der Daten (Übersicht, Problemprodukte, Umbau). */
    private static function stand_hinweis(array $d): string {
        if (empty($d['ts'])) { return ''; }
        $min = max(0, (int) round((time() - (int) $d['ts']) / 60));
        return '<p class="description">Stand: vor ' . ($min < 60 ? $min . ' Min.' : round($min / 60, 1) . ' Std.') . (!empty($d['im_hintergrund']) ? ' &ndash; neue Daten werden gerade im Hintergrund geholt, bitte die Seite in ein bis zwei Minuten neu öffnen.' : '') . '</p>';
    }

    /** Rückkehr-Anteil ehrlich: unter MIN_RUECK Klicks ist eine Prozentzahl nur Zufall. */
    private static function rueck_text(int $n, int $z): string {
        if ($n < 10) { return '<span style="color:#787c82">zu wenig Daten (' . $z . ' von ' . $n . ')</span>'; }
        return round(100 * $z / $n) . ' % <span style="color:#787c82">(' . $z . ' von ' . $n . ')</span>';
    }

    /** Reiter „Statistik“: Klicks pro Tag (Balken antippbar), meistgeklickte Produkte mit Bild, Score und aufklappbaren Seiten. Nur Verwaltung. */
    private static function statistik_html(): string {
        global $wpdb;
        if (!isset($wpdb)) { return '<p>Keine Datenbank.</p>'; }
        $tage = in_array((int) ($_GET['tage'] ?? 30), [7, 30, 90], true) ? (int) ($_GET['tage'] ?? 30) : 30;
        $t = self::klicktabelle();
        $ab = gmdate('Y-m-d 00:00:00', time() - ($tage - 1) * DAY_IN_SECONDS);
        $pro = [];
        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT DATE(ts) d, COUNT(*) n FROM $t WHERE ts >= %s GROUP BY DATE(ts)", $ab), ARRAY_A) as $r) { $pro[$r['d']] = (int) $r['n']; }
        $werte = []; $max = 1; $summe = 0;
        for ($i = $tage - 1; $i >= 0; $i--) {
            $d = gmdate('Y-m-d', time() - $i * DAY_IN_SECONDS);
            $werte[$d] = $pro[$d] ?? 0; $max = max($max, $werte[$d]); $summe += $werte[$d];
        }
        $br = 900; $hoch = 160; $bw = $br / $tage;
        $svg = '<svg id="sab-bal" viewBox="0 0 ' . $br . ' ' . ($hoch + 22) . '" style="width:100%;height:auto;background:#fff;border:1px solid #dcdcde;border-radius:6px;cursor:pointer" role="img" aria-label="Klicks pro Tag">';
        $svg .= '<text x="4" y="12" font-size="11" fill="#555">höchster Tag: ' . $max . ' Klicks</text>';
        $k = 0;
        foreach ($werte as $d => $n) {
            $h = (int) round(($hoch - 22) * $n / $max); $x = $k * $bw;
            $txt = wp_date('d.m.Y', strtotime($d . ' 12:00:00 UTC')) . ': ' . $n . ($n === 1 ? ' Klick' : ' Klicks');
            $svg .= '<g class="sab-b" data-t="' . esc_attr($txt) . '"><rect x="' . round($x, 1) . '" y="18" width="' . round($bw, 1) . '" height="' . ($hoch - 18) . '" fill="transparent"/>'
                . '<rect x="' . round($x + 1, 1) . '" y="' . ($hoch - $h) . '" width="' . round(max($bw - 2, 1), 1) . '" height="' . max($h, $n ? 1 : 0) . '" rx="2" fill="#2271b1"><title>' . esc_html($txt) . '</title></rect>';
            if ($tage <= 14 && $n) { $svg .= '<text x="' . round($x + $bw / 2, 1) . '" y="' . ($hoch - $h - 3) . '" font-size="12" text-anchor="middle" fill="#1d2327">' . $n . '</text>'; }
            $svg .= '</g>';
            if ($k % max(1, (int) ceil($tage / 10)) === 0) { $svg .= '<text x="' . round($x, 1) . '" y="' . ($hoch + 14) . '" font-size="10" fill="#555">' . esc_html(wp_date('d.m.', strtotime($d . ' 12:00:00 UTC'))) . '</text>'; }
            $k++;
        }
        $svg .= '</svg><p id="sab-bal-info" style="margin:6px 0 0;font-weight:600;min-height:1.5em">Balken antippen: Klicks dieses Tages</p>'
            . '<script>(function(){var s=document.getElementById("sab-bal"),i=document.getElementById("sab-bal-info");if(!s)return;s.addEventListener("click",function(e){var g=e.target.closest&&e.target.closest(".sab-b");if(g)i.textContent=g.getAttribute("data-t")})})();</script>';

        $ziel_h = '';
        $zr = (array) $wpdb->get_results($wpdb->prepare("SELECT ziel, COUNT(*) n FROM $t WHERE ts >= %s AND ziel <> '' GROUP BY ziel ORDER BY n DESC", $ab), ARRAY_A);
        if ($zr) {
            $zn = ['com' => 'USA', 'ca' => 'Kanada', 'com.au' => 'Australien/Neuseeland']; $zs = 0; $zz = '';
            foreach ($zr as $r) { [$lz, $art] = explode(':', (string) ($r['ziel'] ?? '')) + ['', '']; $zs += (int) $r['n']; $zz .= '<tr><td>' . esc_html($zn[$lz] ?? $lz) . '</td><td>' . ($art === 'a' ? 'direkt zur ASIN im Land' : 'zur Suche im Land') . '</td><td>' . (int) $r['n'] . '</td></tr>'; }
            $ziel_h = '<h3 style="margin-top:1.5em">Umgeleitete Klicks (Besucher aus anderen Ländern)</h3><p class="description">' . $zs . ' von ' . $summe . ' Klicks (' . ($summe ? round(100 * $zs / $summe) : 0) . ' %) gingen über die Länder-Umschaltung zum Amazon des Besucherlandes.</p><table class="widefat striped" style="max-width:560px"><thead><tr><th>Ziel</th><th>wohin</th><th>Klicks</th></tr></thead><tbody>' . $zz . '</tbody></table>';
        } elseif (get_option('sab_geo') === '1') { $ziel_h = '<h3 style="margin-top:1.5em">Umgeleitete Klicks</h3><p class="description">Die Länder-Umschaltung ist an, in diesem Zeitraum gab es aber noch keine umgeleiteten Klicks.</p>'; }
        // Daten je Produkt und je Seite
        $zeilen = (array) $wpdb->get_results($wpdb->prepare("SELECT asin, COUNT(*) n, SUM(typ='bild') b, SUM(typ<>'bild') x, SUM(zurueck_s IS NOT NULL) z, AVG(zurueck_s) m FROM $t WHERE ts >= %s GROUP BY asin ORDER BY n DESC", $ab), ARRAY_A);
        $seiten = [];
        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT asin, pfad, anker, COUNT(*) n FROM $t WHERE ts >= %s GROUP BY asin, pfad, anker ORDER BY n DESC", $ab), ARRAY_A) as $r) { $seiten[$r['asin']][] = $r; }
        $land = self::land('');
        $cache = get_transient("sab_uebersicht_{$land}");   // nur lesen, nie neu holen
        if (!is_array($cache)) { $cache = get_option("sab_uebersicht_stand_{$land}"); }
        $items = is_array($cache) ? (array) ($cache['items'] ?? []) : [];
        $namen = self::produkte(); $trotz = (array) get_option('sab_trotz', []);
        $gz = 0; foreach ($zeilen as $r) { $gz += (int) $r['z']; }
        $us = (array) get_option('sab_update_status', []);
        $lz = [];   // je Hauptprodukt: Besucherland, Zielland, ausgelieferte ASIN
        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT asin, herkunft, ziel_land, ziel_asin, COUNT(*) n FROM $t WHERE ts >= %s AND (herkunft <> '' OR ziel_land <> '') GROUP BY asin, herkunft, ziel_land, ziel_asin ORDER BY n DESC", $ab), ARRAY_A) as $r) { $lz[$r['asin']][] = $r; }

        $h = '<h2>Statistik</h2><style>.sab-st-k{display:flex;gap:10px;align-items:center;padding:10px;cursor:pointer;list-style:none}.sab-st-k::-webkit-details-marker{display:none}.sab-st-p{background:#fff;border:1px solid #dcdcde;border-radius:8px;margin:0 0 8px}.sab-st-p[open]{border-color:#2271b1}.sab-st-z{flex:1;min-width:0}.sab-st-z b{display:block;overflow-wrap:anywhere}.sab-st-z small{color:#50575e}.sab-st-n{font-size:20px;font-weight:700;text-align:right;min-width:48px}.sab-st-n small{display:block;font-size:11px;font-weight:400;color:#50575e}.sab-st-s{padding:0 10px 10px 10px;border-top:1px solid #f0f0f1}.sab-st-s li{margin:6px 0;overflow-wrap:anywhere}.sab-sc{display:inline-block;padding:1px 7px;border-radius:10px;background:#f0f6fc;color:#0a4b78;font-size:12px;font-weight:600}</style>'
          . '<p>Zeitraum: ';
        foreach ([7, 30, 90] as $z) { $h .= ($z === $tage ? '<strong>' . $z . ' Tage</strong>' : '<a href="' . esc_url(add_query_arg(['page' => 'sab-statistik', 'tage' => $z], admin_url('admin.php'))) . '">' . $z . ' Tage</a>') . ' · '; }
        $h .= '<strong>' . $summe . '</strong> Klicks gesamt. Gezählt werden Klicks auf Plugin-Links; Bots und angemeldete Redakteure werden ausgelassen.</p>' . $svg . $ziel_h
          . '<p class="description">Update-Prüfung: ' . ($us ? esc_html(wp_date('d.m.Y H:i', (int) $us['ts']) . ', Antwort ' . $us['code'] . ', Version im Repo ' . ($us['version'] ?: '–')) : 'noch nicht gelaufen') . '</p>';
        $h .= '<h3 style="margin-top:1.5em">Meistgeklickte Produkte</h3><p class="description">Auf ein Produkt tippen: darunter stehen die Seiten, von denen die Klicks kamen (mit Sprung zur Stelle im Artikel, soweit die Stelle erfasst ist). '
          . '<strong>Zurück</strong> = Besucher kam nach dem Klick in den Tab der Webseite zurück. Das wird nur gemessen, wenn der Besucher später zurückkehrt; wer Amazon im selben Tab weiterbenutzt oder den Tab schließt, zählt nicht mit. '
          . 'Darum ist die Zahl bei wenigen Klicks Zufall (1 von 1 = 100 %), erst ab 10 Klicks wird ein Prozentwert gezeigt. Insgesamt: ' . self::rueck_text($summe, $gz) . '.</p>';
        if (!$zeilen) { return $h . '<p>Noch keine Klicks in diesem Zeitraum.</p>'; }
        $home = untrailingslashit(home_url());
        foreach ($zeilen as $r) {
            $a = (string) $r['asin']; $n = (int) $r['n']; $z = (int) $r['z']; $x = $items[$a] ?? [];
            $name = ($namen[$a] ?? '') ?: mb_substr(trim((string) ($x['titel'] ?? '')), 0, 80);
            if ($name === '') { $name = $a; }
            $score = '';
            if ($x) { $x['trotz'] = isset($trotz[$a]); $score = ' <span class="sab-sc" title="Score aus der Übersicht">Score ' . (int) self::score($x)[0] . '</span>'; }
            $bild = $x ? self::mini_bild($a, $x) : self::mini_bild($a, []);
            $h .= '<details class="sab-st-p"><summary class="sab-st-k">' . str_replace('width:64px;height:64px', 'width:44px;height:44px', $bild)
                . '<span class="sab-st-z"><b>' . esc_html($name) . '</b><small><code>' . esc_html($a) . '</code>' . $score . '</small>'
                . '<small style="display:block;margin-top:2px">Bild ' . (int) $r['b'] . ' · Text/Button ' . (int) $r['x'] . ' · Anteil ' . round(100 * $n / max($summe, 1)) . ' % · Zurück: ' . self::rueck_text($n, $z) . ($z ? ' · Ø ' . esc_html(self::dauer_text((float) $r['m'])) : '') . '</small></span>'
                . '<span class="sab-st-n">' . $n . '<small>Klicks</small></span></summary><div class="sab-st-s"><p class="description" style="margin:8px 0 2px">Seiten, von denen die Klicks kamen:</p><ol style="margin:0 0 0 18px">';
            $k = 0;
            foreach ((array) ($seiten[$a] ?? []) as $sr) {
                if (++$k > 15) { break; }
                $pf = (string) ($sr['pfad'] ?? ''); $an = (string) ($sr['anker'] ?? '');
                $titel = '';
                if ($pf !== '' && function_exists('url_to_postid') && ($pid = (int) url_to_postid($home . $pf)) > 0) { $titel = (string) get_the_title($pid); }
                $url = $home . $pf . ($an !== '' ? '#' . $an : '');
                $h .= '<li><a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html($titel !== '' ? $titel : ($pf !== '' ? $pf : '(unbekannte Seite)')) . '</a> &ndash; <strong>' . (int) $sr['n'] . '</strong>'
                    . ($an !== '' ? ' <small>(Stelle: ' . esc_html($an) . ')</small>' : ' <small>(Stelle nicht erfasst)</small>') . '</li>';
            }
            $h .= '</ol>';
            if (!empty($lz[$a])) {
                $h .= '<p class="description" style="margin:10px 0 2px">Woher die Besucher kamen und wohin der Klick ging (Land aus Zeitzone/Sprache, keine IP):</p><table class="widefat striped" style="max-width:640px"><thead><tr><th>Besucher aus</th><th>Amazon-Land</th><th>ausgelieferte ASIN</th><th>Klicks</th></tr></thead><tbody>';
                foreach (array_slice($lz[$a], 0, 20) as $lr) {
                    $za = (string) $lr['ziel_asin'];
                    $h .= '<tr><td>' . esc_html($lr['herkunft'] !== '' ? $lr['herkunft'] : '?') . '</td><td>' . esc_html($lr['ziel_land'] !== '' ? 'amazon.' . $lr['ziel_land'] : '?') . '</td><td>' . ($za === '' ? '<em>Suche</em>' : '<code>' . esc_html($za) . '</code>' . ($za === $a ? '' : ' <small>(andere ASIN)</small>')) . '</td><td>' . (int) $lr['n'] . '</td></tr>';
                }
                $h .= '</tbody></table>';
            }
            $h .= '</div></details>';
        }
        return $h;
    }

    /**
     * Ersatzvorschlag für ein Produkt: das Produkt mit dem höchsten Score aus derselben Produktgruppe, sonst aus derselben Amazon-Kategorie;
     * nur Produkte mit Preis, Buy Box und Verfügbarkeit (oder von dir bestätigt).
     * @return array{0:string,1:int,2:string}|null [ASIN, Score, Name]
     */
    private static function empfehlung(string $asin, array $items, array $trotz): ?array {
        $kandidaten = [];
        foreach (self::gruppen() as $liste) { if (in_array($asin, $liste, true)) { $kandidaten = array_merge($kandidaten, $liste); } }
        if (!$kandidaten) {
            $kat = (string) ($items[$asin]['kategorie'] ?? '');
            if ($kat !== '') { foreach ($items as $a => $x) { if ((string) ($x['kategorie'] ?? '') === $kat) { $kandidaten[] = (string) $a; } } }
        }
        $namen = self::produkte(); $beste = null;
        foreach (array_unique($kandidaten) as $a) {
            $x = $items[$a] ?? null;
            if (!$x || $a === $asin) { continue; }
            $x['trotz'] = isset($trotz[$a]);
            $ok = ($x['preis'] ?? '') !== '' && !empty($x['kaufen']) && !empty($x['lieferbar']) && ($x['status'] ?? '') !== 'OUT_OF_STOCK';
            if (!$ok && empty($x['trotz'])) { continue; }
            $sc = self::score($x)[0];
            if ($beste === null || $sc > $beste[1]) { $beste = [(string) $a, $sc, ($namen[$a] ?? '') ?: mb_substr((string) ($x['titel'] ?? ''), 0, 70)]; }
        }
        return $beste;
    }

    /** Reiter „Produktgruppen“: ähnliche Produkte nebeneinander, nach Score geordnet, mit Vorschlag für Ersatzliste und A/B-Test. */
    private static function gruppen_html(): string {
        $land = self::land('');
        $gr = self::gruppen();
        $h = '<h2>Produktgruppen</h2><form method="post">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false)
           . '<input type="hidden" name="sab_tab" value="gruppen"><input type="hidden" name="sab_form" value="gruppen">'
           . '<p>Pro Zeile: <code>Gruppenname: ASIN1, ASIN2, ASIN3</code>, zum Beispiel <code>Kupferband: B07YCJ4YZJ, B08JLM6JG6</code>. Ein Produkt kann in mehreren Gruppen stehen.</p>'
           . '<textarea name="sab_gruppen" rows="6" class="large-text code" placeholder="Kupferband: B07YCJ4YZJ, B08JLM6JG6">' . esc_textarea((string) get_option('sab_gruppen', '')) . '</textarea>'
           . '<p><button class="button button-primary">Gruppen speichern</button></p></form>';
        if (!$gr) { return $h . '<p>Noch keine Gruppen angelegt.</p>'; }
        $d = self::uebersicht_daten($land, !empty($_POST['sab_neu']));
        $h .= self::stand_hinweis($d);
        if (!empty($d['fehler'])) { return $h . '<div class="notice notice-error inline"><p>' . esc_html($d['fehler']) . '</p></div>'; }
        $namen = self::produkte(); $trotz = (array) get_option('sab_trotz', []);
        $h .= '<p class="description">Stand höchstens 1 Stunde alt. Jede Zeile zeigt dieselben Angaben wie die Übersicht (Score, Status, Preis, Provision, Rang, Seiten, Klicks, Details). Die Provision je Verkauf entscheidet bei gleich guten Produkten. Innerhalb einer Gruppe ähnlicher Produkte ist der Vergleich aussagekräftig.</p>';
        $kz = self::klickzahlen(); $fund = self::fundstellen(array_values(array_unique(array_merge(...array_values($gr))))); $dp = 'https://' . self::LAENDER[$land][0] . '/dp/';
        $h .= self::uebersicht_css();
        foreach ($gr as $name => $asins) {
            $zeilen = [];
            foreach ($asins as $a) {
                $x = $d['items'][$a] ?? null;
                if ($x) { $x['trotz'] = isset($trotz[$a]); }
                $zeilen[] = ['asin' => $a, 'x' => $x, 'score' => $x ? self::score($x)[0] : -1];
            }
            usort($zeilen, fn($p, $q) => $q['score'] <=> $p['score']);
            $h .= '<h3 style="margin-top:1.5em">' . esc_html($name) . ' (' . count($zeilen) . ')</h3>' . self::uebersicht_kopf(false);
            $gut = [];
            foreach ($zeilen as $i => $z) {
                $x = $z['x'];
                if (!$x) { $h .= '<tr><td><code>' . esc_html($z['asin']) . '</code> ' . esc_html($namen[$z['asin']] ?? '') . '</td><td colspan="2"><strong style="color:#a00">Amazon kennt das Produkt nicht (mehr)</strong></td><td colspan="8"></td></tr>'; continue; }
                $brauchbar = !empty($x['trotz']) || (!empty($x['lieferbar']) && ($x['status'] ?? '') !== 'OUT_OF_STOCK') || (self::unklar($x) && isset($x['rang']) && $x['rang'] <= 20000);
                if ($brauchbar) { $gut[] = $z['asin']; }
                $h .= self::uebersicht_zeile($z['asin'], $x, $namen, $kz, $fund, $dp, false);
            }
            $h .= '</tbody></table>' . self::tabellenhilfe();
            if ($gut) {
                $slot = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $name));
                $h .= '<p><strong>Vorschlag Ersatzliste (beste zuerst):</strong> <code>' . esc_html($slot . ': ' . implode(', ', $gut)) . '</code>'
                    . (count($gut) >= 2 ? '<br><strong>A/B-Test mit den zwei Besten:</strong> <code>' . esc_html($gut[0] . ' gegen ' . $gut[1]) . '</code>' : '') . '</p>';
            } else {
                $h .= '<p><strong>Kein Produkt dieser Gruppe ist derzeit brauchbar</strong>, bitte neue Produkte suchen.</p>';
            }
        }
        return $h . self::bild_gross_html();
    }

    /** Fundstellen je ASIN: Artikel/Seiten dieser Webseite und wie oft das Produkt dort vorkommt (Shortcode, AAWP oder fester Link). */
    private static function fundstellen(array $asins): array {
        global $wpdb;
        $out = [];
        if (!isset($wpdb) || !$asins) { return $out; }
        $rows = $wpdb->get_results("SELECT ID, post_title, post_content FROM {$wpdb->posts} WHERE post_status='publish' AND post_type IN ('post','page') AND (post_content LIKE '%amazon.%' OR post_content LIKE '%[amz\\_%' OR post_content LIKE '%[aawp%' OR post_content LIKE '%[amazon %' OR post_content LIKE '%amzn.to%')", ARRAY_A);
        foreach ((array) $rows as $r) {
            $c = (string) $r['post_content'];
            $ohne = preg_replace('#\[amz_(bild|produkt|link)\b[^\]]*\].*?\[/amz_\1\]|\[(?:amz_[a-z]+|aawp|amazon)\b[^\]]*\]#is', '', $c);   // ohne Plugin-Platzhalter: was übrig bleibt, sind feste Links
            foreach ($asins as $a) {
                $n = substr_count($c, $a);
                if ($n > 1) { $n = max(1, count(self::stellen_gruppen($c, $a))); }
                if ($n > 0) { $out[$a][(int) $r['ID']] = ['titel' => (string) $r['post_title'], 'n' => $n, 'fest' => substr_count($ohne, $a)]; }
            }
        }
        return $out;
    }

    // ---------- Schutz vor doppelten Produkten (nebeneinander oder zu nah nacheinander) ----------

    const NAH = 3000;       // Zeichen im Artikeltext: dasselbe Produkt noch einmal im Abstand darunter gilt als „zu nah“
    const NAH_EINS = 700;   // Bild und Link im selben Block zählen zusammen als eine Anzeige

    /**
     * Findet im Artikeltext dasselbe Produkt an zwei Stellen, die sich zu nahe sind:
     * „nebeneinander“ = in zwei Spalten derselben Tabelle, „nah“ = zwei getrennte Anzeigen im Abstand unter NAH Zeichen.
     * @return array<int,array{asin:string,art:string,abstand:int}>
     */
    public static function doppel_funde(string $c): array {
        $out = []; $re = '#(?:asin|fields|box|link|data-sab-asin)\s*=\s*["\']?([A-Z0-9]{10})\b|/(?:dp|gp/product|product)/([A-Z0-9]{10})\b#i';
        $id = function (array $x): string { return strtoupper(($x[1] ?? '') !== '' ? $x[1] : ($x[2] ?? '')); };
        $tabellen = [];
        if (preg_match_all('#<table\b.*?</table>#is', $c, $tm, PREG_OFFSET_CAPTURE)) {
            foreach ($tm[0] as [$t, $off]) {
                $tabellen[] = [$off, $off + strlen($t)]; $spalten = [];
                preg_match_all('#<tr\b.*?</tr>#is', $t, $rows);
                foreach ($rows[0] as $row) {
                    preg_match_all('#<t[dh]\b.*?</t[dh]>#is', $row, $cs);
                    foreach ($cs[0] as $i => $cell) {
                        if (preg_match_all($re, $cell, $m, PREG_SET_ORDER)) { foreach ($m as $x) { $spalten[$id($x)][$i] = 1; } }
                    }
                }
                foreach ($spalten as $a => $sp) { if (count($sp) > 1) { $out[] = ['asin' => (string) $a, 'art' => 'nebeneinander', 'abstand' => 0]; } }
            }
        }
        if (preg_match_all($re, $c, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            $iv = [];
            foreach ($m as $x) {
                $a = $id(array_map(fn($v) => $v[0], $x)); $p = (int) $x[0][1]; $s = $p; $e = $p;
                foreach ($tabellen as $ti => [$ts, $te]) { if ($p >= $ts && $p <= $te) { $s = $ts; $e = $te; break; } }   // eine Tabelle zählt als eine Anzeige
                $iv[$a][] = [$s, $e];
            }
            foreach ($iv as $a => $liste) {
                usort($liste, fn($x, $y) => $x[0] <=> $y[0]);
                $gr = [$liste[0]];
                foreach (array_slice($liste, 1) as [$s, $e]) {
                    $l = count($gr) - 1;
                    if ($s - $gr[$l][1] <= self::NAH_EINS) { $gr[$l][1] = max($gr[$l][1], $e); } else { $gr[] = [$s, $e]; }
                }
                for ($i = 1; $i < count($gr); $i++) {
                    $abst = $gr[$i][0] - $gr[$i - 1][1];
                    if ($abst < self::NAH) { $out[] = ['asin' => (string) $a, 'art' => 'nah', 'abstand' => $abst]; }
                }
            }
        }
        return $out;
    }

    const HAUFEN_MAX = 2;   // höchstens so viele Produktkarten direkt hintereinander, dann muss Text folgen
    const HAUFEN_TEXT = 150; // weniger Text (Zeichen) zwischen zwei Karten gilt als „direkt hintereinander“

    /**
     * Findet Haufen: mehr als HAUFEN_MAX Produktkarten (Tabellenspalten, [amz_bild], [amz_produkt], [amz_karte], [aawp] mit Bild) ohne echten Text dazwischen.
     * @return array<int,array{anzahl:int,pos:int}>
     */
    public static function haufen_funde(string $c): array {
        $re = '#(?:asin|fields|box|link|data-sab-asin)\s*=\s*["\']?([A-Z0-9]{10})\b|/(?:dp|gp/product|product)/([A-Z0-9]{10})\b#i';
        $karte = '#\[(?:amz_bild|amz_produkt|amz_karte|amz_karten)\b|\[(?:aawp|amazon)\b[^\]]*(?:thumb|image|box|grid|table)#i';
        $units = []; $tabellen = [];
        if (preg_match_all('#<table\b.*?</table>#is', $c, $tm, PREG_OFFSET_CAPTURE)) {
            foreach ($tm[0] as [$t, $off]) {
                $tabellen[] = [$off, $off + strlen($t)]; $ids = [];
                preg_match_all('#<t[dh]\b.*?</t[dh]>#is', $t, $cs);
                foreach ($cs[0] as $cell) {
                    if (!preg_match($karte, $cell) || !preg_match_all($re, $cell, $m, PREG_SET_ORDER)) { continue; }
                    foreach ($m as $x) { $ids[strtoupper(($x[1] ?? '') !== '' ? $x[1] : ($x[2] ?? ''))] = 1; }
                }
                if ($ids) { $units[] = [$off, $off + strlen($t), count($ids)]; }
            }
        }
        $kbl = [];   // [amz_karten]-Blöcke: eine Einheit mit so vielen Produkten, wie Karten darin stehen (nicht zusätzlich die einzelnen Karten zählen)
        if (preg_match_all('#\[amz_karten\].*?\[/amz_karten\]#is', $c, $km, PREG_OFFSET_CAPTURE)) {
            foreach ($km[0] as [$t, $off]) { $n = preg_match_all('#\[amz_karte\b#i', $t); $kbl[] = [$off, $off + strlen($t)]; $units[] = [$off, $off + strlen($t), max(1, $n)]; }
        }
        if (preg_match_all('#\[(amz_bild|amz_produkt|amz_karte|aawp|amazon)\b[^\]]*\]#i', $c, $sm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($sm as $x) {
                $p = (int) $x[0][1]; $in = false;
                foreach ($tabellen as [$ts, $te]) { if ($p >= $ts && $p <= $te) { $in = true; break; } }
                foreach ($kbl as [$ks, $ke]) { if ($p >= $ks && $p < $ke) { $in = true; break; } }
                if ($in || !preg_match($karte, $x[0][0])) { continue; }
                $end = $p + strlen($x[0][0]);
                $z = stripos($c, '[/' . $x[1][0] . ']', $end);
                if ($z !== false && $z - $end < 3000) { $end = $z + strlen($x[1][0]) + 3; }
                $units[] = [$p, $end, 1];
            }
        }
        usort($units, fn($a, $b) => $a[0] <=> $b[0]);
        $out = []; $cl = null;
        foreach ($units as [$s, $e, $n]) {
            if ($cl !== null && $s >= $cl['ende']) {
                $zw = trim(preg_replace('/\s+/u', ' ', strip_tags(preg_replace('/\[[^\]]*\]/', ' ', substr($c, $cl['ende'], $s - $cl['ende'])))));
                if (mb_strlen($zw) < self::HAUFEN_TEXT) { $cl['anzahl'] += $n; $cl['ende'] = max($cl['ende'], $e); continue; }
            } elseif ($cl !== null) { $cl['anzahl'] += $n; $cl['ende'] = max($cl['ende'], $e); continue; }
            if ($cl !== null && $cl['anzahl'] > self::HAUFEN_MAX) { $out[] = ['anzahl' => $cl['anzahl'], 'pos' => $cl['pos'], 'ende' => $cl['ende']]; }
            $cl = ['anzahl' => $n, 'pos' => $s, 'ende' => $e];
        }
        if ($cl !== null && $cl['anzahl'] > self::HAUFEN_MAX) { $out[] = ['anzahl' => $cl['anzahl'], 'pos' => $cl['pos'], 'ende' => $cl['ende']]; }
        return $out;
    }

    /**
     * Aufteilungs-Vorschau für einen Haufen (nur Lesen): je Tabelle die Überschrift davor und die Produkte mit Kategorie, Score und Provision,
     * dazu ein Vorschlag, die Produkte zu Paaren aus verschiedenen Kategorien zu ordnen (bester Score und beste Provision zuerst).
     * @return array{tabellen:array,paare:array,gleich:int,ohne_text:int}
     */
    public static function haufen_plan(string $c, array $f, array $d): array {
        $out = ['tabellen' => [], 'paare' => [], 'gleich' => 0, 'ohne_text' => 0];
        $alle = [];
        if (!preg_match_all('#<table\b.*?</table>#is', $c, $tm, PREG_OFFSET_CAPTURE)) { return $out; }
        $vorher = max(0, (int) $f['pos'] - 300);
        foreach ($tm[0] as [$t, $off]) {
            if ($off < $f['pos'] || $off > $f['ende']) { continue; }
            $kopf = trim(preg_replace('/\s+/u', ' ', strip_tags(preg_replace('/\[[^\]]*\]/', ' ', substr($c, $vorher, max(0, $off - $vorher))))));
            $vorher = $off + strlen($t);
            $spalten = [];
            preg_match_all('#<tr\b.*?</tr>#is', $t, $rows);
            foreach ($rows[0] as $row) {
                preg_match_all('#<t[dh]\b.*?</t[dh]>#is', $row, $cs);
                foreach ($cs[0] as $ci => $cell) { foreach (self::zelle_asins($cell) as $u) { $spalten[$ci][$u[0]] = 1; } }
            }
            $prod = [];
            foreach ($spalten as $sp) {
                $a = (string) array_key_first($sp); $x = $d['items'][$a] ?? [];
                $prod[] = ['asin' => $a, 'kat' => (string) ($x['kategorie'] ?? ''), 'score' => $x ? self::score($x)[0] : 0, 'prov' => $x ? self::provision_satz($x) : null];
                $alle[$a] = end($prod);
            }
            $gleich = count($prod) === 2 && $prod[0]['kat'] !== '' && $prod[0]['kat'] === $prod[1]['kat'];
            if ($gleich) { $out['gleich']++; }
            if (mb_strlen($kopf) < self::HAUFEN_TEXT) { $out['ohne_text']++; }
            $out['tabellen'][] = ['kopf' => mb_substr($kopf, -90), 'produkte' => $prod, 'gleich' => $gleich];
        }
        // Vorschlag: Paare aus verschiedenen Kategorien, bestes Produkt zuerst mit dem besten Partner anderer Kategorie
        $rest = $alle; uasort($rest, fn($a, $b) => ($b['score'] + 3 * (float) $b['prov']) <=> ($a['score'] + 3 * (float) $a['prov']));
        while ($rest) {
            $gruppen = [];   // Kategorie => ASINs (beste zuerst); ohne bekannte Kategorie zählt jedes Produkt für sich
            foreach ($rest as $a => $x) { $gruppen[$x['kat'] !== '' ? $x['kat'] : '#' . $a][] = $a; }
            uasort($gruppen, fn($x, $y) => count($y) <=> count($x));   // stabil: bei Gleichstand bleibt die Reihenfolge nach Score
            $ks = array_values($gruppen);
            $a1 = $ks[0][0]; $p1 = $rest[$a1]; unset($rest[$a1]);
            $a2 = isset($ks[1]) ? $ks[1][0] : ($ks[0][1] ?? null);
            $p2 = $a2 !== null ? $rest[$a2] : null; if ($a2 !== null) { unset($rest[$a2]); }
            $out['paare'][] = $p2 ? [$p1, $p2] : [$p1];
        }
        return $out;
    }

    private static function haufen_plan_html(array $plan, array $namen, array $d): string {
        $z = function (array $p) use ($namen, $d): string {
            $n = ($namen[$p['asin']] ?? '') ?: mb_substr((string) ($d['items'][$p['asin']]['titel'] ?? ''), 0, 40);
            return '<code>' . esc_html($p['asin']) . '</code> ' . esc_html($n) . ' <small>(' . esc_html($p['kat'] !== '' ? $p['kat'] : 'Kategorie unbekannt') . ', Score ' . (int) $p['score'] . ', Prov. ' . ($p['prov'] !== null ? str_replace('.', ',', (string) $p['prov']) . ' %' : '?') . ')</small>';
        };
        $h = '<p><strong>Jetzt:</strong></p><ol>';
        foreach ($plan['tabellen'] as $t) {
            $h .= '<li>' . ($t['kopf'] !== '' ? '„' . esc_html($t['kopf']) . '“ ' : '<em>ohne Überschrift</em> ') . ($t['gleich'] ? '<strong style="color:#b36b00">(beide aus derselben Kategorie)</strong>' : '') . '<ul style="margin:2px 0 6px 16px">';
            foreach ($t['produkte'] as $p) { $h .= '<li>' . $z($p) . '</li>'; }
            $h .= '</ul></li>';
        }
        $h .= '</ol><p><strong>Vorschlag (Paare aus verschiedenen Kategorien, dazwischen jeweils Text):</strong></p><ol>';
        foreach ($plan['paare'] as $pa) { $h .= '<li><ul style="margin:2px 0 6px 16px">'; foreach ($pa as $p) { $h .= '<li>' . $z($p) . '</li>'; } $h .= '</ul></li>'; }
        return $h . '</ol><p class="description">Nur eine Vorschau, nichts wird geändert. Zwischen den Paaren braucht es einen kurzen Text zum Thema; den Text schreibt ihr oder Claude schlägt ihn vor.</p>';
    }

    /** Würde der Tausch $neu_c gegenüber $c ein Produkt $asin neu in eine zu nahe Doppelung bringen? */
    private static function doppel_neu(string $c, string $neu_c, string $asin): bool {
        $z = fn(string $t) => count(array_filter(self::doppel_funde($t), fn($f) => $f['asin'] === $asin));
        return $z($neu_c) > $z($c);
    }

    /** Spätere Anzeige eines zu nah wiederholten Produkts: Bereich [von, bis] des Absatzes bzw. der Tabelle der zweiten Stelle. @return int[]|null */
    public static function wied_stelle(string $c, string $asin): ?array {
        $re = '#(?:asin|fields|box|link|data-sab-asin)\s*=\s*["\']?([A-Z0-9]{10})\b|/(?:dp|gp/product|product)/([A-Z0-9]{10})\b#i'; $tab = []; $iv = [];
        if (preg_match_all('#<table\b.*?</table>#is', $c, $tm, PREG_OFFSET_CAPTURE)) { foreach ($tm[0] as [$t, $off]) { $tab[] = [$off, $off + strlen($t)]; } }
        if (!preg_match_all($re, $c, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) { return null; }
        foreach ($m as $x) {
            if (strtoupper(($x[1][0] ?? '') !== '' ? $x[1][0] : ($x[2][0] ?? '')) !== $asin) { continue; }
            $p = (int) $x[0][1]; $s = $p; $e = $p + strlen($x[0][0]);
            foreach ($tab as [$ts, $te]) { if ($p >= $ts && $p <= $te) { $s = $ts; $e = $te; break; } }
            $iv[] = [$s, $e, $s !== $p];
        }
        if (count($iv) < 2) { return null; }
        usort($iv, fn($a, $b) => $a[0] <=> $b[0]);
        $gr = [$iv[0]];
        foreach (array_slice($iv, 1) as $v) { $l = count($gr) - 1; if ($v[0] - $gr[$l][1] <= self::NAH_EINS) { $gr[$l][1] = max($gr[$l][1], $v[1]); } else { $gr[] = $v; } }
        for ($i = 1; $i < count($gr); $i++) {
            if ($gr[$i][0] - $gr[$i - 1][1] >= self::NAH) { continue; }
            [$s, $e, $tabelle] = $gr[$i];
            if (!$tabelle) {   // Absatz um die Stelle: ab letztem Absatzende bis zum nächsten
                $vor = substr($c, 0, $s); $b = 0;
                foreach (['</p>', '</table>', '</h2>', '</h3>', '</h4>', "\n\n", '[/amz_karten]'] as $t) { $q = strripos($vor, $t); if ($q !== false) { $b = max($b, $q + strlen($t)); } }
                $nach = substr($c, $e); $n = strlen($nach);
                foreach (['</p>', "\n\n", '</table>'] as $t) { $q = stripos($nach, $t); if ($q !== false) { $n = min($n, $q + ($t === "\n\n" ? 0 : strlen($t))); } }
                $s = min($s, $b); $e += $n;
            }
            return [$s, $e];
        }
        return null;
    }

    /** Ersetzt NUR die spätere, zu nahe Anzeige von ALT durch NEU (die erste bleibt). @return array{0:string,1:bool} */
    public static function wiederholung_ersetzen(string $c, string $alt, string $neu, array $alte_namen = [], string $neuer_name = ''): array {
        $st = self::wied_stelle($c, $alt);
        if (!$st) { return [$c, false]; }
        [$seg3, $n] = self::tausch_text(substr($c, $st[0], $st[1] - $st[0]), $alt, $neu, $alte_namen, $neuer_name);
        if ($n < 1) { return [$c, false]; }
        $c2 = substr($c, 0, $st[0]) . $seg3 . substr($c, $st[1]);
        $z = fn(string $t, string $a) => count(array_filter(self::doppel_funde($t), fn($f) => $f['asin'] === $a));
        if ($z($c2, $alt) >= $z($c, $alt) || self::doppel_neu($c, $c2, $neu)) { return [$c, false]; }   // muss die Wiederholung wirklich beseitigen und darf keine neue Doppelung erzeugen
        return [$c2, true];
    }

    /** Panel „Wiederholung ersetzen“: ähnliche Produkte als Variante für die zweite Stelle; Einsetzen nur nach Klick (Freigabe), danach Rückgängig. */
    private static function wiederholung_html(int $pid, string $alt): string {
        $post = get_post($pid); if (!$post) { return '<p>Artikel nicht gefunden.</p>'; }
        $c = (string) $post->post_content; $d = (array) self::uebersicht_daten(self::land(''), false); $trotz = (array) get_option('sab_trotz', []); $namen = self::produkte();
        $fund = self::fundstellen([$alt]); $h = '<h3>Wiederholung ersetzen: „' . esc_html($post->post_title) . '“</h3>';
        $b = (array) get_post_meta($pid, '_sab_wied_backup', true); $l = end($b);
        if ($l && md5($c) === ($l['hash'] ?? '')) { $h .= '<form method="post" style="margin:8px 0">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="umbau"><input type="hidden" name="sab_form" value="wied_zurueck"><input type="hidden" name="sab_id" value="' . $pid . '"><button class="button">Rückgängig: ' . esc_html((string) ($l['neu'] ?? '')) . ' wieder durch ' . esc_html((string) ($l['alt'] ?? '')) . ' ersetzen</button></form>'; }
        $st = self::wied_stelle($c, $alt);
        if (!$st) { return $h . '<p>Keine zu nahe Wiederholung von <code>' . esc_html($alt) . '</code> (mehr) in diesem Artikel.</p>'; }
        $kontext = trim(preg_replace('/\s+/', ' ', strip_tags(preg_replace('/\[[^\]]*\]/', ' ', mb_scrub(substr($c, max(0, $st[0] - 300), 300))))));
        $h .= '<p>Das Produkt <strong>' . esc_html(self::anzeige_name($alt, $d, $fund)) . '</strong> (<code>' . esc_html($alt) . '</code>) steht weniger als ' . self::NAH . ' Zeichen nach seiner ersten Anzeige noch einmal. Nur die <strong>zweite</strong> Stelle (hinter „… ' . esc_html(mb_substr($kontext, -140)) . '“) wird ersetzt, die erste bleibt. Es wird nichts gespeichert, bevor du „Freigeben“ klickst.</p>';
        $k = self::austausch_kandidaten($alt, self::anzeige_name($alt, $d, $fund), $d, $trotz, $fund, 8); $n = 0; $h .= '<div style="display:flex;flex-wrap:wrap;gap:12px">';
        foreach ($k['liste'] as $cd) {
            if ($n >= 4) { break; }
            if (empty($cd['x']) || empty($cd['ok']) || (int) ($cd['passt'] ?? 0) < 2) { continue; }
            $nn = (string) ($namen[$cd['asin']] ?? ''); [$c2, $ok] = self::wiederholung_ersetzen($c, $alt, (string) $cd['asin'], [(string) ($namen[$alt] ?? '')], $nn);
            if (!$ok) { continue; }
            $n++;
            $h .= '<form method="post" style="border:1px solid #c3c4c7;background:#fff;padding:8px;width:260px">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="umbau"><input type="hidden" name="sab_form" value="wied_los"><input type="hidden" name="sab_id" value="' . $pid . '"><input type="hidden" name="sab_alt" value="' . esc_attr($alt) . '"><input type="hidden" name="sab_neu" value="' . esc_attr((string) $cd['asin']) . '"><input type="hidden" name="sab_hash" value="' . md5($c) . '">'
                . '<div style="display:flex;gap:8px;align-items:center">' . self::mini_bild((string) $cd['asin'], (array) $cd['x']) . '<span><strong>' . esc_html($nn ?: self::kurzname_aus_titel((string) ($cd['x']['titel'] ?? ''))) . '</strong><br><small>Score ' . (int) $cd['score'] . ' · <code>' . esc_html((string) $cd['asin']) . '</code></small></span></div>'
                . '<p style="margin:6px 0 0"><button class="button button-primary">Freigeben und einsetzen</button></p></form>';
        }
        $h .= '</div>';
        if (!$n) { $h .= '<p><em>Keine passende Variante im Bestand gefunden (gleiche Produktart, lieferbar, ohne neue Doppelung). Bitte unter „Austausch“ nach einem anderen Zaun suchen oder die Stelle von Hand ändern.</em></p>'; }
        return $h;
    }

    /** Artikel (Titel), die durch den Tausch ALT => NEU eine Doppelung von NEU bekämen. */
    private static function doppel_durch_tausch(string $alt, string $neu): array {
        global $wpdb; $out = [];
        if (!isset($wpdb)) { return $out; }
        $rows = $wpdb->get_results($wpdb->prepare("SELECT ID, post_title, post_content FROM {$wpdb->posts} WHERE post_status IN ('publish','future') AND post_type IN ('post','page') AND post_content LIKE %s", '%' . $wpdb->esc_like($alt) . '%'), ARRAY_A);
        foreach ((array) $rows as $r) {
            $c = (string) $r['post_content'];
            if (self::doppel_neu($c, str_replace($alt, $neu, $c), $neu)) { $out[] = (string) $r['post_title']; }
        }
        return $out;
    }

    /** Liste im Reiter „Ausfälle und Wartung“: Artikel mit demselben Produkt nebeneinander oder zu nah nacheinander (nach Anwendung der Austausch-Regeln). */
    private static function doppel_html(array $d): string {
        global $wpdb;
        $h = '<h3>Doppelte Produkte</h3><p class="description">Dasselbe Produkt darf nie nebeneinander stehen und nicht zu nah nacheinander (weniger als ' . self::NAH . ' Zeichen Text dazwischen) erscheinen. Geprüft werden alle Artikel samt Austausch-Regeln.</p>';
        if (!isset($wpdb)) { return $h; }
        $regeln = self::tausch_regeln(); $namen = self::produkte(); $zeilen = []; $n = 0; $hfz = []; $hfn = 0; $nm0 = $namen;
        $rows = $wpdb->get_results("SELECT ID, post_title, post_content FROM {$wpdb->posts} WHERE post_status='publish' AND post_type IN ('post','page') AND (post_content LIKE '%amazon.%' OR post_content LIKE '%[amz\\_%' OR post_content LIKE '%[aawp%' OR post_content LIKE '%[amazon %')", ARRAY_A);
        foreach ((array) $rows as $r) {
            $c = (string) $r['post_content'];
            for ($i = 0; $i < 3 && $regeln; $i++) { $c = strtr($c, $regeln); }
            foreach (self::haufen_funde($c) as $f) {
                $hfn++;
                if (count($hfz) < 60) { $hfz[] = '<tr><td><a href="' . esc_url(admin_url('post.php?post=' . (int) $r['ID'] . '&action=edit')) . '">' . esc_html((string) $r['post_title']) . '</a></td><td><strong style="color:#a00">' . (int) $f['anzahl'] . ' Produkte</strong> am Stück (ab Zeichen ' . (int) $f['pos'] . ' im Text) <a class="button button-small" href="' . esc_url(add_query_arg(['page' => 'sab-umbau', 'entzerren' => (int) $r['ID']], admin_url('admin.php'))) . '">Entzerren (Vorschau)</a><details><summary>Aufteilung ansehen</summary>' . self::haufen_plan_html(self::haufen_plan($c, $f, $d), $nm0, $d) . '</details></td></tr>'; }
            }
            foreach (self::doppel_funde($c) as $f) {
                $n++;
                if (count($zeilen) < 60) {
                    $name = ($namen[$f['asin']] ?? '') ?: (string) ($d['items'][$f['asin']]['titel'] ?? '');
                    $zeilen[] = '<tr><td><a href="' . esc_url(admin_url('post.php?post=' . (int) $r['ID'] . '&action=edit')) . '">' . esc_html((string) $r['post_title']) . '</a></td><td><code>' . esc_html($f['asin']) . '</code> ' . esc_html($name) . '</td><td>'
                        . ($f['art'] === 'nebeneinander' ? '<strong style="color:#a00">nebeneinander</strong> (zwei Spalten derselben Tabelle)' : '<strong style="color:#b36b00">zu nah</strong> (nur ' . (int) $f['abstand'] . ' Zeichen Abstand) <a class="button button-small" href="' . esc_url(add_query_arg(['page' => 'sab-umbau', 'wied' => (int) $r['ID'], 'wasin' => $f['asin']], admin_url('admin.php'))) . '#sab-wied">Wiederholung ersetzen</a>') . '</td></tr>';
                }
            }
        }
        $hh = '';
        if ($hfz) { $hh = '<h3>Zu viele Produkte auf einem Haufen</h3><p class="description">Mehr als ' . self::HAUFEN_MAX . ' Produktkarten direkt hintereinander (weniger als ' . self::HAUFEN_TEXT . ' Zeichen Text dazwischen). Besser: Blöcke mit höchstens ' . self::HAUFEN_MAX . ' Produkten, dazwischen Text. Mehrere Zweierblöcke lassen sich später im A/B-Test gegeneinander antreten.</p><table class="widefat striped"><thead><tr><th>Artikel</th><th>Karten am Stück</th></tr></thead><tbody>' . implode('', $hfz) . '</tbody></table>' . ($hfn > 60 ? '<p class="description">Es werden nur die ersten 60 gezeigt.</p>' : ''); }
        if (!$n) { return $h . '<p style="color:#1a7f37"><strong>Keine Doppelungen gefunden.</strong></p>' . $hh; }
        return $hh . $h . '<p><strong style="color:#a00">' . $n . ' Doppelung(en) gefunden.</strong> Bitte im Artikel eines der beiden Produkte durch ein anderes ersetzen.</p><table class="widefat striped"><thead><tr><th>Artikel</th><th>Produkt</th><th>Problem</th></tr></thead><tbody>' . implode('', $zeilen) . '</tbody></table>' . ($n > 60 ? '<p class="description">Es werden nur die ersten 60 gezeigt.</p>' : '');
    }

    // ---------- Liste „Amazon kennt das Produkt nicht (mehr)“ mit Seiten und Ersatzvorschlag aus den Favoriten ----------

    /** Stichwort-Arten: gleiche Art in altem und neuem Namen heißt „passt“ (de/es/en). */
    const ARTEN = [
        'Kupfer' => 'kupfer|cobre|copper', 'Zaun/Barriere' => 'zaun|valla|cerca|fence|barrier|barrera', 'Elektro' => 'elektr|eléctric|electric',
        'Falle/Köder' => 'falle|trampa|trap|cebo|köder|bait', 'Granulat/Pellets' => 'granul|pellet', 'Solar' => 'solar', 'Nematoden' => 'nematod',
        'Handschuhe' => 'guante|handschuh|glove', 'Licht' => 'lampe|licht|luz|light|linterna', 'Hochbeet' => 'hochbeet|bancal|raised bed|jardinera',
        'Spray' => 'spray|repelente|abwehr', 'Zecken' => 'zecken|garrapat|\btick\b', 'Insekten' => 'insekten|insecto|bienen|abeja|\bbee\b', 'Vögel' => 'vogel|vögel|\bave\b|aves|bird', 'Teich' => 'teich|estanque|pond',
    ];

    private static function arten(string $text): array {
        $out = [];
        foreach (self::ARTEN as $name => $re) { if (preg_match('/(?:' . $re . ')/iu', $text)) { $out[] = $name; } }
        return $out;
    }

    /** Werkstoffe, die ein Text nennt (Metall, Plastik, Kupfer, Holz, Glas, Keramik). */
    private static function materialien(string $text): array {
        $m = ['Metall' => 'metal|metál|acero|galvaniz|stahl|eisen|aluminio|aluminium|zink|inox', 'Plastik' => 'plást|plast|pvc|kunststoff|polietileno|polypropyl|resina', 'Kupfer' => 'kupfer|cobre|copper', 'Holz' => 'madera|holz|bambú|bambu|wooden', 'Glas' => 'vidrio|glas\\b|cristal', 'Keramik' => 'cerámica|ceramica|keramik|barro|ton\\b'];
        $out = [];
        foreach ($m as $k => $re) { if (preg_match('/(?:' . $re . ')/iu', $text)) { $out[] = $k; } }
        return $out;
    }

    /** Haben zwei Texte ähnliche Wörter (Tippfehler und Beugungen wie „estanque“ / „estaques“)? */
    private static function aehnliche_woerter(string $x, string $y): bool {
        $voll = function (string $t): array { return array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($t)), fn($w) => mb_strlen($w) >= 5)); };
        foreach ($voll($x) as $a) {
            foreach ($voll($y) as $b) {
                $a1 = mb_substr($a, 0, 12); $b1 = mb_substr($b, 0, 12);
                if (levenshtein($a1, $b1) <= (int) floor(min(mb_strlen($a1), mb_strlen($b1)) / 3)) { return true; }
            }
        }
        return false;
    }

    private static function woerter(string $text): array {
        $stop = ['para', 'contra', 'with', 'from', 'pack', 'stück', 'cm', 'set', 'und', 'für', 'mit', 'gegen', 'unidades', 'metros', 'meter'];
        $out = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) as $w) { if (mb_strlen($w) >= 5 && !in_array($w, $stop, true)) { $out[mb_substr($w, 0, 6)] = 1; } }
        return array_keys($out);
    }

    /** Name für die Anzeige: Kurzname, Amazon-Titel, letzter bekannter Titel, Ausfallliste, sonst der Name laut Artikel (Tabellenzeile oder Überschrift). */
    /** Allgemeine Linktexte („Angebot bei Amazon“, „Zum Angebot“, „hier“) sind kein Produktname. */
    const GENERISCHE_WOERTER = ['offer', 'offers', 'angebot', 'angebote', 'zum', 'zur', 'zu', 'das', 'der', 'die', 'bei', 'auf', 'at', 'on', 'in', 'amazon', 'view', 'see', 'check', 'price', 'prices', 'preis', 'preise', 'prüfen', 'ansehen', 'buy', 'now', 'click', 'here', 'hier', 'klick', 'klicken', 'jetzt', 'kaufen', 'bestellen', 'mehr', 'erfahren', 'more', 'details', 'info', 'infos', 'the', 'a', 'an', 'product', 'produkt', 'link', 'button', 'shop', 'get', 'it', 'for', 'für', 'to', 'oferta', 'ofertas', 'en', 'ver', 'precio', 'comprar', 'aquí', 'aqui', 'voir', 'sur', 'prix', 'acheter', 'ici', 'offre', 'offres', 'offerta', 'su', 'vedi', 'prezzo', 'acquista', 'qui', 'com', 'de', 'uk', 'and', 'und'];

    /** Satz direkt vor einem Textlink → Produktbegriff („…you can order fresh sheep wool here.“ → „fresh sheep wool“). Füllwörter am Ende fallen weg; nach Wörtern wie order/kaufen beginnt der Begriff. */
    public static function satz_titel(string $s): string {
        $s = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(preg_replace('/\[[^\]]*\]/', ' ', $s)), ENT_QUOTES, 'UTF-8')));
        $s = preg_replace('/[\s.!?:;,–—-]+$/u', '', $s); $q = preg_split('/[.!?:;—]\s+/u', $s); $s = trim((string) end($q));
        $w = preg_split('/\s+/u', $s, -1, PREG_SPLIT_NO_EMPTY);
        while ($w && preg_match('/^(here|hier|now|jetzt|there|dort|click|klick|link|ici|aquí|aqui|qui|on|at|bei|auf|via|amazon|offer|angebot|the|a|an|and|or|und|oder|der|die|das|ein|eine|el|la|le)$/iu', preg_replace('/[^\p{L}\p{N}.]/u', '', (string) end($w)))) { array_pop($w); }
        $s = implode(' ', $w);
        if (preg_match_all('/\b(?:order|buy|get|find|purchase|shop|kaufen|kauf|bestellen|bestellst|findest|erhältst|erhaeltst|comprar|compras|encuentras|acheter|trouver|trouve|ordina|compra|trovi)\b/iu', $s, $m, PREG_OFFSET_CAPTURE)) {
            $l = end($m[0]); $nach = trim(substr($s, $l[1] + strlen($l[0])));
            $s = $nach !== '' ? $nach : trim(substr($s, 0, $l[1]));
        } else { $s = implode(' ', array_slice($w, -4)); }
        $s = preg_replace('/^(?:the|a|an|some|our|ein|eine|einen|den|das|el|la|los|las|un|una|le|les|des)\s+/iu', '', trim($s));
        return mb_strlen($s) >= 3 ? mb_substr($s, 0, 80) : '';
    }

    public static function generischer_linktext(string $n): bool {
        $t = trim(mb_strtolower(preg_replace('/[^\p{L}\p{N}. ]+/u', ' ', preg_replace('/\.(com|de|co\.uk|fr|it|es|ca|com\.au)\b/i', '', $n))));
        if ($t !== '') {   // Linktext besteht nur aus Aufforderungs-/Amazon-Wörtern (mehrsprachig) oder steht in der eigenen Liste: kein Produkttitel
            foreach (preg_split('/\R/', (string) get_option('sab_generische_linktexte', '')) as $z) { if (trim($z) !== '' && $t === trim(mb_strtolower(preg_replace('/[^\p{L}\p{N}. ]+/u', ' ', $z)))) { return true; } }
            $nur = true; foreach (preg_split('/\s+/u', trim(preg_replace('/\s+/u', ' ', str_replace('.', ' ', $t)))) as $w) { if ($w !== '' && !in_array($w, self::GENERISCHE_WOERTER, true)) { $nur = false; break; } }
            if ($nur) { return true; }
        }
        return (bool) preg_match('/^\s*(?:(?:zum\s+|das\s+)?angebote?(?:\s+(?:bei|auf)\s+amazon)?|(?:bei|auf)\s+amazon(?:\s+ansehen)?|jetzt\s+(?:ansehen|kaufen|bestellen)|hier(?:\s+(?:ansehen|klicken|kaufen))?|mehr\s+erfahren|zum\s+produkt|preis\s+prüfen|preis\s+ansehen|link|amazon)\s*[.:!›»→>]*\s*$/iu', $n);
    }

    /** Zahlen mit Einheit (12 V, 10 Ah, 20 W, 5 l) in einheitlicher Schreibweise („12v“, „10ah“). Nur Verwaltung. @return string[] */
    public static function technik_begriffe(string $t): array {
        $u = ['volt' => 'v', 'v' => 'v', 'ah' => 'ah', 'amperestunde' => 'ah', 'amperestunden' => 'ah', 'mah' => 'mah', 'watt' => 'w', 'w' => 'w', 'liter' => 'l', 'l' => 'l', 'kg' => 'kg', 'ml' => 'ml', 'cm' => 'cm', 'mm' => 'mm'];
        $o = [];
        if (preg_match_all('/(\d+(?:[.,]\d+)?)[\s-]*(volt|amperestunden?|ah|mah|watt|liter|kg|ml|cm|mm|v|w|l)(?![\p{L}])/u', mb_strtolower($t), $m, PREG_SET_ORDER)) { foreach ($m as $x) { $o[$x[1] . $u[$x[2]]] = 1; } }
        return array_keys($o);
    }

    private static function anzeige_name(string $asin, array $d, array $fund): string {
        static $memo = [];
        if (isset($memo[$asin])) { return $memo[$asin]; }
        $n = (string) ((self::produkte()[$asin] ?? '') ?: ($d['items'][$asin]['titel'] ?? ''));
        if ($n === '') { $alt = get_option('sab_alt_' . self::land('') . '_' . $asin); $n = is_array($alt) ? (string) ($alt['titel'] ?? '') : ''; }
        if ($n === '') { $n = (string) (((array) get_option('sab_ausfall', []))[$asin]['name'] ?? ''); }
        if ($n === '' && !empty($fund[$asin])) {
            foreach (array_slice(array_keys($fund[$asin]), 0, 4) as $pid) {
                $post = get_post((int) $pid);
                if ($post) { $n = self::kontext_titel((string) $post->post_content, $asin); if ($n !== '') { break; } }
            }
        }
        if (self::generischer_linktext($n)) { $n = ''; }
        return $memo[$asin] = $n;
    }

    /**
     * Bester Favorit als Ersatz: nur Produkte mit Favoriten-Markierung, die lieferbar sind und zur Art des alten Produkts passen
     * (gleiche Produktgruppe, gleiche Stichwort-Art oder mindestens zwei gemeinsame Wörter). Danach zählen Score, Bewertung, Prime und Lieferzeit.
     * @return array{asin?:string,name?:string,score?:int,chips?:array,warn?:array,fehlt?:string}
     */
    /** Produkte, die Tiere töten oder verletzen (Schneckenkorn, Gift, Fallen) oder Kieselgur/Eierschalen: werden nie empfohlen und tauchen im Austausch nicht auf. */
    private static function toetend(string $t): bool {
        return (bool) preg_match('/molusqui|antilimac|limacida|cebo\b|veneno|metaldeh|fosfato f[eé]rrico|schneckenkorn|schneckengift|slug\s*(?:pellets?|killer|x\b)|ferramol|matababos|mata\s*(?:babosas|caracoles|limacos)|trampa[^.]{0,40}(?:babosa|caracol|limaco)|insecticida|pesticida|plaguicida|herbicida|raticida|rodenticida|diatom|kieselgur/iu', $t);
    }

    /** Welcher Begriff im Namen/Titel hat die Tierschutz-Prüfung ausgelöst? (leer = keiner) */
    private static function toetend_wort(string $a, array $d): string {
        $t = (string) (self::produkte()[$a] ?? '') . ' ' . (string) ($d['items'][$a]['titel'] ?? '');
        return preg_match('/molusqui|antilimac|limacida|cebo\b|veneno|metaldeh|fosfato f[eé]rrico|schneckenkorn|schneckengift|slug\s*(?:pellets?|killer|x\b)|ferramol|matababos|mata\s*(?:babosas|caracoles|limacos)|trampa[^.]{0,40}(?:babosa|caracol|limaco)|insecticida|pesticida|plaguicida|herbicida|raticida|rodenticida|diatom|kieselgur/iu', $t, $m) ? $m[0] : '';
    }

    private static function toetend_asin(string $a, array $d): bool {
        return self::toetend((string) (self::produkte()[$a] ?? '') . ' ' . (string) ($d['items'][$a]['titel'] ?? ''));
    }

    private static function fav_vorschlag(string $alt, string $altname, array $d, array $trotz, array $fund): array {
        $favs = array_keys(array_filter((array) get_option('sab_marken', []), fn($t) => $t === 'fav'));
        if (!$favs) { return ['fehlt' => 'Noch keine Favoriten markiert (Reiter Übersicht).']; }
        $namen = self::produkte(); $items = (array) ($d['items'] ?? []);
        $gruppe = []; foreach (self::gruppen() as $liste) { if (in_array($alt, $liste, true)) { $gruppe = array_merge($gruppe, $liste); } }
        $alt_arten = self::arten($altname); $alt_w = self::woerter($altname);
        if (!$gruppe && $altname === '') { return ['fehlt' => 'Name des alten Produkts unbekannt, bitte selbst eintragen.']; }
        $beste = null;
        foreach ($favs as $a) {
            $x = $items[$a] ?? null;
            if (!$x || $a === $alt) { continue; }
            $x['trotz'] = isset($trotz[$a]);
            $ok = ($x['preis'] ?? '') !== '' && !empty($x['kaufen']) && !empty($x['lieferbar']) && ($x['status'] ?? '') !== 'OUT_OF_STOCK';
            if (!$ok && empty($x['trotz'])) { continue; }
            if (($x['prime'] ?? null) === false) { continue; }
            if (isset($x['sterne']) && $x['sterne'] !== null && (float) $x['sterne'] < 3.9) { continue; }
            $text = ($namen[$a] ?? '') . ' ' . ($x['titel'] ?? '');
            if (self::toetend($text)) { continue; }
            $gl = array_intersect($alt_arten, self::arten($text)); $wl = array_intersect($alt_w, self::woerter($text));
            $passt = ($gruppe && in_array($a, $gruppe, true)) ? 100 : 0;
            $passt += 40 * min(2, count($gl)) + 8 * min(3, count($wl));
            if (!($gruppe && in_array($a, $gruppe, true)) && !$gl && count($wl) < 2) { continue; }
            $sc = self::score($x)[0];
            $rang = $passt + $sc + (($x['prime'] ?? null) === true ? 5 : 0) + (isset($x['liefertage']) && $x['liefertage'] !== null && $x['liefertage'] <= 7 ? 5 : 0) + (isset($x['sterne']) && $x['sterne'] !== null && $x['sterne'] >= 4.2 ? 5 : 0);
            if ($beste === null || $rang > $beste[0]) { $beste = [$rang, $a, $x, $sc, $gl, $wl, $gruppe && in_array($a, $gruppe, true)]; }
        }
        if ($beste === null) { return ['fehlt' => 'Kein passender lieferbarer Favorit gefunden.']; }
        [, $a, $x, $sc, $gl, $wl, $ing] = $beste;
        $chips = [];
        $chips[] = $ing ? 'gleiche Produktgruppe' : ($gl ? 'Art: ' . implode(', ', $gl) : 'gemeinsame Wörter: ' . count($wl));
        $chips[] = isset($x['sterne']) && $x['sterne'] !== null ? str_replace('.', ',', (string) $x['sterne']) . ' ★' . (isset($x['anzahl']) ? ' (' . (int) $x['anzahl'] . ')' : '') : 'Bewertung unbekannt';
        $chips[] = isset($x['prime']) && $x['prime'] !== null ? 'Prime' : 'Prime unbekannt';
        $chips[] = isset($x['liefertage']) && $x['liefertage'] !== null ? 'Lieferzeit Ø ' . str_replace('.', ',', (string) round((float) $x['liefertage'], 1)) . ' Tage' : 'Lieferzeit unbekannt';
        $warn = [];
        if (isset($x['liefertage']) && $x['liefertage'] !== null && $x['liefertage'] > 7) { $warn[] = 'Lieferzeit über 7 Tage'; }
        foreach (($fund[$alt] ?? []) as $pid => $f) {
            if (($fund[$a][$pid]['n'] ?? 0) > 0 && $f['n'] + $fund[$a][$pid]['n'] > 2) { $warn[] = 'Auf „' . ($f['titel'] ?: ('Beitrag ' . $pid)) . '“ gäbe es dann mehr als 2 Links zu diesem Produkt'; }
        }
        return ['asin' => $a, 'name' => ($namen[$a] ?? '') ?: self::kurzname_aus_titel((string) ($x['titel'] ?? '')), 'score' => $sc, 'chips' => $chips, 'warn' => array_slice($warn, 0, 3)];
    }

    /** „Empfehlung von Claude“: Zeilen „ALTE-ASIN | NEUE-ASIN | Begründung“ (Einstellung unter der Liste). */
    private static function claude_empfehlungen(): array {
        $out = [];
        foreach (preg_split('/\R/', (string) get_option('sab_claude_empf', '')) as $z) {
            $t = array_map('trim', explode('|', $z));
            $a = self::asin($t[0] ?? ''); $b = self::asin($t[1] ?? '');
            if (strlen($a) === 10 && strlen($b) === 10) { $out[$a] = [$b, $t[2] ?? '']; }
        }
        return $out;
    }

    private static function weg_html(array $weg, array $d, array $fund, array $trotz, string $dp): string {
        $h = '<h3>Amazon kennt das Produkt nicht (mehr) (' . count($weg) . ')</h3><p class="description">Je Produkt: wie oft es auf wie vielen Seiten steht, und ein Ersatz aus deinen Favoriten (vom Plugin nach Art, Bewertung, Prime und Lieferzeit gewählt). „Freigeben“ schreibt den Tausch dauerhaft in die Artikel (vorher Sicherung, Rückgängig weiter unten).</p>';
        if (!$weg) { return $h . '<p>Keine.</p>'; }
        usort($weg, fn($a, $b) => array_sum(array_column($fund[$b] ?? [], 'n')) <=> array_sum(array_column($fund[$a] ?? [], 'n')));
        $claude = self::claude_empfehlungen(); $namen = self::produkte();
        $h .= '<table class="widefat striped sab-sort2"><thead><tr><th>Produkt</th><th>Vorkommen</th><th>Ersatz und Freigabe</th></tr></thead><tbody>';
        foreach ($weg as $asin) {
            $f = $fund[$asin] ?? []; $n = (int) array_sum(array_column($f, 'n')); $fest = (int) array_sum(array_column($f, 'fest'));
            $name = self::anzeige_name($asin, $d, $fund);
            $li = '';
            foreach ($f as $pid => $x) { $li .= '<li><a href="' . esc_url((string) get_permalink($pid)) . '">' . esc_html($x['titel'] ?: ('Beitrag ' . $pid)) . '</a> (' . (int) $x['n'] . '×' . (!empty($x['fest']) ? ', ' . (int) $x['fest'] . ' fest' : '') . ')</li>'; }
            $liste = !$f ? '–' : (count($f) <= 5 ? '<ul style="margin:4px 0 0">' . $li . '</ul>' : '<details><summary>Seiten anzeigen (' . count($f) . ')</summary><ul style="margin:4px 0 0">' . $li . '</ul></details>');
            $v = self::fav_vorschlag($asin, $name, $d, $trotz, $fund);
            $c = $claude[$asin] ?? null;
            if ($c && self::toetend_asin((string) $c[0], $d)) { $c = null; }
            $vorbelegt = $c ? $c[0] : ($v['asin'] ?? '');
            $vname = $c ? (($namen[$c[0]] ?? '') ?: self::kurzname_aus_titel((string) ($d['items'][$c[0]]['titel'] ?? ''))) : ($v['name'] ?? '');
            $z = '<td>';
            if (!empty($v['asin'])) {
                $nx = $d['items'][$v['asin']] ?? [];
                $z .= '<strong>Vorschlag aus Favoriten:</strong> ' . (!empty($nx['bilder'][0]) ? '<img src="' . esc_url($nx['bilder'][0]) . '" alt="" style="width:36px;height:36px;object-fit:contain;vertical-align:middle;margin-right:4px">' : '')
                    . '<a href="' . esc_url($dp . $v['asin']) . '" target="_blank" rel="noopener"><code>' . esc_html($v['asin']) . '</code></a> ' . esc_html($v['name']) . '<br><small>Score ' . (int) $v['score'] . ' &middot; ' . esc_html(implode(' · ', $v['chips'])) . '</small>';
                foreach ($v['warn'] as $w) { $z .= '<br><small style="color:#a00">' . esc_html($w) . '</small>'; }
            } else { $z .= '<strong>' . esc_html($v['fehlt'] ?? 'Kein Vorschlag.') . '</strong>'; }
            if ($c) { $z .= '<br><strong>Empfehlung von Claude:</strong> <code>' . esc_html($c[0]) . '</code> ' . esc_html($c[1]); }
            $z .= '<form method="post" style="margin-top:6px">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="problem"><input type="hidden" name="sab_form" value="tausch_fest"><input type="hidden" name="sab_alt" value="' . esc_attr($asin) . '">'
                . '<label>Neue ASIN: <input type="text" name="sab_neu" size="12" maxlength="10" placeholder="B0…" value="' . esc_attr($vorbelegt) . '"></label> '
                . '<label>Name im Artikel: <input type="text" name="sab_name" class="regular-text" style="max-width:240px" value="' . esc_attr($vname) . '"></label> '
                . '<button class="button button-primary">Freigeben und in ' . count($f) . ' Artikel schreiben</button></form></td>';
            $h .= '<tr><td><a href="' . esc_url($dp . $asin) . '" target="_blank" rel="noopener"><code>' . esc_html($asin) . '</code></a><br><strong>' . ($name !== '' ? esc_html($name) : '<em>Name unbekannt</em>') . '</strong></td>'
                . '<td><strong>' . $n . '×</strong> auf <strong>' . count($f) . '</strong> Seite' . (count($f) === 1 ? '' : 'n') . ($fest ? '<br><small style="color:#a00">' . $fest . ' davon feste Links (Tausch ersetzt die ASIN im Text; Kurzlinks erst im Reiter „Umbau“)</small>' : '') . $liste . '</td>' . $z . '</tr>';
        }
        $h .= '</tbody></table>' . self::tabellenhilfe();
        $h .= '<details style="margin:10px 0"><summary>Empfehlungen von Claude eintragen</summary><form method="post">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="problem"><input type="hidden" name="sab_form" value="claude_empf">'
            . '<p class="description">Pro Zeile: <code>ALTE-ASIN | NEUE-ASIN | Begründung</code>. Die neue ASIN wird dann beim Produkt vorbelegt.</p><textarea name="sab_claude_empf" rows="4" class="large-text code">' . esc_textarea((string) get_option('sab_claude_empf', '')) . '</textarea><p><button class="button">Speichern</button></p></form></details>';
        return $h;
    }

    /** Listen: Produkte, die Amazon nicht mehr kennt, und Produkte, die nicht verfügbar sind, je mit Artikeln und Häufigkeit. */
    /** Fragt Amazon für eine einzige ASIN ab und gibt Code und rohe Antwort zurück (ohne Zugangsdaten): zeigt, warum ein Produkt „nicht bekannt“ ist. */
    private static function asin_rohantwort(string $eingabe): string {
        $liste = array_slice(array_filter(array_map([__CLASS__, 'asin'], preg_split('/[\s,;]+/', $eingabe))), 0, 10); $asin = implode(', ', $liste);
        $land = self::land(''); $mp = self::LAENDER[$land][0] ?? ''; $token = self::token($land); $tag = self::tag($land);
        if (!$token || !$tag) { return self::zugang_fehler($land, (bool) $token) ?: 'Zugangsdaten oder Partner-ID fehlen.'; }
        $r = self::api_post([
            'timeout' => 15,
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json', 'x-marketplace' => $mp],
            'body'    => wp_json_encode(['itemIds' => array_values($liste), 'itemIdType' => 'ASIN', 'marketplace' => $mp, 'partnerTag' => $tag, 'resources' => ['images.primary.large', 'images.variants.large', 'itemInfo.title', 'offersV2.listings.availability', 'offersV2.listings.price', 'offersV2.listings.isBuyBoxWinner', 'offersV2.listings.merchantInfo', 'offersV2.listings.dealDetails', 'offersV2.listings.type', 'browseNodeInfo.browseNodes', 'browseNodeInfo.websiteSalesRank']]),
        ]);
        if (is_wp_error($r)) { return 'Netzfehler: ' . $r->get_error_message(); }
        return 'Antwort von Amazon für ' . $asin . ' (Code ' . wp_remote_retrieve_response_code($r) . '): ' . mb_substr((string) wp_remote_retrieve_body($r), 0, 4000);
    }

    private static function asin_diag_html(): string {
        return '<hr style="margin:2em 0"><h3>Eine ASIN bei Amazon nachfragen</h3><form method="post">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="problem"><input type="hidden" name="sab_form" value="asin_diag">'
            . '<p class="description">Zeigt die rohe Antwort der Schnittstelle, zum Beispiel für ein Buch, das als „Amazon kennt das Produkt nicht“ gilt.</p>'
            . '<p><input type="text" name="sab_diag_asin" size="40" maxlength="130" placeholder="ASIN (mehrere mit Komma)"> <button class="button">Rohantwort zeigen</button></p></form>';
    }

    private static function problem_html(): string {
        $land = self::land('');
        $d = self::uebersicht_daten($land, !empty($_POST['sab_neu']));
        $namen = self::produkte();
        $h = self::bildfehler_html() . self::name_offen_html() . '<h2>Problemprodukte (' . esc_html($land) . ')</h2>'
           . (get_option('sab_uebersicht_teilfehler', '') !== '' ? '<div class="notice notice-warning inline"><p><strong>Beim letzten Abruf gab es Fehler (einige Produkte fehlen deshalb):</strong> ' . esc_html((string) get_option('sab_uebersicht_teilfehler', '')) . '</p></div>' : '')
           . ((array) get_option('sab_ungueltige_asins', []) ? '<div class="notice notice-info inline"><p>Von Amazon als ungültig abgewiesen: <code>' . esc_html(implode(', ', (array) get_option('sab_ungueltige_asins', []))) . '</code></p></div>' : '')
           . '<p class="description">Stand höchstens 1 Stunde alt. Gezählt werden die Produkte, die per Plugin-Platzhalter (<code>[amz_…]</code>, <code>[aawp]</code>) eingebunden sind, samt allen Stellen in Artikeln dieser Webseite, auch feste Links.</p>'
           . '<form method="post">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="problem"><p><button class="button" name="sab_neu" value="1">Neu laden</button></p></form>';
        $h .= self::stand_hinweis($d);
        if (!empty($d['fehler'])) { return $h . '<div class="notice notice-error inline"><p>' . esc_html($d['fehler']) . '</p></div>'; }
        $weg = []; $nv = []; $uk = []; $wa = []; $trotz = (array) get_option('sab_trotz', []);
        foreach (self::alle_asins() as $asin) {
            $x = $d['items'][$asin] ?? null;
            if (!$x) { if (self::amazon_unbekannt((string) $asin, (array) $d)) { $weg[] = $asin; } continue; }   // fehlt es nur im Zwischenspeicher (frisch getauscht), ist es nicht „unbekannt“
            if (isset(((array) get_option('sab_trotz', []))[$asin])) { continue; }  // von dir als lieferbar bestätigt
            if (($x['preis'] ?? '') === '' && (($x['rang'] ?? null) === null || $x['rang'] > 300000)) { $wa[] = $asin; continue; }   // kein Preis und kein (oder ein sehr schlechter) Rang: wahrscheinlich nicht mehr kaufbar
            if (self::unklar($x)) { $uk[] = $asin; } elseif (empty($x['lieferbar']) || ($x['status'] ?? '') === 'OUT_OF_STOCK') { $nv[] = $asin; }
        }
        $favs = array_keys(array_filter((array) get_option('sab_marken', []), fn($t) => $t === 'fav'));
        // Priorität 1 zusätzlich: Prime-Status trotz Messung unklar (meist nicht mehr oder gerade nicht verfügbar); Priorität 2: Status klar, aber sehr schlechter Score
        $p1x = []; $p2x = []; $grund2 = []; $vorher = array_merge($weg, $nv, $uk, $wa);
        foreach ((array) ($d['items'] ?? []) as $a_ => $x_) {
            $a_ = (string) $a_; if (in_array($a_, $vorher, true) || isset($trotz[$a_])) { continue; }
            if (array_key_exists('manuell_stand', $x_) && ($x_['prime'] ?? null) === null && (($x_['preis'] ?? '') === '' || ($x_['kaufen'] ?? null) === false)) { $p1x[] = $a_; continue; }   // „Prime unklar“ allein ist nur ein Hinweis (Shogen 27.10.): nur mit fehlendem Preis oder ohne Buy Box ein Problem
            $gr_ = [];
            if (self::score($x_)[0] < 35) { $gr_[] = 'sehr schlechter Score (' . self::score($x_)[0] . ')'; }
            if (isset($x_['liefertage']) && $x_['liefertage'] !== null && (float) $x_['liefertage'] > 14) { $gr_[] = 'Lieferzeit ' . round((float) $x_['liefertage']) . ' Tage'; }
            if (isset($x_['sterne']) && $x_['sterne'] !== null && (float) $x_['sterne'] < 3.5) { $gr_[] = 'nur ' . str_replace('.', ',', (string) $x_['sterne']) . ' Sterne'; }
            if ($gr_) { $p2x[] = $a_; $grund2[$a_] = implode(', ', $gr_); }
        }
        $fund = self::fundstellen(array_merge($weg, $nv, $uk, $wa, $favs, $p1x, $p2x));
        $empf = [];
        foreach (array_merge($weg, $wa, $nv, $uk, $p1x, $p2x) as $a) { $e = self::empfehlung($a, (array) ($d['items'] ?? []), $trotz); if ($e) { $empf[$a] = $e; } }
        $dp = 'https://' . self::LAENDER[$land][0] . '/dp/';
        $tabelle = function (array $asins, string $leer, bool $tausch = false) use ($fund, $namen, $dp, $d, $empf): string {
            if (!$asins) { return '<p>' . esc_html($leer) . '</p>'; }
            usort($asins, fn($a, $b) => array_sum(array_column($fund[$b] ?? [], 'n')) <=> array_sum(array_column($fund[$a] ?? [], 'n')));
            $t = '<table class="widefat striped sab-sort2"><thead><tr><th>ASIN / Name</th><th>Stellen gesamt</th><th>Artikel (Anzahl im Artikel)</th>' . ($tausch ? '<th>Empfehlung (hoher Score, gleiche Gruppe/Kategorie)</th><th>Austauschen gegen (neue ASIN)</th>' : '') . '</tr></thead><tbody>';
            foreach ($asins as $asin) {
                $f = $fund[$asin] ?? [];
                $titel = self::anzeige_name($asin, (array) $d, $fund);
                $li = '';
                foreach ($f as $pid => $x) { $li .= '<li><a href="' . esc_url((string) get_permalink($pid)) . '">' . esc_html($x['titel'] ?: ('Beitrag ' . $pid)) . '</a>: ' . (int) $x['n'] . '×' . (!empty($x['fest']) ? ' <span style="color:#a00">(davon ' . (int) $x['fest'] . ' feste Links, noch kein Platzhalter)</span>' : '') . '</li>'; }
                $t .= '<tr><td><a href="' . esc_url($dp . $asin) . '" target="_blank" rel="noopener"><code>' . esc_html($asin) . '</code></a><br><strong>' . esc_html($titel) . '</strong></td>'
                    . '<td>' . (int) array_sum(array_column($f, 'n')) . (array_sum(array_column($f, 'fest')) ? '<br><small style="color:#a00">' . (int) array_sum(array_column($f, 'fest')) . ' davon feste Links: erst im Reiter „Umbau“ umwandeln</small>' : '') . '</td><td>' . ($li ? '<ul style="margin:0">' . $li . '</ul>' : '–') . '</td>'
                    . ($tausch ? '<td>' . (isset($empf[$asin]) ? '<a href="' . esc_url($dp . $empf[$asin][0]) . '" target="_blank" rel="noopener"><code>' . esc_html($empf[$asin][0]) . '</code></a> ' . esc_html($empf[$asin][2]) . '<br><small>Score ' . (int) $empf[$asin][1] . '</small><br><label><input type="checkbox" name="sab_empf[' . esc_attr($asin) . ']" value="' . esc_attr($empf[$asin][0]) . '"> Empfehlung übernehmen</label>' : '–') . '</td>'
                        . '<td><input type="text" name="sab_neu_asin[' . esc_attr($asin) . ']" size="12" maxlength="10" placeholder="B0…"></td>' : '') . '</tr>';
            }
            return $t . '</tbody></table>' . self::tabellenhilfe();
        };
        $faelle = [];
        foreach ($weg as $a) { $faelle[$a] = 'Amazon kennt das Produkt nicht mehr'; }
        foreach ($wa as $a) { $faelle[$a] = 'Kein Preis und kein brauchbarer Rang'; }
        foreach ($nv as $a) { $faelle[$a] = 'Nicht lieferbar'; }
        foreach ($p1x as $a) { $faelle[$a] = 'Priorität 1: Prime-Status unklar, vermutlich nicht verfügbar'; }
        foreach ($p2x as $a) { $faelle[$a] = 'Priorität 2: ' . ($grund2[$a] ?? 'schwach'); }
        $gew = function ($a) use ($fund, $d, $p2x) { $n = array_sum(array_column($fund[$a] ?? [], 'n')); return in_array($a, $p2x, true) ? self::tausch_dringlichkeit((array) $d['items'][$a], self::score((array) $d['items'][$a])[0], self::braucht_bild((string) $a)) * $n : 1000000 + $n; };
        uksort($faelle, fn($a, $b) => $gew($b) <=> $gew($a));
        $h .= self::doppel_html((array) $d) . '<hr style="margin:2em 0">' . self::weg_html($weg, (array) $d, $fund, $trotz, $dp) . '<hr style="margin:2em 0">' . '<p class="description">Der alte Tausch-Assistent ist durch die Austausch-Ansicht ersetzt (dieselbe wie im Reiter „Austausch“):</p>' . self::austausch_html() . '<hr style="margin:2em 0"><h3>Alle Problemprodukte als Liste</h3>';
        $h .= '<form method="post">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="problem"><input type="hidden" name="sab_form" value="tausch_neu">'
;
        $h .= '<h3 style="margin-top:2em">Wahrscheinlich austauschen: kein Preis und kein Verkaufsrang (' . count($wa) . ')</h3><p class="description">Diese Produkte haben weder Preis noch einen brauchbaren Rang (leer oder schlechter als Platz 300.000). Erfahrungsgemäß sind sie bei Amazon nicht mehr kaufbar. Rechts steht, wenn möglich, ein Ersatz mit hohem Score. Bitte vor dem Austausch kurz auf Amazon nachsehen.</p>' . $tabelle($wa, 'Keine.', true);
        $h .= '<h3 style="margin-top:2em">Nicht verfügbar / nicht auf Lager (' . count($nv) . ')</h3>' . $tabelle($nv, 'Keine.', true);
        $h .= '<h3 style="margin-top:2em">Unklar: Amazon meldet „nicht auf Lager“, aber ohne Preis und Verkäufer (' . count($uk) . ')</h3><p class="description">Hier stimmt die Schnittstelle oft nicht mit der Amazon-Seite überein (Beispiel: auf Amazon lieferbar, in der Schnittstelle „No disponible“). Bitte auf Amazon selbst nachsehen. Das Plugin zeigt diese Produkte weiter.</p>' . $tabelle($uk, 'Keine.', true);
        $h .= '<p><button class="button button-primary">Austauschen</button></p></form>';
        return $h;
    }


    // ---------- Tausch-Assistent: Austausch dauerhaft in die Artikel schreiben (mit Sicherung und Rückgängig) ----------

    /** Name des Produkts laut Artikel: Text einer anderen Zeile derselben Tabellenspalte (ohne Link/Bild), sonst fetter Text oder Überschrift kurz davor. */
    public static function kontext_titel(string $c, string $asin): string {
        $q = preg_quote($asin, '#');
        // Karte mit eigenem Titel: [amz_karte asin="…" titel="…"]
        if (preg_match('#\[amz_karte\b[^\]]*\basin="' . $q . '"[^\]]*\btitel="([^"]+)"#i', $c, $m) || preg_match('#\[amz_karte\b[^\]]*\btitel="([^"]+)"[^\]]*\basin="' . $q . '"#i', $c, $m)) { $t = trim(html_entity_decode($m[1])); if ($t !== '') { return $t; } }
        $t = self::kontext_titel_basis($c, $asin);
        if ($t === '' && preg_match('#\[amz_link\b[^\]]*\basin="' . $q . '"[^\]]*\]([^\[]{3,140})\[/amz_link\]#i', $c, $m)) { $t = trim(html_entity_decode(strip_tags($m[1]))); }   // Linktext als letzter Ausweg
        return $t;
    }

    private static function kontext_titel_basis(string $c, string $asin): string {
        $pos = strpos($c, $asin);
        if ($pos === false) { return ''; }
        if (preg_match_all('#<table\b.*?</table>#is', $c, $tm, PREG_OFFSET_CAPTURE)) {
            foreach ($tm[0] as [$t, $off]) {
                if ($pos < $off || $pos > $off + strlen($t)) { continue; }
                preg_match_all('#<tr\b.*?</tr>#is', $t, $rows);
                $ci = null; $zellen = [];
                foreach ($rows[0] as $ri => $row) {
                    preg_match_all('#<t[dh]\b.*?</t[dh]>#is', $row, $cs);
                    foreach ($cs[0] as $i => $cell) { $zellen[$ri][$i] = $cell; if ($ci === null && strpos($cell, $asin) !== false) { $ci = $i; } }
                }
                if ($ci !== null) {
                    foreach ($zellen as $r) {
                        $cell = $r[$ci] ?? '';
                        if ($cell === '' || strpos($cell, $asin) !== false || preg_match('#<a\b|<img\b|\[#i', $cell)) { continue; }
                        $tx = trim(html_entity_decode(strip_tags($cell)));
                        if (mb_strlen($tx) >= 5 && mb_strlen($tx) <= 140 && !preg_match('#https?://#', $tx)) { return $tx; }
                    }
                }
            }
        }
        // Nur der unmittelbare Text vor der Stelle (nie die Überschrift des Absatzes weiter oben): letzter Textblock direkt davor, höchstens zwei Blöcke zurück
        $vor = substr($c, max(0, $pos - 400), min($pos, 400));
        $vor = preg_replace('#(?:\[amz_[^\]]*|<a\b[^>]*)$#i', '', $vor);
        $vor = preg_replace('#<a\b[^>]*>\s*<img\b[^>]*>\s*</a>|\[amz_[a-z]+\b[^\]]*\](?:\[/amz_[a-z]+\])?|<img\b[^>]*>#i', ' ', $vor);
        $bl = array_reverse(preg_split('#</?(?:p|h[1-6]|div|li|td|th|tr|br|figure|figcaption|ul|ol|table)\b[^>]*>#i', $vor));
        $n = 0;
        foreach ($bl as $t) {
            $tx = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $t))));
            if ($tx === '') { continue; }
            if (++$n > 2) { break; }
            if (mb_strlen($tx) >= 5 && mb_strlen($tx) <= 140 && !preg_match('#https?://#', $tx) && !self::generischer_linktext($tx)) { return $tx; }
        }
        return '';
    }

    /** Austausch im Text: ASIN überall (Platzhalter, Links, aawp), dazu der alte Name durch den neuen. @return array{0:string,1:int} [neuer Text, Zahl der Ersetzungen] */
    public static function tausch_text(string $c, string $alt, string $neu, array $alte_namen, string $neuer_name): array {
        $n = substr_count($c, $alt);
        $c = str_replace($alt, $neu, $c);
        if ($neuer_name !== '') {
            // Der Text von Textlinks („Angebot bei Amazon“) bleibt, wie er ist; Namen werden nur außerhalb von Links ersetzt (Tabellen, Überschriften, Fließtext).
            $teile = preg_split('#(<a\b[^>]*>.*?</a>|\[amz_link\b[^\]]*\](?:(?!\[amz_link\b|\[/amz_link\]).)*\[/amz_link\]|\[amz_link\b[^\]]*\])#is', $c, -1, PREG_SPLIT_DELIM_CAPTURE);
            foreach (array_unique(array_filter(array_map('trim', $alte_namen))) as $name) {
                if (mb_strlen($name) < 6 || $name === $neuer_name) { continue; }
                foreach ($teile as $ti => $tt) {
                    if ($ti % 2 === 1 || strpos($tt, $name) === false) { continue; }
                    $n += substr_count($tt, $name); $teile[$ti] = str_replace($name, $neuer_name, $tt);
                }
            }
            $c = implode('', $teile);
        }
        return [$c, $n];
    }

    /**
     * Beim Tausch ALT => NEU: Kurzlinks (amzn.to …) in einer Tabellenspalte, in der ALT steht, werden zu [amz_link] mit NEU,
     * aber nur, wenn der Kurzlink sich eindeutig als ALT auflösen lässt. Zeigt er auf ein anderes Produkt oder lässt er sich nicht auflösen, bleibt er unverändert und es gibt einen Hinweis.
     * @return array{0:string,1:int,2:array} [Text, ersetzte Kurzlinks, Hinweise]
     */
    public static function tausch_kurzlinks(string $c, string $alt, string $neu, string $neuer_name = ''): array {
        $n = 0; $hinweise = [];
        if (!preg_match('#amzn\.to|amzn\.eu|a\.co/|link\.amazon/#i', $c)) { return [$c, 0, []]; }
        $c = preg_replace_callback('#<table\b.*?</table>#is', function ($t) use ($alt, $neu, $neuer_name, &$n, &$hinweise) {
            if (strpos($t[0], $alt) === false || !preg_match_all('#<tr\b.*?</tr>#is', $t[0], $rows)) { return $t[0]; }
            $spalten = []; $asins = [];
            foreach ($rows[0] as $row) {
                if (!preg_match_all('#<t[dh]\b.*?</t[dh]>#is', $row, $cs)) { continue; }
                foreach ($cs[0] as $ci => $cell) {
                    if (strpos($cell, $alt) !== false) { $spalten[$ci] = 1; }
                    foreach (self::zelle_asins($cell) as $u) { $asins[$ci][$u[0]] = 1; }
                }
            }
            if (!$spalten) { return $t[0]; }
            $ci = -1;
            return preg_replace_callback('#<tr\b.*?</tr>#is', function ($rm) use ($spalten, $asins, $alt, $neu, $neuer_name, &$n, &$hinweise) {
                $ci = -1;
                return preg_replace_callback('#<(t[dh])\b([^>]*)>(.*?)</\1>#is', function ($cm) use (&$ci, $spalten, $asins, $alt, $neu, $neuer_name, &$n, &$hinweise) {
                    $ci++;
                    // Titel der Spalte: reiner Text unter dem Bild gehört zum Bildprodukt und bekommt den neuen Namen
                    if (isset($spalten[$ci]) && $neuer_name !== '' && $cm[1] === 'td' && stripos($cm[3], '<a') === false && stripos($cm[3], '<img') === false && strpos($cm[3], '[') === false && ($tx = trim(html_entity_decode(strip_tags($cm[3])))) !== '' && mb_strlen($tx) >= 5 && mb_strlen($tx) <= 140 && $tx !== $neuer_name) {
                        $n++;
                        return '<' . $cm[1] . $cm[2] . '>' . (strpos($cm[3], '<') === false ? esc_html($neuer_name) : preg_replace('#>([^<>]{5,})<#u', '>' . str_replace(['\\', '$'], ['\\\\', '\\$'], esc_html($neuer_name)) . '<', $cm[3], 1)) . '</' . $cm[1] . '>';
                    }
                    if (!isset($spalten[$ci]) || !preg_match('#<a\b[^>]*href=(["\'])(https?://(?:amzn\.to|amzn\.eu|a\.co|link\.amazon)/[^"\']*)\1[^>]*>(.*?)</a>#is', $cm[3], $k)) { return $cm[0]; }
                    if (self::kurz_ok($k[2])) { return $cm[0]; }   // bewusst so gelassen
                    $u = self::umbau_url($k[2], true);
                    if (!$u) {
                        // nicht auflösbar (z. B. Amazon sperrt die Abfrage): steht in der Spalte nur dieses eine Produkt, gehört der Kurzlink zu ihm
                        if (count($asins[$ci] ?? []) !== 1 || !isset($asins[$ci][$alt]) || !self::tag(self::land(''))) { $hinweise[] = 'Kurzlink ' . html_entity_decode($k[2]) . ' ließ sich nicht auflösen, bitte von Hand prüfen'; return $cm[0]; }
                        $u = [self::land(''), $alt];
                        $hinweise[] = 'Kurzlink ' . html_entity_decode($k[2]) . ' ließ sich nicht auflösen, steht aber allein mit ' . $alt . ' in einer Spalte und wurde mitgetauscht';
                    }
                    if ($u[1] !== $alt) { $hinweise[] = 'Kurzlink ' . html_entity_decode($k[2]) . ' zeigte auf ' . $u[1] . ' statt auf das Bildprodukt ' . $alt . ' und wurde nach dem Bild ersetzt'; }   // das Bild bestimmt den Textlink
                    if (!self::tag($u[0])) { return $cm[0]; }
                    $text = trim(html_entity_decode(strip_tags($k[3])));
                    if ($text === '') { return $cm[0]; }
                    $n++;
                    $neuer = '[amz_link asin="' . $neu . '" land="' . $u[0] . '"]' . str_replace(['[', ']'], '', $text) . '[/amz_link]';
                    return '<' . $cm[1] . $cm[2] . '>' . str_replace($k[0], $neuer, $cm[3]) . '</' . $cm[1] . '>';
                }, $rm[0]);
            }, $t[0]);
        }, $c);
        // Textlinks außerhalb von Tabellen: ein Kurzlink direkt unter dem Produkt (gleicher Abschnitt, kein anderes Produkt dazwischen) gehört zu ihm
        $tab = [];
        if (preg_match_all('#<table\b.*?</table>#is', $c, $tm, PREG_OFFSET_CAPTURE)) { foreach ($tm[0] as [$tt, $off]) { $tab[] = [$off, $off + strlen($tt)]; } }
        $re = '#(?:asin|fields|box|link|data-sab-asin)\s*=\s*["\']?([A-Z0-9]{10})\b|/(?:dp|gp/product|product)/([A-Z0-9]{10})\b#i';
        $c = preg_replace_callback('#<a\b[^>]*href=(["\'])(https?://(?:amzn\.to|amzn\.eu|a\.co|link\.amazon)/[^"\']*)\1[^>]*>(.*?)</a>#is', function ($m) use (&$c, $tab, $re, $alt, $neu, &$n, &$hinweise) {
            $pos = strpos($c, $m[0]);
            foreach ($tab as [$ts, $te]) { if ($pos !== false && $pos >= $ts && $pos <= $te) { return $m[0]; } }
            if ($pos === false) { return $m[0]; }
            $vor = substr($c, max(0, $pos - 1200), min($pos, 1200));
            if (($hr = strripos($vor, '<hr')) !== false) { $vor = substr($vor, $hr); }   // nur im selben Abschnitt
            if (!preg_match_all($re, $vor, $am, PREG_SET_ORDER)) { return $m[0]; }
            $last = $am[count($am) - 1]; $nah = strtoupper(($last[1] ?? '') !== '' ? $last[1] : ($last[2] ?? ''));
            if ($nah !== $alt) { return $m[0]; }
            $u = self::umbau_url($m[2], true);
            if ($u && $u[1] !== $alt) { $hinweise[] = 'Textlink ' . html_entity_decode($m[2]) . ' zeigt auf ' . $u[1] . ' statt ' . $alt . ', unverändert gelassen'; return $m[0]; }
            $land = $u ? $u[0] : self::land('');
            if (!self::tag($land)) { return $m[0]; }
            $text = trim(html_entity_decode(strip_tags($m[3])));
            if ($text === '') { return $m[0]; }
            $n++;
            if (!$u) { $hinweise[] = 'Textlink ' . html_entity_decode($m[2]) . ' ließ sich nicht auflösen, steht aber direkt unter ' . $alt . ' und wurde mitgetauscht'; }
            return '[amz_link asin="' . $neu . '" land="' . $land . '"]' . str_replace(['[', ']'], '', $text) . '[/amz_link]';
        }, $c);
        return [$c, $n, array_values(array_unique($hinweise))];
    }

    /** Schreibt den Austausch in alle Artikel (jeweils mit Sicherung). @return array{0:int,1:int,2:string,3:array} [Artikel, Kurzlinks ohne ASIN, Fehler, wegen Doppelung übersprungene Artikel] */
    /** Satz, in dem ein reiner Textlink (ohne Bild) steht: Grenzen im Rohtext, der Satz mit {{LINK}} statt des Links (zum Bearbeiten) und der Link selbst. Null, wenn nicht eindeutig. */
    public static function satz_mit_link(string $c, string $asin, int $ab = 0): ?array {
        $i = strpos($c, $asin, $ab); if ($i === false) { return null; }
        $kopf = substr($c, 0, $i); $a1 = strrpos($kopf, '[amz_link'); $a2 = strrpos($kopf, '<a ');
        if ($a2 !== false && ($a1 === false || $a2 > $a1)) { $e = stripos($c, '</a>', $i); if ($e === false) { return null; } $s = $a2; $e += 4; }
        elseif ($a1 !== false) { $cl = strpos($c, ']', $a1); if ($cl === false) { return null; } $e = $cl + 1; $zu = strpos($c, '[/amz_link]', $e); if ($zu !== false && $zu === strpos($c, '[', $e)) { $e = $zu + 11; } $s = $a1; }
        else { return null; }
        if ($e < $i || $e - $s > 700) { return null; }
        $link = substr($c, $s, $e - $s);
        if (preg_match('/<img|\[amz_(bild|karte|box)/i', $link)) { return null; }
        $grenze = '/(?:[.!?:]\s+|\n|<\/?(?:p|li|br|h[1-6]|div|td|th|tr|ul|ol)\b[^>]*>)/i';
        $v0 = max(0, $s - 500); $vor = substr($c, $v0, $s - $v0); $von = 0;
        if (preg_match_all($grenze, $vor, $m, PREG_OFFSET_CAPTURE)) { $l = end($m[0]); $von = $l[1] + strlen($l[0]); } elseif ($v0 > 0) { return null; }
        $nach = substr($c, $e, 500);
        if (!preg_match($grenze, $nach, $m2, PREG_OFFSET_CAPTURE)) { return null; }
        $bis = $m2[0][1] + (preg_match('/^[.!?:]/', $m2[0][0]) ? 1 : 0);
        $vorn = substr($vor, $von); $hinten = substr($nach, 0, $bis);
        if (preg_match('/<a\s|\[amz_|\[aawp/i', $vorn . $hinten) || trim(strip_tags($vorn . $hinten)) === '') { return null; }
        $tags = [];   // alle anderen Tags/Shortcodes im Satz werden zu ⟦1⟧, ⟦2⟧ …, damit im Textfeld nur Klartext steht
        $mask = function ($t) use (&$tags) { return preg_replace_callback('/<[^>]+>|\[[^\]]+\]/u', function ($m) use (&$tags) { $tags[] = $m[0]; return '⟦' . count($tags) . '⟧'; }, $t); };
        return ['start' => $v0 + $von, 'ende' => $e + $bis, 'link' => $link, 'maske' => $mask($vorn) . '【LINK】' . $mask($hinten), 'ord' => substr_count(substr($c, 0, $s), $asin) + 1, 'linkende' => $e, 'tags' => $tags, 'linktext' => $link[0] === '[' ? trim(strip_tags(preg_match('/\](.*)\[\/amz_link\]$/s', $link, $lt) ? $lt[1] : (preg_match('/\btext\s*=\s*["\']([^"\']*)["\']/i', $link, $lt) ? $lt[1] : ''))) : trim(strip_tags($link))];
    }

    /** Textfeld-Inhalt zurück in Rohtext: 【LINK】 => Link, ⟦n⟧ => ursprüngliches Tag; sonst nichts Fremdes (Eingetipptes < > wird entfernt). Null bei unbekanntem Platzhalter oder wenn der Link nicht genau einmal vorkommt. */
    public static function satz_roh(string $neu, array $sm): ?string {
        if (substr_count($neu, '【LINK】') !== 1 || mb_strlen($neu) > 1500) { return null; }
        $teile = preg_split('/(【LINK】|⟦\d+⟧)/u', $neu, -1, PREG_SPLIT_DELIM_CAPTURE); $o = '';
        foreach ($teile as $t) {
            if ($t === '【LINK】') { $o .= $sm['link']; }
            elseif (preg_match('/^⟦(\d+)⟧$/u', $t, $m)) { $i = (int) $m[1] - 1; if (!isset($sm['tags'][$i])) { return null; } $o .= $sm['tags'][$i]; }
            else { $o .= htmlspecialchars(strip_tags($t), ENT_NOQUOTES, 'UTF-8', false); }
        }
        return $o;
    }

    /** Alle Textlink-Stellen eines Produkts in einem Artikel (höchstens 8), jede mit Satz. @return array<int,array> */
    /** Fundstellen einer ASIN im Artikel als Liste der laufenden Nummern (1, 2 …) des jeweils ersten Vorkommens. Bild- und Textlink direkt nebeneinander (nur Tags/Leerraum dazwischen, kein Absatz-/Listenwechsel) sind eine Stelle. */
    public static function stellen_gruppen(string $c, string $asin): array {
        $ords = []; $ab = 0; $nr = 0; $prev_end = -1; $gruppe_offen = false; $prev_bild = false;
        while (($p = strpos($c, $asin, $ab)) !== false) {
            $nr++; $ab = $p + 1;
            if ($p < $prev_end) { continue; }   // steht im selben Link/Platzhalter wie das vorige Vorkommen
            $kopf = substr($c, 0, $p); $a1 = strrpos($kopf, '[amz_'); $a2 = strrpos($kopf, '<a ');
            $s = $p; $e = $p + strlen($asin);
            if ($a2 !== false && ($a1 === false || $a2 > $a1) && ($z = stripos($c, '</a>', $p)) !== false && $z - $a2 < 900) { $s = $a2; $e = $z + 4; }
            elseif ($a1 !== false && ($cl = strpos($c, ']', $a1)) !== false && $cl >= $p && $cl - $a1 < 600) {
                $s = $a1; $e = $cl + 1;
                if (preg_match('/^\[(amz_[a-z_]+)/', substr($c, $a1, 30), $nm)) { $zu = strpos($c, '[/' . $nm[1] . ']', $e); if ($zu !== false && $zu - $e < 600 && strpos(substr($c, $e, $zu - $e), '[') === false) { $e = $zu + strlen($nm[1]) + 3; } }
            }
            $gleich = false;
            $teil = substr($c, $s, $e - $s); $ist_bild = stripos($teil, '<img') !== false || preg_match('/^\[amz_(?:bild|produkt)\b/', $teil);
            if ($prev_end >= 0 && $s >= $prev_end) {
                $gap = substr($c, $prev_end, $s - $prev_end);
                if (strlen($gap) < 400 && !preg_match('#</p>|<p[\s>]|</?li\b|<h[1-6]\b|</?t[rdh]\b#i', $gap)) {
                    $txt = trim(str_replace("\xC2\xA0", ' ', html_entity_decode(strip_tags(preg_replace('/\[[^\]]*\]/', '', $gap)))));
                    // Bildlink, kurzer Titel (nur durch <br> getrennt), Textlink derselben Zelle: eine Stelle
                    if ($txt === '' || ($prev_bild && !$ist_bild && mb_strlen($txt) <= 160 && !preg_match('#[.!?]\s*$#u', $txt))) { $gleich = true; }
                }
            }
            if (!$gleich) { $ords[] = $nr; }
            $prev_end = $e; $prev_bild = $ist_bild;
        }
        return $ords;
    }

    public static function satz_stellen(string $c, string $asin): array {
        $out = []; $ab = 0; $grp = self::stellen_gruppen($c, $asin);
        while (count($out) < 8 && ($i = strpos($c, $asin, $ab)) !== false) {
            $sm = self::satz_mit_link($c, $asin, $ab);
            if ($sm && $sm['linkende'] > $i) { $g_ = array_filter($grp, fn($x) => $x <= $sm['ord']); $sm['ord'] = $g_ ? max($g_) : $sm['ord']; $out[] = $sm; $ab = $sm['linkende']; } else { $ab = $i + 1; }
        }
        return $out;
    }

    /** eBay-Suchlink als Ausweichlink hinter jeden Textlink des Produkts setzen (nur dort, wo noch keiner steht). Null, wenn nichts zu tun ist. */
    public static function ebay_hinter_textlink(string $c, string $alt, string $term, string $host): ?string {
        $term = trim(str_replace(['"', '[', ']'], '', $term)); if ($term === '') { return null; }
        $ins = ' [amz_ebay asin="' . $alt . '" suche="' . $term . '" host="' . $host . '"]oder bei eBay[/amz_ebay]';
        $st = self::satz_stellen($c, $alt); $n = 0;
        for ($k = count($st) - 1; $k >= 0; $k--) {
            $e = $st[$k]['linkende'];
            if (preg_match('/^\s*\[amz_ebay\b/', substr($c, $e, 40))) { continue; }
            $c = substr($c, 0, $e) . $ins . substr($c, $e); $n++;
        }
        return $n ? $c : null;
    }

    /** eBay-Host passend zum Amazon-Land dieser Webseite. */
    public static function ebay_host(string $land): string {
        return ['de' => 'www.ebay.de', 'fr' => 'www.ebay.fr', 'es' => 'www.ebay.es', 'it' => 'www.ebay.it', 'co.uk' => 'www.ebay.co.uk', 'com' => 'www.ebay.com'][$land] ?? 'www.ebay.de';
    }

    /** Bearbeitbarer Satz auf der Austausch-Karte (nur bei Textlinks). */
    private static function satz_feld_html(string $alt, array $f, string $name = ''): string {
        $felder = ''; $j = 0; $land = self::land(''); $erster = null;
        foreach (array_slice(array_keys($f), 0, 3, true) as $pid) {
            $post = get_post((int) $pid); if (!$post) { continue; }
            $st = self::satz_stellen((string) $post->post_content, $alt); if (!$st) { continue; }
            $erster = $erster ?? $st[0];
            foreach ($st as $k => $sm) {
                if (++$j > 6) { break 2; }
                $v = add_query_arg(['sab_zeige' => $alt, 'sab_nr' => $sm['ord']], (string) get_permalink((int) $post->ID));
                $e = admin_url('post.php?post=' . (int) $post->ID . '&action=edit&sab_zeige=' . rawurlencode($alt) . '&sab_nr=' . (int) $sm['ord']);
                $felder .= '<div style="margin:8px 0 0"><strong>' . (count($st) > 1 || count($f) > 1 ? 'Stelle ' . ($k + 1) . ' von ' . count($st) . ' in „' . esc_html(get_the_title($post)) . '“' : 'Satz im Artikel „' . esc_html(get_the_title($post)) . '“') . '</strong> (Link: „' . esc_html($sm['linktext']) . '“) '
                    . '<a class="button button-small" href="' . esc_url($v) . '" target="_blank" rel="noopener">ansehen ↗</a> <a class="button button-small" href="' . esc_url($e) . '" target="_blank" rel="noopener">im Editor bearbeiten ↗</a>'
                    . '<div style="margin:6px 0 0;padding:6px 8px;background:#fff;border:1px solid #dcdcde;border-radius:4px;font-size:15px;line-height:1.5"><small style="color:#50575e">So liest sich der Satz auf der Seite (Link gelb):</small><br>' . preg_replace('/⟦\d+⟧/u', '', str_replace('【LINK】', '<mark style="background:#fff3a3;padding:0 3px">' . esc_html($sm['linktext']) . '</mark>', esc_html($sm['maske']))) . '</div><small style="color:#50575e">Zum Ändern das Feld darunter benutzen. Dort steht der Satz mit Platzhaltern: 【LINK】 = hier sitzt der Link, ⟦1⟧ = Formatierung wie fett oder kursiv.</small><textarea name="sab_satz[' . esc_attr($alt) . '][' . $j . ']" rows="3" style="width:100%;margin-top:4px;font-size:15px;line-height:1.5;background:#fff">' . esc_textarea($sm['maske']) . '</textarea><input type="hidden" name="sab_satz_alt[' . esc_attr($alt) . '][' . $j . ']" value="' . esc_attr($sm['maske']) . '"></div>';
            }
        }
        if ($felder === '') { return ''; }
        $term = self::suchtext_aus_satz($name, $alt, self::text_vor_stelle($alt, $f));
        return '<div style="margin:10px 0 0;max-width:760px;padding:8px 10px;border:1px solid #c5d9ed;border-radius:6px;background:#f6fafe"><strong>Textlink: Satz anpassen</strong><br><small>Der Satz steht hier als Klartext. <strong>【LINK】</strong> ist der Link (sein Text bleibt, wie er ist) und muss genau einmal drinbleiben; ⟦1⟧ … sind Formatierungen, die du stehen lassen oder löschen kannst. Geändert wird nur dieser Satz, vorher wird der Artikel gesichert (Rückgängig).</small>' . $felder
            . '<div style="margin:10px 0 0;padding-top:8px;border-top:1px solid #c5d9ed"><label><strong>eBay als Ausweichlink:</strong> Suchbegriff <input type="text" name="sab_ebay_term[' . esc_attr($alt) . ']" value="' . esc_attr($term) . '" size="30"></label> '
            . '<button class="button" name="sab_ebay_ein" value="' . esc_attr($alt) . '" title="Setzt hinter jeden Textlink dieses Produkts „oder bei eBay“ (eBay-Suche mit Partner-ID); der Artikel wird vorher gesichert">„oder bei eBay“ dahinter einfügen</button> <a class="button" href="' . esc_url('https://' . self::ebay_host($land) . '/sch/i.html?_nkw=' . rawurlencode($term)) . '" target="_blank" rel="noopener">Bei eBay ansehen ↗</a></div></div>';
    }

    /** „oder bei eBay“ hinter die Textlinks des Produkts setzen (mit Sicherung, Rückgängig und Kontrollliste). */
    private static function ebay_einfuegen(string $alt, string $term): string {
        global $wpdb;
        if (!isset($wpdb) || strlen($alt) !== 10 || $term === '') { return 'eBay-Link nicht eingefügt: Suchbegriff fehlt.'; }
        $rows = $wpdb->get_results($wpdb->prepare("SELECT ID, post_title, post_content FROM {$wpdb->posts} WHERE post_status IN ('publish','future') AND post_type IN ('post','page') AND post_content LIKE %s", '%' . $wpdb->esc_like($alt) . '%'), ARRAY_A);
        $log = (array) get_option('sab_tausch_log', []); $id = (string) time() . substr(md5($alt . 'ebay'), 0, 4); $posts = []; $stellen = []; $host = self::ebay_host(self::land(''));
        foreach ((array) $rows as $r) {
            $pid = (int) $r['ID']; $c = (string) $r['post_content']; $nc = self::ebay_hinter_textlink($c, $alt, $term, $host);
            if ($nc === null) { continue; }
            update_post_meta($pid, '_sab_tausch_' . $id, wp_slash($c));
            wp_update_post(['ID' => $pid, 'post_content' => wp_slash($nc)]);
            $posts[$pid] = md5($nc); $stellen[] = ['pid' => $pid, 'titel' => (string) $r['post_title'], 'satz' => '', 'n' => substr_count($nc, '[amz_ebay asin="' . $alt . '"')];
        }
        if (!$posts) { return 'Kein eBay-Link eingefügt: keine Textlinks gefunden oder dort steht schon einer.'; }
        $log[$id] = ['ts' => time(), 'alt' => $alt, 'neu' => $alt, 'name' => 'eBay-Link', 'posts' => $posts, 'hinweise' => []];
        update_option('sab_tausch_log', array_slice($log, -50, null, true), false);
        $kk = (array) get_option('sab_kontrolle', []); $kk[$id] = ['ts' => time(), 'alt' => $alt, 'neu' => $alt, 'stellen' => $stellen]; update_option('sab_kontrolle', array_slice($kk, -20, null, true), false);
        return '„oder bei eBay“ in ' . count($posts) . ' Artikel(n) eingefügt (Suche: ' . $term . '). Siehe Kontrollliste oben.';
    }

    /** Kontrollliste nach einem Tausch: Stellen zum Ansehen im Frontend (Sprung per ?sab_zeige), bei ein bis zwei Stellen mit dem Satz; „Bestätigt“ blendet aus. */
    private static function kontrolle_html(string $tab): string {
        $kk = (array) get_option('sab_kontrolle', []); if (!$kk) { return ''; }
        $h = '<div class="notice notice-success" style="padding:10px 14px"><p><strong>Getauscht, bitte kontrollieren:</strong> Klick auf „Stelle ansehen“ öffnet den Artikel und springt zum Link (rot markiert). Fehlt dort der Link, meldet die Seite oben „Im sichtbaren Text steht … nicht“.</p>';
        foreach (array_reverse($kk, true) as $id => $e) {
            $st = (array) ($e['stellen'] ?? []); $neu = (string) $e['neu'];
            $h .= '<div style="border-top:1px solid #c3e6cb;padding:8px 0"><strong><code>' . esc_html($e['alt']) . '</code> &rarr; <code>' . esc_html($neu) . '</code></strong> <small>(' . count($st) . ' Artikel, ' . esc_html(wp_date('d.m. H:i', (int) $e['ts'])) . ')</small>';
            foreach (array_slice($st, 0, 12) as $x) {
                $url = add_query_arg('sab_zeige', $neu, (string) get_permalink((int) $x['pid']));
                $h .= '<div style="margin:6px 0 0">' . ($x['satz'] !== '' && count($st) <= 2 ? '<div style="background:#fff;border:1px solid #c3e6cb;border-radius:6px;padding:6px 10px;margin:0 0 4px;max-width:760px">' . esc_html($x['satz']) . '<br><small style="color:#555">【…】 = Link mit diesem Text</small></div>' : '')
                    . esc_html($x['titel']) . ' <small>(' . (int) $x['n'] . '×)</small>';
                $ords = array_slice(array_values(array_map('intval', (array) ($x['ords'] ?? []))) ?: range(1, max(1, min(8, (int) $x['n']))), 0, 8); $nst = count($ords);
                foreach ($ords as $k0 => $k) {
                    $lab = $nst > 1 ? 'Stelle ' . ($k0 + 1) . ' ' : '';
                    $h .= ' <span style="display:inline-block;margin:2px 6px 2px 0"><a class="button button-small" href="' . esc_url(add_query_arg('sab_nr', $k, $url)) . '" target="_blank" rel="noopener">' . esc_html($lab) . 'ansehen ↗</a> <a class="button button-small" href="' . esc_url(admin_url('post.php?post=' . (int) $x['pid'] . '&action=edit&sab_zeige=' . rawurlencode($neu) . '&sab_nr=' . $k)) . '" target="_blank" rel="noopener">' . esc_html($lab) . 'bearbeiten ↗</a></span>';
                }
                $h .= '</div>';
            }
            if (count($st) > 12) { $h .= '<p class="description">… und ' . (count($st) - 12) . ' weitere Artikel.</p>'; }
            $h .= '<form method="post" style="margin:8px 0 0">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="' . esc_attr($tab) . '"><input type="hidden" name="sab_form" value="kontrolle_ok"><input type="hidden" name="sab_id" value="' . esc_attr((string) $id) . '"><button class="button button-primary">Kontrolliert, in Ordnung</button> <button class="button" name="sab_form" value="kontrolle_zurueck" style="color:#b32d2e;border-color:#b32d2e" title="Stellt die Artikel auf den Stand vor dem Tausch zurück (nur wenn sie seitdem nicht geändert wurden)">Nicht in Ordnung, rückgängig machen</button></form></div>';
        }
        return $h . '</div>';
    }

    /** Prüft nach einer Satzänderung, ob alle Links noch heil sind. Leer = in Ordnung, sonst der Grund. */
    public static function link_pruefung(string $vorher, string $nachher, string $alt, string $neu): string {
        $b = fn($t) => [substr_count($t, '<a '), substr_count($t, '</a>'), substr_count($t, '[amz_link'), substr_count($t, '[/amz_link]')];
        if ($b($vorher) !== $b($nachher)) { return 'Zahl der Links hat sich geändert'; }
        if (substr_count($nachher, $neu) < substr_count($vorher, $alt)) { return 'neue ASIN steht seltener im Artikel als die alte'; }
        $sms = self::satz_stellen($nachher, $neu); $sos = self::satz_stellen($vorher, $alt);
        if (count($sms) < count($sos)) { return 'im Satz ist kein Textlink mehr zu erkennen'; }
        foreach ($sos as $k => $so) { if (($sms[$k]['linktext'] ?? null) !== $so['linktext']) { return 'der Linktext hat sich geändert'; } }
        foreach ($sms as $sm) {
        $l = $sm['link'];
        $ok = stripos($l, '<a ') === 0 ? (bool) preg_match('#^<a\b[^>]*\bhref\s*=\s*["\']https?://[^"\']+["\'][^>]*>.*</a>$#is', $l) : (bool) preg_match('#^\[amz_link\b[^\]]*\basin\s*=\s*["\']?' . preg_quote($neu, '#') . '#i', $l);
        if (!$ok) { return 'der Link hat keine gültige Adresse'; }
        }
        return '';
    }

    /** Geänderter Satz für den nächsten Tausch: [Satz vorher mit {{LINK}}, Satz nachher]. */
    private static $satz_aend = null;

    private static function tausch_festschreiben(string $alt, string $neu, string $neuer_name, array $alte_namen): array {
        global $wpdb;
        if (!isset($wpdb) || strlen($alt) !== 10 || strlen($neu) !== 10 || $alt === $neu) { return [0, 0, 'Ungültige Angabe.', [], []]; }
        $rows = $wpdb->get_results($wpdb->prepare("SELECT ID, post_title, post_content FROM {$wpdb->posts} WHERE post_status IN ('publish','future') AND post_type IN ('post','page') AND post_content LIKE %s", '%' . $wpdb->esc_like($alt) . '%'), ARRAY_A);
        $log = (array) get_option('sab_tausch_log', []); $id = (string) (time()) . substr(md5($alt . $neu), 0, 4); $posts = []; $stellen = []; $kurz = 0; $doppel = []; $hinweise = [];
        foreach ((array) $rows as $r) {
            $pid = (int) $r['ID']; $c = (string) $r['post_content'];
            $c_arb = $c; $hw0 = [];
            if (self::$satz_aend) {   // Sätze anpassen: nur wenn sie im Artikel noch genau so stehen wie angezeigt
                $ers = []; $benutzt = [];
                foreach (self::satz_stellen($c, $alt) as $sm) {
                    foreach (self::$satz_aend as $pi => $paar) {
                        if (isset($benutzt[$pi]) || $sm['maske'] !== $paar[0]) { continue; }
                        $roh = self::satz_roh($paar[1], $sm);
                        if ($roh !== null) { $ers[] = [$sm, $roh]; $benutzt[$pi] = 1; }
                        break;
                    }
                }
                usort($ers, fn($p1, $p2) => $p2[0]['start'] <=> $p1[0]['start']);
                foreach ($ers as [$sm, $roh]) { $c_arb = substr($c_arb, 0, $sm['start']) . $roh . substr($c_arb, $sm['ende']); $hw0[] = 'Satz angepasst'; }
            }
            [$c1, $nk1, $hw] = self::tausch_kurzlinks($c_arb, $alt, $neu, $neuer_name); $hw = array_merge($hw0, $hw);   // Kurzlinks derselben Spalte mitnehmen, sonst zeigt der Textlink aufs alte Produkt
            [$neu_c, $n] = self::tausch_text($c1, $alt, $neu, $alte_namen, $neuer_name); $n += $nk1;
            if ($n === 0 || $neu_c === $c) { continue; }
            if ($hw0 && ($pf = self::link_pruefung($c, $neu_c, $alt, $neu)) !== '') {   // Satz geändert: danach muss genau derselbe funktionierende Link im Satz stehen, sonst bleibt der Artikel unberührt
                $hinweise[] = ($r['post_title'] ?? ('Beitrag ' . $pid)) . ': NICHT getauscht, Linkprüfung fehlgeschlagen (' . $pf . '). Der Artikel ist unverändert.'; continue;
            }
            if (self::doppel_neu($c, $neu_c, $neu)) { $doppel[] = (string) ($r['post_title'] ?? ('Beitrag ' . $pid)); continue; }   // Schutz: kein Produkt doppelt nebeneinander oder zu nah
            update_post_meta($pid, '_sab_tausch_' . $id, wp_slash($c));
            wp_update_post(['ID' => $pid, 'post_content' => wp_slash($neu_c)]);
            $posts[$pid] = md5($neu_c);
            $sj0 = self::satz_mit_link($neu_c, $neu); $stellen[] = ['pid' => $pid, 'titel' => (string) ($r['post_title'] ?? ('Beitrag ' . $pid)), 'satz' => $sj0 ? mb_substr(str_replace('【LINK】', '【' . $sj0['linktext'] . '】', $sj0['maske']), 0, 300) : '', 'n' => count(self::stellen_gruppen($neu_c, $neu)), 'ords' => self::stellen_gruppen($neu_c, $neu)];
            foreach ($hw as $w) { $hinweise[] = ($r['post_title'] ?? ('Beitrag ' . $pid)) . ': ' . $w; }
            if ($hw0) { foreach (self::satz_stellen($neu_c, $neu) as $sj) { $hinweise[] = 'Satz jetzt: ' . mb_substr(str_replace('【LINK】', '[Link „' . $sj['linktext'] . '“]', $sj['maske']), 0, 260); } }
            $kurz += preg_match_all('#amzn\.to|amzn\.eu|a\.co/|link\.amazon/#i', $neu_c);
        }
        if ($neuer_name !== '' && !isset(self::produkte()[$neu])) { update_option('sab_produkte', trim((string) get_option('sab_produkte', '')) . "\n" . $neu . ' | ' . $neuer_name, false); }
        if ($stellen) { $kk = (array) get_option('sab_kontrolle', []); $kk[$id] = ['ts' => time(), 'alt' => $alt, 'neu' => $neu, 'stellen' => $stellen]; update_option('sab_kontrolle', array_slice($kk, -20, null, true), false); }
        $log[$id] = ['ts' => time(), 'alt' => $alt, 'neu' => $neu, 'name' => $neuer_name, 'posts' => $posts, 'hinweise' => array_slice($hinweise, 0, 6)];
        update_option('sab_tausch_log', array_slice($log, -50, null, true), false);
        delete_transient('sab_alle_asins'); delete_transient('sab_luecke_' . self::land(''));   // die neue ASIN beim nächsten Aufruf der Übersicht neu abfragen
        return [count($posts), $kurz, '', $doppel, $hinweise];
    }

    /** Macht einen festgeschriebenen Austausch rückgängig (nur Artikel, die seitdem nicht von Hand geändert wurden). @return array{0:int,1:int} [zurückgesetzt, übersprungen] */
    private static function tausch_zurueck(string $id): array {
        $log = (array) get_option('sab_tausch_log', []); $e = $log[$id] ?? null; $ok = 0; $skip = 0;
        if (!$e) { return [0, 0]; }
        foreach ((array) $e['posts'] as $pid => $hash) {
            $post = get_post((int) $pid); $alt = get_post_meta((int) $pid, '_sab_tausch_' . $id, true);
            if (!$post || $alt === '' || md5((string) $post->post_content) !== $hash) { $skip++; continue; }
            wp_update_post(['ID' => (int) $pid, 'post_content' => wp_slash((string) $alt)]);
            delete_post_meta((int) $pid, '_sab_tausch_' . $id); $ok++;
        }
        unset($log[$id]); update_option('sab_tausch_log', $log, false); delete_transient('sab_alle_asins');
        return [$ok, $skip];
    }

    /** Fenster oben im Reiter „Ausfälle und Wartung“: je Problemprodukt ein Vorschlag, per Klick freigeben. */
    private static function assistent_html(array $faelle, array $d, array $empf, array $fund, string $dp): string {
        $namen = self::produkte(); $skip = (array) get_option('sab_tausch_skip', []); $log = (array) get_option('sab_tausch_log', []);
        $h = '<h3>Tausch-Assistent</h3><p class="description">Für jedes Produkt, das Amazon nicht mehr kennt oder das nicht lieferbar ist, steht hier ein Vorschlag. „Freigeben“ schreibt den Austausch dauerhaft in die Artikel (ASIN, Link und Name). Vorher wird jeder Artikel gesichert, „Rückgängig“ unten stellt ihn wieder her. Kurzlinks (amzn.to) ohne ASIN bleiben erst unverändert, die wandelt der Reiter „Umbau“ um.</p>';
        $n = 0; $style = 'border:1px solid #c3c4c7;border-radius:8px;background:#fff;padding:10px 14px;margin:0 0 10px;max-width:980px;display:flex;gap:16px;flex-wrap:wrap';
        foreach ($faelle as $alt => $grund) {
            if (isset($skip[$alt])) { continue; }
            $f = $fund[$alt] ?? []; if (!$f) { continue; }
            $x = $d['items'][$alt] ?? null; $e = $empf[$alt] ?? null; $n++;
            $kn = ''; if (!$x) { $pid = (int) array_key_first($f); $post = get_post($pid); $kn = $post ? self::kontext_titel((string) $post->post_content, $alt) : ''; }
            $altname = ($namen[$alt] ?? '') ?: ($x['titel'] ?? '') ?: $kn ?: self::anzeige_name($alt, $d, $fund);
            $li = ''; foreach (array_slice($f, 0, 6, true) as $pid => $a) { $li .= '<li>' . esc_html($a['titel'] ?: ('Beitrag ' . $pid)) . ' (' . (int) $a['n'] . '×)</li>'; }
            $h .= '<form method="post" style="' . $style . '">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="problem"><input type="hidden" name="sab_form" value="tausch_fest"><input type="hidden" name="sab_alt" value="' . esc_attr($alt) . '">'
               . '<div style="flex:1 1 300px"><strong style="color:#a00">' . esc_html($grund) . '</strong><br><a href="' . esc_url($dp . $alt) . '" target="_blank" rel="noopener"><code>' . esc_html($alt) . '</code></a> ' . esc_html($altname) . ($kn !== '' && !$x ? '<br><small>Name laut Artikel: „' . esc_html($kn) . '“</small>' : '') . '<ul style="margin:6px 0 0;font-size:12px">' . $li . (count($f) > 6 ? '<li>… und ' . (count($f) - 6) . ' weitere</li>' : '') . '</ul></div>'
               . '<div style="flex:1 1 320px">';
            if ($e) {
                $nx = $d['items'][$e[0]] ?? [];
                $vorschlag = ($namen[$e[0]] ?? '') ?: $e[2];
                $h .= '<strong>Vorschlag:</strong> ' . (!empty($nx['bilder'][0]) ? '<img src="' . esc_url($nx['bilder'][0]) . '" alt="" style="width:40px;height:40px;object-fit:contain;vertical-align:middle;margin-right:6px">' : '') . '<a href="' . esc_url($dp . $e[0]) . '" target="_blank" rel="noopener"><code>' . esc_html($e[0]) . '</code></a><br><small>Score ' . (int) $e[1] . ' &middot; ' . esc_html((string) ($nx['preis'] ?? '')) . '</small>'
                    . '<input type="hidden" name="sab_neu" value="' . esc_attr($e[0]) . '"><br><label>Name im Artikel: <input type="text" name="sab_name" value="' . esc_attr($vorschlag) . '" class="regular-text" style="max-width:260px"></label>';
            } else {
                $h .= '<strong>Kein Ersatz in der Gruppe gefunden.</strong><br><label>Neue ASIN: <input type="text" name="sab_neu" size="12" maxlength="10" placeholder="B0…"></label><br><label>Name im Artikel: <input type="text" name="sab_name" class="regular-text" style="max-width:260px"></label>';
            }
            $h .= '<p style="margin:8px 0 0"><button class="button button-primary">Freigeben und in ' . count($f) . ' Artikel schreiben</button> <button class="button" name="sab_zurueckstellen" value="1">Zurückstellen</button></p></div></form>';
            if ($n >= 10) { $h .= '<p class="description">Weitere Vorschläge erscheinen, sobald diese erledigt sind.</p>'; break; }
        }
        if ($n === 0) { $h .= '<p>Keine offenen Vorschläge.</p>'; }
        if ($log) {
            $h .= '<h4>Zuletzt festgeschrieben</h4><ul>';
            foreach (array_reverse($log, true) as $id => $e) {
                $h .= '<li><form method="post" style="display:inline">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="problem"><input type="hidden" name="sab_form" value="tausch_zurueck"><input type="hidden" name="sab_id" value="' . esc_attr((string) $id) . '">'
                    . esc_html(gmdate('d.m. H:i', (int) $e['ts'])) . ': <code>' . esc_html($e['alt']) . '</code> &rarr; <code>' . esc_html($e['neu']) . '</code> in ' . count((array) $e['posts']) . ' Artikeln <button class="button button-small">Rückgängig</button></form>' . (!empty($e['hinweise']) ? '<br><small style="color:#8a5a00">' . esc_html(implode(' | ', (array) $e['hinweise'])) . '</small>' : '') . '</li>';
                if (count($log) > 8) { break; }
            }
            $h .= '</ul>';
        }
        return $h;
    }

    private static function markenzellen(string $asin): string {
        $m = (array) get_option('sab_marken', []); $t = $m[$asin] ?? '';
        return '<td><input type="checkbox" name="sab_fav[' . esc_attr($asin) . ']" value="1"' . ($t === 'fav' ? ' checked' : '') . '></td>'
             . '<td><input type="checkbox" name="sab_ers[' . esc_attr($asin) . ']" value="1"' . ($t === 'ers' ? ' checked' : '') . '></td>'
             . '<td><input type="checkbox" name="sab_trotz[' . esc_attr($asin) . ']" value="1"' . (isset(((array) get_option('sab_trotz', []))[$asin]) ? ' checked' : '') . '></td>'
             . '<td data-v="' . (int) (((array) get_option('sab_bonus', []))[$asin] ?? 0) . '"><input type="number" step="1" name="sab_bonus[' . esc_attr($asin) . ']" value="' . (int) (((array) get_option('sab_bonus', []))[$asin] ?? 0) . '" style="width:56px"></td>';
    }

    private static function klickzellen(?array $k): string {
        if (!$k) { return '<td>0</td><td>–</td><td>–</td>'; }
        $z = (int) $k['z']; $n = (int) $k['n'];
        return '<td>' . $n . ' (' . (int) $k['b'] . '/' . (int) $k['x'] . ')</td><td>' . $z . ($n ? ' (' . round(100 * $z / $n) . ' %)' : '') . '</td>'
             . '<td>' . ($z ? esc_html(self::dauer_text((float) $k['m'])) : '–') . '</td>';
    }

    /** Stil der Produkttabelle (Übersicht und Produktgruppen). */
    private static function uebersicht_css(): string {
        return '<style>table.sab-uebers td{vertical-align:middle;padding:6px 8px}table.sab-uebers .sab-thumb{width:48px;height:48px;object-fit:contain;background:#fff;border:1px solid #e3e6e6;border-radius:4px}'
            . 'table.sab-uebers .sab-bal{display:inline-block;height:8px;border-radius:4px;vertical-align:middle;margin-right:6px}table.sab-uebers .sab-zahl{text-align:right;white-space:nowrap}'
            . 'table.sab-uebers .sab-chip{display:inline-block;padding:1px 6px;border-radius:9px;font-size:11px;margin:1px 2px 1px 0;background:#eef0f0;color:#333}table.sab-uebers .sab-chip.ok{background:#e3f4e7;color:#1a7f37}table.sab-uebers .sab-chip.warn{background:#fdf0d8;color:#8a5a00}table.sab-uebers .sab-chip.bad{background:#fbe3e3;color:#a00}'
            . 'table.sab-uebers .sab-name{max-width:340px}table.sab-uebers details summary{cursor:pointer;color:#2271b1}.sab-bildspalte{width:52px;flex:none}.sab-minis{display:flex;gap:2px;margin-top:2px;width:52px;overflow-x:auto;scrollbar-width:none}.sab-minis::-webkit-scrollbar{display:none}table.sab-uebers .sab-mini{width:14px;height:14px;object-fit:contain;border:1px solid #e3e6e6;border-radius:2px;cursor:pointer;background:#fff;flex:none}#sab-gross{position:fixed;z-index:100000;right:16px;top:60px;width:300px;max-width:80vw;background:#fff;border:1px solid #c3c4c7;box-shadow:0 4px 18px rgba(0,0,0,.25);border-radius:8px;padding:6px;display:none}#sab-gross img{width:100%;height:auto;display:block}.sab-spark{display:inline-flex;align-items:flex-end;gap:1px;height:16px}.sab-spark i{display:block;width:3px;border-radius:1px}</style>';
    }

    /** Kopf der Produkttabelle; ohne Markierungsspalten (Fav., Ers., Trotzdem) in den Produktgruppen. */
    private static function uebersicht_kopf(bool $marken): string {
        $k = '<table class="widefat striped sab-sort2 sab-uebers"><thead><tr><th>Produkt</th><th title="0 bis 100, gewichtet nach den Einstellungen im Reiter Score">Score</th><th>Status</th><th class="sab-zahl">Preis</th><th class="sab-zahl" title="Provision laut Kategorie (Tabelle im Reiter Score) und geschätzter Betrag pro Verkauf">Prov.</th><th class="sab-zahl">Rang</th><th class="sab-zahl" title="Auf wie vielen Seiten das Produkt verlinkt ist, wie oft insgesamt (Bild und Button zählen je einmal) und das Maximum auf einer Seite. Kurzlinks ohne ASIN werden nicht erkannt. „allgemein“ = auf mehr als 10 Seiten.">Seiten</th><th class="sab-zahl" title="Priorität zum Tauschen = Dringlichkeit mal Seiten. Dringlichkeit 100: nicht lieferbar, kein Preis oder Prime-Status unklar. Sonst nur bei schwachem Score (unter 35), sehr langer Lieferzeit (über 14 Tage) oder unter 3,5 Sternen. Gute Produkte haben Priorität 0, egal wie oft sie vorkommen.">Prio.</th><th class="sab-zahl" title="Potenzial zum Ausbauen: guter Score (ab 60), lieferbar, Prime geklärt, Lieferzeit höchstens 7 Tage und noch auf wenigen Seiten. Favoriten zählen anderthalbfach. Hohe Zahl = Kandidat, öfter einzubauen.">Potenzial</th><th class="sab-zahl" title="Klicks insgesamt (Bild/Text), wie viele kamen zurück">Klicks</th><th title="Favorit: in Ersatzlisten bevorzugt">Fav.</th><th title="Ersetzen: rutscht ans Ende der Ersatzlisten">Ers.</th><th title="Du hast auf Amazon geprüft: lieferbar, obwohl die Schnittstelle etwas anderes meldet">Trotzdem</th><th title="Fester Bonus (oder Malus) in Punkten, wird zum Score addiert. Für Produkte, die du bewusst nach vorn stellen willst.">Bonus</th><th>Mehr</th></tr></thead><tbody>';
        $k = preg_replace_callback('#<th([^>]*)>(Score|Preis|Prov\.|Rang|Seiten|Prio\.)</th>#u', function ($m) { $key = ['Score' => 'score', 'Preis' => 'preis', 'Prov.' => 'prov', 'Rang' => 'rang', 'Seiten' => 'seiten', 'Prio.' => 'prio'][$m[2]]; return '<th' . $m[1] . ' data-sort="' . $key . '">' . $m[2] . '</th>'; }, $k);   // Klick auf diese Spaltenköpfe sortiert die ganze Liste (nicht nur die sichtbare Seite)
        return $marken ? $k : preg_replace('#<th title="Favorit.*?</th><th title="Ersetzen.*?</th><th title="Du hast.*?</th><th title="Fester Bonus.*?</th>#s', '', $k);
    }

    /** Großansicht der Mini-Bilder (Klick auf ein kleines Bild). */
    private static function bild_gross_html(): string {
        return '<div id="sab-gross"><img alt=""></div><script>(function(){var g=document.getElementById("sab-gross"),i=g&&g.querySelector("img");document.addEventListener("click",function(e){var t=e.target;if(t.classList&&t.classList.contains("sab-mini")){i.src=t.src;g.style.display="block";e.preventDefault()}else if(g&&g.style.display==="block"&&!g.contains(t)){g.style.display="none"}})})();</script>';
    }

    /** Eine Zeile der Produkttabelle mit allen Angaben (Score, Status, Preis, Provision, Rang, Seiten, Prio., Klicks, Details). */
    private static function uebersicht_zeile(string $asin, array $x, array $namen, array $kz, array $fund, string $dp, bool $marken): string {
            [$sc, $sch] = self::score($x);
            $farbe = self::score_farbe($sc);
            $bild = !empty($x['bilder'][0]) ? '<a href="' . esc_url($dp . $asin) . '" target="_blank" rel="noopener"><img class="sab-thumb" loading="lazy" src="' . esc_url($x['bilder'][0]) . '" alt=""></a>' : '';
            $lief = !empty($x['trotz']) ? ['ok', 'lieferbar (geprüft)'] : (empty($x['lieferbar']) ? ['bad', 'kein Angebot'] : (self::unklar($x) ? ['warn', 'unklar'] : (($x['status'] ?? '') === 'OUT_OF_STOCK' ? ['bad', 'nicht auf Lager'] : ((($x['status'] ?? '') === 'IN_STOCK' || ($x['status'] ?? '') === '') ? ['ok', 'lieferbar'] : ['warn', 'eingeschränkt']))));
            $mini = '';
            foreach (array_slice((array) ($x['bilder'] ?? []), 1, 5) as $bu) { $mini .= '<img class="sab-mini" loading="lazy" src="' . esc_url($bu) . '" alt="" title="Klicken: groß ansehen">'; }
            $bild = '<div class="sab-bildspalte">' . $bild . ($mini !== '' ? '<div class="sab-minis">' . $mini . '</div>' : '') . '</div>';
            $chips = '<span class="sab-chip ' . $lief[0] . '">' . esc_html($lief[1]) . '</span>'
                   . '<span class="sab-chip ' . (($x['kaufen'] ?? null) ? 'ok' : 'bad') . '" title="Buy Box = Kaufen-Button">Buy Box ' . (($x['kaufen'] ?? null) === null ? '?' : (($x['kaufen']) ? 'ja' : 'nein')) . '</span>'
                   . (isset($x['lieferkosten']) && $x['lieferkosten'] !== null ? '<span class="sab-chip ' . ((float) $x['lieferkosten'] <= 0 ? 'ok' : ((float) $x['lieferkosten'] >= 5 ? 'bad' : 'warn')) . '" title="Lieferkosten laut Handdaten (8. Feld)">' . ((float) $x['lieferkosten'] <= 0 ? 'Versand gratis' : 'Versand ' . esc_html(str_replace('.', ',', (string) round((float) $x['lieferkosten'], 2)))) . '</span>' : '')
                   . (!empty($x['deal']) ? '<span class="sab-chip warn">' . esc_html($x['deal']) . '</span>' : '')
                   . (isset($x['sterne']) && $x['sterne'] !== null ? '<span class="sab-chip" title="von Hand gepflegt' . (!empty($x['manuell_stand']) ? ', Stand ' . esc_attr($x['manuell_stand']) : '') . '">★ ' . esc_html(str_replace('.', ',', (string) $x['sterne'])) . ' (' . (int) ($x['anzahl'] ?? 0) . ')</span>' : '')
                   . '<span class="sab-chip" style="padding:0;background:none">' . self::prime_zeichen(isset($x['prime']) ? $x['prime'] : null, !empty($x['prime_unsicher'])) . '</span>'
                   . (isset($x['liefertage']) && $x['liefertage'] !== null ? '<span class="sab-chip ' . ($x['liefertage'] <= 3 ? 'ok' : ($x['liefertage'] >= 7 ? 'bad' : 'warn')) . '" title="Lieferzeit Ø in Tagen: ' . esc_attr((string) ($x['liefertext'] ?? '')) . '">&#128666; ' . esc_html(str_replace('.', ',', (string) round((float) $x['liefertage'], 1))) . ' T</span>' : '');
            if (((!isset($x['sterne']) || $x['sterne'] === null) && empty($x['ohne_bewertung']) || ((!isset($x['prime']) || $x['prime'] === null) && empty($x['prime_unsicher'])) || !isset($x['liefertage']) || $x['liefertage'] === null) && !(!empty($x['manuell_stand']) && ($lief[0] === 'bad' || !empty($x['gelesen_weg'])))) { $chips .= '<span class="sab-chip warn" title="Sterne, Prime oder Lieferzeit fehlen (Reiter Score, Handdaten)">Handdaten fehlen</span>'; }
            $sp = (array) ($x['spar'] ?? []);
            $preis = ($x['preis'] ?? '') !== '' ? '<strong>' . esc_html($x['preis']) . '</strong>' . (!empty($sp['streich']) ? '<br><small><s>' . esc_html($sp['streich']) . '</s>' . (isset($sp['prozent']) ? ' &minus;' . (int) $sp['prozent'] . ' %' : '') . '</small>' : '') : '–';
            $rang = isset($x['rang']) && $x['rang'] !== null ? '<span title="' . esc_attr((string) ($x['kategorie'] ?? '')) . '">' . number_format((int) $x['rang'], 0, ',', '.') . '</span>' : '–';
            $fs = $fund[$asin] ?? []; $ns = count($fs); $nl = array_sum(array_column($fs, 'n')); $mx = $fs ? max(array_column($fs, 'n')) : 0;
            $seiten = $ns ? '<span title="' . esc_attr($nl . ' Links insgesamt, höchstens ' . $mx . ' auf einer Seite') . '"><strong>' . $ns . '</strong> <small>(' . $nl . '×' . ($mx > 2 ? ', max <b style="color:#a00">' . $mx . '</b>' : ', max ' . $mx) . ')</small>' . ($ns > 10 ? ' <span class="sab-chip">allgemein</span>' : '') . '</span>' : '0';
            $dr = self::tausch_dringlichkeit($x, $sc, self::braucht_bild((string) $asin)); $prio = $dr * $ns;
            [$pot, $potwhy] = self::potenzial($x, $sc, $ns, (((array) get_option('sab_marken', []))[$asin] ?? '') === 'fav');
            $k = $kz[$asin] ?? null; $nk = $k ? (int) $k['n'] : 0;
            $klick = $k ? '<span title="Bild ' . (int) $k['b'] . ', Text/Button ' . (int) $k['x'] . '; zurückgekommen ' . (int) $k['z'] . '">' . $nk . '</span>' : '0';
            $mehr = '<details><summary>Details</summary><div style="max-width:420px;font-size:12px">'
                  . '<p><a href="' . esc_url($dp . $asin) . '" target="_blank" rel="noopener">' . esc_html($x['titel'] ?? '') . '</a></p>'
                  . '<p>Verkäufer: ' . esc_html(($x['verkaeufer'] ?? '') ?: '–') . '</p>'
                  . '<p>Kategorie: ' . esc_html((string) ($x['kategorie'] ?? '–')) . ($sch !== '' ? '<br><em>' . esc_html($sch) . '</em>' : '') . '</p>'
                  . '<p>' . self::lieferzelle($x) . '</p>'
                  . ((($x['preis'] ?? '') === '' || ($x['verkaeufer'] ?? '') === '') ? '<p style="color:#a00">' . esc_html($x['diag'] ?? '') . '</p><details><summary>Rohdaten</summary><code style="font-size:10px;word-break:break-all">' . esc_html((string) ($x['roh'] ?? '')) . '</code></details>' : '')
                  . '</div></details>';
            return '<tr><td class="sab-name"><div style="display:flex;gap:8px;align-items:center">' . $bild . '<div><strong>' . esc_html(($namen[$asin] ?? '') ?: mb_substr((string) ($x['titel'] ?? ''), 0, 60)) . '</strong><br><small><a href="' . esc_url($dp . $asin) . '" target="_blank" rel="noopener"><code>' . esc_html($asin) . '</code></a></small></div></div></td>'
               . '<td data-v="' . $sc . '" title="Zahl und Balken: Score heute (etwa 0 bis 100). Die kleinen Striche darunter: Score an den einzelnen Tagen, ein Strich pro Tag seit der ersten Prüfung."><span class="sab-bal" style="width:' . max(4, (int) round(max(0, min(100, $sc)) * 0.6)) . 'px;background:' . $farbe . '"></span><strong style="color:' . $farbe . '">' . $sc . '</strong>' . (!empty($x['bonus']) ? ' <small style="color:#6a3fb0;font-weight:700" title="Von Hand vergebener Bonus, im Score enthalten">&#9998; ' . ($x['bonus'] > 0 ? '+' : '') . (int) $x['bonus'] . ' manuell</small>' : '') . (isset($x['k_anteil']) ? '<br><small title="Tage mit Score ab ' . self::K_SCHWELLE . ' in den letzten ' . (int) $x['k_tage'] . ' Tagen">' . round(100 * $x['k_anteil']) . ' % gut</small>' : '') . (!empty($x['k_reihe']) ? '<br>' . self::sparkline($x['k_reihe']) : '') . '</td>'
               . '<td>' . $chips . '</td><td class="sab-zahl" data-v="' . esc_attr((string) (self::preiszahl((string) ($x['preis'] ?? '')) ?? '')) . '">' . $preis . '</td>'
               . self::provisionszelle($x)
               . '<td class="sab-zahl" data-v="' . (isset($x['rang']) ? (int) $x['rang'] : '') . '">' . $rang . '</td><td class="sab-zahl" data-v="' . $ns . '">' . $seiten . '</td><td class="sab-zahl" data-v="' . $prio . '">' . ($prio > 0 ? '<strong style="color:' . ($prio >= 1500 ? '#a00' : ($prio >= 500 ? '#b36b00' : '#555')) . '">' . $prio . '</strong>' : '–') . '</td><td class="sab-zahl" data-v="' . $pot . '" title="' . esc_attr($potwhy) . '">' . ($pot > 0 ? '<strong style="color:#2e7d32">' . $pot . '</strong>' : '–') . '</td><td class="sab-zahl" data-v="' . $nk . '">' . $klick . '</td>'
               . ($marken ? self::markenzellen($asin) : '') . '<td>' . $mehr . '</td></tr>';
    }

    private static function uebersicht_html(): string {
        $land = self::land('');
        if (!empty($_POST['sab_neu'])) { delete_transient('sab_alle_asins'); }   // Produktliste aus den Artikeln neu einlesen
        $d = self::uebersicht_daten($land, !empty($_POST['sab_neu']));
        $namen = self::produkte(); $ja = fn($v) => $v === null ? '?' : ($v ? '<strong style="color:#1a7f37">ja</strong>' : '<strong style="color:#a00">nein</strong>');
        $h = self::bildfehler_html() . '<h2>Übersicht aller Produkte (' . esc_html($land) . ')</h2>'
           . self::hinweis_box('uebersicht', 'uebersicht', '<p class="description">Alle Produkte dieser Seite mit Bild, Preis, Score und Priorität. Der Stand ist höchstens 1 Stunde alt. Bilder kommen hier im Admin direkt von Amazon; Besucher bekommen sie vom eigenen Server. Die Lieferzeit liefert die Amazon-Schnittstelle nicht, sie ist nur auf der Amazon-Seite für deine Lieferadresse sichtbar.</p>'
           . '<p class="description"><strong>Reihenfolge:</strong> nach Priorität = Dringlichkeit (0 bis 100: nicht lieferbar, kein Bild oder Status unklar = 100, sonst schwacher Score, lange Lieferzeit oder wenig Sterne) mal Zahl der Seiten, auf denen das Produkt steht. Oben steht also, was auf vielen Seiten dringend geprüft werden sollte. Spaltenkopf anklicken sortiert anders.</p>'
           . '<p class="description"><strong>Womit es weitergeht:</strong> <em>Austausch</em> ersetzt schwache oder fehlende Produkte, <em>Umbau</em> wandelt alte Links und Widgets (AAWP, feste Links, Kurzlinks) in Plugin-Platzhalter und Karten um, <em>Widgets</em> hat fertige Vorlagen für die Seitenleiste, <em>A/B-Tests</em> vergleicht Produkte oder Blöcke, <em>Statistik</em> zeigt Klicks, Herkunftsland und ausgelieferte Produkte. Rot oben stehen nur fehlende Produktbilder, alles andere sind kleine graue Hinweise.</p>')
           . '<p><button class="button" name="sab_neu" value="1">Übersicht neu laden</button></p>';
        $h .= self::stand_hinweis($d);
        if (!empty($d['fehler'])) { $h .= '<div class="notice notice-warning inline"><p>' . esc_html($d['fehler']) . '</p></div>'; }
        if (!empty($d['fehler'])) { return $h; }
        if (get_option('sab_uebersicht_teilfehler', '') !== '') { $h .= '<div class="notice notice-warning inline"><p><strong>Abruf bei Amazon unvollständig:</strong> ' . esc_html((string) get_option('sab_uebersicht_teilfehler', '')) . '</p></div>'; }
        $kz = self::klickzahlen(); $fund = self::fundstellen(self::alle_asins()); $dp = 'https://' . self::LAENDER[$land][0] . '/dp/';
        $h .= self::uebersicht_css() . '<!--SABPAGER-->';
        $h .= self::uebersicht_kopf(true);
        $zl = []; $unbenutzt = [];
        foreach (self::alle_asins() as $asin) {
            $x = $d['items'][$asin] ?? null; $ns = count($fund[$asin] ?? []);
            if ($x) { $x['trotz'] = isset(((array) get_option('sab_trotz', []))[$asin]); }
            if (!$x && !self::amazon_unbekannt((string) $asin, (array) $d)) {   // Abruf gescheitert oder noch nicht abgefragt: nicht als „unbekannt“ melden
                $zl[] = ['asin' => $asin, 'prio' => 0, 'dr' => 0, 'sc' => -1, 'v' => ['score' => null, 'preis' => null, 'prov' => null, 'rang' => null, 'seiten' => $ns, 'prio' => 0], 'h' => '<tr><td><code>' . esc_html($asin) . '</code> ' . esc_html($namen[$asin] ?? '') . '</td><td colspan="2"><em>Amazon hat noch nicht geantwortet (Abruf wird wiederholt)</em></td><td colspan="7"></td>' . self::markenzellen($asin) . '<td></td></tr>'];
                continue;
            }
            if (!$x) {
                if ($ns === 0) { $unbenutzt[] = $asin; continue; }   // Amazon kennt es nicht und es steht auf keiner Seite: kein Handlungsbedarf, nicht ganz oben anzeigen
                $m_ = self::manuell_daten()[$asin] ?? null;
                $hd = $m_ ? '<span class="sab-chip" title="von Hand gepflegt">Handdaten: ' . ($m_['sterne'] !== null ? '★ ' . esc_html(str_replace('.', ',', (string) $m_['sterne'])) . ($m_['anzahl'] !== null ? ' (' . (int) $m_['anzahl'] . ')' : '') : 'keine Sterne') . ($m_['prime'] ? ' · Prime' : '') . ($m_['liefertext'] !== '' ? ' · ' . esc_html($m_['liefertext']) : ' · nicht lieferbar') . ($m_['provision'] !== null ? ' · ' . esc_html(str_replace('.', ',', (string) $m_['provision'])) . ' %' : '') . '</span>' : '';
                $zl[] = ['asin' => $asin, 'prio' => 100 * $ns, 'dr' => 100, 'sc' => -1, 'v' => ['score' => null, 'preis' => null, 'prov' => $m_['provision'] ?? null, 'rang' => null, 'seiten' => $ns, 'prio' => 100 * $ns], 'h' => '<tr><td><code>' . esc_html($asin) . '</code> ' . esc_html($namen[$asin] ?? '') . '</td><td colspan="2"><strong style="color:#a00">Amazon kennt das Produkt nicht (mehr)</strong> <small>· steht auf ' . $ns . ' Seite(n), im Reiter Austausch ersetzen</small><details><summary style="cursor:pointer;color:#2271b1">Seiten anzeigen</summary>' . self::fundstellen_links($asin, $fund[$asin] ?? []) . '</details></td><td colspan="7">' . $hd . '</td>' . self::markenzellen($asin) . '<td></td></tr>'];
                continue;
            }
            $sc = self::score($x)[0];
            $pr_ = $ns > 0 ? self::tausch_dringlichkeit($x, $sc, self::braucht_bild((string) $asin)) * $ns : ($sc >= 60 ? $sc - 50 : -1);
            $zl[] = ['asin' => $asin, 'v' => ['score' => $sc, 'preis' => self::preiszahl((string) ($x['preis'] ?? '')) ?: null, 'prov' => self::provision_satz($x), 'rang' => isset($x['rang']) && $x['rang'] !== null ? (int) $x['rang'] : null, 'seiten' => $ns, 'prio' => $pr_], 'prio' => $ns > 0 ? self::tausch_dringlichkeit($x, $sc) * $ns : ($sc >= 60 ? $sc - 50 : -1), 'dr' => self::tausch_dringlichkeit($x, $sc), 'sc' => $sc, 'h' => self::uebersicht_zeile($asin, $x, $namen, $kz, $fund, $dp, true)];
        }
        usort($zl, fn($a, $b) => [(int) ($b['dr'] >= 100 && $b['prio'] > 0), $b['prio'], $b['dr'], $a['sc']] <=> [(int) ($a['dr'] >= 100 && $a['prio'] > 0), $a['prio'], $a['dr'], $b['sc']]);   // wichtigste zuerst, dann dringlichste, dann schwächster Score
        $sk = (string) ($_GET['sab_sort'] ?? ''); $sdir = ($_GET['sab_dir'] ?? 'desc') === 'asc' ? 1 : -1;
        if (in_array($sk, ['score', 'preis', 'prov', 'rang', 'seiten', 'prio'], true)) {   // Klick auf den Spaltenkopf: ganze Liste danach sortieren, Produkte ohne Wert immer ans Ende
            $zl = array_map(null, array_keys($zl), $zl);
            usort($zl, function ($a, $b) use ($sk, $sdir) {
                $x = $a[1]['v'][$sk] ?? null; $y = $b[1]['v'][$sk] ?? null;
                if ($x === null && $y === null) { return $a[0] <=> $b[0]; }
                if ($x === null) { return 1; } if ($y === null) { return -1; }
                return ($x <=> $y) * $sdir ?: ($a[0] <=> $b[0]);
            });
            $zl = array_map(fn($p) => $p[1], $zl);
        }
        $gesamt = count($zl);
        $pp = isset($_GET['sab_pp']) ? (string) $_GET['sab_pp'] : '25'; $pp = $pp === 'alle' ? max(1, $gesamt) : (in_array((int) $pp, [10, 25, 50, 100], true) ? (int) $pp : 25);
        $seiten_n = max(1, (int) ceil($gesamt / $pp)); $sn = max(1, min($seiten_n, (int) ($_GET['sab_s'] ?? 1)));
        $zeigen = array_slice($zl, ($sn - 1) * $pp, $pp);
        foreach ($zeigen as $z) { $h .= $z['h']; }
        $liste_feld = '<input type="hidden" name="sab_zeile_liste" value="' . esc_attr(implode(',', array_map(fn($z) => $z['asin'] ?? '', $zeigen))) . '">';
        $pager = '<p class="sab-pager"><strong>' . $gesamt . ' Produkte</strong> · Seite ' . $sn . ' von ' . $seiten_n . ' · ';
        if ($sn > 1) { $pager .= '<a class="button" href="' . esc_url(add_query_arg(['sab_s' => $sn - 1, 'sab_pp' => $_GET['sab_pp'] ?? 25])) . '">‹ zurück</a> '; }
        if ($sn < $seiten_n) { $pager .= '<a class="button" href="' . esc_url(add_query_arg(['sab_s' => $sn + 1, 'sab_pp' => $_GET['sab_pp'] ?? 25])) . '">weiter ›</a> '; }
        $pager .= ' pro Seite: ';
        foreach ([10, 25, 50, 100, 'alle'] as $o) { $pager .= '<a href="' . esc_url(add_query_arg(['sab_s' => 1, 'sab_pp' => $o])) . '"' . (($o === 'alle' ? ($pp === max(1, $gesamt) && ($_GET['sab_pp'] ?? '') === 'alle') : $pp === $o) ? ' style="font-weight:700"' : '') . '>' . $o . '</a> '; }
        $pager .= '</p>';
        $nb = $unbenutzt ? '<details style="margin:10px 0"><summary>' . count($unbenutzt) . ' Produkt(e), die Amazon nicht kennt und die auf keiner Seite stehen (nicht in der Tabelle)</summary><p><code>' . esc_html(implode(', ', $unbenutzt)) . '</code></p><input type="hidden" name="sab_orphan_liste" value="' . esc_attr(implode(',', $unbenutzt)) . '"><p><button class="button" name="sab_orphans" value="1" onclick="return confirm(\'' . count($unbenutzt) . ' verwaiste Produkte aus allen Listen entfernen? Das lässt sich nicht rückgängig machen.\')">Verwaiste Produkte entfernen (' . count($unbenutzt) . ')</button></p></details>' : '';
        $h = str_replace('<!--SABPAGER-->', $pager, $h);
        return $h . '</tbody></table>' . $pager . $liste_feld . $nb . self::bild_gross_html() . self::tabellenhilfe() . '<p><button class="button button-primary" name="sab_markieren" value="1">Markierungen speichern</button> <span class="description">Favorit: wird in Ersatzlisten bevorzugt. Ersetzen: rutscht ans Ende und steht unter „Ausfälle und Wartung“.</span></p>';
    }

    // ---------- Umbau-Werkzeug: feste Amazon-Links in Artikeln => Plugin-Platzhalter ----------

    /** Seite der Gesamtvorschau, die nach einem Tausch wieder geöffnet wird. */
    public static int $vorschau_seite = 1;
    public static int $vorschau_ab = 0;   // Gesamtvorschau: Artikel-ID, ab der die Seite beginnt (hält die Reihenfolge stabil, auch wenn zwischendurch Artikel umgebaut werden)

    const UMBAU_MAX = 30;       // Artikel, die per Häkchen auf einmal umgebaut werden dürfen
    const UMBAU_PROSEITE = 15; // Artikel pro Durchgang (kurz genug gegen Zeitlimits)

    /** Löst einen Amazon-Kurzlink (Sitestripe, amzn.to / amzn.eu) über seine Weiterleitung auf die Produkt-URL auf; Ergebnis 30 Tage gemerkt. */
    /** Zähler: Kurzlinks, die in diesem Durchgang wegen der Abruf-Grenze noch nicht aufgelöst wurden. */
    private static $kurz_offen = 0;

    private static function kurzlink_aufloesen(string $url): string {
        static $budget = 40;   // höchstens so viele neue Abrufe pro Seitenaufruf, der Rest folgt beim nächsten Klick
        $key = 'sab_kurz_' . md5($url);
        $c = get_transient($key);
        if ($c !== false) { return (string) $c; }
        static $start = 0.0; if ($start === 0.0) { $start = microtime(true); }
        if ($budget <= 0 || microtime(true) - $start > 12) { self::$kurz_offen++; return ''; }   // Zeitgrenze: lieber beim nächsten Klick weitermachen als in einen PHP-Zeitfehler zu laufen
        $budget--;
        $r = wp_remote_head($url, ['timeout' => 4, 'redirection' => 6]);
        $ziel = '';
        if (!is_wp_error($r)) {
            $o = $r['http_response'] ?? null;
            $ziel = ($o && method_exists($o, 'get_response_object') && !empty($o->get_response_object()->url)) ? (string) $o->get_response_object()->url : '';
        }
        if ($ziel === '' && !is_wp_error($r)) { return ''; }   // nicht entscheidbar, später nochmal
        set_transient($key, $ziel, $ziel === '' ? DAY_IN_SECONDS : 30 * DAY_IN_SECONDS);
        return $ziel;
    }

    /** Zwei bis drei Kurznamen-Vorschläge aus dem Amazon-Titel (lang mit Merkmal, nur Produktart, Anfang des Titels); innen mit drei Leerzeichen wie in der Produktliste. */
    public static function kurzname_varianten(string $titel): array {
        $a = self::kurzname_aus_titel($titel); if ($a === '') { return []; }
        $v = [str_replace(' | ', '   ', $a)];
        $art = trim(explode(' | ', $a)[0]);
        if ($art !== '' && $art !== $a) { $v[] = $art; }
        $t = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($titel))));
        $t = trim(preg_split('/\s[-–|]\s|[,(]/u', $t, 2)[0]);
        $kurz = trim(implode(' ', array_slice(preg_split('/\s+/u', $t), 0, 5)), " -–|,");
        if ($kurz !== '' && !in_array($kurz, $v, true) && mb_strlen($kurz) <= 45) { $v[] = $kurz; }
        return array_slice(array_values(array_unique($v)), 0, 3);
    }

    /** AAWP-Reste: Bestandteile eines Artikels, die das Plugin NICHT ersetzt (Tabellen, Grids, Bestseller-/Neuheitenlisten, Preis-/Bewertungsfelder, AAWP-Blöcke). Nur lesen. @return string[] */
    public static function aawp_reste(string $c): array {
        $o = [];
        if (preg_match_all('/\[(aawp|amazon)\b([^\]]*)\]/i', $c, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $a = (array) shortcode_parse_atts(trim($x[2]));
                $a = array_change_key_case($a, CASE_LOWER);
                if (!empty($a['box']) || !empty($a['link'])) { continue; }   // Box und Textlink deckt das Plugin ab
                $v = strtolower(trim((string) ($a['value'] ?? 'thumb')));
                if (!empty($a['fields']) && in_array($v, ['thumb', 'image', 'thumbnail', 'title', 'button'], true)) { continue; }
                $o[] = mb_substr($x[0], 0, 120);
            }
        }
        if (preg_match_all('/<!--\s*wp:aawp[^>]*-->/i', $c, $m)) { foreach ($m[0] as $b) { $o[] = 'Block ' . mb_substr($b, 0, 100); } }
        if (preg_match('/class="[^"]*\baawp-?[a-z-]*/i', $c, $m)) { $o[] = 'AAWP-HTML (' . mb_substr($m[0], 0, 80) . ')'; }
        return $o;
    }

    private static function aawp_reste_html(): string {
        global $wpdb;
        $h = '<hr style="margin:2em 0"><h3>AAWP-Reste (Voraussetzung zum Abschalten von AAWP)</h3><p class="description">Nur lesen. Zeigt Artikel mit AAWP-Bestandteilen, die dieses Plugin nicht ersetzt. Sind es null, kann AAWP auf dieser Seite abgeschaltet werden (zusätzlich AAWP-Widgets unter Design &rarr; Widgets und in der Seitenleiste prüfen, die stehen nicht im Artikeltext).</p>';
        if (!isset($wpdb)) { return $h; }
        $rows = (array) $wpdb->get_results("SELECT ID, post_title, post_content FROM {$wpdb->posts} WHERE post_status IN ('publish','future') AND post_type IN ('post','page') AND (post_content LIKE '%[aawp%' OR post_content LIKE '%[amazon %' OR post_content LIKE '%wp:aawp%' OR post_content LIKE '%aawp%')", ARRAY_A);
        $zeilen = ''; $n = 0; $alle = 0;
        foreach ($rows as $r) {
            $res = self::aawp_reste((string) $r['post_content']); $alle++;
            if (!$res) { continue; }
            $n++;
            $zeilen .= '<tr><td><a href="' . esc_url(admin_url('post.php?post=' . (int) $r['ID'] . '&action=edit')) . '" target="_blank" rel="noopener">' . esc_html($r['post_title'] ?: ('Beitrag ' . $r['ID'])) . '</a></td><td>' . count($res) . '</td><td><code>' . esc_html(implode(' | ', array_slice($res, 0, 4))) . '</code></td></tr>';
        }
        $h .= '<p><strong>' . $alle . ' Artikel mit AAWP-Text geprüft, ' . $n . ' mit nicht ersetzten Bestandteilen.</strong></p>';
        if ($zeilen !== '') { $h .= '<table class="widefat striped"><thead><tr><th>Artikel</th><th>Anzahl</th><th>Beispiele</th></tr></thead><tbody>' . $zeilen . '</tbody></table>'; }
        return $h;
    }

    /** ASIN aus eingefügtem Amazon-Link (…/dp/ASIN, …/gp/product/ASIN) oder reiner ASIN. */
    public static function asin_aus_text(string $t): string {
        $t = trim($t);
        if (preg_match('#(?:/dp/|/gp/product/|/gp/aw/d/|/product/|/ASIN/|[?&]asin=)([A-Za-z0-9]{10})(?![A-Za-z0-9])#i', $t, $m)) { return strtoupper($m[1]); }
        if (preg_match('#\b([A-Za-z0-9]{10})\b#', $t, $m) && preg_match('#\d|^B#i', $m[1]) && !preg_match('#^https?$#i', $m[1])) { return strtoupper($m[1]); }
        return self::asin($t);
    }

    /** Hinweis an Kandidaten ohne Handdaten: Score ist vorsichtig zu lesen, die Helfersitzung trägt Prime/Sterne nach. */
    private static function handdaten_hinweis(string $asin): string {
        static $m = null; if ($m === null) { $m = self::manuell_daten(); }
        return isset($m[$asin]) ? '' : '<br><small style="color:#8a5a00" title="Prime, Sterne und Anzahl fehlen noch; sie stehen in der Liste „Handdaten fehlen“ (Reiter Score) für die Helfersitzung">Handdaten ungeprüft, Score vorsichtig lesen</small>';
    }

    /** Kurzname im Stil „Produktart | Merkmal“: Marke und Beiwerk fallen weg, hinten steht eine aussagekräftige Zahl (Anzahl oder Maß), z. B. „Cuello de caracol | Set de 12“. */
    public static function kurzname_aus_titel(string $t): string {
        $t = self::titel_ohne_marke($t);
        if ($t === '') { return ''; }
        $merk = '';
        if (preg_match('/(\d+(?:[.,]\d+)?\s*(?:m|cm|mm)\s*x\s*\d+(?:[.,]\d+)?\s*(?:m|cm|mm)|\d+(?:[.,]\d+)?(?:\s*x\s*\d+(?:[.,]\d+)?){1,2}\s*(?:m|cm|mm))\b/iu', $t, $m)) {
            $merk = str_replace('.', ',', $m[1]);
            if (preg_match('/(?:m|cm|mm)\s*x\s*\d/i', $merk)) { $merk = preg_replace('/(\d)\s*(cm|mm|m)\b/i', '$1 $2', preg_replace('/\s*x\s*/i', ' x ', $merk)); }   // 20 m x 50 mm
            else { $merk = preg_replace('/\s*x\s*/i', 'x', $merk); $merk = preg_replace('/\s*(cm|mm|m)$/i', ' $1', $merk); }   // 196x76x97 cm
        }
        elseif (preg_match('/(\d+)\s*(?:x\s*)?(?:piezas?|pcs|uds?\.?|unidades|st[üu]ck|pack\b)/iu', $t, $m) || preg_match('/(?:set|juego|paquete|lote)\s+(?:de\s+)?(\d+)/iu', $t, $m)) { $merk = 'Set de ' . $m[1]; }
        elseif (preg_match('/(\d+(?:[.,]\d+)?)\s*(kg|ml|cm|mm|m|l|g)\b/iu', $t, $m)) { $merk = str_replace('.', ',', $m[1]) . ' ' . strtolower($m[2]); }
        if ($merk === '') {   // keine Maße oder Stückzahl erkannt: wenigstens irgendeine Zahl aus dem Titel behalten (Alexander 25.10.)
            if (preg_match('/\b(\d{1,3})\s?-?er\b/iu', $t, $m)) { $merk = $m[1] . 'er'; }
            elseif (preg_match('/(?<![\d.,])(\d{1,4}(?:[.,]\d+)?)(?![\d])\s*(%|teilig|-teilig|stück|x)?/iu', $t, $m) && !preg_match('/^(19|20)\d\d$/', $m[1])) { $merk = str_replace('.', ',', $m[1]) . (($m[2] ?? '') !== '' ? ' ' . ltrim($m[2], '-') : ''); }
        }
        $t0 = $t;
        if (preg_match('/^\S+(?:\s\S+)?\s[-–|:]\s+(.{12,})$/u', $t, $mb)) { $t = $mb[1]; }   // „Marke – Produktname“: Marke vorn weglassen
        $w = preg_split('/\s+/u', $t);
        if (count($w) > 2 && (preg_match('/^[A-ZÄÖÜ0-9][A-ZÄÖÜ0-9]+$/u', $w[0]) || preg_match('/^\p{Lu}\p{L}*\p{Lu}/u', $w[0]) || preg_match('/®|™/u', $w[0]))) { array_shift($w); }   // Marke vorn
        $art = trim(preg_split('/\s[-–|]\s|[,:(]|\s(?:con|para|contra|mit|für|de\s+\d)\s/iu', implode(' ', $w), 2)[0]);
        $art = preg_replace('/\s*\d.*$/u', '', $art);   // ab der ersten Zahl abschneiden
        $art = trim(implode(' ', array_slice(preg_split('/\s+/u', $art), 0, 5)), " -–|,");
        if ($art === '') { $art = mb_substr($t, 0, 40); }
        if (count(preg_split('/\s+/u', $art)) < 3) {   // zu kurz, um das Produkt zu beschreiben: mindestens drei Wörter aus dem Titel nehmen
            $lang = trim(preg_replace('/\s*[\d(,].*$/u', '', $t));
            $wl = preg_split('/\s+/u', $lang !== '' ? $lang : $t);
            if (count($wl) < 3) { $wl = preg_split('/\s+/u', $t); }
            $art = trim(implode(' ', array_slice($wl, 0, min(4, max(3, count($wl))))), " -–|,");
        }
        $art = mb_strtoupper(mb_substr($art, 0, 1)) . mb_substr($art, 1);
        return $merk !== '' && stripos($art, $merk) === false ? $art . ' | ' . $merk : $art;
    }

    /** „Kurzname (ASIN)“ für Meldungen: Kurzname aus der Produktliste oder Amazon-Titel aus dem letzten Abruf, sonst nur die ASIN. */
    private static function produktbeschreibung(string $asin): string {
        $n = (string) (self::produkte()[$asin] ?? '');
        if ($n === '') { $st = get_option('sab_uebersicht_stand_' . self::land('')); $n = is_array($st) ? mb_substr((string) ($st['items'][$asin]['titel'] ?? ''), 0, 60) : ''; }
        return ($n !== '' ? '„' . $n . '“ ' : '') . $asin;
    }

    /** Wohin ein Kurzlink führt, lesbar: Produktname aus der Zieladresse (Teil vor /dp/) und ASIN. */
    private static function kurzlink_ziel_text(string $url, string $asin): string {
        $ziel = (string) get_transient('sab_kurz_' . md5(html_entity_decode($url)));
        $slug = preg_match('#amazon\.[a-z.]+/([^/?]+)/(?:dp|gp/product)/#i', $ziel, $m) ? trim(str_replace('-', ' ', rawurldecode($m[1]))) : '';
        return ($slug !== '' ? '„' . mb_substr($slug, 0, 60) . '“ ' : '') . $asin;
    }

    /** Kennt Amazon das Produkt laut letztem Abruf der Übersicht nicht mehr? Ohne Abruf oder bei unbekanntem Land: nein. */
    /** Umbau trotz „Amazon kennt das Produkt nicht“: Standard ja. Die Stelle zeigt dann „Momentan nicht verfügbar“, der Austausch kommt später mit Vorrang. Merkt das Produkt für die Austauschkarte in der Vorschau. */
    private static function weg_umbauen(string $asin): bool {
        if (get_option('sab_weg_umbauen', '1') !== '1') { return false; }
        self::$umbau_weg[$asin] = 1;
        self::$umbau_info[] = 'Produkt ' . $asin . ' kennt Amazon nicht mehr: wird trotzdem umgebaut und zeigt „Momentan nicht verfügbar“, bitte danach mit Vorrang tauschen';
        return true;
    }

    private static function asin_weg(string $asin, string $land): bool {
        $mp = self::land('');
        $st = get_option('sab_uebersicht_stand_' . ($land !== '' ? $land : $mp));
        if (is_array($st) && !empty($st['items']) && !isset($st['items'][$asin]) && time() - (int) ($st['ts'] ?? 0) < 2 * HOUR_IN_SECONDS && in_array($asin, self::alle_asins(), true)) { return true; }   // beim letzten Abruf mitgefragt, aber nicht zurückgekommen
        $alt = get_option('sab_alt_' . ($land !== '' ? $land : $mp) . '_' . $asin);
        return is_array($alt) && !empty($alt['weg']);   // beim Anzeigen als „nicht mehr bekannt“ erkannt
    }

    private static function umbau_url(string $url, bool $aufloesen = false): ?array {
        $url = html_entity_decode($url);
        if ($aufloesen && preg_match('#^https?://(?:amzn\.to|amzn\.eu|a\.co|link\.amazon)/#i', $url)) { $url = self::kurzlink_aufloesen($url); if ($url === '') { return null; } }
        if (!preg_match('#^https?://(?:www\.)?amazon\.(es|de|fr|it|co\.uk|com)/(?:[^?\#]*/)?(?:dp|gp/product)/([A-Za-z0-9]{10})#i', $url, $u)) { return null; }
        return [strtolower($u[1]), strtoupper($u[2])];
    }

    /**
     * Tabellen: Lässt sich ein Kurzlink nicht als Produkt auflösen, steht aber in derselben Spalte (z. B. Zeilen darüber) ein Bild-/Textlink
     * mit /dp/ASIN, wird der Kurzlink auf diese Produktadresse gesetzt. Gibt es in der Spalte mehrere verschiedene ASINs, bleibt er unverändert.
     */
    private static function kurzlink_spaltenhilfe(string $c): string {
        if (stripos($c, '<table') === false || !preg_match('#amzn\.to|amzn\.eu|a\.co/|link\.amazon/#i', $c)) { return $c; }
        return preg_replace_callback('#<table\b.*?</table>#is', function ($t) {
            if (!preg_match_all('#<tr\b.*?</tr>#is', $t[0], $rows)) { return $t[0]; }
            $spalten = [];   // Spaltennummer => [asin|land => true]
            $zellen = [];
            foreach ($rows[0] as $ri => $row) {
                if (!preg_match_all('#<t[dh]\b.*?</t[dh]>#is', $row, $cs)) { continue; }
                foreach ($cs[0] as $ci => $cell) {
                    $zellen[$ri][$ci] = $cell;
                    foreach (self::zelle_asins($cell) as $k => $u) { $spalten[$ci][$k] = true; }
                }
            }
            $out = $t[0];
            foreach ($zellen as $ri => $cs) {
                foreach ($cs as $ci => $cell) {
                    if (empty($spalten[$ci]) || count($spalten[$ci]) !== 1 || !preg_match('#href=(["\'])(https?://(?:amzn\.to|amzn\.eu|a\.co|link\.amazon)/[^"\']*)\1#i', $cell, $k)) { continue; }
                    if (self::kurz_ok($k[2]) || self::umbau_url($k[2], true)) { continue; }   // bewusst so gelassen oder lässt sich selbst auflösen: nichts ändern
                    [$asin, $land] = explode('|', array_key_first($spalten[$ci]));
                    $getauscht = array_merge(array_values(self::tausch_regeln()), array_column((array) get_option('sab_tausch_log', []), 'neu'));
                    if (in_array($asin, $getauscht, true)) { self::$umbau_abw[] = 'Kurzlink ' . html_entity_decode($k[2]) . ' steht in einer Spalte mit getauschtem Produkt ' . $asin . ', nicht geraten'; continue; }   // im Zweifel nichts ersetzen
                    $neu = str_replace($k[2], 'https://www.amazon.' . $land . '/dp/' . $asin, $cell);
                    $out = str_replace($cell, $neu, $out);
                }
            }
            return $out;
        }, $c);
    }

    /**
     * Textlink-Kurzlink, der sich nicht auflösen lässt (z. B. link.amazon), steht aber im selben Abschnitt unter genau einem Produkt:
     * er bekommt die Produktadresse dieses Produkts. Nicht bei getauschten oder bei Amazon unbekannten Produkten.
     */
    private static function kurzlink_nachbarhilfe(string $c): string {
        if (!preg_match('#amzn\.to|amzn\.eu|a\.co/|link\.amazon/#i', $c)) { return $c; }
        $tab = [];
        if (preg_match_all('#<table\b.*?</table>#is', $c, $tm, PREG_OFFSET_CAPTURE)) { foreach ($tm[0] as [$tt, $off]) { $tab[] = [$off, $off + strlen($tt)]; } }
        $re = '#(?:asin|fields|box|link|data-sab-asin)\s*=\s*["\']?([A-Z0-9]{10})\b|/(?:dp|gp/product|product)/([A-Z0-9]{10})\b#i';
        $orig = $c; $getauscht = array_merge(array_values(self::tausch_regeln()), array_column((array) get_option('sab_tausch_log', []), 'neu'));
        return preg_replace_callback('#<a\b([^>]*)href=(["\'])(https?://(?:amzn\.to|amzn\.eu|a\.co|link\.amazon)/[^"\']*)\2([^>]*)>(.*?)</a>#is', function ($m) use ($orig, $tab, $re, $getauscht) {
            $pos = strpos($orig, $m[0]);
            if ($pos === false) { return $m[0]; }
            foreach ($tab as [$ts, $te]) { if ($pos >= $ts && $pos <= $te) { return $m[0]; } }
            if (self::kurz_ok($m[3]) || self::umbau_url($m[3], true)) { return $m[0]; }   // bewusst so gelassen oder lässt sich selbst auflösen: nichts ändern
            $vor = substr($orig, max(0, $pos - 1200), min($pos, 1200));
            if (($hr = strripos($vor, '<hr')) !== false) { $vor = substr($vor, $hr); }
            if (!preg_match_all($re, $vor, $am, PREG_SET_ORDER)) { return $m[0]; }
            $ids = [];
            foreach ($am as $x) { $ids[strtoupper(($x[1] ?? '') !== '' ? $x[1] : ($x[2] ?? ''))] = 1; }
            if (count($ids) !== 1) { return $m[0]; }
            if (stripos($vor, '[amz_link') !== false) { return $m[0]; }   // das Produkt hat schon einen Textlink
            if (preg_match_all('#<a\b[^>]*href=["\'][^"\']*(?:amazon|amzn)[^"\']*["\'][^>]*>(.*?)</a>#is', $vor, $al)) { foreach ($al[1] as $in) { if (stripos($in, '<img') === false) { return $m[0]; } } }   // dito (Bild-Links zählen nicht)
            $asin = (string) array_key_first($ids); $land = self::land('');
            if (in_array($asin, $getauscht, true)) { self::$umbau_abw[] = 'Kurzlink ' . html_entity_decode($m[3]) . ' steht unter getauschtem Produkt ' . $asin . ', nicht geraten'; return $m[0]; }
            if (!self::umbau_ok('weg:' . $asin) && self::asin_weg($asin, $land) && !self::weg_umbauen($asin)) { self::$umbau_keys[self::$umbau_abw[] = 'Kurzlink ' . html_entity_decode($m[3]) . ' steht unter ' . $asin . ', das Amazon nicht mehr kennt, nicht geraten'] = 'weg:' . $asin; self::$umbau_weg[$asin] = 1; return $m[0]; }
            return '<a' . $m[1] . 'href=' . $m[2] . 'https://' . self::LAENDER[$land][0] . '/dp/' . $asin . $m[2] . $m[4] . '>' . $m[5] . '</a>';
        }, $c);
    }

    /** Alle Produkte, die in einer Tabellenzelle stehen: Amazon-Links mit /dp/ASIN, [aawp fields="ASIN"], [amz_bild|produkt|link asin="ASIN"]. @return array<string,array{0:string,1:string}> */
    private static function zelle_asins(string $cell): array {
        $out = [];
        if (preg_match_all('#href=(["\'])(.*?)\1#is', $cell, $hs)) {
            foreach ($hs[2] as $h) { $u = self::umbau_url($h); if ($u) { $out[$u[1] . '|' . $u[0]] = [$u[1], $u[0]]; } }
        }
        if (preg_match_all('#\[(?:aawp|amazon|amz_(?:bild|produkt|link))\b[^\]]*?(?:fields|asin|box)=(?:"|\'|&\#34;|&quot;)?([A-Za-z0-9]{10})\b[^\]]*\]#i', $cell, $ms)) {
            foreach ($ms[1] as $a) { $a = strtoupper($a); $l = self::land(''); $out[$a . '|' . $l] = [$a, $l]; }
        }
        return $out;
    }

    /** Meldungen des Umbaus, warum eine Tabelle bewusst nicht umgebaut wurde (Abweichung zwischen Bild, Link und Titel). */
    public static array $skip_laender = [];
    /** Gründe, warum einzelne Links übersprungen wurden (mit Artikeltitel). */
    public static array $skip_gruende = [];
    public static array $umbau_abw = [];
    /** Hinweise, was der Umbau nach dem Bild angepasst hat (Link oder Titel passten nicht zum Bildprodukt). */
    public static array $umbau_info = [];
    /** Bei Amazon unbekannte ASINs, auf die der Umbau gestoßen ist (die Vorschau bietet dafür einen Ersatz an). */
    public static array $umbau_weg = [];
    /** Meldungstext => Schlüssel, unter dem man die Abweichung als „gewollt, in Ordnung“ bestätigen kann. */
    public static array $umbau_keys = [];

    /** Hat der Redakteur diese Abweichung als gewollt bestätigt? */
    private static function umbau_ok(string $key): bool { return in_array($key, (array) get_option('sab_umbau_ok', []), true); }

    /** Knopf „Ist okay, so lassen“ hinter einer Meldung der Umbau-Vorschau. */
    private static function ok_knopf(string $text, int $id): string {
        $k = self::$umbau_keys[$text] ?? '';
        if ($k === '') { return ''; }
        return ' <form method="post" style="display:inline">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="umbau"><input type="hidden" name="sab_form" value="umbau_ok"><input type="hidden" name="sab_key" value="' . esc_attr($k) . '"><input type="hidden" name="sab_vorschau" value="' . $id . '"><button class="button button-small" title="Das Plugin merkt sich: Diese Abweichung ist gewollt und wird nicht mehr beanstandet.">Ist okay, so lassen</button></form>';
    }

    /** Passt der Titeltext aus dem Artikel erkennbar nicht zum bekannten Namen des Produkts? Unbekannt oder unklar heißt: kein Einwand. */
    private static function titel_passt_nicht(string $asin, string $land, string $text): bool {
        $bekannt = (string) (self::produkte()[$asin] ?? '');
        $st = get_option("sab_uebersicht_stand_{$land}");
        $am = is_array($st) ? (string) ($st['items'][$asin]['titel'] ?? '') : '';
        if ($bekannt === '' && $am === '') { return false; }
        $a1 = self::arten($text); $w1 = self::woerter($text);
        // Material: Metall gegen Plastik, Kupfer, Holz … (eine Metall-Zaunbeschreibung unter einem Plastikzaun ist falsch, auch wenn beides „Zaun“ heißt)
        $m1 = self::materialien($text); $m2 = [];
        foreach (array_filter([$bekannt, $am]) as $name) { $m2 = array_merge($m2, self::materialien($name)); }
        if ($m1 && $m2 && !array_intersect($m1, $m2)) { return true; }
        foreach (array_filter([$bekannt, $am]) as $name) {
            $a2 = self::arten($name); $w2 = self::woerter($name);
            if (($a1 && $a2 && array_intersect($a1, $a2)) || array_intersect($w1, $w2) || self::aehnliche_woerter($text, $name)) { return false; }   // irgendein Name passt
        }
        return count($w1) >= 2;
    }

    /**
     * Produkttabelle (Spalten mit je einer ASIN: Bild, Titel, Kurzlink, eBay) => [amz_karten] mit je einer [amz_karte] pro Spalte.
     * Unverändert bleibt die Tabelle, wenn eine Spalte nicht eindeutig ist oder ein auflösbarer Kurzlink auf ein anderes Produkt zeigt als das Bild.
     */
    private static function tabellen_zu_karten(string $c, string $nur = ''): string {
        if (stripos($c, '<table') === false || get_option('sab_karten_umbau') === '0') { return $c; }
        return preg_replace_callback('#<table\b.*?</table>#is', function ($t) use ($nur) {
            if (!preg_match_all('#<tr\b.*?</tr>#is', $t[0], $rows)) { return $t[0]; }
            $spalten = []; $zellen = [];
            foreach ($rows[0] as $ri => $row) {
                if (!preg_match_all('#<t[dh]\b.*?</t[dh]>#is', $row, $cs)) { return $t[0]; }
                foreach ($cs[0] as $ci => $cell) { $zellen[$ri][$ci] = $cell; foreach (self::zelle_asins($cell) as $k => $u) { $spalten[$ci][$k] = $u; } }
            }
            $anz = max(array_map('count', $zellen) ?: [0]);
            if ($anz < 1 || count($spalten) !== $anz) { return $t[0]; }
            foreach ($spalten as $sp) { if (count($sp) !== 1) { return $t[0]; } }
            $karten = []; $alle = [];
            foreach ($spalten as $sp) { $alle[] = array_values($sp)[0][0]; }
            $dd = array_unique(array_diff_assoc($alle, array_unique($alle))); sort($dd); $dk = 'dupl:' . implode(',', $dd);
            if (count($alle) !== count(array_unique($alle)) && !self::umbau_ok($dk)) { self::$umbau_keys[self::$umbau_abw[] = 'Dasselbe Produkt steht in zwei Spalten einer Tabelle (' . implode(', ', $dd) . '), Tabelle nicht umgebaut'] = $dk; return $t[0]; }
            foreach ($spalten as $ci => $sp) {
                [$asin, $land] = array_values($sp)[0];
                if ($nur !== '' && $asin !== $nur) { return $t[0]; }
                if (!self::tag($land)) { return $t[0]; }
                if (!self::umbau_ok('weg:' . $asin) && self::asin_weg($asin, $land) && !self::weg_umbauen($asin)) { self::$umbau_keys[self::$umbau_abw[] = 'Produkt ' . $asin . ' ist bei Amazon nicht mehr bekannt, Tabelle nicht umgebaut (erst tauschen)'] = 'weg:' . $asin; self::$umbau_weg[$asin] = 1; return $t[0]; }
                $titel = ''; $attr = ''; $text = '';
                foreach ($zellen as $ri => $zs) {
                    $cell = $zs[$ci] ?? ''; $in = preg_replace('#^<t[dh]\b[^>]*>|</t[dh]>$#i', '', $cell);
                    if (strpos($in, '[') !== false && stripos($in, '[amz_') !== false) { return $t[0]; }
                    if (preg_match('#href=(["\'])(https?://(?:amzn\.to|amzn\.eu|a\.co|link\.amazon)/[^"\']*)\1#i', $in, $k)) {
                        if (self::kurz_ok($k[2])) { return $t[0]; }   // bewusst so gelassen
                        $u = self::umbau_url($k[2], true);
                        if ($u && $u[1] !== $asin) { self::$umbau_info[] = 'Bild bestimmt das Produkt: Kurzlink ' . preg_replace('#^https?://#', '', html_entity_decode($k[2])) . ' führte zu ' . self::kurzlink_ziel_text($k[2], $u[1]) . ', die Karte nimmt das Bildprodukt ' . self::produktbeschreibung($asin); }   // der Link folgt dem Bild
                        $text = trim(strip_tags($in));
                        if (preg_match('/^(ver|see|voir|vedi|zum angebot)\b[^<]{0,25}(amazon|angebot|oferta)\W*$/iu', $text)) { $text = ''; }   // Standardtext: der Knopf nimmt den eingestellten Text (A/B-Test), sonst wirken Karten uneinheitlich
                    } elseif (preg_match('#href=(["\'])(https?://(?:www\.)?(ebay\.[a-z.]+)/sch/[^"\']*)\1[^>]*>(.*?)</a>#is', $in, $e)) {
                        parse_str((string) parse_url(html_entity_decode($e[2]), PHP_URL_QUERY), $q);
                        $attr .= ' host="www.' . strtolower($e[3]) . '"' . (!empty($q['campid']) ? ' campid="' . preg_replace('/\D/', '', (string) $q['campid']) . '"' : '')
                              . (!empty($q['customid']) ? ' customid="' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) $q['customid']) . '"' : '')
                              . (!empty($q['_nkw']) ? ' suche="' . str_replace(['"', '[', ']'], '', trim((string) $q['_nkw'])) . '"' : '')
                              . (trim(strip_tags($e[4])) !== '' ? ' ebay_text="' . str_replace(['"', '[', ']'], '', trim(strip_tags($e[4]))) . '"' : '');
                    } elseif ($titel === '' && stripos($in, '<a') === false && stripos($in, '<img') === false && stripos($in, '[aawp') === false && stripos($in, '[amazon') === false) {
                        $x = trim(html_entity_decode(strip_tags($in)));
                        if ($x !== '' && mb_strlen($x) <= 140) { $titel = $x; }
                    }
                }
                if ($titel !== '' && !self::umbau_ok('titel:' . $asin . ':' . md5($titel)) && self::titel_passt_nicht($asin, $land, $titel)) { self::$umbau_keys[self::$umbau_info[] = 'Bild bestimmt den Titel: „' . mb_substr($titel, 0, 60) . '“ weicht vom Namen ab bei ' . self::produktbeschreibung($asin) . ', die Karte zeigt dessen eigenen Namen'] = 'titel:' . $asin . ':' . md5($titel); $titel = ''; }
                if ($titel !== '') { self::$neue_namen[$asin] = self::$neue_namen[$asin] ?? $titel; }
                $karten[] = '[amz_karte asin="' . $asin . '" land="' . $land . '"' . ($titel !== '' ? ' titel="' . str_replace(['"', '[', ']'], '', $titel) . '"' : '') . ($text !== '' ? ' text="' . str_replace(['"', '[', ']'], '', $text) . '"' : '') . ($attr !== '' ? ' ebay="1"' . $attr : '') . ']';
            }
            self::$karten_n += count($karten);
            return '[amz_karten]' . implode('', $karten) . '[/amz_karten]';
        }, $c);
    }

    /** Titel, die der Umbau in [amz_titel] verwandelt hat: ASIN => ursprünglicher Text (wird als Kurzname gespeichert). */
    public static array $neue_namen = [];
    private static bool $karten = false;
    private static int $karten_n = 0;

    /**
     * Tabellen mit Produktspalten (Bild-Link mit ASIN, darunter Titel als reiner Text, dann Kurzlink, dann eBay-Suchlink):
     * Der Titeltext wird zu [amz_titel], der eBay-Link zu [amz_ebay]; so wechseln beide mit, wenn die ASIN getauscht wird.
     */
    private static function tabellen_umbau(string $c, string $nur = ''): string {
        if (stripos($c, '<table') === false) { return $c; }
        return preg_replace_callback('#<table\b.*?</table>#is', function ($t) use ($nur) {
            if (!preg_match_all('#<tr\b.*?</tr>#is', $t[0], $rows)) { return $t[0]; }
            $spalte = []; $bildzeile = [];
            foreach ($rows[0] as $ri => $row) {
                if (!preg_match_all('#<t[dh]\b.*?</t[dh]>#is', $row, $cs)) { continue; }
                foreach ($cs[0] as $ci => $cell) {
                    foreach (self::zelle_asins($cell) as $k => $u) { $spalte[$ci][$k] = $u; $bildzeile[$ci] = min($bildzeile[$ci] ?? 99, $ri); }
                }
            }
            $ri = -1;
            return preg_replace_callback('#<tr\b.*?</tr>#is', function ($rm) use (&$ri, $spalte, $bildzeile, $nur) {
                $ri++; $ci = -1;
                return preg_replace_callback('#<(t[dh])\b([^>]*)>(.*?)</\1>#is', function ($cm) use (&$ci, $ri, $spalte, $bildzeile, $nur) {
                    $ci++;
                    if (empty($spalte[$ci]) || count($spalte[$ci]) !== 1) { return $cm[0]; }
                    [$asin, $land] = array_values($spalte[$ci])[0];
                    if ($nur !== '' && $asin !== $nur) { return $cm[0]; }
                    $in = $cm[3];
                    if (strpos($in, '[') !== false || !self::tag($land)) { return $cm[0]; }
                    // eBay-Suchlink
                    if (preg_match('#^(.*?)<a\b[^>]*href=(["\'])(https?://(?:www\.)?(ebay\.[a-z.]+)/sch/[^"\']*)\2[^>]*>(.*?)</a>(.*)$#is', $in, $e)) {
                        $url = html_entity_decode($e[3]); parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
                        $suche = trim((string) ($q['_nkw'] ?? ''));
                        $attr = ' asin="' . $asin . '" land="' . $land . '" host="www.' . strtolower($e[4]) . '"'
                              . (!empty($q['campid']) ? ' campid="' . preg_replace('/\D/', '', (string) $q['campid']) . '"' : '')
                              . (!empty($q['customid']) ? ' customid="' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) $q['customid']) . '"' : '')
                              . ($suche !== '' ? ' suche="' . str_replace(['"', '[', ']'], '', $suche) . '"' : '');
                        $txt = trim(strip_tags($e[5])); if ($txt === '') { return $cm[0]; }
                        return '<' . $cm[1] . $cm[2] . '>' . $e[1] . '[amz_ebay' . $attr . ']' . str_replace(['[', ']'], '', $txt) . '[/amz_ebay]' . $e[6] . '</' . $cm[1] . '>';
                    }
                    // Titeltext: reiner Text unter dem Bild-Link, ohne Links und Bilder
                    if ($cm[1] === 'td' && $ri > ($bildzeile[$ci] ?? 99) && stripos($in, '<a') === false && stripos($in, '<img') === false) {
                        $txt = trim(html_entity_decode(strip_tags($in)));
                        if ($txt !== '' && mb_strlen($txt) <= 140) {
                            self::$neue_namen[$asin] = self::$neue_namen[$asin] ?? $txt;
                            return '<' . $cm[1] . $cm[2] . '>[amz_titel asin="' . $asin . '" land="' . $land . '" titel="' . str_replace(['"', '[', ']'], '', $txt) . '"]</' . $cm[1] . '>';
                        }
                    }
                    return $cm[0];
                }, $rm[0]);
            }, $t[0]);
        }, $c);
    }

    /** Wandelt feste Amazon-Links eines Textes um. @return array{0:string,1:int,2:int} [neuer Text, umgewandelt, übersprungen] */
    /** Ein Platzhalter im src-Attribut eines Bildes wird von WordPress nicht ausgegeben (kaputtes Bild). Hier wird das ganze Bild-Element (samt umgebendem Link) durch den Platzhalter ersetzt, der Bild und Link selbst ausgibt. */
    public static function attr_reparieren($c) {
        if (!is_string($c) || strpos($c, '[amz_') === false) { return $c; }
        $re = '#(?:<a\b[^>]*>\s*)?<img\b[^>]*?\bsrc=(["\'])(\[amz_bild\b[^\]]*\](?:\[/amz_bild\])?)\1[^>]*>(?:\s*</a>)?#is';
        $neu = preg_replace_callback($re, function ($m) { $sc = $m[2]; return substr($sc, -11) === '[/amz_bild]' ? $sc : $sc . '[/amz_bild]'; }, $c);
        return $neu ?? $c;
    }

    public static function umbau_text(string $c, string $nur = ''): array {
        $fest = [];
        // bereits vorhandene Plugin-Platzhalter schützen
        $c_orig = $c;
        $c = preg_replace_callback('#\[amz_(bild|produkt|link|ebay)\b[^\]]*\].*?\[/amz_\1\]#is', function ($m) use (&$fest) {
            // die Marke behält Produkt und Art, damit spätere Schritte (Nachbarhilfe) wissen, was dort stand
            $k = "\0SAB" . count($fest) . ($m[1] === 'link' ? '[amz_link' : '') . (preg_match('/asin=["\']?([A-Za-z0-9]{10})/', $m[0], $am) ? ' asin="' . strtoupper($am[1]) . '"' : '') . "\0"; $fest[$k] = $m[0]; return $k;
        }, $c);
        if ($c === null) { $c = $c_orig; $fest = []; }
        self::$karten_n = 0;
        $c0 = $c; $c = self::tabellen_zu_karten($c, $nur); if (!is_string($c)) { $c = $c0; self::$umbau_abw[] = 'Schritt tabellen_zu_karten abgebrochen (PCRE-Grenze), Artikel nicht vollständig geprüft'; }
        // AAWP- und [amazon]-Platzhalter => Plugin-Platzhalter: fields+thumb => [amz_bild], box => [amz_produkt], link => [amz_link]; tracking_id bleibt als tag, image="2|3" als bild
        $vor_aawp = $c;
        $c = preg_replace_callback('#\[(aawp|amazon)\b([^\]]*)\](?:([^\[]{0,1500})\[/\1\])?#is', function ($m) use ($nur) {
            $x = html_entity_decode($m[2]);
            $land = self::land('');
            if (!self::tag($land)) { return $m[0]; }
            $tg = preg_match('#\btracking_id=["\']([A-Za-z0-9\-]{3,40})["\']#i', $x, $tm) ? self::tag_erlaubt($tm[1]) : '';
            $tagattr = $tg !== '' ? ' tag="' . $tg . '"' : '';
            if (preg_match('#\bbox=["\']?([A-Za-z0-9]{10})["\'\s/]#i', $x . ' ', $b)) {
                $asin = strtoupper($b[1]);
                if ($nur !== '' && $asin !== $nur) { return $m[0]; }
                self::$karten_n++;
                return '[amz_produkt asin="' . $asin . '" land="' . $land . '"' . $tagattr . ']';
            }
            if (preg_match('#\blink=["\']?([A-Za-z0-9]{10})["\'\s/]#i', $x . ' ', $l)) {
                $asin = strtoupper($l[1]);
                if ($nur !== '' && $asin !== $nur) { return $m[0]; }
                $text = isset($m[3]) && trim(strip_tags($m[3])) !== '' ? trim(strip_tags($m[3])) : (preg_match('#\btitle=(["\'])(.*?)\1#is', $x, $tt) ? trim($tt[2]) : '');
                self::$karten_n++;
                return '[amz_link asin="' . $asin . '" land="' . $land . '"' . $tagattr . ']' . str_replace(['[', ']'], '', $text) . '[/amz_link]';
            }
            if (!preg_match('#\bfields=["\']?([A-Za-z0-9]{10})["\'\s]#i', $x . ' ', $f) || !preg_match('#\bvalue=["\']?(thumb|image|thumbnail)\b#i', $x)) { return $m[0]; }
            $asin = strtoupper($f[1]);
            if ($nur !== '' && $asin !== $nur) { return $m[0]; }
            $w = preg_match('#\bimage_width=["\']?(\d{2,4})#i', $x, $bw) ? ' breite="' . min(self::AAWP_BILD_MAX, (int) $bw[1]) . '"' : '';
            $bi = preg_match('#\bimage=["\']?([23])\b#', $x, $bm) ? ' bild="' . $bm[1] . '"' : '';
            self::$karten_n++;
            return '[amz_bild asin="' . $asin . '" land="' . $land . '"' . $w . $bi . $tagattr . '][/amz_bild]';
        }, $c);
        if ($c === null) { $c = $vor_aawp; }   // PCRE-Fehler (zu langer Artikel): lieber nichts umbauen als abbrechen
        $c0 = $c; $c = self::kurzlink_spaltenhilfe($c); if (!is_string($c)) { $c = $c0; self::$umbau_abw[] = 'Schritt kurzlink_spaltenhilfe abgebrochen (PCRE-Grenze), Artikel nicht vollständig geprüft'; }
        $c0 = $c; $c = self::kurzlink_nachbarhilfe($c); if (!is_string($c)) { $c = $c0; self::$umbau_abw[] = 'Schritt kurzlink_nachbarhilfe abgebrochen (PCRE-Grenze), Artikel nicht vollständig geprüft'; }
        $c0 = $c; $c = self::tabellen_umbau($c, $nur); if (!is_string($c)) { $c = $c0; self::$umbau_abw[] = 'Schritt tabellen_umbau abgebrochen (PCRE-Grenze), Artikel nicht vollständig geprüft'; }
        $n = 0; $skip = 0;
        if ($nur === '') { [$c_s, $n_s] = self::seitenlinks_ergaenzen($c); $c = $c_s; $n += $n_s; }   // Startseiten- und Rezensionslinks: Partner-ID des Landes und rel ergänzen
        $c = preg_replace_callback('#<a\b([^>]*)>(.*?)</a>#is', function ($m) use (&$n, &$skip, $nur) {
            if (!preg_match('#href=(["\'])(.*?)\1#is', $m[1], $h)) { return $m[0]; }
            $u = self::umbau_url($h[2], true);
            if (!$u) { return $m[0]; }
            [$land, $asin] = $u;
            if ($nur !== '' && $asin !== $nur) { return $m[0]; }
            if (!self::tag($land) || strpos($m[2], '[') !== false) {
                $skip++;
                if (!self::tag($land)) { self::$skip_laender[$land] = 1; self::$skip_gruende[] = 'Link zu ' . $asin . ' im Shop amazon.' . $land . ': keine Partner-ID für diesen Shop eingetragen'; }
                else { self::$skip_gruende[] = 'Link zu ' . $asin . ' (Text „' . mb_substr(trim(strip_tags($m[2])), 0, 30) . '“): der Linktext enthält eckige Klammern/Platzhalter (Sonderinhalt), bitte von Hand ansehen'; }
                return $m[0];
            }
            if (!self::umbau_ok('weg:' . $asin) && self::asin_weg($asin, $land) && !self::weg_umbauen($asin)) { self::$umbau_keys[self::$umbau_abw[] = 'Link „' . mb_substr(trim(strip_tags($m[2])), 0, 40) . '“ zeigt auf ' . $asin . ', das Amazon nicht mehr kennt, nicht umgebaut (erst das Produkt tauschen)'] = 'weg:' . $asin; self::$umbau_weg[$asin] = 1; $skip++; return $m[0]; }
            $cls = '';
            if (preg_match('#class=(["\'])(.*?)\1#is', $m[1], $cl)) { $cls = trim(preg_replace('/[^A-Za-z0-9 _-]/', '', $cl[2])); }
            $attr = ' asin="' . $asin . '" land="' . $land . '"' . ($cls !== '' ? ' class="' . $cls . '"' : '');
            if (stripos($m[2], '<img') !== false) { $n++; return '[amz_bild' . $attr . ']' . $m[0] . '[/amz_bild]'; }
            $text = trim(html_entity_decode(strip_tags($m[2])));
            if ($text === '') { $skip++; self::$skip_gruende[] = 'Link zu ' . $asin . ' ohne Linktext und ohne Bild, nicht umbaubar'; return $m[0]; }
            $n++;
            return '[amz_link' . $attr . ']' . str_replace(['[', ']'], '', $text) . '[/amz_link]';
        }, $c);
        return [self::attr_reparieren(strtr($c, $fest)), $n + self::$karten_n, $skip];
    }

    /** Analyse (nur Lesen): je ASIN Anzahl Stellen und Artikel, und was bereits im Plugin läuft. */
    private static function umbau_analyse(): array {
        global $wpdb;
        $out = ['asins' => [], 'artikel' => 0, 'kurz' => 0, 'fertig' => 0];
        if (!isset($wpdb)) { return $out; }
        $rows = $wpdb->get_results("SELECT ID, post_title, post_content FROM {$wpdb->posts} WHERE post_status='publish' AND post_type IN ('post','page') AND post_content LIKE '%amazon.%'", ARRAY_A);
        foreach ((array) $rows as $r) {
            $c = (string) $r['post_content'];
            $out['fertig'] += preg_match_all('#\[amz_(?:bild|link|produkt)\b#', $c);
            $c = preg_replace('#\[amz_(bild|produkt|link)\b[^\]]*\].*?\[/amz_\1\]#is', '', $c);
            $out['kurz'] += preg_match_all('#href=["\']https?://amzn\.to/#i', $c);
            if (!preg_match_all('#<a\b[^>]*href=(["\'])(.*?)\1#is', $c, $m)) { continue; }
            $hat = false;
            foreach ($m[2] as $url) {
                $u = self::umbau_url($url);
                if (!$u) { continue; }
                $hat = true;
                $k = $u[1] . '|' . $u[0];
                $out['asins'][$k] = $out['asins'][$k] ?? ['asin' => $u[1], 'land' => $u[0], 'stellen' => 0, 'artikel' => []];
                $out['asins'][$k]['stellen']++;
                $out['asins'][$k]['artikel'][(int) $r['ID']] = $r['post_title'];
            }
            if ($hat) { $out['artikel']++; }
        }
        uasort($out['asins'], fn($a, $b) => $b['stellen'] <=> $a['stellen']);
        return $out;
    }

    /** Ein Durchgang: nächste Artikel umbauen, Original vorher in der Meta-Sicherung ablegen. */
    private static function umbau_lauf(string $nur): string {
        global $wpdb;
        if (!isset($wpdb)) { return 'Keine Datenbank.'; }
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_status='publish' AND p.post_type IN ('post','page') AND (p.post_content LIKE %s OR p.post_content LIKE %s OR p.post_content LIKE '%%link.amazon%%')
             AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id=p.ID AND m.meta_key='_sab_umbau_geprueft' AND m.meta_value=%s) ORDER BY p.ID LIMIT %d",
            '%amazon.%', '%amzn.%', $nur !== '' ? $nur : 'alle', self::UMBAU_PROSEITE));
        $geaendert = 0; $links = 0; $skip = 0; self::$umbau_abw = []; self::$umbau_info = []; self::$skip_gruende = [];
        foreach ((array) $ids as $id) {
            $post = get_post((int) $id);
            if (!$post) { continue; }
            [$n, $sk, $gea] = self::umbau_artikel((int) $id, $nur);
            $links += $n; $skip += $sk; $geaendert += $gea;
        }
        delete_transient('sab_alle_asins');
        $rest = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_status='publish' AND p.post_type IN ('post','page') AND (p.post_content LIKE %s OR p.post_content LIKE %s OR p.post_content LIKE '%%link.amazon%%')
             AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id=p.ID AND m.meta_key='_sab_umbau_geprueft' AND m.meta_value=%s)",
            '%amazon.%', '%amzn.%', $nur !== '' ? $nur : 'alle'));
        return count((array) $ids) . ' Artikel geprüft, ' . $geaendert . ' geändert, ' . $links . ' Links umgebaut'
             . ($skip ? ', ' . $skip . ' Links übersprungen: ' . (self::$skip_gruende ? implode(' | ', array_slice(array_unique(self::$skip_gruende), 0, 8)) : 'Grund unbekannt') : '')
             . '. ' . ($rest ? 'Noch ' . $rest . ' Artikel offen: bitte nochmal klicken.' : 'Fertig.')
             . (self::$umbau_info ? ' Nach dem Bild angepasst: ' . implode(' | ', array_slice(array_unique(self::$umbau_info), 0, 8)) . '.' : '')
             . (self::$umbau_abw ? ' NICHT umgebaut wegen Abweichung (bitte von Hand klären): ' . implode(' | ', array_slice(array_unique(self::$umbau_abw), 0, 8)) . '.' : '');
    }

    /** Bisherige Titeltexte als Kurznamen in der Produktliste merken (nur wo noch keiner steht); so bleibt der Wortlaut des Artikels erhalten. */
    private static function namen_merken(array $neu): void {
        if (!$neu) { return; }
        $vorhanden = self::produkte(); $zeilen = trim((string) get_option('sab_produkte', ''));
        foreach ($neu as $asin => $name) {
            if (($vorhanden[$asin] ?? '') !== '') { continue; }
            $name = trim(str_replace(['|', "\n", "\r"], ' ', $name));
            $zeilen = preg_replace('/^' . preg_quote($asin, '/') . '\s*(\|.*)?$/m', '', $zeilen);
            $zeilen = trim($zeilen . "\n" . $asin . ' | ' . $name);
        }
        update_option('sab_produkte', trim(preg_replace('/\n{2,}/', "\n", $zeilen)));
    }

    /** Baut einen einzelnen Artikel um (Sicherung vorher). @return array{0:int,1:int,2:int} [umgebaute Links, übersprungen, geändert 0/1] */
    /** Findet einen [amz_…]-Platzhalter innerhalb eines HTML-Tags (z. B. in src, href, data-src); liefert einen kurzen Ausschnitt oder ''. */
    private static function platzhalter_im_tag(string $c): string {
        return preg_match('#<[a-z][a-z0-9]*\b(?:[^>\[]|\[(?!amz_))*\[amz_[^>]*>#i', $c, $m) ? mb_substr($m[0], 0, 120) : '';
    }

    /** Holt für alle neu entstehenden Plugin-Platzhalter die Produktdaten und nennt die ASINs, die danach kein Bild hätten. @return string[] */
    private static function umbau_bildpruefung(string $alt, string $neu): array {
        $land = self::land(''); $ohne = []; $geholt = 0;
        $vorher = []; if (preg_match_all('/\[amz_(?:produkt|bild)\b[^\]]*\basin="([A-Za-z0-9]{10})"/', $alt, $m0)) { $vorher = array_flip(array_map('strtoupper', $m0[1])); }
        if (!preg_match_all('/\[amz_(?:produkt|bild)\b[^\]]*\basin="([A-Za-z0-9]{10})"/', $neu, $m)) { return []; }
        foreach (array_unique(array_map('strtoupper', $m[1])) as $a) {
            if (isset($vorher[$a])) { continue; }
            $a = self::getauscht($a);
            if (get_option('sab_weg_umbauen', '1') === '1' && self::asin_weg($a, $land)) { continue; }   // unbekanntes Produkt: Platzhalter statt Abbruch
            if (!get_transient("sab_{$land}_{$a}")) { if ($geholt >= 25) { continue; } $geholt++; }
            if (!self::hat_bild(self::produkt($a, $land, false, true))) { $ohne[] = $a; }
        }
        return $ohne;
    }

    private static function umbau_artikel(int $id, string $nur = ''): array {
        $post = get_post($id);
        if (!$post) { return [0, 0, 0]; }
        self::$kurz_offen = 0;
        self::$neue_namen = [];
        $abw0 = count(self::$umbau_abw); $info0 = count(self::$umbau_info); $skg0 = count(self::$skip_gruende);
        [$neu, $n, $sk] = self::umbau_text((string) $post->post_content, $nur);
        for ($i = $abw0; $i < count(self::$umbau_abw); $i++) { self::$umbau_abw[$i] = $post->post_title . ': ' . self::$umbau_abw[$i]; }
        for ($i = $skg0; $i < count(self::$skip_gruende); $i++) { self::$skip_gruende[$i] = $post->post_title . ': ' . self::$skip_gruende[$i]; }
        for ($i = $info0; $i < count(self::$umbau_info); $i++) { self::$umbau_info[$i] = $post->post_title . ': ' . self::$umbau_info[$i]; }
        $gea = 0;
        if ($n > 0 && $neu !== $post->post_content) {
            if (self::platzhalter_im_tag($neu) && !self::platzhalter_im_tag((string) $post->post_content)) {   // Platzhalter innerhalb eines HTML-Tags (Attribut) gibt WordPress nicht aus
                self::$umbau_abw[] = $post->post_title . ': NICHT umgebaut, weil ein Platzhalter in einem HTML-Attribut gelandet wäre (' . self::platzhalter_im_tag($neu) . ').';
                return [0, $sk, 0];
            }
            $ohne = self::umbau_bildpruefung((string) $post->post_content, $neu);
            if ($ohne) {   // nie umbauen, wenn danach irgendwo ein Produktbild fehlen würde
                self::$umbau_abw[] = $post->post_title . ': NICHT umgebaut, weil für ' . implode(', ', $ohne) . ' bei Amazon kein Bild geliefert wird. Dort bitte zuerst das Produkt ersetzen.';
                return [0, $sk, 0];
            }
            self::namen_merken(self::$neue_namen);
            if (get_post_meta($id, '_sab_umbau_backup', true) === '') { update_post_meta($id, '_sab_umbau_backup', wp_slash($post->post_content)); }
            wp_update_post(['ID' => $id, 'post_content' => wp_slash($neu)]);
            $gea = 1;
        }
        if (self::$kurz_offen === 0) { update_post_meta($id, '_sab_umbau_geprueft', $nur !== '' ? $nur : 'alle'); }  // sonst beim nächsten Klick weitermachen
        delete_transient('sab_alle_asins');
        return [$n, $sk, $gea];
    }

    /** Ein Artikel zurück auf den Zustand vor dem Umbau. */
    private static function umbau_zurueck_artikel(int $id): string {
        $alt = get_post_meta($id, '_sab_umbau_backup', true);
        if ($alt === '' || $alt === false) { return 'Für diesen Artikel gibt es keine Sicherung.'; }
        wp_update_post(['ID' => $id, 'post_content' => wp_slash($alt)]);
        delete_post_meta($id, '_sab_umbau_backup'); delete_post_meta($id, '_sab_umbau_geprueft');
        delete_transient('sab_alle_asins');
        return 'Artikel wieder auf den Zustand vor dem Umbau gesetzt.';
    }

    /** Artikel mit festen Amazon-Links (inkl. Kurzlinks): ID, Titel, Anzahl Links, Kurzlinks, umgebaut ja/nein. */
    private static function umbau_artikelliste(): array {
        global $wpdb;
        $out = [];
        if (!isset($wpdb)) { return $out; }
        $rows = $wpdb->get_results("SELECT ID, post_title, post_content, post_status FROM {$wpdb->posts} WHERE post_status IN ('publish','future') AND post_type NOT IN ('revision','attachment','nav_menu_item','customize_changeset','oembed_cache','wp_template','wp_template_part','wp_global_styles','wp_navigation','wp_block') AND (post_content LIKE '%amazon.%' OR post_content LIKE '%amzn.%' OR post_content LIKE '%link.amazon%' OR post_content LIKE '%[aawp%' OR post_content LIKE '%[amazon %') ORDER BY ID", ARRAY_A);
        foreach ((array) $rows as $r) {
            $c = (string) $r['post_content'];
            $c = preg_replace('#\[amz_(bild|produkt|link)\b[^\]]*\].*?\[/amz_\1\]#is', '', $c);
            $fest = 0; $kurz = 0;
            if (preg_match_all('/\[(?:aawp|amazon)\b[^\]]*\b(?:box|link|fields)=/i', $c, $mm)) { $fest += count($mm[0]); }   // AAWP- und [amazon]-Platzhalter zählen als umzubauen
            if (preg_match_all('#<a\b[^>]*href=(["\'])(.*?)\1#is', $c, $m)) {
                foreach ($m[2] as $url) {
                    if (self::umbau_url($url)) { $fest++; } elseif (preg_match('#^https?://(?:amzn\.to|amzn\.eu|a\.co|link\.amazon)/#i', html_entity_decode($url)) && !self::kurz_ok($url)) { $kurz++; }
                }
            }
            $gesichert = get_post_meta((int) $r['ID'], '_sab_umbau_backup', true) !== '';
            $fertig = $gesichert && $fest + $kurz === 0;   // „umgebaut“ erst, wenn kein fester Link und kein Kurzlink mehr übrig ist (sonst bliebe ein halb umgebauter Artikel unsichtbar)
            if ($fest + $kurz > 0 || $gesichert) { $out[] = ['id' => (int) $r['ID'], 'titel' => (string) $r['post_title'], 'fest' => $fest, 'kurz' => $kurz, 'fertig' => $fertig, 'teil' => $gesichert && !$fertig, 'status' => (string) ($r['post_status'] ?? 'publish')]; }
        }
        usort($out, fn($a, $b) => [(int) $a['fertig'], $b['fest'] + $b['kurz'], $a['id']] <=> [(int) $b['fertig'], $a['fest'] + $a['kurz'], $b['id']]);   // offene Artikel oben, die mit den meisten Links zuerst
        return $out;
    }

    /** Alle noch offenen festen Links, Kurzlinks und AAWP-Platzhalter eines Artikels mit Sprung ins Frontend und in den Editor (markiert die Stelle). */
    private static function umbau_links_html(int $pid): string {
        $post = get_post($pid); if (!$post) { return ''; }
        $c = (string) preg_replace('#\[amz_(bild|produkt|link)\b[^\]]*\].*?\[/amz_\1\]#is', '', (string) $post->post_content);
        $items = [];
        if (preg_match_all('#<a\b[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)</a>#is', $c, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $url = html_entity_decode($x[2]); $txt = trim(html_entity_decode(strip_tags($x[3]))); if ($txt === '') { $txt = '(Bild)'; }
                if (($u = self::umbau_url($x[2])) !== null) { $items[] = ['fest', $txt, $url, (string) $u[1]]; }
                elseif (preg_match('#^https?://(?:amzn\.to|amzn\.eu|a\.co|link\.amazon)/([A-Za-z0-9]+)#i', $url, $km) && !self::kurz_ok($x[2])) { $items[] = ['kurz', $txt, $url, (string) $km[1]]; }
            }
        }
        if (preg_match_all('/\[(?:aawp|amazon)\b[^\]]*\b(?:box|link|fields)=["\']([A-Za-z0-9]{10})/i', $c, $am)) { foreach ($am[1] as $a_) { $items[] = ['aawp', '[aawp-Platzhalter]', '', strtoupper($a_)]; } }
        if (!$items) { return ''; }
        $kennung_n = []; $li = '';
        foreach (array_slice($items, 0, 30) as [$art, $txt, $url, $k]) {
            $k = preg_replace('/[^A-Za-z0-9]/', '', $k); $kennung_n[$k] = ($kennung_n[$k] ?? 0) + 1;
            $fe = add_query_arg(['sab_zeige' => $k, 'sab_nr' => $kennung_n[$k]], (string) get_permalink($pid));
            $be = admin_url('post.php?post=' . $pid . '&action=edit&sab_zeige=' . rawurlencode($k) . '&sab_nr=' . $kennung_n[$k]);
            $li .= '<li><strong>' . esc_html(['fest' => 'fester Link', 'kurz' => 'Kurzlink', 'aawp' => 'AAWP'][$art]) . '</strong> „' . esc_html(mb_substr($txt, 0, 40)) . '“ <code>' . esc_html($k) . '</code> <a href="' . esc_url($fe) . '" target="_blank" rel="noopener">Frontend ↗</a> · <a href="' . esc_url($be) . '" target="_blank" rel="noopener">Editor ↗</a></li>';
        }
        return '<details style="margin-top:4px"><summary><small>Links dieses Artikels (' . count($items) . ')</small></summary><ul style="margin:4px 0 0 16px;list-style:disc">' . $li . '</ul></details>';
    }

    /** ASIN aus der aufgelösten Zieladresse (Produkt-Rezensionsseite /product-reviews/ASIN, sonst jede Adresse mit ASIN). */
    private static function kurzlink_ziel_asin(string $kurz): string {
        $z = self::kurzlink_aufloesen($kurz);
        return preg_match('#/(?:product-reviews|dp|gp/product|gp/aw/d|review/[^/]*/[^/]*)/([A-Z0-9]{10})(?![A-Za-z0-9])#i', $z, $m) || preg_match('#[?&]asin=([A-Z0-9]{10})#i', $z, $m) ? strtoupper($m[1]) : '';
    }

    /** Hat Shogen diesen Kurzlink als „so lassen“ bestätigt? Dann zählt er nicht als Problem und der Umbau lässt ihn aus. */
    public static function kurz_ok(string $url): bool {
        $l = (array) get_option('sab_kurz_ok', []);
        return $l && in_array(html_entity_decode(trim($url)), $l, true);
    }

    public static function kurz_ok_setzen(string $url, bool $ok): void {
        $url = html_entity_decode(trim($url));
        if (!preg_match('#^https?://(?:amzn\.to|amzn\.eu|a\.co|link\.amazon)/#i', $url)) { return; }
        $l = array_values(array_diff((array) get_option('sab_kurz_ok', []), [$url]));
        if ($ok) { $l[] = $url; }
        update_option('sab_kurz_ok', array_slice($l, -500), false);
    }

    public static function ajax_kurz_ok(): void {
        check_ajax_referer('sab_stelle', 'n');
        $pid = (int) ($_POST['post'] ?? 0);
        if (!$pid || !current_user_can('edit_post', $pid)) { wp_send_json_error('Keine Berechtigung.'); }
        self::kurz_ok_setzen((string) wp_unslash($_POST['kurz'] ?? ''), (string) ($_POST['ok'] ?? '1') === '1');
        wp_send_json_success(['ok' => (string) ($_POST['ok'] ?? '1') === '1']);
    }

    /** Warum lässt sich ein Kurzlink nicht auf ein Produkt umbauen? Nutzt nur das, was schon im Zwischenspeicher steht oder jetzt (mit Abrufgrenze) aufgelöst wird. */
    private static function kurzlink_grund(string $kurz): string {
        $z = self::kurzlink_aufloesen($kurz);
        if ($z === '') { return 'der Kurzlink lässt sich nicht auflösen (Amazon antwortet nicht oder gibt keine Zieladresse heraus).'; }
        $tag = preg_match('/[?&]tag=([A-Za-z0-9\-]{3,40})/', $z, $tm) ? $tm[1] : '';
        $pid = $tag !== '' ? 'Partner-ID im Ziel: <code>' . esc_html($tag) . '</code>.' : 'keine Partner-ID im Ziel erkennbar.';
        if (preg_match('#/(?:gp/customer-reviews|gp/aw/cr|product-reviews|review/|customer-reviews)#i', $z)) {
            $ra = self::kurzlink_ziel_asin($kurz);
            return '<strong>Link zu den Rezensionen' . ($ra !== '' ? ' des Produkts ' . esc_html($ra) : '') . ', kein Produktlink.</strong> Das Plugin lässt ihn unverändert (keine Umwandlung, kein Tracking durch das Plugin). Entweder so lassen (der Partner-Tag steckt im Kurzlink), oder unten durch den Produktlink' . ($ra !== '' ? ' (ASIN schon eingetragen)' : '') . ' ersetzen; ' . $pid . ' Ziel: <code style="word-break:break-all">' . esc_html(mb_substr($z, 0, 140)) . '</code>';
        }
        return 'das Ziel ist keine Produktseite mit /dp/ASIN. ' . $pid . ' Ziel: <code style="word-break:break-all">' . esc_html(mb_substr($z, 0, 140)) . '</code>';
    }

    /** Kurzlinks (amzn.to …) eines Artikels, die sich nicht als Produkt auflösen lassen: Textumgebung, Sprung zur Stelle und Feld für ASIN oder Amazon-Link. */
    private static function kurzlink_liste_html(int $id, string $rueck = ''): string {
        $post = get_post($id); if (!$post) { return ''; }
        $c = preg_replace('#\[amz_(bild|produkt|link)\b[^\]]*\].*?\[/amz_\1\]#is', '', (string) $post->post_content);
        if (!preg_match_all('#<a\b[^>]*href=(["\'])(https?://(?:amzn\.to|amzn\.eu|a\.co|link\.amazon)/[^"\']*)\1[^>]*>(.*?)</a>#is', $c, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) { return ''; }
        $h = ''; $gesehen = []; $ok_liste = '';
        foreach ($m as $x) {
            $u = html_entity_decode($x[2][0]);
            if (isset($gesehen[$u]) || self::umbau_url($u, true)) { continue; }
            if (($sa_ = self::seite_art(self::kurzlink_aufloesen($u))) !== '') { $gesehen[$u] = 1; $ok_liste .= '<li><code style="word-break:break-all">' . esc_html($u) . '</code> <small>(' . ($sa_ === 'rezension' ? 'Rezensionsseite eines Produkts, gültiger Produktlink' : 'Amazon-Seite, kein Produkt, gewollt') . ')</small></li>'; continue; }
            if (self::kurz_ok($u)) { $gesehen[$u] = 1; $ok_liste .= '<li><code style="word-break:break-all">' . esc_html($u) . '</code> <form method="post" style="display:inline">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="umbau"><input type="hidden" name="sab_form" value="kurzlink_ok"><input type="hidden" name="sab_aus" value="1"><input type="hidden" name="sab_kurz" value="' . esc_attr($u) . '">' . $rueck . '<button class="button button-small">Wieder prüfen</button></form></li>'; continue; }
            $gesehen[$u] = 1;
            $vor = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(preg_replace('/\[[^\]]*\]/', ' ', substr($c, max(0, $x[0][1] - 500), min(500, $x[0][1])))))));
            $vor = mb_strlen($vor) > 140 ? '… ' . mb_substr($vor, -140) : $vor;
            $lt = trim(html_entity_decode(strip_tags($x[3][0])));
            $kenn = preg_match('#/([A-Za-z0-9]{5,12})$#', rtrim(explode('?', $u)[0], '/'), $kn) ? $kn[1] : '';
            $h .= '<li style="margin:6px 0"><div><code style="word-break:break-all">' . esc_html($u) . '</code> · Linktext „' . esc_html(mb_substr($lt, 0, 60)) . '“</div>'
                . '<div class="description">Davor im Text: ' . esc_html($vor !== '' ? $vor : '(nichts)') . '</div>'
                . '<div>Grund: ' . self::kurzlink_grund($u) . ' '
                . ($kenn !== '' ? '<a href="' . esc_url(add_query_arg('sab_zeige', $kenn, (string) get_permalink($id))) . '" target="_blank" rel="noopener">Stelle im Frontend ansehen</a> · <a href="' . esc_url(admin_url('post.php?post=' . $id . '&action=edit&sab_zeige=' . rawurlencode($kenn))) . '" target="_blank" rel="noopener">Im Editor bearbeiten</a>' : '') . '</div>'
                . '<div style="margin:4px 0 0">' . self::kurzlink_suche_html($vor, $lt) . '</div>'
                . '<form method="post" style="margin:4px 0 0">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="umbau"><input type="hidden" name="sab_form" value="kurzlink_setzen"><input type="hidden" name="sab_id" value="' . $id . '"><input type="hidden" name="sab_kurz" value="' . esc_attr($u) . '">' . $rueck
                . '<input type="text" name="sab_asin" value="' . esc_attr(self::kurzlink_ziel_asin($u)) . '" placeholder="ASIN oder Amazon-Produktlink (mit /dp/…)" style="width:340px" required> <button class="button button-small button-primary">Diesen Kurzlink ersetzen</button></form>'
                . '<form method="post" style="margin:4px 0 0">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="umbau"><input type="hidden" name="sab_form" value="kurzlink_ok"><input type="hidden" name="sab_kurz" value="' . esc_attr($u) . '">' . $rueck . '<button class="button button-small" title="Das Plugin merkt sich: Dieser Link ist gewollt, zählt nicht mehr als Problem und wird beim Umbau ausgelassen.">Ist okay, so lassen</button></form></li>';
        }
        $ok_html = $ok_liste !== '' ? '<details style="margin:6px 0"><summary>Als „so lassen“ bestätigte Kurzlinks in diesem Artikel</summary><ul style="margin:6px 0 0 16px">' . $ok_liste . '</ul></details>' : '';
        return $ok_html . ($h === '' ? '' : '<div style="background:#fff8e5;border:1px solid #dba617;padding:6px 12px;margin:8px 0;max-width:900px"><strong>Kurzlinks, die das Plugin nicht lösen kann:</strong> Mit ASIN oder Amazon-Link ersetzen, danach lässt sich der Artikel umbauen.<ul style="margin:6px 0 0 16px">' . $h . '</ul></div>');
    }

    /** Suchfeld + Knopf „Bei Amazon suchen“ (neuer Tab): Begriff aus dem Text direkt vor dem Kurzlink, sonst der Linktext; steht eine ASIN im Eingabefeld darunter, wird nach ihr gesucht. */
    private static function kurzlink_suche_html(string $vor, string $linktext): string {
        $teile = preg_split('/(?<=[.!?:;])\s+/u', trim($vor)); $frag = trim((string) end($teile), " .!?:;–-");
        $w = array_values(array_filter(preg_split('/\s+/u', $frag)));
        $term = count($w) >= 2 ? implode(' ', array_slice($w, -8)) : '';
        if ($term === '' && $linktext !== '' && !self::generischer_linktext($linktext)) { $term = $linktext; }
        $base = 'https://' . self::LAENDER[self::land('')][0] . '/s?k=';
        return '<input type="text" value="' . esc_attr($term) . '" style="width:340px" aria-label="Suchbegriff"> <button type="button" class="button button-small" onclick="var f=this.closest(\'li\'),a=f&&f.querySelector(\'input[name=sab_asin]\'),q=(a&&a.value.trim())||this.previousElementSibling.value;window.open(' . esc_attr(wp_json_encode($base)) . '+encodeURIComponent(q),\'_blank\',\'noopener\')">Bei Amazon suchen</button> <span class="description">(Begriff änderbar; steht unten eine ASIN, wird nach ihr gesucht)</span>';
    }

    /** Ersetzt einen Kurzlink im Artikel durch die Produktadresse zur eingegebenen ASIN (WordPress legt dabei eine Revision an). */
    private static function kurzlink_setzen(int $id, string $kurz, string $eingabe): string {
        $post = get_post($id);
        if (!$post || !current_user_can('edit_post', $id)) { return 'Artikel nicht gefunden oder keine Berechtigung.'; }
        if (!preg_match('#^https?://(?:amzn\.to|amzn\.eu|a\.co|link\.amazon)/#i', $kurz)) { return 'Das ist kein Kurzlink.'; }
        $asin = self::asin_aus_text($eingabe);
        if (strlen($asin) !== 10) { return 'Bitte eine gültige ASIN oder einen Amazon-Produktlink mit /dp/… eingeben (Kurzlinks enthalten keine ASIN).'; }
        $u = self::umbau_url($eingabe); $land = $u ? $u[0] : self::land('');
        if (!isset(self::LAENDER[$land])) { $land = self::land(''); }
        $c = (string) $post->post_content; $neu_url = 'https://' . self::LAENDER[$land][0] . '/dp/' . $asin;
        $n = substr_count($c, $kurz) + substr_count($c, htmlspecialchars($kurz, ENT_QUOTES)) + substr_count($c, str_replace('&', '&#038;', $kurz));
        if ($n < 1) { return 'Der Kurzlink steht nicht mehr im Artikel.'; }
        $c2 = str_replace([$kurz, htmlspecialchars($kurz, ENT_QUOTES), str_replace('&', '&#038;', $kurz)], $neu_url, $c);
        wp_update_post(['ID' => $id, 'post_content' => wp_slash($c2)]);
        delete_transient('sab_alle_asins');
        return 'Kurzlink ersetzt durch ' . $neu_url . ' (' . $n . '×). Der Artikel kann jetzt umgebaut werden; die alte Fassung steht unter „Versionen“.';
    }

    /** Vorschau: jeder Amazon-Link des Artikels vorher und nachher (ohne etwas zu speichern). */
    /** Links „Stelle ansehen“ zu einer Meldung des Umbaus: Kurzlink-Kennungen und ASINs aus dem Meldungstext, der Sprung markiert die Stelle im Artikel und scrollt dorthin. */
    private static function ort_links(string $text, int $id): string {
        $n = [];
        if (preg_match_all('#(?:amzn\.to|amzn\.eu|a\.co|link\.amazon)/([A-Za-z0-9]{5,12})#', $text, $m)) { $n = array_merge($n, $m[1]); }
        if (preg_match_all('/\b(B0[A-Z0-9]{8}|[0-9]{9}[0-9X])\b/', $text, $m)) { $n = array_merge($n, $m[1]); }
        $url = (string) get_permalink($id); $out = '';
        foreach (array_slice(array_values(array_unique($n)), 0, 3) as $x) {
            $out .= ' <a href="' . esc_url(add_query_arg('sab_zeige', $x, $url)) . '" target="_blank" rel="noopener">Stelle ansehen (' . esc_html($x) . ')</a>';
        }
        return $out . ($out !== '' ? ' · <a href="' . esc_url(admin_url('post.php?post=' . $id . '&action=edit&sab_zeige=' . rawurlencode((string) $n[0]))) . '" target="_blank" rel="noopener">Bearbeiten (springt zur Stelle)</a>' : '');
    }

    /** Je Artikel mit dem Produkt: „Stelle ansehen“ (öffnet den Artikel und markiert die Stelle) und „Bearbeiten“. */
    private static function fundstellen_links(string $asin, array $fund): string {
        if (!$fund) { return ''; }
        $h = '<ul style="margin:4px 0 6px 16px;font-size:12px">'; $i = 0;
        foreach ($fund as $pid => $f) {
            if (++$i > 8) { $h .= '<li>… und ' . (count($fund) - 8) . ' weitere</li>'; break; }
            $u = add_query_arg('sab_zeige', $asin, (string) get_permalink((int) $pid));
            $h .= '<li>' . esc_html($f['titel'] ?: ('Beitrag ' . $pid)) . ' (' . (int) $f['n'] . '×): <a href="' . esc_url($u) . '" target="_blank" rel="noopener">Stelle ansehen</a> · <a href="' . esc_url(admin_url('post.php?post=' . (int) $pid . '&action=edit&sab_zeige=' . rawurlencode($asin))) . '" target="_blank" rel="noopener">Bearbeiten (springt zur Stelle)</a></li>';
        }
        return $h . '</ul>';
    }

    /**
     * Vorschau für mehrere offene Artikel auf einmal (je Seite UMBAU_PROSEITE): Status je Artikel, alle Problemprodukte mit Ersatzkasten (je ASIN einmal)
     * und ein Knopf, der nur die Artikel ohne Problem umbaut.
     */
    private static function umbau_uebersicht_html(int $ab): string {
        $ab = max(0, $ab); self::$vorschau_ab = $ab;
        $offen = array_values(array_filter(self::umbau_artikelliste(), fn($a) => !$a['fertig']));
        usort($offen, fn($a, $b) => $a['id'] <=> $b['id']);   // feste Reihenfolge nach ID: Umbauen verschiebt nichts, „Nächste“ überspringt keinen Artikel
        $gesamt = count($offen);
        $rest = array_values(array_filter($offen, fn($a) => $a['id'] > $ab));
        $liste = array_slice($rest, 0, self::UMBAU_PROSEITE);
        $h = '<h3>Vorschau für die offenen Artikel (' . $gesamt . ' Artikel offen' . ($ab > 0 ? ', weiter nach Artikel-Nr. ' . $ab : '') . ')</h3><p class="description">Es wird nichts gespeichert. Jeder Artikel wird so geprüft wie beim Umbau. „Bereit“ heißt: kein Problem gefunden. Probleme siehst du unten, dort kannst du auch Ersatz für Produkte wählen, die Amazon nicht mehr kennt.</p>';
        $zeilen = []; $bereit = []; $wegAlle = []; $kurzOffen = 0; $nProb = 0; $letzte = $ab; $geprueft = 0;
        $t_start = microtime(true); $uebrig = 0;
        foreach ($liste as $a) {
            if (microtime(true) - $t_start > 20) { $uebrig++; continue; }   // Zeitgrenze: Der Rest kommt auf der nächsten Seite (sie beginnt nach dem letzten geprüften Artikel)
            $letzte = (int) $a['id']; $geprueft++;
            $post = get_post($a['id']); if (!$post) { continue; }
            self::$umbau_abw = []; self::$umbau_info = []; self::$umbau_weg = []; self::$umbau_keys = []; self::$kurz_offen = 0; self::$neue_namen = []; self::$skip_laender = []; self::$skip_gruende = [];
            try { self::umbau_text((string) $post->post_content); }
            catch (\Throwable $e) { self::$umbau_abw[] = 'Prüfung abgebrochen: ' . $e->getMessage(); }
            $abw = array_values(array_unique(self::$umbau_abw)); $info = count(array_unique(self::$umbau_info)); $kurzOffen += self::$kurz_offen;
            foreach (self::$umbau_weg as $w => $_) { $wegAlle[$w] = ($wegAlle[$w] ?? 0) + 1; }
            if ($abw) { $nProb++; $status = '<strong style="color:#a00">Problem (' . count($abw) . ')</strong>'; }
            elseif (self::$kurz_offen) { $status = '<strong style="color:#b36b00">noch nicht fertig geprüft</strong>'; }
            else { $status = '<strong style="color:#1a7f37">bereit</strong>' . ($info ? ' <small>(' . $info . ' Anpassung' . ($info === 1 ? '' : 'en') . ' nach dem Bild)</small>' : ''); $bereit[] = (int) $a['id']; }
            $meld = ''; foreach (array_slice($abw, 0, 3) as $m) { $meld .= '<li>' . esc_html($m) . self::ort_links($m, (int) $a['id']) . '</li>'; }
            $kl_ = self::kurzlink_liste_html((int) $a['id'], '<input type="hidden" name="sab_alle_vorschau" value="1"><input type="hidden" name="sab_ab" value="' . (int) $ab . '">');
            $zeilen[] = [$abw ? 1 : 0, '<tr><td><a href="' . esc_url((string) get_permalink($a['id'])) . '" target="_blank" rel="noopener">' . esc_html($a['titel'] ?: ('Beitrag ' . $a['id'])) . '</a> <small><a href="' . esc_url(admin_url('post.php?post=' . (int) $a['id'] . '&action=edit')) . '" target="_blank" rel="noopener">Bearbeiten ↗</a></small>' . self::umbau_links_html((int) $a['id']) . '</td><td>' . $status . '</td><td>' . ($meld !== '' ? '<ul style="margin:0 0 0 14px">' . $meld . (count($abw) > 3 ? '<li>… und ' . (count($abw) - 3) . ' weitere</li>' : '') . '</ul>' : '–') . $kl_ . '</td><td><a class="button" href="' . esc_url(add_query_arg(['page' => 'sab-umbau', 'vorschau' => $a['id']], admin_url('admin.php'))) . '">Einzelvorschau</a></td></tr>'];
        }
        $h .= '<table class="widefat striped"><thead><tr><th>Artikel</th><th>Status</th><th>Probleme</th><th></th></tr></thead><tbody>' . ($zeilen ? implode('', array_column((function (array $z) { usort($z, fn($a, $b) => $a[0] <=> $b[0]); return $z; })($zeilen), 1)) : '<tr><td colspan="4">Keine weiteren offenen Artikel.</td></tr>') . '</tbody></table>';
        $nGeprueft = $geprueft;
        $h = str_replace('</h3><p class="description">Es wird nichts gespeichert.', '</h3><p style="font-size:15px"><strong>Diese Seite: ' . count($bereit) . ' bereit · ' . $nProb . ' mit Problem · ' . max(0, $nGeprueft - count($bereit) - $nProb) . ' noch nicht fertig geprüft' . ($uebrig ? ' · ' . $uebrig . ' ungeprüft (Zeitgrenze)' : '') . '</strong> (' . $gesamt . ' offen insgesamt). Umbau in zwei Stufen: <strong>Stufe 1</strong> baut nur die Artikel ohne Problem um (oben „Alle problemlosen umbauen“ erledigt das für alle Artikel auf einmal), <strong>Stufe 2</strong> sind die Artikel mit Problem, sobald das Ersatzprodukt getauscht ist.</p><p class="description">Es wird nichts gespeichert.', $h);
        if ($uebrig) { $h .= '<p><strong>' . $uebrig . ' Artikel dieser Seite wurden wegen der Zeitgrenze noch nicht geprüft.</strong> Sie kommen auf der nächsten Seite dran.</p>'; }
        if ($kurzOffen) { $h .= '<p><strong>' . $kurzOffen . ' Kurzlinks wurden noch nicht aufgelöst</strong> (Abrufgrenze pro Aufruf), bitte diese Seite noch einmal öffnen.</p>'; }
        if ($wegAlle) {
            arsort($wegAlle);
            $h .= self::umbau_ersatz_html(array_keys($wegAlle), 0, '');
        }
        if ($bereit) {
            $h .= '<form method="post" style="margin:14px 0">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="umbau"><input type="hidden" name="sab_form" value="umbau_viele"><input type="hidden" name="sab_alle_vorschau" value="1"><input type="hidden" name="sab_ab" value="' . (int) $ab . '">';
            foreach ($bereit as $i) { $h .= '<input type="hidden" name="sab_ids[]" value="' . $i . '">'; }
            $h .= '<button class="button button-primary button-hero">Stufe 1: diese ' . count($bereit) . ' Artikel ohne Problem jetzt umbauen</button> <span class="description">Jeder Artikel wird vorher gesichert, Rückgängig geht in der Liste. Artikel mit Problem bleiben unberührt.</span></form>';
        } else { $h .= '<p><em>Auf dieser Seite ist kein Artikel bereit, deshalb gibt es hier keinen Umbau-Knopf. Erst die Probleme oben lösen (bei Kurzlinks: ASIN oder Link eintragen), danach diese Seite neu laden.</em></p>'; }
        $nav = '';
        if ($ab > 0) { $nav .= '<a class="button" href="' . esc_url(add_query_arg(['page' => 'sab-umbau', 'alle_vorschau' => 1, 'ab' => 0], admin_url('admin.php'))) . '">« Zum Anfang</a> '; }
        if ($geprueft && count(array_filter($rest, fn($a) => $a['id'] > $letzte)) > 0) { $nav .= '<a class="button" href="' . esc_url(add_query_arg(['page' => 'sab-umbau', 'alle_vorschau' => 1, 'ab' => $letzte], admin_url('admin.php'))) . '">Nächste ' . self::UMBAU_PROSEITE . ' Artikel »</a>'; }
        return $h . ($nav !== '' ? '<p>' . $nav . '</p>' : '');
    }

    public static bool $lauf_weiter = false;   // true, wenn die Seite direkt aus einem Klick auf „Alle problemlosen umbauen“ kommt (nur dann macht sie von selbst weiter)

    private static function umbau_lauf_stand(): array {
        return array_merge(['aktiv' => false, 'umgebaut' => 0, 'versuche' => [], 'probleme' => [], 'start' => 0, 'ende' => 0, 'rest' => 0], (array) get_option('sab_umbau_lauf', []));
    }

    /**
     * Stufe 1 des Umbaus: alle Artikel ohne Problem umbauen, Problemartikel (unbekannte Produkte, Abweichungen, Duplikate …) merken und überspringen.
     * Arbeitet in Schritten von etwa 30 Sekunden; die Seite macht von selbst weiter, bis nichts mehr zu prüfen ist.
     */
    private static function umbau_problemlos_schritt(bool $neu): string {
        $l = $neu ? ['aktiv' => true, 'umgebaut' => 0, 'versuche' => [], 'probleme' => [], 'start' => time(), 'ende' => 0, 'rest' => 0] : self::umbau_lauf_stand();
        $t0 = microtime(true); $umg = 0; $rest = 0;
        foreach (self::umbau_artikelliste() as $a) {
            $id = (int) $a['id'];
            if ($a['fertig'] || isset($l['probleme'][$id])) { continue; }
            if (microtime(true) - $t0 > 30) { $rest++; continue; }
            $post = get_post($id); if (!$post) { continue; }
            $problem = function (array $m, array $weg) use (&$l, $id, $a): void { $l['probleme'][$id] = ['t' => (string) $a['titel'], 'm' => array_slice(array_values(array_unique($m)), 0, 4), 'weg' => array_values(array_map('strval', array_keys($weg)))]; };
            self::$umbau_abw = []; self::$umbau_info = []; self::$umbau_weg = []; self::$umbau_keys = []; self::$kurz_offen = 0; self::$neue_namen = [];
            try { self::umbau_text((string) $post->post_content); }
            catch (\Throwable $e) { self::$umbau_abw[] = 'Prüfung abgebrochen: ' . $e->getMessage(); }
            if (self::$umbau_abw) { $problem(self::$umbau_abw, self::$umbau_weg); continue; }
            if (self::$kurz_offen) {   // Kurzlinks werden pro Aufruf nur zum Teil aufgelöst: im nächsten Schritt weiter
                $v = (int) ($l['versuche'][$id] ?? 0) + 1; $l['versuche'][$id] = $v;
                if ($v >= 10) { $problem(['Kurzlinks konnten nicht aufgelöst werden.'], []); } else { $rest++; }
                continue;
            }
            self::$umbau_abw = []; self::$umbau_info = []; self::$skip_gruende = []; $gea = 0; $sk = 0;
            try { [$n, $sk, $gea] = self::umbau_artikel($id); }
            catch (\Throwable $e) { self::$umbau_abw[] = 'Umbau abgebrochen (' . $e->getMessage() . '), Artikel unverändert'; }
            if (self::$umbau_abw) { $problem(self::$umbau_abw, self::$umbau_weg); }
            elseif ($gea) { $umg++; $l['umgebaut'] = (int) $l['umgebaut'] + 1; }
            else { $problem(['Nichts umgebaut' . ($sk ? ' (' . $sk . ' übersprungen: ' . (self::$skip_gruende ? implode(' | ', array_slice(array_unique(self::$skip_gruende), 0, 4)) : 'Grund unbekannt') . ')' : '') . '.'], []); }
        }
        $l['rest'] = $rest;
        if ($rest === 0) { $l['aktiv'] = false; $l['ende'] = time(); }
        update_option('sab_umbau_lauf', $l, false);
        self::$lauf_weiter = $rest > 0;
        return $umg . ' Artikel ohne Problem umgebaut (insgesamt in diesem Lauf: ' . $l['umgebaut'] . '). ' . count($l['probleme']) . ' Problemartikel bleiben unberührt und stehen unten.' . ($rest > 0 ? ' Noch ' . $rest . ' Artikel zu prüfen, es geht gleich automatisch weiter.' : ' Der Lauf ist fertig.');
    }

    /** Anzeige zum Lauf „Alle problemlosen umbauen“: Stand, Weiter-Knopf, danach die Problemartikel und deren Problemprodukte (zuletzt). */
    private static function umbau_lauf_html(bool $mit_karten): string {
        $l = self::umbau_lauf_stand();
        if (!$l['start']) { return ''; }
        $np = count($l['probleme']);
        $h = '<div style="border:2px solid #2271b1;background:#fff;padding:8px 16px;margin:12px 0;max-width:1100px"><h3 style="margin:.4em 0">Alle problemlosen umbauen: ' . ($l['aktiv'] ? 'läuft' : 'fertig') . '</h3>'
           . '<p><strong>' . (int) $l['umgebaut'] . '</strong> Artikel umgebaut, <strong>' . $np . '</strong> Problemartikel übersprungen' . ($l['aktiv'] ? ', <strong>' . (int) $l['rest'] . '</strong> noch zu prüfen' : '') . '. Gestartet ' . esc_html(wp_date('d.m. H:i', (int) $l['start'])) . ($l['ende'] ? ', beendet ' . esc_html(wp_date('d.m. H:i', (int) $l['ende'])) : '') . '.</p>';
        if ($l['aktiv']) {
            $h .= '<form method="post" id="sab-lauf-weiter">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="umbau"><input type="hidden" name="sab_form" value="umbau_problemlos_weiter"><button class="button button-primary">Weiter</button> <span class="description">' . (self::$lauf_weiter ? 'Die Seite macht gleich von selbst weiter. Bitte offen lassen.' : 'Der Lauf wurde unterbrochen: mit „Weiter“ geht es dort weiter, wo er aufgehört hat.') . '</span></form>'
                . (self::$lauf_weiter ? '<script>setTimeout(function(){var f=document.getElementById("sab-lauf-weiter");if(f){f.submit();}},1500);</script>' : '');
        }
        $h .= '</div>';
        if ($np && !$l['aktiv']) {
            $h .= '<h3>Problemartikel (' . $np . '), zuletzt zu klären</h3><p class="description">Diese Artikel wurden nicht angefasst. Meist fehlt ein Ersatz für ein Produkt, das Amazon nicht mehr kennt: unten gleich tauschen, dann „Alle problemlosen umbauen“ erneut starten.</p><table class="widefat striped" style="max-width:1100px"><thead><tr><th>Artikel</th><th>Problem</th><th></th></tr></thead><tbody>';
            foreach ($l['probleme'] as $pid => $pr) {
                $m = ''; foreach ((array) $pr['m'] as $t) { $m .= '<li>' . esc_html($t) . self::ort_links($t, (int) $pid) . '</li>'; }
                $h .= '<tr><td><a href="' . esc_url((string) get_permalink((int) $pid)) . '" target="_blank" rel="noopener">' . esc_html($pr['t'] ?: ('Beitrag ' . $pid)) . '</a></td><td><ul style="margin:0 0 0 14px">' . $m . '</ul>' . self::kurzlink_liste_html((int) $pid) . '</td><td><a class="button" href="' . esc_url(add_query_arg(['page' => 'sab-umbau', 'vorschau' => (int) $pid], admin_url('admin.php'))) . '">Einzelvorschau</a></td></tr>';
            }
            $h .= '</tbody></table>';
            if ($mit_karten) {
                $zaehl = [];
                foreach ($l['probleme'] as $pr) { foreach ((array) $pr['weg'] as $w) { $zaehl[(string) $w] = ($zaehl[(string) $w] ?? 0) + 1; } }
                arsort($zaehl);
                if ($zaehl) { $h .= '<h3>Problemprodukte (' . count($zaehl) . '): Ersatz wählen</h3>' . self::umbau_ersatz_html(array_keys($zaehl), 0, '', 10, 'lauf'); }
            }
        }
        return $h;
    }

    /** Tausch ALT => NEU in allen Artikeln (mit Sicherung, Kurzlinks der Spalte, Titel, Schutz vor Doppelung). @return string Meldung für den Redakteur */
    /** Neues Produkt vor dem Tausch holen: Daten und Bild in allen Ländern mit Partner-ID speichern (Artikel können ein eigenes Land haben). Wahr, wenn es im Standardland samt Bild da ist. */
    private static function neues_produkt_vorladen(string $neu): bool {
        $std = self::land(''); $ok = false;
        $laender = array_values(array_unique(array_merge([$std], array_filter(array_keys(self::tags()), fn($l) => isset(self::LAENDER[$l])))));
        foreach ($laender as $l) {
            $pn = self::produkt($neu, $l, true, true);
            if (!self::hat_bild($pn)) { continue; }
            $u = self::bild_url($pn, $neu, $l, 0, true);
            if ($l === $std && $u !== '') { $ok = true; }
        }
        return $ok;
    }

    private static function tausch_ausfuehren(string $alt, string $neu, string $name): string {
        if (!self::neues_produkt_vorladen($neu)) { return 'NICHT getauscht: Für ' . $neu . ' liefert Amazon kein Bild oder keinen Link, oder das Bild ließ sich nicht speichern (Produkt unbekannt oder Abruf fehlgeschlagen). Bitte ein anderes Ersatzprodukt wählen oder in einer Minute noch einmal versuchen.'; }
        update_option('sab_ungueltige_asins', array_values(array_diff(array_map('strval', (array) get_option('sab_ungueltige_asins', [])), [$neu])), false);   // Amazon hat das neue Produkt gerade geliefert: nie als „unbekannt“ führen, bis die Übersicht es selbst kennt
        $d0 = self::uebersicht_daten(self::land(''), false); $f0 = self::fundstellen([$alt]); $an = [(string) (self::produkte()[$alt] ?? ''), (string) ($d0['items'][$alt]['titel'] ?? '')];
        if (!$an[1] && !empty($f0[$alt])) { $p0 = get_post((int) array_key_first($f0[$alt])); $an[] = $p0 ? self::kontext_titel((string) $p0->post_content, $alt) : ''; }
        $eigenerName = $name !== '';
        if ($name === '') { $name = ($m = (self::produkte()[$neu] ?? '')) !== '' ? $m : self::kurzname_aus_titel((string) ($d0['items'][$neu]['titel'] ?? '')); }
        if ((self::produkte()[$neu] ?? '') === '' && !$eigenerName) {   // neues Produkt ohne eigenen Kurznamen: Liste „Kurzname prüfen“, die Mac-Sitzung vergibt einen guten Namen
            $of = (array) get_option('sab_name_offen', []); $of[$neu] = $name; update_option('sab_name_offen', $of, false);
        }
        [$na, $nk, $fe, $ue, $hi] = self::tausch_festschreiben($alt, $neu, $name, $an);
        $meldung = $fe !== '' ? $fe : $alt . ' → ' . $neu . ' in ' . $na . ' Artikel(n) geschrieben.' . ($nk ? ' In diesen Artikeln stehen noch ' . $nk . ' Kurzlinks, die erst der Umbau erfasst.' : '');
        if (!empty($hi)) { $meldung .= ' Hinweise: ' . implode(' | ', array_slice($hi, 0, 6)) . '.'; }
        if (!empty($ue)) { $meldung .= ' NICHT getauscht (Produkt stünde dort doppelt nebeneinander oder zu nah): ' . implode('; ', array_slice($ue, 0, 8)) . '.'; }
        return $meldung;
    }

    /** Amazons „alternative Produkte“ zu nicht mehr verfügbaren Produkten: Zeilen „ALTE-ASIN | ASIN1, ASIN2, ASIN3 | Datum“ (liest die Mac-Sitzung von der Amazon-Seite). */
    private static function amz_alternativen(): array {
        $out = [];
        foreach (preg_split('/\R/', (string) get_option('sab_amz_alt', '')) as $z) {
            $t = array_map('trim', explode('|', $z));
            $a = self::asin($t[0] ?? ''); if (strlen($a) !== 10) { continue; }
            $l = array_values(array_filter(array_map([__CLASS__, 'asin'], explode(',', (string) ($t[1] ?? ''))), fn($x) => strlen($x) === 10));
            if ($l) { $out[$a] = ['liste' => $l, 'datum' => (string) ($t[2] ?? '')]; }
        }
        return $out;
    }

    /** Treffer der Amazon-Suche nach dem Produkttitel (liest die Mac-Sitzung): Zeilen „ALTE-ASIN | ASIN1, ASIN2, ASIN3 | Datum“. */
    /** „Kunden kauften auch“: Zeilen <code>ASIN | ASIN1, ASIN2, … | Datum</code> (von der Mac-Sitzung von der Amazon-Produktseite gelesen). */
    private static function auch_gekauft(): array {
        $out = [];
        foreach (preg_split('/\R/', (string) get_option('sab_auch_gekauft', '')) as $z) {
            $t = array_map('trim', explode('|', $z));
            $a = self::asin($t[0] ?? ''); if (strlen($a) !== 10) { continue; }
            $l = array_values(array_unique(array_filter(array_map([__CLASS__, 'asin'], explode(',', (string) ($t[1] ?? ''))), fn($x) => strlen($x) === 10 && $x !== $a)));
            if ($l) { $out[$a] = ['liste' => $l, 'datum' => (string) ($t[2] ?? '')]; }
        }
        return $out;
    }

    /** Wie oft jede ASIN in den „Kunden kauften auch“-Listen vorkommt, höchste zuerst. @return array<string,int> */
    private static function auch_haeufigkeit(): array {
        $z = [];
        foreach (self::auch_gekauft() as $l) { foreach ($l['liste'] as $a) { $z[$a] = ($z[$a] ?? 0) + 1; } }
        arsort($z);
        return $z;
    }

    /** Einmal-Importe: feste Zeilen, die pro Seite genau einmal ergänzt werden (nur neue ASINs, nichts überschreiben). Ersetzt das Eintippen im Backend. */
    const IMPORTE = [
        '2026-10-26-such-kand' => ['host' => 'schneckenhilfe.de', 'option' => 'sab_such_kand', 'zeilen' => [
            'B08562GF55 | B00B8QSSZY, B0DK7X57NC, B07BHMJKFJ | 2026-10-26',
            'B085631P3W | B00B8QSSZY, B0DK7X57NC, B07BHMJKFJ | 2026-10-26',
            'B01EC7PRMW | B00B8QSSZY, B006DUH03G, B0DK7X57NC | 2026-10-26',
            'B07SC2VNHM | B0DK7X57NC, B00B8QSSZY | 2026-10-26',
            'B07S8XF8P6 | B0DK7X57NC, B00B8QSSZY | 2026-10-26',
            'B06XJ2GV3H | B0CG9Y4Q6F, B09KNCD6G1, B0838CTXD7 | 2026-10-26',
        ]],
        '2026-10-26-such-kand-feder' => ['host' => 'federhilfe.de', 'option' => 'sab_such_kand', 'zeilen' => [
            'B01MU8UQ64 | B004WO8ZIE, B000WFLAMA, B091TXGPP9 | 2026-10-26',
            'B08VKRJ5D4 | B0037YHTMS | 2026-10-26',
            'B09VH7LSN7 | B08CXDRVFQ, B085TM4GRC | 2026-10-26',
            'B07R2DK75K | B0BSLTNV6R, B098D8VG8F, B08SYNFTFG | 2026-10-26',
            'B0769DG96T | B0037YHTMS | 2026-10-26',
            'B0869LW28K | B000WFLAMA, B00OY271L6, B004WO8ZIE | 2026-10-26',
            'B081D5NHVC | B09GBB2GQR, B08PZCCTJ8 | 2026-10-26',
            'B074G11BCW | B0D12L44BN, B08W4P33M6 | 2026-10-26',
            'B08NVW6Z45 | B09GBB2GQR | 2026-10-26',
            'B004SUHRI6 | B0CZ6PJGBQ, B0CZ6WLJHB | 2026-10-26',
            'B005MIBCMK | B0D12L44BN, B0D251WX3G, B0CZ415595 | 2026-10-26',
            'B002RXARXS | B0B2PYMX9F, B00NJQTHJC, B083YSS2MC | 2026-10-26',
            'B09X65D63W | B000WFLAMA, B004WO8ZIE, B091TXGPP9 | 2026-10-26',
            'B01M8KINM2 | B0D12L44BN, B08W4P33M6 | 2026-10-26',
        ]],
        '2026-10-26-such-kand-schnecken-2' => ['host' => 'schneckenhilfe.de', 'option' => 'sab_such_kand', 'zeilen' => [
            'B01EUS5G1U | B0F1DB4ZDL, B0FHRGMQ48, B0F93PWHPV | 2026-10-26',
            'B00D1VO916 | B07ST2P369, B0CDH4R958 | 2026-10-26',
            'B0167513IO | B08JGX3NYM, B08RNM1D5H, B06XDQW7BF | 2026-10-26',
        ]],
        '2026-10-26-such-kand-zecken-1' => ['host' => 'zeckenhilfe.de', 'option' => 'sab_such_kand', 'zeilen' => [
            'B00E5AK7ZE | B001QC34LS, B07FTRT8WN, B07F89JFH6 | 2026-10-26',
            'B008BI50OY | B07PRJFSWC, B07F89JFH6, B001QC34LS | 2026-10-26',
            'B00F0LVLUM | B08DDF49L6, B06VW88X76 | 2026-10-26',
            'B0983557BB | B0DBHR1XK3, B001EJOHYG | 2026-10-26',
        ]],
        '2026-10-26-such-kand-feder-2' => ['host' => 'federhilfe.de', 'option' => 'sab_such_kand', 'zeilen' => [
            'B08KJ9WTDN | B085CX8W6B, B073T9SM39 | 2026-10-26',
            'B0031HDYFM | B0001IP95C, B0001IP95M | 2026-10-26',
        ]],
        '2026-10-26-such-kand-zecken-2' => ['host' => 'zeckenhilfe.de', 'option' => 'sab_such_kand', 'zeilen' => [
            'B01E7I39GW | B07DLVKMT3, B07LG6W18Y, B07SJ6TXYG | 2026-10-26',
            'B00EF3X028 | B01CWAP0F0, B07DJD9DJM, B09W5V82K6 | 2026-10-26',
            'B01CXA3OW0 | B0B5H15521, B093LR5P62, B0F9FV7GKX | 2026-10-26',
        ]],
        '2026-10-26-such-kand-schnecken-3' => ['host' => 'schneckenhilfe.de', 'option' => 'sab_such_kand', 'zusammen' => true, 'zeilen' => [   // PC-Sitzung: bis 8 Kandidaten, neue vor die vorhandenen
            'B00D1VO916 | B07ST2P369, B0CDH4R958, B0944BTD7P, B09419LZSW, B07PRX8YHY, B0872JB5HX, B0C5XYJ9RR, B08L5JYG5B | 2026-10-26',
            'B0167513IO | B08JGX3NYM, B08D6RGB1H, B07HKJZCVL, B08RNM1D5H, B06XDQW7BF, B00VFYMPCQ, B00I4XWO7C, B07HKD8LWQ | 2026-10-26',
        ]],
        '2026-10-26-such-kand-zecken-3' => ['host' => 'zeckenhilfe.de', 'option' => 'sab_such_kand', 'zeilen' => [   // PC-Sitzung: automatisch nach Name (ab 40 %), ungeprüft; Bauart prüft der Austausch beim Anzeigen; nur Produkte ohne bisherige Kandidaten
            'B00EB0SPQG | B008ALWD24, B0F7LDDGNS, B0D4R348MW, B0CP6581P5, B0058WXMSW | 2026-10-26',
            'B00UFMDYP6 | B00U7BTLZM, B09JYZLD6X, B07VRBMWJ9, B01A8D0GSO, B077HXRKJ3 | 2026-10-26',
            'B0061WFSIK | B0CXDTVM3C | 2026-10-26',
            'B00LOCJZCW | B0827XBBVK, B008ALWD24, B00PE5VBAY, B0FC56TKWB | 2026-10-26',
            'B00I227TP2 | B00LAY9ZME, B00LAYA4P6, B01D01RBAC, B01D01QX9W, B01F24W42U | 2026-10-26',
            'B00GYZXS18 | B004EF3SH4, B00GULCCFY | 2026-10-26',
            'B015FPEG2W | B0FBS9C2G2, B01MF5TXQJ, B008ALWD24, B0FC56TKWB | 2026-10-26',
            'B08594YVZ9 | B00JDQQMXQ, B08641NGH6, B0F4QTWRZX, B00JDQT2O2, B08X4TWLL1 | 2026-10-26',
            'B07F86MB3H | B0CD8DQ22Y | 2026-10-26',
            'B00EX6H42E | B00E67W1IM, B09HKSCVQG, B07PRJFSWC, B07F89JFH6, B07CR8FXDY | 2026-10-26',
            'B00UVIDN7S | B07FTRT8WN, B0BVG1CX5H, B0F9PQL5RX, B0CBBS6FQ9 | 2026-10-26',
            'B0831H9G94 | B07DLVKMT3, B07LG6W18Y, B07SJ6TXYG | 2026-10-26',
        ]],
        '2026-10-26-such-kand-feder-5' => ['host' => 'federhilfe.de', 'option' => 'sab_such_kand', 'zusammen' => true, 'zeilen' => [   // PC-Sitzung: bis 8 Kandidaten je Produkt, neue vor die vorhandenen; Bauart prüft der Austausch beim Anzeigen
            'B07R2DK75K | B0D9JTH8DW, B08CB48SX7, B08P4Q969L, B0BSN188DT, B08P5TT7NF, B08SYWXRKF, B0B21K47D4, B0CX5GWWDT | 2026-10-26',
            'B01MU8UQ64 | B0CWP2NJGV, B0CK3W738W, B08P5Q97NF, B0CG6D8KJN, B0CF5QF6DT, B09C8WY395, B0CXJFT8VK, B0CX6GF8HN | 2026-10-26',
            'B074G11BCW | B0D12L44BN, B0DN5Q2PS4, B0D2XBS1LY, B0FG7KJ7NG, B0CRHTL846, B0G2XNQ7PZ, B0C2KZDXSH, B071R8XTHB | 2026-10-26',
            'B07PYHJLMC | B00JA9UJW6, B0DN5Q2PS4, B0D2XBS1LY, B0D679PSYT, B071R8XTHB, B0GJ8L6N6Y, B0GFN8LF9F, B0GS94JRDQ | 2026-10-26',
            'B08NVW6Z45 | B0CFVP59DL, B09GBB2GQR, B096RWQTL1, B08N4GBV35, B0D679PSYT, B08T7QB2RW, B0GGBK6VJ2, B0DKN3RZGD | 2026-10-26',
            'B01M8KINM2 | B07VJ5YW3D, B093XVHQ6D, B0H138BPXC | 2026-10-26',
            'B005MIBCMK | B00JA9UJW6, B00J6C9XMY, B00J6CCT9S, B00J6C1YSK, B00J6C6FHA, B08WHSTTQQ, B0D12L44BN, B0D251WX3G | 2026-10-26',
            'B09X65D63W | B0D946TSVN | 2026-10-26',
            'B002RXARXS | B0D31SBQNG, B0CCNJXPDT, B0FGJFY77V, B0BHSRR2HW, B0B1DJLQKT, B0D31S5W33, B0D31SK5K8 | 2026-10-26',
            'B007BQLV5E | B0BFJL4445, B0CWB19KTM, B003NWTMJC, B07B2JFY22 | 2026-10-26',
            'B09C9NRQJ7 | B0DKCFLFKW, B07VWW2XYX, B0CL2QWS38, B0CG1KT18B, B002CX0BSO, B002SKTJJS, B0CJYHKTLC, B0CJYG9XPZ | 2026-10-26',
            'B08CMNG99T | B08284C5S9, B0842V8K73, B08CMZPR7W, B087D5GG1C, B0CGV7439P, B0D8W312DB, B0D8W1DL9J, B0D8W9878Z | 2026-10-26',
            'B083M2SM3Y | B0CGV7MVHS, B0CGV6D865, B08CMZPR7W, B0CGV7C5Y7, B0CGV9Z17L, B0D8W312DB, B0D8W48747, B0D8VZGBZ4 | 2026-10-26',
            'B083M4K3RL | B0CGV9Z17L, B08CMZPR7W, B0D8W312DB, B0D8W48747, B0D8VZGBZ4, B0CGV7MVHS, B0CGV6D865, B0D8TGMDD5 | 2026-10-26',
            'B083M1B2LT | B0CGV727H2, B0CGV81PGM, B0D8W45HB5, B0D8VZGBZ4, B0D8W1DL9J, B08PL2T4WV, B08CMZPR7W, B0CGV7878N | 2026-10-26',
            'B08CMLN5VM | B0D8W48747, B0CGV7BYBN, B0D8VZGBZ4, B0CGV6D865, B0D8TGXLHJ, B08PL2T4WV, B08CMZPR7W, B0CGV7439P | 2026-10-26',
            'B08CN33RN6 | B0CGV7BYBN, B0D8TGXLHJ, B0D8W48747, B0CGV9Z17L, B0CGV5W9ZQ, B0CGV6D865, B0D8VZGBZ4, B0D8TGMDD5 | 2026-10-26',
            'B07PJLHWKS | B07CZHNJ86, B085DNPH2C, B0CFYC456B, B0CF5L3RWJ, B0CFF9ZC7T, B0CFF5STTZ, B0CGV33WHG, B0CFVJ8QLD | 2026-10-26',
            'B07YLRJWL8 | B0BSLTNV6R, B078WT4BGD, B0BSFWT7TF, B0CFYC456B, B0DB1VBTVV, B0CZ6PJGBQ, B0CFYBVH9J, B0FJRPTLCC | 2026-10-26',
            'B00PEB3WF0 | B00G44JBF6, B0B59SNJHN, B00PEB43KS, B00PEB3R2S, B00G44JKVG, B00G44J804, B00PEB3OWQ, B00HU6IHEI | 2026-10-26',
            'B01GSY9MD2 | B07W3PH15D, B0F83MVZQ7, B0CNKVCSMJ, B0D942HFY6 | 2026-10-26',
            'B0741D4LGG | B0B15C8TC1, B09SLSKYLF, B07FYNYV15, B09ZLBV3DN | 2026-10-26',
            'B00AJGKZC8 | B00AJGL6U8, B0CZTZDYX2, B0CZTZZHKS, B00B0CQEMK, B0DG9BZVL7, B08JVCS5VW, B08FMJFN5X | 2026-10-26',
            'B00OJ7AIT8 | B00ENIOF4C, B00ENIOFG0, B006Z40T0Q, B007FG05WA, B07DNKNZD4, B008MHZ2UQ, B08WLZPZ47, B07LBYHM29 | 2026-10-26',
            'B0869PHW9P | B0CWB19KTM, B08DD2G82Z, B085YCVWPQ, B0CZ6JBSMG | 2026-10-26',
            'B005YCM6OC | B086C4YR95, B002HHP13K | 2026-10-26',
            'B086C4YR95 | B086C979Q8, B01N6KLJIX, B009LI2B1S, B077Z5Q1HR, B0BSFWT7TF, B00P160HWO, B0852S4847, B00W1F4SXW | 2026-10-26',
            'B00WMHPEQE | B00FEDRUSI, B0F5HV4Z42, B07KSN3D53, B00SVAJEV2, B08W5F2GCV, B0BG2C44LP, B0BG2BYCNC, B08W59C3YQ | 2026-10-26',
            'B005VQ8NBQ | B0D8LDTF4Q, B005YCM6OC, B002HHP13K | 2026-10-26',
            'B00G2J026U | B0066N98V2, B00G2J015C, B0066NB19O, B00466F41O, B000WFI9R4, B000WFI9Q0, B004DI9PT2, B00G2IZYSM | 2026-10-26',
            'B009FASV5W | B09DSVWRKX, B016QE4QOE, B00P160HWO, B0BNF4F95W, B0CFF9ZC7T, B0FL6N7KKM, B016QE4JDM, B0H2C1ZNQ1 | 2026-10-26',
            'B09LF76TZD | B09LFFZMVQ, B07MTFDBRF | 2026-10-26',
            'B0073GYFHS | B005YCM6OC, B002HHP13K, B005VQ8NBQ, B081VM8M97, B002AX4N0S, B01JBFUCB6 | 2026-10-26',
            'B002U6IU1S | B0001IP95M, B0001IP93O, B00H6HPSD4, B0001IP94I, B0001IP952, B07QQLLBSC | 2026-10-26',
            'B0037YHTMS | B0F5HW6W31, B07C9W8VCS, B00I03WRXM, B001E50USW, B001P3SUX0, B08M98NC1C, B08M8KF5NT, B08M94PPT8 | 2026-10-26',
            'B00VV8L298 | B00VV8KXZM, B0CG6FVWDP, B00OY274M2, B0BFJL4445, B0CGVGF4X7, B0BG4SQ6TV, B0BVRDFQ3P, B0BFJKGS2G | 2026-10-26',
            'B09X44H5X6 | B0BP7PQ7SG, B09YDLG2FT, B0D8Q8MDRN, B0CK4393LH, B00VV8KXZM, B07QQLLBSC | 2026-10-26',
            'B08M3FKVSL | B0D8PYWZ88, B0CG6FVWDP, B07M85TYQY, B08VS6XFD9, B08YFH81HG, B08YFGDRMX, B091TXGPP9, B0CG6D8KJN | 2026-10-26',
            'B07PJW211S | B07PDHJ4ML, B07MTFDBRF, B0CJV1W2VQ, B07KFLV7MW, B08M66FH21, B09QHRVJPH, B0CWB19KTM, B0BKQBGNDT | 2026-10-26',
            'B08M8KF5NT | B08M98NC1C, B08M94PPT8, B0CGLY63PV | 2026-10-26',
            'B00JPE65Z6 | B0852S4847, B083JMP12D, B0852RPP5V, B083JMTGNJ | 2026-10-26',
            'B01CYS5C7Q | B0CXJFT8VK, B09JLLPD5S, B005446EXY, B0G53K8TSJ, B07BXSP2R8, B01CYS5BX6 | 2026-10-26',
            'B01M3S25LR | B0G574MKHH, B0D1JYSBP1, B01M2ZDF57, B0013V15YA, B00JPE65Z6, B09QHRVJPH, B0B3GZ62ZJ, B09B2FVL2B | 2026-10-26',
            'B09FSPYFYG | B0H2M42DFQ, B0CXJFT8VK, B0CX6GF8HN, B08NW5FH5V, B07BXSP2R8, B0BSHCJRDM, B085N96FZJ, B0CK4393LH | 2026-10-26',
            'B001E52XWI | B01BM4GV64, B000QINBZC, B001GXWHBK, B00VTS9MRE, B01DI733DM, B01BM4GWWW, B01BM4GVZK, B001E50USW | 2026-10-26',
            'B087H6XD97 | B0852S4847, B087D76TK4, B0852RPP5V, B0G5B8RLVF, B084WWSKGP, B0CWB19KTM, B0BN4PF6BS, B0CB2WN4SV | 2026-10-26',
            'B085BPN1BG | B0CTMQ63F8, B09M8YY894, B0CG6D8KJN, B0D946TSVN, B0CXJFT8VK, B0CX6GF8HN, B0DNSMV7TM, B0CLB75SF1 | 2026-10-26',
            'B0816L8XGJ | B07KSN3D53, B0C6LYP7FN, B07KSD9RWJ, B0BR6JQQGM, B0CFY4YNXP, B0CGV7439P, B0CFF5STTZ, B0F6371XT8 | 2026-10-26',
            'B00518JSGI | B00518MI9C, B00518IE86, B07J6NR9SQ | 2026-10-26',
            'B00H6HPSD4 | B0CGLY63PV, B0GZ3CC8J7, B07QMYD8F3 | 2026-10-26',
            'B08M94PPT8 | B08M8KF5NT, B08M98NC1C, B0CGLY63PV | 2026-10-26',
            'B07TBKS6V9 | B083JMT9T6, B083JMTGNJ, B0BFJKGS2G, B0CGLY63PV, B07B2JFY22 | 2026-10-26',
            'B07QMYLZRD | B07QMYD8F3 | 2026-10-26',
            'B084C219C3 | B0CGLY63PV, B0GZ3CC8J7 | 2026-10-26',
            'B000QSGILG | B0001IP95M, B0001IP93O, B0001IP9YS, B0001IP94I, B0001IP934, B00A6F4VFO | 2026-10-26',
            'B0001IP9YS | B0001IP94S, B0001IP94I, B0001IP934, B0001IP95M, B0001IP93O, B000QSINPA, B0001IP95W, B001493YEA | 2026-10-26',
            'B085N96FZJ | B085N89QYT, B08X2WVNGZ, B085N8VNWT, B0D8PYWZ88, B0CF5QF6DT, B0852S4847, B083JMT9T6, B09DSN641T | 2026-10-26',
            'B083Z98H8X | B0D8Q8MDRN, B0842V8K73, B07ZFYKLM4, B0843HQW9V | 2026-10-26',
            'B0CK4393LH | B0CK3W738W, B07KKMMF57, B0CXJFT8VK, B07BXSP2R8, B0D8Q8MDRN, B0BP7PQ7SG | 2026-10-26',
            'B09T1C4YXC | B0D88CGK39, B0C872RHRK, B085N89QYT, B085N96FZJ, B08X2WVNGZ, B085N8VNWT | 2026-10-26',
            'B0G4VTPF2J | B0G4VSWP21 | 2026-10-26',
            'B08W5F2GCV | B00FEDRUSI, B0F5HV4Z42, B07KSN3D53, B07KSD9RWJ, B00SVAJEV2, B0BG2C44LP, B088L46J1S, B0BG2BYCNC | 2026-10-26',
            'B00GQH1OYC | B0F5HWSTJQ | 2026-10-26',
            'B09BYYMVVD | B016QE4QOE, B00EYPPMX2, B08P4Q969L, B0BN4MR55X, B016QE4JDM, B0BN4PF6BS, B09C1NJ6BW, B08P5TT7NF | 2026-10-26',
            'B08SYNFTFG | B08SYNVZRR, B07VWW2XYX, B09NBMR317, B09NBMMS32, B00P160HWO, B086Q9Q3KZ, B0DKCFLFKW, B0FJ2KWW2Y | 2026-10-26',
            'B08F7XF8R1 | B07ZFYKLM4, B0843HQW9V, B08284C5S9, B0842V8K73, B08CMZPR7W, B087D5GG1C, B089M9XX5M, B0DM9DBWV6 | 2026-10-26',
            'B0CS31QGHQ | B0BV29724V, B0B3MPG1N6, B0C5HW1S26, B0DQ6WPHSH, B0DJ2G12RT, B0BV28FRPD, B0GF1MNJN6, B0DSG7X5ZK | 2026-10-26',
            'B00AXXZ1ZI | B07MTFDBRF, B08SYNFTFG, B08K9B8L7F, B00QNT3L30, B08SYNVZRR, B0D1JYSBP1, B07VWW2XYX, B0CFF9ZC7T | 2026-10-26',
            'B003CGXI2G | B073T9SM39, B0CG6D8KJN, B095RBG5SH, B0CZ6WLJHB, B0GXDPM5HP, B0D946TSVN, B09C8VLHWQ, B0CXJFT8VK | 2026-10-26',
            'B096VWTT3F | B084QQS1Y6, B0D31SK5K8 | 2026-10-26',
            'B08BNMHSDM | B0CYSJHJ9R | 2026-10-26',
            'B000QSINPA | B0001IP95M, B0001IP94S, B0001IP9YS, B0001IP94I, B00A6F4VFO, B0001IP95W, B001493YEA, B0001IP952 | 2026-10-26',
            'B00A7EE9Y2 | B000QSINPA | 2026-10-26',
            'B01DAFTNEU | B0GVJP4THF, B0FXRFM3Z4, B0GTND8FD2, B097B5TJQB, B0DXPGTKTK | 2026-10-26',
            'B073YBM8TH | B08HMZ2VLQ, B0B7NZW5XK, B08W8GTWNT, B0C5819971, B0B3HZQW3L, B0FWBYGK4F, B0FWBY8N2P, B0CD7RV9YJ | 2026-10-26',
            'B083M3MJYB | B0CGV7439P, B0D8W48747, B0CGV7BYBN, B0CGV5W9ZQ, B0CGV6D865, B0D8VZGBZ4, B0D8WFHW2H, B0CGV7M582 | 2026-10-26',
            'B08BZTGT4W | B08FTBY4L4, B08FTC6QT5, B08FT8YF5P, B0CFXZNFX9, B0CFXYSQCW, B0CFY4YNXP, B0CFF5STTZ, B085DNPH2C | 2026-10-26',
            'B07GBNVT5K | B00KS3YLKE, B0157V16GS, B00ANZL2UE, B00AA7XCVM, B00N8L397O, B00ANZL56K, B00ANZL59W, B00AA7XD3E | 2026-10-26',
        ]],
        '2026-10-26-such-kand-maulwurf-2' => ['host' => 'maulwurfhilfe.de', 'option' => 'sab_such_kand', 'zusammen' => true, 'zeilen' => [   // Mac-Sitzung: Maulwurf-Schrecken alle Solar (teils 4er-Sets); neue vor die vorhandenen
            'B0H4RCDFW6 | B0H4R83YBT, B0HMGJPJ16, B0H5WH8YWJ, B0HGQ2RZBQ, B0HGQDWB2F, B0H29XCMHV, B0HKXR1BV9, B0GXDQ93CY | 2026-10-26',
            'B07PHCWNH1 | B0H29XCMHV, B0H4R83YBT, B0GXDQ93CY, B0HMGJPJ16, B0HGQ2RZBQ, B0H5KR79XS, B0HKXR1BV9, B0HGQDWB2F | 2026-10-26',
            'B07PH1FNSP | B0H29XCMHV, B0H4R83YBT, B0GXDQ93CY, B0HMGJPJ16, B0HGQ2RZBQ, B0H5KR79XS, B0HKXR1BV9, B0HGQDWB2F | 2026-10-26',
            'B0C3RM9RDH | B0H29XCMHV, B0H4R83YBT, B0GXDQ93CY, B0HMGJPJ16, B0HGQ2RZBQ, B0H5KR79XS, B0HKXR1BV9, B0HGQDWB2F | 2026-10-26',
            'B09DGVNDDG | B00EIP9F8G, B0FXB6V2BG, B08KKCSFV9, B0DFYKKFSB, B0B4PQQVTH, B0D9MFN7CQ, B0FX691X1T, B09HNSLTBJ | 2026-10-26',
            'B09DGTGY8Y | B00EIP9F8G, B0FXB6V2BG, B08KKCSFV9, B0DFYKKFSB, B0B4PQQVTH, B0D9MFN7CQ, B0FX691X1T, B09HNSLTBJ | 2026-10-26',
            'B07K19PB9C | B00EIP9F8G, B0FXB6V2BG, B08KKCSFV9, B0DFYKKFSB, B0B4PQQVTH, B0D9MFN7CQ, B0FX691X1T, B09HNSLTBJ | 2026-10-26',
            'B09Q363DTB | B00EIP9F8G, B0FXB6V2BG, B08KKCSFV9, B0DFYKKFSB, B0B4PQQVTH, B0D9MFN7CQ, B0FX691X1T, B09HNSLTBJ | 2026-10-26',
            'B09WYY1K18 | B0BQLHM1C5, B0C3CRVH74, B07QW9C6NY, B0GGQN4XZ8, B0CQC6Q1TM, B0GSG6ZDGG, B0C5T47S73 | 2026-10-26',
            'B0182BTRI4 | B0BW4BNGCF, B07SWBVL3R, B07PDGRWDB, B0DLH2HJDT, B0G3LVM8FG, B0F24HCM59, B0FR8CSYLD, B0F3JRRJCD | 2026-10-26',
        ]],
        '2026-10-26-such-kand-schnecken-4' => ['host' => 'schneckenhilfe.de', 'option' => 'sab_such_kand', 'zusammen' => true, 'zeilen' => [   // Mac-Sitzung: Schneckenzaun nur 6 Treffer, B07BHMJKFJ/B08SW421HX/B09TTYK5HZ/B006DUH03G wenige Bewertungen (weniger sicher); Saat: Wildblumen-/Bienenweide
            'B08562GF55 | B00UVQQYFI, B00B8QSSZY, B07BHMJKFJ, B08SW421HX, B09TTYK5HZ, B006DUH03G | 2026-10-26',
            'B00D1VO916 | B07MK3Q8G5, B07RD876TC, B07KVM35TW, B07ST2P369, B0GT56XWFJ, B0CDH4R958, B0GTBY1X9P, B0872JB5HX, B0CXGZ14Y5 | 2026-10-26',
        ]],
        '2026-10-26-such-kand-feder-6' => ['host' => 'federhilfe.de', 'option' => 'sab_such_kand', 'zusammen' => true, 'zeilen' => [   // Mac-Sitzung: Nistkasten XXL, Teekannennest (Halbhöhlen); neue vor die vorhandenen
            'B09T1C4YXC | B073T9SM39, B08P5Q97NF, B0CG6D8KJN, B085CX8W6B, B0BSHCJRDM, B0BT46LC5X, B09RTSPDZK, B08NW5FH5V | 2026-10-26',
            'B0731JJNDQ | B091TXGPP9, B0CG6FVWDP, B0D8PYWZ88, B0B29H9TV2, B0BFJL4445, B0001IP93O, B083JMP12D, B09C8VLHWQ | 2026-10-26',
        ]],
        '2026-10-26-korrektur-feder' => ['host' => 'federhilfe.de', 'option' => 'sab_such_kand', 'zeilen' => [], 'ersetzen' => [   // nur wenn die Zeile noch genau so dasteht (von Hand Geändertes bleibt)
            'B01MU8UQ64 | B004WO8ZIE, B000WFLAMA, B091TXGPP9 | 2026-10-26' => 'B01MU8UQ64 | B0CXJFT8VK, B0CX6GF8HN, B0F3P51Q4M | 2026-10-26',
            'B08VKRJ5D4 | B0037YHTMS | 2026-10-26' => '',
        ]],
        '2026-10-26-such-kand-feder-3' => ['host' => 'federhilfe.de', 'option' => 'sab_such_kand', 'zeilen' => [   // PC-Recherche, ab 60 % Namensübereinstimmung; Bauart prüft der Austausch beim Anzeigen gegen den Kontext
            'B07R2DK75K | B0B4C4P424, B0D9JTH8DW, B0B21K47D4 | 2026-10-26',
            'B07PYHJLMC | B00JA9UJW6, B0DN5Q2PS4, B0GH72QN8J | 2026-10-26',
            'B08NVW6Z45 | B0GX1DVKCS, B0CFVP59DL | 2026-10-26',
            'B01M8KINM2 | B07VJ5YW3D, B093XVHQ6D, B0H138BPXC | 2026-10-26',
            'B005MIBCMK | B00J6C6FHA, B00JA9UJW6, B00J6C9XMY | 2026-10-26',
            'B09X65D63W | B0D946TSVN | 2026-10-26',
            'B002RXARXS | B0D31SBQNG, B0FGJFY77V, B0BHSRR2HW | 2026-10-26',
            'B000WFLAKW | B09VXF8MCH, B09PR4L6CM, B09PR7T9MY | 2026-10-26',
            'B079Y526G4 | B01EYG822I, B01EYG7Z3U | 2026-10-26',
            'B08BRGLK3Y | B01MSE1PWU | 2026-10-26',
            'B08CMNG99T | B0D8W48747, B0CGV79TGG, B0CGV639Q7 | 2026-10-26',
            'B083M2SM3Y | B0CGV79TGG, B0CGV7MVHS | 2026-10-26',
            'B083M4K3RL | B0D8TGXLHJ, B0D8VZGBZ4, B0CGV9Z17L | 2026-10-26',
            'B083M1B2LT | B0CGV7878N, B0CGV727H2 | 2026-10-26',
            'B08CMLN5VM | B0CGV7878N, B0D8WFHW2H, B0D8W9878Z | 2026-10-26',
            'B08CN33RN6 | B0CGV7BYBN, B0D8TGXLHJ | 2026-10-26',
            'B09YDLWJKR | B09YDLG2FT, B09JLLPD5S, B09C8WY395 | 2026-10-26',
            'B07PJLHWKS | B07CZHNJ86, B085DNPH2C, B0CFYC456B | 2026-10-26',
            'B00PEB3WF0 | B00G44JKVG, B00G44JBF6, B00PEB43KS | 2026-10-26',
            'B0741D4LGG | B0B15C8TC1, B0FT1BSLBH | 2026-10-26',
            'B00AJGKZC8 | B086VLXGGD, B00AJGL6U8, B08CHMWGYT | 2026-10-26',
            'B00OJ7AIT8 | B006Z40T0Q, B007FG05WA | 2026-10-26',
            'B0869PHW9P | B0CZ6JBSMG | 2026-10-26',
            'B005YCM6OC | B002HHP13K, B005VQ8NBQ, B0CG6D8KJN | 2026-10-26',
            'B086C4YR95 | B08M66FH21, B0CWB1XSR4, B0BLK8NP9Y | 2026-10-26',
            'B005VQ8NBQ | B005YCM6OC, B002HHP13K, B0D8LDTF4Q | 2026-10-26',
            'B009FASV5W | B009X737UQ, B00DL05QHI, B0FR38688H | 2026-10-26',
            'B0073GYFHS | B005YCM6OC, B002HHP13K, B005VQ8NBQ | 2026-10-26',
            'B0037YHTMS | B0F5HW6W31, B07C9W8VCS, B001E50USW | 2026-10-26',
            'B0001IP93O | B09YDLG2FT, B07MCVNPP1, B07QQLLBSC | 2026-10-26',
            'B00VV8L298 | B00VV8KXZM, B0CG6FVWDP, B00OY274M2 | 2026-10-26',
            'B08M3FKVSL | B0CG6FVWDP, B0CGVGF4X7 | 2026-10-26',
            'B07PJW211S | B07PDHJ4ML, B0CG6FVWDP | 2026-10-26',
            'B08M8KF5NT | B08M98NC1C, B08M94PPT8, B0CGLY63PV | 2026-10-26',
            'B01M3S25LR | B0G574MKHH, B01M2ZDF57 | 2026-10-26',
            'B09FSPYFYG | B0CXJFT8VK, B0CX6GF8HN | 2026-10-26',
            'B07MTG2Q59 | B07MTFDBRF, B07KHXBDSQ | 2026-10-26',
            'B085BPN1BG | B0CG6FVWDP, B0D8PYWZ88 | 2026-10-26',
            'B0816L8XGJ | B07KSN3D53, B07KSD9RWJ, B0BR6JQQGM | 2026-10-26',
            'B00518JSGI | B00518MI9C, B00518IE86, B07J6NR9SQ | 2026-10-26',
            'B08M94PPT8 | B08M98NC1C, B08M8KF5NT, B0CGLY63PV | 2026-10-26',
            'B07QMYLZRD | B0GZ3CC8J7, B0CGLY63PV | 2026-10-26',
            'B084C219C3 | B0CGLY63PV, B0GZ3CC8J7 | 2026-10-26',
            'B0001IP9YS | B0001IP94I, B0001IP934, B0001IP95M | 2026-10-26',
            'B085N96FZJ | B08X2WVNGZ, B085N89QYT, B085N8VNWT | 2026-10-26',
            'B0G4VTPF2J | B0G4VSWP21, B085DNPH2C, B0CGV33WHG | 2026-10-26',
            'B08W5F2GCV | B07R4LX23Q, B07KSN3D53, B088L46J1S | 2026-10-26',
            'B00GQH1OYC | B0F5HWSTJQ | 2026-10-26',
            'B09BYYMVVD | B09NBMR317, B09V6H5W6H | 2026-10-26',
            'B08SYNFTFG | B08SYNVZRR, B002SKTJJS | 2026-10-26',
            'B08F7XF8R1 | B0DM9DBWV6, B0843HQW9V | 2026-10-26',
            'B0CS31QGHQ | B0B3MPG1N6, B0C5HW1S26, B0DQ6WPHSH | 2026-10-26',
            'B000QSINPA | B0001IP95M, B0001IP94S, B0001IP9YS | 2026-10-26',
            'B07PK47VMM | B01MSE1PWU | 2026-10-26',
            'B01DAFTNEU | B0GVJP4THF, B0FXRFM3Z4, B097B5TJQB | 2026-10-26',
            'B00AE9TF7G | B08C7YKNQX, B0BXPYC925, B0D53KPHN2 | 2026-10-26',
            'B083M3MJYB | B0CGV7439P, B0CGV7BYBN | 2026-10-26',
            'B08BZTGT4W | B0CFF5STTZ, B08FTBY4L4 | 2026-10-26',
            'B07R6L3RZL | B0CL6H26D2, B0D2XBS1LY, B0D679PSYT | 2026-10-26',
            'B07GBNVT5K | B00ANZL2UE, B00ANZL57Y, B00ANZL1IC | 2026-10-26',
            'B09LFFZMVQ | B016QE4N4M, B00P160MKQ, B075DVVJQB | 2026-10-26',
            'B01KKDI5NA | B0001IP93O, B0001IP95M, B0001IP9YS | 2026-10-26',
        ]],
        '2026-10-26-such-kand-feder-4' => ['host' => 'federhilfe.de', 'option' => 'sab_such_kand', 'zusammen' => true, 'zeilen' => [   // Mac-Sitzung: neue Kandidaten kommen vor die vorhandenen, nichts geht verloren
            'B01MU8UQ64 | B0CXJFT8VK, B0CX6GF8HN, B004WO8ZIE, B0H2FL51W8, B0CK3W738W | 2026-10-26',
            'B08VKRJ5D4 | B07C9W8VCS, B081ZCWS1F, B07QMYD8F3, B07QMYLZRD, B00H6HPSD4 | 2026-10-26',
            'B0769DG96T | B07QMYD8F3, B00H6HPSD4, B0CGLY63PV, B07QMYLZRD | 2026-10-26',
            'B08KJ9WTDN | B00BEE9XJ0, B005YCM6OC, B002HHP13K, B085CX8W6B | 2026-10-26',
            'B09VH7LSN7 | B01617SWTQ, B003HSV67S, B07QJ719WS, B007MO6178, B00H7Z8RNI | 2026-10-26',
            'B07R2DK75K | B00OY271PW, B0B21K47D4, B08FDFY9SL, B0DF57ZQF8 | 2026-10-26',
            'B0869LW28K | B004WO8ZIE, B095RBG5SH, B00OY271L6, B0CK3W738W | 2026-10-26',
            'B00ITDM31E | B00H6HPSD4, B0CGLY63PV | 2026-10-26',
            'B09X65D63W | B0001IP95M, B0001IP94S, B09YDLG2FT | 2026-10-26',
        ]],
        '2026-10-26-kurznamen-feder' => ['host' => 'federhilfe.de', 'option' => 'sab_produkte', 'zeilen' => [
            'B0F3P51Q4M | Nistkasten   2er-Set, 32 mm',
        ]],
        '2026-10-26-kurznamen-feder-2' => ['host' => 'federhilfe.de', 'option' => 'sab_produkte', 'zeilen' => [
            'B07QHYTSXZ | Mehlschwalbennest   Einzelnest, Holzbeton',
            'B0CJJV2HDF | Vogeltränke   Flussstein, frostsicher',
            'B095RBG5SH | Nistkasten   Spechtschutz, nesträubersicher',
            'B0DXLK5NR6 | Vogelfutter   Paprika, eichhörnchenresistent',
            'B0H1WZ5VT1 | Vogeltränke   Granit, rund 27 cm',
            'B0078KSZW0 | Kotbrett   für Mehlschwalbennest',
            'B0BP2HZ72F | Nistkasten   Holzbeton, Kohlmeise',
            'B0CGLQC4CD | Vogeltränke   Granit, frostsicher',
            'B0037YHTMS | Schwalbennest   Esschert',
            'B0DJJL5LYD | Rauchschwalbennest   Nisthilfe',
            'B0DWT2FLMC | Vogelbad   Naturstein, grau',
            'B0D2L4613K | Vogelfutter   Beeren und Früchte, 1 kg',
            'B07MQV13LW | Nistkasten   Holzbeton, grün',
            'B07CHTXRFV | Vogelschreck   Rabe, Kunststoff',
            'B0CR9S4W6B | Eichhörnchenabweiser   für Vogelhaus',
            'B0B4DLTZ1V | Katzenabwehr   Stachelmatten, 12 Stück',
            'B0D8Q8MDRN | Nistkasten   Stare, Metalldach',
            'B0FNKHSCDL | Nisthilfe   Waldkauzröhre, 83 cm',
        ]],
        '2026-10-26-kurznamen-maulwurf' => ['host' => 'maulwurfhilfe.de', 'option' => 'sab_produkte', 'zeilen' => [
            'B0H4R83YBT | Maulwurfschreck   Solar, 2er-Set',
            'B08X2V8FL8 | Nistkasten   Steinkauz, Waldkauz',
            'B075T5TTQB | Maulwurfschreck   Solar mit Erdspieß',
        ]],
        '2026-10-26-kurznamen-schnecken' => ['host' => 'schneckenhilfe.de', 'option' => 'sab_produkte', 'zeilen' => [
            'B07SFV13F2 | Kupferband   50 mm x 30 m',
        ]],
    ];

    public static function einmal_importe(?string $host = null, ?array $importe = null): void {
        if (!current_user_can('manage_options')) { return; }
        $host = preg_replace('/^www\./', '', strtolower($host ?? (string) wp_parse_url(home_url(), PHP_URL_HOST)));
        $fertig = (array) get_option('sab_importe_erledigt', []);
        $neu = false;
        foreach ($importe ?? self::IMPORTE as $id => $j) {
            if (in_array($id, $fertig, true) || $j['host'] !== $host) { continue; }
            $txt = rtrim((string) get_option($j['option'], ''));
            $vorhanden = [];
            foreach (preg_split('/\R/', $txt) as $z) { $vorhanden[self::asin(explode('|', $z)[0])] = true; }
            if (!empty($j['zusammen'])) {   // vorhandene Zeile desselben Produkts: Kandidatenlisten vereinen (neue zuerst)
                $zl = array_map('rtrim', preg_split('/\R/', $txt));
                foreach ($j['zeilen'] as $k_ => $z) {
                    $t_ = array_map('trim', explode('|', $z)); $a_ = self::asin($t_[0] ?? '');
                    foreach ($zl as $i_ => $alt_z) {
                        $o_ = array_map('trim', explode('|', $alt_z));
                        if (self::asin($o_[0] ?? '') !== $a_ || $a_ === '') { continue; }
                        $neu_l = array_filter(array_map('trim', explode(',', (string) ($t_[1] ?? ''))));
                        $alt_l = array_filter(array_map('trim', explode(',', (string) ($o_[1] ?? ''))));
                        $zl[$i_] = $a_ . ' | ' . implode(', ', array_values(array_unique(array_merge($neu_l, $alt_l)))) . ' | ' . ($t_[2] ?? ($o_[2] ?? ''));
                        unset($j['zeilen'][$k_]); break;
                    }
                }
                $txt = implode("\n", $zl);
            }
            foreach ($j['zeilen'] as $z) {
                $a = self::asin(explode('|', $z)[0]);
                if ($a !== '' && !isset($vorhanden[$a])) { $txt .= ($txt === '' ? '' : "\n") . $z; $vorhanden[$a] = true; }
            }
            foreach ((array) ($j['ersetzen'] ?? []) as $von => $nach) {
                $zl = array_map('rtrim', preg_split('/\R/', $txt)); $i = array_search($von, $zl, true);
                if ($i !== false) { if ($nach === '') { unset($zl[$i]); } else { $zl[$i] = $nach; } $txt = implode("\n", $zl); }
            }
            update_option($j['option'], $txt, false);
            $fertig[] = $id; $neu = true;
        }
        if ($neu) {
            update_option('sab_importe_erledigt', $fertig, false);
            delete_transient('sab_alle_asins'); foreach (array_keys(self::LAENDER) as $l_) { delete_transient("sab_uebersicht_{$l_}"); }
        }
    }

    private static function such_kandidaten(): array {
        $out = [];
        foreach (preg_split('/\R/', (string) get_option('sab_such_kand', '')) as $z) {
            $t = array_map('trim', explode('|', $z));
            $a = self::asin($t[0] ?? ''); if (strlen($a) !== 10) { continue; }
            $l = array_values(array_filter(array_map([__CLASS__, 'asin'], explode(',', (string) ($t[1] ?? ''))), fn($x) => strlen($x) === 10));
            if ($l) { $out[$a] = ['liste' => $l, 'datum' => (string) ($t[2] ?? '')]; }
        }
        return $out;
    }

    /** Link zur Amazon-Suche nach dem Titel des Produkts (damit man sofort sieht, was Amazon dazu anzeigt). */
    /** Suchtext für Amazon: der Produktname, sonst (bei Textlinks ohne echten Namen) das Nomen direkt vor dem Link plus Zahlen mit Einheit, z. B. „Akku 12V 10Ah“; nie die ASIN, wenn der Satz etwas hergibt. */
    public static function suchtext_aus_satz(string $name, string $alt, string $satz): string {
        if (trim($name) !== '' && !self::generischer_linktext($name)) { return $name; }
        $tech = array_map(fn($t) => preg_replace_callback('/[a-z]+$/', fn($m) => $m[0] === 'ah' ? 'Ah' : strtoupper($m[0]), $t), self::technik_begriffe($satz));
        $nom = array_values(array_filter(self::schluesselbegriffe($satz, 8), fn($w) => !preg_match('/^\d/', $w)));
        $nom = array_map(fn($w) => mb_strtoupper(mb_substr($w, 0, 1)) . mb_substr($w, 1), $tech ? array_slice($nom, -1) : array_slice($nom, -2));
        $q = trim(implode(' ', array_merge($nom, array_slice($tech, -2))));
        return $q !== '' ? $q : $alt;
    }

    private static function such_link(string $titel): string {
        return 'https://' . self::LAENDER[self::land('')][0] . '/s?k=' . rawurlencode(mb_substr(trim(preg_replace('/\s+/u', ' ', $titel)), 0, 120));
    }

    /** Welche Produkte getauscht werden müssen: weg (Amazon kennt es nicht), kein Preis/Rang, nicht lieferbar, unklar. @return array<string,string> ASIN => Grund */
    /** Amazon hat die ASIN beim letzten Abruf eindeutig abgewiesen (ungültig/nicht zugänglich). Fehlende Daten durch gescheiterte Abrufe zählen nicht. */
    private static function amazon_unbekannt(string $asin, array $d): bool {
        if (self::bild_frisch(self::land(''), $asin)) { return false; }   // ein frisches Bild liegt vor: das Produkt ist bekannt (Altlast aus früheren Fehlabrufen)
        return !isset($d['items'][$asin]) && in_array($asin, array_map('strval', (array) get_option('sab_ungueltige_asins', [])), true);
    }

    private static function tausch_faelle(array $d, array $trotz): array {
        $out = [];
        foreach (self::alle_asins() as $asin) {
            $x = $d['items'][$asin] ?? null;
            if (!$x) { if (self::amazon_unbekannt((string) $asin, $d)) { $out[$asin] = 'Amazon kennt das Produkt nicht mehr'; } continue; }
            if (isset($trotz[$asin])) { continue; }
            if (($x['preis'] ?? '') === '' && (($x['rang'] ?? null) === null || $x['rang'] > 300000)) { $out[$asin] = 'kein Preis, kein brauchbarer Rang'; continue; }
            if (!self::unklar($x) && (empty($x['lieferbar']) || ($x['status'] ?? '') === 'OUT_OF_STOCK')) { $out[$asin] = 'nicht lieferbar'; }
        }
        return $out;
    }

    /** Die letzten ein bis zwei Sätze (höchstens ~260 Zeichen) vor der ersten Fundstelle des Produkts im Artikel: dort steht oft, welches Produkt gemeint war. */
    /** Schlüsselnomen aus dem Satz vor einem Link (Produktart, nicht Marken): großgeschriebene Wörter, ohne Satzanfangs-Füllwörter und ohne Namen aus mehreren Großwörtern. Nur Verwaltung. @return string[] */
    public static function schluesselbegriffe(string $text, int $max = 6): array {
        $stop = ['möchte','möchten','wenn','dann','auch','dabei','hier','zudem','außerdem','alternativ','besonders','gerne','allerdings','natürlich','jedoch','daher','deshalb','damit','dafür','bitte','tipp','beispiel','beispielsweise','hinweis','achtung','wichtig','unter','anderem','allem','kann','können','sollte','sollten','wer','wir','sie','man','eine','einen','einem','einer','dieser','diese','dieses','jeder','jede','viele','manche','einige','garten','gärten','jahr','jahre','jahren','zeit','euro','amazon','link','produkt','produkte','produkten','empfehlen','empfehlenswert','empfehlung','empfehlungen','wasser','boden','pflanzen','pflanze','tiere','tier'];
        $tok = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $gross = fn($w) => (bool) preg_match('/^\p{Lu}\p{Ll}{3,}$/u', $w);
        $einheit = ['volt', 'ampere', 'amperestunden', 'amperestunde', 'watt', 'liter', 'meter', 'zentimeter', 'millimeter', 'kilogramm', 'gramm'];
        $out = []; foreach (array_slice(self::technik_begriffe($text), -3) as $tb) { $out[$tb] = 1; }
        foreach ($tok as $i => $w) {
            if (!$gross($w) || in_array(mb_strtolower($w), $stop, true) || in_array(mb_strtolower($w), $einheit, true)) { continue; }
            if (($i > 0 && $gross($tok[$i - 1])) || (isset($tok[$i + 1]) && $gross($tok[$i + 1]) && !in_array(mb_strtolower($tok[$i + 1]), $stop, true))) { continue; }   // Namen wie „Rieger Hoffmann“
            $b = mb_strtolower($w);
            if (mb_strlen($b) >= 9 && preg_match('/(ungen|ionen)$/u', $b)) { $b = mb_substr($b, 0, -2); }
            elseif (mb_strlen($b) >= 8 && substr($b, -2) === 'en') { $b = mb_substr($b, 0, -2); }
            $out[$b] = 1;
        }
        return array_slice(array_keys($out), 0, $max);
    }

    private static function text_vor_stelle(string $asin, array $f): string {
        static $c = [];
        if (isset($c[$asin])) { return $c[$asin]; }
        $c[$asin] = '';
        if (!$f) { return ''; }
        foreach (array_slice(array_keys($f), 0, 3, true) as $pid) {
            $post = get_post((int) $pid); if (!$post) { continue; }
            $t = (string) $post->post_content; $i = strpos($t, $asin); if ($i === false) { continue; }
            $vor = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(preg_replace('/\[[^\]]*\]/', ' ', mb_scrub(substr($t, max(0, $i - 900), min($i, 900))))))));
            if (mb_strlen($vor) < 25) { continue; }
            $teile = preg_split('/(?<=[.!?:])\s+/u', $vor); $out = '';
            while ($teile && mb_strlen($out) < 160) { $out = array_pop($teile) . ($out !== '' ? ' ' . $out : ''); }
            $c[$asin] = mb_strlen($out) > 260 ? '…' . mb_substr($out, -260) : $out; break;
        }
        return $c[$asin];
    }

    /** Text rund um die Stelle, an der ein Produkt im Artikel steht (±600 Zeichen), für „passt der Ersatz inhaltlich?“. Nur bei Produkten auf wenigen Seiten. */
    private static function stellen_kontext(string $asin, array $f): string {
        if (!$f || count($f) > 10) { return ''; }
        $pid = (int) array_key_first($f); $post = get_post($pid); if (!$post) { return ''; }
        $c = (string) $post->post_content; $i = strpos($c, $asin); if ($i === false) { return ''; }
        return trim(preg_replace('/\s+/', ' ', strip_tags(preg_replace('/\[[^\]]*\]/', ' ', mb_scrub(substr($c, max(0, $i - 600), 1200))))));
    }

    /**
     * Ersatzkandidaten zu einem Problemprodukt: Amazons Alternativen, Empfehlung, Favoriten und der übrige Katalog des Plugins, gefiltert nach „passt zum ersetzten Produkt“
     * (gleiche Art, gleiche Gruppe oder gemeinsame Wörter, bei Produkten auf wenigen Seiten auch zur Textstelle), gereiht nach Score und Provision (5 % schlägt 3 %).
     * @return array{liste:array,aussortiert:array} liste: bis zu $max Kandidaten, bester zuerst
     */
    /** Entfernt Kandidaten, bei denen der Tausch ALT => Kandidat in einem Artikel eine zu nahe Doppelung ergäbe (gleiche Prüfung wie beim Festschreiben). Prüft die ersten 40, mit demselben Weg wie der Tausch (Artikel pro ALT einmal geladen). */
    private static function ohne_doppel_kandidaten(string $alt, array $liste, array $items = [], array $namen = [], array &$blockiert_liste = []): array {
        global $wpdb; static $cache = [];
        if (!isset($wpdb) || !$liste) { return $liste; }
        if (!isset($cache[$alt])) {
            $cache[$alt] = [];
            foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT post_content FROM {$wpdb->posts} WHERE post_status IN ('publish','future') AND post_type IN ('post','page') AND post_content LIKE %s", '%' . $wpdb->esc_like($alt) . '%'), ARRAY_A) as $r) { $cache[$alt][] = (string) $r['post_content']; }
        }
        if (!$cache[$alt]) { return $liste; }
        $out = [];
        foreach ($liste as $i => $c) {
            $blockiert = false;
            if (self::gleiche_bauart_nah($alt, (string) $c['asin'], $cache[$alt], $items, $namen)) { $blockiert = true; }   // Produktvielfalt: nicht zweimal dieselbe Bauart nebeneinander
            if (!$blockiert && $i < 40) { foreach ($cache[$alt] as $t) {   // genau der Weg des Tauschs: Kurzlinks der Spalte mitnehmen, dann Text tauschen, dann Doppelungs-Prüfung
                [$t1] = self::tausch_kurzlinks($t, $alt, (string) $c['asin'], ''); [$t2] = self::tausch_text($t1, $alt, (string) $c['asin'], [], '');
                if (self::doppel_neu($t, $t2, (string) $c['asin']) || self::doppel_neu($t, str_replace($alt, (string) $c['asin'], $t), (string) $c['asin'])) { $blockiert = true; break; }
            } }
            if (!$blockiert) { $out[] = $c; } else { $blockiert_liste[] = $c; }
        }
        return $out;
    }

    // ---------- Lernen aus Entscheidungen (datensparsam: nur ASINs, Platz, Quelle; höchstens 500 Einträge) ----------

    /** Merkt sich einen Tausch: Platz des gewählten Produkts im Ranking, Quelle und die höher gereihten (= abgelehnten) Vorschläge. */
    private static function lernen_merken(string $alt, string $neu): void {
        try {
            $d = (array) self::uebersicht_daten(self::land(''), false); $trotz = (array) get_option('sab_trotz', []); $fund = self::fundstellen([$alt]);
            $k = self::austausch_kandidaten($alt, self::anzeige_name($alt, $d, $fund), $d, $trotz, $fund);
            $platz = 0; $q = 'eigene'; $dif = 0; $ab = [];
            foreach ($k['liste'] as $i => $c) {
                if ($c['asin'] === $neu) { $platz = $i + 1; $q = strpos((string) $c['quelle'], 'Amazon') === 0 ? 'amazon' : 'bestand'; $dif = (int) ($c['score'] ?? 0) - (int) (self::score(array_merge((array) ($d['items'][$alt] ?? []), ['trotz' => isset($trotz[$alt])]))[0] ?? 0); break; }
                $ab[] = $c['asin'];
            }
            if (!$platz) { $ab = []; }
            $log = (array) get_option('sab_lern', []);
            $log[] = ['t' => gmdate('Y-m-d'), 'a' => $alt, 'n' => $neu, 'p' => $platz, 'q' => $q, 'd' => $dif, 'ab' => array_slice($ab, 0, 5)];
            update_option('sab_lern', array_slice($log, -500), false);
        } catch (\Throwable $e) { /* Lernen darf den Tausch nie stören */ }
    }

    /** Vorschläge, die für dieses Produkt schon einmal abgelehnt wurden (ein höher gereihter Vorschlag wurde übergangen). @return array<string,int> ASIN => Anzahl */
    private static function lernen_abgelehnt(string $alt): array {
        $o = [];
        foreach ((array) get_option('sab_lern', []) as $e) { if (($e['a'] ?? '') === $alt) { foreach ((array) ($e['ab'] ?? []) as $a) { $o[$a] = ($o[$a] ?? 0) + 1; } } }
        return $o;
    }

    /** Trefferquote: wie oft war Platz 1 die Wahl (letzte 30 Tausche aus dem Ranking). @return array{n:int,eins:int,quote:int} */
    private static function lernen_quote(): array {
        $l = array_values(array_filter((array) get_option('sab_lern', []), fn($e) => (int) ($e['p'] ?? 0) > 0));
        $l = array_slice($l, -30); $eins = count(array_filter($l, fn($e) => (int) $e['p'] === 1));
        return ['n' => count($l), 'eins' => $eins, 'quote' => $l ? (int) round(100 * $eins / count($l)) : 0];
    }

    /** Auto-Kandidat (nur Anzeige): Quote der letzten 30 mindestens 90 %, Platz 1 mit Score-Abstand 15, Namens-/Textübereinstimmung. */
    private static function lernen_auto(array $c, int $alt_score, array $q): bool {
        return $q['n'] >= 30 && $q['quote'] >= 90 && !empty($c['ok']) && $c['score'] !== null && (int) $c['score'] - $alt_score >= 15 && (int) ($c['aehn'] ?? 0) >= 1 && (int) ($c['passt'] ?? 0) >= 2;
    }

    /** Schlüsselmerkmale eines Produkttexts: Zahl (einzel/doppel), Material, Tierart. Dienen als Ausschluss: widerspricht ein Merkmal, passt der Kandidat nicht. @return array<string,string[]> */
    private static function merkmale(string $t): array {
        $t = mb_strtolower($t); $o = [];
        $reg = [
            'zahl' => ['einzel' => '/einzel|\b1er\b|\bsingle\b|\bindividual\b|\bsimple\b|\bunique\b/u', 'doppel' => '/doppel|\b2er\b|\bdouble\b|\bdoble\b|\bduo\b|\btwin\b|\bzwillings/u'],
            'material' => ['holzbeton' => '/holzbeton|woodcrete|wood\s?concrete|hormig[oó]n de madera/u', 'lehm' => '/lehm|schlamm|\bton\b|\bclay\b|\bmud\b|arcilla|barro|argile/u', 'keramik' => '/keramik|ceramic|cer[aá]mic/u', 'metall' => '/metall|\bmetal\b|stahl|steel/u', 'stein' => '/granit|sandstein|\bstein\b|\bstone\b|piedra/u'],
            'tier' => ['mehlschwalbe' => '/mehlschwalbe/u', 'rauchschwalbe' => '/rauchschwalbe/u', 'schwalbe' => '/schwalbe|swallow|golondrina|hirondelle/u', 'meise' => '/meise|\btit\b|carbonero|m[eé]sange/u', 'star' => '/\bstar(en)?\b|starling/u', 'fledermaus' => '/fledermaus|\bbat\b|murci[eé]lago|chauve/u', 'igel' => '/\bigel|hedgehog|erizo|h[eé]risson/u', 'eule' => '/eule|kauz|\bowl\b|b[uú]ho|hibou/u', 'biene' => '/wildbiene|\bbiene|\bbee\b|abeja|abeille|insektenhotel|insect hotel/u', 'specht' => '/specht|woodpecker|pic\b/u', 'rotkehlchen' => '/rotkehlchen|robin|petirrojo/u']
        ];
        foreach ($reg as $g => $liste) { foreach ($liste as $k => $re) { if (preg_match($re, $t)) { $o[$g][] = $k; } } }
        if (!empty($o['material']) && in_array('holzbeton', $o['material'], true)) { $o['material'] = array_values(array_diff($o['material'], ['holz'])); }
        if (isset($o['tier']) && count($o['tier']) > 1) { $o['tier'] = array_values(array_diff($o['tier'], ['schwalbe'])); }
        return $o;
    }

    /** Widerspricht der Kandidat den Schlüsselmerkmalen des Produkts (Einzel/Doppel, Material, Tierart)? Fehlt ein Merkmal auf einer Seite, gilt es nicht als Widerspruch. */
    private static function merkmal_widerspruch(array $ref, array $cand): bool {
        foreach ($ref as $g => $a) {
            $b = $cand[$g] ?? []; if (!$b) { continue; }
            if ($g === 'tier') {
                $ok = false;
                foreach ($a as $x) { foreach ($b as $y) { if ($x === $y || $x === 'schwalbe' || $y === 'schwalbe') { $ok = true; } } }
                if (!$ok) { return true; }
            } elseif (!array_intersect($a, $b)) { return true; }
        }
        return false;
    }

    /** Steht in der Nähe der Ersetzungsstelle (±2500 Zeichen) schon ein anderes Produkt gleicher Bauart (gleiche Zahl wie Einzel/Doppel oder gleiches Material)? Dann wird der Kandidat nicht vorgeschlagen. */
    private static function gleiche_bauart_nah(string $alt, string $cand, array $artikel, array $items, array $namen): bool {
        $mc = self::merkmale(($namen[$cand] ?? '') . ' ' . (string) ($items[$cand]['titel'] ?? ''));
        $mc = array_intersect_key($mc, ['zahl' => 1, 'material' => 1]); if (!$mc) { return false; }
        foreach ($artikel as $t) {
            $off = 0;
            while (($i = strpos($t, $alt, $off)) !== false) {
                $off = $i + 10;
                $fenster = substr($t, max(0, $i - 2500), 5000 > strlen($t) ? strlen($t) : 5000);
                if (!preg_match_all('/\b(B[0-9A-Z]{9})\b/', $fenster, $m)) { continue; }
                foreach (array_unique($m[1]) as $n) {
                    if ($n === $alt || $n === $cand) { continue; }
                    $mn = self::merkmale(($namen[$n] ?? '') . ' ' . (string) ($items[$n]['titel'] ?? ''));
                    foreach ($mc as $g => $werte) { if (!empty($mn[$g]) && array_intersect($werte, $mn[$g])) { return true; } }
                }
            }
        }
        return false;
    }

    /** Auswahl von zwei bis drei Kurznamen für einen Kandidaten ohne eigenen Kurznamen; der erste ist vorgewählt. Wird beim Tausch als Name im Artikel genutzt. */
    private static function kurzname_wahl_html(string $alt, array $c, array $namen, array $x): string {
        if (($namen[$c['asin']] ?? '') !== '' || empty($x['titel'])) { return ''; }
        $v = self::kurzname_varianten((string) $x['titel']); if (!$v) { return ''; }
        $h = '<span class="sab-kn" style="display:block;margin-top:6px;border-top:1px dashed #ccc;padding-top:4px"><small><strong>Name im Artikel:</strong> kurz, ohne Marke, wenn passend mit Zahl am Ende (z. B. „Schneckenzaun-Set, 14-teilig“)</small>';
        foreach ($v as $i => $n) { $h .= '<br><label style="display:inline"><input type="radio" name="sab_kn[' . esc_attr($alt) . '][' . esc_attr($c['asin']) . ']" value="' . esc_attr($n) . '"' . ($i === 0 ? ' checked' : '') . '> <small>' . esc_html($n) . '</small></label>'; }
        return $h . '</span>';
    }

    private static function austausch_kandidaten(string $alt, string $name, array $d, array $trotz, array $fund, int $max = 4): array {
        $items = (array) ($d['items'] ?? []); $namen = self::produkte(); $marken = (array) get_option('sab_marken', []);
        if (self::generischer_linktext($name)) { $name = ''; }   // „Angebot bei Amazon“ sagt nichts über das Produkt
        if (!defined('SAB_SYNC') && get_transient('sab_such_pruefung') === false) {   // eingetragene Suchtreffer ohne Amazon-Daten: Abruf im Hintergrund anstoßen (höchstens alle 10 Minuten)
            foreach (self::such_kandidaten() as $l_) { foreach ($l_['liste'] as $b_) { if (!isset($items[$b_])) { set_transient('sab_such_pruefung', 1, 10 * MINUTE_IN_SECONDS); foreach (array_keys(self::LAENDER) as $ld_) { delete_transient("sab_uebersicht_{$ld_}"); } break 2; } } }
        }
        $q = [];
        foreach ((self::amz_alternativen()[$alt]['liste'] ?? []) as $i => $a) { $q[$a] = 'Amazon-Alternative ' . ($i + 1); }
        foreach ((self::such_kandidaten()[$alt]['liste'] ?? []) as $i => $a) { $q[$a] = $q[$a] ?? 'Amazon-Suche ' . ($i + 1); }
        foreach ((self::auch_gekauft()[$alt]['liste'] ?? []) as $a) { $q[$a] = $q[$a] ?? 'Kunden kauften auch'; }
        foreach (self::ersatz_fuer($alt) as $a => $stt) { $q[$a] = 'Ersatz-Datenbank (' . $stt . ')'; }   // gepflegte Ersatzprodukte der Produktart
        $ce = self::claude_empfehlungen()[$alt] ?? null; if ($ce) { $q[$ce[0]] = 'Empfehlung'; }
        foreach ($marken as $a => $t) { if ($t === 'fav' && !isset($q[$a])) { $q[$a] = 'Favorit'; } }
        foreach (array_keys($namen) as $a) { if (!isset($q[$a])) { $q[$a] = 'Katalog'; } }
        $gruppe = []; foreach (self::gruppen() as $liste) { if (in_array($alt, $liste, true)) { $gruppe = array_merge($gruppe, $liste); } }
        $wn = self::woerter(trim($name . ' ' . (string) ($items[$alt]['titel'] ?? '')));
        $wk = self::woerter(self::text_vor_stelle($alt, $fund[$alt] ?? []));
        $tvs = self::text_vor_stelle($alt, $fund[$alt] ?? []); $tech = self::technik_begriffe($tvs);   // z. B. „12-Volt-10-Ah-Akku“ => 12v, 10ah
        $nom = array_values(array_filter(self::schluesselbegriffe($tvs), fn($w) => !preg_match('/^\d/', $w)));   // Nomen direkt vor dem Link (auch kurze wie „Akku“)
        $refm = self::merkmale(trim($name . ' ' . (string) ($items[$alt]['titel'] ?? '') . ' ' . self::text_vor_stelle($alt, $fund[$alt] ?? [])));   // Schlüsselmerkmale aus Name, Titel und Text vor dem Link   // Hinweise aus dem Satz vor dem Link   // nur der Produktname selbst, ohne Artikeltext: Namensähnlichkeit
        $text = $name;   // der Textzusammenhang bringt nur etwas, wenn der Name fast nichts sagt: sonst ziehen Wörter wie „Schnecken“ aus dem Artikel unpassende Produkte heran
        if (count(self::woerter($name)) < 2) { $text .= ' ' . self::stellen_kontext($alt, $fund[$alt] ?? []); }
        $a1 = self::arten($text); $w1 = self::woerter($text);
        $out = []; $aus = []; $rest = []; $lw = self::lern_daten()['w']; $lf = [];
        foreach ($q as $a => $quelle) {
            $a = (string) $a; if ($a === $alt || ($marken[$a] ?? '') === 'ers' || !isset($items[$a])) { if (($quelle === 'Empfehlung' || strpos($quelle, 'Amazon-Suche') === 0) && $a !== $alt && !isset($items[$a])) { $out[] = ['asin' => $a, 'quelle' => $quelle, 'score' => null, 'x' => [], 'ok' => false, 'passt' => 0, 'rang' => -1, 'ausgeschlossen' => false]; } continue; }
            $x = $items[$a]; $x['trotz'] = isset($trotz[$a]);
            $ok = ($x['preis'] ?? '') !== '' && !empty($x['lieferbar']) && ($x['status'] ?? '') !== 'OUT_OF_STOCK';
            $tc = ($namen[$a] ?? '') . ' ' . (string) ($x['titel'] ?? '');
            if (self::toetend($tc)) { continue; }   // tötende Produkte nie vorschlagen
            if ($refm && self::merkmal_widerspruch($refm, self::merkmale($tc))) { $aus[] = ['asin' => $a, 'quelle' => $quelle, 'titel' => (string) ($x['titel'] ?? '')]; continue; }   // z. B. Einzelnest vs. Doppelnest, Holzbeton vs. Lehm
            $ar = count(array_intersect($a1, self::arten($tc))); $tr = count(array_intersect($w1, self::woerter($tc))) + (self::aehnliche_woerter($text, $tc) ? 1 : 0);
            $ing = in_array($a, $gruppe, true);
            $tk = $wk ? count(array_intersect($wk, self::woerter($tc))) : 0;
            $passt = $ing ? 3 : ($ar >= 1 ? 2 : ($tr >= 3 ? 1 : 0));   // nur zwei gemeinsame Wörter („caracoles“, „protección“) reichen nicht
            $sc = self::score($x)[0]; $pv = self::provision_satz($x);
            $tt = $tech ? count(array_intersect($tech, self::technik_begriffe($tc))) : 0; $nb = 0; $tcl = mb_strtolower($tc);
            foreach ($nom as $w_) { if (mb_strlen($w_) >= 4 && strpos($tcl, $w_) !== false) { $nb++; } }
            if ($tt >= 1 && $nb >= 1) { $passt = max($passt, 2); } elseif ($tt >= 1 || $nb >= 1) { $passt = max($passt, 1); }
            $aehn = count(array_intersect($wn, self::woerter($tc))) + min(2, $tt + $nb); if ($tk >= 2) { $aehn++; if ($passt === 0) { $passt = 1; } } $sim = $wn ? min(1.0, count(array_intersect($wn, self::woerter($tc))) / max(1, count($wn))) : 0.0;
            if ($passt === 0 && ($quelle === 'Kunden kauften auch' || strpos($quelle, 'Amazon-Suche') === 0 || strpos($quelle, 'Ersatz-Datenbank') === 0)) { $passt = 2; }   // von Amazon als zugehörig gezeigt beziehungsweise von Hand geprüft   // von Hand gesucht und geprüft: nicht noch einmal aussortieren
            if ($passt === 0) { $aus[] = ['asin' => $a, 'quelle' => $quelle, 'titel' => (string) ($x['titel'] ?? '')]; if ($ok && $quelle === 'Katalog') { $rest[] = ['asin' => $a, 'quelle' => 'Bestes im Portfolio (Passung unklar)', 'score' => $sc, 'x' => $x, 'ok' => $ok, 'passt' => 0, 'pv' => $pv, 'rang' => $sc, 'ausgeschlossen' => false]; } continue; }
            $gw = $wn ? count(array_intersect($wn, self::woerter($tc))) : 0;   // gemeinsame Titelwörter (ohne Füllwörter): Hauptkriterium der Reihenfolge, danach Score
            $out[] = ['asin' => $a, 'quelle' => $quelle, 'score' => $sc, 'x' => $x, 'ok' => $ok, 'passt' => $passt, 'aehn' => $aehn, 'sim' => $sim, 'gw' => $gw, 'ahs' => min(6, $gw) * 5 + (int) floor(4 * $sim) + min(2, $aehn), 'f' => ($lf = self::lern_merkmale($sim, $passt, self::preiszahl((string) ($items[$alt]['preis'] ?? '')) ?: null, self::preiszahl((string) ($x['preis'] ?? '')) ?: null, (int) $sc, $pv !== null ? (float) $pv : null, $x)), 'lern' => self::lern_summe($lf, $lw), 'pv' => $pv, 'rang' => $sc + 30 * $tt + 10 * $nb + 15 * max(0, ((float) $pv) - 3) + 10 * $ar + 4 * $tr + 12 * $aehn + 40 * $sim + ($quelle === 'Katalog' ? 0 : 3) + ($quelle === 'Ersatz-Datenbank (geprüft)' ? 20 : 0), 'ausgeschlossen' => false];
        }
        // zwei Gruppen: bis zu $max aus dem eigenen Bestand (vom Algorithmus gewählt) und bis zu 2 neue Treffer der Amazon-Suche
        usort($out, fn($p, $q2) => [(int) $q2['ok'], (int) ($q2['ahs'] ?? 0), (float) ($q2['score'] ?? -1), (float) $q2['rang']] <=> [(int) $p['ok'], (int) ($p['ahs'] ?? 0), (float) ($p['score'] ?? -1), (float) $p['rang']]);   // lieferbare zuerst, dann ähnlicher Produktname (Alexander 25.10.), dann Score, bei gleichem Score nach Rang inkl. Provision
        $bl_ = []; $out = self::ohne_doppel_kandidaten($alt, $out, $items, $namen, $bl_);
        foreach ($bl_ as $c_) { $aus[] = ['asin' => $c_['asin'], 'quelle' => $c_['quelle'], 'titel' => (string) ($c_['x']['titel'] ?? ''), 'grund' => 'steht oder passt schon in der Nähe im Artikel (Doppelung)']; }
        $abl = self::lernen_abgelehnt($alt);   // schon einmal übergangene Vorschläge rutschen nach hinten, zweimal abgelehnte fliegen raus
        if ($abl) { $out = array_values(array_filter($out, fn($c) => ($abl[$c['asin']] ?? 0) < 2)); foreach ($out as &$c_) { $c_['abgel'] = (int) ($abl[$c_['asin']] ?? 0); } unset($c_); }   // nur Produkte vorschlagen, die der Austausch auch annehmen würde (keine Doppelung nebeneinander oder zu nah)
        $bestand = []; $neu = [];
        foreach ($out as $c) { if (strpos((string) $c['quelle'], 'Amazon-Suche') === 0) { $c['neu'] = true; $neu[] = $c; } else { $bestand[] = $c; } }
        $bestand = array_values(array_filter($bestand, fn($c) => (int) ($c['passt'] ?? 0) >= 2 || ((int) ($c['passt'] ?? 0) === 1 && (int) ($c['aehn'] ?? 0) >= 1)));   // Bestand nur bei echter Namens-/Typ-Übereinstimmung
        $bs = array_slice($bestand, 0, $max);
        $liste = array_merge($bs, array_slice($neu, 0, $max > 4 ? 6 : max(2, min(4, 6 - count($bs)))));
        if (false && count($liste) < 4 && $rest) {   // nichts Passendes gefunden: wenigstens die stärksten lieferbaren Produkte des Bestands zeigen
            usort($rest, fn($p, $q2) => $q2['score'] <=> $p['score']);
            $liste = array_merge($liste, array_slice($rest, 0, 4 - count($liste)));
        }
        $la_ = self::lern_aktiv();
        usort($liste, fn($p, $q2) => [(int) !empty($q2['neu']), -(int) ($q2['abgel'] ?? 0), (int) $q2['ok'], $la_ ? (int) round(10 * ($q2['lern'] ?? 0)) : 0, (int) ($q2['ahs'] ?? 0), (float) ($q2['score'] ?? -1), (float) $q2['rang']] <=> [(int) !empty($p['neu']), -(int) ($p['abgel'] ?? 0), (int) $p['ok'], $la_ ? (int) round(10 * ($p['lern'] ?? 0)) : 0, (int) ($p['ahs'] ?? 0), (float) ($p['score'] ?? -1), (float) $p['rang']]);   // Bestand und neue Treffer zusammen: oben links ähnlicher Name, dann höchster Score
        return ['liste' => $liste, 'aussortiert' => $aus];   // hat der Bestand wenig Passendes, füllen Treffer der Amazon-Suche auf (bis zu vier)
    }

    // ---------- Haufen entzerren: Produktblöcke dorthin schieben, wo der Artikel über sie schreibt ----------

    const ENTZERREN_ABSTAND = 3500;   // Zeichen Text, die mindestens zwischen zwei Produktblöcken liegen sollen

    /** Produktblöcke eines Artikels: Tabellen und [amz_karten]-Blöcke samt Beschriftung davor. @return array<int,array{s:int,e:int,html:string,asins:array}> nach Position */
    private static function entz_einheiten(string $c): array {
        $e = [];
        if (preg_match_all('#<table\b.*?</table>#is', $c, $tm, PREG_OFFSET_CAPTURE)) {
            foreach ($tm[0] as [$t, $off]) { if (preg_match('#\[(?:amz_(?:bild|produkt|karte|karten|link|titel)|aawp|amazon)\b|amazon\.|amzn\.#i', $t)) { $e[] = ['s' => $off, 'e' => $off + strlen($t), 'html' => $t]; } }
        }
        if (preg_match_all('#\[amz_karten\].*?\[/amz_karten\]#is', $c, $km, PREG_OFFSET_CAPTURE)) {
            foreach ($km[0] as [$t, $off]) { $e[] = ['s' => $off, 'e' => $off + strlen($t), 'html' => $t]; }
        }
        if (preg_match_all('#\[(amz_bild|amz_produkt|amz_karte|aawp|amazon)\b[^\]]*\]#i', $c, $sm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {   // einzelne Platzhalter außerhalb von Tabellen und Kartenblöcken zählen wie in der Wartungsliste mit
            foreach ($sm as $x) {
                $p = (int) $x[0][1]; $in = false;
                foreach ($e as $u0) { if ($p >= $u0['s'] && $p < $u0['e']) { $in = true; break; } }
                if ($in || !preg_match('#\[(?:amz_bild|amz_produkt|amz_karte)\b|\[(?:aawp|amazon)\b[^\]]*(?:thumb|image|box|grid|table)#i', $x[0][0])) { continue; }
                $s0 = $p; $e0 = $p + strlen($x[0][0]);
                $z = stripos($c, '[/' . $x[1][0] . ']', $e0);
                if ($z !== false && $z - $e0 < 3000) { $e0 = $z + strlen($x[1][0]) + 3; }
                if (preg_match('#<p[^>]*>\s*$#i', substr($c, max(0, $s0 - 80), min($s0, 80)), $pm) && preg_match('#^\s*</p>#i', substr($c, $e0, 20), $pn)) { $s0 -= strlen($pm[0]); $e0 += strlen($pn[0]); }
                $e[] = ['s' => $s0, 'e' => $e0, 'html' => substr($c, $s0, $e0 - $s0)];
            }
        }
        usort($e, fn($a, $b) => $a['s'] <=> $b['s']);
        $re = '#(?:asin|fields|box|link|data-sab-asin)\s*=\s*["\']?([A-Z0-9]{10})\b|/(?:dp|gp/product|product)/([A-Z0-9]{10})\b#i';
        foreach ($e as &$u) {
            $vor = substr($c, max(0, $u['s'] - 320), min($u['s'], 320));
            if (preg_match('#(?:<p[^>]*>\s*)?<strong>[^<]{2,100}</strong>(?:\s*<br\s*/?>)?(?:\s*</p>)?\s*$#i', $vor, $m) || preg_match('#<h[3-6][^>]*>[^<]{2,100}</h[3-6]>\s*$#i', $vor, $m)) { $u['s'] -= strlen($m[0]); }
            $u['html'] = substr($c, $u['s'], $u['e'] - $u['s']);
            $u['asins'] = [];
            if (preg_match_all($re, $u['html'], $am, PREG_SET_ORDER)) { foreach ($am as $x) { $u['asins'][strtoupper(($x[1] ?? '') !== '' ? $x[1] : ($x[2] ?? ''))] = 1; } }
            $u['asins'] = array_keys($u['asins']);
        }
        unset($u);
        return $e;
    }

    /**
     * Zerlegt einen Block mit mehr als zwei Produkten (Kartenblock oder Tabelle mit mehr als zwei Produktspalten) in Paare:
     * das erste Paar bleibt, die weiteren werden eigene Zweierblöcke (ein übrig bleibendes einzelnes Produkt wird ein Einerblock).
     * @return array{bleibt:string,extra:string[]}|null
     */
    private static function entz_zerlegen(string $html): ?array {
        if (preg_match('#^(.*?)\[amz_karten\](.*)\[/amz_karten\](.*)$#is', $html, $m)) {
            $teile = preg_split('#(?=\[amz_karte\b)#i', $m[2], -1, PREG_SPLIT_NO_EMPTY);
            if (!$teile || count($teile) <= self::HAUFEN_MAX) { return null; }
            $paare = array_chunk($teile, 2); $extra = [];
            foreach (array_slice($paare, 1) as $pp) { $extra[] = '[amz_karten]' . implode('', $pp) . '[/amz_karten]'; }
            return ['bleibt' => $m[1] . '[amz_karten]' . implode('', $paare[0]) . '[/amz_karten]' . $m[3], 'extra' => $extra];
        }
        if (preg_match('#^(.*?)(<table\b[^>]*>)(.*)</table>(.*)$#is', $html, $m) && stripos($m[3], '<table') === false && stripos($m[3], 'colspan') === false && stripos($m[3], 'rowspan') === false) {
            if (!preg_match_all('#(<tr\b[^>]*>)(.*?)</tr>#is', $m[3], $rm, PREG_SET_ORDER)) { return null; }
            $zeilen = []; $n = null;
            foreach ($rm as $r) {
                if (!preg_match_all('#(<t[dh]\b[^>]*>.*?</t[dh]>)#is', $r[2], $cm)) { return null; }
                if ($n === null) { $n = count($cm[1]); } elseif ($n !== count($cm[1])) { return null; }
                $zeilen[] = [$r[1], $cm[1]];
            }
            if ($n === null || $n <= self::HAUFEN_MAX) { return null; }
            $tb = stripos($m[3], '<tbody') !== false;
            $bau = function (array $spalten) use ($zeilen, $m, $tb): string {
                $o = '';
                foreach ($zeilen as [$tr, $cells]) { $o .= $tr . implode('', array_map(fn($i) => $cells[$i], $spalten)) . '</tr>'; }
                return $m[2] . ($tb ? '<tbody>' . $o . '</tbody>' : $o) . '</table>';
            };
            $paare = array_chunk(range(0, $n - 1), 2); $extra = [];
            foreach (array_slice($paare, 1) as $pp) { $extra[] = $bau($pp); }
            return ['bleibt' => $m[1] . $bau($paare[0]) . $m[4], 'extra' => $extra];
        }
        return null;
    }

    /** ASINs eines Blocks (ein Eintrag je Produkt). */
    private static function entz_asins(string $html): array {
        $re = '#(?:asin|fields|box|link|data-sab-asin)\s*=\s*["\']?([A-Z0-9]{10})\b|/(?:dp|gp/product|product)/([A-Z0-9]{10})\b#i'; $o = [];
        if (preg_match_all($re, $html, $am, PREG_SET_ORDER)) { foreach ($am as $x) { $o[strtoupper(($x[1] ?? '') !== '' ? $x[1] : ($x[2] ?? ''))] = 1; } }
        return array_keys($o);
    }

    /** Stellen im Text, an denen ein Block stehen darf: nach einem Absatz, einer Überschrift oder Leerzeile, außerhalb von Tabellen, Listen, Bildunterschriften usw. @return int[] */
    private static function entz_stellen(string $c): array {
        $out = []; $tiefe = 0;
        if (!preg_match_all('#<(/?)(table|ul|ol|figure|blockquote|div|details|pre|script|style)\b[^>]*>|\[caption[^\]]*\]|\[/caption\]|\[amz_karten\]|\[/amz_karten\]|</p>|</h[1-6]>|\r?\n[ \t]*\r?\n#i', $c, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) { return $out; }
        foreach ($m as $x) {
            $t = strtolower($x[0][0]); $pos = (int) $x[0][1] + strlen($x[0][0]);
            if (preg_match('#^<(table|ul|ol|figure|blockquote|div|details|pre|script|style)\b#', $t) || $t === '[amz_karten]' || strpos($t, '[caption') === 0) { $tiefe++; }
            elseif (preg_match('#^</(table|ul|ol|figure|blockquote|div|details|pre|script|style)>#', $t) || $t === '[/amz_karten]' || $t === '[/caption]') { $tiefe = max(0, $tiefe - 1); if ($tiefe === 0) { $out[] = $pos; } }
            elseif ($tiefe === 0 && !preg_match('#^</h[1-6]>#', $t)) { $out[] = $pos; }   // nicht direkt hinter eine Überschrift: sie soll bei ihrem Text bleiben
        }
        return array_values(array_unique($out));
    }

    /**
     * Plan zum Entzerren: In jedem Haufen (mehr als HAUFEN_MAX Produkte ohne Text dazwischen) bleibt der erste Block stehen, die übrigen wandern
     * an die Stelle, an der der Artikel am meisten über ihre Produkte schreibt, mit möglichst großem Abstand zu anderen Blöcken.
     * @return array{neu:string,schritte:array,haufen:int}
     */
    public static function entzerren_plan(string $c, array $d, array $namen): array {
        $u = self::entz_einheiten($c); $haufen = []; $cl = [];
        foreach ($u as $i => $x) {
            if ($cl && $i > 0) {
                $zw = trim(preg_replace('/\s+/', ' ', strip_tags(preg_replace('/\[[^\]]*\]/', ' ', mb_scrub(substr($c, $u[$i - 1]['e'], max(0, $x['s'] - $u[$i - 1]['e'])))))));
                if ($x['s'] < $u[$i - 1]['e'] || mb_strlen($zw) < self::HAUFEN_TEXT) { $cl[] = $i; continue; }
                $haufen[] = $cl; $cl = [];
            }
            $cl[] = $i;
        }
        if ($cl) { $haufen[] = $cl; }
        $bewegen = []; $teilen = [];
        foreach ($haufen as $h) {
            $anz = 0; foreach ($h as $i) { $anz += max(1, count($u[$i]['asins'])); }
            if ($anz <= self::HAUFEN_MAX) { continue; }
            foreach ($h as $k => $i) {
                if ($k > 0) { $bewegen[] = $i; }
                elseif (self::entz_zerlegen($u[$i]['html']) !== null) { $teilen[] = $i; }   // der erste Block bleibt, hat er mehr als zwei Produkte, wird er aufgeteilt
            }
        }
        $res = ['neu' => $c, 'schritte' => [], 'haufen' => count(array_filter($haufen, fn($h) => count($h) > 1 || self::entz_zerlegen($u[$h[0]]['html']) !== null))];
        if (!$bewegen && !$teilen) { return $res; }
        // Blöcke herausnehmen bzw. verkleinern (von hinten nach vorn), Überreste nach Doppel-Trennlinien aufräumen
        $rest = $c; $raus = [];
        $ops = [];
        foreach ($bewegen as $i) {
            $z = self::entz_zerlegen($u[$i]['html']);
            if ($z) { foreach ($z['extra'] as $n => $ex) { $raus[sprintf('%05d-%02d', $i, $n + 1)] = array_merge($u[$i], ['html' => $ex, 'asins' => self::entz_asins($ex)]); } $raus[sprintf('%05d-00', $i)] = array_merge($u[$i], ['html' => $z['bleibt'], 'asins' => self::entz_asins($z['bleibt'])]); }
            else { $raus[sprintf('%05d-00', $i)] = $u[$i]; }
            $ops[] = [$u[$i]['s'], $u[$i]['e'], ''];
        }
        foreach ($teilen as $i) {
            $z = self::entz_zerlegen($u[$i]['html']);
            foreach ($z['extra'] as $n => $ex) { $raus[sprintf('%05d-%02d', $i, $n + 1)] = array_merge($u[$i], ['html' => $ex, 'asins' => self::entz_asins($ex)]); }
            $ops[] = [$u[$i]['s'], $u[$i]['e'], $z['bleibt']];
        }
        usort($ops, fn($a, $b) => $b[0] <=> $a[0]);
        foreach ($ops as [$os, $oe, $ersatz]) { $rest = substr($rest, 0, $os) . $ersatz . substr($rest, $oe); }
        $rest = preg_replace('#(<hr\s*/?>\s*){2,}#i', '<hr />' . "\n", $rest);
        $stellen = self::entz_stellen($rest); $plaetze = [];   // bereits vergebene Stellen
        // Wörter, die fast überall im Artikel vorkommen (z. B. „Pflanzen“, „Schnecken“), sagen nichts über die passende Stelle
        $flach = preg_replace('/\s+/', ' ', strip_tags(preg_replace('/\[[^\]]*\]/', ' ', mb_scrub($rest)))); $stuecke = str_split($flach, 1500); $haeufigkeit = [];
        foreach ($stuecke as $st1) { foreach (self::woerter($st1) as $w) { $haeufigkeit[$w] = ($haeufigkeit[$w] ?? 0) + 1; } }
        $haeufig = array_keys(array_filter($haeufigkeit, fn($n) => $n >= max(3, 0.4 * count($stuecke))));
        $feste = self::entz_einheiten($rest);
        ksort($raus);
        foreach ($raus as $i => $blk) {
            $text = ''; foreach ($blk['asins'] as $a) { $text .= ' ' . ($namen[$a] ?? '') . ' ' . (string) ($d['items'][$a]['titel'] ?? ''); }
            preg_match_all('/titel="([^"]*)"/u', $blk['html'], $tt); $text .= ' ' . implode(' ', $tt[1]) . ' ' . trim(strip_tags(preg_replace('/\[[^\]]*\]/', ' ', $blk['html'])));
            $w1 = array_values(array_diff(self::woerter($text), $haeufig)); $a1 = self::arten($text);
            $beste = null;
            foreach ($stellen as $pos) {
                $txt = function (int $von, int $len) use ($rest): string { return trim(preg_replace('/\s+/', ' ', strip_tags(preg_replace('/\[[^\]]*\]/', ' ', mb_scrub(substr($rest, max(0, $von), max(0, $len))))))); };
                $f1 = $txt($pos - 400, min($pos, 400)); $f2 = $txt($pos - 900, min($pos - 400, 500)); $fenster = trim($f2 . ' ' . $f1);   // nur Text davor: der Block steht HINTER dem Absatz, der von seinen Produkten handelt
                $tr = 2 * count(array_intersect($w1, self::woerter($f1))) + count(array_intersect($w1, self::woerter($f2))); $ar = count(array_intersect($a1, self::arten($f1))) + count(array_intersect($a1, self::arten($f2)));
                $abst = PHP_INT_MAX;
                foreach (array_merge($feste, array_map(fn($p) => ['s' => $p, 'e' => $p], $plaetze)) as $f) { $abst = min($abst, $pos <= $f['s'] ? $f['s'] - $pos : ($pos >= $f['e'] ? $pos - $f['e'] : 0)); }
                if ($abst === 0) { continue; }
                $wert = 10 * $tr + 25 * $ar + ($abst >= self::ENTZERREN_ABSTAND ? min($abst, 20000) / 1000 : -1000 + (int) ($abst / 10));
                if ($beste === null || $wert > $beste['wert']) { $beste = ['pos' => $pos, 'wert' => $wert, 'tr' => $tr, 'ar' => $ar, 'abst' => $abst === PHP_INT_MAX ? 99999 : $abst, 'fenster' => $fenster]; }
            }
            if ($beste === null) { continue; }
            $plaetze[] = $beste['pos'];
            $res['schritte'][] = ['unit' => $blk, 'pos' => $beste['pos'], 'tr' => $beste['tr'], 'ar' => $beste['ar'], 'abst' => $beste['abst'], 'kontext' => mb_substr($beste['fenster'], max(0, mb_strlen($beste['fenster']) - 330), 300), 'danach' => mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags(preg_replace('/\[[^\]]*\]/', ' ', mb_scrub(substr($rest, $beste['pos'], 700)))))), 0, 110)];
        }
        if (!$res['schritte']) { return $res; }
        $ins = $res['schritte']; usort($ins, fn($a, $b) => $b['pos'] <=> $a['pos']);
        $neu = $rest;
        foreach ($ins as $st) { $neu = substr($neu, 0, $st['pos']) . "\n\n" . trim($st['unit']['html']) . "\n\n" . substr($neu, $st['pos']); }
        $neu = preg_replace('#(<hr\s*/?>\s*){2,}#i', '<hr />' . "\n", $neu);
        $res['neu'] = $neu;
        return $res;
    }

    /** Artikeltext ohne Produktblöcke, Trennlinien und Leerraum: vor und nach dem Entzerren muss er identisch sein. */
    private static function text_ohne_bloecke(string $c): string {
        $u = self::entz_einheiten($c);
        foreach (array_reverse($u) as $x) { $c = substr($c, 0, $x['s']) . substr($c, $x['e']); }
        return preg_replace('#\s+|<hr\s*/?>#i', '', $c);
    }

    /** Vorschau mit Zahlen und Textstellen; Knopf zum Anwenden (mit Sicherung) und zum Zurücksetzen. */
    private static function entzerren_vorschau_html(int $id): string {
        $post = get_post($id); if (!$post) { return '<p>Artikel nicht gefunden.</p>'; }
        $c = (string) $post->post_content; $d = self::uebersicht_daten(self::land(''), false); $namen = self::produkte();
        $plan = self::entzerren_plan($c, (array) $d, $namen);
        $h = '<h3>Haufen entzerren: „' . esc_html($post->post_title) . '“</h3>';
        $zur = get_post_meta($id, '_sab_entzerren_backup', true) !== '';
        if ($zur) { $h .= '<form method="post" style="margin:8px 0">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="umbau"><input type="hidden" name="sab_form" value="entzerren_zurueck"><input type="hidden" name="sab_id" value="' . $id . '"><button class="button">Entzerren rückgängig machen</button> <span class="description">Stellt den Artikel auf den Stand vor dem Entzerren.</span></form>'; }
        if (!$plan['schritte']) { return $h . '<p>' . ($plan['haufen'] ? 'Es gibt einen Haufen, aber ich finde keine geeignete Stelle zum Verschieben.' : 'In diesem Artikel steht kein Haufen (mehr als ' . self::HAUFEN_MAX . ' Produkte ohne Text dazwischen).') . '</p>'; }
        $gleich = self::text_ohne_bloecke($c) === self::text_ohne_bloecke($plan['neu']);
        $h .= '<p><strong>Es wird kein Text eingefügt, geändert oder gelöscht</strong>, nur Produktblöcke werden verschoben, und zwar ans Ende eines Absatzes (nie direkt hinter eine Überschrift). Kontrolle: Artikeltext ohne Produktblöcke vorher und nachher ' . ($gleich ? '<span style="color:#1a7f37;font-weight:700">identisch &#10003;</span>' : '<span style="color:#a00;font-weight:700">NICHT identisch, bitte nicht anwenden</span>') . '. Es wird nichts gespeichert, bevor du „Jetzt entzerren“ klickst. Je Haufen bleibt der erste Block stehen, die anderen wandern dorthin, wo der Artikel über ihre Produkte schreibt, mit möglichst viel Abstand zu anderen Blöcken (Ziel: mindestens ' . self::ENTZERREN_ABSTAND . ' Zeichen Text).</p><ol>';
        foreach ($plan['schritte'] as $st) {
            $pn = []; foreach ($st['unit']['asins'] as $a) { $pn[] = '<code>' . esc_html($a) . '</code> ' . esc_html(($namen[$a] ?? '') ?: self::kurzname_aus_titel((string) ($d['items'][$a]['titel'] ?? ''))); }
            $h .= '<li><strong>Block mit ' . implode(' und ', $pn ?: ['(ohne erkennbare ASIN)']) . '</strong><br><span style="color:#666">Bisher: ab Zeichen ' . (int) $st['unit']['s'] . ' (Haufen)</span><br>Neu: <strong>hinter</strong> <em>„… ' . esc_html($st['kontext']) . '“</em>' . (($st['danach'] ?? '') !== '' ? '<br><strong>vor</strong> <em>„' . esc_html($st['danach']) . ' …“</em>' : '') . '<br><small>' . ((int) $st['tr'] + (int) $st['ar'] === 0 ? 'Passung: keine thematisch passende Stelle gefunden, deshalb gleichmäßig verteilt' : 'Passung: ' . (int) $st['tr'] . ' gemeinsame Wörter, ' . (int) $st['ar'] . ' gleiche Produktart') . ' · Abstand zum nächsten Block: ' . ($st['abst'] >= 99999 ? 'sehr groß' : (int) $st['abst'] . ' Zeichen') . ($st['abst'] < self::ENTZERREN_ABSTAND ? ' <span style="color:#a00">(weniger als gewünscht)</span>' : '') . '</small></li>';
        }
        $h .= '</ol><form method="post" style="margin:12px 0">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="umbau"><input type="hidden" name="sab_form" value="entzerren_los"><input type="hidden" name="sab_id" value="' . $id . '"><input type="hidden" name="sab_hash" value="' . md5($c) . '"><button class="button button-primary button-hero"' . ($gleich ? '' : ' disabled') . '>Jetzt entzerren</button> <span class="description">Der Artikel wird vorher gesichert, danach siehst du ihn mit „Stelle ansehen“ im Browser.</span></form>';
        return $h;
    }

    /** Kleines Bild (Admin-Bereich, direkt von Amazon bzw. aus dem eigenen Bildspeicher) für Austausch-Zeilen. */
    private static function mini_bild(string $asin, array $x): string {
        $u = !empty($x['bilder'][0]) ? (string) $x['bilder'][0] : '';
        if ($u === '') { $u2 = wp_upload_dir(); $pfad = self::bild_pfad(self::land(''), $asin, 0); if (is_file($pfad)) { $u = rtrim((string) $u2['baseurl'], '/') . '/sab/' . basename($pfad); } }
        return $u !== '' ? '<img src="' . esc_url($u) . '" alt="" loading="lazy" style="width:64px;height:64px;object-fit:contain;background:#fff;border:1px solid #e3e6e6;border-radius:4px;flex:none">' : '<span style="width:64px;height:64px;flex:none;display:inline-flex;align-items:center;justify-content:center;background:#f6f7f7;border:1px dashed #c3c4c7;border-radius:4px;font-size:10px;color:#888;text-align:center">kein Bild</span>';
    }

    /** Reiter „Austausch“: Produkte, die getauscht werden müssen, nach Priorität, mit bis zu drei passenden Ersatzkandidaten (mit Bild), eigener ASIN und Automatik. */
    /** Suchfeld „Im ganzen Bestand suchen“, „Weitere vier Vorschläge“ und Handy-Darstellung der Austausch-Karten (gemeinsam für Austausch und Umbau). */
    private static function austausch_skript(array $d, array $trotz, array $namen, string $land): string {
        $ps = []; foreach (self::alle_asins() as $a_) { $ps[] = [(string) $a_, (string) ($namen[$a_] ?? ''), mb_substr((string) ($d['items'][$a_]['titel'] ?? ''), 0, 90), isset($d['items'][$a_]) ? (int) self::score(array_merge((array) $d['items'][$a_], ['trotz' => isset($trotz[$a_])]))[0] : -1]; }
        $out = '<script>window.SAB_PS=' . wp_json_encode($ps) . ';window.SAB_DP=' . wp_json_encode('https://' . self::LAENDER[$land][0] . '/dp/') . ';'
            . "function sabNz(x){return x.toLowerCase().replace(/(\\d+(?:[.,]\\d+)?)[\\s-]*(volt|amperestunden?|ah|mah|watt|liter|kg|ml|cm|mm|v|w|l)(?![a-zäöüß])/g,function(a,n,u){var m={volt:'v',amperestunde:'ah',amperestunden:'ah',watt:'w',liter:'l'};return n+(m[u]||u)});}"
            . "function sabRun(w){var t=w.querySelector('.sab-ps-in'),out=w.querySelector('.sab-ps-out'),q=sabNz(t.value).trim().split(/\\s+/).filter(Boolean),alt=w.getAttribute('data-alt'),r=[],h='';if(!q.length){out.innerHTML='';return;}"
            . "for(var i=0;i<window.SAB_PS.length;i++){var p=window.SAB_PS[i],hay=sabNz(p[0]+' '+p[1]+' '+p[2]),c=0;if(p[0]===alt){continue;}for(var j=0;j<q.length;j++){if(hay.indexOf(q[j])>=0){c++;}}if(c){r.push([c,p]);}}"
            . "r.sort(function(a,b){return b[0]-a[0]||b[1][3]-a[1][3]});r=r.slice(0,8);"
            . "r.forEach(function(x){var p=x[1];h+='<div style=\"display:flex;gap:8px;align-items:center;padding:3px 0;border-bottom:1px solid #eee\"><button type=\"button\" class=\"button button-small sab-ps-pick\" data-asin=\"'+p[0]+'\" data-name=\"'+(p[1]||p[2]).replace(/\"/g,'')+'\">wählen</button><span><strong>'+(p[1]||p[2]||'(ohne Name)').replace(/</g,'&lt;')+'</strong> <small>'+(q.length>1?x[0]+' von '+q.length+' Begriffen · ':'')+(p[3]>=0?'Score '+p[3]+' · ':'')+p[0]+'</small></span> <a href=\"'+window.SAB_DP+p[0]+'\" target=\"_blank\" rel=\"noopener\">bei Amazon ansehen ↗</a></div>';});"
            . "out.innerHTML=h||'<em>Kein Treffer im Bestand.</em>';}"
            . "document.addEventListener('input',function(e){var t=e.target;if(t.classList&&t.classList.contains('sab-ps-in')){sabRun(t.closest('.sab-ps'));}});"
            . "document.addEventListener('keydown',function(e){var t=e.target;if(e.key==='Enter'&&t.classList&&t.classList.contains('sab-ps-in')){e.preventDefault();sabRun(t.closest('.sab-ps'));}});"
            . "document.addEventListener('click',function(e){var t=e.target;if(!t.closest){return;}var g=t.closest('.sab-ps-go'),c=t.closest('.sab-ps-chip');if(g){e.preventDefault();sabRun(g.closest('.sab-ps'));}if(c){e.preventDefault();var w=c.closest('.sab-ps');w.querySelector('.sab-ps-in').value=c.getAttribute('data-q');sabRun(w);}});"
            . "document.querySelectorAll('.sab-ps').forEach(function(w){if(w.querySelector('.sab-ps-in').value){sabRun(w);}});"
            . "document.addEventListener('click',function(e){var b=e.target;if(!b.classList||!b.classList.contains('sab-ps-pick')){return;}var w=b.closest('.sab-ps'),alt=w.getAttribute('data-alt');document.querySelector('input[name=\"sab_eigen['+alt+']\"]').value=b.getAttribute('data-asin');var nf=document.querySelector('input[name=\"sab_eigen_name['+alt+']\"]');if(nf&&!nf.value){nf.value=b.getAttribute('data-name');}document.getElementById('sabr_'+alt).checked=true;w.querySelector('.sab-ps-out').innerHTML='<strong>Gewählt: '+b.getAttribute('data-asin')+'</strong> (steht unter „Eigene ASIN“, dann „Jetzt tauschen“)';});</script>";
        $out .= '<style>@media(max-width:782px){.sab-tk{padding:8px!important;overflow:hidden}.sab-tk,.sab-tk *{max-width:100%;box-sizing:border-box;overflow-wrap:anywhere}.sab-tk-kopf{flex-direction:column;align-items:flex-start!important}.sab-tk-k{flex:1 1 100%!important;max-width:none!important}.sab-tk input[type=text]{width:100%!important;max-width:100%!important;size:auto}.sab-tk .sab-ps-out>div{flex-wrap:wrap}}</style>';
        $out .= '<script>document.addEventListener("click",function(e){var t=e.target;if(!t.closest)return;var b=t.closest(".sab-weiter");if(b){var k=+b.getAttribute("data-n"),c=b.closest(".sab-tk");c.querySelectorAll(".sab-g"+k).forEach(function(x){x.style.display="flex"});b.setAttribute("data-n",k+1);if(!c.querySelector(".sab-g"+(k+1)))b.style.display="none";return}var kb=t.closest(".sab-kopieren");if(kb){var ta=document.getElementById(kb.getAttribute("data-ziel"));if(ta){ta.select();try{(navigator.clipboard?navigator.clipboard.writeText(ta.value):Promise.reject()).catch(function(){document.execCommand("copy")})}catch(x){document.execCommand("copy")}var ok=kb.parentNode.querySelector(".sab-kopiert");if(ok)ok.style.display="inline"}return}var r=t.closest(".sab-kn input");if(r){var l=r.closest(".sab-tk-k");if(l){var a=l.querySelector("input[name^=sab_a]");if(a)a.checked=true}}});</script>';
        return $out;
    }

    /** Eine Austausch-Karte zu einem Produkt (Kandidaten, eigene ASIN, Suchknöpfe); gemeinsam für den Reiter Austausch und den Umbau. */
    /** Fertiger Prompt für die Mac-/PC-Sitzung: je Produkt mit zu wenig Vorschlägen alle Angaben, Regeln und das Ausgabeformat; zum Kopieren mit einem Klick. */
    /** Merkt sich Produkte, für die der Austausch (fast) keinen passenden Ersatz findet („Ersatz gesucht“); Einträge mit wieder genug Kandidaten fallen weg. Schreibt nur bei Änderung. @param array<int,array> $liste Zeilen wie in ersatz_prompt_html */
    public static function ersatz_gesucht_merken(array $liste, array $gruppen = [], string $land = ''): array {
        $alt = (array) get_option('sab_ersatz_gesucht', []); $neu = $alt; $jetzt = time();
        foreach ($liste as $r) {
            $a = (string) $r[0]; $art = ''; foreach ($gruppen as $gn => $asins) { if (in_array($a, $asins, true)) { $art = (string) $gn; break; } }
            if ((int) $r[4] >= 2) { unset($neu[$a]); continue; }
            $neu[$a] = ['name' => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $r[1])), 0, 120), 'art' => $art !== '' ? $art : (string) $r[3], 'land' => $land, 'seiten' => (int) $r[6], 'kontext' => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $r[2])), 0, 120), 'n' => (int) $r[4], 'ts' => (int) ($alt[$a]['ts'] ?? $jetzt)];
        }
        foreach ($neu as $a => $e) { if ($jetzt - (int) $e['ts'] > 45 * DAY_IN_SECONDS) { unset($neu[$a]); } }
        if ($neu != $alt) { update_option('sab_ersatz_gesucht', $neu, false); }
        return $neu;
    }

    /** Abschnitt „Ersatz gesucht“ für die Helferaufgaben: Liste und kopierbare Zeilen. */
    private static function ersatz_gesucht_html(array $gesucht): string {
        $im_bestand = array_flip(self::alle_asins()); $gesucht = array_filter($gesucht, fn($k) => isset($im_bestand[$k]), ARRAY_FILTER_USE_KEY);
        if (!$gesucht) { return ''; }
        $land = self::land(''); $host = preg_replace('#^www\.#', '', strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST))); $z = '';
        $h = '<h3 style="margin-top:2em">Ersatz gesucht (' . count($gesucht) . ')</h3><p class="description">Hier hat das Plugin selbst festgestellt, dass der Bestand und die Ersatz-Datenbank keinen (oder nur einen) passenden Ersatz kennen. Die Helfer-Sitzungen suchen bei Amazon (' . esc_html(self::LAENDER[$land][0]) . ') nach dem Titel und tragen den Fund in die Ersatz-Datenbank ein (Reiter Score) und die Handdaten nach. Keine tötenden oder verletzenden Produkte.</p><table class="widefat striped" style="max-width:980px"><thead><tr><th>Produkt</th><th>Produktart</th><th>Seiten</th><th>Kandidaten</th></tr></thead><tbody>';
        foreach ($gesucht as $a => $e) {
            $h .= '<tr><td><code>' . esc_html((string) $a) . '</code> ' . esc_html((string) $e['name']) . '</td><td>' . esc_html((string) $e['art']) . '</td><td>' . (int) $e['seiten'] . '</td><td>' . (int) $e['n'] . '</td></tr>';
            $z .= str_replace('|', '/', (string) $e['art']) . ' | ' . $land . ' | ' . $a . ' | ' . str_replace('|', ' ', (string) $e['name']) . ' | ' . $host . ' | ' . str_replace('|', ' ', (string) $e['kontext']) . "\n";
        }
        return $h . '</tbody></table><p class="description"><strong>Auftragszeilen zum Kopieren</strong> (Produktart | Land | ALTE ASIN | Titel | Seite | Text vor dem Link). Antwort der Sitzung je Fund im Format der Ersatz-Datenbank: <code>Produktart | Land | NEUE ASIN | neu | Datum | Quelle</code>:</p><textarea readonly rows="' . min(10, count($gesucht) + 1) . '" class="large-text code" onclick="this.select()">' . esc_textarea($z) . '</textarea>';
    }

    private static function ersatz_prompt_html(array $liste): string {
        $gs = self::ersatz_gesucht_html(self::ersatz_gesucht_merken($liste, self::gruppen(), self::land('')));
        if (!$liste) { return $gs; }
        $host = preg_replace('#^www\.#', '', strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST))); $dat = gmdate('Y-m-d');
        usort($liste, fn($a, $b) => ((int) $a[4] <=> (int) $b[4]));
        $p = "Aufgabe: Suche bei Amazon (" . self::LAENDER[self::land('')][0] . ") Ersatzprodukte für die folgenden Produkte der Seite $host. Zu jedem Produkt mindestens 8, besser 12 Kandidaten, die zum Produkt passen.\n\n"
           . "Regeln: gleiche Bauart wie im Kontext (Einzel bleibt Einzel, Doppel bleibt Doppel; Material und Tierart müssen passen), mindestens 4 Sterne und 30 Bewertungen (wenige Bewertungen sind ok, wenn sie sehr gut sind), Prime wenn möglich, lieferbar, keine tötenden oder verletzenden Produkte (keine Netze, Fallen, Gift, Kieselgur, Eierschalen, Salz, Leim), möglichst auch in UK/USA erhältlich. Nimm Amazons „ähnliche Produkte“ zuerst, sonst suche mit Titel oder dem im Kontext genannten Produkt. Amazon nur normal ansehen, nichts auslesen, was Amazons Bedingungen verbietet.\n\n"
           . "Ausgabe: je Produkt eine Zeile im Format `ALTE-ASIN | NEU1, NEU2, NEU3, ... | $dat` (nur ASINs). Die Zeilen gehen an den Plugin-Thread zum Einbau in „Treffer der Amazon-Suche“.\n\nProdukte (" . count($liste) . "):\n";
        foreach ($liste as $i => $r) {
            $mk = []; foreach (self::merkmale($r[1] . ' ' . $r[5] . ' ' . $r[2]) as $g => $w) { $mk[] = $g . ': ' . implode('/', $w); }
            $p .= ($i + 1) . '. ' . $r[0] . ' | Titel/Name: ' . (self::generischer_linktext((string) $r[1]) || trim((string) $r[1]) === '' ? '(Textlink ohne Produktnamen: Satz davor beachten)' : trim(preg_replace('/\s+/', ' ', $r[1]))) . ($r[3] !== '' ? ' | Kategorie: ' . $r[3] : '') . ' | Seite: ' . $host . ' (' . (int) $r[6] . ' Artikel) | Text davor: ' . ($r[2] !== '' ? '„' . trim(preg_replace('/\s+/', ' ', $r[2])) . '“' : '–') . (($sbg = self::schluesselbegriffe((string) $r[2])) ? ' | Suchbegriffe aus dem Satz: ' . implode(', ', $sbg) : '') . ($mk ? ' | Merkmale: ' . implode(', ', $mk) : '') . ' | bisher ' . (int) $r[4] . ' Vorschläge' . "\n";
        }
        return '<div class="notice notice-info inline" style="padding:8px 14px"><p><strong>Prompt für die Suche-Sitzung (' . count($liste) . ' Produkte mit weniger als 4 Vorschlägen).</strong> Mit einem Klick kopieren und im Hauptthread einfügen.</p><textarea readonly id="sab-prompt" rows="6" class="large-text code">' . esc_textarea($p) . '</textarea><p><button type="button" class="button button-primary sab-kopieren" data-ziel="sab-prompt">Prompt kopieren</button> <a class="button" download="suchauftrag-' . esc_attr($host) . '-' . esc_attr($dat) . '.txt" href="data:text/plain;charset=utf-8,' . rawurlencode($p) . '">als Datei herunterladen</a> <span class="description sab-kopiert" style="display:none">Kopiert.</span></p></div>' . $gs;
    }

    /** Eigene Abschnitte unter der Austauschliste: Produkte ohne Kandidat („Wartet auf Amazon-Suche“) und Tierschädliches („Entscheidung nötig“). */
    private static function austausch_abschnitte_html(array $d, string $land): string {
        $h = '';
        if (self::$tk_warte) {
            $h .= '<h3 style="margin-top:2em">Wartet auf Amazon-Suche (' . count(self::$tk_warte) . ')</h3><p class="description">Für diese Produkte gibt es noch keinen einzigen Kandidaten. Sie stehen im Suchauftrag unten; sobald die Treffer eingetragen sind, erscheinen sie oben als Karte.</p><ul>';
            foreach (self::$tk_warte as $z) { $h .= '<li><code>' . esc_html($z['alt']) . '</code> ' . esc_html($z['name'] !== '' ? $z['name'] : 'Name unbekannt') . ' <small>(' . esc_html($z['grund']) . '; ' . (int) $z['seiten'] . ' Seite' . ($z['seiten'] === 1 ? '' : 'n') . ')</small>' . (!empty($z['aus']) ? ' <small style="color:#8a5a00">· ' . count($z['aus']) . ' Kandidaten aussortiert' . (!empty($z['aus'][0]['grund']) ? ' (' . esc_html($z['aus'][0]['grund']) . ')' : ' (passen nicht zum Satz/Produkt)') . ', z. B. ' . esc_html(mb_substr((string) $z['aus'][0]['titel'], 0, 50)) . '</small>' : '') . ' <a href="' . esc_url(self::such_link(self::suchtext_aus_satz((string) $z['name'], (string) $z['alt'], self::text_vor_stelle((string) $z['alt'], (array) $z['f'])))) . '" target="_blank" rel="noopener">bei Amazon suchen ↗</a>';
                $nr = 0; foreach ((array) $z['f'] as $pid => $f) { if (++$nr > 3) { break; } $h .= ' · <a href="' . esc_url(admin_url('post.php?post=' . (int) $pid . '&action=edit&sab_zeige=' . $z['alt'])) . '" target="_blank" rel="noopener">' . esc_html($f['titel'] ?: ('Beitrag ' . $pid)) . '</a>' . ' (<a href="' . esc_url(add_query_arg('sab_zeige', $z['alt'], (string) get_permalink((int) $pid))) . '" target="_blank" rel="noopener"><strong>im Frontend tauschen</strong></a>)'; }
                $h .= '</li>'; }
            $h .= '</ul>';
        }
        if (self::$tk_toet) {
            $h .= '<h3 style="margin-top:2em;color:#8a1f1f">Tierschädlich, Entscheidung nötig: ersetzen, löschen oder lassen (' . count(self::$tk_toet) . ')</h3><p class="description">Diese Produkte können Tiere töten oder verletzen. Sie werden nie automatisch ersetzt oder vorgeschlagen; du entscheidest je Produkt.</p><ul>';
            foreach (self::$tk_toet as $z) {
                $li = ''; $nr = 0;
                foreach ((array) $z['f'] as $pid => $f) { if (++$nr > 6) { break; } $li .= ' <a href="' . esc_url(admin_url('post.php?post=' . (int) $pid . '&action=edit&sab_zeige=' . $z['alt'])) . '" target="_blank" rel="noopener">' . esc_html($f['titel'] ?: ('Beitrag ' . $pid)) . '</a>' . ' (<a href="' . esc_url(add_query_arg('sab_zeige', $z['alt'], (string) get_permalink((int) $pid))) . '" target="_blank" rel="noopener"><strong>im Frontend tauschen</strong></a>)'; }
                $h .= '<li><code>' . esc_html($z['alt']) . '</code> ' . esc_html($z['name'] !== '' ? $z['name'] : 'Name unbekannt') . ' <small>(' . esc_html($z['grund']) . ')</small><br><small>Artikel:' . $li . '</small></li>';
            }
            $h .= '</ul>';
        }
        self::$tk_warte = []; self::$tk_toet = [];
        return $h;
    }

    private static $tk_sammeln = false; private static $tk_warte = []; private static $tk_toet = [];

    private static function austausch_karte_html(array $z, array $d, array $trotz, array $namen, string $land, array $fund, array $lq, array &$ohneVorschlag): string {
        $h = '';
        $alt = $z['alt']; $xalt = $d['items'][$alt] ?? [];
        $k = self::austausch_kandidaten($alt, (string) $z['name'], (array) $d, $trotz, $fund, 8);
        if (empty($z['bl']) && $z['dr'] < 100 && $z['dr'] < 1000) {   // Produkt ist lieferbar: Ersatz nur, wenn der Score deutlich höher ist
            $salt = (int) self::score(array_merge((array) $xalt, ['trotz' => isset($trotz[$alt])]))[0];
            $k['liste'] = array_values(array_filter($k['liste'], fn($c) => $c['score'] !== null && (int) $c['score'] >= $salt + 10));
        }
        $best = $k['liste'][0] ?? null;
        if (self::$tk_sammeln) {   // Haupt-Austauschliste: nur entscheidbare Karten; Tierschädliches und Produkte ohne Kandidat kommen in eigene Abschnitte
            if ((int) $z['dr'] >= 1000) { self::$tk_toet[] = $z; return ''; }
            if (!$k['liste']) {
                if ((int) $z['dr'] >= 100 || !empty($z['bl'])) {
                    $z['aus'] = (array) $k['aussortiert']; self::$tk_warte[] = $z;
                    $ohneVorschlag[] = [$alt, $z['name'] !== '' ? (string) $z['name'] : (string) ($xalt['titel'] ?? ''), self::text_vor_stelle((string) $alt, (array) $z['f']), (string) ($xalt['kategorie'] ?? ''), count($k['liste']), (string) ($xalt['titel'] ?? ''), (int) $z['seiten']];
                }
                return '';   // lieferbar und kein besserer Ersatz: keine Karte
            }
        }
        $voraus = false;   // nie vorwählen: getauscht wird nur, was bewusst angeklickt wurde (Alexander 25.10.)
        $wo = '<strong>' . (int) $z['n'] . '×</strong> auf <strong>' . (int) $z['seiten'] . '</strong> Seite' . ($z['seiten'] === 1 ? '' : 'n');
        if ($z['f']) {
            $an = ''; $ed = ''; $nr = 0;
            foreach ($z['f'] as $pid => $f) {
                if (++$nr > 10) { break; }
                $tt = ' title="' . esc_attr((string) ($f['titel'] ?: ('Beitrag ' . $pid)) . ' (' . (int) $f['n'] . '×)') . '"';
                $an .= ' <a href="' . esc_url(add_query_arg('sab_zeige', $alt, (string) get_permalink((int) $pid))) . '" target="_blank" rel="noopener"' . $tt . '>' . ($z['seiten'] === 1 ? esc_html((string) ($f['titel'] ?: ('Beitrag ' . $pid))) : $nr) . '</a>';
                $ed .= ' <a href="' . esc_url(admin_url('post.php?post=' . (int) $pid . '&action=edit&sab_zeige=' . $alt)) . '" target="_blank" rel="noopener"' . $tt . '>' . ($z['seiten'] === 1 ? 'Seite bearbeiten' : $nr) . '</a>';
            }
            $wo .= ' · Stelle ansehen:' . $an . ' · Bearbeiten:' . $ed . ($z['seiten'] > 10 ? ' … (' . $z['seiten'] . ' insgesamt)' : '');
            if (!self::braucht_bild((string) $alt)) { $wo .= ' · <strong>Nur Textlink, kein Bild nötig.</strong> Mit Bild: Stelle ansehen, dann „＋ Karte“ neben dem Link.'; }
        }
        $badge = !empty($z['bl']) ? ' <span style="display:inline-block;padding:1px 9px;border-radius:9px;background:#fcf0f1;color:#8a1f1f;font-weight:700;font-size:12px">ohne Bild</span>' : '';
        if ($best && self::lernen_auto($best, (int) self::score(array_merge((array) $xalt, ['trotz' => isset($trotz[$alt])]))[0], $lq)) { $badge .= ' <span style="display:inline-block;padding:1px 9px;border-radius:9px;background:#e3f4e7;color:#1a7f37;font-weight:700;font-size:12px" title="Würde bei eingeschalteter Automatik getauscht (noch nicht eingeschaltet)">Auto-Kandidat</span>'; }
        $extra = '';
        if (!isset($d['items'][$alt])) { $extra .= '<br><strong style="color:#a00">Amazon liefert für dieses Produkt weder Titel noch Bild.</strong>' . ($z['name'] !== '' ? ' Der Name stammt aus dem Artikel.' : ' Auch im Artikel ist kein Name zu finden: bitte ASIN bei Amazon suchen.'); }
        elseif ((self::produkte()[$alt] ?? '') === '' && empty($d['items'][$alt]['titel']) && $z['name'] !== '') { $extra .= '<br><small>Name aus dem Artikel.</small>'; }
        $tv = self::text_vor_stelle((string) $alt, (array) $z['f']); $sb = self::schluesselbegriffe($tv); if ($tv !== '') { $extra .= '<br><small style="color:#444"><strong>Text davor im Artikel:</strong> „' . esc_html($tv) . '“</small>'; }
        $extra .= '<br><a class="button button-small" href="' . esc_url(self::such_link(self::suchtext_aus_satz((string) $z['name'], (string) $alt, self::text_vor_stelle((string) $alt, (array) $z['f'])))) . '" target="_blank" rel="noopener">' . (self::generischer_linktext((string) $z['name']) || $z['name'] === '' ? 'Bei Amazon nach dem Satz-Begriff suchen' : 'Bei Amazon nach dem Titel suchen') . '</a> <a class="button button-small" href="' . esc_url('https://' . self::ebay_host($land) . '/sch/i.html?_nkw=' . rawurlencode(self::suchtext_aus_satz((string) $z['name'], (string) $alt, self::text_vor_stelle((string) $alt, (array) $z['f'])))) . '" target="_blank" rel="noopener">Bei eBay nach dem Titel suchen</a> <a class="button button-small" href="' . esc_url(self::such_link($alt)) . '" target="_blank" rel="noopener">ASIN bei Amazon suchen</a>';
        $h .= '<div class="sab-tk" style="border:1px solid #c3c4c7;border-radius:8px;background:#fff;padding:10px 14px;margin:0 0 12px;max-width:1100px"><div class="sab-tk-kopf" style="display:flex;gap:12px;align-items:center;margin-bottom:8px">' . self::mini_bild($alt, $xalt)
            . '<div><strong>Zu tauschen:</strong>' . $badge . ' <a href="' . esc_url('https://' . self::LAENDER[$land][0] . '/dp/' . $alt) . '" target="_blank" rel="noopener"><code>' . esc_html($alt) . '</code></a> ' . ($z['name'] !== '' ? esc_html($z['name']) : '<em>Name unbekannt</em>') . '<br><span style="color:#a00;font-size:16px;font-weight:700">' . esc_html($z['grund']) . '</span><br><small style="color:#555" title="Reihenfolge der Liste: Dringlichkeit (0 bis 100) mal Zahl der Seiten">Priorität ' . (int) $z['prio'] . ' = Dringlichkeit ' . (int) $z['dr'] . ' &times; ' . (int) $z['seiten'] . ' Seite' . ($z['seiten'] === 1 ? '' : 'n') . '</small><br>' . $wo . $extra . '</div>' . self::alt_kennzahlen($xalt, $alt, $z['seiten']) . '</div>'
            . '<!--SABOPT' . $alt . '--><div style="display:flex;flex-wrap:wrap;gap:10px">';
        foreach ($k['liste'] as $i => $c) {
            $x = $c['x']; $pv = $c['pv'] ?? null; $nm = ($namen[$c['asin']] ?? '') ?: self::kurzname_aus_titel((string) ($x['titel'] ?? ''));
            $pvStil = $pv !== null && $pv >= 5 ? 'background:#e3f4e7;color:#1a7f37' : ($pv !== null && $pv >= 4 ? 'background:#fdf0d8;color:#8a5a00' : 'background:#eef0f0;color:#333');
            $h .= '<label class="sab-tk-k' . ($i >= 4 ? ' sab-g' . intdiv($i, 4) : '') . '" style="' . ($i >= 4 ? 'display:none;' : '') . 'flex:1 1 300px;max-width:340px;border:1px solid #dcdcde;border-radius:6px;padding:8px;display:flex;gap:8px;cursor:pointer"><input type="radio" name="sab_a[' . esc_attr($alt) . ']" value="' . esc_attr($c['asin']) . '"' . ($voraus && $i === 0 ? ' checked' : '') . '>'
                . '<span style="display:flex;flex-direction:column;gap:6px;align-items:center;flex:none">' . self::mini_bild($c['asin'], $x) . '<button class="button button-primary button-small" name="sab_einzeln" value="' . esc_attr($alt) . '" onclick="this.closest(\'label\').querySelector(\'input[type=radio]\').checked=true" title="Wählt dieses Produkt und tauscht sofort (nur dieses Produkt, mit Sicherung und Rückgängig)">Jetzt tauschen</button></span><span>' . (!empty($c['neu']) ? '<span style="display:inline-block;padding:0 7px;border-radius:9px;background:#dbe9ff;color:#0b3d91;font-size:11px;font-weight:700">NEU durch Amazon-Suche</span><br>' : '<span style="display:inline-block;padding:0 7px;border-radius:9px;background:#eee;color:#444;font-size:11px">aus dem Bestand</span><br>') . '<strong>' . esc_html($nm !== '' ? $nm : '(noch keine Daten)') . '</strong><br><a href="' . esc_url('https://' . self::LAENDER[$land][0] . '/dp/' . $c['asin']) . '" target="_blank" rel="noopener"><code>' . esc_html($c['asin']) . '</code></a> <small><a href="' . esc_url('https://' . self::LAENDER[$land][0] . '/dp/' . $c['asin']) . '" target="_blank" rel="noopener">bei Amazon ansehen ↗</a></small><br>'
                . '<small>' . esc_html($c['quelle']) . ' · <strong>Score ' . ($c['score'] !== null ? (int) $c['score'] : '?') . '</strong>' . (!empty($x['bonus']) ? ' <span style="color:#6a3fb0;font-weight:700" title="Von Hand vergebener Bonus, im Score enthalten">&#9998; ' . ($x['bonus'] > 0 ? '+' : '') . (int) $x['bonus'] . ' manuell</span>' : '') . ($x && ($x['preis'] ?? '') !== '' ? ' · ' . esc_html((string) $x['preis']) : '') . ($x && isset($x['sterne']) && $x['sterne'] !== null ? ' · ' . esc_html(str_replace('.', ',', (string) $x['sterne'])) . ' ★' . (isset($x['anzahl']) && $x['anzahl'] !== null ? ' (' . number_format((int) $x['anzahl'], 0, ',', '.') . ')' : '') : '') . ' · ' . self::prime_zeichen($x && isset($x['prime']) ? $x['prime'] : null) . ($x && isset($x['liefertage']) && $x['liefertage'] !== null ? ' · ' . esc_html(str_replace('.', ',', (string) round((float) $x['liefertage'], 1))) . ' T' : '') . '</small><br>'
                . ($pv !== null ? '<span style="display:inline-block;padding:1px 8px;border-radius:10px;font-weight:700;' . $pvStil . '">' . esc_html(str_replace('.', ',', (string) $pv)) . ' % Provision</span>' : '<span style="color:#a00">Provision unbekannt</span>')
                . (!$c['ok'] ? ' <span style="color:#a00">· nicht lieferbar / keine Daten</span>' : '') . ($x ? '<br>' . self::score_grafik(self::score($x)[2]) . (($sa = (int) (self::score(array_merge($xalt, ['trotz' => isset($trotz[$alt])]))[0] ?? 0)) && $c['score'] !== null ? ' <small style="font-weight:700;color:' . ($c['score'] >= $sa ? '#1a7f37' : '#a00') . '" title="Unterschied zum alten Produkt">' . ($c['score'] - $sa >= 0 ? '+' : '') . ((int) $c['score'] - $sa) . ' gegenüber dem alten</small>' : '') : '') . self::handdaten_hinweis((string) $c['asin']) . self::kurzname_wahl_html($alt, $c, $namen, $x) . '</span></label>';
        }
        if (count($k['liste']) > 4) { $h .= '<p style="flex:1 1 100%;margin:0"><button type="button" class="button sab-weiter" data-n="1">Weitere vier Vorschläge anzeigen</button> <small class="description">(' . count($k['liste']) . ' insgesamt)</small></p>'; }
        if (count($k['liste']) < 4) { $ohneVorschlag[] = [$alt, $z['name'] !== '' ? (string) $z['name'] : (string) ($xalt['titel'] ?? ''), self::text_vor_stelle((string) $alt, (array) $z['f']), (string) ($xalt['kategorie'] ?? ''), count($k['liste']), (string) ($xalt['titel'] ?? ''), (int) $z['seiten']]; }
        $suchtitel = self::suchtext_aus_satz((string) $z['name'], (string) $alt, self::text_vor_stelle((string) $alt, (array) $z['f']));
        if (!$k['liste']) { $h .= '<p style="margin:0"><em>' . ($z['dr'] < 100 ? 'Kein besserer Ersatz im Bestand: das Produkt ist lieferbar, kein Kandidat hat einen deutlich höheren Score.' : 'Kein passender Kandidat gefunden: <strong>Amazon-Suche nötig</strong> (Titel oder Text davor als Suchwort).') . '</em> <a class="button button-small" href="' . esc_url(self::such_link($suchtitel)) . '" target="_blank" rel="noopener">Bei Amazon nach dem Titel suchen</a></p>'; }
        $h .= '</div>'; $optzeile = '<div style="display:flex;flex-wrap:wrap;gap:18px;align-items:center;margin:2px 0 12px;padding:10px 12px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;font-size:14px"><label><input type="radio" name="sab_a[' . esc_attr($alt) . ']" value=""' . ($voraus ? '' : ' checked') . '> <strong>Nichts tauschen</strong></label><label><input type="radio" name="sab_a[' . esc_attr($alt) . ']" value="__eigen" id="sabr_' . esc_attr($alt) . '"> <strong>Eigene ASIN:</strong> <input type="text" name="sab_eigen[' . esc_attr($alt) . ']" size="40" style="font-size:14px;padding:4px 6px" placeholder="Amazon-Link oder ASIN" oninput="document.getElementById(\'sabr_' . esc_attr($alt) . '\').checked=true"></label><label><strong>Kurzname:</strong> <input type="text" name="sab_eigen_name[' . esc_attr($alt) . ']" size="22" maxlength="60" placeholder="optional, z. B. Teichfolie 4×6 m" oninput="document.getElementById(\'sabr_' . esc_attr($alt) . '\').checked=true"></label><button class="button button-primary" name="sab_einzeln" value="' . esc_attr($alt) . '" title="Tauscht nur dieses Produkt mit der angewählten Karte oder eigenen ASIN">Jetzt tauschen</button>' . (self::$tk_sammeln ? (isset(((array) get_option('sab_austausch_hinten', []))[$alt]) ? '<button class="button" name="sab_vorn" value="' . esc_attr($alt) . '" title="Wieder an die ursprüngliche Stelle der Liste">Wieder nach vorn</button>' : '<button class="button" name="sab_hinten" value="' . esc_attr($alt) . '" title="Stellt das Produkt ans Ende der Liste, damit es beim Durchgehen nicht stört">Ans Ende</button>') : '') . '<a class="button" href="' . esc_url(self::such_link($suchtitel)) . '" target="_blank" rel="noopener">Bei Amazon nach dem Titel suchen</a></div>'; $h = str_replace('<!--SABOPT' . $alt . '-->', $optzeile, $h); $h .= self::satz_feld_html((string) $alt, (array) $z['f'], (string) $z['name']) . '<div class="sab-ps" data-alt="' . esc_attr($alt) . '" style="margin:10px 0 0"><label><strong>Im ganzen Bestand suchen:</strong> <input type="search" class="sab-ps-in" size="34" placeholder="Name, Stichwort oder ASIN eintippen" autocomplete="off" value="' . esc_attr(implode(' ', $sb)) . '"></label> <button type="button" class="button sab-ps-go">Suchen</button>' . ($sb ? '<div style="margin:4px 0"><small>Suchbegriffe aus dem Satz: ' . implode(' ', array_map(fn($w) => '<a href="#" class="sab-ps-chip" data-q="' . esc_attr($w) . '" style="display:inline-block;padding:1px 8px;margin:0 3px 3px 0;border-radius:10px;background:#f0f6fc;border:1px solid #c5d9ed;text-decoration:none">' . esc_html($w) . '</a>', $sb)) . '</small></div>' : '') . '<div class="sab-ps-out" style="margin-top:4px;max-width:760px"></div></div><p style="margin:6px 0 0">' . ''
            . ($k['aussortiert'] ? ' <small class="description">· ' . count($k['aussortiert']) . ' Vorschläge aussortiert, weil sie nicht zum Produkt passen (z. B. „' . esc_html(mb_substr((string) $k['aussortiert'][0]['titel'], 0, 50)) . '“)</small>' : '') . '</p></div>';
        return $h;
    }

    private static function austausch_html(bool $helfer = false): string {
        $land = self::land(''); $d = self::uebersicht_daten($land, false); $trotz = (array) get_option('sab_trotz', []); $namen = self::produkte();
        $h = '<h2>Austausch: Produkte ersetzen, die Amazon nicht mehr liefert</h2>' . '<form method="post">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="austausch"><input type="hidden" name="sab_form" value="hinweis_leer">' . self::hinweis_box('austausch', 'austausch', '<p class="description">Hauptaufgabe des Plugins neben der Ausgabe von Bildern, Links und Karten: Produkte, die bei Amazon fehlen, rechtzeitig durch passende ersetzen. Zu jedem Problemprodukt zeigt das Plugin bis zu vier <strong>passende</strong> Kandidaten aus dem eigenen Bestand plus neue Treffer der Amazon-Suche (zwei, bei wenig Passendem im Bestand bis zu vier) (gleiche Art oder Gruppe, bei Spezialprodukten auch zur Textstelle) aus Amazons Alternativen, Favoriten und dem Katalog, geordnet nach Score; der höhere Provisionssatz zählt stark (5 % schlägt 3 %, es sei denn der Score ist deutlich schlechter). Du kannst einen wählen oder eine eigene ASIN eintragen.</p>') . '</form>';
        $h .= self::scan_knopf_html('austausch') . '<form method="post" style="margin:8px 0">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="austausch"><input type="hidden" name="sab_form" value="bilder_nachladen"><button class="button">Alle Bilder jetzt prüfen und nachladen</button> <span class="description">Holt für alle Produkte dieser Seite ohne frisches Bild das Bild sofort und räumt erledigte Warnungen auf. Die Zahl in „Produkte ohne Bild“ unten zeigt, was noch fehlt.</span></form>';
        if (!empty($d['fehler'])) { return $h . '<div class="notice notice-error inline"><p>' . esc_html($d['fehler']) . '</p></div>'; }
        $faelle = self::tausch_faelle((array) $d, $trotz);
        $fund = self::fundstellen(array_keys($faelle) ?: ['XXXXXXXXXX']);
        $zeilen = [];
        $dring = [];   // Dringlichkeit 0 bis 100 je Produkt: nicht lieferbar/unklar = 100, sonst schwacher Score, lange Lieferzeit, wenige Sterne
        $marken = (array) get_option('sab_marken', []);
        foreach (self::alle_asins() as $a0) {
            $x0 = $d['items'][$a0] ?? null;
            if (self::toetend_asin((string) $a0, (array) $d)) { $dring[$a0] = 100; if (!isset($faelle[$a0])) { $faelle[$a0] = 'Tierschutz'; } continue; }
            if (!$x0) { if (self::amazon_unbekannt((string) $a0, (array) $d)) { $dring[$a0] = 100; } continue; }
            $x0['trotz'] = isset($trotz[$a0]);
            if (empty($x0['bild']) && empty($x0['bilder']) && self::bild_frisch($land, (string) $a0)) { $x0['bild'] = 'lokal'; $x0['bilder'] = ['lokal']; }   // Bild liegt bereits auf dem Server: nicht als „ohne Bild“ melden
            $sc0 = self::score($x0)[0]; $dr0 = self::tausch_dringlichkeit($x0, $sc0, self::braucht_bild((string) $a0));
            if (($marken[$a0] ?? '') === 'ers') { $dr0 = max($dr0, 100); }
            if ($dr0 > 0) {
                $dring[$a0] = $dr0;
                if (!isset($faelle[$a0])) {
                    $faelle[$a0] = ($marken[$a0] ?? '') === 'ers' ? 'von dir als „Ersetzen“ markiert' : ($sc0 < 35 ? 'sehr schwacher Score (' . $sc0 . ')' : (isset($x0['liefertage']) && (float) $x0['liefertage'] > 14 ? 'Lieferzeit über 14 Tage' : (isset($x0['sterne']) && $x0['sterne'] !== null && (float) $x0['sterne'] < 3.5 ? 'unter 3,5 Sterne' : 'Prime-Status unklar (bei Amazon nicht erkennbar)')));
                }
            }
        }
        $fund = self::fundstellen(array_keys($faelle) ?: ['XXXXXXXXXX']);
        foreach ($faelle as $alt => $grund) {
            $f = $fund[$alt] ?? []; $seiten = count($f); $n = (int) array_sum(array_column($f, 'n'));
            if ($seiten === 0) { continue; }   // steht in keinem Artikel: kein Handlungsbedarf
            $toet = self::toetend_asin((string) $alt, (array) $d);   // steht schon in Artikeln, obwohl es Tiere töten kann: ganz nach oben, Ersatz suchen
            $zeilen[] = ['alt' => $alt, 'grund' => $toet ? 'Tierschutz-Prüfung: Begriff „' . self::toetend_wort((string) $alt, (array) $d) . '“ im Titel, bitte prüfen' : $grund, 'seiten' => $seiten, 'n' => $n, 'name' => (preg_match('/^\s*(angebote?\s+bei\s+amazon|bei\s+amazon|amazon|hier|jetzt|zum\s+produkt|ansehen|link)\b/iu', (string) self::anzeige_name($alt, (array) $d, $fund)) ? '' : self::anzeige_name($alt, (array) $d, $fund)), 'prio' => $toet ? 1000 * max(1, $seiten) : ($dring[$alt] ?? 100) * max(1, $seiten), 'dr' => $toet ? 1000 : ($dring[$alt] ?? 100), 'f' => $f];
        }
        usort($zeilen, fn($a, $b) => [(int) ($b['dr'] >= 100), $b['prio'], $b['n']] <=> [(int) ($a['dr'] >= 100), $a['prio'], $a['n']]);   // zuerst alles Nicht-Lieferbare/ohne Bild, darin nach Priorität; danach der Rest
        // Produkte ohne Bild (laut Warnliste, von Amazon unbekannt oder ohne Bild und ohne eigenes Bild) kommen als eigene Dringend-Liste nach oben: zuerst die auf den meisten Seiten
        self::bildfehler_html();   // räumt erledigte und veraltete Einträge auf, bevor die Dringend-Liste sie liest
        $bf = []; foreach ((array) get_option('sab_bildfehler', []) as $e_) { $bf[(string) ($e_['asin'] ?? '')] = 1; }
        $zb = []; $zr = [];
        foreach ($zeilen as $z_) {
            $a_ = (string) $z_['alt'];
            $xi_ = $d['items'][$a_] ?? null; $unl_ = $xi_ && (empty($xi_['lieferbar']) || ($xi_['status'] ?? '') === 'OUT_OF_STOCK');   // nicht lieferbar: Amazon liefert dann oft keine Bild-URL, das Produkt hat aber eins; es steht ohnehin als „nicht lieferbar“ da
            $z_['bl'] = !$xi_ || (!$unl_ && self::braucht_bild($a_) && (isset($bf[$a_]) || (empty($xi_['bilder']) && !is_file(self::bild_pfad($land, $a_, 0)) && self::braucht_bild($a_))));
            if ($z_['bl']) { $zb[] = $z_; } else { $zr[] = $z_; }
        }
        usort($zb, fn($a, $b) => [$b['seiten'], $b['n']] <=> [$a['seiten'], $a['n']]);
        $hinten = (array) get_option('sab_austausch_hinten', []);   // vom Nutzer ans Ende geschoben
        $zh = array_values(array_filter(array_merge($zb, $zr), fn($z_) => isset($hinten[(string) $z_['alt']])));
        $zb = array_values(array_filter($zb, fn($z_) => !isset($hinten[(string) $z_['alt']]))); $zr = array_values(array_filter($zr, fn($z_) => !isset($hinten[(string) $z_['alt']])));
        $zeilen = array_merge($zb, $zr, $zh); $nb = count($zb); $nvorn = count($zb) + count($zr);
        $schwelle = (int) get_option('sab_auto_schwelle', 55); $ladeLimit = min(60, $nb + 10);
        $h .= self::austausch_skript($d, $trotz, $namen, $land);
        $h .= '<form method="post">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="austausch"><input type="hidden" name="sab_form" value="austausch_viele">'
            . '<h3>Zu tauschen <!--SAB_ZZ-->, wichtigste zuerst</h3><p class="description">Vorausgewählt ist der beste Kandidat ab Score ' . $schwelle . ' mit lieferbarem Angebot. „Nichts tauschen“ lässt das Produkt unverändert. Reihenfolge: zuerst alles, was nicht lieferbar ist, keinen Preis oder kein Bild hat, nach Seitenzahl; danach lieferbare Produkte mit schwachem Score. Es werden immer die ' . $ladeLimit . ' obersten gezeigt; jedes getauschte Produkt rückt das nächste nach, bis nur noch gute Produkte übrig sind.</p>';
        $zaehler = 0; $kopf_a = $kopf_b = $kopf_c = false; $lq = self::lernen_quote(); $ohneVorschlag = [];
        $h .= '<p><button class="button button-primary button-hero" name="sab_form" value="ersatz_suchen">Ersatz suchen</button> <span class="description">Bewertet Bestand und eingetragene Amazon-Treffer neu (nach Titel, Kategorie und Text davor), bis zu 12 Vorschläge je Produkt, 4 auf einmal. Wo zu wenig gefunden wird, steht der Prompt für die Sitzung ganz unten.</span></p><!--SAB_HINWEIS-->';
        $h .= '<!--SAB_H-->';
        $h .= '<p style="font-size:14px"><strong>Amazon-Treffer im Plugin:</strong> ' . count(self::such_kandidaten()) . ' Produkte mit Suchtreffern, ' . count((array) get_option('sab_importe_erledigt', [])) . ' Importe eingespielt.</p>';
        $h .= '<!--/SAB_H-->';
        $h .= '<p style="font-size:14px"><strong>Trefferquote der Vorschläge:</strong> ' . ($lq['n'] ? 'Platz 1 gewählt: ' . $lq['eins'] . ' von ' . $lq['n'] . ' (' . $lq['quote'] . ' %)' : 'noch keine Tausche gemerkt') . '. <span class="description">Gemerkt werden nur ASINs und der Platz im Ranking (höchstens 500 Einträge). Übergangene Vorschläge rutschen für dasselbe Produkt nach hinten. ' . ($lq['n'] >= 30 && $lq['quote'] >= 90 ? 'Grün markierte Karten würden bei eingeschalteter Automatik getauscht (noch nicht eingeschaltet).' : 'Eine Auto-Markierung gibt es ab 30 Tauschen mit 90 % Platz 1.') . '</span></p>';
        $h .= '<!--SAB_H-->';
        $h .= '<p class="description">Vor jeder Austauschrunde sucht die Mac-Sitzung zu jedem Produkt bei Amazon nach dem Titel und trägt die besten Treffer mit Score und Provision unten ein (Quelle „Amazon-Suche“).</p>';
        $h .= '<!--/SAB_H-->';
        foreach ($zeilen as $z) {
            if ($nb > 0 && !$kopf_a) { $kopf_a = true; $h .= '<h3 style="margin:18px 0 6px;color:#8a1f1f">Dringend tauschen: Produkte ohne Bild (' . (int) $nb . '), zuerst die auf den meisten Seiten</h3><p class="description">Hier bekommen Besucher kein Bild. Oben stehen die Produkte, die auf vielen Seiten vorkommen.</p>'; }
            if ($nb > 0 && $zaehler === $nb && $nvorn > $nb && !$kopf_b) { $kopf_b = true; $h .= '<h3 style="margin:22px 0 6px">Weitere Produkte zu tauschen</h3>'; }
            if ($zh && $zaehler === $nvorn && !$kopf_c) { $kopf_c = true; $h .= '<h3 style="margin:22px 0 6px;color:#555">Ans Ende geschoben (' . count($zh) . ')</h3><p class="description">Diese Produkte hast du zurückgestellt. Mit „Wieder nach vorn“ stehen sie wieder in der Reihenfolge oben.</p>'; }
            if (++$zaehler > $ladeLimit) { $h .= '<p class="description">… und ' . (count($zeilen) - $ladeLimit) . ' weitere (erst diese erledigen).</p>'; break; }
            self::$tk_sammeln = true; $kh = self::austausch_karte_html($z, $d, $trotz, $namen, $land, $fund, $lq, $ohneVorschlag); self::$tk_sammeln = false;
            if ($kh === '') { $zaehler--; } else { $h .= $kh; }
        }
        $n_karten = max(0, min($zaehler, $ladeLimit)); $n_warte = count(self::$tk_warte); $n_toet = count(self::$tk_toet);
        $h = str_replace('<!--SAB_ZZ-->', '(' . count($zeilen) . ' Produkte: ' . $n_karten . ' als Karte' . ($n_warte ? ', ' . $n_warte . ' warten auf die Amazon-Suche' : '') . ($n_toet ? ', ' . $n_toet . ' tierschädlich mit Entscheidung nötig' : '') . (count($zeilen) > $ladeLimit ? ', Rest später' : '') . '; ein Produkt zählt einmal, egal wie viele Bild- und Textlinks es hat)', $h);
        $h .= self::austausch_abschnitte_html($d, $land);
        $h .= ($zeilen ? '<p><button class="button button-primary button-hero">Auswahl tauschen (jeweils mit Sicherung)</button> <span class="description">Der Tausch gilt in allen Artikeln, Titel und Textlinks folgen dem neuen Bildprodukt. Bis zu 10 pro Klick.</span></p>' : '<p>Nichts zu tauschen.</p>') . '</form>';
        $pr_ = self::ersatz_prompt_html($ohneVorschlag); $no_ = self::name_offen_html();   // Hilfsmittel für die Suche-Sitzung stehen unten, damit die Tauschliste oben beginnt
        $h = str_replace('<!--SAB_HINWEIS-->', ($pr_ !== '' || $no_ !== '') ? '<p class="description">Aufgaben für die Helfer-Sitzungen (Suche-Prompt, Kurznamen prüfen) stehen im Reiter <a href="' . esc_url(admin_url('admin.php?page=sab-helfer')) . '">Helferaufgaben</a>.</p>' : '', $h);
        $h .= '<!--SAB_H--><div id="sab-unten">' . $pr_ . $no_ . '</div>';
        // Austauschliste: Amazons Alternativen im Überblick
        $alts = self::amz_alternativen();
        $h .= '<h3 style="margin-top:2em">Amazons Alternativen (Austauschliste)</h3><form method="post">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="austausch"><input type="hidden" name="sab_form" value="amz_alt">'
            . '<p class="description">Eine Zeile pro Produkt: <code>ALTE-ASIN | ASIN1, ASIN2, ASIN3 | Datum</code>. Das sind die „alternativen Produkte“, die Amazon auf der Seite eines nicht mehr verfügbaren Produkts zeigt. Die Mac-Sitzung liest sie beim Rundgang und trägt sie hier ein. Nur passende werden oben vorgeschlagen.</p>'
            . '<textarea name="sab_amz_alt" rows="6" class="large-text code">' . esc_textarea((string) get_option('sab_amz_alt', '')) . '</textarea><p><button class="button">Speichern</button> <span class="description">' . count($alts) . ' Produkte mit Alternativen.</span></p></form>';
        $h .= '<p class="description" style="margin-top:2em">Für Fortgeschrittene: <a href="' . esc_url(admin_url('admin.php?page=sab-produkte')) . '">Kurznamen, Ersatzlisten und Austausch-Regeln von Hand bearbeiten</a>.</p>';
        $h .= '<h3 style="margin-top:2em">Treffer der Amazon-Suche</h3><form method="post">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="austausch"><input type="hidden" name="sab_form" value="such_kand">'
            . '<p class="description">Wo Amazon keine Alternativen zeigt: Die Mac-Sitzung sucht bei Amazon nach dem Titel des Produkts und trägt hier die besten passenden Treffer ein (gleiches Format: <code>ALTE-ASIN | ASIN1, ASIN2, ASIN3 | Datum</code>). Sie erscheinen oben als „Amazon-Suche“.</p>'
            . '<textarea name="sab_such_kand" rows="5" class="large-text code">' . esc_textarea((string) get_option('sab_such_kand', '')) . '</textarea><p><button class="button">Speichern</button> <span class="description">' . count(self::such_kandidaten()) . ' Produkte mit Suchtreffern.</span></p></form>';
        $ag = self::auch_gekauft(); $hf = self::auch_haeufigkeit();
        $h .= '<h3 style="margin-top:2em">Kunden kauften auch</h3><form method="post">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="austausch"><input type="hidden" name="sab_form" value="auch_gekauft">'
            . '<p class="description">Auf jeder Amazon-Produktseite steht „Kunden, die dies gekauft haben, kauften auch“. Die Mac-Sitzung trägt diese Produkte hier ein (Format: <code>ASIN | ASIN1, ASIN2, ASIN3 | Datum</code>, die ersten etwa zehn). Das Plugin zählt, wie oft ein Produkt vorkommt, ermittelt den Score und schlägt häufig Mitgekauftes beim Austausch vor.</p>'
            . '<textarea name="sab_auch_gekauft" rows="5" class="large-text code">' . esc_textarea((string) get_option('sab_auch_gekauft', '')) . '</textarea><p><button class="button">Speichern</button> <span class="description">' . count($ag) . ' Produkte mit Mitgekauftem.</span></p></form>';
        if ($hf) {
            $h .= '<h4>Am häufigsten mitgekauft</h4><form method="post">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="austausch"><input type="hidden" name="sab_form" value="auch_uebernehmen"><table class="widefat striped" style="max-width:860px"><thead><tr><th>Produkt</th><th>Vorkommen</th><th>Score</th><th>Stand</th></tr></thead><tbody>';
            foreach (array_slice($hf, 0, 20, true) as $a => $n) {
                $x = $d['items'][$a] ?? null; $im = isset($namen[$a]);
                $sc = $x ? self::score($x)[0] : null;
                $t = ($namen[$a] ?? '') ?: self::kurzname_aus_titel((string) ($x['titel'] ?? ''));
                $h .= '<tr><td><code>' . esc_html($a) . '</code> ' . esc_html($t) . ($n >= 3 && !$im ? ' <strong style="color:#b36b00">Häufung</strong>' : '') . '</td><td>' . (int) $n . '×</td><td>' . ($sc === null ? '–' : (int) $sc) . '</td><td>' . ($im ? 'im Portfolio' : '<label><input type="checkbox" name="sab_auch_neu[]" value="' . esc_attr($a) . '"> aufnehmen</label>') . '</td></tr>';
            }
            $h .= '</tbody></table><p><button class="button">Angekreuzte in die Produktliste aufnehmen</button></p></form>';
        }
        $h .= '<!--/SAB_H-->';
        // Automatik
        $auto = get_option('sab_auto_tausch') === '1'; $log = (array) get_option('sab_auto_log', []);
        $h .= '<h3 style="margin-top:2em">Automatik</h3><form method="post">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="austausch"><input type="hidden" name="sab_form" value="austausch_auto">'
            . '<p><label><input type="checkbox" name="sab_auto_tausch" value="1"' . ($auto ? ' checked' : '') . '> Produkte, die Amazon nicht mehr kennt, im täglichen Lauf selbst ersetzen</label></p>'
            . '<p>Nur wenn der beste passende Kandidat lieferbar ist und mindestens Score <input type="number" name="sab_auto_schwelle" min="0" max="100" value="' . $schwelle . '" style="width:70px"> hat. Das Plugin legt dann eine <strong>Austausch-Regel</strong> an (Reiter „Produkte und Ersatzlisten“): Sie wirkt sofort auf alle Platzhalter, schreibt aber nichts in die Artikel. Rückgängig: Regel löschen. Du bekommst eine Mail.</p>'
            . '<p><button class="button">Speichern</button></p></form>';
        if ($log) {
            $nf = self::tausch_regeln();
            $h .= '<h4 id="sab-auto-log">Automatisch getauscht (zur Kontrolle)</h4><p class="description">Links das alte, rechts das neue Produkt. „Rückgängig“ löscht die Austausch-Regel, das alte Produkt erscheint dann wieder. Ist alles geprüft, kannst du die Liste leeren (die Tausche bleiben).</p>';
            $h .= '<form method="post">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="austausch"><input type="hidden" name="sab_form" value="auto_log">';
            $h .= '<table class="widefat striped" style="max-width:1000px"><thead><tr><th>Wann</th><th>Vorher</th><th>Nachher</th><th>Score</th><th></th></tr></thead><tbody>';
            $fa = self::fundstellen(array_values(array_unique(array_column($log, 'alt'))) ?: ['XXXXXXXXXX']);
            foreach (array_slice(array_reverse($log), 0, 30) as $e) {
                $xa = $d['items'][$e['alt']] ?? []; $xn = $d['items'][$e['neu']] ?? [];
                $na = ($namen[$e['alt']] ?? '') ?: self::kurzname_aus_titel((string) ($xa['titel'] ?? '')); $nn = ($namen[$e['neu']] ?? '') ?: self::kurzname_aus_titel((string) ($xn['titel'] ?? ''));
                $aktiv = ($nf[$e['alt']] ?? '') === $e['neu'];
                $h .= '<tr><td>' . esc_html(wp_date('d.m. H:i', (int) $e['ts'])) . '</td>'
                    . '<td><div style="display:flex;gap:8px;align-items:center">' . self::mini_bild($e['alt'], $xa) . '<span><strong>' . esc_html($na ?: $e['alt']) . '</strong><br><code>' . esc_html($e['alt']) . '</code><br><small>auf ' . count($fa[$e['alt']] ?? []) . ' Seiten</small></span></div></td>'
                    . '<td><div style="display:flex;gap:8px;align-items:center">' . self::mini_bild($e['neu'], $xn) . '<span><strong>' . esc_html($nn ?: $e['neu']) . '</strong><br><code>' . esc_html($e['neu']) . '</code></span></div></td>'
                    . '<td>' . (int) $e['score'] . '</td><td>' . ($aktiv ? '<button class="button" name="auto_zurueck" value="' . esc_attr($e['alt']) . '">Rückgängig</button>' : '<small>zurückgenommen</small>') . '</td></tr>';
            }
            $h .= '</tbody></table><p><button class="button" name="auto_leeren" value="1" onclick="return confirm(\'Liste leeren? Die Tausche bleiben bestehen.\')">Liste leeren (geprüft)</button></p></form>';
        }
        preg_match_all('/<!--SAB_H-->(.*?)<!--\/SAB_H-->/s', $h, $mm);
        if ($helfer) { return '<h2>Helferaufgaben</h2><p class="description">Was die Helfer-Sitzungen (Mac/PC) erledigen: Suchtreffer, Alternativen und Kurznamen eintragen. Hier gebündelt, damit die anderen Reiter frei bleiben.</p>' . str_replace('name="sab_tab" value="austausch"', 'name="sab_tab" value="helfer"', implode('', $mm[1])); }
        $h = preg_replace('/<!--SAB_H-->.*?<!--\/SAB_H-->/s', '', $h);
        return $h;
    }

    /** Automatik: Produkte, die Amazon nicht kennt, bekommen eine Austausch-Regel auf den besten lieferbaren Kandidaten (nur wenn eingeschaltet). */
    private static function auto_tausch(): void {
        if (get_option('sab_auto_tausch') !== '1') { return; }
        $land = self::land(''); $d = self::uebersicht_daten($land, false); if (!empty($d['fehler']) || empty($d['items'])) { return; }
        $trotz = (array) get_option('sab_trotz', []); $schwelle = (int) get_option('sab_auto_schwelle', 55); $regeln = self::tausch_regeln(); $neu_regeln = []; $log = (array) get_option('sab_auto_log', []);
        $fund = self::fundstellen(array_keys(array_filter(self::tausch_faelle((array) $d, $trotz), fn($g) => $g === 'Amazon kennt das Produkt nicht mehr')) ?: ['XXXXXXXXXX']);
        foreach (self::tausch_faelle((array) $d, $trotz) as $alt => $grund) {
            if ($grund !== 'Amazon kennt das Produkt nicht mehr' || isset($regeln[$alt]) || empty($fund[$alt])) { continue; }
            $k = self::austausch_kandidaten($alt, self::anzeige_name($alt, (array) $d, $fund), (array) $d, $trotz, $fund);
            $b = $k['liste'][0] ?? null;
            if (!$b || !$b['ok'] || $b['score'] === null || $b['score'] < $schwelle || $b['passt'] < 1) { continue; }
            $neu_regeln[$alt] = $b['asin']; $log[] = ['ts' => time(), 'alt' => $alt, 'neu' => $b['asin'], 'score' => $b['score']];
        }
        if (!$neu_regeln) { return; }
        $zeilen = []; foreach (array_merge($regeln, $neu_regeln) as $a => $b) { $zeilen[] = $a . ' => ' . $b; }
        update_option('sab_tausch', implode("\n", $zeilen), false); update_option('sab_auto_log', array_slice($log, -50), false); delete_transient('sab_alle_asins');
        $namen = self::produkte(); $pl = [];
        foreach ($neu_regeln as $a => $b) { $pl[$a] = ['neu' => $b, 'alt_name' => self::anzeige_name($a, (array) $d, $fund), 'neu_name' => self::anzeige_name($b, (array) $d, []), 'seiten' => array_map(fn($pid, $f) => ['titel' => (string) ($f['titel'] ?? ''), 'url' => add_query_arg('sab_zeige', $b, (string) get_permalink((int) $pid)), 'edit' => admin_url('post.php?post=' . (int) $pid . '&action=edit&sab_zeige=' . $b)], array_keys((array) ($fund[$a] ?? [])), array_values((array) ($fund[$a] ?? [])))]; }
        self::alarm('auto', 'Produkte automatisch ersetzt', self::auto_mail_text($pl, (string) wp_parse_url(home_url(), PHP_URL_HOST), admin_url('admin.php?page=sab-austausch#sab-auto-log')));
    }

    /** Text der Mail „Produkte automatisch ersetzt“: je Produkt Backend-Link (Kontrolle/Rückgängig) und Frontend-Links zu den Artikeln, in denen getauscht wurde. @param array<string,array> $pl alte ASIN => [neu, alt_name, neu_name, seiten[titel,url,edit]] */
    public static function auto_mail_text(array $pl, string $host, string $backend): string {
        $t = "Seite: $host\nDas Plugin hat Produkte ersetzt, die Amazon nicht mehr kennt (Austausch-Regeln, schreibt nichts in die Artikel):\n";
        foreach ($pl as $alt => $e) {
            $t .= "\n" . ($e['alt_name'] !== '' ? $e['alt_name'] . ' ' : '') . "($alt) => " . ($e['neu_name'] !== '' ? $e['neu_name'] . ' ' : '') . '(' . $e['neu'] . ")\n";
            $t .= "  Backend (Kontrolle, Rückgängig): $backend\n";
            foreach (array_slice((array) $e['seiten'], 0, 5) as $sx) { $t .= '  Artikel „' . $sx['titel'] . '“: ' . $sx['url'] . "\n    im Backend: " . $sx['edit'] . "\n"; }
            if (count((array) $e['seiten']) > 5) { $t .= '  … und ' . (count($e['seiten']) - 5) . " weitere Artikel\n"; }
        }
        return $t . "\nRückgängig: im Backend unter Austausch > „Automatisch getauscht (zur Kontrolle)“ auf „Rückgängig“ klicken. Die Automatik schaltest du dort unter „Automatik“ aus.";
    }

    /** Zu Produkten, die Amazon nicht mehr kennt: Ersatz-ASIN eingeben oder den Vorschlag (Favorit mit hohem Score, passende Art) übernehmen; tauscht in allen Artikeln, danach öffnet sich die Vorschau neu. */
    private static function umbau_ersatz_html(array $weg, int $id, string $c, int $limit = 0, string $kontext = ''): string {
        $land = self::land(''); $d = self::uebersicht_daten($land, false); $trotz = (array) get_option('sab_trotz', []); $namen = self::produkte();
        $fund = self::fundstellen(array_values(array_unique($weg)) ?: ['XXXXXXXXXX']);
        $kontext = $kontext !== '' ? $kontext : ($id > 0 ? 'einzel' : 'gesamt');
        $karten = []; $uebrig = 0; $lq = self::lernen_quote(); $ohneVorschlag = [];
        foreach ($weg as $alt) {
            $f = $fund[$alt] ?? []; if (!$f && $kontext === 'lauf') { continue; }   // steht in keinem Artikel mehr (schon getauscht): nichts zu tun
            if ($limit > 0 && count($karten) >= $limit) { $uebrig++; continue; }
            $name = ($namen[$alt] ?? '') ?: ($c !== '' ? self::kontext_titel($c, $alt) : '');
            if ($name === '') { $name = self::anzeige_name((string) $alt, (array) $d, $fund); }
            $xalt = (array) ($d['items'][$alt] ?? []);
            $bl = !isset($d['items'][$alt]) || (empty($xalt['bilder']) && !is_file(self::bild_pfad($land, (string) $alt, 0)));
            $z = ['alt' => (string) $alt, 'grund' => 'Amazon kennt dieses Produkt nicht (mehr). Es kann nicht umgebaut werden und hat bei Besuchern kein Bild.', 'seiten' => count($f), 'n' => (int) array_sum(array_column($f, 'n')), 'name' => (string) $name, 'dr' => 100, 'prio' => 100 * max(1, count($f)), 'f' => $f, 'bl' => $bl];
            $karten[] = self::austausch_karte_html($z, (array) $d, $trotz, $namen, $land, $fund, $lq, $ohneVorschlag);
        }
        if (!$karten) { return ''; }
        $h = '<div class="notice notice-warning inline"><p><strong>Produkte, die Amazon nicht mehr kennt: Ersatz wählen</strong> (dieselben Karten wie im Reiter „Austausch“, mit Vorschlägen, eigener ASIN und Suche bei Amazon). Getauscht wird in allen Artikeln, danach läuft die Prüfung neu.</p></div>'
            . self::austausch_skript((array) $d, $trotz, $namen, $land)
            . '<form method="post">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="umbau"><input type="hidden" name="sab_form" value="austausch_viele">'
            . ($kontext === 'einzel' ? '<input type="hidden" name="sab_vorschau" value="' . (int) $id . '">' : ($kontext === 'gesamt' ? '<input type="hidden" name="sab_alle_vorschau" value="1"><input type="hidden" name="sab_ab" value="' . (int) self::$vorschau_ab . '">' : ''))
            . implode('', $karten)
            . ($uebrig ? '<p class="description">… und ' . $uebrig . ' weitere Produkte (erst diese erledigen, dann „Alle problemlosen umbauen“ erneut starten).</p>' : '')
            . '<p><button class="button button-primary button-hero">Auswahl tauschen (jeweils mit Sicherung)</button> <span class="description">Bis zu 10 pro Klick. Oder an einer Karte „Jetzt tauschen“.</span></p></form>';
        return $h;
    }

    /** Stylesheets und Körperklassen der echten Webseite (von der Startseite gelesen, 6 Stunden gemerkt), damit Vorschauen wie bei Besuchern aussehen. */
    private static function theme_kopf(): array {
        $c = get_transient('sab_theme_kopf');
        if (is_array($c)) { return $c; }
        $kopf = ''; $klasse = '';
        $r = wp_remote_get(home_url('/'), ['timeout' => 8]);
        if (!is_wp_error($r) && wp_remote_retrieve_response_code($r) === 200) {
            $html = (string) wp_remote_retrieve_body($r);
            $head = preg_match('#<head\b.*?</head>#is', $html, $m) ? $m[0] : '';
            if (preg_match_all('#<link\b[^>]*rel=["\']stylesheet["\'][^>]*>#i', $head, $m)) { $kopf .= implode("\n", $m[0]); }
            if (preg_match_all('#<style\b[^>]*>.*?</style>#is', $head, $m)) { $kopf .= implode("\n", $m[0]); }
            if (preg_match('#<body\b[^>]*class=["\']([^"\']*)#i', $html, $m)) { $klasse = trim(str_replace(['home', 'blog'], '', $m[1])) . ' single single-post'; }
        }
        $out = ['kopf' => $kopf, 'klasse' => $klasse];
        set_transient('sab_theme_kopf', $out, $kopf === '' ? MINUTE_IN_SECONDS : 6 * HOUR_IN_SECONDS);
        return $out;
    }

    /** Zeigt HTML in einem eigenen Rahmen mit dem Aussehen der Webseite; das Admin-Aussehen kann so nichts verzerren (z. B. riesige Symbole). */
    private static function vorschau_rahmen(string $inhalt): string {
        $t = self::theme_kopf();
        $warn = $t['kopf'] === '' ? '<p style="margin:0 0 4px;color:#a00;font-size:12px"><strong>Achtung:</strong> Das Aussehen der Webseite konnte nicht geladen werden (Startseite nicht abrufbar). Größen und Layout in dieser Vorschau sind deshalb nicht verlässlich.</p>' : '';
        $css = get_option('sab_karten_css') !== '0' ? '<style>' . self::KARTEN_CSS . '</style>' : '';
        $doc = '<!doctype html><html><head><meta charset="utf-8"><base href="' . esc_url(home_url('/')) . '" target="_blank">' . $t['kopf'] . $css . '<style>body{margin:0;padding:12px;background:#fff}.sab-chg{outline:2px solid #d63638;outline-offset:2px;background:rgba(214,54,56,.08);cursor:help}.sab-chg-b{display:inline-block;max-width:100%}.sab-chg-i{display:inline}</style></head><body class="' . esc_attr($t['klasse']) . '"><div class="site-content"><div class="inside-article"><div class="entry-content">' . preg_replace('#<script\b.*?</script>#is', '', $inhalt) . '</div></div></div></body></html>';
        return $warn . '<iframe class="sab-umb-rahmen" sandbox="allow-same-origin" loading="lazy" style="width:100%;height:320px;border:1px solid #dcdcde;background:#fff" srcdoc="' . esc_attr($doc) . '" onload="try{this.style.height=Math.min(900,this.contentDocument.documentElement.scrollHeight+10)+\'px\'}catch(e){}"></iframe>';
    }

    /** Rot markiert, was der Umbau ändert: im Vorher die alten Boxen und Links, im Nachher die neuen Plugin-Karten (nur Vorschau, mit Erklärung beim Darüberfahren). */
    private static function aenderungen_markieren(string $vorher, string $nachher): array {
        $mark = function (string $t, string $re, string $titel, string $art, string $gegen): string {
            return preg_replace_callback($re, function ($m) use ($titel, $art, $gegen) {
                if (strpos($gegen, $m[0]) !== false) { return $m[0]; }   // kommt auch auf der anderen Seite unverändert vor: keine Änderung
                $t = $titel;
                if (preg_match('/asin="([A-Za-z0-9]{10})"/', $m[0], $a) || preg_match('/\b(?:box|link|fields)=["\']?([A-Za-z0-9]{10})/', $m[0], $a) || preg_match('#/(?:dp|gp/product)/([A-Za-z0-9]{10})#', $m[0], $a)) { $t .= ' (Produkt ' . strtoupper($a[1]) . ')'; }
                return '<span class="sab-chg sab-chg-' . $art . '" title="' . esc_attr($t) . '">' . $m[0] . '</span>';
            }, $t);
        };
        $v = $mark($vorher, '#\[(aawp|amazon)\b[^\]]*\](?:(?:(?!\[(?:aawp|amazon)\b).)*?\[/\1\])?#is', 'Wird ersetzt: alte AAWP-/Amazon-Box, danach Plugin-Karte', 'b', $nachher);
        $v = $mark($v, '#<a\b[^>]*href=["\'][^"\']*(?:amazon\.|amzn\.)[^"\']*["\'][^>]*>.*?</a>#is', 'Wird ersetzt: fester Amazon-Link, danach Plugin-Link mit Partner-ID', 'i', $nachher);
        $n = $mark($nachher, '#\[amz_(produkt|bild|link|ebay)\b[^\]]*\](?:(?:(?!\[amz_).)*?\[/amz_\1\])?#is', 'Neu: Plugin-Karte oder -Link (Daten, Bild und Partner-ID kommen aus dem Plugin)', 'b', $vorher);
        return [$v, $n];
    }

    /** Einzelvorschau; ein Fehler im Artikel erscheint als Hinweis statt als kritischer Fehler der ganzen Seite. */
    private static function umbau_vorschau(int $id): string {
        try { return self::umbau_vorschau_roh($id); }
        catch (\Throwable $e) { return '<div class="notice notice-error inline"><p><strong>Die Vorschau dieses Artikels ist abgebrochen:</strong> ' . esc_html($e->getMessage()) . ' (' . esc_html(basename($e->getFile())) . ':' . (int) $e->getLine() . '). Der Artikel wurde nicht verändert.</p></div>'; }
    }

    private static function umbau_vorschau_roh(int $id): string {
        $post = get_post($id);
        if (!$post) { return '<p>Artikel nicht gefunden.</p>'; }
        $c = (string) $post->post_content;
        self::$umbau_abw = []; self::$umbau_info = []; self::$umbau_weg = []; self::$umbau_keys = []; self::$kurz_offen = 0; self::$neue_namen = [];
        self::$skip_laender = [];
        [$neu, $n, $sk] = self::umbau_text($c);   // der ganze Artikel auf einmal, wie beim echten Umbau (Tabellenspalten und Nachbarn sind sichtbar)
        $h = '<h3>Vorschau für „' . esc_html($post->post_title) . '“</h3><p class="description">Es wird nichts gespeichert. Gezeigt wird das Ergebnis für den ganzen Artikel, so wie der Umbau ihn schreiben würde.</p>';
        if (self::$skip_laender) { $h .= '<div class="notice notice-error inline"><p><strong>Keine Partner-ID für: ' . esc_html(implode(', ', array_keys(self::$skip_laender))) . '.</strong> Links zu diesen Amazon-Ländern werden deshalb nicht umgebaut. Bitte unter Einstellungen die Partner-ID dieses Shops eintragen.</p></div>'; }
        $knopf = '<form method="post" style="margin:10px 0">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="umbau"><input type="hidden" name="sab_form" value="umbau_einzeln"><input type="hidden" name="sab_id" value="' . (int) $id . '"><button class="button button-primary button-hero">Jetzt so umbauen</button> <span class="description">Der Artikel wird vorher gesichert, Rückgängig geht in der Liste.</span></form>';
        $h .= $knopf;
        if (self::$umbau_info) { $h .= '<div class="notice notice-info inline"><p><strong>Das Bild bestimmt das Produkt, daher angepasst:</strong></p><ul>' . implode('', array_map(fn($x) => '<li>' . esc_html($x) . self::ort_links($x, $id) . self::ok_knopf($x, $id) . '</li>', array_unique(self::$umbau_info))) . '</ul></div>'; }
        if (self::$umbau_abw) { $h .= '<div class="notice notice-warning inline"><p><strong>Wird bewusst nicht umgebaut (bitte von Hand klären):</strong></p><ul>' . implode('', array_map(fn($x) => '<li>' . esc_html($x) . self::ort_links($x, $id) . self::ok_knopf($x, $id) . '</li>', array_unique(self::$umbau_abw))) . '</ul></div>'; }
        $h .= self::kurzlink_liste_html($id, '<input type="hidden" name="sab_vorschau" value="' . $id . '">');
        if (self::$umbau_weg) { $h .= self::umbau_ersatz_html(array_keys(self::$umbau_weg), $id, $c, 0, 'einzel'); }
        if (self::$kurz_offen) { $h .= '<p><strong>' . (int) self::$kurz_offen . ' Kurzlinks wurden noch nicht aufgelöst</strong> (Abrufgrenze), bitte die Vorschau noch einmal öffnen.</p>'; }
        // Vorher / Nachher nebeneinander: der Artikel wird an den Trennlinien in Abschnitte geteilt, jeder Abschnitt einzeln umgebaut und gezeigt, wenn er sich ändert
        // Karten brauchen Produktdaten: fehlen sie im Zwischenspeicher, würde die Karte leer bleiben. Daher jetzt (höchstens 20 Abrufe) holen.
        $vl = self::land(''); $geholt = 0; $unbrauchbar = [];
        if (preg_match_all('/\[amz_(?:produkt|bild|link)\b[^\]]*\basin="([A-Za-z0-9]{10})"/', $neu, $mm)) {
            foreach (array_unique(array_map([__CLASS__, 'asin'], $mm[1])) as $pa) {
                if (!get_transient("sab_{$vl}_{$pa}")) { if ($geholt >= 20) { continue; } $geholt++; }
                if (get_option('sab_weg_umbauen', '1') === '1' && self::asin_weg($pa, $vl)) { continue; }
                if (!self::hat_bild(self::produkt($pa, $vl, false, true))) { $unbrauchbar[] = $pa; }
            }
        }
        if (($pt = self::platzhalter_im_tag($neu)) !== '' && self::platzhalter_im_tag($c) === '') { $h .= '<div class="notice notice-error inline"><p><strong>ACHTUNG: Platzhalter in einem HTML-Attribut, so würde kein Bild erscheinen. Der Umbau wird für diesen Artikel verweigert:</strong> <code>' . esc_html($pt) . '</code></p></div>'; }
        if ($unbrauchbar) { $h .= '<div class="notice notice-error inline"><p><strong>ACHTUNG, kein Bild bei Amazon: Dieser Artikel wird NICHT umgebaut, solange diese Produkte darin neu entstehen würden:</strong> ' . esc_html(implode(', ', $unbrauchbar)) . '</p></div>'; }
        $abschnitte = preg_split('#(?=<hr\b)#i', $c); $zeilen = []; $i = 0;
        foreach ($abschnitte as $ab) {
            [$nab] = self::umbau_text($ab);
            if ($nab === $ab) { continue; }
            $i++;
            // nur der geänderte Bereich (gemeinsamer Anfang und gemeinsames Ende werden weggelassen), sonst sieht man bei langen Abschnitten nichts
            $la = strlen($ab); $lb = strlen($nab); $pre = 0; $maxp = min($la, $lb);
            while ($pre < $maxp && $ab[$pre] === $nab[$pre]) { $pre++; }
            $suf = 0; $maxs = min($la, $lb) - $pre;
            while ($suf < $maxs && $ab[$la - 1 - $suf] === $nab[$lb - 1 - $suf]) { $suf++; }
            $von = max(0, $pre - 80);
            $zeig = function (string $t, int $len) use ($von, $suf): string { $ende = $len - $suf + 80; return ($von > 0 ? '… ' : '') . substr($t, $von, min(8000, max(0, $ende - $von))) . ($len - $suf + 80 < $len ? ' …' : ''); };
            $kurz = fn(string $t, int $len) => '<code style="word-break:break-all;white-space:pre-wrap;font-size:11px;display:block;max-height:420px;overflow:auto">' . esc_html($zeig($t, $len)) . '</code>';
            $titelz = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($ab))), 0, 60) ?: 'Abschnitt ' . $i;
            // grafische Ansicht: beide Fassungen so gezeigt, wie Besucher sie sehen würden (höchstens 12 Abschnitte, wegen der Ladezeit)
            [$abm, $nabm] = self::aenderungen_markieren($ab, $nab);
            $bild = fn(string $t) => self::vorschau_rahmen(do_shortcode(wpautop($t)));
            $grafisch = $i <= 12;
            $code = '<details style="margin-top:6px"><summary style="cursor:pointer;color:#2271b1">HTML-Code zeigen</summary>';
            $zeilen[] = '<tr><td colspan="2" style="background:#f6f7f7"><strong>' . esc_html($titelz) . '</strong></td></tr><tr><td style="width:50%;vertical-align:top">' . ($grafisch ? $bild($abm) . $code . $kurz($ab, $la) . '</details>' : $kurz($ab, $la)) . '</td><td style="width:50%;vertical-align:top">' . ($grafisch ? $bild($nabm) . $code . $kurz($nab, $lb) . '</details>' : $kurz($nab, $lb)) . '</td></tr>';
        }
        $h .= '' . (get_option('sab_karten_css') !== '0' ? '<style>' . self::KARTEN_CSS . '</style>' : '') . '<style>.sab-umb-bild img{max-width:100%;height:auto}</style><p><strong>Abschnitte, die sich ändern (' . count($zeilen) . '):</strong> links vorher, rechts nachher, so wie Besucher es sehen (bei den ersten 12 Abschnitten als Bild im Aussehen der Webseite, den HTML-Code gibt es per Klick). Die Vorher-Seite zeigt, was jetzt im Artikel steht.</p><table class="widefat striped"><thead><tr><th>Vorher</th><th>Nachher</th></tr></thead><tbody>' . ($zeilen ? implode('', $zeilen) : '<tr><td colspan="2">Nichts.</td></tr>') . '</tbody></table>';
        $rest = [];
        if (preg_match_all('#<a\b[^>]*>.*?</a>#is', preg_replace('#\[amz_(bild|produkt|link)\b[^\]]*\].*?\[/amz_\1\]#is', '', $neu), $m)) {
            foreach ($m[0] as $a) { if (preg_match('#href=(["\'])(.*?)\1#is', $a, $hh) && preg_match('#amazon\.|amzn\.|a\.co/|link\.amazon#i', $hh[2])) { $rest[] = '<li><code style="word-break:break-all">' . esc_html(mb_substr($a, 0, 300)) . '</code></li>'; } }
        }
        $okl = (array) get_option('sab_umbau_ok', []);
        if ($okl) {
            $h .= '<details style="margin:8px 0"><summary>Als gewollt bestätigte Abweichungen (' . count($okl) . ')</summary><ul>';
            foreach (array_slice($okl, -30, null, true) as $ix => $k) {
                $h .= '<li><code>' . esc_html($k) . '</code> <form method="post" style="display:inline">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="umbau"><input type="hidden" name="sab_form" value="umbau_ok_weg"><input type="hidden" name="sab_key" value="' . esc_attr($k) . '"><input type="hidden" name="sab_vorschau" value="' . $id . '"><button class="button button-small">Wieder prüfen</button></form></li>';
            }
            $h .= '</ul></details>';
        }
        return $h . $knopf . '<p><strong>Amazon-Links, die unverändert bleiben (' . count($rest) . '):</strong></p>' . ($rest ? '<ul>' . implode('', $rest) . '</ul><p class="description">Das sind Links ohne erkennbares Produkt oder ohne Partner-ID für das Land, Kurzlinks, die sich nicht eindeutig zuordnen lassen, oder Produkte, die Amazon nicht mehr kennt.</p>' : '<p>Keine.</p>');
    }

    /** Alles zurück: Originaltext aus der Sicherung wiederherstellen. */
    private static function umbau_zurueck(): string {
        global $wpdb;
        if (!isset($wpdb)) { return 'Keine Datenbank.'; }
        $ids = $wpdb->get_col("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_sab_umbau_backup'");
        foreach ((array) $ids as $id) {
            $alt = get_post_meta($id, '_sab_umbau_backup', true);
            if ($alt !== '') { wp_update_post(['ID' => (int) $id, 'post_content' => wp_slash($alt)]); }
            delete_post_meta($id, '_sab_umbau_backup');
        }
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key='_sab_umbau_geprueft'");
        delete_transient('sab_alle_asins');
        return count((array) $ids) . ' Artikel auf den Zustand vor dem Umbau zurückgesetzt.';
    }

    // ---------- Widgets in der Seitenleiste umbauen (Umbau-Reiter) ----------

    const WIDGET_OPT = ['widget_block' => ['block', 'content'], 'widget_text' => ['text', 'text'], 'widget_custom_html' => ['custom_html', 'content']];
    private static $wi_vorschau = [];
    private static $wi_meldung = [];

    /** Widgets mit Amazon-/AAWP-Links. @return array<string,array> Widget-ID => Angaben */
    private static function widgets_finden(): array {
        $out = []; $sb = (array) get_option('sidebars_widgets', []);
        foreach (self::WIDGET_OPT as $opt => [$typ, $feld]) {
            foreach ((array) get_option($opt, []) as $nr => $w) {
                if (!is_int($nr) || !is_array($w)) { continue; }
                $c = (string) ($w[$feld] ?? '');
                $id = $typ . '-' . $nr;
                // nur echte Amazon-Adressen oder AAWP zählen (ein eBay-Link mit „amazon“ im Suchwort oder [amz_ebay] ist kein Amazon-Link)
                $re_alt = '#https?://(?:[a-z0-9-]+\.)*(?:amazon\.[a-z.]{2,6}|amzn\.(?:to|eu)|a\.co)(?:[/?\#"\'\s]|$)|aawp|\[amazon\b#i';
                $hat_alt = preg_match($re_alt, preg_replace('#\[amz_widget\b[^\]]*\]#', '', $c), $tm) === 1;
                if (!$hat_alt && !preg_match('#\[amz_(?:widget|karten?|bild|link|produkt)\b#', $c)) { continue; }
                $leiste = '';
                foreach ($sb as $sn => $ids) { if (is_array($ids) && in_array($id, $ids, true)) { $leiste = (string) $sn; break; } }
                $asins = [];
                foreach (['#(?:dp|gp/product)/([A-Za-z0-9]{10})#', '#aawp-product-asin=["\']([A-Za-z0-9]{10})#', '#\[(?:aawp|amazon)\b[^\]]*\b(?:box|fields|link)=["\']([A-Za-z0-9]{10})#i', '#[?&]asin=([A-Za-z0-9]{10})#i', '#\[amz_[a-z]+\b[^\]]*\basin=["\']([A-Za-z0-9]{10})#'] as $re) { if (preg_match_all($re, $c, $m)) { foreach ($m[1] as $a_) { $asins[strtoupper($a_)] = 1; } } }
                $lk = [];
                if (preg_match_all('#<a\b[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)</a>#is', $c, $am_, PREG_SET_ORDER)) {
                    foreach ($am_ as $a_) {
                        $u_ = html_entity_decode($a_[2]);
                        if (!preg_match('#^https?://(?:[a-z0-9-]+\.)*(?:amazon\.[a-z.]{2,6}|amzn\.(?:to|eu)|a\.co|link\.amazon)(?:[/?\#]|$)#i', $u_)) { continue; }
                        $uu = self::umbau_url($a_[2]);
                        $lk[] = ['url' => $u_, 'text' => trim(html_entity_decode(strip_tags($a_[3]))), 'asin' => $uu ? (string) $uu[1] : '', 'kurz' => (bool) preg_match('#amzn\.|a\.co/|link\.amazon#i', $u_)];
                    }
                }
                $out[$id] = ['links' => $lk, 'ok' => ((array) get_option('sab_widget_ok', []))[$id] ?? '' , 'id' => $id, 'opt' => $opt, 'nr' => $nr, 'feld' => $feld, 'inhalt' => $c, 'leiste' => $leiste, 'asins' => array_map('strval', array_keys($asins)), 'fertig' => !$hat_alt, 'treffer' => $hat_alt ? trim((string) $tm[0], " \"'\t\r\n#") : '', 'block' => $typ === 'block'];
            }
        }
        return $out;
    }

    /** Vorbelegung für die Buch-Karte aus dem alten Widget-Inhalt (Titel, Text, zweiter Link). */
    /** Eine Seite, auf der die Seitenleiste erscheint (neuester Beitrag; die Startseite hat oft keine). */
    private static function widget_testseite(): string {
        $p = function_exists('get_posts') ? get_posts(['numberposts' => 1, 'post_type' => 'post', 'post_status' => 'publish']) : [];
        return $p ? (string) get_permalink($p[0]) : home_url('/');
    }

    /** Im Frontend (nur für Redakteure): ?sab_widget=block-16 markiert das Widget und scrollt hin; steht es nicht auf der Seite, kommt ein Hinweis. */
    public static function widget_zeige_skript(): void {
        $id = isset($_GET['sab_widget']) ? preg_replace('/[^a-z_0-9-]/', '', (string) wp_unslash($_GET['sab_widget'])) : '';
        if ($id === '' || !function_exists('current_user_can') || !current_user_can('edit_posts')) { return; }
        echo '<script>(function(){var id=' . wp_json_encode($id) . ',e=document.getElementById(id);var b=document.createElement("div");b.style.cssText="position:fixed;left:0;right:0;top:32px;z-index:99999;background:#d63638;color:#fff;padding:8px 16px;font:600 14px sans-serif;text-align:center;cursor:pointer";b.onclick=function(){b.remove()};'
           . 'if(e){e.style.cssText+=";outline:4px solid #d63638;outline-offset:4px;background:#fff3a3";e.scrollIntoView({block:"center"});b.textContent="Hier ist das Widget "+id+" (rot umrandet). Zum Schließen klicken."}else{b.textContent="Das Widget "+id+" steht auf dieser Seite nicht sichtbar. Vielleicht ist die Seitenleiste auf diesem Seitentyp aus: bitte einen anderen Beitrag öffnen."}document.body.appendChild(b)})();</script>';
    }

    private static function widget_felder(array $w): array {
        $c = (string) $w['inhalt'];
        $titel = ''; $text = ''; $alt_u = ''; $alt_t = '';
        if (preg_match('#<(?:h[1-6]|strong|b)\b[^>]*>(.*?)</(?:h[1-6]|strong|b)>#is', $c, $m)) { $titel = trim(html_entity_decode(strip_tags($m[1]))); }
        if (preg_match_all('#<p\b[^>]*>(.*?)</p>#is', $c, $pm)) { foreach ($pm[1] as $p_) { $t = trim(html_entity_decode(strip_tags($p_))); if (mb_strlen($t) >= 25 && $t !== $titel) { $text = $t; break; } } }
        if (preg_match_all('#<a\b[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)</a>#is', $c, $am, PREG_SET_ORDER)) {
            foreach ($am as $a_) { if (!preg_match('#amazon\.|amzn\.|aawp|/dp/#i', $a_[2]) && ($t = trim(html_entity_decode(strip_tags($a_[3])))) !== '' && mb_strlen($t) <= 60) { $alt_u = html_entity_decode($a_[2]); $alt_t = $t; break; } }
        }
        return ['asin' => (string) ($w['asins'][0] ?? ''), 'marke' => 'Buchtipp', 'titel' => mb_substr(self::textrest_sauber($titel), 0, 90), 'text' => mb_substr(self::textrest_sauber($text), 0, 220), 'knopf' => '', 'alt_text' => $alt_t, 'alt_url' => $alt_u];
    }

    private static function widget_code(array $f): string {
        $q = fn(string $v): string => str_replace(['"', '[', ']'], ['″', '(', ')'], trim($v));
        $a = ['typ' => 'buch', 'asin' => self::asin($f['asin'] ?? '')];
        foreach (['marke', 'titel', 'text', 'knopf', 'alt_text'] as $k) { if (self::textrest_sauber((string) ($f[$k] ?? '')) !== '') { $a[$k] = $q(self::textrest_sauber((string) $f[$k])); } }
        if (esc_url((string) ($f['alt_url'] ?? '')) !== '') { $a['alt_url'] = esc_url_raw((string) $f['alt_url']); }
        $o = '[amz_widget'; foreach ($a as $k => $v) { $o .= ' ' . $k . '="' . $v . '"'; } return $o . ']';
    }

    private static function widget_speichern(string $id, string $inhalt): bool {
        foreach (self::WIDGET_OPT as $opt => [$typ, $feld]) {
            if (strpos($id, $typ . '-') !== 0) { continue; }
            $nr = (int) substr($id, strlen($typ) + 1); $o = (array) get_option($opt, []);
            if (!isset($o[$nr]) || !is_array($o[$nr])) { return false; }
            $o[$nr][$feld] = $inhalt; update_option($opt, $o); return true;
        }
        return false;
    }

    /** Formularverarbeitung: vorschau (nichts gespeichert), speichern (mit Sicherung), zurueck. */
    private static function widget_umbau_lauf(): string {
        $m = self::widget_umbau_innen();
        $id = preg_replace('/[^a-z_0-9-]/', '', (string) wp_unslash($_POST['sab_wid'] ?? ''));
        if ($id !== '') { self::$wi_meldung[$id] = $m; }
        return $m;
    }

    private static function widget_umbau_innen(): string {
        $id = preg_replace('/[^a-z_0-9-]/', '', (string) wp_unslash($_POST['sab_wid'] ?? '')); $akt = (string) ($_POST['sab_wakt'] ?? '');
        $alle = self::widgets_finden(); $bk = (array) get_option('sab_widget_backup', []);
        if ($akt === 'ok' || $akt === 'ok_weg') {
            $okl = (array) get_option('sab_widget_ok', []);
            if ($akt === 'ok' && isset($alle[$id])) { $okl[$id] = md5((string) $alle[$id]['inhalt']); $m = 'Widget ' . $id . ' bleibt, wie es ist (wird nicht mehr als offen gezählt, solange sich der Inhalt nicht ändert).'; }
            else { unset($okl[$id]); $m = 'Widget ' . $id . ' wird wieder als offen geführt.'; }
            update_option('sab_widget_ok', $okl, false); self::$wi_meldung[$id] = $m; return $m;
        }
        if ($akt === 'zurueck') {
            if (!isset($bk[$id])) { return 'Keine Sicherung für dieses Widget.'; }
            $ok = self::widget_speichern($id, (string) $bk[$id]['vorher']); unset($bk[$id]); update_option('sab_widget_backup', $bk, false);
            return $ok ? 'Widget ' . $id . ' wieder auf den Zustand vorher gesetzt.' : 'Widget nicht gefunden.';
        }
        if (!isset($alle[$id])) { return 'Widget nicht gefunden.'; }
        $f = []; foreach (['asin', 'marke', 'titel', 'text', 'knopf', 'alt_text', 'alt_url'] as $k) { $f[$k] = sanitize_text_field(wp_unslash($_POST['sab_w_' . $k] ?? '')); }
        if (strlen(self::asin($f['asin'])) !== 10) { self::$wi_vorschau[$id] = $f; self::$wi_meldung[$id] = 'Es fehlt eine gültige ASIN (10 Zeichen). Im Widget steht kein Link mit ASIN, vermutlich ein Kurzlink oder Bild. Bitte die ASIN des Produkts von Hand eintragen.'; return self::$wi_meldung[$id]; }
        self::$wi_vorschau[$id] = $f;
        if ($akt !== 'speichern') { self::$wi_meldung[$id] = 'Vorschau unten. Es wurde nichts gespeichert.'; return self::$wi_meldung[$id]; }
        if (!self::neues_produkt_vorladen(self::asin($f['asin']))) { return 'Amazon liefert zu ' . $f['asin'] . ' gerade kein Bild oder keine Daten. Nicht umgebaut.'; }
        $code = self::widget_code($f); if ($alle[$id]['block']) { $code = "<!-- wp:shortcode -->\n" . $code . "\n<!-- /wp:shortcode -->"; }
        $bk[$id] = ['ts' => time(), 'vorher' => $alle[$id]['inhalt']]; update_option('sab_widget_backup', $bk, false);
        if (!self::widget_speichern($id, $code)) { return 'Widget nicht gespeichert.'; }
        delete_transient('sab_alle_asins'); unset(self::$wi_vorschau[$id]);
        return 'Widget ' . $id . ' umgebaut (Sicherung gespeichert, Rückgängig unten). Bitte im Frontend ansehen.';
    }

    private static function umbau_widgets_html(): string {
        $ws = self::widgets_finden(); if (!$ws) { return ''; }
        $bk = (array) get_option('sab_widget_backup', []);
        $offen = array_filter($ws, fn($w) => !$w['fertig'] && !($w['ok'] !== '' && $w['ok'] === md5((string) $w['inhalt'])));
        $h = '<h3 style="margin-top:1.5em">Widgets in der Seitenleiste (' . count($offen) . ' mit alten Amazon-Links)</h3><p class="description" style="max-width:820px">Hier stehen Widgets (Block-, Text- und HTML-Widgets) mit Amazon- oder AAWP-Links. Die Buch-Karte unten ist vorbelegt; Felder ändern, „Vorschau“ ansehen und erst dann „Umbauen“. Vor dem Speichern wird der alte Inhalt gesichert, „Rückgängig“ stellt ihn wieder her.</p>';
        foreach ($ws as $id => $w) {
            $f = isset(self::$wi_vorschau[$id]) ? self::$wi_vorschau[$id] : self::widget_felder($w);
            $ign = $w['ok'] !== '' && $w['ok'] === md5((string) $w['inhalt']);
            $h .= '<div id="sab-w-' . esc_attr($id) . '" style="border:1px solid #c3c4c7;border-radius:8px;background:#fff;padding:10px 14px;margin:0 0 12px;max-width:1000px' . (isset(self::$wi_meldung[$id]) ? ';border-color:#2271b1;border-width:2px' : '') . '">' . (isset(self::$wi_meldung[$id]) ? '<p style="margin:0 0 8px;padding:6px 10px;background:#f0f6fc;border-left:4px solid #2271b1;font-weight:600">' . esc_html(self::$wi_meldung[$id]) . '</p>' : '') . '<strong>' . esc_html($id) . '</strong>' . ($w['leiste'] !== '' ? ' · Bereich <code>' . esc_html($w['leiste']) . '</code>' : '') . ' · ASIN: ' . ($w['asins'] ? esc_html(implode(', ', $w['asins'])) : '<em>keine gefunden</em>')
                . ($w['fertig'] ? ' · <span style="color:#1a7f37;font-weight:700">schon umgebaut</span>' : ($ign ? ' · <span style="color:#50575e;font-weight:700">bleibt so (von dir bestätigt)</span>' : ' · <span style="color:#a00;font-weight:700">alte Links</span>'))
                . ' · <a href="' . esc_url(admin_url('widgets.php')) . '" target="_blank" rel="noopener">Widgets bearbeiten ↗</a> · <a href="' . esc_url(add_query_arg('sab_widget', $id, self::widget_testseite())) . '" target="_blank" rel="noopener">Im Frontend ansehen (Widget wird markiert) ↗</a>' . ($w['treffer'] !== '' ? '<br><small>Erkannt wegen: <code>' . esc_html(mb_substr((string) $w['treffer'], 0, 80)) . '</code></small>' : '');
            if (!$w['fertig'] && $w['links']) {
                $h .= '<ul style="margin:6px 0 0 16px;list-style:disc">';
                foreach ($w['links'] as $lk_) { $h .= '<li><a href="' . esc_url($lk_['url']) . '" target="_blank" rel="noopener">' . esc_html(mb_substr($lk_['url'], 0, 90)) . '</a> „' . esc_html(mb_substr($lk_['text'], 0, 40)) . '“ · ' . ($lk_['asin'] !== '' ? 'ASIN <code>' . esc_html($lk_['asin']) . '</code>' : ($lk_['kurz'] ? '<span style="color:#b36b00">Kurzlink: ASIN nicht erkennbar, bitte von Hand eintragen</span>' : '<span style="color:#b36b00">keine ASIN erkennbar</span>')) . '</li>'; }
                $h .= '</ul>';
            }
            if (!$w['fertig']) {
                $h .= '<form method="post" action="' . esc_url(admin_url('admin.php?page=sab-umbau#sab-w-' . $id)) . '" style="margin-top:6px">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="umbau"><input type="hidden" name="sab_form" value="widget_umbau"><input type="hidden" name="sab_wid" value="' . esc_attr($id) . '"><button class="button" name="sab_wakt" value="' . ($ign ? 'ok_weg' : 'ok') . '">' . ($ign ? 'wieder als offen führen' : 'Ist okay so (nicht umbauen)') . '</button></form>';
                $h .= '<details style="margin-top:6px"><summary>Alter Inhalt</summary><pre style="white-space:pre-wrap;max-height:160px;overflow:auto;background:#f6f7f7;padding:6px;font-size:12px">' . esc_html(mb_substr((string) $w['inhalt'], 0, 1500)) . '</pre></details>'
                    . '<form method="post" action="' . esc_url(admin_url('admin.php?page=sab-umbau#sab-w-' . $id)) . '" style="margin-top:8px">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="umbau"><input type="hidden" name="sab_form" value="widget_umbau"><input type="hidden" name="sab_wid" value="' . esc_attr($id) . '">'
                    . '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px">';
                foreach (['asin' => 'ASIN des Buchs', 'marke' => 'Etikett (z. B. Buchtipp)', 'titel' => 'Titel', 'text' => 'Kurztext', 'knopf' => 'Knopftext (leer = Standard)', 'alt_text' => 'zweiter Link: Text (z. B. Thalia)', 'alt_url' => 'zweiter Link: Adresse'] as $k => $l) {
                    $h .= '<label>' . esc_html($l) . '<br><input type="text" name="sab_w_' . esc_attr($k) . '" value="' . esc_attr((string) ($f[$k] ?? '')) . '" style="width:100%"></label>';
                }
                $h .= '</div><p><button class="button" name="sab_wakt" value="vorschau">Vorschau</button> <button class="button button-primary" name="sab_wakt" value="speichern" onclick="return confirm(\'Dieses Widget jetzt umbauen? Der alte Inhalt wird gesichert.\')">Umbauen</button></p></form>';
                if (isset(self::$wi_vorschau[$id]) && strlen(self::asin((string) ($f['asin'] ?? ''))) === 10) { $h .= '<div style="background:#f1f3ee;padding:14px;border-radius:8px"><div style="width:300px">' . do_shortcode(self::widget_code($f)) . '</div></div>'; }
            }
            if ($w['fertig']) { $h .= self::ab_widget_form_html((string) $id, $w); }
            if (isset($bk[$id])) { $h .= '<form method="post" action="' . esc_url(admin_url('admin.php?page=sab-umbau#sab-w-' . $id)) . '" style="margin-top:8px">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="umbau"><input type="hidden" name="sab_form" value="widget_umbau"><input type="hidden" name="sab_wid" value="' . esc_attr($id) . '"><button class="button" name="sab_wakt" value="zurueck">Rückgängig (alter Inhalt von ' . esc_html(wp_date('d.m.Y H:i', (int) $bk[$id]['ts'])) . ')</button></form>'; }
            $h .= '</div>';
        }
        return $h;
    }

    // ---------- Sichtbare Shortcode-Reste finden (z. B. image_width="300") im Widget- und Artikeltext) ----------

    private static $textreste = null;

    /** Alte AAWP-Reste im Text: „(amazon fields=… image_width=…)“ und lose Attribute (auch mit typografischen Anführungszeichen). */
    private static function textrest_entfernen(string $t): string {
        $t = preg_replace('/\(\s*(?:amazon|aawp)\b[^)]*\)/iu', ' ', $t);
        $t = preg_replace('/\b(?:fields|value|box|tracking_id|image_(?:size|width|height))\s*=\s*["“”″\']?[^\s"“”″\')\]]*["“”″\']?\)?/u', ' ', (string) $t);
        return (string) preg_replace('/ {2,}/u', ' ', (string) $t);
    }

    /** Für einen Kurztext/Titel: Reste entfernen, Leerraum glätten. */
    public static function textrest_sauber(string $t): string { return trim(preg_replace('/\s+/u', ' ', self::textrest_entfernen($t))); }

    /** Bereinigt Widget-/Artikeltext: nur Textstücke (nicht Tags, nicht Shortcodes) und die Attribute text/titel/alt_text von [amz_widget]. */
    public static function textrest_bereinigen(string $c): string {
        $teile = preg_split('/(<[^>]*>|\[[^\[\]]*\])/u', $c, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($teile as $i => $t) {
            if ($t === '' || $t[0] === '<') { continue; }
            if ($t[0] === '[') {
                if (strpos($t, '[amz_widget') === 0) { $teile[$i] = preg_replace_callback('/\b(text|titel|alt_text)="([^"]*)"/u', fn($m) => $m[1] . '="' . self::textrest_sauber($m[2]) . '"', $t); }
                continue;
            }
            $teile[$i] = self::textrest_entfernen($t);
        }
        return implode('', $teile);
    }

    /** Text ohne Tags und ohne vollständige [Klammern]: was dann noch wie Shortcode-Attribute aussieht, wird den Besuchern vermutlich als Text gezeigt. @return array<int,array{wo:string,treffer:string,link:string,edit:string}> */
    public static function textreste_finden(): array {
        $pruefen = function (string $c): string {
            if (self::textrest_bereinigen($c) === $c) { return ''; }   // nichts, was die Bereinigung ändern würde (auch Reste in Attributen von [amz_widget] zählen)
            return preg_match('/\(\s*(?:amazon|aawp)\b[^)]*\)|\b(?:image_(?:size|width|height)|fields|value|tracking_id)\s*=\s*[^\s<>\[\]]{0,30}/iu', html_entity_decode($c, ENT_QUOTES, 'UTF-8'), $m) ? trim($m[0]) : 'Rest';
        };
        $out = [];
        foreach (self::WIDGET_OPT as $opt => [$typ, $feld]) {
            foreach ((array) get_option($opt, []) as $nr => $w) {
                if (!is_int($nr) || !is_array($w)) { continue; }
                if (($tr = $pruefen((string) ($w[$feld] ?? ''))) !== '') { $id = $typ . '-' . $nr; $out[] = ['typ' => 'widget', 'id' => $id, 'wo' => 'Widget ' . $id, 'treffer' => $tr, 'link' => add_query_arg('sab_widget', $id, self::widget_testseite()), 'edit' => admin_url('widgets.php')]; }
            }
        }
        global $wpdb;
        if (isset($wpdb)) {
            $rows = $wpdb->get_results("SELECT ID, post_title, post_content FROM {$wpdb->posts} WHERE post_status IN ('publish','future') AND post_type IN ('post','page') AND (post_content LIKE '%image\\_width%' OR post_content LIKE '%image\\_size%' OR post_content LIKE '%image\\_height%' OR post_content LIKE '%tracking\\_id%' OR post_content LIKE '%fields=%' OR post_content LIKE '%value=%')", ARRAY_A);
            foreach ((array) $rows as $r) {
                if (($tr = $pruefen((string) $r['post_content'])) !== '') { $out[] = ['typ' => 'post', 'id' => (string) (int) $r['ID'], 'wo' => 'Artikel „' . ((string) $r['post_title'] ?: ('Beitrag ' . $r['ID'])) . '“', 'treffer' => $tr, 'link' => (string) get_permalink((int) $r['ID']), 'edit' => admin_url('post.php?post=' . (int) $r['ID'] . '&action=edit')]; }
            }
        }
        return $out;
    }

    /** Reparieren-Knopf der Textrest-Suche: Reste entfernen, alten Inhalt vorher sichern. */
    private static function textrest_fix(): string {
        $typ = (string) ($_POST['sab_typ'] ?? ''); $id = preg_replace('/[^a-z_0-9-]/', '', (string) wp_unslash($_POST['sab_wid'] ?? ''));
        if ($typ === 'widget') {
            $roh = null;
            foreach (self::WIDGET_OPT as $opt => [$t_, $feld]) { if (strpos($id, $t_ . '-') === 0) { $roh = ((array) (((array) get_option($opt, []))[(int) substr($id, strlen($t_) + 1)] ?? []))[$feld] ?? null; } }
            if ($roh === null) { return 'Widget nicht gefunden.'; }
            $neu = self::textrest_bereinigen((string) $roh);
            if ($neu === $roh) { return 'Nichts zu ändern in ' . $id . '.'; }
            $bk = (array) get_option('sab_widget_backup', []); $bk[$id] = ['ts' => time(), 'vorher' => (string) $roh]; update_option('sab_widget_backup', $bk, false);
            return self::widget_speichern($id, $neu) ? 'Reste in ' . $id . ' entfernt (alter Inhalt gesichert, im Umbau-Reiter beim Widget „Rückgängig“).' : 'Widget nicht gespeichert.';
        }
        $pid = (int) $id; $post = $pid > 0 ? get_post($pid) : null;
        if (!$post || !current_user_can('edit_post', $pid)) { return 'Artikel nicht gefunden.'; }
        $c = (string) $post->post_content; $c2 = self::textrest_bereinigen($c);
        if ($c2 === $c) { return 'Nichts zu ändern.'; }
        $b = (array) get_post_meta($pid, '_sab_stelle_backup', true);
        $b = array_slice(array_merge($b, [['ts' => time(), 'hash' => md5($c2), 'vorher' => $c, 'alt' => 'Textrest', 'neu' => 'Textrest']]), -5);
        update_post_meta($pid, '_sab_stelle_backup', wp_slash($b)); wp_update_post(['ID' => $pid, 'post_content' => wp_slash($c2)]);
        return 'Reste im Artikel entfernt (mit Sicherung, WordPress-Revision vorhanden).';
    }

    private static function umbau_textreste_html(): string {
        $h = '<form method="post" action="' . esc_url(admin_url('admin.php?page=sab-umbau#sab-textreste')) . '" style="margin:10px 0">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="umbau"><input type="hidden" name="sab_form" value="textreste"><button class="button">Nach sichtbaren Shortcode-Resten suchen</button> <span class="description">Sucht in Widgets und Artikeln nach Textstücken wie <code>image_width="300")</code>, die Besucher als Text sehen könnten.</span></form>';
        if (self::$textreste === null) { return $h; }
        $h .= '<div id="sab-textreste" style="max-width:1000px;padding:8px 12px;background:#fff;border:1px solid ' . (self::$textreste ? '#d63638' : '#c3c4c7') . '">';
        if (!self::$textreste) { return $h . '<strong style="color:#1a7f37">Keine sichtbaren Shortcode-Reste gefunden.</strong></div>'; }
        $h .= '<strong>' . count(self::$textreste) . ' Fundstelle(n):</strong><ul style="margin:6px 0 0 16px;list-style:disc">';
        foreach (array_slice(self::$textreste, 0, 60) as $t) { $h .= '<li>' . esc_html($t['wo']) . ': <code>' . esc_html(mb_substr($t['treffer'], 0, 70)) . '</code> <a href="' . esc_url($t['link']) . '" target="_blank" rel="noopener">ansehen ↗</a> · <a href="' . esc_url($t['edit']) . '" target="_blank" rel="noopener">bearbeiten ↗</a> <form method="post" action="' . esc_url(admin_url('admin.php?page=sab-umbau#sab-textreste')) . '" style="display:inline" onsubmit="return confirm(\'Reste in diesem Text entfernen? Der alte Inhalt wird gesichert.\')">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="umbau"><input type="hidden" name="sab_form" value="textrest_fix"><input type="hidden" name="sab_typ" value="' . esc_attr($t['typ']) . '"><input type="hidden" name="sab_wid" value="' . esc_attr($t['id']) . '"><button class="button button-small">Reparieren</button></form></li>'; }
        return $h . '</ul></div>';
    }

    // ---------- Widgets für die Seitenleiste ----------

    const WIDGET_CSS = '.sab-wb,.sab-wt,.sab-wl{box-sizing:border-box;max-width:340px;margin:0 auto 18px;font-family:inherit}.sab-wb{position:relative;background:#fff;border:1px solid #dfe6d8;border-radius:14px;padding:18px 16px 14px;box-shadow:0 2px 10px rgba(40,70,30,.08);text-align:center}.sab-wb .sab-wtag{position:absolute;top:-10px;left:14px;background:#e8873a;color:#fff;font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;padding:3px 10px;border-radius:999px}.sab-wb .sab-wcover{margin:6px auto 12px}.sab-wb .sab-wcover img{display:block;margin:0 auto;max-height:210px;width:auto;max-width:70%;height:auto;border-radius:4px 10px 10px 4px;box-shadow:-4px 4px 0 #cfd9c7,0 6px 14px rgba(0,0,0,.2)}.sab-wb h4{margin:0 0 6px;font-size:17px;line-height:1.25}.sab-wb p{margin:0 0 12px;font-size:14px;line-height:1.45;color:#44543f}.sab-wb a.sab-wbtn{display:block;background:#2f7a3d;border:1px solid #2f7a3d;color:#fff!important;text-decoration:none!important;font-weight:600;padding:9px 12px;border-radius:9px;font-size:14px}.sab-wb a.sab-wbtn:hover{background:#256331;border-color:#256331}.sab-wb a.sab-wbtn:after{content:"";position:absolute;inset:0;border-radius:14px}.sab-wb a.sab-walt{position:relative;z-index:2;display:inline-block;margin-top:9px;font-size:13px;color:#2f7a3d}.sab-wb .sab-wnote{font-size:11px;color:#7b8a76;margin-top:8px}.sab-wt{background:linear-gradient(160deg,#fff7e8,#ffeccb);border-radius:14px;padding:16px;border:1px solid #f1d9a8}.sab-wt b{display:block;font-size:17px;margin-bottom:6px}.sab-wt p{margin:0 0 10px;font-size:14px;line-height:1.45}.sab-wt a{display:inline-block;background:#e8873a;color:#fff!important;text-decoration:none!important;font-weight:700;padding:9px 14px;border-radius:9px;font-size:14px}.sab-wl{background:#fff;border-radius:14px;padding:14px 16px;border:1px solid #dfe6d8}.sab-wl h4{margin:0 0 10px;font-size:16px}.sab-wl ol{margin:0;padding:0;list-style:none;counter-reset:n}.sab-wl li{counter-increment:n;display:flex;gap:10px;align-items:center;padding:8px 0;border-top:1px solid #edf1e9;font-size:14px}.sab-wl li:first-child{border-top:0}.sab-wl li:before{content:counter(n);flex:none;width:24px;height:24px;border-radius:50%;background:#2f7a3d;background:var(--sabc,#2f7a3d);color:#fff;font-weight:700;display:flex;align-items:center;justify-content:center;font-size:13px}.sab-wl li a{color:inherit;text-decoration:none}.sab-tw,.sab-tier,.sab-perch{display:none}@media (hover:hover) and (pointer:fine) and (min-width:1024px) and (prefers-reduced-motion:no-preference){.sab-wb .sab-wcover{position:relative;overflow:hidden;padding:0 14px 20px;margin:6px -14px -8px}.sab-wb .sab-wcover img{transform-origin:bottom left;animation:sabwip 20s ease-in-out infinite}.sab-wb .sab-wcover::after{content:"";position:absolute;top:0;left:-60%;width:30%;height:100%;pointer-events:none;background:linear-gradient(100deg,transparent,rgba(255,255,255,.55),transparent);transform:skewX(-15deg);animation:sabgl 20s ease-in-out infinite}@keyframes sabwip{0%,38%,46%,100%{transform:none}41%{transform:rotate(-4deg) translateY(-3px)}44%{transform:rotate(1.5deg)}}@keyframes sabgl{0%,80%{left:-60%}92%,100%{left:130%}}.sab-wb.sab-m-a .sab-tw,.sab-wb.sab-m-c .sab-tw{display:none!important}.sab-wb.sab-m-b .sab-wcover img,.sab-wb.sab-m-c .sab-wcover img{animation:none}.sab-wb.sab-m-b .sab-wcover::after,.sab-wb.sab-m-c .sab-wcover::after{display:none}.sab-tw{display:block!important;position:relative;left:50%;width:0;height:0;pointer-events:none}.sab-tier{display:block;position:absolute;left:-17px;top:-11px;width:34px;height:23px;pointer-events:none;opacity:0;z-index:2;animation:sabfly 90s linear infinite}.sab-tier svg{display:block;width:34px;height:23px;overflow:visible}.sab-tier .fw{transform-origin:24px 16px;animation:sabfl .12s ease-in-out infinite alternate}@keyframes sabfl{to{transform:scaleY(.3)}}.sab-tier .fd{transform-origin:24px 16px;animation:sabfd 90s linear infinite}.sab-perch{display:block;position:absolute;z-index:1;pointer-events:none;opacity:0;transform-origin:50% 100%;height:30px;top:-26px;width:36px;animation:sabpa 90s linear infinite}.sab-perch svg{display:block;width:100%;height:100%;overflow:visible}.sab-pa{left:77px}.sab-pb{left:-113px;animation-name:sabpb}.sab-p3{width:44px;top:-27px}.sab-p3.sab-pa{left:73px}.sab-p3.sab-pb{left:-117px}.sab-t2,.sab-p2,.sab-t2 .fd{animation-duration:110s}.sab-t3,.sab-p3,.sab-t3 .fd{animation-duration:76s}.sab-wb:hover .sab-tier{animation:sabhov 2.6s linear 1}@keyframes sabfly{0%,19.9%{opacity:0;transform:translate(-150px,-150px) rotate(-50deg)}20%{opacity:1;transform:translate(-150px,-150px) rotate(-50deg)}20.57%{opacity:1;transform:translate(-146px,-154px) rotate(-47deg)}21.14%{opacity:1;transform:translate(-133px,-163px) rotate(-16deg)}21.71%{opacity:1;transform:translate(-109px,-158px) rotate(29deg)}22.29%{opacity:1;transform:translate(-83px,-140px) rotate(36deg)}22.86%{opacity:1;transform:translate(-49px,-127px) rotate(-10deg)}23.43%{opacity:1;transform:translate(-19px,-153px) rotate(-48deg)}24%{opacity:1;transform:translate(15px,-177px) rotate(-13deg)}24.57%{opacity:1;transform:translate(46px,-154px) rotate(65deg)}25.14%{opacity:1;transform:translate(59px,-116px) rotate(76deg)}25.71%{opacity:1;transform:translate(65px,-80px) rotate(85deg)}26.29%{opacity:1;transform:translate(68px,-48px) rotate(82deg)}26.86%{opacity:1;transform:translate(76px,-25px) rotate(55deg)}27.43%{opacity:1;transform:translate(89px,-16px) rotate(13deg)}28%{opacity:1;transform:translate(95px,-15px) rotate(-1deg)}35%{opacity:1;transform:translate(95px,-15px) rotate(-1deg)}35.43%{opacity:1;transform:translate(99px,-15px) rotate(-13deg)}35.86%{opacity:1;transform:translate(109px,-21px) rotate(-39deg)}36.29%{opacity:1;transform:translate(122px,-35px) rotate(-51deg)}36.71%{opacity:1;transform:translate(136px,-53px) rotate(-51deg)}37.14%{opacity:1;transform:translate(154px,-73px) rotate(-42deg)}37.57%{opacity:1;transform:translate(180px,-88px) rotate(-16deg)}38%{opacity:1;transform:translate(210px,-85px) rotate(21deg)}38.43%{opacity:1;transform:translate(240px,-82px) rotate(-13deg)}38.86%{opacity:1;transform:translate(266px,-96px) rotate(-40deg)}39.29%{opacity:1;transform:translate(286px,-114px) rotate(-38deg)}39.71%{opacity:1;transform:translate(307px,-125px) rotate(-16deg)}40.14%{opacity:1;transform:translate(325px,-125px) rotate(11deg)}40.57%{opacity:1;transform:translate(336px,-122px) rotate(23deg)}41%{opacity:1;transform:translate(340px,-120px) rotate(25deg)}41.3%{opacity:0;transform:translate(340px,-120px) rotate(25deg)}41.4%,69.9%{opacity:0;transform:translate(150px,-150px) rotate(230deg)}70%{opacity:1;transform:translate(150px,-150px) rotate(230deg)}70.57%{opacity:1;transform:translate(146px,-154px) rotate(227deg)}71.14%{opacity:1;transform:translate(133px,-163px) rotate(196deg)}71.71%{opacity:1;transform:translate(109px,-158px) rotate(151deg)}72.29%{opacity:1;transform:translate(83px,-140px) rotate(144deg)}72.86%{opacity:1;transform:translate(49px,-127px) rotate(190deg)}73.43%{opacity:1;transform:translate(19px,-153px) rotate(228deg)}74%{opacity:1;transform:translate(-15px,-177px) rotate(193deg)}74.57%{opacity:1;transform:translate(-46px,-154px) rotate(115deg)}75.14%{opacity:1;transform:translate(-59px,-116px) rotate(104deg)}75.71%{opacity:1;transform:translate(-65px,-80px) rotate(95deg)}76.29%{opacity:1;transform:translate(-68px,-48px) rotate(98deg)}76.86%{opacity:1;transform:translate(-76px,-25px) rotate(125deg)}77.43%{opacity:1;transform:translate(-89px,-16px) rotate(167deg)}78%{opacity:1;transform:translate(-95px,-15px) rotate(181deg)}85%{opacity:1;transform:translate(-95px,-15px) rotate(181deg)}85.43%{opacity:1;transform:translate(-99px,-15px) rotate(193deg)}85.86%{opacity:1;transform:translate(-109px,-21px) rotate(219deg)}86.29%{opacity:1;transform:translate(-122px,-35px) rotate(231deg)}86.71%{opacity:1;transform:translate(-136px,-53px) rotate(231deg)}87.14%{opacity:1;transform:translate(-154px,-73px) rotate(222deg)}87.57%{opacity:1;transform:translate(-180px,-88px) rotate(196deg)}88%{opacity:1;transform:translate(-210px,-85px) rotate(159deg)}88.43%{opacity:1;transform:translate(-240px,-82px) rotate(193deg)}88.86%{opacity:1;transform:translate(-266px,-96px) rotate(220deg)}89.29%{opacity:1;transform:translate(-286px,-114px) rotate(218deg)}89.71%{opacity:1;transform:translate(-307px,-125px) rotate(196deg)}90.14%{opacity:1;transform:translate(-325px,-125px) rotate(169deg)}90.57%{opacity:1;transform:translate(-336px,-122px) rotate(157deg)}91%{opacity:1;transform:translate(-340px,-120px) rotate(155deg)}91.3%,100%{opacity:0;transform:translate(-340px,-120px) rotate(155deg)}}@keyframes sabfd{0%,27.5%{transform:none}28.5%,34.5%{transform:scaleY(.5)}35.5%,77.5%{transform:none}78.5%,84.5%{transform:scaleY(.5)}85.5%,100%{transform:none}}@keyframes sabpa{0%,14%{opacity:0;transform:scale(0)}18%,42%{opacity:1;transform:scale(1)}46%,100%{opacity:0;transform:scale(0)}}@keyframes sabpb{0%,64%{opacity:0;transform:scale(0)}68%,92%{opacity:1;transform:scale(1)}96%,100%{opacity:0;transform:scale(0)}}@keyframes sabhov{0%{opacity:0;transform:translate(-150px,-150px) rotate(-50deg)}8%{opacity:1;transform:translate(-140px,-159px) rotate(-35deg)}17%{opacity:1;transform:translate(-105px,-156px) rotate(32deg)}25%{opacity:1;transform:translate(-57px,-127px) rotate(8deg)}33%{opacity:1;transform:translate(-3px,-168px) rotate(-38deg)}42%{opacity:1;transform:translate(53px,-137px) rotate(72deg)}50%{opacity:1;transform:translate(68px,-54px) rotate(84deg)}58%{opacity:1;transform:translate(120px,-33px) rotate(-51deg)}67%{opacity:1;transform:translate(178px,-87px) rotate(-16deg)}75%{opacity:1;transform:translate(247px,-84px) rotate(-22deg)}83%{opacity:1;transform:translate(292px,-118px) rotate(-33deg)}92%{opacity:1;transform:translate(327px,-125px) rotate(14deg)}100%{opacity:0;transform:translate(340px,-120px) rotate(25deg)}}}';
    private static $widget_css = false;
    private static $widget_benutzt = false;

    /** Sicherheitsnetz: Das Widget-CSS steht auch im Seitenfuß, falls das erste Widget ohne CSS ausgegeben oder sein Ergebnis verworfen wurde (Besucher sahen sonst Karten ohne Gestaltung). */
    public static function widget_css_fuss(): void {
        if (!self::$widget_benutzt) { return; }
        $f = (string) get_option('sab_widget_knopffarbe', '');   // z. B. #3a6ea5: eigene Knopffarbe
        $ex = preg_match('/^#[0-9a-fA-F]{6}$/', $f) ? '.sab-wb a.sab-wbtn{background:' . $f . ';border-color:' . $f . '}.sab-wb a.sab-wbtn:hover{filter:brightness(.88)}' : '';
        echo '<style id="sab-widget-fuss">' . self::WIDGET_CSS . $ex . '</style>';
    }

    /**
     * Meistgelesene Artikel der letzten 7 Tage (eigene Aufrufzählung, ohne Cookie/IP, Roboter schon beim Zählen ausgeschlossen).
     * @param string[] $aus Pfade oder Beitrags-IDs, die nicht erscheinen sollen (Startseite, Impressum, Datenschutz, Kontakt sind immer ausgeschlossen)
     * @return array<int,array{titel:string,url:string}>
     */
    private static function beliebt_artikel(int $n, array $aus): array {
        global $wpdb;
        if (!isset($wpdb)) { return []; }
        $key = 'sab_beliebt_' . md5($n . '|' . implode(',', $aus));
        $c = get_transient($key); if (is_array($c)) { return $c; }
        $ab = gmdate('Y-m-d', time() - 6 * DAY_IN_SECONDS);
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT pfad, SUM(n) s FROM " . self::seiten_tabelle() . " WHERE tag >= %s GROUP BY pfad ORDER BY s DESC LIMIT 60", $ab), ARRAY_A);
        $aus_ids = []; $aus_pf = [];
        foreach ($aus as $x) { if (ctype_digit($x)) { $aus_ids[(int) $x] = 1; } else { $aus_pf[] = '/' . trim($x, '/') . '/'; } }
        $out = [];
        foreach ($rows as $r) {
            $pf = (string) $r['pfad']; $nn = '/' . trim($pf, '/') . '/';
            if ($nn === '//' || $nn === '/' || (int) $r['s'] < 3) { continue; }
            if (preg_match('#/(impressum|datenschutz[^/]*|kontakt|cookie[^/]*|privacy[^/]*|aviso-legal|politica[^/]*|mentions-legales|contact|contacto|danke|dank|404)/$#i', $nn) || in_array($nn, $aus_pf, true)) { continue; }
            $id = (int) url_to_postid(home_url($pf));
            if ($id < 1 || isset($aus_ids[$id]) || get_post_status($id) !== 'publish' || !in_array(get_post_type($id), ['post', 'page'], true)) { continue; }
            $out[$id] = ['titel' => (string) get_the_title($id), 'url' => (string) get_permalink($id)];
            if (count($out) >= $n) { break; }
        }
        $out = array_values($out);
        set_transient($key, $out, HOUR_IN_SECONDS);
        return $out;
    }

    /** Kleines Tierchen (Biene, Schmetterling, Libelle), das ab und zu am Desktop durchs Widget fliegt; reines CSS, je Buch fest (nach ASIN), also cache-sicher. */
    private static function widget_tier(string $asin): string {
        if (get_option('sab_widget_tiere') !== '1') { return ''; }   // Tierchen nur auf Wunsch: Hotfix 0.92.12, auf manchen Seiten griff das CSS nicht und die Tiere standen riesig da
        $t = [1 => '<svg width="34" height="23" viewBox="0 0 48 32"><g class="fd"><g class="fw"><path d="M25 12C24 6 27 1.5 32 1.5 35 3 33 9 29 12.5Z" fill="#e3f0f8" fill-opacity=".65" stroke="#8aa9bd" stroke-width=".4"/><path d="M27 12C27 8 29 4 31 2.5" fill="none" stroke="#8aa9bd" stroke-width=".3"/><path d="M22 12.5C19 8 20 4 23 4 26 5 25 9 23.5 12.5Z" fill="#e3f0f8" fill-opacity=".6" stroke="#8aa9bd" stroke-width=".4"/><g transform="matrix(1 0 0 -1 0 32)"><path d="M25 12C24 6 27 1.5 32 1.5 35 3 33 9 29 12.5Z" fill="#e3f0f8" fill-opacity=".65" stroke="#8aa9bd" stroke-width=".4"/><path d="M27 12C27 8 29 4 31 2.5" fill="none" stroke="#8aa9bd" stroke-width=".3"/><path d="M22 12.5C19 8 20 4 23 4 26 5 25 9 23.5 12.5Z" fill="#e3f0f8" fill-opacity=".6" stroke="#8aa9bd" stroke-width=".4"/></g></g></g><g stroke="#2b2118" stroke-width=".6" fill="none"><path d="M28 11L26 7.5M31 11.5L33 8.5M25.5 12L22.5 9.5"/><g transform="matrix(1 0 0 -1 0 32)"><path d="M28 11L26 7.5M31 11.5L33 8.5M25.5 12L22.5 9.5"/></g></g><ellipse cx="15" cy="16" rx="11.5" ry="6.2" fill="#c8902a"/><path d="M10.5 10.3v11.4M15 9.8v12.4M19.5 10.3v11.4" stroke="#3a2a1a" stroke-width="2.3"/><path d="M4 16H1.5" stroke="#2b2118" stroke-width="1"/><ellipse cx="14" cy="13" rx="8" ry="1.6" fill="#fff" fill-opacity=".25"/><circle cx="28.5" cy="16" r="5.2" fill="#8a6a30" stroke="#d9b45a" stroke-width="1"/><ellipse cx="36.5" cy="16" rx="3.6" ry="4" fill="#2b2118"/><ellipse cx="37" cy="13.8" rx="1.6" ry="2" fill="#4a3a30"/><ellipse cx="37" cy="18.2" rx="1.6" ry="2" fill="#4a3a30"/><circle cx="37.4" cy="13.2" r=".5" fill="#fff"/><circle cx="37.4" cy="18.8" r=".5" fill="#fff"/><path d="M39 14.3Q42 11 45 12M39 17.7Q42 21 45 20" stroke="#2b2118" fill="none" stroke-width=".7"/></svg>', 2 => '<svg width="34" height="23" viewBox="0 0 48 32"><g class="fd"><g class="fw"><path d="M34 15.5C35 8 31 2 25 1.5 20 1.5 18 6 21 10.5 24 14 30 15.5 34 15.5Z" fill="#b53a1c" stroke="#2a1d17" stroke-width="1.3"/><path d="M27 16C22 12.5 14 8 10 11 8 13.5 13 16 18 16.5Z" fill="#9c2f19" stroke="#2a1d17" stroke-width="1.3"/><path d="M33 15L25 3M33 15L21.5 6M32 15.3L20.5 9M27 15.8L13 10.5M26 16L11 13" stroke="#2a1d17" stroke-width=".6" fill="none"/><circle cx="24.5" cy="5.6" r="2.6" fill="#f2d24a"/><circle cx="24.5" cy="5.6" r="1.9" fill="#2a1d17"/><circle cx="24.5" cy="5.6" r="1.1" fill="#3d6fc4"/><circle cx="15" cy="12.4" r="1.4" fill="#3d6fc4" stroke="#2a1d17" stroke-width=".5"/><g transform="matrix(1 0 0 -1 0 32)"><path d="M34 15.5C35 8 31 2 25 1.5 20 1.5 18 6 21 10.5 24 14 30 15.5 34 15.5Z" fill="#b53a1c" stroke="#2a1d17" stroke-width="1.3"/><path d="M27 16C22 12.5 14 8 10 11 8 13.5 13 16 18 16.5Z" fill="#9c2f19" stroke="#2a1d17" stroke-width="1.3"/><path d="M33 15L25 3M33 15L21.5 6M32 15.3L20.5 9M27 15.8L13 10.5M26 16L11 13" stroke="#2a1d17" stroke-width=".6" fill="none"/><circle cx="24.5" cy="5.6" r="2.6" fill="#f2d24a"/><circle cx="24.5" cy="5.6" r="1.9" fill="#2a1d17"/><circle cx="24.5" cy="5.6" r="1.1" fill="#3d6fc4"/><circle cx="15" cy="12.4" r="1.4" fill="#3d6fc4" stroke="#2a1d17" stroke-width=".5"/></g></g></g><path d="M13 16H37" stroke="#2a1d17" stroke-width="2.6" stroke-linecap="round"/><path d="M16 15.2v1.6M19 15.2v1.6M22 15.2v1.6M25 15.2v1.6" stroke="#8a7a70" stroke-width=".6"/><circle cx="38.5" cy="16" r="2.2" fill="#2a1d17"/><circle cx="39.2" cy="15.3" r=".5" fill="#8a7a70"/><path d="M39.5 14.8Q43 10 46 11M39.5 17.2Q43 22 46 21" stroke="#2a1d17" fill="none" stroke-width=".6"/><circle cx="46.2" cy="11" r=".9" fill="#2a1d17"/><circle cx="46.2" cy="21" r=".9" fill="#2a1d17"/></svg>', 3 => '<svg width="34" height="23" viewBox="0 0 48 32"><g class="fd"><g class="fw"><path d="M31 15C29 9 30 4 33.5 1.2 37 3 37.5 9 35.5 14.5Z" fill="#dcecf4" fill-opacity=".5" stroke="#6f93a8" stroke-width=".5"/><path d="M33 14L33.5 2.5M32 12L35 4M34 13.5L36.2 6" stroke="#6f93a8" stroke-width=".3" fill="none"/><rect x="33" y="1.6" width="1.3" height="2" fill="#3a2a20"/><path d="M28.5 15.2C23 11.5 21.5 6 25 2.8 29.5 4 32 9.5 31 14.5Z" fill="#dcecf4" fill-opacity=".5" stroke="#6f93a8" stroke-width=".5"/><path d="M29.5 14.5L25.3 3.5M28.5 13L28.5 4.8M30 14L24 7" stroke="#6f93a8" stroke-width=".3" fill="none"/><rect x="24.8" y="3" width="1.3" height="2" fill="#3a2a20"/><g transform="matrix(1 0 0 -1 0 32)"><path d="M31 15C29 9 30 4 33.5 1.2 37 3 37.5 9 35.5 14.5Z" fill="#dcecf4" fill-opacity=".5" stroke="#6f93a8" stroke-width=".5"/><path d="M33 14L33.5 2.5M32 12L35 4M34 13.5L36.2 6" stroke="#6f93a8" stroke-width=".3" fill="none"/><rect x="33" y="1.6" width="1.3" height="2" fill="#3a2a20"/><path d="M28.5 15.2C23 11.5 21.5 6 25 2.8 29.5 4 32 9.5 31 14.5Z" fill="#dcecf4" fill-opacity=".5" stroke="#6f93a8" stroke-width=".5"/><path d="M29.5 14.5L25.3 3.5M28.5 13L28.5 4.8M30 14L24 7" stroke="#6f93a8" stroke-width=".3" fill="none"/><rect x="24.8" y="3" width="1.3" height="2" fill="#3a2a20"/></g></g></g><path d="M31 14.7L4.5 15.5 2 16 4.5 16.5 31 17.3Z" fill="#46a6e0"/><path d="M9 14.8v2.4M13 14.8v2.4M17 14.7v2.6M21 14.7v2.6M25 14.6v2.8M3 15.4v1.2" stroke="#14232e" stroke-width="1.1"/><ellipse cx="33.5" cy="16" rx="4.6" ry="3.2" fill="#2f86c6"/><path d="M30 13.8L37 14.3M30 18.2L37 17.7" stroke="#14232e" stroke-width=".9"/><path d="M31 13.5L35 18.5M33.5 13L37 17.5" stroke="#1d4a36" stroke-width=".6"/><path d="M32 13L30 10.5M34 13L35.5 10.8M32 19L30 21.5M34 19L35.5 21.2" stroke="#1d2a30" stroke-width=".5"/><rect x="38" y="13" width="3.4" height="6" rx="1.4" fill="#14232e"/><ellipse cx="40.2" cy="12.6" rx="2.3" ry="2.4" fill="#4fb0d8"/><ellipse cx="40.2" cy="19.4" rx="2.3" ry="2.4" fill="#4fb0d8"/><circle cx="40.8" cy="12" r=".6" fill="#fff"/><circle cx="40.8" cy="18.8" r=".6" fill="#fff"/></svg>'];
        $n = (crc32($asin) % 3) + 1;
        $p = [1 => ['<svg width="36" height="30" viewBox="0 0 36 30"><path d="M18 14V30" stroke="#4b8a3a" stroke-width="1.6"/><ellipse cx="22" cy="24" rx="4" ry="1.8" fill="#5fa04a" transform="rotate(-25 22 24)"/><g fill="#fff" stroke="#d6d6d6" stroke-width=".4"><ellipse cx="18" cy="5" rx="2" ry="4.5"/><ellipse cx="18" cy="5" rx="2" ry="4.5" transform="rotate(45 18 10)"/><ellipse cx="18" cy="5" rx="2" ry="4.5" transform="rotate(90 18 10)"/><ellipse cx="18" cy="5" rx="2" ry="4.5" transform="rotate(135 18 10)"/><ellipse cx="18" cy="5" rx="2" ry="4.5" transform="rotate(180 18 10)"/><ellipse cx="18" cy="5" rx="2" ry="4.5" transform="rotate(225 18 10)"/><ellipse cx="18" cy="5" rx="2" ry="4.5" transform="rotate(270 18 10)"/><ellipse cx="18" cy="5" rx="2" ry="4.5" transform="rotate(315 18 10)"/></g><circle cx="18" cy="10" r="3" fill="#f5c400"/></svg>', '<svg width="36" height="30" viewBox="0 0 36 30"><path d="M18 15V30" stroke="#4b8a3a" stroke-width="1.6"/><g fill="#d8322b"><circle cx="13" cy="8" r="5.2"/><circle cx="23" cy="8" r="5.2"/><circle cx="14" cy="13" r="4.6"/><circle cx="22" cy="13" r="4.6"/></g><circle cx="18" cy="10.5" r="2.2" fill="#2b2118"/></svg>'], 2 => ['<svg width="36" height="30" viewBox="0 0 36 30"><path d="M18 14V30" stroke="#4b8a3a" stroke-width="1.6"/><g fill="#e56aa0" stroke="#c24a82" stroke-width=".3"><ellipse cx="18" cy="4.5" rx="1.8" ry="5"/><ellipse cx="18" cy="4.5" rx="1.8" ry="5" transform="rotate(36 18 10)"/><ellipse cx="18" cy="4.5" rx="1.8" ry="5" transform="rotate(72 18 10)"/><ellipse cx="18" cy="4.5" rx="1.8" ry="5" transform="rotate(108 18 10)"/><ellipse cx="18" cy="4.5" rx="1.8" ry="5" transform="rotate(144 18 10)"/><ellipse cx="18" cy="4.5" rx="1.8" ry="5" transform="rotate(180 18 10)"/><ellipse cx="18" cy="4.5" rx="1.8" ry="5" transform="rotate(216 18 10)"/><ellipse cx="18" cy="4.5" rx="1.8" ry="5" transform="rotate(252 18 10)"/><ellipse cx="18" cy="4.5" rx="1.8" ry="5" transform="rotate(288 18 10)"/><ellipse cx="18" cy="4.5" rx="1.8" ry="5" transform="rotate(324 18 10)"/></g><circle cx="18" cy="10" r="2.6" fill="#f5c400"/></svg>', '<svg width="36" height="30" viewBox="0 0 36 30"><path d="M18 14V30" stroke="#4b8a3a" stroke-width="1.6"/><g fill="#9b6fc4"><circle cx="18" cy="4" r="2.6"/><circle cx="14" cy="7" r="2.6"/><circle cx="22" cy="7" r="2.6"/><circle cx="18" cy="9" r="2.8"/><circle cx="12" cy="11" r="2.4"/><circle cx="24" cy="11" r="2.4"/><circle cx="18" cy="14" r="2.4"/></g></svg>'], 3 => ['<svg width="44" height="30" viewBox="0 0 44 30"><ellipse cx="22" cy="24" rx="20" ry="5.5" fill="#6bb6d6" fill-opacity=".85"/><path d="M22 17 29 13A9 3.4 0 1 1 22 17Z" fill="#4f9a4a"/><ellipse cx="22" cy="17" rx="9" ry="3.4" fill="#4f9a4a"/><g fill="#f4a6c4" stroke="#d676a0" stroke-width=".3"><ellipse cx="22" cy="12" rx="2" ry="4.5"/><ellipse cx="22" cy="12" rx="2" ry="4.5" transform="rotate(40 22 15)"/><ellipse cx="22" cy="12" rx="2" ry="4.5" transform="rotate(-40 22 15)"/></g></svg>', '<svg width="44" height="30" viewBox="0 0 44 30"><ellipse cx="22" cy="24" rx="20" ry="5.5" fill="#6bb6d6" fill-opacity=".85"/><path d="M13 21C14 14 30 13 31 21Z" fill="#8d9096"/><path d="M16 18C19 15 25 15 28 17" stroke="#b9bcc2" stroke-width="1" fill="none"/></svg>']][$n];
        return '<span class="sab-tw" style="display:none" aria-hidden="true"><i class="sab-tier sab-t' . $n . '" aria-hidden="true">' . $t[$n] . '</i><i class="sab-perch sab-pa sab-p' . $n . '" aria-hidden="true">' . $p[0] . '</i><i class="sab-perch sab-pb sab-p' . $n . '" aria-hidden="true">' . $p[1] . '</i></span>';
    }

    /**
     * [amz_widget typ="buch" asin="…" marke="Buchtipp" titel="…" text="…" knopf="…" alt_text="oder bei Thalia ansehen" alt_url="https://…" hinweis="Werbung · Partnerlink"]
     * [amz_widget typ="tipp" titel="…" text="…" knopf="Zu den Tipps" url="https://…"]
     * [amz_widget typ="liste" titel="Beliebt diese Woche"]Text|https://…  (eine Zeile je Eintrag, Link ist optional)[/amz_widget]
     * Buch-Karte: Bild und Knopf sind EIN Affiliate-Link (das Bild ist kein eigener Link, der Knopf deckt die ganze Karte ab).
     */
    public static function sc_widget($a, $inhalt = ''): string {
        $a = shortcode_atts(['anzahl' => '3', 'ohne' => '', 'farbe' => '', 'typ' => 'buch', 'asin' => '', 'land' => '', 'marke' => '', 'titel' => '', 'text' => '', 'knopf' => '', 'alt_text' => '', 'alt_url' => '', 'url' => '', 'hinweis' => '', 'tag' => ''], (array) $a);
        $css = '';
        self::$widget_benutzt = true;
        if (!self::$widget_css) { self::$widget_css = true; $css = '<style id="sab-widget">' . self::WIDGET_CSS . '</style>'; }
        $typ = strtolower(trim((string) $a['typ']));
        if ($typ === 'tipp') {
            $u = esc_url((string) $a['url']);
            return $css . '<div class="sab-wt"><b>' . esc_html((string) $a['titel']) . '</b>' . ($a['text'] !== '' ? '<p>' . esc_html((string) $a['text']) . '</p>' : '') . ($u !== '' ? '<a href="' . $u . '">' . esc_html($a['knopf'] !== '' ? (string) $a['knopf'] : 'Mehr erfahren') . '</a>' : '') . '</div>';
        }
        if ($typ === 'beliebt') {   // Beliebte Artikel der letzten 7 Tage aus der eigenen Aufrufzählung (ohne Zahlen, ohne Cookie/IP, Roboter ausgeschlossen)
            if (get_option('sab_beliebt_an') !== '1') { update_option('sab_beliebt_an', '1', false); }   // ab jetzt zählt das Plugin die Aufrufe aller Artikel
            $it = self::beliebt_artikel(max(1, min(10, (int) $a['anzahl'])), array_filter(array_map('trim', explode(',', (string) $a['ohne']))));
            if (count($it) < 2) { return ''; }
            $f = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $a['farbe']) ? (string) $a['farbe'] : (preg_match('/^#[0-9a-fA-F]{6}$/', (string) get_option('sab_widget_farbe', '')) ? (string) get_option('sab_widget_farbe') : '');
            $tt = ['de' => 'Beliebte Artikel diese Woche', 'es' => 'Artículos populares esta semana', 'fr' => 'Articles populaires cette semaine', 'it' => 'Articoli popolari questa settimana', 'co.uk' => 'Popular articles this week', 'com' => 'Popular articles this week'];
            $li = ''; foreach ($it as $x) { $li .= '<li><a href="' . esc_url($x['url']) . '">' . esc_html($x['titel']) . '</a></li>'; }
            return $css . '<div class="sab-wl"' . ($f !== '' ? ' style="--sabc:' . $f . ';border-color:' . $f . '33"' : '') . '><h4>' . esc_html($a['titel'] !== '' ? (string) $a['titel'] : ($tt[self::land($a['land'])] ?? $tt['de'])) . '</h4><ol>' . $li . '</ol></div>';
        }
        if ($typ === 'liste') {
            $li = '';
            foreach (preg_split('/\R/', trim(strip_tags((string) $inhalt))) as $z) {
                $z = trim($z); if ($z === '') { continue; }
                [$t, $u] = array_pad(array_map('trim', explode('|', $z, 2)), 2, '');
                $li .= '<li>' . ($u !== '' && esc_url($u) !== '' ? '<a href="' . esc_url($u) . '">' . esc_html($t) . '</a>' : esc_html($t)) . '</li>';
            }
            return $li === '' ? '' : $css . '<div class="sab-wl">' . ($a['titel'] !== '' ? '<h4>' . esc_html((string) $a['titel']) . '</h4>' : '') . '<ol>' . $li . '</ol></div>';
        }
        $land = self::land($a['land']); $asin = self::getauscht(self::asin($a['asin']));
        if (strlen($asin) !== 10) { return ''; }
        if (!self::$ab_in && !self::$ab_ctx && ($wz = self::ab_widget(self::asin($a['asin'])))) { $wh = self::ab_widget_html($a, $wz); if ($wh !== '') { return $css . $wh; } }
        self::$stil_n = true;
        $bild = self::sc_bild(['asin' => $asin, 'land' => $land, 'tag' => $a['tag'], 'breite' => '240'], '');
        $img = preg_match('#<img\b[^>]*>#i', $bild, $mi) ? $mi[0] : $bild;   // das Bild ist kein eigener Link: nur der Knopf ist der Affiliate-Link
        $knopf = $a['knopf'] !== '' ? (string) $a['knopf'] : (self::BUTTON_TEXT[$land] ?? self::BUTTON_TEXT['de']);
        $btn = self::sc_link(['asin' => $asin, 'land' => $land, 'class' => 'sab-wbtn', 'tag' => $a['tag']], $knopf);
        $alt = (get_option('sab_widget_alt') === '1' && $a['alt_text'] !== '' && esc_url((string) $a['alt_url']) !== '') ? '<a class="sab-walt" href="' . esc_url((string) $a['alt_url']) . '" target="_blank" rel="nofollow sponsored noopener">' . esc_html((string) $a['alt_text']) . '</a>' : '';
        $titel = $a['titel'] !== '' ? (string) $a['titel'] : (string) (self::produkte()[$asin] ?? '');
        if ($a['titel'] === '' && $titel !== '' && $typ === 'produkt') { $titel = self::kurztitel($titel); }   // ohne eigenen Titel: Kurzname des Produkts
        return $css . '<div class="sab-wb">' . ($a['marke'] !== '' ? '<span class="sab-wtag">' . esc_html((string) $a['marke']) . '</span>' : '') . '<div class="sab-wcover">' . $img . '</div>' . ($typ === 'produkt' ? '' : self::widget_tier($asin))   // „produkt“ = Einzelprodukt-Widget: gleiche Optik, ohne Tierchen
            . ($titel !== '' ? '<h4>' . esc_html($titel) . '</h4>' : '') . ($a['text'] !== '' ? '<p>' . esc_html((string) $a['text']) . '</p>' : '') . $btn . $alt
            . (get_option('sab_widget_hinweis') === '1' || $a['hinweis'] !== '' ? '<div class="sab-wnote">' . esc_html($a['hinweis'] !== '' ? (string) $a['hinweis'] : 'Werbung · Partnerlink') . '</div>' : '') . '</div>';
    }

    /** Reiter „Widgets“: fertige Vorlagen für die Seitenleiste mit Vorschau und Kopierfeld (Shortcode in ein Text- oder HTML-Widget einfügen). */
    private static function widgets_html(): string {
        $asin = ''; foreach (array_keys(self::produkte()) as $k) { $asin = (string) $k; break; }
        $demo = $asin !== '' ? $asin : 'B000000000';
        $vorlagen = [
            ['Buch-Karte (ersetzt die alte Buch-Box)', 'Bild und Knopf sind zusammen EIN Affiliate-Link, die ganze Karte ist klickbar. Die ASIN des Buchs eintragen; Bild und Link folgen einem Austausch automatisch.',
             '[amz_widget typ="buch" asin="' . $demo . '" marke="Buchtipp" titel="Ein Lob der Unordnung im Garten" text="Wie aus Laub, Totholz und Steinen Verstecke für Igel, Kröten und Laufkäfer werden. Diese Tiere fressen Schnecken für dich." knopf="Bei Amazon ansehen" alt_text="oder bei Thalia ansehen" alt_url="https://www.thalia.de/"]'],
            ['Produkt-Widget (allgemeines Tierhilfe-Produkt)', 'Wie die Buch-Karte, aber für ein einzelnes Produkt: Bild, Kurzname, kurzer Text, dezenter Button, ohne Tierchen. Im Umbau-Reiter lässt sich darunter ein A/B/C-Test mit zwei weiteren Produkten starten.',
             '[amz_widget typ="produkt" asin="' . $demo . '" marke="Tipp" text="Hilft ohne Gift und ohne Verletzung der Tiere."]'],
            ['Buch-Karte kurz (ohne zweiten Link)', 'Für Seiten mit nur einem Händler.',
             '[amz_widget typ="buch" asin="' . $demo . '" marke="Zum Weiterlesen" titel="Titel des Buchs" text="Ein bis zwei Sätze, warum sich das Buch lohnt."]'],
            ['Tipp mit Frage', 'Orange Box mit Frage und Knopf zu einem Artikel (kein Affiliate-Link).',
             '[amz_widget typ="tipp" titel="Schnecken im Beet? 🐌" text="Diese drei Dinge helfen am schnellsten. Ohne Gift, und die Tiere bleiben unverletzt." knopf="Zu den Tipps" url="' . esc_url(home_url('/')) . '"]'],
            ['Einfacher Amazon-Link (Startseite)', 'Ein Textlink zur Amazon-Startseite mit der Partner-ID des Landes, mit Länder-Umschaltung und Klickstatistik (Kennung STARTSEITE). Passt als ruhiger Ersatz für alte Startseiten-Kurzlinks in der Seitenleiste.',
             '[amz_startseite]Bei Amazon stöbern[/amz_startseite]'],
            ['Top-3-Liste', 'Nummerierte Liste, jede Zeile: Text|Adresse (Adresse ist optional).',
             "[amz_widget typ=\"liste\" titel=\"Beliebt diese Woche\"]\nSchneckenkragen aus Kupfer|" . esc_url(home_url('/')) . "\nElektrischer Schneckenzaun|" . esc_url(home_url('/')) . "\nIgelhaus für den Garten|" . esc_url(home_url('/')) . "\n[/amz_widget]"],
        ];
        $h = '<h2>Widgets für die Seitenleiste</h2><p class="description" style="max-width:760px">Fertige, ansprechende Vorlagen. So geht es: Unter Design &rarr; Widgets ein „Text“- oder „Eigenes HTML“-Widget in die Seitenleiste ziehen und den Shortcode aus dem Feld einfügen. Texte, Titel und ASIN kannst du frei ändern. Die Vorschau unten ist so breit wie eine Seitenleiste (300 px).</p>';
        $h .= '<style>' . self::WIDGET_CSS . '.sab-wv{display:flex;flex-wrap:wrap;gap:24px;align-items:flex-start;margin:0 0 22px;padding:16px;background:#f1f3ee;border:1px solid #dcdcde;border-radius:8px;max-width:1000px}.sab-wv .v{width:300px;flex:none}.sab-wv .c{flex:1;min-width:280px}.sab-wv textarea{width:100%;min-height:110px;font-family:monospace;font-size:12px}</style>';
        foreach ($vorlagen as $i => [$name, $hilfe, $code]) {
            $h .= '<div class="sab-wv"><div class="v">' . do_shortcode($code) . '</div><div class="c"><h3 style="margin-top:0">' . esc_html($name) . '</h3><p class="description">' . esc_html($hilfe) . '</p><textarea id="sab-wc' . (int) $i . '" readonly onclick="this.select()">' . esc_textarea($code) . '</textarea><p><button type="button" class="button" onclick="var t=document.getElementById(\'sab-wc' . (int) $i . '\');t.select();try{document.execCommand(\'copy\');this.textContent=\'Kopiert ✓\'}catch(e){}">Shortcode kopieren</button></p></div></div>';
        }
        return $h;
    }

    // ---------- Verwaltung ----------

    const TABS = [
        'uebersicht'    => 'Übersicht',
        'produkte'      => 'Produkte und Ersatzlisten',
        'statistik'     => 'Statistik',
        'problem'       => 'Ausfälle und Wartung',
        'austausch'     => 'Austausch',
        'helfer'        => 'Helferaufgaben',
        'laender'       => 'Länder',
        'lernen'        => 'Lernen',
        'scan'          => 'Seite scannen',
        'kartendesign'  => 'Kartendesign',
        'gruppen'       => 'Produktgruppen',
        'score'         => 'Score',
        'umbau'         => 'Umbau',
        'vorschau'      => 'Vorschau',
        'tests'         => 'A/B-Tests',
        'einstellungen' => 'Einstellungen',
        'widgets'       => 'Widgets',
        'hilfe'         => 'Hilfe',
    ];

    /** Links in der Pluginliste neben „Deaktivieren“. */
    public static function aktionslinks($links): array {
        $neu = [
            '<a href="' . esc_url(admin_url('admin.php?page=sab-einstellungen')) . '">Einstellungen</a>',
            '<a href="' . esc_url(admin_url('admin.php?page=sab')) . '">Übersicht</a>',
            '<a href="' . esc_url(wp_nonce_url(admin_url('admin.php?page=sab-einstellungen&sab_update_jetzt=1'), 'sab_update_jetzt')) . '">Nach Update suchen</a>',
        ];
        return array_merge($neu, (array) $links);
    }

    /**
     * Gutenberg-Block „Amazon-Produkt“ (dynamisch: die Ausgabe kommt beim Anzeigen vom Plugin, so wirken Austausch, Gruppen und Tests).
     * Eingabe pro Zeile: ASIN, Amazon-Adresse oder Kurzlink (amzn.to/…), optional „ | Kurzname“. Mehrere Zeilen = Karten nebeneinander.
     */
    public static function block_registrieren(): void {
        if (!function_exists('register_block_type') || !function_exists('wp_register_script')) { return; }
        wp_register_script('sab-block', '', ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render'], self::VERSION, true);
        wp_add_inline_script('sab-block', self::BLOCK_JS);
        register_block_type('sab/produkt', [
            'api_version'     => 2,
            'title'           => 'Amazon-Produkt (' . self::NAME . ')',
            'category'        => 'widgets',
            'icon'            => 'cart',
            'attributes'      => ['quelle' => ['type' => 'string', 'default' => ''], 'darstellung' => ['type' => 'string', 'default' => 'karte'], 'text' => ['type' => 'string', 'default' => '']],
            'editor_script'   => 'sab-block',
            'render_callback' => [__CLASS__, 'block_ausgabe'],
        ]);
    }

    const BLOCK_JS = <<<'JS'
(function(w){var b=w.wp.blocks,e=w.wp.element.createElement,be=w.wp.blockEditor,c=w.wp.components,SSR=w.wp.serverSideRender;
b.registerBlockType('sab/produkt',{edit:function(p){var a=p.attributes,s=p.setAttributes;
return e('div',be.useBlockProps(),
e(be.InspectorControls,null,e(c.PanelBody,{title:'Darstellung',initialOpen:true},
e(c.SelectControl,{label:'Darstellung',value:a.darstellung,options:[{label:'Karte(n) nebeneinander',value:'karte'},{label:'Nur Bild',value:'bild'},{label:'Nur Button',value:'button'},{label:'Textlink',value:'text'}],onChange:function(v){s({darstellung:v})}}),
e(c.TextControl,{label:'Button-/Linktext (optional)',value:a.text,onChange:function(v){s({text:v})}}))),
e(c.TextareaControl,{label:'Produkt(e): pro Zeile ASIN, Amazon-Adresse oder Kurzlink, optional | Kurzname',value:a.quelle,rows:3,onChange:function(v){s({quelle:v})},placeholder:'B0DZWRJNTV | Cuello anticaracoles - 12 unidades\nhttps://amzn.to/3B0BRT2'}),
a.quelle?e(SSR,{block:'sab/produkt',attributes:a}):null)},save:function(){return null}});})(window);
JS;

    /** Ausgabe des Blocks. */
    public static function block_ausgabe($attrs): string {
        $attrs = (array) $attrs;
        $dar = in_array($attrs['darstellung'] ?? 'karte', ['karte', 'bild', 'button', 'text'], true) ? $attrs['darstellung'] : 'karte';
        $text = trim((string) ($attrs['text'] ?? ''));
        $teile = [];
        foreach (preg_split('/\R/', (string) ($attrs['quelle'] ?? '')) as $z) {
            [$q, $name] = array_pad(array_map('trim', explode('|', $z, 2)), 2, '');
            if ($q === '') { continue; }
            $land = self::land('');
            if (preg_match('#^https?://#i', $q)) { $u = self::umbau_url($q, true); if (!$u) { continue; } [$land, $asin] = $u; }
            else { $asin = substr(self::asin($q), 0, 10); }
            if (strlen($asin) !== 10) { continue; }
            if ($dar === 'bild') { $teile[] = self::sc_bild(['asin' => $asin, 'land' => $land], ''); }
            elseif ($dar === 'button') { $teile[] = self::sc_link(['asin' => $asin, 'land' => $land, 'class' => 'sab-karte-btn'], $text !== '' ? $text : (self::BUTTON_TEXT[$land] ?? self::BUTTON_TEXT['de'])); }
            elseif ($dar === 'text') { $teile[] = self::sc_link(['asin' => $asin, 'land' => $land], $text !== '' ? $text : $name); }
            else { $teile[] = self::sc_karte(['asin' => $asin, 'land' => $land, 'titel' => $name, 'text' => $text], ''); }
        }
        $teile = array_values(array_filter($teile));
        if (!$teile) { return ''; }
        if ($dar !== 'karte') { return implode(' ', $teile); }
        self::$genutzt = true; self::$karten = true;
        return '<div class="sab-karten" style="grid-template-columns:repeat(' . max(1, min(4, count($teile))) . ',minmax(0,1fr))">' . implode('', $teile) . '</div>';
    }

    /** Eigener Punkt "1AB Affiliate Help" in der Seitenleiste des Backends, mit einem Untermenü je Reiter. */
    public static function menue(): void {
        add_menu_page(self::NAME, self::NAME, 'manage_options', 'sab', [__CLASS__, 'seite'], 'dashicons-cart', 58);
        foreach (self::TABS as $k => $name) {
            // „Produkte und Ersatzlisten“ (Kurznamen, Austauschregeln) wird kaum noch gebraucht: nicht im Menü, nur über den Link unten im Austausch-Reiter
            add_submenu_page($k === 'produkte' ? null : 'sab', $name . ' - ' . self::NAME, $name, 'manage_options', $k === 'uebersicht' ? 'sab' : 'sab-' . $k, [__CLASS__, 'seite']);
        }
    }

    // ---------- Auswertung der Produktblöcke und Endblöcke ----------

    /** Der Endblock („sm-world“) eines Artikels: Anfang und Ende (ausgewogene div-Klammern) oder null. @return int[]|null */
    public static function endblock_von(string $c): ?array {
        if (!preg_match('#<div\b[^>]*class="[^"]*\bsm-world\b[^"]*"[^>]*>#i', $c, $m, PREG_OFFSET_CAPTURE)) { return null; }
        $s = (int) $m[0][1]; $tiefe = 0; $pos = $s;
        if (!preg_match_all('#<(/?)div\b[^>]*>#i', $c, $dm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE, $s)) { return null; }
        foreach ($dm as $x) {
            $tiefe += $x[1][0] === '/' ? -1 : 1; $pos = (int) $x[0][1] + strlen($x[0][0]);
            if ($tiefe === 0) { return [$s, $pos]; }
        }
        return [$s, strlen($c)];
    }

    /**
     * Zählt die Produktblöcke (nach Zahl der Produkte: 1, 2, 3, 4 und mehr) und die Endblock-Varianten in den übergebenen Artikeln.
     * @param array<int,array{id:int,title:string,content:string}> $posts
     * @return array{artikel:int,mit_end:int,bloecke:array,end:array}
     */
    public static function bloecke_aus_inhalten(array $posts): array {
        $out = ['artikel' => count($posts), 'mit_end' => 0, 'bloecke' => [1 => [], 2 => [], 3 => [], 4 => []], 'end' => []];
        foreach ($posts as $p) {
            $c = (string) $p['content']; $id = (int) $p['id'];
            if ($e = self::endblock_von($c)) {
                $eh = substr($c, $e[0], $e[1] - $e[0]); $as = self::entz_asins($eh); sort($as); $k = md5(implode(',', $as));
                $out['mit_end']++;
                if (!isset($out['end'][$k])) { $out['end'][$k] = ['n' => 0, 'asins' => $as, 'html' => mb_substr($eh, 0, 60000), 'posts' => []]; }
                $out['end'][$k]['n']++; $out['end'][$k]['posts'][$id] = (string) $p['title'];
                $c = substr($c, 0, $e[0]) . substr($c, $e[1]);
            }
            foreach (self::entz_einheiten($c) as $u) {
                $as = $u['asins']; sort($as); $n = max(1, count($as)); $g = min(4, $n); $k = implode(',', $as) . '#' . $n;
                if (!isset($out['bloecke'][$g][$k])) { $out['bloecke'][$g][$k] = ['n' => 0, 'asins' => $as, 'html' => mb_substr((string) $u['html'], 0, 6000), 'posts' => []]; }
                $out['bloecke'][$g][$k]['n']++; $out['bloecke'][$g][$k]['posts'][$id] = (string) $p['title'];
            }
        }
        foreach ($out['bloecke'] as $g => &$liste) { uasort($liste, fn($a, $b) => $b['n'] <=> $a['n']); } unset($liste);
        uasort($out['end'], fn($a, $b) => $b['n'] <=> $a['n']);
        return $out;
    }

    private static function bloecke_daten(): array {
        $c = get_transient('sab_bloecke_daten');
        if (is_array($c) && empty($_POST['sab_bloecke_neu'])) { return $c; }
        global $wpdb; $posts = [];
        if (isset($wpdb)) {
            foreach ((array) $wpdb->get_results("SELECT ID, post_title, post_content FROM {$wpdb->posts} WHERE post_status='publish' AND post_type IN ('post','page') AND (post_content LIKE '%[amz\\_%' OR post_content LIKE '%[aawp%' OR post_content LIKE '%[amazon %' OR post_content LIKE '%amazon.%' OR post_content LIKE '%sm-world%')") as $r) { $posts[] = ['id' => (int) $r->ID, 'title' => (string) $r->post_title, 'content' => (string) $r->post_content]; }
        }
        $d = self::bloecke_aus_inhalten($posts); $d['ts'] = time();
        set_transient('sab_bloecke_daten', $d, 15 * MINUTE_IN_SECONDS);
        return $d;
    }

    private static function bloecke_artikel_links(array $posts, int $max = 12): string {
        $l = []; $i = 0;
        foreach ($posts as $id => $t) {
            if (++$i > $max) { $l[] = '… und ' . (count($posts) - $max) . ' weitere'; break; }
            $l[] = '<a href="' . esc_url((string) get_permalink((int) $id)) . '" target="_blank" rel="noopener">' . esc_html(mb_substr($t !== '' ? $t : 'Beitrag ' . $id, 0, 45)) . '</a> <small><a href="' . esc_url(admin_url('post.php?post=' . (int) $id . '&action=edit')) . '" target="_blank" rel="noopener">Bearbeiten</a></small>';
        }
        return implode(' · ', $l);
    }

    private static function bloecke_auswertung_html(): string {
        $d = self::bloecke_daten(); $namen = self::produkte(); $titel = [1 => 'Einerblöcke (ein Produkt)', 2 => 'Zweierblöcke (zwei Produkte nebeneinander)', 3 => 'Dreierblöcke', 4 => 'Viererblöcke und größer (werden beim Entzerren geteilt)'];
        $h = '<h2>Auswertung: Produktblöcke in den Artikeln</h2><form method="post" style="margin:0 0 8px">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="vorschau"><button class="button" name="sab_bloecke_neu" value="1">Neu berechnen</button> <span class="description">' . (int) $d['artikel'] . ' Artikel mit Produkten ausgewertet, Stand vor ' . max(0, (int) round((time() - (int) ($d['ts'] ?? time())) / 60)) . ' Min. Es wird nichts geändert.</span></form>';
        $h .= '<table class="widefat striped" style="max-width:760px"><thead><tr><th>Blockart</th><th>Blöcke gesamt</th><th>verschiedene Kombinationen</th><th>Artikel</th></tr></thead><tbody>';
        foreach ($d['bloecke'] as $g => $liste) {
            $ges = array_sum(array_column($liste, 'n')); $art = [];
            foreach ($liste as $b) { foreach ($b['posts'] as $id => $t) { $art[$id] = 1; } }
            $h .= '<tr><td>' . esc_html($titel[$g]) . '</td><td><strong>' . (int) $ges . '</strong></td><td>' . count($liste) . '</td><td>' . count($art) . '</td></tr>';
        }
        $h .= '<tr><td>Endblock („sm-world“)</td><td><strong>' . (int) $d['mit_end'] . '</strong></td><td>' . count($d['end']) . ' Varianten</td><td>' . (int) $d['mit_end'] . '</td></tr></tbody></table>';
        foreach ($d['bloecke'] as $g => $liste) {
            if (!$liste) { continue; }
            $h .= '<details style="margin:12px 0"' . ($g >= 3 ? ' open' : '') . '><summary><strong>' . esc_html($titel[$g]) . '</strong>: ' . count($liste) . ' Kombinationen (die häufigsten zuerst, höchstens 30)</summary>';
            $i = 0;
            foreach ($liste as $b) {
                if (++$i > 30) { break; }
                $pn = []; foreach ($b['asins'] as $a) { $pn[] = esc_html(($namen[$a] ?? '') ?: $a); }
                $h .= '<div style="border:1px solid #dcdcde;border-radius:6px;background:#fff;padding:8px 10px;margin:8px 0;max-width:1100px"><p style="margin:0 0 6px"><strong>' . (int) $b['n'] . '×</strong> in ' . count($b['posts']) . ' Artikel(n) · ' . implode(' + ', $pn ?: ['(ohne erkennbare ASIN)']) . '</p>'
                    . '<div style="max-height:240px;overflow:auto;border:1px dashed #ccd0d4;padding:6px;margin:0 0 6px">' . do_shortcode(wpautop((string) $b['html'])) . '</div>'
                    . '<p style="margin:0;font-size:12px">' . self::bloecke_artikel_links($b['posts']) . '</p></div>';
            }
            $h .= '</details>';
        }
        $h .= '<h3>Endblock-Varianten</h3>';
        if (!$d['end']) { $h .= '<p>Kein Artikel hat einen Endblock („sm-world“).</p>'; }
        $i = 0;
        foreach ($d['end'] as $b) {
            if (++$i > 12) { break; }
            $pn = []; foreach ($b['asins'] as $a) { $pn[] = esc_html(($namen[$a] ?? '') ?: $a); }
            $h .= '<div style="border:1px solid #dcdcde;border-radius:6px;background:#fff;padding:8px 10px;margin:8px 0;max-width:1100px"><p style="margin:0 0 6px"><strong>Variante ' . $i . ':</strong> ' . (int) $b['n'] . ' Artikel · ' . count($b['asins']) . ' Produkte: ' . implode(', ', $pn) . '</p>'
                . '<details><summary>Endblock so ansehen, wie er vorkommt</summary><div style="max-height:420px;overflow:auto;border:1px dashed #ccd0d4;padding:6px;margin:6px 0">' . do_shortcode((string) $b['html']) . '</div></details>'
                . '<p style="margin:6px 0 0;font-size:12px">' . self::bloecke_artikel_links($b['posts']) . '</p></div>';
        }
        return $h;
    }

    /** Vorschau mit echten Daten: jeder Slot und jedes Produkt als Karte und als Bild, so wie die Besucher es sehen. */
    private static function vorschau_html(): string {
        $h = '<p>So sehen Besucher die Karten (echte Bilder und Texte aus dem Zwischenspeicher). Läuft ein A/B-Test, zeigt die Vorschau die Variante, die dieser Seite zugeteilt wäre.</p>';
        if (get_option('sab_karten_css') !== '0') { $h .= '<style>' . self::KARTEN_CSS . '</style>'; }
        $h .= self::bloecke_auswertung_html();
        $land = self::land('');
        $slots = array_merge(self::slots(), self::tests());
        if ($slots) {
            $h .= '<h2>Slots</h2>';
            foreach (array_keys($slots) as $sn) { $h .= '<h3>' . esc_html($sn) . '</h3>' . self::sc_produkt(['slot' => (string) $sn, 'land' => $land], ''); }
        }
        $h .= '<h2>Einzelne Produkte</h2>';
        foreach (self::produkte() as $asin => $name) {
            $h .= '<h3>' . esc_html($name !== '' ? $name : $asin) . ' <small>' . esc_html($asin) . '</small></h3>'
                . self::sc_produkt(['asin' => (string) $asin, 'land' => $land], '')
                . '<p><small>Nur Bild:</small> ' . self::sc_bild(['asin' => (string) $asin, 'land' => $land, 'breite' => '120'], '') . '</p>';
        }
        return $h;
    }

    // ---------- Länder-Zuordnung: pro Produkt (Hauptstore) je Land eine ASIN, mit Freigabe ----------

    const LAND_ZIELE = ['com' => 'USA (amazon.com)', 'ca' => 'Kanada (amazon.ca)', 'com.au' => 'Australien (amazon.com.au, auch Neuseeland)'];

    private static function laender_such_url(string $land, string $text): string {
        return 'https://www.amazon.' . $land . '/s?k=' . rawurlencode(trim(preg_replace('/\s+/u', ' ', str_replace('|', ' ', $text))));
    }

    /** Eine Zeile je Vorschlag: UK-ASIN | Land | ASIN | Titel | Sterne | Anzahl | Prime (ja/nein) | Lieferung | Preis. Neue Vorschläge warten auf die Freigabe, nichts wirkt vorher. */
    private static function laender_import(string $text): string {
        $v = (array) get_option('sab_laender_vorschlag', []); $n = 0; $skip = 0;
        foreach (preg_split('/\R/', $text) as $z) {
            $t = array_map('trim', explode('|', $z)); if (count($t) < 3) { continue; }
            $uk = self::asin($t[0]); $land = self::land_schluessel($t[1]); $a = self::asin_aus_text($t[2]);
            if (strlen($uk) !== 10 || strlen($a) !== 10 || !isset(self::LAND_ZIELE[$land]) || $a === $uk) { $skip++; continue; }
            foreach ((array) ($v[$uk][$land] ?? []) as $e) { if (($e['asin'] ?? '') === $a) { continue 2; } }
            $v[$uk][$land][] = ['asin' => $a, 'titel' => mb_substr($t[3] ?? '', 0, 140), 'sterne' => $t[4] ?? '', 'anzahl' => $t[5] ?? '', 'prime' => strtolower($t[6] ?? ''), 'liefer' => mb_substr($t[7] ?? '', 0, 60), 'preis' => mb_substr($t[8] ?? '', 0, 30), 'ts' => time()];
            $n++;
        }
        update_option('sab_laender_vorschlag', $v, false);
        return $n . ' Vorschläge aufgenommen (warten auf Freigabe)' . ($skip ? ', ' . $skip . ' Zeilen übersprungen (Format oder Land unbekannt)' : '') . '.';
    }

    private static function laender_speichern(): string {
        $uk = self::asin((string) ($_POST['sab_uk'] ?? '')); $land = self::land_schluessel((string) ($_POST['sab_land'] ?? ''));
        $akt = (string) wp_unslash($_POST['sab_la'] ?? '');
        if (strlen($uk) !== 10 || !isset(self::LAND_ZIELE[$land])) { return 'Eintrag nicht gefunden.'; }
        $map = (array) get_option('sab_laender_map', []); $v = (array) get_option('sab_laender_vorschlag', []);
        if (strpos($akt, 'ok|') === 0 || strpos($akt, 'ab|') === 0) {
            $a = self::asin(substr($akt, 3)); $rest = []; $gew = null;
            foreach ((array) ($v[$uk][$land] ?? []) as $e) { if (($e['asin'] ?? '') === $a) { $gew = $e; } else { $rest[] = $e; } }
            $v[$uk][$land] = $rest; if (!$rest) { unset($v[$uk][$land]); } if (empty($v[$uk])) { unset($v[$uk]); }
            update_option('sab_laender_vorschlag', $v, false);
            if (strpos($akt, 'ok|') === 0 && $gew) { $map[$uk][$land] = $gew; update_option('sab_laender_map', $map, false); return $land . ': ' . $a . ' freigegeben. Besucher von dort bekommen diesen Link (wenn die Länder-Umschaltung an ist).'; }
            return 'Vorschlag abgelehnt.';
        }
        if ($akt === 'weg') { unset($map[$uk][$land]); if (empty($map[$uk])) { unset($map[$uk]); } update_option('sab_laender_map', $map, false); return 'Zuordnung für ' . $land . ' entfernt: dort gibt es jetzt nur die Suche.'; }
        if ($akt === 'eigen') {
            $a = self::asin_aus_text((string) wp_unslash($_POST['sab_eigen'] ?? ''));
            if (strlen($a) !== 10) { return 'Keine gültige ASIN oder kein Amazon-Produktlink erkannt (Kurzlinks wie amzn.to enthalten keine ASIN).'; }
            $map[$uk][$land] = ['asin' => $a, 'titel' => '', 'sterne' => '', 'anzahl' => '', 'prime' => '', 'liefer' => '', 'preis' => '', 'ts' => time(), 'eigen' => 1];
            update_option('sab_laender_map', $map, false);
            return $land . ': ' . $a . ' eingetragen und freigegeben. Bitte kurz bei Amazon ansehen, ob es passt.';
        }
        return '';
    }

    private static function laender_zeile(string $uk, array $x, string $name, array $map, array $vor): string {
        $such = $name !== '' ? $name : (string) ($x['titel'] ?? '');
        $sc = isset($x['titel']) ? self::score(array_merge($x, ['trotz' => false]))[0] : null;
        $prime = isset($x['prime']) && $x['prime'] !== null ? ($x['prime'] ? 'Prime ja' : 'Prime nein') : 'Prime unbekannt';
        $st = isset($x['sterne']) && $x['sterne'] !== null ? str_replace('.', ',', (string) $x['sterne']) . ' ★ (' . (int) ($x['anzahl'] ?? 0) . ')' : '';
        $h = '<div style="border:1px solid #c3c4c7;border-radius:8px;background:#fff;padding:12px 14px;margin:0 0 14px;max-width:1150px"><div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">' . self::mini_bild($uk, $x)
           . '<div style="flex:1;min-width:220px"><strong>' . esc_html($such !== '' ? mb_substr($such, 0, 80) : $uk) . '</strong> <small>(Hauptstore)</small><br><small><a href="' . esc_url('https://' . self::LAENDER[self::land('')][0] . '/dp/' . $uk) . '" target="_blank" rel="noopener"><code>' . esc_html($uk) . '</code></a>'
           . ($sc !== null ? ' · Score ' . (int) $sc : '') . ' · ' . esc_html($prime) . ($st !== '' ? ' · ' . esc_html($st) : '') . (!empty($x['preis']) ? ' · ' . esc_html((string) $x['preis']) : '') . '</small></div></div>'
           . '<div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:10px">';
        foreach (self::LAND_ZIELE as $land => $lname) {
            $m = $map[$land] ?? null; $vl = (array) ($vor[$land] ?? []);
            $h .= '<form method="post" style="flex:1 1 320px;min-width:280px;border:1px solid ' . ($m ? '#8fd19e' : '#dcdcde') . ';border-radius:6px;padding:8px 10px;background:' . ($m ? '#f3fbf5' : '#fafafa') . '">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false)
               . '<input type="hidden" name="sab_tab" value="laender"><input type="hidden" name="sab_form" value="laender"><input type="hidden" name="sab_uk" value="' . esc_attr($uk) . '"><input type="hidden" name="sab_land" value="' . esc_attr($land) . '">'
               . '<strong>' . esc_html($lname) . '</strong>';
            if ($m) {
                $h .= '<div style="margin:4px 0"><span style="color:#1a7f37;font-weight:600">freigegeben:</span> <a href="' . esc_url('https://www.amazon.' . $land . '/dp/' . $m['asin']) . '" target="_blank" rel="noopener"><code>' . esc_html($m['asin']) . '</code> ansehen ↗</a>'
                   . self::laender_details($m) . ' <button class="button button-small" name="sab_la" value="weg">entfernen</button></div>';
            } else { $h .= '<div style="margin:4px 0;color:#8a5a00">noch keine ASIN: Besucher bekommen nur die Suche in diesem Land.</div>'; }
            foreach ($vl as $e) {
                $h .= '<div style="margin:6px 0;padding:6px;background:#fff8e1;border-radius:4px"><small>Vorschlag:</small> <a href="' . esc_url('https://www.amazon.' . $land . '/dp/' . $e['asin']) . '" target="_blank" rel="noopener"><code>' . esc_html($e['asin']) . '</code> ansehen ↗</a>'
                   . ($e['titel'] !== '' ? '<br><small>' . esc_html($e['titel']) . '</small>' : '') . self::laender_details($e)
                   . '<br><button class="button button-small button-primary" name="sab_la" value="ok|' . esc_attr($e['asin']) . '">✓ freigeben</button> <button class="button button-small" name="sab_la" value="ab|' . esc_attr($e['asin']) . '">✗ ablehnen</button></div>';
            }
            $h .= '<p style="margin:6px 0"><a class="button button-small" href="' . esc_url(self::laender_such_url($land, $such)) . '" target="_blank" rel="noopener">Nach UK-Titel suchen ↗</a></p>'
               . '<p style="margin:4px 0"><input type="text" name="sab_eigen" placeholder="Eigene ASIN oder Amazon-Link" style="width:100%"> <button class="button button-small" name="sab_la" value="eigen">übernehmen</button></p></form>';
        }
        return $h . '</div></div>';
    }

    private static function laender_details(array $e): string {
        $t = [];
        if (($e['sterne'] ?? '') !== '') { $t[] = esc_html(($e['sterne']) . ' ★' . (($e['anzahl'] ?? '') !== '' ? ' (' . $e['anzahl'] . ')' : '')); }
        if (($e['prime'] ?? '') !== '') { $t[] = in_array($e['prime'], ['ja', 'yes', '1'], true) ? 'Prime ja' : 'Prime ' . esc_html($e['prime']); }
        if (($e['liefer'] ?? '') !== '') { $t[] = esc_html($e['liefer']); }
        if (($e['preis'] ?? '') !== '') { $t[] = esc_html($e['preis']); }
        return $t ? ' <small>· ' . implode(' · ', $t) . '</small>' : '';
    }

    private static function laender_html(): string {
        $map = (array) get_option('sab_laender_map', []); $vor = (array) get_option('sab_laender_vorschlag', []);
        $land = self::land(''); $d = self::uebersicht_daten($land, false); $items = (array) ($d['items'] ?? []); $namen = self::produkte();
        $h = '<h2>Länder: eigene Produkte für Besucher aus anderen Ländern</h2><p class="description">Bild, Titel und Preis kommen immer vom Hauptstore (' . esc_html(self::LAENDER[$land][0]) . '). Wer aus den USA, Kanada oder Australien/Neuseeland kommt, wird beim Klick zur freigegebenen ASIN in seinem Land geleitet, ohne ASIN zur Suche dort. Das Land erkennt der Browser aus Sprache und Zeitzone, ohne IP.</p>';
        if (get_option('sab_geo') !== '1') { $h .= '<div class="notice notice-warning inline"><p>Die Länder-Umschaltung ist aus (Einstellungen). Zuordnungen wirken erst, wenn sie an ist.</p></div>'; }
        $n_vor = 0; foreach ($vor as $l) { foreach ($l as $es) { $n_vor += count($es); } }
        $h .= '<p><strong>' . count($map) . '</strong> Produkte mit Zuordnung · <strong>' . $n_vor . '</strong> Vorschläge warten auf Freigabe</p>';
        $q = isset($_GET['q']) ? trim(sanitize_text_field(wp_unslash($_GET['q']))) : '';
        $h .= '<form method="get" style="margin:0 0 12px"><input type="hidden" name="page" value="sab-laender"><input type="search" name="q" value="' . esc_attr($q) . '" placeholder="Produkt suchen (Name oder ASIN)" style="min-width:260px"> <button class="button">Suchen</button></form>';
        $liste = [];
        foreach (self::alle_asins() as $a) {
            $a = (string) $a; if (!isset($items[$a])) { continue; }
            $nm = (string) ($namen[$a] ?? '') ?: self::kurzname_aus_titel((string) ($items[$a]['titel'] ?? ''));
            if ($q !== '' && stripos($nm . ' ' . $a . ' ' . (string) ($items[$a]['titel'] ?? ''), $q) === false) { continue; }
            $liste[] = [$a, $nm, (int) isset($vor[$a]) * 2 + (int) isset($map[$a])];
        }
        usort($liste, fn($x, $y) => [$y[2], $x[1]] <=> [$x[2], $y[1]]);   // zuerst mit offenen Vorschlägen, dann mit Zuordnung
        foreach (array_slice($liste, 0, 30) as [$a, $nm]) { $h .= self::laender_zeile($a, (array) $items[$a], $nm, (array) ($map[$a] ?? []), (array) ($vor[$a] ?? [])); }
        if (count($liste) > 30) { $h .= '<p class="description">… und ' . (count($liste) - 30) . ' weitere: oben nach Name oder ASIN suchen.</p>'; }
        $h .= '<h3 style="margin-top:2em">Vorschläge einer Mac/PC-Sitzung einfügen</h3><form method="post">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="laender"><input type="hidden" name="sab_form" value="laender_import">'
            . '<p class="description">Eine Zeile je Vorschlag: <code>UK-ASIN | Land (com, ca oder com.au) | ASIN im Land | Titel | Sterne | Anzahl Bewertungen | Prime (ja/nein) | Lieferung | Preis</code>. Die letzten Felder dürfen leer sein. Vorschläge wirken erst nach deiner Freigabe oben.</p>'
            . '<textarea name="sab_la_text" rows="5" class="large-text code"></textarea><p><button class="button">Vorschläge aufnehmen</button></p></form>';
        return $h;
    }

    /** Eingabefelder: je Shop eine Partner-ID (statt Textzeilen, die man falsch schreiben kann). */
    private static function partner_felder_html(): string {
        $t = self::tags(); $h = '<table>';
        foreach (self::PARTNER_SHOPS as $k => $n) { $h .= '<tr><td style="padding:2px 10px 2px 0">' . esc_html($n) . '</td><td><input type="text" name="sab_tag_f[' . esc_attr($k) . ']" class="regular-text code" value="' . esc_attr((string) ($t[$k] ?? '')) . '" placeholder="z. B. meinname-21"></td></tr>'; }
        return $h . '</table>';
    }

    private static function land_auswahl_html(): string {
        $akt = self::standardland_roh(); $h = '<select name="sab_default_land">';
        foreach (self::LAENDER as $k => $v) { $h .= '<option value="' . esc_attr($k) . '"' . ($k === $akt ? ' selected' : '') . '>' . esc_html($v[0]) . '</option>'; }
        return $h . '</select>';
    }

    public static function seite(): void {
        if (!current_user_can('manage_options')) { return; }
        $tabs = self::TABS;
        $seite = sanitize_key((string) ($_GET['page'] ?? ''));
        $tab = sanitize_key((string) ($_POST['sab_tab'] ?? ($_GET['tab'] ?? (strpos($seite, 'sab-') === 0 ? substr($seite, 4) : 'uebersicht'))));
        if ($tab === 'ausfaelle') { $tab = 'problem'; }   // beide Reiter sind zusammengelegt
        if (!isset($tabs[$tab])) { $tab = 'uebersicht'; }

        $meldung = '';
        $update_knopf = '';
        if (!empty($_GET['sab_update_jetzt']) && wp_verify_nonce((string) ($_GET['_wpnonce'] ?? ''), 'sab_update_jetzt')) {
            $nv = self::update_nachsehen(true);
            $meldung = $nv !== '' ? 'Neue Version ' . $nv . ' gefunden: sie steht jetzt unter Plugins zum Aktualisieren bereit.' : 'Kein Update gefunden: Installiert ist die neueste Version (' . self::VERSION . ').';
            if ($nv !== '') { $pk = plugin_basename(__FILE__); $update_knopf = ' <a class="button button-primary" href="' . esc_url(wp_nonce_url(self_admin_url('update.php?action=upgrade-plugin&plugin=' . rawurlencode($pk)), 'upgrade-plugin_' . $pk)) . '">Jetzt updaten</a> <a class="button" href="' . esc_url(admin_url('plugins.php')) . '">Zur Plugin-Seite</a>'; }
        }
        if (!empty($_POST['sab_form']) && check_admin_referer('sab_speichern')) {
            $form = sanitize_key((string) $_POST['sab_form']);
            if (!empty($_POST['sab_hinweis'])) { self::hinweis_setzen(); }   // Erklärung aus-/einblenden (Knopf im Formular des Reiters)
            if ($form === 'kontrolle_zurueck') {
                $kid = (string) ($_POST['sab_id'] ?? ''); $kk = (array) get_option('sab_kontrolle', []); $e = $kk[$kid] ?? null;
                if (!$e) { $meldung = 'Eintrag nicht gefunden.'; }
                elseif (($e['art'] ?? '') === 'stelle') {   // Tausch an einer Stelle im Frontend: Sicherung des Artikels
                    $pid = (int) ($e['pid'] ?? 0); $b = (array) get_post_meta($pid, '_sab_stelle_backup', true); $post = get_post($pid); $l = end($b);
                    if (!$post || !$l) { $meldung = 'Keine Sicherung gefunden. Bitte im Artikel unter „Versionen“ die Version vor dem Tausch laden.'; }
                    elseif (md5((string) $post->post_content) !== ($l['hash'] ?? '')) { $meldung = 'Der Artikel wurde seitdem geändert; Rückgängig ist zu riskant. Bitte im Artikel unter „Versionen“ die Version vor dem Tausch laden.'; }
                    else { wp_update_post(['ID' => $pid, 'post_content' => wp_slash((string) $l['vorher'])]); array_pop($b); update_post_meta($pid, '_sab_stelle_backup', wp_slash($b)); unset($kk[$kid]); update_option('sab_kontrolle', $kk, false); $meldung = 'Rückgängig gemacht: ' . $e['alt'] . ' steht wieder im Artikel.'; }
                } else {
                    [$ok, $skip] = self::tausch_zurueck($kid);
                    if ($ok > 0) { unset($kk[$kid]); update_option('sab_kontrolle', $kk, false); }
                    $meldung = $ok > 0 ? 'Rückgängig gemacht in ' . $ok . ' Artikel(n)' . ($skip ? ', ' . $skip . ' übersprungen, weil sie seitdem von Hand geändert wurden (dort bitte unter „Versionen“ zurückgehen).' : '.') : 'Nichts zurückgesetzt: ' . ($skip ? 'die Artikel wurden seitdem geändert (bitte unter „Versionen“ die Version vor dem Tausch laden).' : 'keine Sicherung gefunden.');
                }
            } elseif ($form === 'kartendesign') {
                $meldung = self::kd_speichern();
            } elseif ($form === 'laender') {
                $meldung = self::laender_speichern();
            } elseif ($form === 'laender_import') {
                $meldung = self::laender_import((string) wp_unslash($_POST['sab_la_text'] ?? ''));
            } elseif ($form === 'kontrolle_ok') {
                $kk = (array) get_option('sab_kontrolle', []); unset($kk[(string) ($_POST['sab_id'] ?? '')]); update_option('sab_kontrolle', $kk, false); $meldung = 'Kontrolle bestätigt.';
            } elseif ($form === 'produkte') {
                update_option('sab_produkte', sanitize_textarea_field(wp_unslash($_POST['sab_produkte'] ?? '')), false);
                update_option('sab_slots', sanitize_textarea_field(wp_unslash($_POST['sab_slots'] ?? '')), false);
                update_option('sab_tausch', sanitize_textarea_field(wp_unslash($_POST['sab_tausch'] ?? '')), false);
                delete_transient('sab_alle_asins');
                $meldung = 'Gespeichert.';
            } elseif ($form === 'ab') {
                $meldung = self::ab_speichern();
            } elseif ($form === 'ab_stil') {
                $meldung = self::stil_speichern();
            } elseif ($form === 'tests') {
                update_option('sab_tests', sanitize_textarea_field(wp_unslash($_POST['sab_tests'] ?? '')), false);
                update_option('sab_bildtests', sanitize_textarea_field(wp_unslash($_POST['sab_bildtests'] ?? '')), false);
                $meldung = 'Gespeichert.';
            } elseif ($form === 'einstellungen') {
                update_option('sab_client_id', sanitize_text_field(wp_unslash($_POST['sab_client_id'] ?? '')), false);
                $geheim = sanitize_text_field(wp_unslash($_POST['sab_client_secret'] ?? ''));
                if ($geheim !== '') { update_option('sab_client_secret', $geheim, false); } // leer = unverändert
                if (isset($_POST['sab_tag_f']) && is_array($_POST['sab_tag_f'])) {   // ein Feld je Shop; andere, früher von Hand eingetragene Länder bleiben erhalten
                    $feld = []; foreach (self::PARTNER_SHOPS as $k_ => $n_) { $v_ = trim(sanitize_text_field(wp_unslash((string) ($_POST['sab_tag_f'][$k_] ?? ''))), " \t\"'"); if ($v_ !== '') { $feld[$k_] = $v_; } }
                    $zeilen_ = []; foreach (self::tags() as $k_ => $v_) { if (!isset(self::PARTNER_SHOPS[$k_])) { $zeilen_[] = $k_ . '=' . $v_; } }
                    foreach ($feld as $k_ => $v_) { $zeilen_[] = $k_ . '=' . $v_; }
                    update_option('sab_tags', implode("\n", $zeilen_), false);
                } else { update_option('sab_tags', sanitize_textarea_field(wp_unslash($_POST['sab_tags'] ?? '')), false); }
                update_option('sab_tags_extra', sanitize_textarea_field(wp_unslash($_POST['sab_tags_extra'] ?? '')), false);
                update_option('sab_ebay_ids', sanitize_textarea_field(wp_unslash($_POST['sab_ebay_ids'] ?? '')), false);
                $dl_ = self::land_schluessel(sanitize_text_field(wp_unslash($_POST['sab_default_land'] ?? 'de'))); update_option('sab_default_land', isset(self::LAENDER[$dl_]) ? $dl_ : 'de', false);
                update_option('sab_geo', !empty($_POST['sab_geo']) ? '1' : '0', false);
                update_option('sab_hinweis_auto', !empty($_POST['sab_hinweis_auto']) ? '1' : '0', false);
                update_option('sab_karten_css', !empty($_POST['sab_karten_css']) ? '1' : '0', false);
                update_option('sab_heimat_liste', sanitize_textarea_field(wp_unslash($_POST['sab_heimat_liste'] ?? '')), false);
                update_option('sab_generische_linktexte', sanitize_textarea_field(wp_unslash($_POST['sab_generische_linktexte'] ?? '')), false);
                update_option('sab_weg_umbauen', !empty($_POST['sab_weg_umbauen']) ? '1' : '0', false);
                update_option('sab_strikt', !empty($_POST['sab_strikt']) ? '1' : '0', false);
                update_option('sab_preis_test', !empty($_POST['sab_preis_test']) ? '1' : '0', false);
                update_option('sab_btn_a', sanitize_text_field(wp_unslash($_POST['sab_btn_a'] ?? '')), false);
                update_option('sab_btn_b', sanitize_text_field(wp_unslash($_POST['sab_btn_b'] ?? '')), false);
                update_option('sab_mail_an', sanitize_email(wp_unslash($_POST['sab_mail_an'] ?? '')), false);
                update_option('sab_update_mail', !empty($_POST['sab_update_mail']) ? '1' : '0', false);
                update_option('sab_amz_seiten', sanitize_textarea_field(wp_unslash($_POST['sab_amz_seiten'] ?? '')), false);
                update_option('sab_hinweis_text', sanitize_text_field(wp_unslash($_POST['sab_hinweis_text'] ?? '')), false);
                delete_transient('sab_token_' . md5(self::LAENDER['de'][1]));
                delete_transient('sab_token_' . md5(self::LAENDER['com'][1]));
                $meldung = 'Gespeichert.';
            } elseif ($form === 'tausch_neu') {
                $regeln = self::tausch_regeln(); $neu = []; $fehler = [];
                $eingabe = (array) ($_POST['sab_neu_asin'] ?? []);
                foreach ((array) ($_POST['sab_empf'] ?? []) as $alt => $nw) { if (trim((string) ($eingabe[$alt] ?? '')) === '') { $eingabe[$alt] = $nw; } }   // angekreuzte Empfehlung, wenn nichts selbst eingetragen
                foreach ($eingabe as $alt => $nw) {
                    $alt = self::asin((string) $alt); $nw = self::asin((string) $nw);
                    if ($nw === '') { continue; }
                    if (strlen($nw) !== 10 || strlen($alt) !== 10 || $alt === $nw) { $fehler[] = $alt . ' → ' . $nw; continue; }
                    if ($dop = self::doppel_durch_tausch($alt, $nw)) { $fehler[] = $alt . ' → ' . $nw . ' (stünde doppelt in: ' . implode('; ', array_slice($dop, 0, 5)) . ')'; continue; }
                    $regeln[$alt] = $nw; $neu[] = $alt . ' => ' . $nw;
                }
                if ($neu) {
                    $zeilen = [];
                    foreach ($regeln as $a => $b) { $zeilen[] = $a . ' => ' . $b; }
                    update_option('sab_tausch', implode("\n", $zeilen), false);
                    delete_transient('sab_alle_asins');
                }
                $meldung = $neu ? count($neu) . ' Austausch-Regel(n) angelegt: ' . implode(', ', $neu) . '.' : 'Nichts eingetragen.';
                if ($fehler) { $meldung .= ' Nicht angelegt (ungültig oder Doppelung): ' . implode(', ', $fehler) . '.'; }
            } elseif ($form === 'tausch_fest') {
                $alt = self::asin((string) ($_POST['sab_alt'] ?? '')); $neu = self::asin((string) ($_POST['sab_neu'] ?? ''));
                if (!empty($_POST['sab_zurueckstellen'])) { $sk = (array) get_option('sab_tausch_skip', []); $sk[$alt] = time(); update_option('sab_tausch_skip', $sk, false); $meldung = 'Zurückgestellt: ' . $alt . '.'; }
                else {
                    $meldung = self::tausch_ausfuehren($alt, $neu, trim(sanitize_text_field(wp_unslash($_POST['sab_name'] ?? ''))));
                }
            } elseif ($form === 'asin_diag') {
                $da = trim((string) wp_unslash($_POST['sab_diag_asin'] ?? ''));
                $meldung = $da !== '' ? self::asin_rohantwort($da) : 'Bitte eine ASIN eintragen (mehrere mit Komma, höchstens 10).';
            } elseif ($form === 'ab_widget') {
                $meldung = self::ab_widget_speichern();
            } elseif ($form === 'textrest_fix') {
                $meldung = self::textrest_fix(); self::$textreste = self::textreste_finden();
            } elseif ($form === 'textreste') {
                self::$textreste = self::textreste_finden(); $meldung = self::$textreste ? count(self::$textreste) . ' Fundstelle(n) mit sichtbaren Shortcode-Resten, Liste im Umbau-Reiter unten bei den Widgets.' : 'Keine sichtbaren Shortcode-Reste gefunden.';
            } elseif ($form === 'widget_umbau') {
                $meldung = self::widget_umbau_lauf();
            } elseif ($form === 'umbau_scan') {
                $meldung = self::umbau_scan();
            } elseif ($form === 'ersatz_suchen') {
                delete_transient('sab_alle_asins'); delete_transient('sab_such_pruefung'); foreach (array_keys(self::LAENDER) as $l_) { delete_transient("sab_uebersicht_{$l_}"); }
                $meldung = 'Ersatz wird neu gesucht: Bestand und Amazon-Treffer werden neu bewertet, die Daten der Treffer holt das Plugin im Hintergrund. Oben auf der Seite steht der Prompt für Produkte, die noch zu wenig Vorschläge haben.';
            } elseif ($form === 'austausch_viele' && !empty($_POST['sab_such_anfrage'])) {
                update_option('sab_such_anfrage', gmdate('Y-m-d H:i'), false);
                $meldung = 'Angefordert. Die Mac-Sitzung sucht vor der nächsten Austauschrunde bei Amazon und trägt die Treffer hier ein.';
            } elseif ($form === 'austausch_viele') {
                $hv = self::asin((string) ($_POST['sab_hinten'] ?? '')); $vo = self::asin((string) ($_POST['sab_vorn'] ?? '')); $eb = self::asin((string) ($_POST['sab_ebay_ein'] ?? ''));
                if ($eb !== '') {
                    $meldung = self::ebay_einfuegen($eb, trim(sanitize_text_field(wp_unslash((string) ($_POST['sab_ebay_term'][$eb] ?? '')))));
                } elseif ($hv !== '' || $vo !== '') {
                    $hl = (array) get_option('sab_austausch_hinten', []);
                    if ($hv !== '') { $hl[$hv] = time(); $meldung = $hv . ' steht jetzt am Ende der Liste.'; } else { unset($hl[$vo]); $meldung = $vo . ' steht wieder in der normalen Reihenfolge.'; }
                    update_option('sab_austausch_hinten', $hl, false);
                } else {
                $m = []; $n = 0;
                $wahl = (array) ($_POST['sab_a'] ?? []); $eigen = (array) ($_POST['sab_eigen'] ?? []); $enamen = (array) ($_POST['sab_eigen_name'] ?? []);
                $nur_alt = self::asin((string) ($_POST['sab_einzeln'] ?? ''));   // „Jetzt tauschen“ an einer Karte: nur dieses Produkt
                foreach (array_keys($wahl + $eigen) as $alt) {
                    $alt0 = $alt; $eigenWahl = ($wahl[$alt] ?? '') === '__eigen';
                    if ($nur_alt !== '' && self::asin((string) $alt) !== $nur_alt) { continue; }
                    $neu = ($wahl[$alt] ?? '') === '__eigen' ? self::asin_aus_text((string) ($eigen[$alt] ?? '')) : self::asin((string) ($wahl[$alt] ?? ''));   // eigene ASIN: auch ein eingefügter Amazon-Link genügt
                    $alt = self::asin((string) $alt);
                    if (strlen($alt) !== 10 || strlen($neu) !== 10 || $alt === $neu) { continue; }
                    if (++$n > 10) { $m[] = 'Weitere beim nächsten Klick.'; break; }
                    $kn = $eigenWahl ? trim(sanitize_text_field(wp_unslash((string) ($enamen[$alt0] ?? '')))) : '';
                    if (!$eigenWahl) { $kw = $_POST['sab_kn'][$alt0][$neu] ?? ($_POST['sab_kn'][$alt][$neu] ?? ''); if (is_string($kw)) { $kn = trim(sanitize_text_field(wp_unslash($kw))); } }   // gewählter Kurzname-Vorschlag
                    self::lernen_merken($alt, $neu);
                    self::$satz_aend = null;
                    $san = (array) ($_POST['sab_satz_alt'][$alt0] ?? []); $snn = (array) ($_POST['sab_satz'][$alt0] ?? []); $paare = [];
                    foreach ($san as $ki => $sa0) {
                        $sa = (string) wp_unslash($sa0); $sn = (string) wp_unslash($snn[$ki] ?? '');
                        if ($sa === '' || $sn === '' || $sn === $sa) { continue; }
                        if (substr_count($sn, '【LINK】') === 1 && mb_strlen($sn) <= 1500) { $paare[] = [$sa, $sn]; }
                        else { $m[] = 'Satz NICHT geändert: 【LINK】 muss genau einmal vorkommen.'; }
                    }
                    self::$satz_aend = $paare ?: null;
                    $m[] = self::tausch_ausfuehren($alt, $neu, mb_substr($kn, 0, 60));
                }
                $meldung = $m ? implode(' ', $m) : 'Nichts angehakt.';
                }
            } elseif ($form === 'entzerren_los') {
                $pid = (int) ($_POST['sab_id'] ?? 0); $post = $pid ? get_post($pid) : null;
                if (!$post || md5((string) $post->post_content) !== (string) ($_POST['sab_hash'] ?? '')) { $meldung = 'Der Artikel hat sich inzwischen geändert, bitte die Vorschau neu öffnen.'; }
                else {
                    $plan = self::entzerren_plan((string) $post->post_content, (array) self::uebersicht_daten(self::land(''), false), self::produkte());
                    if (!$plan['schritte'] || $plan['neu'] === (string) $post->post_content) { $meldung = 'Nichts zu entzerren.'; }
                    elseif (self::text_ohne_bloecke((string) $post->post_content) !== self::text_ohne_bloecke($plan['neu'])) { $meldung = 'Abgebrochen: die Kontrolle (Text ohne Produktblöcke) war nicht identisch, es wurde nichts gespeichert.'; }
                    else {
                        if (get_post_meta($pid, '_sab_entzerren_backup', true) === '') { update_post_meta($pid, '_sab_entzerren_backup', wp_slash((string) $post->post_content)); }
                        wp_update_post(['ID' => $pid, 'post_content' => wp_slash($plan['neu'])]);
                        $meldung = count($plan['schritte']) . ' Block/Blöcke verschoben. Bitte den Artikel ansehen; Rückgängig steht in der Vorschau.';
                    }
                }
            } elseif ($form === 'wied_los') {
                $pid = (int) ($_POST['sab_id'] ?? 0); $post = $pid ? get_post($pid) : null; $alt = self::asin((string) ($_POST['sab_alt'] ?? '')); $neu = self::asin((string) ($_POST['sab_neu'] ?? ''));
                if (!$post || !current_user_can('edit_post', $pid) || strlen($alt) !== 10 || strlen($neu) !== 10) { $meldung = 'Ungültige Angaben.'; }
                elseif (md5((string) $post->post_content) !== (string) ($_POST['sab_hash'] ?? '')) { $meldung = 'Der Artikel hat sich inzwischen geändert, bitte die Vorschau neu öffnen.'; }
                elseif (!self::neues_produkt_vorladen($neu)) { $meldung = 'Amazon liefert zu ' . $neu . ' gerade kein Bild oder keine Daten. Nicht getauscht.'; }
                else {
                    $namen = self::produkte(); [$c2, $ok] = self::wiederholung_ersetzen((string) $post->post_content, $alt, $neu, [(string) ($namen[$alt] ?? '')], (string) ($namen[$neu] ?? ''));
                    if (!$ok) { $meldung = 'Nichts geändert (keine passende Stelle oder es würde eine neue Doppelung entstehen).'; }
                    else {
                        $b = (array) get_post_meta($pid, '_sab_wied_backup', true);
                        $b = array_slice(array_merge($b, [['ts' => time(), 'hash' => md5($c2), 'vorher' => (string) $post->post_content, 'alt' => $alt, 'neu' => $neu]]), -5);
                        update_post_meta($pid, '_sab_wied_backup', wp_slash($b));
                        wp_update_post(['ID' => $pid, 'post_content' => wp_slash($c2)]); delete_transient('sab_alle_asins');
                        $meldung = 'Die zweite Stelle von ' . $alt . ' ist jetzt ' . $neu . '. Rückgängig steht unten in der Vorschau; bitte den Artikel ansehen.';
                    }
                }
            } elseif ($form === 'wied_zurueck') {
                $pid = (int) ($_POST['sab_id'] ?? 0); $post = $pid ? get_post($pid) : null; $b = $pid ? (array) get_post_meta($pid, '_sab_wied_backup', true) : []; $l = end($b);
                if (!$post || !current_user_can('edit_post', $pid) || !$l) { $meldung = 'Keine Sicherung vorhanden.'; }
                elseif (md5((string) $post->post_content) !== ($l['hash'] ?? '')) { $meldung = 'Der Artikel wurde seitdem geändert; Rückgängig ist zu riskant. Bitte unter „Versionen“ zurückgehen.'; }
                else { wp_update_post(['ID' => $pid, 'post_content' => wp_slash((string) $l['vorher'])]); array_pop($b); update_post_meta($pid, '_sab_wied_backup', wp_slash($b)); delete_transient('sab_alle_asins'); $meldung = 'Rückgängig gemacht: ' . ($l['alt'] ?? '') . ' steht wieder an der zweiten Stelle.'; }
            } elseif ($form === 'entzerren_zurueck') {
                $pid = (int) ($_POST['sab_id'] ?? 0); $alt = $pid ? get_post_meta($pid, '_sab_entzerren_backup', true) : '';
                if ($alt !== '' && $alt !== false) { wp_update_post(['ID' => $pid, 'post_content' => wp_slash($alt)]); delete_post_meta($pid, '_sab_entzerren_backup'); $meldung = 'Entzerren rückgängig gemacht.'; } else { $meldung = 'Keine Sicherung vorhanden.'; }
            } elseif ($form === 'amz_alt') {
                update_option('sab_amz_alt', sanitize_textarea_field(wp_unslash($_POST['sab_amz_alt'] ?? '')), false); delete_transient('sab_alle_asins');
                $meldung = 'Alternativen gespeichert.';
            } elseif ($form === 'such_kand') {
                update_option('sab_such_kand', sanitize_textarea_field(wp_unslash($_POST['sab_such_kand'] ?? '')), false); delete_transient('sab_alle_asins'); foreach (array_keys(self::LAENDER) as $l_) { delete_transient("sab_uebersicht_{$l_}"); }   // neue ASINs sofort im Hintergrund abfragen
                $meldung = 'Suchtreffer gespeichert.';
            } elseif ($form === 'auch_gekauft') {
                update_option('sab_auch_gekauft', sanitize_textarea_field(wp_unslash($_POST['sab_auch_gekauft'] ?? '')), false); delete_transient('sab_alle_asins'); foreach (array_keys(self::LAENDER) as $l_) { delete_transient("sab_uebersicht_{$l_}"); }
                $meldung = 'Mitgekauftes gespeichert.';
            } elseif ($form === 'auch_uebernehmen') {
                $dd = self::uebersicht_daten(self::land(''), false); $nn = 0; $prod = self::produkte(); $txt = rtrim((string) get_option('sab_produkte', ''));
                foreach ((array) ($_POST['sab_auch_neu'] ?? []) as $a) { $a = self::asin((string) $a); if (strlen($a) !== 10 || isset($prod[$a])) { continue; } $txt .= "\n" . $a . ' | ' . self::kurzname_aus_titel((string) ($dd['items'][$a]['titel'] ?? '')); $nn++; }
                update_option('sab_produkte', ltrim($txt), false); delete_transient('sab_alle_asins');
                $meldung = $nn . ' Produkte in die Produktliste aufgenommen (Kurznamen bitte prüfen).';
            } elseif ($form === 'austausch_auto') {
                update_option('sab_auto_tausch', !empty($_POST['sab_auto_tausch']) ? '1' : '0', false);
                update_option('sab_auto_schwelle', max(0, min(100, (int) ($_POST['sab_auto_schwelle'] ?? 55))), false);
                $meldung = 'Automatik gespeichert: ' . (!empty($_POST['sab_auto_tausch']) ? 'an' : 'aus') . '.';
            } elseif ($form === 'auto_log') {
                if (!empty($_POST['auto_leeren'])) { update_option('sab_auto_log', [], false); $meldung = 'Liste geleert. Die Tausche bleiben bestehen.'; }
                elseif (!empty($_POST['auto_zurueck'])) {
                    $alt = self::asin((string) $_POST['auto_zurueck']); $zeilen = [];
                    foreach (self::tausch_regeln() as $a => $b) { if ($a !== $alt) { $zeilen[] = $a . ' => ' . $b; } }
                    update_option('sab_tausch', implode("\n", $zeilen), false); delete_transient('sab_alle_asins');
                    $meldung = 'Rückgängig: ' . $alt . ' erscheint wieder.';
                }
            } elseif ($form === 'ids_uebernehmen') {
                $sc = self::ids_scan(); $am = []; $eb = [];
                $a0 = array_values(self::tags()); foreach (preg_split('/\R/', (string) get_option('sab_tags_extra', '')) as $z) { $z = trim(preg_split('/[\s|=,]+/', trim($z))[0] ?? ''); if ($z !== '') { $a0[] = $z; } }
                $e0 = []; foreach (preg_split('/\R/', (string) get_option('sab_ebay_ids', '')) as $z) { $z = trim(preg_split('/[\s|=,]+/', trim($z))[0] ?? ''); if ($z !== '') { $e0[] = $z; } }
                foreach ($sc['amazon'] as $id => $d) { if (!in_array((string) $id, $a0, true)) { $am[] = $id . ' (' . $d['stellen'] . ' Stellen)'; } }
                foreach ($sc['ebay'] as $id => $d) { if (!in_array((string) $id, $e0, true)) { $eb[] = $id . ' (' . $d['stellen'] . ' Stellen)'; } }
                if ($am) { update_option('sab_tags_extra', trim((string) get_option('sab_tags_extra', '') . "\n" . implode("\n", $am)), false); }
                if ($eb) { update_option('sab_ebay_ids', trim((string) get_option('sab_ebay_ids', '') . "\n" . implode("\n", $eb)), false); }
                $meldung = count($am) + count($eb) . ' IDs übernommen.';
            } elseif ($form === 'umbau_viele') {
                $ids = array_slice(array_unique(array_map('intval', (array) ($_POST['sab_ids'] ?? []))), 0, self::UMBAU_MAX);
                self::$umbau_abw = []; self::$umbau_info = []; $ok = 0; $links = 0; $uebersprungen = []; $t_u = microtime(true); $nicht_dran = 0;
                foreach ($ids as $pid) {
                    if ($pid < 1) { continue; }
                    if (microtime(true) - $t_u > 40) { $nicht_dran++; continue; }   // Zeitgrenze: Rest beim nächsten Klick
                    $abw0 = count(self::$umbau_abw);
                    try { [$n, $sk, $gea] = self::umbau_artikel($pid); }
                    catch (\Throwable $e) { self::$umbau_abw[] = get_the_title($pid) . ': Umbau abgebrochen (' . $e->getMessage() . '), Artikel unverändert'; $n = 0; $sk = 0; $gea = 0; }
                    if (count(self::$umbau_abw) > $abw0) { $uebersprungen[] = (string) get_the_title($pid); }
                    $ok += $gea; $links += $n;
                }
                $meldung = $ok . ' Artikel umgebaut (' . $links . ' Links bzw. Blöcke).' . ($nicht_dran ? ' ' . $nicht_dran . ' Artikel blieben wegen der Zeitgrenze offen, bitte erneut markieren.' : '') . ($uebersprungen ? ' Mit Hinweis, bitte ansehen: ' . implode('; ', array_slice($uebersprungen, 0, 6)) . '.' : '');
            } elseif ($form === 'umbau_ok' || $form === 'umbau_ok_weg') {
                $k = sanitize_text_field(wp_unslash($_POST['sab_key'] ?? '')); $okl = (array) get_option('sab_umbau_ok', []);
                if ($k !== '' && preg_match('/^(titel|dupl|weg):[A-Za-z0-9,:]+$/', $k)) {
                    $okl = array_values(array_diff($okl, [$k]));
                    if ($form === 'umbau_ok') { $okl[] = $k; }
                    update_option('sab_umbau_ok', $okl, false);
                    $meldung = $form === 'umbau_ok' ? 'Gemerkt: Diese Abweichung ist gewollt und wird beim Umbau nicht mehr beanstandet.' : 'Die Abweichung wird wieder geprüft.';
                }
            } elseif ($form === 'kurzlink_setzen') {
                $meldung = self::kurzlink_setzen((int) ($_POST['sab_id'] ?? 0), esc_url_raw(html_entity_decode((string) wp_unslash($_POST['sab_kurz'] ?? ''))), sanitize_text_field(wp_unslash($_POST['sab_asin'] ?? '')));
            } elseif ($form === 'bilder_nachladen') {
                $meldung = self::bilder_nachladen();
            } elseif ($form === 'kurzlink_ok') {
                self::kurz_ok_setzen(esc_url_raw(html_entity_decode((string) wp_unslash($_POST['sab_kurz'] ?? ''))), empty($_POST['sab_aus']));
                $meldung = empty($_POST['sab_aus']) ? 'Gemerkt: Dieser Kurzlink bleibt so und zählt nicht mehr als Problem.' : 'Der Kurzlink wird wieder geprüft.';
            } elseif ($form === 'claude_empf') {
                update_option('sab_claude_empf', sanitize_textarea_field(wp_unslash($_POST['sab_claude_empf'] ?? '')), false);
                $meldung = 'Empfehlungen gespeichert.';
            } elseif ($form === 'tausch_zurueck') {
                [$ok, $sk] = self::tausch_zurueck(sanitize_text_field(wp_unslash($_POST['sab_id'] ?? '')));
                $meldung = $ok . ' Artikel zurückgesetzt' . ($sk ? ', ' . $sk . ' übersprungen (seitdem von Hand geändert)' : '') . '.';
            } elseif ($form === 'provision') {
                update_option('sab_provision', sanitize_textarea_field(wp_unslash($_POST['sab_provision'] ?? '')), false);
                $meldung = 'Provisionen gespeichert.';
            } elseif ($form === 'katgroesse') {
                update_option('sab_kat_groesse', sanitize_textarea_field(wp_unslash($_POST['sab_kat_groesse'] ?? '')), false);
                $meldung = 'Kategoriegrößen gespeichert.';
            } elseif ($form === 'manuell') {
                update_option('sab_manuell', sanitize_textarea_field(wp_unslash($_POST['sab_manuell'] ?? '')), false);
                $meldung = 'Handdaten gespeichert.';
            } elseif ($form === 'ersatz') {
                update_option('sab_ersatz', sanitize_textarea_field(wp_unslash($_POST['sab_ersatz'] ?? '')), false); delete_transient('sab_alle_asins'); foreach (array_keys(self::LAENDER) as $l_) { delete_transient("sab_uebersicht_{$l_}"); }
                $meldung = 'Ersatz-Datenbank gespeichert (' . count(self::ersatz_liste()) . ' Einträge).';
            } elseif ($form === 'lernen_import') {
                [, $meldung] = self::lern_import((string) wp_unslash($_POST['sab_lern_json'] ?? ''));
            } elseif ($form === 'lernen_repo') {
                update_option('sab_lern_repo', !empty($_POST['sab_lern_repo']) ? 1 : 0, false);
                $meldung = !empty($_POST['sab_jetzt']) ? self::lern_abruf(true) : 'Einstellung gespeichert.';
            } elseif ($form === 'lernen_reset') {
                delete_option('sab_lern_w'); delete_option('sab_lern_sim'); $meldung = 'Gewichte zurückgesetzt.';
            } elseif ($form === 'lernen_ende') {
                delete_option('sab_lern_sim'); $meldung = 'Simulation beendet.';
            } elseif ($form === 'lernen_start') {
                $d = (array) self::uebersicht_daten(self::land(''), false); $trotz = (array) get_option('sab_trotz', []); $as = array_values(array_filter(self::alle_asins(), fn($a) => isset($d['items'][$a]))); shuffle($as); $gef = false;
                foreach (array_slice($as, 0, 12) as $alt) {
                    $fund = self::fundstellen([$alt]); $k = self::austausch_kandidaten($alt, self::anzeige_name($alt, $d, $fund), $d, $trotz, $fund, 12)['liste']; $l = [];
                    foreach ($k as $c) { if (!empty($c['x']) && !empty($c['f'])) { $l[] = ['asin' => $c['asin'], 'f' => $c['f'], 'lern' => (float) $c['lern'], 'score' => (int) ($c['score'] ?? 0)]; } }
                    if (count($l) >= 3) { usort($l, fn($p, $q) => $q['lern'] <=> $p['lern']); update_option('sab_lern_sim', ['alt' => $alt, 'liste' => $l, 'pos' => 0], false); $gef = true; break; }
                }
                $meldung = $gef ? '' : 'Kein Produkt mit mindestens drei Vorschlägen gefunden. Bitte erst den Ersatzpool füllen oder später noch einmal versuchen.';
            } elseif ($form === 'lernen_passt') {
                $sim = (array) get_option('sab_lern_sim', []); $r = (int) ($_POST['sab_rang'] ?? -1); $l = (array) ($sim['liste'] ?? []);
                if (!isset($l[$r])) { $meldung = 'Keine laufende Simulation.'; }
                else {
                    $ld = self::lern_daten(); $abg = $r > 0 ? array_column(array_slice($l, 0, $r), 'f') : array_column(array_slice($l, 1, 2), 'f');
                    $w2 = self::lern_schritt($ld['w'], (array) $l[$r]['f'], $abg, $r > 0 ? self::LERN_RATE : self::LERN_RATE / 2);
                    $log = $ld['log']; $log[] = ['t' => gmdate('Y-m-d'), 'p' => $r + 1];
                    self::lern_speichern($w2, $ld['runden'] + 1, $log); delete_option('sab_lern_sim');
                    $meldung = 'Gemerkt (Platz ' . ($r + 1) . ' gewählt). Das Plugin hat seine Gewichte angepasst.';
                }
            } elseif ($form === 'lernen_keiner') {
                $sim = (array) get_option('sab_lern_sim', []); $pos = (int) ($_POST['sab_pos'] ?? 0); $l = (array) ($sim['liste'] ?? []);
                if ($l) {
                    $ld = self::lern_daten(); $w2 = self::lern_ablehnen($ld['w'], array_column(array_slice($l, $pos, 4), 'f'));
                    if ($pos + 4 >= count($l)) { $log = $ld['log']; $log[] = ['t' => gmdate('Y-m-d'), 'p' => 0]; self::lern_speichern($w2, $ld['runden'] + 1, $log); delete_option('sab_lern_sim'); $meldung = 'Keiner passte; das ist gemerkt.'; }
                    else { $sim['pos'] = $pos + 4; update_option('sab_lern_sim', $sim, false); self::lern_speichern($w2, $ld['runden'], $ld['log']); }
                }
            } elseif ($form === 'ersatz_pruef') {
                [$txt, $ch] = self::ersatz_status_setzen((string) get_option('sab_ersatz', ''), sanitize_text_field(wp_unslash($_POST['sab_art'] ?? '')), self::land_schluessel((string) ($_POST['sab_land'] ?? '')), self::asin((string) ($_POST['sab_asin'] ?? '')), ($_POST['sab_status'] ?? '') === 'geprüft' ? 'geprüft' : 'neu');
                if ($ch) { update_option('sab_ersatz', $txt, false); } $meldung = $ch ? 'Status gespeichert.' : 'Eintrag nicht gefunden.';
            } elseif ($form === 'dringlichkeit') {
                if (!empty($_POST['sab_dr_std'])) { delete_option('sab_dringlichkeit'); $meldung = 'Dringlichkeit auf den Standard zurückgesetzt.'; }
                else {
                    $o = [];
                    foreach (array_keys(self::DRINGLICHKEIT_STANDARD) as $k) { $v = str_replace(',', '.', (string) wp_unslash($_POST['sab_dr'][$k] ?? '')); if (is_numeric($v)) { $o[$k] = max(0, min(1000, (float) $v)); } }
                    update_option('sab_dringlichkeit', $o, false);
                    $meldung = 'Dringlichkeit gespeichert. Die Reihenfolge in Übersicht und Austausch ist sofort neu berechnet.';
                }
            } elseif ($form === 'score') {
                if (!empty($_POST['sab_score_std'])) { delete_option('sab_score_w'); $meldung = 'Gewichte auf den Standard zurückgesetzt.'; }
                else {
                    $w = [];
                    foreach (array_keys(self::SCORE_STANDARD) as $k) { $w[$k] = max(0, min(1000000, (int) ($_POST['sab_w'][$k] ?? 0))); }
                    update_option('sab_score_w', $w, false);
                    $meldung = 'Gewichte gespeichert. Die Scores in allen Listen sind sofort neu berechnet.';
                }
            } elseif ($form === 'gruppen') {
                update_option('sab_gruppen', sanitize_textarea_field(wp_unslash($_POST['sab_gruppen'] ?? '')), false);
                delete_transient('sab_alle_asins');
                $meldung = 'Gruppen gespeichert.';
            } elseif ($form === 'marken' && !empty($_POST['sab_orphans'])) {
                $kand = array_values(array_filter(array_map([__CLASS__, 'asin'], explode(',', (string) ($_POST['sab_orphan_liste'] ?? '')))));
                $fund = self::fundstellen($kand);
                $weg = array_values(array_filter($kand, fn($a) => empty($fund[$a])));   // nur, was wirklich in keinem Artikel steht
                $meldung = self::verwaiste_entfernen($weg) . ' verwaiste Produkte entfernt.';
            } elseif ($form === 'marken' && !empty($_POST['sab_markieren'])) {
                $gezeigt = array_filter(array_map([__CLASS__, 'asin'], explode(',', (string) ($_POST['sab_zeile_liste'] ?? ''))));   // nur die angezeigte Seite überschreiben, Markierungen der anderen Seiten bleiben
                $behalte = fn($opt) => array_diff_key((array) get_option($opt, []), array_flip($gezeigt));
                $m = $behalte('sab_marken');
                foreach ((array) ($_POST['sab_fav'] ?? []) as $a => $x) { $a = self::asin((string) $a); if ($a) { $m[$a] = 'fav'; } }
                foreach ((array) ($_POST['sab_ers'] ?? []) as $a => $x) { $a = self::asin((string) $a); if ($a) { $m[$a] = 'ers'; } } // „Ersetzen“ gewinnt
                update_option('sab_marken', $m, false);
                $tr = $behalte('sab_trotz');
                foreach ((array) ($_POST['sab_trotz'] ?? []) as $a => $x) { $a = self::asin((string) $a); if ($a) { $tr[$a] = 1; } }
                update_option('sab_trotz', $tr, false);
                $bo = $behalte('sab_bonus');
                foreach ((array) ($_POST['sab_bonus'] ?? []) as $a => $x) { $a = self::asin((string) $a); $n = (int) $x; if ($a && $n !== 0) { $bo[$a] = max(-100, min(100, $n)); } }
                update_option('sab_bonus', $bo, false);
                delete_transient('sab_alle_asins');
                $meldung = 'Markierungen gespeichert.';
            } elseif ($form === 'umbau' && !empty($_POST['sab_bestaetigt'])) {
                $meldung = self::umbau_lauf(self::asin((string) ($_POST['sab_nur'] ?? '')));
            } elseif ($form === 'umbau_einzeln') {
                self::$umbau_abw = []; self::$umbau_info = [];
                try { [$n, $sk, $gea] = self::umbau_artikel((int) ($_POST['sab_id'] ?? 0)); }
                catch (\Throwable $e) { self::$umbau_abw[] = 'Umbau abgebrochen (' . $e->getMessage() . '), Artikel unverändert'; $n = 0; $sk = 0; $gea = 0; }
                $meldung = ($gea ? $n . ' Link(s) in diesem Artikel umgebaut. Bitte den Artikel ansehen; Rückgängig geht in der Liste.' : 'Nichts umgebaut' . ($sk ? ' (' . $sk . ' übersprungen: ' . (self::$skip_gruende ? implode(' | ', array_slice(array_unique(self::$skip_gruende), 0, 6)) : 'Grund unbekannt') . ')' : '') . '.')
                    . (self::$umbau_info ? ' Nach dem Bild angepasst: ' . implode(' | ', array_slice(array_unique(self::$umbau_info), 0, 8)) . '.' : '')
                    . (self::$umbau_abw ? ' NICHT umgebaut wegen Abweichung: ' . implode(' | ', array_slice(array_unique(self::$umbau_abw), 0, 8)) . '.' : '');
            } elseif ($form === 'umbau_problemlos_start' && !empty($_POST['sab_bestaetigt'])) {
                $meldung = self::umbau_problemlos_schritt(true);
            } elseif ($form === 'umbau_problemlos_weiter') {
                $meldung = self::umbau_problemlos_schritt(false);
            } elseif ($form === 'umbau_zurueck_einzeln') {
                $meldung = self::umbau_zurueck_artikel((int) ($_POST['sab_id'] ?? 0));
            } elseif ($form === 'umbau_zurueck' && !empty($_POST['sab_bestaetigt'])) {
                $meldung = self::umbau_zurueck();
            } elseif ($form === 'jetzt') {
                self::pruefen();
                $meldung = 'Prüfung durchgeführt.';
            }
        }

        echo '<style>' . self::ADMIN_MOBIL_CSS . '</style><script>document.addEventListener("DOMContentLoaded",function(){document.querySelectorAll(".sab-admin table").forEach(function(t){var h=[];t.querySelectorAll("thead th").forEach(function(x){h.push(x.textContent.trim())});if(!h.length)return;t.querySelectorAll("tbody tr").forEach(function(r){var c=r.children;if(c.length!==h.length)return;for(var i=0;i<c.length;i++){if(h[i]&&!c[i].hasAttribute("data-l"))c[i].setAttribute("data-l",h[i])}})})});</script>';
        echo '<div class="wrap sab-admin"><h1>' . esc_html(self::NAME) . ' <small style="font-size:12px;color:#666">Version ' . esc_html(self::VERSION) . '</small></h1>';
        if ($meldung) { echo '<div class="notice notice-success"><p>' . esc_html($meldung) . ($update_knopf ?? '') . '</p></div>'; }
        echo self::kontrolle_html($tab);
        echo '<h2 class="nav-tab-wrapper">';
        foreach ($tabs as $k => $name) {
            if ($k === 'produkte' && $tab !== 'produkte') { continue; }
            echo '<a class="nav-tab' . ($k === $tab ? ' nav-tab-active' : '') . '" href="' . esc_url('admin.php?page=' . ($k === 'uebersicht' ? 'sab' : 'sab-' . $k)) . '">' . esc_html($name) . '</a>';
        }
        echo '</h2>';

        if ($tab === 'uebersicht') {
            echo '<form method="post">'; wp_nonce_field('sab_speichern');
            echo '<input type="hidden" name="sab_tab" value="uebersicht"><input type="hidden" name="sab_form" value="marken">';
            echo self::uebersicht_html() . '</form>';
        } elseif ($tab === 'produkte') {
            echo '<form method="post">'; wp_nonce_field('sab_speichern');
            echo '<input type="hidden" name="sab_tab" value="produkte"><input type="hidden" name="sab_form" value="produkte">'
               . '<h2>Produkte</h2><p>Pro Zeile: <code>ASIN | Kurzname</code>. Der Kurzname wird angezeigt (Amazons Titel sind sehr lang).</p>'
               . '<textarea name="sab_produkte" rows="8" class="large-text code">' . esc_textarea((string) get_option('sab_produkte', '')) . '</textarea>'
               . '<h2>Ersatzlisten</h2><p>Pro Zeile: <code>name: ASIN1, ASIN2, ASIN3</code>. Die erste ASIN wird gezeigt, fällt sie aus, die nächste. Favoriten (Reiter Übersicht) rücken nach vorn, „Ersetzen“-Produkte ans Ende. Im Beitrag: <code>[amz_produkt slot="name"]</code></p>'
               . '<textarea name="sab_slots" rows="6" class="large-text code">' . esc_textarea((string) get_option('sab_slots', '')) . '</textarea>'
               . '<h2>Austausch-Regeln (seitenweit)</h2><p>Pro Zeile: <code>ALTE-ASIN =&gt; NEUE-ASIN</code>. Wirkt sofort in allen Artikeln, in denen das Produkt per <code>[amz_bild]</code>, <code>[amz_link]</code> oder Ersatzliste steht. Kein Suchen und Ersetzen in den Texten nötig. Regel löschen = alter Zustand.</p>'
               . '<textarea name="sab_tausch" rows="4" class="large-text code" placeholder="B08BCFRPH5 =&gt; B0CNPW8Y97">' . esc_textarea((string) get_option('sab_tausch', '')) . '</textarea>'
               . '<p><button class="button button-primary">Speichern</button></p></form>';
        } elseif ($tab === 'statistik') {
            echo self::ab_statistik_html();
            echo self::statistik_html();
        } elseif ($tab === 'gruppen') {
            echo self::gruppen_html();
        } elseif ($tab === 'score') {
            echo self::score_html();
        } elseif ($tab === 'austausch') {
            echo self::austausch_html();
        } elseif ($tab === 'laender') {
            echo self::laender_html();
        } elseif ($tab === 'lernen') {
            echo self::lernen_html();
        } elseif ($tab === 'kartendesign') {
            echo self::kd_html();
        } elseif ($tab === 'scan') {
            echo self::scan_html();
        } elseif ($tab === 'helfer') {
            echo self::austausch_html(true);
        } elseif ($tab === 'problem') {
            echo self::ausfall_html() . '<hr style="margin:2em 0">' . self::problem_html() . self::asin_diag_html() . self::aawp_reste_html();
        } elseif ($tab === 'umbau') {
            $eid = (int) ($_GET['entzerren'] ?? ($_POST['sab_id'] ?? 0));
            if (!empty($_GET['entzerren']) || (isset($_POST['sab_form']) && in_array($_POST['sab_form'], ['entzerren_los', 'entzerren_zurueck'], true))) { echo '<div id="sab-entzerren" style="border:2px solid #2271b1;padding:8px 16px;margin:12px 0;background:#fff">' . self::entzerren_vorschau_html($eid) . '</div>'; }
            if ((!empty($_GET['wied']) && !empty($_GET['wasin'])) || (isset($_POST['sab_form']) && in_array($_POST['sab_form'], ['wied_los', 'wied_zurueck'], true))) {
                $wp_ = (int) ($_GET['wied'] ?? ($_POST['sab_id'] ?? 0)); $wa_ = self::asin((string) ($_GET['wasin'] ?? ($_POST['sab_alt'] ?? '')));
                if (!$wa_ && $wp_ && ($lw_ = (array) get_post_meta($wp_, '_sab_wied_backup', true)) && ($le_ = end($lw_))) { $wa_ = (string) ($le_['alt'] ?? ''); }
                echo '<div id="sab-wied" style="border:2px solid #2271b1;padding:8px 16px;margin:12px 0;background:#fff">' . self::wiederholung_html($wp_, $wa_) . '</div>';
            }
            $liste = self::umbau_artikelliste(); $nf = 0; $nk = 0; $nfertig = 0; $nteil = 0;
            foreach ($liste as $a) { $nf += $a['fest']; $nk += $a['kurz']; $nfertig += $a['fertig'] ? 1 : 0; $nteil += !empty($a['teil']) ? 1 : 0; }
            echo '<h2>Umbau: feste Amazon-Links durch Plugin-Platzhalter ersetzen</h2>' . self::scan_knopf_html('umbau') . self::umbau_widgets_html() . self::umbau_textreste_html()
               . '<ol style="max-width:760px"><li><strong>Vorschau ansehen:</strong> Pro Artikel siehst du, welche Links wie umgebaut würden. Es wird nichts gespeichert.</li>'
               . '<li><strong>Umbauen:</strong> Der Artikel wird gesichert (Original in der Sicherung, dazu eine WordPress-Revision) und die Links werden zu <code>[amz_bild]</code> / <code>[amz_link]</code>. Der alte Code bleibt als Rückfall zwischen den Tags.</li>'
               . '<li><strong>Danach</strong> lassen sich Produkte seitenweit tauschen (Reiter „Problemprodukte“), und der Artikel zählt für Statistik und A/B-Tests. <strong>Rückgängig</strong> geht pro Artikel.</li></ol>'
               . '<p>Tipp: Zuerst <strong>einen einzelnen Artikel</strong> umbauen und im Browser ansehen. Wenn alles stimmt, unten „Alle auf einmal“.</p>'
               . '<p>Gefunden: <strong>' . count($liste) . '</strong> Artikel (Beiträge, Seiten und Entwürfe) mit Amazon-Links: <strong>' . ($nfertig) . '</strong> vollständig umgebaut, <strong>' . (count($liste) - $nfertig) . '</strong> noch offen' . ($nteil ? ' (davon ' . $nteil . ' teilweise umgebaut, es sind noch Links übrig)' : '') . '. Offen sind noch <strong>' . $nf . '</strong> feste Produkt-Links und <strong>' . $nk . '</strong> Kurzlinks (amzn.to).</p>';
            echo self::umbau_lauf_html(empty($_GET['alle_vorschau']) && empty($_POST['sab_alle_vorschau']) && empty($_GET['vorschau']) && empty($_POST['sab_vorschau']));
            if ($nfertig < count($liste)) {
                echo '<form method="post" style="margin:12px 0;padding:10px 16px;background:#fff;border:1px solid #c3c4c7;max-width:1100px">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false)
                   . '<input type="hidden" name="sab_tab" value="umbau"><input type="hidden" name="sab_form" value="umbau_problemlos_start"><p style="margin:0 0 6px"><strong>Stufe 1: Alle Artikel ohne Problem auf einmal umbauen.</strong> Das Plugin prüft jeden offenen Artikel wie die Vorschau und baut nur die um, bei denen nichts zu beanstanden ist (jeweils mit Sicherung). Artikel mit Problem, etwa mit einem Produkt, das Amazon nicht mehr kennt, bleiben unberührt und stehen danach getrennt am Ende. Es läuft in Schritten von selbst durch.</p>'
                   . '<p style="margin:0 0 8px"><label><input type="checkbox" name="sab_bestaetigt" value="1" required> Ja, alle Artikel ohne Problem jetzt umbauen</label></p><button class="button button-primary button-hero">Alle problemlosen umbauen</button></form>';
            }
            echo '<p><a class="button button-primary" href="' . esc_url(add_query_arg(['page' => 'sab-umbau', 'alle_vorschau' => 1, 'ab' => 0], admin_url('admin.php'))) . '">Vorschau für alle offenen Artikel (Probleme sehen, dann mehrere auf einmal umbauen)</a></p>';
            if (!empty($_GET['alle_vorschau']) || !empty($_POST['sab_alle_vorschau'])) { echo self::umbau_uebersicht_html((int) ($_GET['ab'] ?? ($_POST['sab_ab'] ?? 0))); }
            $vid = (int) ($_GET['vorschau'] ?? ($_POST['sab_vorschau'] ?? 0));
            if ($vid) { echo self::umbau_vorschau($vid); }
            if ($liste) {
                $zeile = function (array $a): void {
                    $vu = add_query_arg(['page' => 'sab-umbau', 'vorschau' => $a['id']], admin_url('admin.php'));
                    echo '<tr><td>' . ($a['fertig'] ? '' : '<input type="checkbox" name="sab_ids[]" form="umbau-sammel" value="' . (int) $a['id'] . '" class="sab-wahl">') . '</td><td><a href="' . esc_url((string) get_permalink($a['id'])) . '" target="_blank" rel="noopener">' . esc_html($a['titel'] ?: ('Beitrag ' . $a['id'])) . '</a> <small><a href="' . esc_url(admin_url('post.php?post=' . (int) $a['id'] . '&action=edit')) . '" target="_blank" rel="noopener">Bearbeiten ↗</a></small>' . ($a['fertig'] ? '' : self::umbau_links_html((int) $a['id'])) . '</td><td data-v="' . (int) $a['fest'] . '">' . (int) $a['fest'] . '</td><td data-v="' . (int) $a['kurz'] . '">' . (int) $a['kurz'] . '</td>'
                       . '<td>' . ($a['fertig'] ? '<strong style="color:#1a7f37">umgebaut</strong>' : (!empty($a['teil']) ? '<strong style="color:#b36b00">teilweise umgebaut</strong>' : 'offen')) . ($a['status'] !== 'publish' && $a['status'] !== '' ? ' <small>(' . esc_html(['draft' => 'Entwurf', 'private' => 'privat', 'pending' => 'zur Review', 'future' => 'geplant'][$a['status']] ?? $a['status']) . ')</small>' : '') . '</td><td><a class="button" href="' . esc_url($vu) . '">Vorschau</a> ';
                    echo '<form method="post" style="display:inline">'; wp_nonce_field('sab_speichern');
                    echo '<input type="hidden" name="sab_tab" value="umbau"><input type="hidden" name="sab_id" value="' . (int) $a['id'] . '">'
                       . ($a['fertig'] ? '<input type="hidden" name="sab_form" value="umbau_zurueck_einzeln"><button class="button">Rückgängig</button>' : '<input type="hidden" name="sab_form" value="umbau_einzeln"><button class="button button-primary">Umbauen</button>')
                       . '</form></td></tr>';
                };
                $kopf = '<table class="widefat striped sab-sort2" style="margin-top:1em"><thead><tr><th style="width:28px"><input type="checkbox" onclick="event.stopPropagation();document.querySelectorAll(\'.sab-wahl\').forEach(function(c){c.checked=this.checked}.bind(this))" title="Alle offenen markieren"></th><th>Artikel</th><th>feste Links</th><th>Kurzlinks</th><th>Stand</th><th>Aktion</th></tr></thead><tbody>';
                $offene = array_values(array_filter($liste, fn($x) => !$x['fertig'])); $fertige = array_values(array_filter($liste, fn($x) => $x['fertig']));
                echo '<h3>Noch offen (' . count($offene) . ')</h3><p class="description">Auf eine Überschrift klicken sortiert die Liste. Häkchen setzen und unten „Markierte umbauen“ klicken (bis zu ' . self::UMBAU_MAX . ' auf einmal).</p>' . $kopf;
                foreach ($offene as $a) { $zeile($a); }
                echo '</tbody></table>';
                if ($offene) {
                    echo '<form method="post" id="umbau-sammel" style="margin:10px 0">'; wp_nonce_field('sab_speichern');
                    echo '<input type="hidden" name="sab_tab" value="umbau"><input type="hidden" name="sab_form" value="umbau_viele"><button class="button button-primary button-large">Markierte Artikel umbauen</button> <span class="description">Jeder Artikel wird vorher gesichert und kann einzeln zurückgesetzt werden.</span></form>';
                }
                if ($fertige) {
                    echo '<details style="margin-top:1em"><summary><strong>Schon umgebaut (' . count($fertige) . ')</strong></summary>' . $kopf;
                    foreach ($fertige as $a) { $zeile($a); }
                    echo '</tbody></table></details>';
                }
                echo self::tabellenhilfe();
            }
            $namen = self::produkte(); $an = self::umbau_analyse();
            echo '<details style="margin-top:1.5em"><summary><strong>Alle auf einmal umbauen oder zurücksetzen</strong></summary>';
            echo '<form method="post" style="margin-top:1em">'; wp_nonce_field('sab_speichern');
            echo '<input type="hidden" name="sab_tab" value="umbau"><input type="hidden" name="sab_form" value="umbau">'
               . '<p>Nur diese ASIN umbauen (leer = alle): <input type="text" name="sab_nur" size="14"></p>'
               . '<p><label><input type="checkbox" name="sab_bestaetigt" value="1"> Ja, die nächsten ' . self::UMBAU_PROSEITE . ' Artikel jetzt umbauen</label></p>'
               . '<p><button class="button button-primary">Umbauen (' . self::UMBAU_PROSEITE . ' Artikel pro Klick)</button></p></form>';
            echo '<form method="post">'; wp_nonce_field('sab_speichern');
            echo '<input type="hidden" name="sab_tab" value="umbau"><input type="hidden" name="sab_form" value="umbau_zurueck">'
               . '<p><label><input type="checkbox" name="sab_bestaetigt" value="1"> Ja, alles auf den Zustand vor dem Umbau zurücksetzen</label> <button class="button">Rückgängig</button></p></form>';
            if ($an['asins']) {
                echo '<h3>Produkte in festen Links</h3><table class="widefat striped"><thead><tr><th>ASIN</th><th>Name</th><th>Land</th><th>Stellen</th><th>Artikel</th></tr></thead><tbody>';
                foreach ($an['asins'] as $r) {
                    echo '<tr><td><a href="' . esc_url('https://' . self::LAENDER[$r['land']][0] . '/dp/' . $r['asin']) . '" target="_blank" rel="noopener"><code>' . esc_html($r['asin']) . '</code></a></td><td>' . esc_html($namen[$r['asin']] ?? '')
                       . '</td><td>' . esc_html($r['land']) . '</td><td>' . (int) $r['stellen'] . '</td><td>' . count($r['artikel']) . '</td></tr>';
                }
                echo '</tbody></table>';
            }
            echo '</details>';
        } elseif ($tab === 'widgets') {
            echo self::widgets_html();
        } elseif ($tab === 'vorschau') {
            echo self::vorschau_html();
        } elseif ($tab === 'tests') {
            echo self::ab_tab_html();
            echo '<details style="margin-top:2em"><summary><strong>Weitere Tests: Ersatzlisten und Bilder</strong></summary>';
            echo '<form method="post">'; wp_nonce_field('sab_speichern');
            echo '<input type="hidden" name="sab_tab" value="tests"><input type="hidden" name="sab_form" value="tests">'
               . '<h2>A/B-Tests</h2><p>Pro Zeile: <code>slot: ASIN-A | ASIN-B</code> (bis zu 4 Varianten). Für diese Ersatzliste (<code>[amz_produkt slot="slot"]</code>) bekommt jeder Artikel fest eine Variante, ohne Cookie und ohne IP. Fällt die Variante aus, springt die Ersatzliste ein. Zeile löschen = Test beendet.</p>'
               . '<textarea name="sab_tests" rows="4" class="large-text code" placeholder="erizos: B0CNPW8Y97 | B08KGPF1ML">' . esc_textarea((string) get_option('sab_tests', '')) . '</textarea>'
               . '<h2>Bild-Tests</h2><p>Pro Zeile: <code>ASIN: 1 | 3</code>. Die Zahlen sind die Bildnummern aus der Übersicht (1 = Hauptbild). Jeder Artikel bekommt fest ein Bild, die Klicks werden je Bild gezählt (in der Tabelle unten als <code>bild-ASIN</code>). Gilt für <code>[amz_produkt]</code> und <code>[amz_bild]</code>.</p>'
               . '<textarea name="sab_bildtests" rows="3" class="large-text code" placeholder="B0CNPW8Y97: 1 | 3">' . esc_textarea((string) get_option('sab_bildtests', '')) . '</textarea>'
               . '<p><button class="button button-primary">Speichern</button></p></form>';
            echo '<h2>Ergebnis</h2>';
            $z = self::testzahlen();
            if (!$z) { echo '<p>Noch keine Klicks.</p>'; }
            else {
                echo '<table class="widefat striped"><thead><tr><th>Ersatzliste</th><th>Variante</th><th>Klicks</th><th>kamen zurück</th></tr></thead><tbody>';
                foreach ($z as $r) { echo '<tr><td>' . esc_html($r['slot']) . '</td><td>' . esc_html($r['var']) . '</td><td>' . (int) $r['n'] . '</td><td>' . (int) $r['z'] . '</td></tr>'; }
                echo '</tbody></table><p class="description">Gezählt werden Klicks, nicht Seitenaufrufe. Die Artikel verteilen sich gleichmäßig auf die Varianten, vergleiche daher erst bei genügend Klicks.</p>';
            }
            echo '</details>';
        } elseif ($tab === 'einstellungen') {
            $us = (array) get_option('sab_update_status', []); $ab = self::update_intervall();
            echo '<p class="description"><strong>Update-Prüfung:</strong> letzte Abfrage bei GitHub ' . (!empty($us['ts']) ? 'vor ' . max(0, (int) round((time() - (int) $us['ts']) / 60)) . ' Min.' : 'noch nie') . ', dort liegt Version ' . esc_html((string) ($us['version'] ?? '?')) . ', installiert ist ' . esc_html(self::VERSION) . '. Abstand jetzt: ' . ($ab >= DAY_IN_SECONDS ? 'einmal am Tag' : ($ab >= 3600 ? (int) round($ab / 3600) . ' Std.' : (int) round($ab / 60) . ' Min.')) . ' (kürzer, solange oft Updates kommen). <a class="button button-small" href="' . esc_url(wp_nonce_url(admin_url('admin.php?page=sab-einstellungen&sab_update_jetzt=1'), 'sab_update_jetzt')) . '">Jetzt nach Update suchen</a></p>';
            echo self::ids_html();
            echo '<form method="post">'; wp_nonce_field('sab_speichern');
            echo '<input type="hidden" name="sab_tab" value="einstellungen"><input type="hidden" name="sab_form" value="einstellungen">';
            $fest = defined('SAB_CLIENT_ID') && defined('SAB_CLIENT_SECRET');
            echo '<h2>Amazon-Zugang (Creators API)</h2>';
            if ($fest) { echo '<p><em>Die Zugangsdaten stehen in der wp-config.php und haben Vorrang. Die Felder unten werden dann nicht benutzt.</em></p>'; }
            echo '<table class="form-table"><tr><th>Credential ID</th><td><input type="text" name="sab_client_id" class="regular-text" autocomplete="off" value="'
               . esc_attr((string) get_option('sab_client_id', '')) . '"></td></tr>'
               . '<tr><th>Credential Secret</th><td><input type="password" name="sab_client_secret" class="regular-text" autocomplete="new-password" value="" placeholder="'
               . (get_option('sab_client_secret') ? 'gespeichert, leer lassen = unverändert' : '') . '"></td></tr>'
               . '<tr><th>Partner-IDs</th><td>' . self::partner_felder_html() . '<p class="description">Pro Shop eine ID, leer lassen = dort keine. Nicht genutzte Länder einfach leer lassen.</p></td></tr>'
               . '<tr><th>Länder-Umschaltung</th><td><label><input type="checkbox" name="sab_geo" value="1"' . (get_option('sab_geo') === '1' ? ' checked' : '') . '> Amazon-Links für Besucher aus anderen Ländern auf deren Amazon-Shop (Suche nach dem Produktnamen) umstellen</label><p class="description">Erkennung nur im Browser aus Zeitzone und Browsersprache: keine IP, kein Cookie, nichts wird übertragen. Wirkt nur mit je einer Partner-ID pro Land (oben, Zeile <code>ca=…</code>, <code>com.au=…</code>); auf Slughelp sind die bisherigen IDs vorbelegt. Fehlt etwas, bleibt der normale Link.</p></td></tr>'
               . '<tr><th>Weitere Partner-IDs</th><td><textarea name="sab_tags_extra" rows="3" class="regular-text code">' . esc_textarea((string) get_option('sab_tags_extra', ''))
               . '</textarea><p class="description">Eine ID pro Zeile, zum Beispiel <code>cpflanzhilfe-21</code> (optional mit Notiz dahinter). Nur diese IDs darf ein Platzhalter mit <code>tag="…"</code> oder <code>tracking_id="…"</code> statt der Standard-ID benutzen. Der Umbau übernimmt sie so.</p></td></tr>'
               . '<tr><th>eBay-Kampagnen-IDs</th><td><textarea name="sab_ebay_ids" rows="3" class="regular-text code">' . esc_textarea((string) get_option('sab_ebay_ids', ''))
               . '</textarea><p class="description">Eine Kampagnen-ID (campid) pro Zeile, die erste ist der Standard. Beispiel: <code>5338619899 Standard</code>. Sie gilt, wenn bei einem eBay-Link keine eigene <code>campid</code> steht.</p></td></tr>'
               . '<tr><th>Pflichthinweis</th><td><label><input type="checkbox" name="sab_hinweis_auto" value="1"' . (get_option('sab_hinweis_auto') === '1' ? ' checked' : '')
               . '> automatisch im Fuß jeder Seite mit Partnerlinks</label><br><input type="text" name="sab_hinweis_text" class="large-text" placeholder="leer = Standardsatz je Land" value="'
               . esc_attr((string) get_option('sab_hinweis_text', '')) . '"></td></tr>'
               . '<tr><th>Nur in einem anderen Store</th><td><textarea name="sab_heimat_liste" rows="3" cols="40" class="code" placeholder="B0XXXXXXXX = com">' . esc_textarea((string) get_option('sab_heimat_liste', '')) . '</textarea><p class="description">Produkte, die es im Standard-Store dieser Seite nicht gibt (z. B. nur auf amazon.com). Eine ASIN je Zeile, Land dahinter (com, co.uk, ca …) oder weglassen, dann sucht das Plugin. Bild und Link kommen dann live von diesem Store (Bild höchstens 1 Tag zwischengespeichert), die Länder-Umschaltung lässt den Link in Ruhe. Dafür muss für das Land eine Partner-ID eingetragen sein.</p></td></tr>'
               . '<tr><th>Allgemeine Linktexte</th><td><textarea name="sab_generische_linktexte" rows="3" cols="40" placeholder="Offer on Amazon&#10;Zum Shop vor Ort">' . esc_textarea((string) get_option('sab_generische_linktexte', '')) . '</textarea><p class="description">Ein Linktext je Zeile, der nie als Produkttitel gilt (Standard: Angebot/Offer/Click/Hier … bei Amazon, mehrsprachig). Der Titel kommt dann aus dem Satz vor dem Link.</p></td></tr>'
               . '<tr><th>Karten-Design</th><td><label><input type="checkbox" name="sab_karten_css" value="1"' . (get_option('sab_karten_css') !== '0' ? ' checked' : '')
               . '> kleines Karten-Design für <code>[amz_produkt]</code> laden (weißer Kasten, grüner Button)</label></td></tr>'
               . '<tr><th>Umbau bei unbekannten Produkten</th><td><label><input type="checkbox" name="sab_weg_umbauen" value="1"' . (get_option('sab_weg_umbauen', '1') === '1' ? ' checked' : '')
               . '> Produkte, die Amazon nicht mehr kennt, trotzdem umbauen: Die Stelle zeigt „Momentan nicht verfügbar“, getauscht wird später mit Vorrang im Reiter Austausch</label></td></tr>'
               . '<tr><th>Nicht verfügbar</th><td><label><input type="checkbox" name="sab_strikt" value="1"' . (get_option('sab_strikt') === '1' ? ' checked' : '')
               . '> Produkte, die Amazons Schnittstelle als „nicht verfügbar“ meldet, wie ausgefallen behandeln und durch den Ersatz aus der Liste ersetzen</label><p class="description">Aus lassen, wenn Amazon die Lieferbarkeit für einen anderen Lieferort prüft als deinen: Dann würde ein lieferbares Produkt fälschlich ersetzt.</p></td></tr>'
               . '<tr><th>Amazon-Seite (kein Produkt)</th><td><textarea name="sab_amz_seiten" rows="3" class="large-text code" placeholder="https://www.amazon.de/b?node=123*">' . esc_textarea((string) get_option('sab_amz_seiten', '')) . '</textarea><p class="description">Ausnahmeliste: Amazon-Adressen ohne Produkt, die gewollt sind (eine pro Zeile, am Ende * erlaubt). Die Amazon-Startseite und Rezensionsseiten (/product-reviews/ASIN) gelten ohnehin als in Ordnung; das Plugin ergänzt dort beim Umbau die Partner-ID des Landes und <code>rel="sponsored nofollow noopener"</code>. Empfohlen: voller Link <code>https://www.amazon.de/?tag=ID</code> statt Kurzlink. Baustein für Rezensionen: <code>[amz_rezension asin="ASIN" land="co.uk"]Rezensionen ansehen[/amz_rezension]</code>. Es werden keine Rezensionstexte gespeichert.</p></td></tr>'
               . '<tr><th>WordPress-Mails zu Plugin-Updates</th><td><label><input type="checkbox" name="sab_update_mail" value="1"' . (get_option('sab_update_mail') === '1' ? ' checked' : '') . '> Mails über erfolgreiche automatische Plugin- und Theme-Updates weiterhin senden</label><p class="description">Aus (Standard): WordPress verschickt keine Mail mehr über erfolgreiche automatische Plugin- und Theme-Updates. Das gilt für alle Plugins und Themes dieser Webseite, nicht nur für dieses. Mails über fehlgeschlagene Updates bleiben an.</p></td></tr>'
               . '<tr><th>Wochenbericht</th><td><input type="email" name="sab_mail_an" class="regular-text" placeholder="leer = keine Mail" value="' . esc_attr((string) get_option('sab_mail_an', '')) . '"><p class="description">Einmal pro Woche eine Mail mit Klicks, Ausfällen und Seiten mit vielen Partnerlinks. An dieselbe Adresse gehen sofort Meldungen, wenn ein Produkt ausfällt oder die Amazon-Schnittstelle nicht antwortet.</p></td></tr>'
               . '<tr><th>Preis-Test</th><td><label><input type="checkbox" name="sab_preis_test" value="1"' . (get_option('sab_preis_test') === '1' ? ' checked' : '')
               . '> A/B-Test: Karte ohne Preis (A) gegen Karte mit Preis (B)</label><p class="description">Jeder Artikel bekommt fest eine Variante; Auswertung unter A/B-Tests als „preis-test“. Hat Vorrang vor dem Button-Test.</p></td></tr>'
               . '<tr><th>Button-Test</th><td><input type="text" name="sab_btn_a" class="regular-text" placeholder="Text A, z. B. Ver oferta en Amazon" value="' . esc_attr((string) get_option('sab_btn_a', '')) . '"> '
               . '<input type="text" name="sab_btn_b" class="regular-text" placeholder="Text B, z. B. Ver precio en Amazon" value="' . esc_attr((string) get_option('sab_btn_b', '')) . '">'
               . '<p class="description">Beide ausfüllen = A/B-Test der Button-Texte (jeder Artikel bekommt fest einen Text; Auswertung unter A/B-Tests als „button-test“). Leer lassen = Standardtext. Gilt nur für Boxen ohne eigenen Text.</p></td></tr>'
               . '<tr><th>Standardland</th><td>' . self::land_auswahl_html() . ' <span class="description">Fehlt dort die Partner-ID, nimmt das Plugin automatisch das erste Land mit ID.</span></td></tr></table>'
               . '<p class="description">Hinweis: Das Secret wird in der Datenbank gespeichert (und taucht in Datenbank-Sicherungen auf). Sicherer ist die wp-config.php.</p>'
               . '<p><button class="button button-primary">Speichern</button></p></form>';
        } else {
            echo '<h2>Hilfe</h2><ul style="list-style:disc;margin-left:1.5em;max-width:60em">'
               . '<li><code>[amz_produkt slot="name"]</code>: Bild, Name und Textlink aus einer Ersatzliste (empfohlen). Zwischen <code>[amz_produkt]…[/amz_produkt]</code> kann der bisherige HTML-Code als Rückfall stehen.</li>'
               . '<li><code>[amz_bild asin="…" land="es"]</code> und <code>[amz_link asin="…"]Text[/amz_link]</code>: einzelnes Bild bzw. Textlink.</li>'
               . '<li><code>land="auto"</code>: Land nach Browsersprache. Mit Seiten-Cache bekommen alle das Land des ersten Abrufs.</li>'
               . '<li><code>[amz_hinweis]</code>: Pflichthinweis, oder automatisch im Fuß (Einstellungen).</li>'
               . '<li><strong>Produkt tauschen, wenn es nicht mehr lieferbar ist:</strong> Reiter „Austausch“ (die Regel wird dort automatisch angelegt). Gilt sofort überall.</li>'
               . '<li><strong>Favorit / Ersetzen:</strong> Kästchen in der Übersicht. Favoriten werden in Ersatzlisten bevorzugt, „Ersetzen“ rutscht ans Ende und steht unter „Ausfälle und Wartung“.</li>'
               . '<li><strong>Umbau:</strong> Reiter „Umbau“ wandelt feste Amazon-Links in Artikeln in Plugin-Platzhalter um (mit Sicherung und Rückgängig).</li>'
               . '<li><strong>A/B-Test:</strong> Reiter „A/B-Tests“. Jeder Artikel bekommt fest eine Variante, Klicks werden je Variante gezählt.</li>'
               . '<li>Bilder, Links und Titel werden höchstens 1 Tag zwischengespeichert. Alle Amazon-Links tragen <code>rel="sponsored nofollow noopener"</code>. Die Klickmessung nutzt keine Cookies und keine IP.</li>'
               . '</ul>';
        }
        echo '</div>';
    }
}
SAB::init();

} else {
    add_action('admin_notices', function () { echo '<div class="notice notice-warning"><p>1AB Affiliate Help: Die Fassung in „' . esc_html(basename(__DIR__)) . '“ wurde nicht geladen, weil schon eine andere Fassung des Plugins aktiv ist. Bitte nur eine davon aktiv lassen.</p></div>'; });
}
