<?php
/**
 * Plugin Name: 1AB Affiliate Help
 * Description: Kleiner Ersatz für AAWP. Zeigt Amazon-Produktbilder, Namen und Textlinks über die Creators API. Bilder kommen vom eigenen Server. Erkennt ausgefallene Produkte und setzt den nächsten Ersatz aus einer Liste ein.
 * Version: 0.77.3
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
    const API      = 'https://creatorsapi.amazon/catalog/v1/getItems';
    const TTL      = DAY_IN_SECONDS;      // Amazon: Bilder und Links höchstens 1 Tag zwischenspeichern
    const BILD_MAX = DAY_IN_SECONDS + HOUR_IN_SECONDS; // Amazon-Regel: Bilder höchstens 1 Tag; ältere werden nie gezeigt (1 h Puffer für den Cron)

    const VERSION = '0.77.3';
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
        add_shortcode('amz_karte',   [__CLASS__, 'sc_karte']);
        add_shortcode('amz_hinweis', [__CLASS__, 'sc_hinweis']);
        add_shortcode('aawp',        [__CLASS__, 'sc_aawp']);
        add_shortcode('amazon',      [__CLASS__, 'sc_aawp']);   // Alias wie bei AAWP (AAWP gewinnt, solange es aktiv ist)
        add_action('sab_pruefen',    [__CLASS__, 'pruefen']);
        add_action('wp_ajax_sab_pruef_start',   [__CLASS__, 'ajax_pruef_start']);
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
        add_action('admin_footer-post.php', [__CLASS__, 'zeige_editor_skript']);
        add_filter('pre_do_shortcode_tag', [__CLASS__, 'ord_pre'], 10, 4);
        add_filter('do_shortcode_tag',     [__CLASS__, 'ord_post'], 10, 4);
        add_filter('the_content',          [__CLASS__, 'ord_reset'], 9);
        add_filter('the_content',          [__CLASS__, 'attr_reparieren'], 8);
        add_action('wp_footer',            [__CLASS__, 'stellen_skript'], 97);
        add_action('wp_ajax_sab_stelle_kand',    [__CLASS__, 'ajax_stelle_kand']);
        add_action('wp_ajax_sab_stelle_tausch',  [__CLASS__, 'ajax_stelle_tausch']);
        add_action('wp_ajax_sab_stelle_zurueck', [__CLASS__, 'ajax_stelle_zurueck']);
        add_action('wp_footer',      [__CLASS__, 'fuss'], 5);
        add_filter('pre_set_site_transient_update_plugins', [__CLASS__, 'update_pruefen']);
        add_action('sab_update_check', [__CLASS__, 'update_cron']);
        add_action('admin_init',     [__CLASS__, 'update_admin']);
        add_action('admin_init',     [__CLASS__, 'tabelle']);
        add_action('admin_init',     [__CLASS__, 'einmal_importe']);
        if (!wp_next_scheduled('sab_pruefen')) { wp_schedule_event(time() + 300, 'daily', 'sab_pruefen'); }
    }

    // ---------- Zugang ----------

    // Zugangsdaten: wp-config.php hat Vorrang, sonst die Eingabemaske unter Seitenleiste > 1AB Affiliate Help

    private static function zugang(): array {
        return [
            defined('SAB_CLIENT_ID')     ? SAB_CLIENT_ID     : (string) get_option('sab_client_id', ''),
            defined('SAB_CLIENT_SECRET') ? SAB_CLIENT_SECRET : (string) get_option('sab_client_secret', ''),
        ];
    }

    /** Zeilen "land=partner-id" aus der Eingabemaske => [land => id] */
    private static function tags(): array {
        if (defined('SAB_TAGS')) { return SAB_TAGS; }
        $out = [];
        foreach (preg_split('/\R/', (string) get_option('sab_tags', '')) as $z) {
            $z = trim($z, " \t\"'");
            if ($z === '') { continue; }
            $t = array_map(fn($v) => trim($v, " \t\"'"), preg_split('/\s*[=:]\s*/', $z, 2));
            if (count($t) === 2 && $t[0] !== '' && $t[1] !== '') { $out[strtolower($t[0])] = $t[1]; }
            elseif (count($t) === 1 && preg_match('/^[A-Za-z0-9][A-Za-z0-9\-]{2,39}$/', $t[0])) { $out[self::standardland()] = $out[self::standardland()] ?? $t[0]; }   // nur die ID ohne „de=“: gilt für das Standardland
        }
        return $out;
    }

    private static function standardland(): string {
        return defined('SAB_DEFAULT_LAND') ? SAB_DEFAULT_LAND : (string) get_option('sab_default_land', 'de');
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
        return self::tags()[$land] ?? '';
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
        if (!$frisch && !$warten && !defined('SAB_SYNC')) { // nichts im Speicher: Hintergrundabruf anstoßen, die Seite nutzt den Rückfall (schlichter Link)
            self::im_hintergrund('sab_holen', [$asin, $land]);
            return null;
        }

        $alt = get_option("sab_alt_{$land}_{$asin}");
        $p = self::abrufen($asin, $land, $alt ?: []);
        if ($p !== null) {
            set_transient("sab_{$land}_{$asin}", $p, self::TTL);
            update_option("sab_alt_{$land}_{$asin}", $p, false);
            return $p;
        }
        // API gerade nicht erreichbar: letzter Stand nur, solange er höchstens 1 Tag alt ist (Amazon-Caching-Regel)
        return ($alt && (time() - (int) ($alt['ts'] ?? 0)) <= self::TTL) ? $alt : null;
    }

    /** Plant einen einmaligen Abruf im WP-Cron (ohne Doppelungen), damit Seitenaufrufe nie auf Amazon warten. */
    private static function im_hintergrund(string $hook, array $args): void {
        if (!function_exists('wp_schedule_single_event') || wp_next_scheduled($hook, $args)) { return; }
        wp_schedule_single_event(time(), $hook, $args);
        if (function_exists('spawn_cron')) { spawn_cron(); }
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
    const SCORE_STANDARD = ['rang' => 27, 'buybox' => 20, 'angebot' => 13, 'preis' => 20, 'streich' => 20, 'deal' => 10, 'bewertung' => 100, 'konstanz' => 10, 'prime' => 42, 'liefer' => 42, 'provision' => 50];
    /** Kategorien, in denen ein Verkaufsrang für Garten-Produkte zählt (Hauptkategorie und typische Unterkategorien). */
    const GARTEN_KAT = '/jard[ií]n|garten|giardin|garden|lawn|c[eé]sped|outdoor|plaga|control de (?:aves|animales|plagas|insectos|roedores|caracoles)|babosa|caracol|\baves?\b|cerca|valla|huerto|plantas?\b|riego|cultivo|abono|maceta|invernadero|semill|poda|jardiner/iu';
    const SCORE_NAMEN = [
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
                'prime' => $p === '' || in_array($p, ['-', '?', 'nv', 'n/v', 'weg', 'nicht verfügbar'], true) ? null : in_array($p, ['ja', 'yes', 'j', '1', 'prime'], true),   // ja = Prime, nein = gelesen und kein Prime, „-“ = bei Amazon nicht verfügbar, leer = unbekannt/ungeprüft
                'ohne_bewertung' => trim((string) ($t[1] ?? '')) === '' && trim((string) ($t[2] ?? '')) === '' && trim((string) ($t[5] ?? '')) !== '',
                'gelesen_weg' => in_array($p, ['-', 'nv', 'n/v', 'weg', 'nicht verfügbar'], true), 'liefertage' => $tage,
                'liefertext' => $t[4] ?? '', 'manuell_stand' => $t[5] ?? '', 'provision' => isset($t[6]) && preg_match('/\\d/', $t[6]) ? (float) str_replace(',', '.', preg_replace('/[^\\d,.]/', '', $t[6])) : null,
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
        if (array_key_exists('manuell_stand', $x) && ($x['prime'] ?? null) === null) { return [0, '']; }
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

    private static function tausch_dringlichkeit(array $x, int $score): int {
        $nicht = empty($x['trotz']) && (empty($x['lieferbar']) || ($x['status'] ?? '') === 'OUT_OF_STOCK' || ($x['preis'] ?? '') === '');
        if ($nicht || (array_key_exists('bilder', $x) && empty($x['bilder']) && empty($x['bild']))) { return 100; }   // nicht lieferbar, kein Preis oder kein Bild
        $q = self::dringlichkeit_werte();
        $primeUnklar = array_key_exists('manuell_stand', $x) && ($x['prime'] ?? null) === null;
        if ($primeUnklar && (($x['preis'] ?? '') === '' || ($x['kaufen'] ?? null) === false)) { return 100; }   // ohne Preis oder Buy Box und ohne Prime: wohl nicht mehr zu haben
        $d = max($primeUnklar ? 20 : 0, $score < $q['schwelle'] ? (int) round(($q['schwelle'] - $score) * $q['faktor']) : 0);
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
        $t[] = self::prime_zeichen(isset($x['prime']) ? $x['prime'] : null);
        if (isset($x['liefertage']) && $x['liefertage'] !== null) { $t[] = '&#128666; ' . esc_html(str_replace('.', ',', (string) round((float) $x['liefertage'], 1))) . ' T'; }
        if (isset($x['rang']) && $x['rang'] !== null) { $t[] = 'Rang ' . number_format((int) $x['rang'], 0, ',', '.'); }
        $t[] = $pv !== null ? esc_html(str_replace('.', ',', (string) $pv)) . ' % Prov.' : 'Prov. ?';
        return '<div style="margin-left:auto;text-align:right;min-width:260px"><span style="font-size:20px;font-weight:700;color:' . self::score_farbe($sc) . '" title="Score des alten Produkts">Score ' . (int) $sc . '</span><br><small>' . implode(' · ', $t) . '</small><br>' . self::score_grafik($det) . '</div>';
    }

    /** Prime als Schildchen: blau mit Haken, bei „kein Prime“ grau und durchgestrichen, bei unbekannt gestrichelt (eigene Gestaltung, nicht das Amazon-Logo). */
    private static function prime_zeichen($prime): string {
        $b = 'display:inline-block;padding:0 7px;border-radius:4px;font-size:11px;font-weight:700;font-style:italic;line-height:17px;vertical-align:baseline;';
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

    /** Rote Warnung über fehlenden Produktbildern (Priorität vor allem anderen). Behobene Fälle verschwinden von selbst. */
    private static function bildfehler_html(): string {
        $l = (array) get_option('sab_bildfehler', []); if (!$l) { return ''; }
        $land = self::land(''); $rest = []; $nach = [];
        foreach ($l as $k => $e) {
            $p = get_transient("sab_{$land}_" . $e['asin']); if (!is_array($p)) { $p = null; }   // get_transient liefert false, wenn nichts da ist
            if (self::hat_bild($p)) { continue; }   // Bild ist inzwischen da
            if ((int) $e['post'] <= 0 || get_post_status((int) $e['post']) !== 'publish') { continue; }   // Vorschau außerhalb eines Artikels oder Artikel nicht mehr veröffentlicht: keine Warnung
            $rest[$k] = $e; $nach[$e['asin']][] = (int) $e['post'];
        }
        if (count($rest) !== count($l)) { update_option('sab_bildfehler', $rest, false); }
        if (!$rest) { return ''; }
        uasort($nach, fn($x, $y) => count($y) <=> count($x));   // die auf den meisten Seiten stehen zuerst
        $zeilen = [];
        foreach (array_slice($nach, 0, 40, true) as $asin => $posts) {
            $links = [];
            foreach (array_slice($posts, 0, 4) as $pid) { $links[] = '<a href="' . esc_url(get_edit_post_link($pid)) . '">' . esc_html(get_the_title($pid) ?: ('Beitrag ' . $pid)) . '</a>'; }
            $zeilen[] = '<li><code>' . esc_html($asin) . '</code> auf ' . count($posts) . ' Seite(n): ' . implode(', ', $links) . (count($posts) > 4 ? ' …' : '') . '</li>';
        }
        return '<div class="notice notice-error" style="border-left-width:8px"><p><strong>Produktbilder fehlen (' . count($nach) . ' Produkte):</strong> An diesen Stellen bekommen Besucher kein Bild. Das hat Vorrang vor allem anderen: Produkt ersetzen oder Bild bei Amazon prüfen.</p><ul style="margin-left:18px;list-style:disc">' . implode('', $zeilen) . '</ul><p><a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=sab-austausch')) . '">Zum Austausch: dringend tauschen</a></p></div>';
    }

    /** Fehlende Produktbilder zählen (nur Artikel, die noch veröffentlicht sind und deren Bild noch fehlt): [Produkte, Stellen]. */
    public static function bildfehler_zaehlen(): array {
        $l = (array) get_option('sab_bildfehler', []); if (!$l) { return [0, 0]; }
        $land = self::land(''); $asins = []; $stellen = 0;
        foreach ($l as $e) {
            $p = get_transient("sab_{$land}_" . $e['asin']); if (self::hat_bild(is_array($p) ? $p : null)) { continue; }
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
            return '<span style="display:inline-block;padding:6px 10px;background:#fcf0f1;border:2px solid #d63638;color:#8a1f1f;font:600 13px sans-serif">Produktbild fehlt: ' . esc_html($asin) . ' (nur für Redakteure sichtbar)</span>';
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
        return array_values(array_unique($alle));
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
        self::auto_tausch();
        self::wochenmail();
    }

    /** Täglicher Lauf: alle bekannten Produkte frisch holen, Ausfälle eintragen oder löschen. */
    public static function pruefen(): void {
        self::alte_bilder_loeschen();
        $land = self::land('');
        self::uebersicht_holen($land);   // hält den täglichen Score-Verlauf fest
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
        $lauf = self::pruef_neuer_lauf();
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
                if (!isset($st['items'][$a]) && !isset($liste[$a])) { $liste[$a] = ['name' => ($nm[$a] ?? ''), 'grund' => 'Amazon kennt das Produkt nicht mehr', 'seit' => (int) ($st['ts'] ?? time()), 'ersatz' => '', 'seite' => '']; }
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

    private static function bild_url(array $p, string $asin, string $land, int $idx = 0, bool $laden = false): string {
        $quelle = $idx > 0 ? ($p['bilder'][$idx] ?? '') : ($p['bild'] ?? '');
        if (!$quelle) { return ''; }
        $up   = wp_upload_dir();
        $dir  = $up['basedir'] . '/sab';
        $name = "{$land}-{$asin}" . ($idx > 0 ? "-{$idx}" : '') . '.jpg';
        $file = "$dir/$name";
        $alter = file_exists($file) ? time() - filemtime($file) : PHP_INT_MAX;

        if ($alter > self::TTL && !$laden && !defined('SAB_SYNC')) { self::im_hintergrund('sab_bild_holen', [$land, $asin, $idx]); }
        if ($alter > self::TTL && ($laden || defined('SAB_SYNC'))) {
            wp_mkdir_p($dir);
            $r = wp_remote_get($quelle, ['timeout' => 10]);
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
        // data-Attribute: die Mini-Statistik kann Klicks damit dem Produkt und der Ersatzliste zuordnen
        return ' data-sab-asin="' . esc_attr($asin) . '"' . ($slot ? ' data-sab-slot="' . esc_attr($slot) . '"' : '') . ($var ? ' data-sab-var="' . esc_attr($var) . '"' : '')
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
        if (self::$karten && get_option('sab_karten_css') !== '0') { echo '<style id="sab-karte">' . self::KARTE_CSS . '</style>'; }
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
            if (function_exists('current_user_can') && current_user_can('manage_options')) {
                echo '<div style="position:fixed;left:8px;bottom:8px;z-index:99999;background:#fff3cd;border:1px solid #d4a017;color:#000;padding:6px 10px;font:13px sans-serif">' . esc_html(self::NAME) . ': ' . (int) $n . ' Partnerlinks auf dieser Seite (Warnung ab ' . self::LINK_WARNUNG . ')</div>';
            }
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
            if (strlen($asin) !== 10) { continue; }
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
            if ($n > 0 && mb_strlen($name) > $n) { $name = rtrim(mb_substr($name, 0, $n), " ,.-–") . '…'; }
        }
        return esc_html($name !== '' ? $name : (string) $inhalt);
    }

    /** Schlichte Karten, auch am Handy zwei nebeneinander (nur CSS, HTML bleibt gleich). */
    const KARTE_CSS = '.sab-karten{display:grid;gap:10px;margin:1.2em 0;align-items:stretch}.sab-karte{display:flex;flex-direction:column;align-items:center;gap:8px;padding:10px;border:1px solid #e6e8e8;border-radius:10px;background:#fff;text-align:center;min-width:0}.sab-karte .sab-bild{display:block;width:100%}.sab-karte .sab-bild img{display:block;width:100%;max-height:200px;object-fit:contain;margin:0 auto}.sab-karte-titel{font-size:.9em;line-height:1.3;color:#333;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;margin-top:auto}.sab-karte-btn{display:block;width:100%;box-sizing:border-box;padding:.55em .4em;min-height:40px;border:1px solid #d9dcdc;border-radius:8px;background:#f7f8f8;color:#1a1a1a!important;font-size:.88em;font-weight:600;text-decoration:none!important}.sab-karte-btn:hover{background:#eef0f0}.sab-karte-ebay{font-size:.8em;color:#666!important}.sab-karte a.sab-bild{display:flex;flex-direction:column;align-items:center;gap:8px;text-decoration:none!important;color:inherit}span.sab-karte-btn{display:block}@media(min-width:600px){.sab-karte{padding:14px;gap:10px}.sab-karte-titel{font-size:.95em}}@media(max-width:320px){.sab-karten{grid-template-columns:1fr!important}}';

    public static function sc_karten($a, $inhalt = ''): string {
        self::$genutzt = true; self::$karten = true;
        // wpautop macht aus den Zeilenumbrüchen zwischen den Karten <br> und <p>: das würde im Raster zusätzliche Spalten erzeugen
        $inhalt = preg_replace('#</?p\b[^>]*>|<br\s*/?>#i', '', (string) $inhalt);
        $n = max(1, min(4, substr_count($inhalt, '[amz_karte')));
        return '<div class="sab-karten" style="grid-template-columns:repeat(' . $n . ',minmax(0,1fr))">' . do_shortcode(trim($inhalt)) . '</div>';
    }

    /** [amz_karte asin="…" land="es" titel="" ebay_host/campid/suche/customid="…" text="Ver en Amazon" ebay_text="o en eBay"] */
    public static function sc_karte($a, $inhalt = ''): string {
        $a = shortcode_atts(['asin' => '', 'land' => '', 'titel' => '', 'text' => '', 'ebay' => '', 'host' => 'www.ebay.es', 'campid' => '', 'suche' => '', 'customid' => '', 'ebay_text' => '', 'tag' => ''], $a);
        $orig = self::asin($a['asin']); $asin = self::getauscht($orig); $land = self::land($a['land']);
        if (strlen($asin) !== 10) { return ''; }
        self::$genutzt = true; self::$karten = true;
        $p = self::produkt($asin, $land);
        // dein Titel aus dem Artikel bleibt stehen; nach einem Austausch kommt der Kurzname des neuen Produkts
        $titel = ($asin === $orig && trim((string) $a['titel']) !== '') ? esc_html(trim((string) $a['titel'])) : self::sc_titel(['asin' => $asin, 'land' => $land, 'kurz' => 70], '');
        $bild = self::brauchbar($p) ? self::sc_bild(['asin' => $asin, 'land' => $land, 'tag' => $a['tag']], '') : self::bild_fehlt($asin, 'Karte');   // nie still ohne Bild: Platzhalter, für Redakteure rot, und Eintrag in der Warnliste
        $btn = self::sc_link(['asin' => $asin, 'land' => $land, 'class' => 'sab-karte-btn', 'tag' => $a['tag']], $a['text'] !== '' ? $a['text'] : (self::BUTTON_TEXT[$land] ?? self::BUTTON_TEXT['de']));
        $ebay = $a['ebay'] !== '' || $a['campid'] !== '' ? self::sc_ebay(['asin' => $orig, 'land' => $land, 'host' => $a['host'], 'campid' => $a['campid'], 'suche' => $a['suche'], 'customid' => $a['customid']], $a['ebay_text'] !== '' ? $a['ebay_text'] : 'eBay') : '';
        if ($ebay !== '') { $ebay = str_replace('<a ', '<a class="sab-karte-ebay" ', $ebay); }
        $tt = $titel !== '' ? '<div class="sab-karte-titel">' . $titel . '</div>' : '';
        // Bild, Titel und Button sind EIN Link (für Crawler zählt das als ein Affiliate-Link); data-sab-asin/-slot/-var bleiben für Statistik und Austausch am Link
        if (preg_match('#^(<a\b[^>]*\bclass="sab-bild[^"]*"[^>]*>)(.*)</a>$#s', $bild, $mb) && preg_match('#^<a\b[^>]*>(.*)</a>$#s', $btn, $mt)) {
            return '<div class="sab-karte">' . $mb[1] . $mb[2] . $tt . '<span class="sab-karte-btn">' . $mt[1] . '</span></a>' . $ebay . '</div>';
        }
        return '<div class="sab-karte">' . $bild . $tt . $btn . $ebay . '</div>';
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
        return '<span data-sab-weg="' . esc_attr($asin) . '" title="Amazon kennt dieses Produkt nicht mehr">' . $html . '</span>';
    }

    /** Im Bearbeiten-Fenster (klassischer Editor): ?sab_zeige=ASIN schaltet auf „Text“, markiert die Stelle im Code und scrollt direkt dorthin. */
    public static function zeige_editor_skript(): void {
        $a = isset($_GET['sab_zeige']) ? preg_replace('/[^A-Za-z0-9]/', '', (string) wp_unslash($_GET['sab_zeige'])) : '';
        if (strlen($a) < 5 || strlen($a) > 12 || !current_user_can('edit_posts')) { return; }
        echo '<script>(function(){var a=' . wp_json_encode($a) . ';'
           . 'function ban(t){var b=document.createElement("div");b.style.cssText="position:fixed;left:180px;right:0;top:32px;z-index:99999;background:#d63638;color:#fff;padding:8px 16px;font:600 14px sans-serif;text-align:center";b.textContent=t;b.onclick=function(){b.remove()};document.body.appendChild(b)}'
           . 'function gb(n){n=n||0;var wd=window.wp&&wp.data;var be=wd&&wd.select("core/block-editor");var bl=be?be.getBlocks():[];if(!bl.length){if(n<25){setTimeout(function(){gb(n+1)},400)}else{ban("Der Editor ist nicht geladen; bitte mit Strg+F nach "+a+" suchen.")}return}'
           . 'function fi(l){for(var i=0;i<l.length;i++){var b=l[i],t=JSON.stringify(b.attributes||{})+String(b.originalContent||"");if(t.indexOf(a)>=0){return b}var r=fi(b.innerBlocks||[]);if(r){return r}}return null}'
           . 'var f=fi(bl);if(!f){ban("Im Text habe ich "+a+" nicht gefunden (vielleicht über einen Platzhalter oder eine Austausch-Regel).");return}'
           . 'wd.dispatch("core/block-editor").selectBlock(f.clientId);setTimeout(function(){var e=document.getElementById("block-"+f.clientId);if(!e){var fr=document.querySelector("iframe[name=editor-canvas]");e=fr&&fr.contentDocument&&fr.contentDocument.getElementById("block-"+f.clientId)}if(e){e.scrollIntoView({block:"center"})}},300);ban(a+" steht im markierten Block ("+f.name+"). Zum Schließen klicken.")}'
           . 'function hl(el){var o=el.style.cssText;el.style.cssText+=";background:#fff3a3;outline:3px solid #d63638";setTimeout(function(){el.style.cssText=o},6000)}'
           . 'function vis(n){n=n||0;var ed=window.tinyMCE&&tinyMCE.get&&tinyMCE.get("content");if(!ed||!ed.getBody()||!ed.getBody().innerHTML){if(n<30){setTimeout(function(){vis(n+1)},400)}else{txt()}return}'
           . 'var body=ed.getBody(),w=ed.getDoc().createTreeWalker(body,NodeFilter.SHOW_TEXT,null),nd,hit=null;while((nd=w.nextNode())){if(nd.nodeValue.indexOf(a)>=0){hit=nd;break}}'
           . 'var el=hit?hit.parentNode:body.querySelector("[href*=\""+a+"\"],[data-mce-href*=\""+a+"\"],[src*=\""+a+"\"]");'
           . 'if(!el){txt();return}'
           . 'if(hit){var r=ed.getDoc().createRange(),i=hit.nodeValue.indexOf(a);r.setStart(hit,i);r.setEnd(hit,i+a.length);ed.selection.setRng(r)}'
           . 'var fr=document.getElementById("content_ifr"),rc=el.getBoundingClientRect();'
           . 'try{el.scrollIntoView({block:"center"})}catch(e){}'
           . 'if(fr){window.scrollTo(0,Math.max(0,fr.getBoundingClientRect().top+window.pageYOffset+rc.top-220))}'
           . 'hl(el);ban(a+" steht hier (gelb markiert, Ansicht Visuell). Zum Schließen klicken.")}'
           . 'function txt(){var t=document.getElementById("content");if(!t){return}'
           . 'if(window.switchEditors&&document.getElementById("wp-content-wrap")&&document.getElementById("wp-content-wrap").className.indexOf("tmce-active")>=0){try{switchEditors.go("content","html")}catch(e){}}'
           . 'setTimeout(function(){var v=t.value,i=v.indexOf(a);if(i<0){ban("Im Text habe ich "+a+" nicht gefunden (vielleicht über einen Platzhalter oder eine Austausch-Regel).");return}'
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
        if (in_array($tag, self::ORD_TAGS, true) && self::ord_aktiv()) { self::$ord++; self::$ord_stack[] = self::$ord; }
        return $r;
    }

    public static function ord_post($out, $tag, $attr, $m) {
        if (!in_array($tag, self::ORD_TAGS, true) || !self::$ord_stack) { return $out; }
        $n = array_pop(self::$ord_stack);
        return str_replace('data-sab-asin="', 'data-sab-ord="' . (int) $n . '" data-sab-asin="', (string) $out);
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
        if ($ord < 1 || !isset($st[$ord - 1])) { wp_send_json_error('Die Stelle passt nicht mehr zum Artikeltext. Bitte die Seite neu laden.'); }
        [$tag, $pos] = $st[$ord - 1];
        $quelle = '';
        if (preg_match_all('/\b[A-Z0-9]{10}\b/', $tag, $tm)) { foreach (array_unique($tm[0]) as $t) { if (self::getauscht($t) === $asin) { $quelle = $t; break; } } }
        if ($quelle === '') { wp_send_json_error('An dieser Stelle steht keine feste ASIN (vermutlich eine Ersatzliste/Slot). Bitte im Reiter „Produkte und Ersatzlisten“ ändern.'); }
        return [$post, $c, $st, $ord, $tag, $pos, $quelle, $asin];
    }

    public static function ajax_stelle_kand(): void {
        [$post, , , , , , , $asin] = self::stelle_pruefen();
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
        wp_send_json_success(['alt' => $name ?: $asin, 'liste' => $out, 'zurueck' => (bool) get_post_meta($post->ID, '_sab_stelle_backup', true)]);
    }

    public static function ajax_stelle_tausch(): void {
        [$post, $c, $st, $ord, $tag, $pos, $quelle, $asin] = self::stelle_pruefen();
        $neu = self::asin((string) ($_POST['neu'] ?? ''));
        if (strlen($neu) !== 10 || $neu === $asin) { wp_send_json_error('Bitte eine gültige, andere ASIN angeben.'); }
        $namen = self::produkte(); $neuer = (string) ($namen[$neu] ?? '');
        $alte = array_filter([(string) ($namen[$asin] ?? ''), (string) ($namen[$quelle] ?? '')]);
        // gleiche Tabellenzelle: alle zählenden Platzhalter desselben Produkts mitnehmen (Bild, Titel, Link)
        $von = $pos; $bis = $pos + strlen($tag);
        $td0 = strripos(substr($c, 0, $pos), '<td'); $td1 = strripos(substr($c, 0, $pos), '</td>');
        if ($td0 !== false && ($td1 === false || $td1 < $td0)) {
            $e = stripos($c, '</td>', $pos);
            if ($e !== false) { $von = $td0; $bis = $e; }
        }
        $seg = substr($c, $von, $bis - $von);
        $seg2 = preg_replace('/\btitel="[^"]*"/u', '', $seg);   // dein alter Titel passt nicht zum neuen Produkt
        [$seg3, $n] = self::tausch_text($seg2, $quelle, $neu, $alte, $neuer);
        if ($n === 0 || $seg3 === $seg) { wp_send_json_error('Nichts geändert.'); }
        $c2 = substr($c, 0, $von) . $seg3 . substr($c, $bis);
        $b = (array) get_post_meta($post->ID, '_sab_stelle_backup', true);
        $b = array_slice(array_merge($b, [['ts' => time(), 'hash' => md5($c2), 'vorher' => $c, 'alt' => $asin, 'neu' => $neu]]), -5);
        update_post_meta($post->ID, '_sab_stelle_backup', wp_slash($b));
        wp_update_post(['ID' => $post->ID, 'post_content' => wp_slash($c2)]);
        delete_transient('sab_alle_asins');
        wp_send_json_success(['n' => $n]);
    }

    public static function ajax_stelle_zurueck(): void {
        check_ajax_referer('sab_stelle', 'n');
        $pid = (int) ($_POST['post'] ?? 0);
        if (!$pid || !current_user_can('edit_post', $pid)) { wp_send_json_error('Keine Berechtigung.'); }
        $b = (array) get_post_meta($pid, '_sab_stelle_backup', true); $post = get_post($pid);
        $l = end($b);
        if (!$post || !$l) { wp_send_json_error('Es gibt nichts rückgängig zu machen.'); }
        if (md5((string) $post->post_content) !== ($l['hash'] ?? '')) { wp_send_json_error('Der Artikel wurde seitdem geändert; Rückgängig ist zu riskant. Bitte über „Bearbeiten“ prüfen.'); }
        wp_update_post(['ID' => $pid, 'post_content' => wp_slash((string) $l['vorher'])]);
        array_pop($b); update_post_meta($pid, '_sab_stelle_backup', wp_slash($b));
        wp_send_json_success(['alt' => $l['alt'] ?? '', 'neu' => $l['neu'] ?? '']);
    }

    public static function stellen_skript(): void {
        if (!function_exists('is_user_logged_in') || !is_user_logged_in() || !current_user_can('edit_posts') || !is_singular()) { return; }
        $cfg = wp_json_encode(['u' => admin_url('admin-ajax.php'), 'n' => wp_create_nonce('sab_stelle'), 'p' => (int) get_queried_object_id()]);
        echo '<script>(function(){var C=' . $cfg . ';var seen={};'
           . 'function post(a,d,f){var fd=new FormData();fd.append("action",a);fd.append("n",C.n);fd.append("post",C.p);for(var k in d){fd.append(k,d[k])}fetch(C.u,{method:"POST",body:fd,credentials:"same-origin"}).then(function(r){return r.json()}).then(f).catch(function(){f({success:false,data:"Verbindungsfehler"})})}'
           . 'function el(t,s,x){var e=document.createElement(t);if(s){e.style.cssText=s}if(x!==undefined){e.textContent=x}return e}'
           . 'function oeffne(o,asin){var ov=el("div","position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.55);display:flex;align-items:flex-start;justify-content:center;padding:40px 12px;overflow:auto");'
           . 'var bx=el("div","background:#fff;color:#222;max-width:640px;width:100%;border-radius:10px;padding:18px;font:14px/1.4 sans-serif;box-shadow:0 8px 30px rgba(0,0,0,.4)");ov.appendChild(bx);'
           . 'ov.onclick=function(e){if(e.target===ov){ov.remove()}};document.body.appendChild(ov);'
           . 'bx.appendChild(el("h3","margin:0 0 6px;font-size:18px","Produkt an dieser Stelle tauschen"));var inf=el("p","margin:0 0 10px;color:#555","Lade Vorschläge …");bx.appendChild(inf);'
           . 'var lst=el("div","");bx.appendChild(lst);'
           . 'function tausche(neu){inf.textContent="Tausche …";post("sab_stelle_tausch",{ord:o,asin:asin,neu:neu},function(r){if(r.success){inf.textContent="Getauscht. Seite wird neu geladen …";location.reload()}else{inf.textContent="Nicht möglich: "+r.data;inf.style.color="#a00"}})}'
           . 'post("sab_stelle_kand",{ord:o,asin:asin},function(r){if(!r.success){inf.textContent=r.data;inf.style.color="#a00";return}'
           . 'inf.textContent="Jetzt: "+r.data.alt+". Nur diese Stelle im Artikel wird getauscht (mit Sicherung).";'
           . 'r.data.liste.forEach(function(c){var row=el("div","display:flex;gap:10px;align-items:center;padding:8px 0;border-top:1px solid #eee");'
           . 'if(c.img){var im=el("img","width:56px;height:56px;object-fit:contain;flex:none");im.src=c.img;row.appendChild(im)}'
           . 'var tx=el("div","flex:1;min-width:0");tx.appendChild(el("strong","",c.name||c.asin));tx.appendChild(el("div","font-size:12px;color:#555","Score "+(c.score===null?"?":c.score)+(c.preis?" · "+c.preis:"")+" · "+c.quelle+(c.ok?"":" · nicht lieferbar")));row.appendChild(tx);'
           . 'var bt=el("button","padding:6px 12px;border:0;border-radius:6px;background:#2271b1;color:#fff;cursor:pointer;font-weight:600","Einsetzen");bt.onclick=function(){tausche(c.asin)};row.appendChild(bt);lst.appendChild(row)});'
           . 'if(!r.data.liste.length){lst.appendChild(el("p","","Keine passenden Vorschläge. Gib unten eine ASIN ein."))}'
           . 'var fr=el("div","display:flex;gap:8px;align-items:center;padding-top:10px;border-top:1px solid #eee");var ip=el("input","flex:1;padding:6px;border:1px solid #bbb;border-radius:6px");ip.placeholder="Eigene ASIN, z. B. B0XXXXXXXX";var b2=el("button","padding:6px 12px;border:0;border-radius:6px;background:#2271b1;color:#fff;cursor:pointer;font-weight:600","Einsetzen");b2.onclick=function(){tausche(ip.value.trim().toUpperCase())};fr.appendChild(ip);fr.appendChild(b2);bx.appendChild(fr);'
           . 'if(r.data.zurueck){var zu=el("a","display:inline-block;margin-top:10px;color:#a00;cursor:pointer;text-decoration:underline","Letzten Tausch in diesem Artikel rückgängig machen");zu.onclick=function(){post("sab_stelle_zurueck",{},function(q){if(q.success){location.reload()}else{inf.textContent=q.data;inf.style.color="#a00"}})};bx.appendChild(zu)}'
           . '});}'
           . 'function mk(e){var o=e.getAttribute("data-sab-ord");if(!o||seen[o]){return}seen[o]=1;var box=e.closest(".sab-karte,.sab-produkt,td")||e.parentNode;if(getComputedStyle(box).position==="static"){box.style.position="relative"}'
           . 'var b=el("button","position:absolute;top:4px;right:4px;z-index:50;font:600 12px sans-serif;padding:3px 8px;border:1px solid #2271b1;border-radius:12px;background:#fff;color:#2271b1;cursor:pointer;opacity:.9","⇄ tauschen");b.type="button";b.title="Nur für dich sichtbar (angemeldet)";b.onclick=function(ev){ev.preventDefault();ev.stopPropagation();oeffne(o,e.getAttribute("data-sab-asin"))};box.appendChild(b)}'
           . 'var es=document.querySelectorAll("[data-sab-ord]");for(var i=0;i<es.length;i++){mk(es[i])}})();</script>';
    }


    /** Durchsucht alle Artikel nach Partner-IDs (Amazon: tag=, tracking_id; eBay: campid) und zählt Stellen und Artikel. @return array{amazon:array,ebay:array} */
    private static function ids_scan(): array {
        global $wpdb; $out = ['amazon' => [], 'ebay' => [], 'ohne' => []];
        if (!isset($wpdb)) { return $out; }
        $rows = $wpdb->get_results("SELECT ID, post_title, post_content FROM {$wpdb->posts} WHERE post_status IN ('publish','draft','private') AND post_type IN ('post','page') AND (post_content LIKE '%tag=%' OR post_content LIKE '%tracking_id%' OR post_content LIKE '%campid%' OR post_content LIKE '%amazon.%' OR post_content LIKE '%amzn.%')", ARRAY_A);
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

    /** Entfernt ASINs aus allen Listen (Produkte, Handdaten, Slots, Tests, Gruppen, Tausch, Alternativen, Kandidaten, Markierungen); Rückgabe: Zahl der entfernten ASINs. */
    private static function verwaiste_entfernen(array $asins): int {
        if (!$asins) { return 0; }
        $set = array_flip($asins);
        $schluessel = ['sab_produkte', 'sab_manuell', 'sab_amz_alt', 'sab_such_kand', 'sab_auch_gekauft'];   // erste Spalte = ASIN: ganze Zeile weg
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
        echo '<script>(function(){var ed=' . wp_json_encode($ed) . ',a=' . wp_json_encode($a) . ',s=document.querySelectorAll("[data-sab-weg=\\""+a+"\\"],[data-sab-asin=\\""+a+"\\"],.entry-content a[href*=\\""+a+"\\"]");'
           . 'var b=document.createElement("div");b.style.cssText="position:fixed;left:0;right:0;top:0;z-index:99999;background:#d63638;color:#fff;padding:10px 16px;font:600 15px sans-serif;text-align:center";'
           . 'b.textContent=s.length?"Hier steht "+a+" ("+s.length+" Stelle"+(s.length>1?"n":"")+", rot markiert)":"Im sichtbaren Text steht "+a+" nicht"+(ed?"; ich öffne den Editor an der Stelle ...":" (vielleicht in einem Bild oder Code-Block).");document.body.appendChild(b);if(!s.length&&ed){setTimeout(function(){location.href=ed},1200)}'
           . 'for(var i=0;i<s.length;i++){s[i].style.outline="4px solid #d63638";s[i].style.background="#fde8e8";}'
           . 'if(s.length){setTimeout(function(){s[0].scrollIntoView({block:"center"})},300)}})();</script>';
    }

    public static function sc_link($a, $inhalt = ''): string {
        $a = shortcode_atts(['asin' => '', 'land' => '', 'class' => '', 'tag' => ''], $a);
        $orig = self::asin($a['asin']);
        $asin = self::getauscht($orig);
        $p = self::produkt($asin, self::land($a['land']));
        if ($asin !== $orig && trim((string) $inhalt) !== '') { $inhalt = self::name_tauschen((string) $inhalt, $orig, $asin, self::land($a['land']), $p); }
        if ($p && !empty($p['weg'])) { return self::weg_marker($asin, esc_html($inhalt)); }
        $link = $p['link'] ?? '';
        if (!$link) { // Amazon nicht erreichbar und kein frischer Stand: schlichter Link mit Partner-ID
            $land = self::land($a['land']); $tag = self::tag($land);
            if (!$asin || !$tag) { return esc_html($inhalt); }
            $link = 'https://' . self::LAENDER[$land][0] . '/dp/' . $asin . '?tag=' . rawurlencode($tag);
        }
        $link = self::lnk($link, (string) $a['tag']);
        $cls = trim(preg_replace('/[^A-Za-z0-9 _-]/', '', (string) $a['class']));
        return '<a' . ($cls ? ' class="' . esc_attr($cls) . '"' : '') . ' href="' . esc_url($link) . '"' . self::a_attr($asin) . '>' . esc_html($inhalt ?: ($p['titel'] ?? '')) . '</a>';
    }

    // ---------- Eigene Klickmessung (ohne Cookies, ohne IP, ohne Besucher-Kennung) ----------

    private static function klicktabelle(): string { global $wpdb; return $wpdb->prefix . 'sab_klicks'; }

    public static function tabelle(): void {
        global $wpdb;
        if (!isset($wpdb) || get_option('sab_db_version') === '2') { return; }
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
            zurueck_s INT UNSIGNED NULL,
            PRIMARY KEY (id),
            KEY asin (asin),
            KEY kid (kid)
        ) {$wpdb->get_charset_collate()};");
        update_option('sab_db_version', '2', false);
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
        if (isset($d['s'])) {
            $sek = (int) $d['s'];
            if ($sek > 0 && $sek <= 3 * HOUR_IN_SECONDS) {
                $wpdb->query($wpdb->prepare("UPDATE $t SET zurueck_s=%d WHERE kid=%s AND zurueck_s IS NULL", $sek, $d['id']));
            }
        } else {
            $asin = self::asin((string) ($d['asin'] ?? ''));
            if (strlen($asin) === 10 && in_array($asin, self::alle_asins(), true)) {
                $typ = in_array($d['typ'] ?? '', ['bild', 'box', 'text'], true) ? $d['typ'] : 'text';
                $wpdb->insert($t, [
                    'ts' => gmdate('Y-m-d H:i:s'), 'kid' => $d['id'], 'asin' => $asin,
                    'slot' => substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($d['slot'] ?? '')), 0, 40),
                    'var' => substr(preg_replace('/[^A-D]/', '', (string) ($d['var'] ?? '')), 0, 1),
                    'typ' => $typ, 'pfad' => substr((string) wp_parse_url((string) ($d['pfad'] ?? ''), PHP_URL_PATH), 0, 190),
                ]);
            }
        }
        return new WP_REST_Response(null, 204);
    }

    /** Kleines Skript, nur auf Seiten mit Produkten. Misst Klick und die Zeit bis zur Rückkehr in den Tab. */
    public static function messskript(): void {
        if (!self::$genutzt || (function_exists('is_user_logged_in') && is_user_logged_in() && current_user_can('edit_posts'))) { return; }   // angemeldete Redakteure werden nicht gemessen
        $url = esc_url_raw(rest_url('sab/v1/m'));
        echo '<script>(function(){var U=' . wp_json_encode($url) . ',p=null;'
           . 'function S(o){try{navigator.sendBeacon(U,new Blob([JSON.stringify(o)],{type:"text/plain"}))}catch(e){}}'
           . 'document.addEventListener("click",function(e){var a=e.target.closest&&e.target.closest("a[data-sab-asin]");if(!a)return;'
           . 'var id=Math.random().toString(36).slice(2,10),c=a.classList;'
           . 'S({id:id,asin:a.getAttribute("data-sab-asin"),slot:a.getAttribute("data-sab-slot")||"",var:a.getAttribute("data-sab-var")||"",typ:(e.target.closest&&e.target.closest(".sab-karte-btn"))?"text":c.contains("sab-bild")?"bild":c.contains("sab-link")?"box":"text",pfad:location.pathname});'
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
            $out[$r['asin']] = $r;
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
        foreach (self::slots() as $liste) { $alle = array_merge($alle, $liste); }
        foreach (self::tests() as $liste) { $alle = array_merge($alle, $liste); }
        $alle = array_merge($alle, array_values(self::tausch_regeln()));
        foreach (self::gruppen() as $liste) { $alle = array_merge($alle, $liste); }
        foreach (self::amz_alternativen() as $l) { $alle = array_merge($alle, $l['liste']); }   // Amazons Alternativen mit abfragen (Score)
        foreach (self::such_kandidaten() as $l) { $alle = array_merge($alle, $l['liste']); }
        foreach (self::auch_gekauft() as $l) { $alle = array_merge($alle, $l['liste']); }   // Mitgekauftes mit abfragen (Score)
        foreach (self::claude_empfehlungen() as $l) { $alle[] = $l[0]; }
        global $wpdb;
        if (isset($wpdb)) {
            $host = preg_quote(preg_replace('#^www\.#', '', self::LAENDER[self::land('')][0]), '#');   // nur Links ins Land dieser Webseite, sonst sähen Produkte anderer Länder „unbekannt“ aus
            $rows = $wpdb->get_col("SELECT post_content FROM {$wpdb->posts} WHERE post_status='publish' AND post_type IN ('post','page') AND (post_content LIKE '%[amz\\_%' OR post_content LIKE '%[aawp%' OR post_content LIKE '%[amazon %' OR post_content LIKE '%amazon.%')");
            foreach ((array) $rows as $c) {
                $tx = self::nur_textlink_neben_bild((string) $c); $vor_n = count($alle);
                if (preg_match_all('#https?://(?:www\.)?' . $host . '/(?:[^"\'\s<>]*/)?(?:dp|gp/product)/([A-Za-z0-9]{10})#', $c, $m)) { $alle = array_merge($alle, array_map('strtoupper', $m[1])); }   // feste Links
                // alle Plugin-Shortcodes mit asin="..." (bild, link, produkt) und die AAWP-Zeilen (fields/box/link, mehrere ASINs mit Komma)
                if (preg_match_all('/\[amz_(?:bild|link|produkt)[^\]]*asin="([A-Za-z0-9]{10})"/', $c, $m)) { $alle = array_merge($alle, array_map([__CLASS__, 'asin'], $m[1])); }
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
            $x['trotz'] = false; [$sc, , $det] = self::score($x); $dr = self::tausch_dringlichkeit($x, $sc);
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
            $h .= '<tr><td><strong>' . esc_html($name) . '</strong></td><td><input type="number" min="0" max="1000000" name="sab_w[' . esc_attr($k) . ']" value="' . (int) $w[$k] . '" style="width:100px"> <small>(' . round(100 * $w[$k] / max(1, $summe)) . ' %)</small></td><td><small>' . esc_html($erkl[$k]) . '</small></td></tr>';
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
        }
        $h .= '<h3 style="margin-top:2em">Von Hand gepflegte Daten</h3><p class="description">Die Amazon-Schnittstelle liefert keine Sterne, kein Prime und keine Lieferzeit. Trag sie hier ein, eine Zeile pro Produkt: <code>ASIN | Sterne | Anzahl Bewertungen | Prime (ja/nein) | Lieferzeit in Tagen je Hauptstadt | Datum | Provision in %</code>, zum Beispiel <code>B001AUKTYG | 4,1 | 588 | ja | ' . (self::land('') === 'de' ? 'Berlin 2, München 3, Hamburg 2' : 'Madrid 2, Barcelona 3, Sevilla 2') . ' | 2026-10-24 | 3</code>. Die Provision steht in der SiteStripe auf der Amazon-Seite (Tarif); sie gilt für dieses Produkt und geht der Kategorietabelle vor. Leere Felder sind erlaubt. Die Angaben erscheinen als Chips in der Übersicht und fließen in den Score ein (Gewicht oben).</p>'
           . '<form method="post">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="score"><input type="hidden" name="sab_form" value="manuell">'
           . '<textarea name="sab_manuell" rows="10" class="large-text code">' . esc_textarea((string) get_option('sab_manuell', '')) . '</textarea><p><button class="button button-primary">Handdaten speichern</button></p></form>';
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

    /** Reiter „Statistik“: Klicks pro Tag als Balkengrafik und sortierbare Liste der meistgeklickten Produkte (mit Rückkehr und Zeit). */
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
        $svg = '<svg viewBox="0 0 ' . $br . ' ' . ($hoch + 22) . '" style="max-width:100%;height:auto;background:#fff;border:1px solid #ccd0d4" role="img" aria-label="Klicks pro Tag">';
        $svg .= '<text x="4" y="12" font-size="11" fill="#555">max. ' . $max . ' Klicks/Tag</text>';
        $k = 0;
        foreach ($werte as $d => $n) {
            $h = (int) round(($hoch - 18) * $n / $max); $x = $k * $bw;
            $svg .= '<rect x="' . round($x + 1, 1) . '" y="' . ($hoch - $h) . '" width="' . round(max($bw - 2, 1), 1) . '" height="' . $h . '" fill="#2271b1"><title>' . esc_html(wp_date('d.m.Y', strtotime($d . ' 12:00:00 UTC')) . ': ' . $n . ' Klicks') . '</title></rect>';
            if ($k % max(1, (int) ceil($tage / 10)) === 0) { $svg .= '<text x="' . round($x, 1) . '" y="' . ($hoch + 14) . '" font-size="10" fill="#555">' . esc_html(wp_date('d.m.', strtotime($d . ' 12:00:00 UTC'))) . '</text>'; }
            $k++;
        }
        $svg .= '</svg>';
        $namen = self::produkte();
        $zeilen = (array) $wpdb->get_results($wpdb->prepare("SELECT asin, COUNT(*) n, SUM(typ='bild') b, SUM(typ<>'bild') x, SUM(zurueck_s IS NOT NULL) z, AVG(zurueck_s) m FROM $t WHERE ts >= %s GROUP BY asin ORDER BY n DESC", $ab), ARRAY_A);
        $us = (array) get_option('sab_update_status', []);
        $h = '<h2>Statistik</h2><p>Zeitraum: ';
        foreach ([7, 30, 90] as $z) { $h .= ($z === $tage ? '<strong>' . $z . ' Tage</strong>' : '<a href="' . esc_url(add_query_arg(['page' => 'sab-statistik', 'tage' => $z], admin_url('admin.php'))) . '">' . $z . ' Tage</a>') . ' · '; }
        $h .= '<strong>' . $summe . '</strong> Klicks gesamt. Gezählt werden Klicks auf Plugin-Links; Bots werden ausgelassen.</p>' . $svg
          . '<p class="description">Update-Prüfung: ' . ($us ? esc_html(wp_date('d.m.Y H:i', (int) $us['ts']) . ', Antwort ' . $us['code'] . ', Version im Repo ' . ($us['version'] ?: '–')) : 'noch nicht gelaufen') . '</p>';
        $h .= '<h3 style="margin-top:1.5em">Meistgeklickte Produkte</h3><p class="description">Auf eine Überschrift klicken sortiert die Liste (nochmal klicken: umgekehrt). „Zurück“ = Besucher kam nach dem Klick auf die Webseite zurück.</p>';
        if (!$zeilen) { return $h . '<p>Noch keine Klicks in diesem Zeitraum.</p>'; }
        $h .= '<style>table.sab-sort thead th{position:sticky;top:32px;z-index:3;background:#f6f7f7;box-shadow:0 1px 0 #c3c4c7}</style><table class="widefat striped sab-sort"><thead><tr><th>Produkt</th><th data-z="1">Klicks</th><th data-z="1">Anteil</th><th data-z="1">auf Bild</th><th data-z="1">auf Text/Button</th><th data-z="1">Zurück</th><th data-z="1">Zurück in %</th><th data-z="1">Ø Zeit bis zurück</th></tr></thead><tbody>';
        foreach ($zeilen as $r) {
            $n = (int) $r['n']; $z = (int) $r['z'];
            $h .= '<tr><td data-v="' . esc_attr(strtolower(($namen[$r['asin']] ?? '') . $r['asin'])) . '"><code>' . esc_html($r['asin']) . '</code> ' . esc_html($namen[$r['asin']] ?? '') . '</td>'
                . '<td data-v="' . $n . '">' . $n . '</td><td data-v="' . $n / max($summe, 1) . '">' . round(100 * $n / max($summe, 1)) . ' %</td>'
                . '<td data-v="' . (int) $r['b'] . '">' . (int) $r['b'] . '</td><td data-v="' . (int) $r['x'] . '">' . (int) $r['x'] . '</td>'
                . '<td data-v="' . $z . '">' . $z . '</td><td data-v="' . ($n ? $z / $n : 0) . '">' . ($n ? round(100 * $z / $n) . ' %' : '–') . '</td>'
                . '<td data-v="' . ($z ? (float) $r['m'] : -1) . '">' . ($z ? esc_html(self::dauer_text((float) $r['m'])) : '–') . '</td></tr>';
        }
        $h .= '</tbody></table><script>(function(){var T=document.querySelector(".sab-sort");if(!T)return;T.tHead.querySelectorAll("th").forEach(function(th,i){th.style.cursor="pointer";th.addEventListener("click",function(){var b=T.tBodies[0],r=[].slice.call(b.rows),d=th.dataset.d=th.dataset.d==="1"?"-1":"1";'
            . 'r.sort(function(x,y){var a=x.cells[i].dataset.v,c=y.cells[i].dataset.v,A=parseFloat(a),C=parseFloat(c);var u=isNaN(A)||isNaN(C)?(a<c?-1:a>c?1:0):A-C;return u*d});r.forEach(function(e){b.appendChild(e)})})})})();</script>';
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

    /** Artikel (Titel), die durch den Tausch ALT => NEU eine Doppelung von NEU bekämen. */
    private static function doppel_durch_tausch(string $alt, string $neu): array {
        global $wpdb; $out = [];
        if (!isset($wpdb)) { return $out; }
        $rows = $wpdb->get_results($wpdb->prepare("SELECT ID, post_title, post_content FROM {$wpdb->posts} WHERE post_status IN ('publish','draft','private') AND post_type IN ('post','page') AND post_content LIKE %s", '%' . $wpdb->esc_like($alt) . '%'), ARRAY_A);
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
                        . ($f['art'] === 'nebeneinander' ? '<strong style="color:#a00">nebeneinander</strong> (zwei Spalten derselben Tabelle)' : '<strong style="color:#b36b00">zu nah</strong> (nur ' . (int) $f['abstand'] . ' Zeichen Abstand)') . '</td></tr>';
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
            if (!$x) { $weg[] = $asin; continue; }
            if (isset(((array) get_option('sab_trotz', []))[$asin])) { continue; }  // von dir als lieferbar bestätigt
            if (($x['preis'] ?? '') === '' && (($x['rang'] ?? null) === null || $x['rang'] > 300000)) { $wa[] = $asin; continue; }   // kein Preis und kein (oder ein sehr schlechter) Rang: wahrscheinlich nicht mehr kaufbar
            if (self::unklar($x)) { $uk[] = $asin; } elseif (empty($x['lieferbar']) || ($x['status'] ?? '') === 'OUT_OF_STOCK') { $nv[] = $asin; }
        }
        $favs = array_keys(array_filter((array) get_option('sab_marken', []), fn($t) => $t === 'fav'));
        // Priorität 1 zusätzlich: Prime-Status trotz Messung unklar (meist nicht mehr oder gerade nicht verfügbar); Priorität 2: Status klar, aber sehr schlechter Score
        $p1x = []; $p2x = []; $grund2 = []; $vorher = array_merge($weg, $nv, $uk, $wa);
        foreach ((array) ($d['items'] ?? []) as $a_ => $x_) {
            $a_ = (string) $a_; if (in_array($a_, $vorher, true) || isset($trotz[$a_])) { continue; }
            if (array_key_exists('manuell_stand', $x_) && ($x_['prime'] ?? null) === null) { $p1x[] = $a_; continue; }
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
        $gew = function ($a) use ($fund, $d, $p2x) { $n = array_sum(array_column($fund[$a] ?? [], 'n')); return in_array($a, $p2x, true) ? self::tausch_dringlichkeit((array) $d['items'][$a], self::score((array) $d['items'][$a])[0]) * $n : 1000000 + $n; };
        uksort($faelle, fn($a, $b) => $gew($b) <=> $gew($a));
        $h .= self::doppel_html((array) $d) . '<hr style="margin:2em 0">' . self::weg_html($weg, (array) $d, $fund, $trotz, $dp) . '<hr style="margin:2em 0">' . self::assistent_html(array_diff_key($faelle, array_flip($weg)), (array) $d, $empf, $fund, $dp) . '<hr style="margin:2em 0"><h3>Alle Problemprodukte als Liste</h3>';
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
        $vor = substr($c, max(0, $pos - 700), min($pos, 700));
        if (preg_match_all('#<(strong|b|h[2-5])\b[^>]*>(.*?)</\1>|<span id="productTitle">(.*?)</span>#is', $vor, $m, PREG_SET_ORDER)) {
            $l = end($m); $tx = trim(html_entity_decode(strip_tags((string) (($l[2] ?? '') !== '' ? $l[2] : ($l[3] ?? '')))));
            if ($tx !== '') { return mb_substr($tx, 0, 140); }
        }
        return '';
    }

    /** Austausch im Text: ASIN überall (Platzhalter, Links, aawp), dazu der alte Name durch den neuen. @return array{0:string,1:int} [neuer Text, Zahl der Ersetzungen] */
    public static function tausch_text(string $c, string $alt, string $neu, array $alte_namen, string $neuer_name): array {
        $n = substr_count($c, $alt);
        $c = str_replace($alt, $neu, $c);
        if ($neuer_name !== '') {
            foreach (array_unique(array_filter(array_map('trim', $alte_namen))) as $name) {
                if (mb_strlen($name) >= 6 && $name !== $neuer_name && strpos($c, $name) !== false) { $n += substr_count($c, $name); $c = str_replace($name, $neuer_name, $c); }
            }
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
    private static function tausch_festschreiben(string $alt, string $neu, string $neuer_name, array $alte_namen): array {
        global $wpdb;
        if (!isset($wpdb) || strlen($alt) !== 10 || strlen($neu) !== 10 || $alt === $neu) { return [0, 0, 'Ungültige Angabe.', [], []]; }
        $rows = $wpdb->get_results($wpdb->prepare("SELECT ID, post_title, post_content FROM {$wpdb->posts} WHERE post_status IN ('publish','draft','private') AND post_type IN ('post','page') AND post_content LIKE %s", '%' . $wpdb->esc_like($alt) . '%'), ARRAY_A);
        $log = (array) get_option('sab_tausch_log', []); $id = (string) (time()) . substr(md5($alt . $neu), 0, 4); $posts = []; $kurz = 0; $doppel = []; $hinweise = [];
        foreach ((array) $rows as $r) {
            $pid = (int) $r['ID']; $c = (string) $r['post_content'];
            [$c1, $nk1, $hw] = self::tausch_kurzlinks($c, $alt, $neu, $neuer_name);   // Kurzlinks derselben Spalte mitnehmen, sonst zeigt der Textlink aufs alte Produkt
            [$neu_c, $n] = self::tausch_text($c1, $alt, $neu, $alte_namen, $neuer_name); $n += $nk1;
            if ($n === 0 || $neu_c === $c) { continue; }
            if (self::doppel_neu($c, $neu_c, $neu)) { $doppel[] = (string) ($r['post_title'] ?? ('Beitrag ' . $pid)); continue; }   // Schutz: kein Produkt doppelt nebeneinander oder zu nah
            update_post_meta($pid, '_sab_tausch_' . $id, wp_slash($c));
            wp_update_post(['ID' => $pid, 'post_content' => wp_slash($neu_c)]);
            $posts[$pid] = md5($neu_c);
            foreach ($hw as $w) { $hinweise[] = ($r['post_title'] ?? ('Beitrag ' . $pid)) . ': ' . $w; }
            $kurz += preg_match_all('#amzn\.to|amzn\.eu|a\.co/|link\.amazon/#i', $neu_c);
        }
        if ($neuer_name !== '' && !isset(self::produkte()[$neu])) { update_option('sab_produkte', trim((string) get_option('sab_produkte', '')) . "\n" . $neu . ' | ' . $neuer_name, false); }
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
                   . (!empty($x['deal']) ? '<span class="sab-chip warn">' . esc_html($x['deal']) . '</span>' : '')
                   . (isset($x['sterne']) && $x['sterne'] !== null ? '<span class="sab-chip" title="von Hand gepflegt' . (!empty($x['manuell_stand']) ? ', Stand ' . esc_attr($x['manuell_stand']) : '') . '">★ ' . esc_html(str_replace('.', ',', (string) $x['sterne'])) . ' (' . (int) ($x['anzahl'] ?? 0) . ')</span>' : '')
                   . '<span class="sab-chip" style="padding:0;background:none">' . self::prime_zeichen(isset($x['prime']) ? $x['prime'] : null) . '</span>'
                   . (isset($x['liefertage']) && $x['liefertage'] !== null ? '<span class="sab-chip ' . ($x['liefertage'] <= 3 ? 'ok' : ($x['liefertage'] >= 7 ? 'bad' : 'warn')) . '" title="Lieferzeit Ø in Tagen: ' . esc_attr((string) ($x['liefertext'] ?? '')) . '">&#128666; ' . esc_html(str_replace('.', ',', (string) round((float) $x['liefertage'], 1))) . ' T</span>' : '');
            if (((!isset($x['sterne']) || $x['sterne'] === null) && empty($x['ohne_bewertung']) || !isset($x['prime']) || $x['prime'] === null || !isset($x['liefertage']) || $x['liefertage'] === null) && !(!empty($x['manuell_stand']) && ($lief[0] === 'bad' || !empty($x['gelesen_weg'])))) { $chips .= '<span class="sab-chip warn" title="Sterne, Prime oder Lieferzeit fehlen (Reiter Score, Handdaten)">Handdaten fehlen</span>'; }
            $sp = (array) ($x['spar'] ?? []);
            $preis = ($x['preis'] ?? '') !== '' ? '<strong>' . esc_html($x['preis']) . '</strong>' . (!empty($sp['streich']) ? '<br><small><s>' . esc_html($sp['streich']) . '</s>' . (isset($sp['prozent']) ? ' &minus;' . (int) $sp['prozent'] . ' %' : '') . '</small>' : '') : '–';
            $rang = isset($x['rang']) && $x['rang'] !== null ? '<span title="' . esc_attr((string) ($x['kategorie'] ?? '')) . '">' . number_format((int) $x['rang'], 0, ',', '.') . '</span>' : '–';
            $fs = $fund[$asin] ?? []; $ns = count($fs); $nl = array_sum(array_column($fs, 'n')); $mx = $fs ? max(array_column($fs, 'n')) : 0;
            $seiten = $ns ? '<span title="' . esc_attr($nl . ' Links insgesamt, höchstens ' . $mx . ' auf einer Seite') . '"><strong>' . $ns . '</strong> <small>(' . $nl . '×' . ($mx > 2 ? ', max <b style="color:#a00">' . $mx . '</b>' : ', max ' . $mx) . ')</small>' . ($ns > 10 ? ' <span class="sab-chip">allgemein</span>' : '') . '</span>' : '0';
            $dr = self::tausch_dringlichkeit($x, $sc); $prio = $dr * $ns;
            [$pot, $potwhy] = self::potenzial($x, $sc, $ns, (((array) get_option('sab_marken', []))[$asin] ?? '') === 'fav');
            $k = $kz[$asin] ?? null; $nk = $k ? (int) $k['n'] : 0;
            $klick = $k ? '<span title="Bild ' . (int) $k['b'] . ', Text/Button ' . (int) $k['x'] . '; zurückgekommen ' . (int) $k['z'] . '">' . $nk . '</span>' : '0';
            $mehr = '<details><summary>Details</summary><div style="max-width:420px;font-size:12px">'
                  . '<p><a href="' . esc_url($dp . $asin) . '" target="_blank" rel="noopener">' . esc_html($x['titel'] ?? '') . '</a></p>'
                  . '<p>Verkäufer: ' . esc_html($x['verkaeufer'] ?: '–') . '</p>'
                  . '<p>Kategorie: ' . esc_html((string) ($x['kategorie'] ?? '–')) . ($sch !== '' ? '<br><em>' . esc_html($sch) . '</em>' : '') . '</p>'
                  . '<p>' . self::lieferzelle($x) . '</p>'
                  . (($x['preis'] === '' || $x['verkaeufer'] === '') ? '<p style="color:#a00">' . esc_html($x['diag'] ?? '') . '</p><details><summary>Rohdaten</summary><code style="font-size:10px;word-break:break-all">' . esc_html((string) ($x['roh'] ?? '')) . '</code></details>' : '')
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
           . '<p class="description">Stand höchstens 1 Stunde alt. Bilder kommen hier direkt von Amazon (nur im Admin-Bereich, nicht für Besucher). Die Lieferzeit liefert die API nicht; sie ist nur auf der Amazon-Seite mit spanischer Lieferadresse sichtbar.</p>'
           . '<p class="description"><strong>Reihenfolge:</strong> nach Priorität. Priorität = Dringlichkeit (0 bis 100: nicht lieferbar oder Status unklar = 100, sonst schwacher Score, sehr lange Lieferzeit oder wenig Sterne) mal Zahl der Seiten, auf denen das Produkt steht. Oben steht also, was auf vielen Seiten dringend geprüft werden muss. Spaltenkopf anklicken, um anders zu sortieren.</p>'
           . '<p><button class="button" name="sab_neu" value="1">Übersicht neu laden</button></p>';
        $h .= self::stand_hinweis($d);
        if ($d['fehler']) { $h .= '<div class="notice notice-warning inline"><p>' . esc_html($d['fehler']) . '</p></div>'; }
        if ($d['fehler']) { return $h; }
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
            $pr_ = $ns > 0 ? self::tausch_dringlichkeit($x, $sc) * $ns : ($sc >= 60 ? $sc - 50 : -1);
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

    /** Kurzname im Stil „Produktart | Merkmal“: Marke und Beiwerk fallen weg, hinten steht eine aussagekräftige Zahl (Anzahl oder Maß), z. B. „Cuello de caracol | Set de 12“. */
    public static function kurzname_aus_titel(string $t): string {
        $t = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($t))));
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
                    if (self::umbau_url($k[2], true)) { continue; }   // lässt sich selbst auflösen: nichts ändern
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
            if (self::umbau_url($m[3], true)) { return $m[0]; }   // lässt sich selbst auflösen: nichts ändern
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
        $c = preg_replace_callback('#<a\b([^>]*)>(.*?)</a>#is', function ($m) use (&$n, &$skip, $nur) {
            if (!preg_match('#href=(["\'])(.*?)\1#is', $m[1], $h)) { return $m[0]; }
            $u = self::umbau_url($h[2], true);
            if (!$u) { return $m[0]; }
            [$land, $asin] = $u;
            if ($nur !== '' && $asin !== $nur) { return $m[0]; }
            if (!self::tag($land) || strpos($m[2], '[') !== false) { $skip++; return $m[0]; }
            if (!self::umbau_ok('weg:' . $asin) && self::asin_weg($asin, $land) && !self::weg_umbauen($asin)) { self::$umbau_keys[self::$umbau_abw[] = 'Link „' . mb_substr(trim(strip_tags($m[2])), 0, 40) . '“ zeigt auf ' . $asin . ', das Amazon nicht mehr kennt, nicht umgebaut (erst das Produkt tauschen)'] = 'weg:' . $asin; self::$umbau_weg[$asin] = 1; $skip++; return $m[0]; }
            $cls = '';
            if (preg_match('#class=(["\'])(.*?)\1#is', $m[1], $cl)) { $cls = trim(preg_replace('/[^A-Za-z0-9 _-]/', '', $cl[2])); }
            $attr = ' asin="' . $asin . '" land="' . $land . '"' . ($cls !== '' ? ' class="' . $cls . '"' : '');
            if (stripos($m[2], '<img') !== false) { $n++; return '[amz_bild' . $attr . ']' . $m[0] . '[/amz_bild]'; }
            $text = trim(html_entity_decode(strip_tags($m[2])));
            if ($text === '') { $skip++; return $m[0]; }
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
        $geaendert = 0; $links = 0; $skip = 0; self::$umbau_abw = []; self::$umbau_info = [];
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
             . ($skip ? ', ' . $skip . ' übersprungen (keine Partner-ID für das Land oder Sonderinhalt)' : '')
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
        $abw0 = count(self::$umbau_abw); $info0 = count(self::$umbau_info);
        [$neu, $n, $sk] = self::umbau_text((string) $post->post_content, $nur);
        for ($i = $abw0; $i < count(self::$umbau_abw); $i++) { self::$umbau_abw[$i] = $post->post_title . ': ' . self::$umbau_abw[$i]; }
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
        $rows = $wpdb->get_results("SELECT ID, post_title, post_content FROM {$wpdb->posts} WHERE post_status='publish' AND post_type IN ('post','page') AND (post_content LIKE '%amazon.%' OR post_content LIKE '%amzn.%' OR post_content LIKE '%link.amazon%' OR post_content LIKE '%[aawp%' OR post_content LIKE '%[amazon %') ORDER BY ID", ARRAY_A);
        foreach ((array) $rows as $r) {
            $c = (string) $r['post_content'];
            $c = preg_replace('#\[amz_(bild|produkt|link)\b[^\]]*\].*?\[/amz_\1\]#is', '', $c);
            $fest = 0; $kurz = 0;
            if (preg_match_all('/\[(?:aawp|amazon)\b[^\]]*\b(?:box|link|fields)=/i', $c, $mm)) { $fest += count($mm[0]); }   // AAWP- und [amazon]-Platzhalter zählen als umzubauen
            if (preg_match_all('#<a\b[^>]*href=(["\'])(.*?)\1#is', $c, $m)) {
                foreach ($m[2] as $url) {
                    if (self::umbau_url($url)) { $fest++; } elseif (preg_match('#^https?://(?:amzn\.to|amzn\.eu|a\.co|link\.amazon)/#i', html_entity_decode($url))) { $kurz++; }
                }
            }
            $fertig = get_post_meta((int) $r['ID'], '_sab_umbau_backup', true) !== '';
            if ($fest + $kurz > 0 || $fertig) { $out[] = ['id' => (int) $r['ID'], 'titel' => (string) $r['post_title'], 'fest' => $fest, 'kurz' => $kurz, 'fertig' => $fertig]; }
        }
        usort($out, fn($a, $b) => [(int) $a['fertig'], $b['fest'] + $b['kurz']] <=> [(int) $b['fertig'], $a['fest'] + $a['kurz']]);   // offene Artikel oben, die mit den meisten Links zuerst
        return $out;
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
    private static function umbau_uebersicht_html(int $seite): string {
        $seite = max(1, $seite); self::$vorschau_seite = $seite;
        $offen = array_values(array_filter(self::umbau_artikelliste(), fn($a) => !$a['fertig']));
        usort($offen, fn($a, $b) => ($a['fest'] + $a['kurz']) <=> ($b['fest'] + $b['kurz']));   // einfache Artikel (wenige Links) zuerst, schwierige später
        $gesamt = count($offen); $seiten = max(1, (int) ceil($gesamt / self::UMBAU_PROSEITE));
        $liste = array_slice($offen, ($seite - 1) * self::UMBAU_PROSEITE, self::UMBAU_PROSEITE);
        $h = '<h3>Vorschau für alle offenen Artikel (Seite ' . $seite . ' von ' . $seiten . ', ' . $gesamt . ' Artikel offen)</h3><p class="description">Es wird nichts gespeichert. Jeder Artikel wird so geprüft wie beim Umbau. „Bereit“ heißt: kein Problem gefunden. Probleme siehst du unten, dort kannst du auch Ersatz für Produkte wählen, die Amazon nicht mehr kennt.</p>';
        $zeilen = []; $bereit = []; $wegAlle = []; $kurzOffen = 0; $nProb = 0;
        $t_start = microtime(true); $uebrig = 0;
        foreach ($liste as $a) {
            if (microtime(true) - $t_start > 20) { $uebrig++; continue; }   // Zeitgrenze: Rest über „Nächste Seite“ beziehungsweise erneut öffnen
            $post = get_post($a['id']); if (!$post) { continue; }
            self::$umbau_abw = []; self::$umbau_info = []; self::$umbau_weg = []; self::$umbau_keys = []; self::$kurz_offen = 0; self::$neue_namen = [];
            try { self::umbau_text((string) $post->post_content); }
            catch (\Throwable $e) { self::$umbau_abw[] = 'Prüfung abgebrochen: ' . $e->getMessage(); }
            $abw = array_values(array_unique(self::$umbau_abw)); $info = count(array_unique(self::$umbau_info)); $kurzOffen += self::$kurz_offen;
            foreach (self::$umbau_weg as $w => $_) { $wegAlle[$w] = ($wegAlle[$w] ?? 0) + 1; }
            if ($abw) { $nProb++; $status = '<strong style="color:#a00">Problem (' . count($abw) . ')</strong>'; }
            elseif (self::$kurz_offen) { $status = '<strong style="color:#b36b00">noch nicht fertig geprüft</strong>'; }
            else { $status = '<strong style="color:#1a7f37">bereit</strong>' . ($info ? ' <small>(' . $info . ' Anpassung' . ($info === 1 ? '' : 'en') . ' nach dem Bild)</small>' : ''); $bereit[] = (int) $a['id']; }
            $meld = ''; foreach (array_slice($abw, 0, 3) as $m) { $meld .= '<li>' . esc_html($m) . self::ort_links($m, (int) $a['id']) . '</li>'; }
            $zeilen[] = [$abw ? 1 : 0, '<tr><td><a href="' . esc_url((string) get_permalink($a['id'])) . '" target="_blank" rel="noopener">' . esc_html($a['titel'] ?: ('Beitrag ' . $a['id'])) . '</a></td><td>' . $status . '</td><td>' . ($meld !== '' ? '<ul style="margin:0 0 0 14px">' . $meld . (count($abw) > 3 ? '<li>… und ' . (count($abw) - 3) . ' weitere</li>' : '') . '</ul>' : '–') . '</td><td><a class="button" href="' . esc_url(add_query_arg(['page' => 'sab-umbau', 'vorschau' => $a['id']], admin_url('admin.php'))) . '">Einzelvorschau</a></td></tr>'];
        }
        $h .= '<table class="widefat striped"><thead><tr><th>Artikel</th><th>Status</th><th>Probleme</th><th></th></tr></thead><tbody>' . ($zeilen ? implode('', array_column((function (array $z) { usort($z, fn($a, $b) => $a[0] <=> $b[0]); return $z; })($zeilen), 1)) : '<tr><td colspan="4">Keine offenen Artikel.</td></tr>') . '</tbody></table>';
        $nGeprueft = count($zeilen);
        $h = str_replace('</h3><p class="description">Es wird nichts gespeichert.', '</h3><p style="font-size:15px"><strong>Diese Seite: ' . count($bereit) . ' bereit · ' . $nProb . ' mit Problem · ' . max(0, $nGeprueft - count($bereit) - $nProb) . ' noch nicht fertig geprüft' . ($uebrig ? ' · ' . $uebrig . ' ungeprüft (Zeitgrenze)' : '') . '</strong> (von ' . count($liste) . ' auf dieser Seite, ' . $gesamt . ' offen insgesamt). Umbau in zwei Stufen: <strong>Stufe 1</strong> baut nur die Artikel ohne Problem um, <strong>Stufe 2</strong> sind die Artikel mit Problem, sobald das Ersatzprodukt getauscht ist.</p><p class="description">Es wird nichts gespeichert.', $h);
        if ($uebrig) { $h .= '<p><strong>' . $uebrig . ' Artikel dieser Seite wurden wegen der Zeitgrenze noch nicht geprüft.</strong> Bitte die Seite noch einmal öffnen, die Kurzlinks werden dabei Stück für Stück aufgelöst.</p>'; }
        if ($kurzOffen) { $h .= '<p><strong>' . $kurzOffen . ' Kurzlinks wurden noch nicht aufgelöst</strong> (Abrufgrenze pro Aufruf), bitte diese Seite noch einmal öffnen.</p>'; }
        if ($wegAlle) {
            arsort($wegAlle);
            $h .= self::umbau_ersatz_html(array_keys($wegAlle), 0, '');
        }
        if ($bereit) {
            $h .= '<form method="post" style="margin:14px 0">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="umbau"><input type="hidden" name="sab_form" value="umbau_viele">';
            foreach ($bereit as $i) { $h .= '<input type="hidden" name="sab_ids[]" value="' . $i . '">'; }
            $h .= '<button class="button button-primary button-hero">Stufe 1: diese ' . count($bereit) . ' Artikel ohne Problem jetzt umbauen</button> <span class="description">Jeder Artikel wird vorher gesichert, Rückgängig geht in der Liste. Artikel mit Problem bleiben unberührt.</span></form>';
        } else { $h .= '<p><em>Auf dieser Seite ist kein Artikel bereit.</em></p>'; }
        $nav = '';
        if ($seite > 1) { $nav .= '<a class="button" href="' . esc_url(add_query_arg(['page' => 'sab-umbau', 'alle_vorschau' => 1, 'seite' => $seite - 1], admin_url('admin.php'))) . '">« Vorherige Seite</a> '; }
        if ($seite < $seiten) { $nav .= '<a class="button" href="' . esc_url(add_query_arg(['page' => 'sab-umbau', 'alle_vorschau' => 1, 'seite' => $seite + 1], admin_url('admin.php'))) . '">Nächste Seite »</a>'; }
        return $h . ($nav !== '' ? '<p>' . $nav . '</p>' : '');
    }

    /** Tausch ALT => NEU in allen Artikeln (mit Sicherung, Kurzlinks der Spalte, Titel, Schutz vor Doppelung). @return string Meldung für den Redakteur */
    private static function tausch_ausfuehren(string $alt, string $neu, string $name): string {
        $pn = self::produkt($neu, self::land(''), true, true);   // Ersatz sofort bei Amazon holen und Bild speichern, sonst fehlt es nach dem Tausch auf der Seite
        if (!self::hat_bild($pn)) { return 'NICHT getauscht: Für ' . $neu . ' liefert Amazon kein Bild oder keinen Link (Produkt unbekannt oder Abruf fehlgeschlagen). Bitte ein anderes Ersatzprodukt wählen.'; }
        self::bild_url($pn, $neu, self::land(''), 0, true);
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
        '2026-10-26-korrektur-feder' => ['host' => 'federhilfe.de', 'option' => 'sab_such_kand', 'zeilen' => [], 'ersetzen' => [   // nur wenn die Zeile noch genau so dasteht (von Hand Geändertes bleibt)
            'B01MU8UQ64 | B004WO8ZIE, B000WFLAMA, B091TXGPP9 | 2026-10-26' => 'B01MU8UQ64 | B0CXJFT8VK, B0CX6GF8HN, B0F3P51Q4M | 2026-10-26',
            'B08VKRJ5D4 | B0037YHTMS | 2026-10-26' => '',
        ]],
        '2026-10-26-kurznamen-feder' => ['host' => 'federhilfe.de', 'option' => 'sab_produkte', 'zeilen' => [
            'B0F3P51Q4M | Nistkasten   2er-Set, 32 mm',
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
    private static function such_link(string $titel): string {
        return 'https://' . self::LAENDER[self::land('')][0] . '/s?k=' . rawurlencode(mb_substr(trim(preg_replace('/\s+/u', ' ', $titel)), 0, 120));
    }

    /** Welche Produkte getauscht werden müssen: weg (Amazon kennt es nicht), kein Preis/Rang, nicht lieferbar, unklar. @return array<string,string> ASIN => Grund */
    /** Amazon hat die ASIN beim letzten Abruf eindeutig abgewiesen (ungültig/nicht zugänglich). Fehlende Daten durch gescheiterte Abrufe zählen nicht. */
    private static function amazon_unbekannt(string $asin, array $d): bool {
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
    private static function ohne_doppel_kandidaten(string $alt, array $liste): array {
        global $wpdb; static $cache = [];
        if (!isset($wpdb) || !$liste) { return $liste; }
        if (!isset($cache[$alt])) {
            $cache[$alt] = [];
            foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT post_content FROM {$wpdb->posts} WHERE post_status IN ('publish','draft','private') AND post_type IN ('post','page') AND post_content LIKE %s", '%' . $wpdb->esc_like($alt) . '%'), ARRAY_A) as $r) { $cache[$alt][] = (string) $r['post_content']; }
        }
        if (!$cache[$alt]) { return $liste; }
        $out = [];
        foreach ($liste as $i => $c) {
            $blockiert = false;
            if ($i < 40) { foreach ($cache[$alt] as $t) {   // genau der Weg des Tauschs: Kurzlinks der Spalte mitnehmen, dann Text tauschen, dann Doppelungs-Prüfung
                [$t1] = self::tausch_kurzlinks($t, $alt, (string) $c['asin'], ''); [$t2] = self::tausch_text($t1, $alt, (string) $c['asin'], [], '');
                if (self::doppel_neu($t, $t2, (string) $c['asin']) || self::doppel_neu($t, str_replace($alt, (string) $c['asin'], $t), (string) $c['asin'])) { $blockiert = true; break; }
            } }
            if (!$blockiert) { $out[] = $c; }
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

    private static function austausch_kandidaten(string $alt, string $name, array $d, array $trotz, array $fund, int $max = 4): array {
        $items = (array) ($d['items'] ?? []); $namen = self::produkte(); $marken = (array) get_option('sab_marken', []);
        if (!defined('SAB_SYNC') && get_transient('sab_such_pruefung') === false) {   // eingetragene Suchtreffer ohne Amazon-Daten: Abruf im Hintergrund anstoßen (höchstens alle 10 Minuten)
            foreach (self::such_kandidaten() as $l_) { foreach ($l_['liste'] as $b_) { if (!isset($items[$b_])) { set_transient('sab_such_pruefung', 1, 10 * MINUTE_IN_SECONDS); foreach (array_keys(self::LAENDER) as $ld_) { delete_transient("sab_uebersicht_{$ld_}"); } break 2; } } }
        }
        $q = [];
        foreach ((self::amz_alternativen()[$alt]['liste'] ?? []) as $i => $a) { $q[$a] = 'Amazon-Alternative ' . ($i + 1); }
        foreach ((self::such_kandidaten()[$alt]['liste'] ?? []) as $i => $a) { $q[$a] = $q[$a] ?? 'Amazon-Suche ' . ($i + 1); }
        foreach ((self::auch_gekauft()[$alt]['liste'] ?? []) as $a) { $q[$a] = $q[$a] ?? 'Kunden kauften auch'; }
        $ce = self::claude_empfehlungen()[$alt] ?? null; if ($ce) { $q[$ce[0]] = 'Empfehlung'; }
        foreach ($marken as $a => $t) { if ($t === 'fav' && !isset($q[$a])) { $q[$a] = 'Favorit'; } }
        foreach (array_keys($namen) as $a) { if (!isset($q[$a])) { $q[$a] = 'Katalog'; } }
        $gruppe = []; foreach (self::gruppen() as $liste) { if (in_array($alt, $liste, true)) { $gruppe = array_merge($gruppe, $liste); } }
        $wn = self::woerter(trim($name . ' ' . (string) ($items[$alt]['titel'] ?? '')));
        $wk = self::woerter(self::text_vor_stelle($alt, $fund[$alt] ?? []));
        $refm = self::merkmale(trim($name . ' ' . (string) ($items[$alt]['titel'] ?? '') . ' ' . self::text_vor_stelle($alt, $fund[$alt] ?? [])));   // Schlüsselmerkmale aus Name, Titel und Text vor dem Link   // Hinweise aus dem Satz vor dem Link   // nur der Produktname selbst, ohne Artikeltext: Namensähnlichkeit
        $text = $name;   // der Textzusammenhang bringt nur etwas, wenn der Name fast nichts sagt: sonst ziehen Wörter wie „Schnecken“ aus dem Artikel unpassende Produkte heran
        if (count(self::woerter($name)) < 2) { $text .= ' ' . self::stellen_kontext($alt, $fund[$alt] ?? []); }
        $a1 = self::arten($text); $w1 = self::woerter($text);
        $out = []; $aus = []; $rest = [];
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
            $aehn = count(array_intersect($wn, self::woerter($tc))); if ($tk >= 2) { $aehn++; if ($passt === 0) { $passt = 1; } }
            if ($passt === 0 && ($quelle === 'Kunden kauften auch' || strpos($quelle, 'Amazon-Suche') === 0)) { $passt = 2; }   // von Amazon als zugehörig gezeigt beziehungsweise von Hand geprüft   // von Hand gesucht und geprüft: nicht noch einmal aussortieren
            if ($passt === 0) { $aus[] = ['asin' => $a, 'quelle' => $quelle, 'titel' => (string) ($x['titel'] ?? '')]; if ($ok && $quelle === 'Katalog') { $rest[] = ['asin' => $a, 'quelle' => 'Bestes im Portfolio (Passung unklar)', 'score' => $sc, 'x' => $x, 'ok' => $ok, 'passt' => 0, 'pv' => $pv, 'rang' => $sc, 'ausgeschlossen' => false]; } continue; }
            $out[] = ['asin' => $a, 'quelle' => $quelle, 'score' => $sc, 'x' => $x, 'ok' => $ok, 'passt' => $passt, 'aehn' => $aehn, 'pv' => $pv, 'rang' => $sc + 15 * max(0, ((float) $pv) - 3) + 10 * $ar + 4 * $tr + 12 * $aehn + ($quelle === 'Katalog' ? 0 : 3), 'ausgeschlossen' => false];
        }
        // zwei Gruppen: bis zu $max aus dem eigenen Bestand (vom Algorithmus gewählt) und bis zu 2 neue Treffer der Amazon-Suche
        usort($out, fn($p, $q2) => [(int) $q2['ok'], min(2, (int) ($q2['aehn'] ?? 0)), (float) ($q2['score'] ?? -1), (float) $q2['rang']] <=> [(int) $p['ok'], min(2, (int) ($p['aehn'] ?? 0)), (float) ($p['score'] ?? -1), (float) $p['rang']]);   // lieferbare zuerst, dann ähnlicher Produktname (Alexander 25.10.), dann Score, bei gleichem Score nach Rang inkl. Provision
        $out = self::ohne_doppel_kandidaten($alt, $out);
        $abl = self::lernen_abgelehnt($alt);   // schon einmal übergangene Vorschläge rutschen nach hinten, zweimal abgelehnte fliegen raus
        if ($abl) { $out = array_values(array_filter($out, fn($c) => ($abl[$c['asin']] ?? 0) < 2)); foreach ($out as &$c_) { $c_['abgel'] = (int) ($abl[$c_['asin']] ?? 0); } unset($c_); }   // nur Produkte vorschlagen, die der Austausch auch annehmen würde (keine Doppelung nebeneinander oder zu nah)
        $bestand = []; $neu = [];
        foreach ($out as $c) { if (strpos((string) $c['quelle'], 'Amazon-Suche') === 0) { $c['neu'] = true; $neu[] = $c; } else { $bestand[] = $c; } }
        $bestand = array_values(array_filter($bestand, fn($c) => (int) ($c['passt'] ?? 0) >= 2 || ((int) ($c['passt'] ?? 0) === 1 && (int) ($c['aehn'] ?? 0) >= 1)));   // Bestand nur bei echter Namens-/Typ-Übereinstimmung
        $bs = array_slice($bestand, 0, $max);
        $liste = array_merge($bs, array_slice($neu, 0, max(2, min(4, 6 - count($bs)))));
        if (false && count($liste) < 4 && $rest) {   // nichts Passendes gefunden: wenigstens die stärksten lieferbaren Produkte des Bestands zeigen
            usort($rest, fn($p, $q2) => $q2['score'] <=> $p['score']);
            $liste = array_merge($liste, array_slice($rest, 0, 4 - count($liste)));
        }
        usort($liste, fn($p, $q2) => [(int) !empty($q2['neu']), -(int) ($q2['abgel'] ?? 0), (int) $q2['ok'], min(2, (int) ($q2['aehn'] ?? 0)), (float) ($q2['score'] ?? -1), (float) $q2['rang']] <=> [(int) !empty($p['neu']), -(int) ($p['abgel'] ?? 0), (int) $p['ok'], min(2, (int) ($p['aehn'] ?? 0)), (float) ($p['score'] ?? -1), (float) $p['rang']]);   // Bestand und neue Treffer zusammen: oben links ähnlicher Name, dann höchster Score
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
    private static function austausch_html(): string {
        $land = self::land(''); $d = self::uebersicht_daten($land, false); $trotz = (array) get_option('sab_trotz', []); $namen = self::produkte();
        $h = self::name_offen_html() . '<h2>Austausch: Produkte ersetzen, die Amazon nicht mehr liefert</h2><p class="description">Hauptaufgabe des Plugins neben der Ausgabe von Bildern, Links und Karten: Produkte, die bei Amazon fehlen, rechtzeitig durch passende ersetzen. Zu jedem Problemprodukt zeigt das Plugin bis zu vier <strong>passende</strong> Kandidaten aus dem eigenen Bestand plus neue Treffer der Amazon-Suche (zwei, bei wenig Passendem im Bestand bis zu vier) (gleiche Art oder Gruppe, bei Spezialprodukten auch zur Textstelle) aus Amazons Alternativen, Favoriten und dem Katalog, geordnet nach Score; der höhere Provisionssatz zählt stark (5 % schlägt 3 %, es sei denn der Score ist deutlich schlechter). Du kannst einen wählen oder eine eigene ASIN eintragen.</p>';
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
            $sc0 = self::score($x0)[0]; $dr0 = self::tausch_dringlichkeit($x0, $sc0);
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
            $zeilen[] = ['alt' => $alt, 'grund' => $toet ? 'Tierschutz-Prüfung: Begriff „' . self::toetend_wort((string) $alt, (array) $d) . '“ im Titel, bitte prüfen' : $grund, 'seiten' => $seiten, 'n' => $n, 'name' => self::anzeige_name($alt, (array) $d, $fund), 'prio' => $toet ? 1000 * max(1, $seiten) : ($dring[$alt] ?? 100) * max(1, $seiten), 'dr' => $toet ? 1000 : ($dring[$alt] ?? 100), 'f' => $f];
        }
        usort($zeilen, fn($a, $b) => [(int) ($b['dr'] >= 100), $b['prio'], $b['n']] <=> [(int) ($a['dr'] >= 100), $a['prio'], $a['n']]);   // zuerst alles Nicht-Lieferbare/ohne Bild, darin nach Priorität; danach der Rest
        // Produkte ohne Bild (laut Warnliste, von Amazon unbekannt oder ohne Bild und ohne eigenes Bild) kommen als eigene Dringend-Liste nach oben: zuerst die auf den meisten Seiten
        $bf = []; foreach ((array) get_option('sab_bildfehler', []) as $e_) { $bf[(string) ($e_['asin'] ?? '')] = 1; }
        $zb = []; $zr = [];
        foreach ($zeilen as $z_) {
            $a_ = (string) $z_['alt'];
            $z_['bl'] = isset($bf[$a_]) || !isset($d['items'][$a_]) || (empty($d['items'][$a_]['bilder']) && !is_file(self::bild_pfad($land, $a_, 0)));
            if ($z_['bl']) { $zb[] = $z_; } else { $zr[] = $z_; }
        }
        usort($zb, fn($a, $b) => [$b['seiten'], $b['n']] <=> [$a['seiten'], $a['n']]);
        $zeilen = array_merge($zb, $zr); $nb = count($zb);
        $schwelle = (int) get_option('sab_auto_schwelle', 55); $ladeLimit = min(60, $nb + 10);
        $ps = []; foreach (self::alle_asins() as $a_) { $ps[] = [(string) $a_, (string) ($namen[$a_] ?? ''), mb_substr((string) ($d['items'][$a_]['titel'] ?? ''), 0, 90), isset($d['items'][$a_]) ? (int) self::score(array_merge((array) $d['items'][$a_], ['trotz' => isset($trotz[$a_])]))[0] : -1]; }
        $h .= '<script>window.SAB_PS=' . wp_json_encode($ps) . ';window.SAB_DP=' . wp_json_encode('https://' . self::LAENDER[$land][0] . '/dp/') . ';'
            . "document.addEventListener('input',function(e){var t=e.target;if(!t.classList||!t.classList.contains('sab-ps-in')){return;}var w=t.parentNode,out=w.querySelector('.sab-ps-out'),q=t.value.toLowerCase().trim().split(/\\s+/).filter(Boolean),alt=w.getAttribute('data-alt'),n=0,h='';if(!q.length){out.innerHTML='';return;}"
            . "for(var i=0;i<window.SAB_PS.length&&n<8;i++){var p=window.SAB_PS[i],hay=(p[0]+' '+p[1]+' '+p[2]).toLowerCase(),ok=true;for(var j=0;j<q.length;j++){if(hay.indexOf(q[j])<0){ok=false;break;}}if(!ok||p[0]===alt){continue;}n++;"
            . "h+='<div style=\"display:flex;gap:8px;align-items:center;padding:3px 0;border-bottom:1px solid #eee\"><button type=\"button\" class=\"button button-small sab-ps-pick\" data-asin=\"'+p[0]+'\" data-name=\"'+(p[1]||p[2]).replace(/\"/g,'')+'\">wählen</button><span><strong>'+(p[1]||p[2]||'(ohne Name)').replace(/</g,'&lt;')+'</strong> <small>'+(p[3]>=0?'Score '+p[3]+' · ':'')+p[0]+'</small></span> <a href=\"'+window.SAB_DP+p[0]+'\" target=\"_blank\" rel=\"noopener\">bei Amazon ansehen ↗</a></div>';}"
            . "out.innerHTML=h||'<em>Kein Treffer im Bestand.</em>';});"
            . "document.addEventListener('click',function(e){var b=e.target;if(!b.classList||!b.classList.contains('sab-ps-pick')){return;}var w=b.closest('.sab-ps'),alt=w.getAttribute('data-alt');document.querySelector('input[name=\"sab_eigen['+alt+']\"]').value=b.getAttribute('data-asin');var nf=document.querySelector('input[name=\"sab_eigen_name['+alt+']\"]');if(nf&&!nf.value){nf.value=b.getAttribute('data-name');}document.getElementById('sabr_'+alt).checked=true;w.querySelector('.sab-ps-out').innerHTML='<strong>Gewählt: '+b.getAttribute('data-asin')+'</strong> (steht unter „Eigene ASIN“, dann „Jetzt tauschen“)';});</script>";
        $h .= '<style>@media(max-width:782px){.sab-tk{padding:8px!important;overflow:hidden}.sab-tk,.sab-tk *{max-width:100%;box-sizing:border-box;overflow-wrap:anywhere}.sab-tk-kopf{flex-direction:column;align-items:flex-start!important}.sab-tk-k{flex:1 1 100%!important;max-width:none!important}.sab-tk input[type=text]{width:100%!important;max-width:100%!important;size:auto}.sab-tk .sab-ps-out>div{flex-wrap:wrap}}</style>';
        $h .= '<form method="post">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="austausch"><input type="hidden" name="sab_form" value="austausch_viele">'
            . '<h3>Zu tauschen (' . count($zeilen) . '), wichtigste zuerst</h3><p class="description">Vorausgewählt ist der beste Kandidat ab Score ' . $schwelle . ' mit lieferbarem Angebot. „Nichts tauschen“ lässt das Produkt unverändert. Reihenfolge: zuerst alles, was nicht lieferbar ist, keinen Preis oder kein Bild hat, nach Seitenzahl; danach lieferbare Produkte mit schwachem Score. Es werden immer die ' . $ladeLimit . ' obersten gezeigt; jedes getauschte Produkt rückt das nächste nach, bis nur noch gute Produkte übrig sind.</p>';
        $zaehler = 0; $lq = self::lernen_quote();
        $h .= '<p style="font-size:14px"><strong>Trefferquote der Vorschläge:</strong> ' . ($lq['n'] ? 'Platz 1 gewählt: ' . $lq['eins'] . ' von ' . $lq['n'] . ' (' . $lq['quote'] . ' %)' : 'noch keine Tausche gemerkt') . '. <span class="description">Gemerkt werden nur ASINs und der Platz im Ranking (höchstens 500 Einträge). Übergangene Vorschläge rutschen für dasselbe Produkt nach hinten. ' . ($lq['n'] >= 30 && $lq['quote'] >= 90 ? 'Grün markierte Karten würden bei eingeschalteter Automatik getauscht (noch nicht eingeschaltet).' : 'Eine Auto-Markierung gibt es ab 30 Tauschen mit 90 % Platz 1.') . '</span></p>';
        $h .= '<p class="description">Vor jeder Austauschrunde sucht die Mac-Sitzung zu jedem Produkt bei Amazon nach dem Titel und trägt die besten Treffer mit Score und Provision unten ein (Quelle „Amazon-Suche“).</p>';
        foreach ($zeilen as $z) {
            if ($nb > 0 && $zaehler === 0) { $h .= '<h3 style="margin:18px 0 6px;color:#8a1f1f">Dringend tauschen: Produkte ohne Bild (' . (int) $nb . '), zuerst die auf den meisten Seiten</h3><p class="description">Hier bekommen Besucher kein Bild. Oben stehen die Produkte, die auf vielen Seiten vorkommen.</p>'; }
            if ($nb > 0 && $zaehler === $nb) { $h .= '<h3 style="margin:22px 0 6px">Weitere Produkte zu tauschen</h3>'; }
            if (++$zaehler > $ladeLimit) { $h .= '<p class="description">… und ' . (count($zeilen) - $ladeLimit) . ' weitere (erst diese erledigen).</p>'; break; }
            $alt = $z['alt']; $xalt = $d['items'][$alt] ?? [];
            $k = self::austausch_kandidaten($alt, (string) $z['name'], (array) $d, $trotz, $fund);
            if (empty($z['bl']) && $z['dr'] < 100 && $z['dr'] < 1000) {   // Produkt ist lieferbar: Ersatz nur, wenn der Score deutlich höher ist
                $salt = (int) self::score(array_merge((array) $xalt, ['trotz' => isset($trotz[$alt])]))[0];
                $k['liste'] = array_values(array_filter($k['liste'], fn($c) => $c['score'] !== null && (int) $c['score'] >= $salt + 10));
            }
            $best = $k['liste'][0] ?? null;
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
            }
            $badge = !empty($z['bl']) ? ' <span style="display:inline-block;padding:1px 9px;border-radius:9px;background:#fcf0f1;color:#8a1f1f;font-weight:700;font-size:12px">ohne Bild</span>' : '';
            if ($best && self::lernen_auto($best, (int) self::score(array_merge((array) $xalt, ['trotz' => isset($trotz[$alt])]))[0], $lq)) { $badge .= ' <span style="display:inline-block;padding:1px 9px;border-radius:9px;background:#e3f4e7;color:#1a7f37;font-weight:700;font-size:12px" title="Würde bei eingeschalteter Automatik getauscht (noch nicht eingeschaltet)">Auto-Kandidat</span>'; }
            $extra = '';
            if (!isset($d['items'][$alt])) { $extra .= '<br><strong style="color:#a00">Amazon liefert für dieses Produkt weder Titel noch Bild.</strong>' . ($z['name'] !== '' ? ' Der Name stammt aus dem Artikel.' : ' Auch im Artikel ist kein Name zu finden: bitte ASIN bei Amazon suchen.'); }
            elseif ((self::produkte()[$alt] ?? '') === '' && empty($d['items'][$alt]['titel']) && $z['name'] !== '') { $extra .= '<br><small>Name aus dem Artikel.</small>'; }
            $tv = self::text_vor_stelle((string) $alt, (array) $z['f']); if ($tv !== '') { $extra .= '<br><small style="color:#444"><strong>Text davor im Artikel:</strong> „' . esc_html($tv) . '“</small>'; }
            $extra .= '<br><a class="button button-small" href="' . esc_url(self::such_link($z['name'] !== '' ? (string) $z['name'] : $alt)) . '" target="_blank" rel="noopener">Bei Amazon nach dem Titel suchen</a> <a class="button button-small" href="' . esc_url(self::such_link($alt)) . '" target="_blank" rel="noopener">ASIN bei Amazon suchen</a>';
            $h .= '<div class="sab-tk" style="border:1px solid #c3c4c7;border-radius:8px;background:#fff;padding:10px 14px;margin:0 0 12px;max-width:1100px"><div class="sab-tk-kopf" style="display:flex;gap:12px;align-items:center;margin-bottom:8px">' . self::mini_bild($alt, $xalt)
                . '<div><strong>Zu tauschen:</strong>' . $badge . ' <a href="' . esc_url('https://' . self::LAENDER[$land][0] . '/dp/' . $alt) . '" target="_blank" rel="noopener"><code>' . esc_html($alt) . '</code></a> ' . ($z['name'] !== '' ? esc_html($z['name']) : '<em>Name unbekannt</em>') . '<br><span style="color:#a00;font-size:16px;font-weight:700">' . esc_html($z['grund']) . '</span><br><small style="color:#555" title="Reihenfolge der Liste: Dringlichkeit (0 bis 100) mal Zahl der Seiten">Priorität ' . (int) $z['prio'] . ' = Dringlichkeit ' . (int) $z['dr'] . ' &times; ' . (int) $z['seiten'] . ' Seite' . ($z['seiten'] === 1 ? '' : 'n') . '</small><br>' . $wo . $extra . '</div>' . self::alt_kennzahlen($xalt, $alt, $z['seiten']) . '</div>'
                . '<div style="display:flex;flex-wrap:wrap;gap:10px">';
            foreach ($k['liste'] as $i => $c) {
                $x = $c['x']; $pv = $c['pv'] ?? null; $nm = ($namen[$c['asin']] ?? '') ?: self::kurzname_aus_titel((string) ($x['titel'] ?? ''));
                $pvStil = $pv !== null && $pv >= 5 ? 'background:#e3f4e7;color:#1a7f37' : ($pv !== null && $pv >= 4 ? 'background:#fdf0d8;color:#8a5a00' : 'background:#eef0f0;color:#333');
                $h .= '<label class="sab-tk-k" style="flex:1 1 300px;max-width:340px;border:1px solid #dcdcde;border-radius:6px;padding:8px;display:flex;gap:8px;cursor:pointer"><input type="radio" name="sab_a[' . esc_attr($alt) . ']" value="' . esc_attr($c['asin']) . '"' . ($voraus && $i === 0 ? ' checked' : '') . '>'
                    . self::mini_bild($c['asin'], $x) . '<span>' . (!empty($c['neu']) ? '<span style="display:inline-block;padding:0 7px;border-radius:9px;background:#dbe9ff;color:#0b3d91;font-size:11px;font-weight:700">NEU durch Amazon-Suche</span><br>' : '<span style="display:inline-block;padding:0 7px;border-radius:9px;background:#eee;color:#444;font-size:11px">aus dem Bestand</span><br>') . '<strong>' . esc_html($nm !== '' ? $nm : '(noch keine Daten)') . '</strong><br><a href="' . esc_url('https://' . self::LAENDER[$land][0] . '/dp/' . $c['asin']) . '" target="_blank" rel="noopener"><code>' . esc_html($c['asin']) . '</code></a> <small><a href="' . esc_url('https://' . self::LAENDER[$land][0] . '/dp/' . $c['asin']) . '" target="_blank" rel="noopener">bei Amazon ansehen ↗</a></small><br>'
                    . '<small>' . esc_html($c['quelle']) . ' · <strong>Score ' . ($c['score'] !== null ? (int) $c['score'] : '?') . '</strong>' . (!empty($x['bonus']) ? ' <span style="color:#6a3fb0;font-weight:700" title="Von Hand vergebener Bonus, im Score enthalten">&#9998; ' . ($x['bonus'] > 0 ? '+' : '') . (int) $x['bonus'] . ' manuell</span>' : '') . ($x && ($x['preis'] ?? '') !== '' ? ' · ' . esc_html((string) $x['preis']) : '') . ($x && isset($x['sterne']) && $x['sterne'] !== null ? ' · ' . esc_html(str_replace('.', ',', (string) $x['sterne'])) . ' ★' . (isset($x['anzahl']) && $x['anzahl'] !== null ? ' (' . number_format((int) $x['anzahl'], 0, ',', '.') . ')' : '') : '') . ' · ' . self::prime_zeichen($x && isset($x['prime']) ? $x['prime'] : null) . ($x && isset($x['liefertage']) && $x['liefertage'] !== null ? ' · ' . esc_html(str_replace('.', ',', (string) round((float) $x['liefertage'], 1))) . ' T' : '') . '</small><br>'
                    . ($pv !== null ? '<span style="display:inline-block;padding:1px 8px;border-radius:10px;font-weight:700;' . $pvStil . '">' . esc_html(str_replace('.', ',', (string) $pv)) . ' % Provision</span>' : '<span style="color:#a00">Provision unbekannt</span>')
                    . (!$c['ok'] ? ' <span style="color:#a00">· nicht lieferbar / keine Daten</span>' : '') . ($x ? '<br>' . self::score_grafik(self::score($x)[2]) . (($sa = (int) (self::score(array_merge($xalt, ['trotz' => isset($trotz[$alt])]))[0] ?? 0)) && $c['score'] !== null ? ' <small style="font-weight:700;color:' . ($c['score'] >= $sa ? '#1a7f37' : '#a00') . '" title="Unterschied zum alten Produkt">' . ($c['score'] - $sa >= 0 ? '+' : '') . ((int) $c['score'] - $sa) . ' gegenüber dem alten</small>' : '') : '') . '</span></label>';
            }
            $suchtitel = $z['name'] !== '' ? $z['name'] : $alt;
            if (!$k['liste']) { $h .= '<p style="margin:0"><em>' . ($z['dr'] < 100 ? 'Kein besserer Ersatz im Bestand: das Produkt ist lieferbar, kein Kandidat hat einen deutlich höheren Score.' : 'Kein passender Kandidat gefunden: <strong>Amazon-Suche nötig</strong> (Titel oder Text davor als Suchwort).') . '</em> <a class="button button-small" href="' . esc_url(self::such_link($suchtitel)) . '" target="_blank" rel="noopener">Bei Amazon nach dem Titel suchen</a></p>'; }
            $h .= '</div><div style="display:flex;flex-wrap:wrap;gap:18px;align-items:center;margin:10px 0 0"><label><input type="radio" name="sab_a[' . esc_attr($alt) . ']" value=""' . ($voraus ? '' : ' checked') . '> <strong>Nichts tauschen</strong></label><label><input type="radio" name="sab_a[' . esc_attr($alt) . ']" value="__eigen" id="sabr_' . esc_attr($alt) . '"> <strong>Eigene ASIN:</strong> <input type="text" name="sab_eigen[' . esc_attr($alt) . ']" size="12" maxlength="10" placeholder="B0…" oninput="document.getElementById(\'sabr_' . esc_attr($alt) . '\').checked=true"></label><label><strong>Kurzname:</strong> <input type="text" name="sab_eigen_name[' . esc_attr($alt) . ']" size="22" maxlength="60" placeholder="optional, z. B. Teichfolie 4×6 m" oninput="document.getElementById(\'sabr_' . esc_attr($alt) . '\').checked=true"></label><button class="button button-primary" name="sab_einzeln" value="' . esc_attr($alt) . '" title="Tauscht nur dieses Produkt mit der angewählten Karte oder eigenen ASIN">Jetzt tauschen</button><a class="button" href="' . esc_url(self::such_link($suchtitel)) . '" target="_blank" rel="noopener">Bei Amazon nach dem Titel suchen</a></div><div class="sab-ps" data-alt="' . esc_attr($alt) . '" style="margin:10px 0 0"><label><strong>Im ganzen Bestand suchen:</strong> <input type="search" class="sab-ps-in" size="34" placeholder="Name, Stichwort oder ASIN eintippen" autocomplete="off"></label><div class="sab-ps-out" style="margin-top:4px;max-width:760px"></div></div><p style="margin:6px 0 0">' . ''
                . ($k['aussortiert'] ? ' <small class="description">· ' . count($k['aussortiert']) . ' Vorschläge aussortiert, weil sie nicht zum Produkt passen (z. B. „' . esc_html(mb_substr((string) $k['aussortiert'][0]['titel'], 0, 50)) . '“)</small>' : '') . '</p></div>';
        }
        $h .= ($zeilen ? '<p><button class="button button-primary button-hero">Auswahl tauschen (jeweils mit Sicherung)</button> <span class="description">Der Tausch gilt in allen Artikeln, Titel und Textlinks folgen dem neuen Bildprodukt. Bis zu 10 pro Klick.</span></p>' : '<p>Nichts zu tauschen.</p>') . '</form>';
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
        // Automatik
        $auto = get_option('sab_auto_tausch') === '1'; $log = (array) get_option('sab_auto_log', []);
        $h .= '<h3 style="margin-top:2em">Automatik</h3><form method="post">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="austausch"><input type="hidden" name="sab_form" value="austausch_auto">'
            . '<p><label><input type="checkbox" name="sab_auto_tausch" value="1"' . ($auto ? ' checked' : '') . '> Produkte, die Amazon nicht mehr kennt, im täglichen Lauf selbst ersetzen</label></p>'
            . '<p>Nur wenn der beste passende Kandidat lieferbar ist und mindestens Score <input type="number" name="sab_auto_schwelle" min="0" max="100" value="' . $schwelle . '" style="width:70px"> hat. Das Plugin legt dann eine <strong>Austausch-Regel</strong> an (Reiter „Produkte und Ersatzlisten“): Sie wirkt sofort auf alle Platzhalter, schreibt aber nichts in die Artikel. Rückgängig: Regel löschen. Du bekommst eine Mail.</p>'
            . '<p><button class="button">Speichern</button></p></form>';
        if ($log) {
            $nf = self::tausch_regeln();
            $h .= '<h4>Automatisch getauscht (zur Kontrolle)</h4><p class="description">Links das alte, rechts das neue Produkt. „Rückgängig“ löscht die Austausch-Regel, das alte Produkt erscheint dann wieder. Ist alles geprüft, kannst du die Liste leeren (die Tausche bleiben).</p>';
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
        $t = []; foreach ($neu_regeln as $a => $b) { $t[] = '  ' . $a . ' => ' . $b; }
        self::alarm('auto', 'Produkte automatisch ersetzt', "Das Plugin hat Produkte ersetzt, die Amazon nicht mehr kennt (Austausch-Regeln):\n" . implode("\n", $t) . "\n\nRückgängig: Seitenleiste > " . self::NAME . " > Produkte und Ersatzlisten > Austausch-Regeln, Zeile löschen.");
    }

    /** Zu Produkten, die Amazon nicht mehr kennt: Ersatz-ASIN eingeben oder den Vorschlag (Favorit mit hohem Score, passende Art) übernehmen; tauscht in allen Artikeln, danach öffnet sich die Vorschau neu. */
    private static function umbau_ersatz_html(array $weg, int $id, string $c): string {
        $land = self::land(''); $d = self::uebersicht_daten($land, false); $trotz = (array) get_option('sab_trotz', []); $namen = self::produkte();
        $favs = array_keys(array_filter((array) get_option('sab_marken', []), fn($t) => $t === 'fav'));
        $fund = self::fundstellen(array_values(array_unique(array_merge($weg, $favs))));
        $h = '<div class="notice notice-warning inline"><p><strong>Produkte, die Amazon nicht mehr kennt: Ersatz wählen</strong></p>';
        foreach ($weg as $alt) {
            $name = ($namen[$alt] ?? '') ?: self::kontext_titel($c, $alt);
            $nf = count($fund[$alt] ?? []);
            $xalt = (array) ($d['items'][$alt] ?? []);
            $k = self::austausch_kandidaten($alt, (string) $name, (array) $d, $trotz, $fund, 5);
            $voraus = false;   // keine Vorauswahl: getauscht wird nur, was bewusst angeklickt wurde
            $h .= '<form method="post" style="margin:10px 0;padding:10px 14px;background:#fff;border:1px solid #c3c4c7;border-radius:8px;max-width:1100px">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false)
               . '<input type="hidden" name="sab_tab" value="umbau"><input type="hidden" name="sab_form" value="tausch_fest"><input type="hidden" name="sab_alt" value="' . esc_attr($alt) . '">' . ($id > 0 ? '<input type="hidden" name="sab_vorschau" value="' . (int) $id . '">' : '<input type="hidden" name="sab_alle_vorschau" value="' . max(1, self::$vorschau_seite) . '">')
               . '<div style="display:flex;gap:12px;align-items:center;margin-bottom:8px">' . self::mini_bild($alt, $xalt) . '<div><strong>Zu tauschen:</strong> <code>' . esc_html($alt) . '</code> ' . esc_html((string) $name) . ' <small>(steht in ' . $nf . ' Artikel' . ($nf === 1 ? '' : 'n') . ')</small><br><span style="color:#a00;font-weight:600">Grund: Amazon kennt dieses Produkt nicht (mehr). Es kann nicht umgebaut werden und hat bei Besuchern kein Bild.</span></div></div>'
               . self::fundstellen_links($alt, $fund[$alt] ?? []);
            $tv = self::text_vor_stelle((string) $alt, (array) ($fund[$alt] ?? [])); if ($tv !== '') { $h .= '<small style="color:#444"><strong>Text davor im Artikel:</strong> „' . esc_html($tv) . '“</small><br>'; }
            $ce = self::claude_empfehlungen()[$alt] ?? null;
            if ($ce) { $h .= '<small><strong>Empfehlung (von Amazons Alternativen):</strong> <code>' . esc_html($ce[0]) . '</code> ' . esc_html($ce[1]) . '</small><br>'; }
            $h .= '<div style="display:flex;flex-wrap:wrap;gap:10px;margin:8px 0">';
            foreach ($k['liste'] as $i => $cd) {
                $x = (array) $cd['x']; $pv = $cd['pv'] ?? null; $nm = ($namen[$cd['asin']] ?? '') ?: self::kurzname_aus_titel((string) ($x['titel'] ?? ''));
                $h .= '<label style="flex:1 1 280px;max-width:330px;border:1px solid #dcdcde;border-radius:6px;padding:8px;display:flex;gap:8px;cursor:pointer"><input type="radio" name="sab_wahl" value="' . esc_attr($cd['asin']) . '"' . ($voraus && $i === 0 ? ' checked' : '') . ' onclick="this.form.sab_neu.value=this.value">'
                    . self::mini_bild($cd['asin'], $x) . '<span><strong>' . esc_html($nm ?: $cd['asin']) . '</strong><br><small><a href="' . esc_url('https://' . self::LAENDER[$land][0] . '/dp/' . $cd['asin']) . '" target="_blank" rel="noopener"><code>' . esc_html($cd['asin']) . '</code></a> · ' . esc_html((string) $cd['quelle']) . '<br><strong>Score ' . ($cd['score'] !== null ? (int) $cd['score'] : '?') . '</strong>'
                    . (!empty($x['preis']) ? ' · ' . esc_html((string) $x['preis']) : '') . ($pv !== null ? ' · ' . esc_html(str_replace('.', ',', (string) $pv)) . ' % Provision' : '') . (!$cd['ok'] ? ' · <span style="color:#a00">nicht lieferbar</span>' : '') . '</small></span></label>';
            }
            if (!$k['liste']) { $h .= '<p style="margin:0"><em>Kein passender Kandidat gefunden.</em></p>'; }
            $h .= '<p style="margin:0 0 6px;flex:1 1 100%"><a class="button button-small" href="' . esc_url(self::such_link($name !== '' ? (string) $name : $alt)) . '" target="_blank" rel="noopener">Bei Amazon nach dem Titel suchen</a> <a class="button button-small" href="' . esc_url(self::such_link($alt)) . '" target="_blank" rel="noopener">ASIN bei Amazon suchen</a></p>';
            $vorn = '';
            $vorname = '';
            $h .= '</div><label>Neue ASIN: <input type="text" name="sab_neu" size="12" maxlength="10" value="' . esc_attr($vorn) . '" placeholder="B0…"></label> '
               . '<label>Name im Artikel: <input type="text" name="sab_name" class="regular-text" style="max-width:240px" value="' . esc_attr((string) $vorname) . '"></label> '
               . '<button class="button button-primary">Tauschen (in allen ' . $nf . ' Artikeln), danach Vorschau neu</button><span class="description"> Eine Karte anklicken füllt die ASIN ein, Name bitte prüfen.</span></form>';
        }
        return $h . '</div>';
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
        [$neu, $n, $sk] = self::umbau_text($c);   // der ganze Artikel auf einmal, wie beim echten Umbau (Tabellenspalten und Nachbarn sind sichtbar)
        $h = '<h3>Vorschau für „' . esc_html($post->post_title) . '“</h3><p class="description">Es wird nichts gespeichert. Gezeigt wird das Ergebnis für den ganzen Artikel, so wie der Umbau ihn schreiben würde.</p>';
        $knopf = '<form method="post" style="margin:10px 0">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="umbau"><input type="hidden" name="sab_form" value="umbau_einzeln"><input type="hidden" name="sab_id" value="' . (int) $id . '"><button class="button button-primary button-hero">Jetzt so umbauen</button> <span class="description">Der Artikel wird vorher gesichert, Rückgängig geht in der Liste.</span></form>';
        $h .= $knopf;
        if (self::$umbau_info) { $h .= '<div class="notice notice-info inline"><p><strong>Das Bild bestimmt das Produkt, daher angepasst:</strong></p><ul>' . implode('', array_map(fn($x) => '<li>' . esc_html($x) . self::ort_links($x, $id) . self::ok_knopf($x, $id) . '</li>', array_unique(self::$umbau_info))) . '</ul></div>'; }
        if (self::$umbau_abw) { $h .= '<div class="notice notice-warning inline"><p><strong>Wird bewusst nicht umgebaut (bitte von Hand klären):</strong></p><ul>' . implode('', array_map(fn($x) => '<li>' . esc_html($x) . self::ort_links($x, $id) . self::ok_knopf($x, $id) . '</li>', array_unique(self::$umbau_abw))) . '</ul></div>'; }
        if (self::$umbau_weg) { $h .= self::umbau_ersatz_html(array_keys(self::$umbau_weg), $id, $c); }
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

    // ---------- Verwaltung ----------

    const TABS = [
        'uebersicht'    => 'Übersicht',
        'produkte'      => 'Produkte und Ersatzlisten',
        'statistik'     => 'Statistik',
        'problem'       => 'Ausfälle und Wartung',
        'austausch'     => 'Austausch',
        'gruppen'       => 'Produktgruppen',
        'score'         => 'Score',
        'umbau'         => 'Umbau',
        'vorschau'      => 'Vorschau',
        'tests'         => 'A/B-Tests',
        'einstellungen' => 'Einstellungen',
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

    public static function seite(): void {
        if (!current_user_can('manage_options')) { return; }
        $tabs = self::TABS;
        $seite = sanitize_key((string) ($_GET['page'] ?? ''));
        $tab = sanitize_key((string) ($_POST['sab_tab'] ?? ($_GET['tab'] ?? (strpos($seite, 'sab-') === 0 ? substr($seite, 4) : 'uebersicht'))));
        if ($tab === 'ausfaelle') { $tab = 'problem'; }   // beide Reiter sind zusammengelegt
        if (!isset($tabs[$tab])) { $tab = 'uebersicht'; }

        $meldung = '';
        if (!empty($_GET['sab_update_jetzt']) && wp_verify_nonce((string) ($_GET['_wpnonce'] ?? ''), 'sab_update_jetzt')) {
            $nv = self::update_nachsehen(true);
            $meldung = $nv !== '' ? 'Neue Version ' . $nv . ' gefunden: sie steht jetzt unter Plugins zum Aktualisieren bereit.' : 'Kein Update gefunden: Installiert ist die neueste Version (' . self::VERSION . ').';
        }
        if (!empty($_POST['sab_form']) && check_admin_referer('sab_speichern')) {
            $form = sanitize_key((string) $_POST['sab_form']);
            if ($form === 'produkte') {
                update_option('sab_produkte', sanitize_textarea_field(wp_unslash($_POST['sab_produkte'] ?? '')), false);
                update_option('sab_slots', sanitize_textarea_field(wp_unslash($_POST['sab_slots'] ?? '')), false);
                update_option('sab_tausch', sanitize_textarea_field(wp_unslash($_POST['sab_tausch'] ?? '')), false);
                delete_transient('sab_alle_asins');
                $meldung = 'Gespeichert.';
            } elseif ($form === 'tests') {
                update_option('sab_tests', sanitize_textarea_field(wp_unslash($_POST['sab_tests'] ?? '')), false);
                update_option('sab_bildtests', sanitize_textarea_field(wp_unslash($_POST['sab_bildtests'] ?? '')), false);
                $meldung = 'Gespeichert.';
            } elseif ($form === 'einstellungen') {
                update_option('sab_client_id', sanitize_text_field(wp_unslash($_POST['sab_client_id'] ?? '')), false);
                $geheim = sanitize_text_field(wp_unslash($_POST['sab_client_secret'] ?? ''));
                if ($geheim !== '') { update_option('sab_client_secret', $geheim, false); } // leer = unverändert
                update_option('sab_tags', sanitize_textarea_field(wp_unslash($_POST['sab_tags'] ?? '')), false);
                update_option('sab_tags_extra', sanitize_textarea_field(wp_unslash($_POST['sab_tags_extra'] ?? '')), false);
                update_option('sab_ebay_ids', sanitize_textarea_field(wp_unslash($_POST['sab_ebay_ids'] ?? '')), false);
                update_option('sab_default_land', sanitize_key(wp_unslash($_POST['sab_default_land'] ?? 'de')), false);
                update_option('sab_hinweis_auto', !empty($_POST['sab_hinweis_auto']) ? '1' : '0', false);
                update_option('sab_karten_css', !empty($_POST['sab_karten_css']) ? '1' : '0', false);
                update_option('sab_weg_umbauen', !empty($_POST['sab_weg_umbauen']) ? '1' : '0', false);
                update_option('sab_strikt', !empty($_POST['sab_strikt']) ? '1' : '0', false);
                update_option('sab_preis_test', !empty($_POST['sab_preis_test']) ? '1' : '0', false);
                update_option('sab_btn_a', sanitize_text_field(wp_unslash($_POST['sab_btn_a'] ?? '')), false);
                update_option('sab_btn_b', sanitize_text_field(wp_unslash($_POST['sab_btn_b'] ?? '')), false);
                update_option('sab_mail_an', sanitize_email(wp_unslash($_POST['sab_mail_an'] ?? '')), false);
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
            } elseif ($form === 'austausch_viele' && !empty($_POST['sab_such_anfrage'])) {
                update_option('sab_such_anfrage', gmdate('Y-m-d H:i'), false);
                $meldung = 'Angefordert. Die Mac-Sitzung sucht vor der nächsten Austauschrunde bei Amazon und trägt die Treffer hier ein.';
            } elseif ($form === 'austausch_viele') {
                $m = []; $n = 0;
                $wahl = (array) ($_POST['sab_a'] ?? []); $eigen = (array) ($_POST['sab_eigen'] ?? []); $enamen = (array) ($_POST['sab_eigen_name'] ?? []);
                $nur_alt = self::asin((string) ($_POST['sab_einzeln'] ?? ''));   // „Jetzt tauschen“ an einer Karte: nur dieses Produkt
                foreach (array_keys($wahl + $eigen) as $alt) {
                    $alt0 = $alt; $eigenWahl = ($wahl[$alt] ?? '') === '__eigen';
                    if ($nur_alt !== '' && self::asin((string) $alt) !== $nur_alt) { continue; }
                    $neu = self::asin((string) ($wahl[$alt] ?? '') === '__eigen' ? (string) ($eigen[$alt] ?? '') : (string) ($wahl[$alt] ?? ''));
                    $alt = self::asin((string) $alt);
                    if (strlen($alt) !== 10 || strlen($neu) !== 10 || $alt === $neu) { continue; }
                    if (++$n > 10) { $m[] = 'Weitere beim nächsten Klick.'; break; }
                    $kn = $eigenWahl ? trim(sanitize_text_field(wp_unslash((string) ($enamen[$alt0] ?? '')))) : '';
                    self::lernen_merken($alt, $neu);
                    $m[] = self::tausch_ausfuehren($alt, $neu, mb_substr($kn, 0, 60));
                }
                $meldung = $m ? implode(' ', $m) : 'Nichts angehakt.';
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
                $meldung = ($gea ? $n . ' Link(s) in diesem Artikel umgebaut. Bitte den Artikel ansehen; Rückgängig geht in der Liste.' : 'Nichts umgebaut' . ($sk ? ' (' . $sk . ' übersprungen: keine Partner-ID für das Land oder Sonderinhalt)' : '') . '.')
                    . (self::$umbau_info ? ' Nach dem Bild angepasst: ' . implode(' | ', array_slice(array_unique(self::$umbau_info), 0, 8)) . '.' : '')
                    . (self::$umbau_abw ? ' NICHT umgebaut wegen Abweichung: ' . implode(' | ', array_slice(array_unique(self::$umbau_abw), 0, 8)) . '.' : '');
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
        if ($meldung) { echo '<div class="notice notice-success"><p>' . esc_html($meldung) . '</p></div>'; }
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
            echo self::statistik_html();
        } elseif ($tab === 'gruppen') {
            echo self::gruppen_html();
        } elseif ($tab === 'score') {
            echo self::score_html();
        } elseif ($tab === 'austausch') {
            echo self::austausch_html();
        } elseif ($tab === 'problem') {
            echo self::ausfall_html() . '<hr style="margin:2em 0">' . self::problem_html() . self::asin_diag_html();
        } elseif ($tab === 'umbau') {
            $eid = (int) ($_GET['entzerren'] ?? ($_POST['sab_id'] ?? 0));
            if (!empty($_GET['entzerren']) || (isset($_POST['sab_form']) && in_array($_POST['sab_form'], ['entzerren_los', 'entzerren_zurueck'], true))) { echo '<div id="sab-entzerren" style="border:2px solid #2271b1;padding:8px 16px;margin:12px 0;background:#fff">' . self::entzerren_vorschau_html($eid) . '</div>'; }
            $liste = self::umbau_artikelliste(); $nf = 0; $nk = 0; $nfertig = 0;
            foreach ($liste as $a) { $nf += $a['fest']; $nk += $a['kurz']; $nfertig += $a['fertig'] ? 1 : 0; }
            echo '<h2>Umbau: feste Amazon-Links durch Plugin-Platzhalter ersetzen</h2>'
               . '<ol style="max-width:760px"><li><strong>Vorschau ansehen:</strong> Pro Artikel siehst du, welche Links wie umgebaut würden. Es wird nichts gespeichert.</li>'
               . '<li><strong>Umbauen:</strong> Der Artikel wird gesichert (Original in der Sicherung, dazu eine WordPress-Revision) und die Links werden zu <code>[amz_bild]</code> / <code>[amz_link]</code>. Der alte Code bleibt als Rückfall zwischen den Tags.</li>'
               . '<li><strong>Danach</strong> lassen sich Produkte seitenweit tauschen (Reiter „Problemprodukte“), und der Artikel zählt für Statistik und A/B-Tests. <strong>Rückgängig</strong> geht pro Artikel.</li></ol>'
               . '<p>Tipp: Zuerst <strong>einen einzelnen Artikel</strong> umbauen und im Browser ansehen. Wenn alles stimmt, unten „Alle auf einmal“.</p>'
               . '<p>Gefunden: <strong>' . count($liste) . '</strong> Artikel mit Amazon-Links, davon <strong>' . $nfertig . '</strong> schon umgebaut; <strong>' . $nf . '</strong> feste Produkt-Links und <strong>' . $nk . '</strong> Kurzlinks (amzn.to) offen.</p>';
            echo '<p><a class="button button-primary" href="' . esc_url(add_query_arg(['page' => 'sab-umbau', 'alle_vorschau' => 1, 'seite' => 1], admin_url('admin.php'))) . '">Vorschau für alle offenen Artikel (Probleme sehen, dann mehrere auf einmal umbauen)</a></p>';
            if (!empty($_GET['alle_vorschau']) || !empty($_POST['sab_alle_vorschau'])) { echo self::umbau_uebersicht_html((int) ($_GET['seite'] ?? ($_POST['sab_alle_vorschau'] ?? 1))); }
            $vid = (int) ($_GET['vorschau'] ?? ($_POST['sab_vorschau'] ?? 0));
            if ($vid) { echo self::umbau_vorschau($vid); }
            if ($liste) {
                $zeile = function (array $a): void {
                    $vu = add_query_arg(['page' => 'sab-umbau', 'vorschau' => $a['id']], admin_url('admin.php'));
                    echo '<tr><td>' . ($a['fertig'] ? '' : '<input type="checkbox" name="sab_ids[]" form="umbau-sammel" value="' . (int) $a['id'] . '" class="sab-wahl">') . '</td><td><a href="' . esc_url((string) get_permalink($a['id'])) . '" target="_blank" rel="noopener">' . esc_html($a['titel'] ?: ('Beitrag ' . $a['id'])) . '</a></td><td data-v="' . (int) $a['fest'] . '">' . (int) $a['fest'] . '</td><td data-v="' . (int) $a['kurz'] . '">' . (int) $a['kurz'] . '</td>'
                       . '<td>' . ($a['fertig'] ? '<strong style="color:#1a7f37">umgebaut</strong>' : 'offen') . '</td><td><a class="button" href="' . esc_url($vu) . '">Vorschau</a> ';
                    echo '<form method="post" style="display:inline">'; wp_nonce_field('sab_speichern');
                    echo '<input type="hidden" name="sab_tab" value="umbau"><input type="hidden" name="sab_id" value="' . (int) $a['id'] . '">'
                       . ($a['fertig'] ? '<input type="hidden" name="sab_form" value="umbau_zurueck_einzeln"><button class="button">Rückgängig</button>' : '<input type="hidden" name="sab_form" value="umbau_einzeln"><button class="button button-primary">Umbauen</button>')
                       . '</form></td></tr>';
                };
                $kopf = '<table class="widefat striped sab-sort2" style="margin-top:1em"><thead><tr><th style="width:28px"><input type="checkbox" onclick="event.stopPropagation();document.querySelectorAll(\'.sab-wahl\').forEach(function(c){c.checked=this.checked}.bind(this))" title="Alle offenen markieren"></th><th>Artikel</th><th>feste Links</th><th>Kurzlinks</th><th>Stand</th><th>Aktion</th></tr></thead><tbody>';
                $offene = array_values(array_filter($liste, fn($x) => !$x['fertig'])); $fertige = array_values(array_filter($liste, fn($x) => $x['fertig']));
                echo '<h3>Noch offen (' . count($offene) . ')</h3><p class="description">Auf eine Überschrift klicken sortiert die Liste. Häkchen setzen und unten „Markierte umbauen“ klicken (bis zu ' . self::UMBAU_MAX . ' auf einmal).</p>' . $kopf;
                foreach (array_slice($offene, 0, 200) as $a) { $zeile($a); }
                echo '</tbody></table>';
                if ($offene) {
                    echo '<form method="post" id="umbau-sammel" style="margin:10px 0">'; wp_nonce_field('sab_speichern');
                    echo '<input type="hidden" name="sab_tab" value="umbau"><input type="hidden" name="sab_form" value="umbau_viele"><button class="button button-primary button-large">Markierte Artikel umbauen</button> <span class="description">Jeder Artikel wird vorher gesichert und kann einzeln zurückgesetzt werden.</span></form>';
                }
                if ($fertige) {
                    echo '<details style="margin-top:1em"><summary><strong>Schon umgebaut (' . count($fertige) . ')</strong></summary>' . $kopf;
                    foreach (array_slice($fertige, 0, 200) as $a) { $zeile($a); }
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
        } elseif ($tab === 'vorschau') {
            echo self::vorschau_html();
        } elseif ($tab === 'tests') {
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
               . '<tr><th>Partner-IDs</th><td><textarea name="sab_tags" rows="3" class="regular-text code">' . esc_textarea((string) get_option('sab_tags', ''))
               . '</textarea><p class="description">Pro Zeile: <code>land=partner-id</code>, zum Beispiel <code>es=ayuba09-21</code></p></td></tr>'
               . '<tr><th>Weitere Partner-IDs</th><td><textarea name="sab_tags_extra" rows="3" class="regular-text code">' . esc_textarea((string) get_option('sab_tags_extra', ''))
               . '</textarea><p class="description">Eine ID pro Zeile, zum Beispiel <code>cpflanzhilfe-21</code> (optional mit Notiz dahinter). Nur diese IDs darf ein Platzhalter mit <code>tag="…"</code> oder <code>tracking_id="…"</code> statt der Standard-ID benutzen. Der Umbau übernimmt sie so.</p></td></tr>'
               . '<tr><th>eBay-Kampagnen-IDs</th><td><textarea name="sab_ebay_ids" rows="3" class="regular-text code">' . esc_textarea((string) get_option('sab_ebay_ids', ''))
               . '</textarea><p class="description">Eine Kampagnen-ID (campid) pro Zeile, die erste ist der Standard. Beispiel: <code>5338619899 Standard</code>. Sie gilt, wenn bei einem eBay-Link keine eigene <code>campid</code> steht.</p></td></tr>'
               . '<tr><th>Pflichthinweis</th><td><label><input type="checkbox" name="sab_hinweis_auto" value="1"' . (get_option('sab_hinweis_auto') === '1' ? ' checked' : '')
               . '> automatisch im Fuß jeder Seite mit Partnerlinks</label><br><input type="text" name="sab_hinweis_text" class="large-text" placeholder="leer = Standardsatz je Land" value="'
               . esc_attr((string) get_option('sab_hinweis_text', '')) . '"></td></tr>'
               . '<tr><th>Karten-Design</th><td><label><input type="checkbox" name="sab_karten_css" value="1"' . (get_option('sab_karten_css') !== '0' ? ' checked' : '')
               . '> kleines Karten-Design für <code>[amz_produkt]</code> laden (weißer Kasten, grüner Button)</label></td></tr>'
               . '<tr><th>Umbau bei unbekannten Produkten</th><td><label><input type="checkbox" name="sab_weg_umbauen" value="1"' . (get_option('sab_weg_umbauen', '1') === '1' ? ' checked' : '')
               . '> Produkte, die Amazon nicht mehr kennt, trotzdem umbauen: Die Stelle zeigt „Momentan nicht verfügbar“, getauscht wird später mit Vorrang im Reiter Austausch</label></td></tr>'
               . '<tr><th>Nicht verfügbar</th><td><label><input type="checkbox" name="sab_strikt" value="1"' . (get_option('sab_strikt') === '1' ? ' checked' : '')
               . '> Produkte, die Amazons Schnittstelle als „nicht verfügbar“ meldet, wie ausgefallen behandeln und durch den Ersatz aus der Liste ersetzen</label><p class="description">Aus lassen, wenn Amazon die Lieferbarkeit für einen anderen Lieferort prüft als deinen: Dann würde ein lieferbares Produkt fälschlich ersetzt.</p></td></tr>'
               . '<tr><th>Wochenbericht</th><td><input type="email" name="sab_mail_an" class="regular-text" placeholder="leer = keine Mail" value="' . esc_attr((string) get_option('sab_mail_an', '')) . '"><p class="description">Einmal pro Woche eine Mail mit Klicks, Ausfällen und Seiten mit vielen Partnerlinks. An dieselbe Adresse gehen sofort Meldungen, wenn ein Produkt ausfällt oder die Amazon-Schnittstelle nicht antwortet.</p></td></tr>'
               . '<tr><th>Preis-Test</th><td><label><input type="checkbox" name="sab_preis_test" value="1"' . (get_option('sab_preis_test') === '1' ? ' checked' : '')
               . '> A/B-Test: Karte ohne Preis (A) gegen Karte mit Preis (B)</label><p class="description">Jeder Artikel bekommt fest eine Variante; Auswertung unter A/B-Tests als „preis-test“. Hat Vorrang vor dem Button-Test.</p></td></tr>'
               . '<tr><th>Button-Test</th><td><input type="text" name="sab_btn_a" class="regular-text" placeholder="Text A, z. B. Ver oferta en Amazon" value="' . esc_attr((string) get_option('sab_btn_a', '')) . '"> '
               . '<input type="text" name="sab_btn_b" class="regular-text" placeholder="Text B, z. B. Ver precio en Amazon" value="' . esc_attr((string) get_option('sab_btn_b', '')) . '">'
               . '<p class="description">Beide ausfüllen = A/B-Test der Button-Texte (jeder Artikel bekommt fest einen Text; Auswertung unter A/B-Tests als „button-test“). Leer lassen = Standardtext. Gilt nur für Boxen ohne eigenen Text.</p></td></tr>'
               . '<tr><th>Standardland</th><td><input type="text" name="sab_default_land" size="6" value="' . esc_attr(self::standardland())
               . '"> <span class="description">de, es, fr, it, co.uk oder com</span></td></tr></table>'
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
