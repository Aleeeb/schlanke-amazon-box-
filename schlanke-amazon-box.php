<?php
/**
 * Plugin Name: 1a Amazon Box 0.37.0
 * Description: Kleiner Ersatz für AAWP. Zeigt Amazon-Produktbilder, Namen und Textlinks über die Creators API. Bilder kommen vom eigenen Server. Erkennt ausgefallene Produkte und setzt den nächsten Ersatz aus einer Liste ein.
 * Version: 0.37.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) { exit; }

/*
 * Zugangsdaten: am einfachsten unter Seitenleiste > 1a Amazon Box eintragen.
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
 * Einstellungen: Seitenleiste > 1a Amazon Box (Produkte mit Kurznamen, Ersatzlisten, Ausfälle).
 */

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

    const VERSION = '0.37.0';
    const FAV_TEXT = ['de' => 'Unsere Empfehlung', 'es' => 'Nuestra elección', 'fr' => 'Notre choix', 'it' => 'La nostra scelta', 'co.uk' => 'Our pick', 'com' => 'Our pick'];
    const PREIS_STAND = ['de' => 'Stand', 'es' => 'a fecha', 'fr' => 'au', 'it' => 'al', 'co.uk' => 'as of', 'com' => 'as of'];
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
        add_shortcode('amz_hinweis', [__CLASS__, 'sc_hinweis']);
        add_shortcode('aawp',        [__CLASS__, 'sc_aawp']);
        add_action('sab_pruefen',    [__CLASS__, 'pruefen']);
        add_action('sab_holen',      [__CLASS__, 'holen'], 10, 2);
        add_action('sab_bild_holen', [__CLASS__, 'bild_holen'], 10, 3);
        add_action('admin_menu',     [__CLASS__, 'menue']);
        add_action('rest_api_init',  [__CLASS__, 'rest']);
        add_action('wp_footer',      [__CLASS__, 'messskript']);
        add_action('wp_footer',      [__CLASS__, 'fuss'], 5);
        add_filter('pre_set_site_transient_update_plugins', [__CLASS__, 'update_pruefen']);
        add_action('admin_init',     [__CLASS__, 'tabelle']);
        if (!wp_next_scheduled('sab_pruefen')) { wp_schedule_event(time() + 300, 'daily', 'sab_pruefen'); }
    }

    // ---------- Zugang ----------

    // Zugangsdaten: wp-config.php hat Vorrang, sonst die Eingabemaske unter Seitenleiste > 1a Amazon Box

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
            $t = array_map('trim', explode('=', $z, 2));
            if (($t[0] ?? '') !== '' && ($t[1] ?? '') !== '') { $out[strtolower($t[0])] = $t[1]; }
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
        if (is_wp_error($r) || wp_remote_retrieve_response_code($r) !== 200) { return ''; }
        $j = json_decode(wp_remote_retrieve_body($r), true);
        if (empty($j['access_token'])) { return ''; }
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
                'itemIds'     => [$asin],
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
        for ($versuch = 0; $versuch < 2; $versuch++) {
            $warte = 1.1 - (microtime(true) - $zuletzt);
            if ($warte > 0 && !defined('SAB_SYNC')) { usleep((int) ($warte * 1000000)); }
            $r = wp_remote_post(self::API, $args);
            $zuletzt = microtime(true);
            if (is_wp_error($r) || wp_remote_retrieve_response_code($r) !== 429) { break; }
            if (!defined('SAB_SYNC')) { usleep(2000000); }
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

    /**
     * Produktscore 0 bis 100 aus den Amazon-Daten: Verkaufsrang (bis 45), Buy Box (20), Verfügbarkeit (20), Preis (5), Streichpreis (5), Aktion (5).
     * Der Rang gilt nur innerhalb der Kategorie, darum ist der Score ein Richtwert zum Vergleichen ähnlicher Produkte, kein Urteil über die Qualität.
     * @return array{0:int,1:string} [Score, Hinweis]
     */
    private static function score(array $x): array {
        $r = $x['rang'] ?? null; $pkt = 0;
        if ($r !== null) { $pkt += $r <= 1000 ? 45 : ($r <= 5000 ? 38 : ($r <= 20000 ? 28 : ($r <= 50000 ? 18 : ($r <= 150000 ? 8 : 2)))); }
        if (!empty($x['kaufen'])) { $pkt += 20; }
        $st = (string) ($x['status'] ?? '');
        if (!empty($x['trotz']) || $st === 'IN_STOCK' || ($st === '' && !empty($x['lieferbar']))) { $pkt += 20; }
        elseif ($st === 'IN_STOCK_SCARCE') { $pkt += 14; }
        elseif (!empty($x['lieferbar']) && $st !== 'OUT_OF_STOCK') { $pkt += 10; }
        if (($x['preis'] ?? '') !== '') { $pkt += 5; }
        if (!empty($x['spar']['streich'])) { $pkt += 5; }
        if (!empty($x['deal'])) { $pkt += 5; }
        $hinweis = '';
        if ($r === null) { $hinweis = 'ohne Verkaufsrang'; }
        if (self::unklar($x) && empty($x['trotz']) && $r !== null && $r <= 20000) { $hinweis = 'Schnittstelle prüfen: guter Rang, aber kein Angebot'; }
        return [$pkt, $hinweis];
    }

    private static function scorezelle(array $x): string {
        [$p, $h] = self::score($x);
        $farbe = $p >= 70 ? '#1a7f37' : ($p >= 40 ? '#b36b00' : '#a00');
        return '<strong style="color:' . $farbe . ';font-size:1.2em">' . $p . '</strong>' . ($h !== '' ? '<br><small>' . esc_html($h) . '</small>' : '');
    }

    /** Sortieren per Klick auf die Überschrift und feststehende Kopfzeile für alle Tabellen mit der Klasse „sab-sort2“. */
    private static function tabellenhilfe(): string {
        return '<style>table.sab-sort2 thead th{position:sticky;top:32px;z-index:3;background:#f6f7f7;cursor:pointer;box-shadow:0 1px 0 #c3c4c7}table.sab-sort2 thead th.sab-auf:after{content:" \\25B2"}table.sab-sort2 thead th.sab-ab:after{content:" \\25BC"}</style>'
             . '<script>(function(){function v(c){if(!c)return "";var d=c.getAttribute("data-v");if(d!==null)return d===""?"":parseFloat(d);var i=c.querySelector("input[type=checkbox]");if(i)return i.checked?1:0;var t=c.textContent.trim().toLowerCase();var n=parseFloat(t.replace(/\\./g,"").replace(",",".").replace(/[^0-9.\\-]/g,""));return isNaN(n)||!/^[^a-z]*[0-9]/.test(t)?t:n}'
             . 'document.querySelectorAll("table.sab-sort2").forEach(function(T){var H=T.tHead&&T.tHead.rows[0];if(!H)return;[].forEach.call(H.cells,function(th,i){th.addEventListener("click",function(){var b=T.tBodies[0],r=[].slice.call(b.rows),d=th.classList.contains("sab-auf")?-1:1;'
             . '[].forEach.call(H.cells,function(x){x.classList.remove("sab-auf","sab-ab")});th.classList.add(d===1?"sab-auf":"sab-ab");'
             . 'r.sort(function(x,y){var a=v(x.cells[i]),c=v(y.cells[i]);if(a===""&&c!=="")return 1;if(c===""&&a!=="")return -1;return(a<c?-1:a>c?1:0)*d});r.forEach(function(e){b.appendChild(e)})})})})})();</script>';
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

    /** Täglicher Lauf: alle bekannten Produkte frisch holen, Ausfälle eintragen oder löschen. */
    public static function pruefen(): void {
        self::alte_bilder_loeschen();
        $land = self::land('');
        $alle = array_keys(self::produkte());
        foreach (self::slots() as $liste) { $alle = array_merge($alle, $liste); }
        foreach (self::tests() as $liste) { $alle = array_merge($alle, $liste); }
        $alle = array_merge($alle, array_values(self::tausch_regeln()));
        $vorher = (array) get_option('sab_ausfall', []);
        $neu = []; $unerreichbar = 0; $gesamt = 0;
        foreach (array_unique($alle) as $asin) {
            $gesamt++;
            $p = self::produkt($asin, $land, true);
            if (!$p) { $unerreichbar++; continue; } // Amazon nicht erreichbar: nichts ändern
            if (self::brauchbar($p)) { self::ausfall_loeschen($asin); } else {
                self::ausfall_setzen($asin, $land, $p);
                if (!isset($vorher[$asin])) { $neu[] = (self::produkte()[$asin] ?? '') ?: ($p['titel'] ?? '') ?: $asin; }
            }
        }
        if ($neu) { self::alarm('ausfall', 'Neue Produkte nicht mehr verfügbar', "Seit dem letzten Lauf ausgefallen:\n  " . implode("\n  ", $neu) . "\n\nDas Plugin setzt, wo eine Ersatzliste hinterlegt ist, automatisch den nächsten Ersatz ein.\nÜbersicht: Seitenleiste > 1a Amazon Box > Ausfälle und Warnungen"); }
        if ($gesamt > 0 && $unerreichbar === $gesamt) { self::alarm('api', 'Amazon-Schnittstelle antwortet nicht', "Beim täglichen Lauf konnte kein einziges Produkt von Amazon abgerufen werden.\nMögliche Ursachen: Zugangsdaten (Credentials) abgelaufen, Amazon-Konto gesperrt oder Amazon vorübergehend nicht erreichbar.\nDie Seiten zeigen weiter die zwischengespeicherten Daten, solange sie vorhanden sind.\nPrüfen: Seitenleiste > 1a Amazon Box > Übersicht."); }
        self::wochenmail();
    }

    /** Sofortmeldung per Mail (an dieselbe Adresse wie der Wochenbericht), je Art höchstens einmal in 20 Stunden. */
    private static function alarm(string $art, string $betreff, string $text): void {
        $an = trim((string) get_option('sab_mail_an', ''));
        if ($an === '') { return; }
        $z = (array) get_option('sab_alarm_zuletzt', []);
        if (time() - (int) ($z[$art] ?? 0) < 20 * HOUR_IN_SECONDS) { return; }
        if (wp_mail($an, '1a Amazon Box: ' . $betreff, $text)) { $z[$art] = time(); update_option('sab_alarm_zuletzt', $z, false); }
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
        if (wp_mail($an, '1a Amazon Box: Wochenbericht', $text)) { update_option('sab_mail_zuletzt', time(), false); }
    }

    private static function wochenmail_text(array $klicks, array $ausfall, array $warn): string {
        $namen = self::produkte();
        $z = ['1a Amazon Box: die letzten 7 Tage', ''];
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
        $a = shortcode_atts(['slot' => '', 'asin' => '', 'land' => '', 'breite' => '', 'text' => ''], $a);
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
        if (!$w) { return (string) $inhalt; }
        $asin = $w['asin']; $p = $w['p'];
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
            set_transient('sab_update_json', is_array($m) ? $m : [], $m ? 6 * HOUR_IN_SECONDS : 15 * MINUTE_IN_SECONDS);  // bei Fehlern bald erneut versuchen
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
    const KARTEN_CSS = '.sab-produkt{margin:1.4em 0;--sab-akzent:#ffa41c;--sab-akzent-dunkel:#fa8900}.sab-link{position:relative;display:flex;flex-direction:column;align-items:center;gap:14px;max-width:560px;margin:0 auto;padding:18px;border:1px solid #e3e6e6;border-radius:12px;text-decoration:none!important;color:inherit;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.06);transition:box-shadow .15s,transform .15s}.sab-link:hover{box-shadow:0 6px 18px rgba(0,0,0,.12);transform:translateY(-1px)}.sab-bildbox{flex:0 0 auto;display:flex;align-items:center;justify-content:center;width:100%;max-width:170px}.sab-bildbox img{max-width:100%;max-height:170px;width:auto;height:auto;object-fit:contain}.sab-info{display:flex;flex-direction:column;align-items:center;gap:8px;text-align:center;min-width:0;flex:1}.sab-name{font-weight:600;line-height:1.35;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}.sab-preis{font-size:1.3em;font-weight:700;color:#b12704}.sab-preis small{font-size:.6em;font-weight:400;color:#666}.sab-text{display:inline-block;padding:.6em 1.3em;border:1px solid #e08a00;border-radius:999px;background:var(--sab-akzent);color:#111;font-weight:600;line-height:1.2;transition:background .15s}.sab-link:hover .sab-text{background:var(--sab-akzent-dunkel)}.sab-fav{position:absolute;top:-10px;left:14px;padding:2px 10px;border-radius:999px;background:#2e7d32;color:#fff;font-size:.78em;font-weight:600;box-shadow:0 1px 3px rgba(0,0,0,.2)}@media(min-width:560px){.sab-link{flex-direction:row;text-align:left;gap:22px}.sab-bildbox{width:170px}.sab-info{align-items:flex-start;text-align:left}}.sab-hinweis{color:#666}';

    public static function fuss(): void {
        if (!self::$genutzt) { return; }
        if (get_option('sab_karten_css') !== '0') { echo '<style id="sab-karten">' . self::KARTEN_CSS . '</style>'; }
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
                echo '<div style="position:fixed;left:8px;bottom:8px;z-index:99999;background:#fff3cd;border:1px solid #d4a017;color:#000;padding:6px 10px;font:13px sans-serif">1a Amazon Box: ' . (int) $n . ' Partnerlinks auf dieser Seite (Warnung ab ' . self::LINK_WARNUNG . ')</div>';
            }
        }
    }

    public static function sc_bild($a, $inhalt = ''): string {
        $a = shortcode_atts(['asin' => '', 'land' => '', 'breite' => '', 'alt' => '', 'class' => ''], $a);
        $land = self::land($a['land']);
        $asin = self::getauscht(self::asin($a['asin']));
        $p = self::produkt($asin, $land);
        if (!self::brauchbar($p)) { return (string) $inhalt; }  // Rückfall: bisheriger HTML-Code
        [$bi, $bv] = self::bildvariante($asin, $p);
        $cls = trim(preg_replace('/[^A-Za-z0-9 _-]/', '', (string) $a['class']));
        return self::bild_html($p, $asin, $land, $a['alt'] ?: $p['titel'], (int) $a['breite'], $bv !== '' ? 'bild-' . $asin : '', $cls, $bi, $bv)
            ?: ($bi ? self::bild_html($p, $asin, $land, $a['alt'] ?: $p['titel'], (int) $a['breite'], '', $cls) : '')
            ?: (string) $inhalt;
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
        $a = shortcode_atts(['fields' => '', 'box' => '', 'link' => '', 'value' => 'thumb', 'image_size' => '', 'image_width' => '', 'image_height' => '', 'class' => '', 'land' => '', 'title' => ''], (array) $a);
        $quelle = $a['box'] !== '' ? 'box' : ($a['link'] !== '' ? 'link' : 'fields');
        $out = '';
        foreach (explode(',', (string) $a[$quelle]) as $roh) {
            $asin = substr(self::asin($roh), 0, 10);
            if (strlen($asin) !== 10) { continue; }
            if ($quelle === 'box') {
                $out .= self::sc_produkt(['asin' => $asin, 'land' => $a['land']], '');
            } elseif ($quelle === 'link') {
                $out .= self::sc_link(['asin' => $asin, 'land' => $a['land'], 'class' => $a['class']], (string) ($inhalt ?: $a['title']));
            } else {
                $v = strtolower(trim((string) $a['value']));
                if (in_array($v, ['thumb', 'image', 'thumbnail'], true)) {
                    $out .= self::sc_bild(['asin' => $asin, 'land' => $a['land'], 'class' => $a['class'], 'breite' => (string) (int) $a['image_width']], '');
                } elseif ($v === 'title') {
                    $out .= self::sc_link(['asin' => $asin, 'land' => $a['land'], 'class' => $a['class']], '');
                } elseif ($v === 'button') {
                    $out .= self::sc_link(['asin' => $asin, 'land' => $a['land'], 'class' => 'sab-text'], self::BUTTON_TEXT[self::land($a['land'])] ?? self::BUTTON_TEXT['de']);
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
        $a = shortcode_atts(['asin' => '', 'land' => '', 'kurz' => ''], $a);
        $asin = self::getauscht(self::asin($a['asin']));
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
        if ($a['campid'] !== '') { $q['campid'] = preg_replace('/\D/', '', $a['campid']); }
        if ($a['customid'] !== '') { $q['customid'] = preg_replace('/[^A-Za-z0-9_-]/', '', $a['customid']); }
        return '<a href="' . esc_url('https://' . $host . '/sch/i.html?' . http_build_query($q)) . '" target="_blank" rel="nofollow sponsored noopener">' . esc_html((string) ($inhalt !== '' ? $inhalt : 'eBay')) . '</a>';
    }

    public static function sc_link($a, $inhalt = ''): string {
        $a = shortcode_atts(['asin' => '', 'land' => '', 'class' => ''], $a);
        $orig = self::asin($a['asin']);
        $asin = self::getauscht($orig);
        $p = self::produkt($asin, self::land($a['land']));
        if ($asin !== $orig && trim((string) $inhalt) !== '') { $inhalt = self::name_tauschen((string) $inhalt, $orig, $asin, self::land($a['land']), $p); }
        if ($p && !empty($p['weg'])) { return esc_html($inhalt); }
        $link = $p['link'] ?? '';
        if (!$link) { // Amazon nicht erreichbar und kein frischer Stand: schlichter Link mit Partner-ID
            $land = self::land($a['land']); $tag = self::tag($land);
            if (!$asin || !$tag) { return esc_html($inhalt); }
            $link = 'https://' . self::LAENDER[$land][0] . '/dp/' . $asin . '?tag=' . rawurlencode($tag);
        }
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
    public static function messen($req) {
        global $wpdb;
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
        if (!self::$genutzt) { return; }
        $url = esc_url_raw(rest_url('sab/v1/m'));
        echo '<script>(function(){var U=' . wp_json_encode($url) . ',p=null;'
           . 'function S(o){try{navigator.sendBeacon(U,new Blob([JSON.stringify(o)],{type:"text/plain"}))}catch(e){}}'
           . 'document.addEventListener("click",function(e){var a=e.target.closest&&e.target.closest("a[data-sab-asin]");if(!a)return;'
           . 'var id=Math.random().toString(36).slice(2,10),c=a.classList;'
           . 'S({id:id,asin:a.getAttribute("data-sab-asin"),slot:a.getAttribute("data-sab-slot")||"",var:a.getAttribute("data-sab-var")||"",typ:c.contains("sab-bild")?"bild":c.contains("sab-link")?"box":"text",pfad:location.pathname});'
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
    private static function alle_asins(): array {
        $c = get_transient('sab_alle_asins');
        if (is_array($c)) { return $c; }
        $alle = array_keys(self::produkte());
        foreach (self::slots() as $liste) { $alle = array_merge($alle, $liste); }
        foreach (self::tests() as $liste) { $alle = array_merge($alle, $liste); }
        $alle = array_merge($alle, array_values(self::tausch_regeln()));
        foreach (self::gruppen() as $liste) { $alle = array_merge($alle, $liste); }
        global $wpdb;
        if (isset($wpdb)) {
            $rows = $wpdb->get_col("SELECT post_content FROM {$wpdb->posts} WHERE post_status='publish' AND post_type IN ('post','page') AND (post_content LIKE '%[amz\\_%' OR post_content LIKE '%[aawp%')");
            foreach ((array) $rows as $c) {
                // alle Plugin-Shortcodes mit asin="..." (bild, link, produkt) und die AAWP-Zeilen (fields/box/link, mehrere ASINs mit Komma)
                if (preg_match_all('/\[amz_(?:bild|link|produkt)[^\]]*asin="([A-Za-z0-9]{10})"/', $c, $m)) { $alle = array_merge($alle, array_map([__CLASS__, 'asin'], $m[1])); }
                if (preg_match_all('/\[aawp\b[^\]]*\b(?:fields|box|link)="([^"]+)"/', $c, $m)) {
                    foreach ($m[1] as $liste) { foreach (explode(',', $liste) as $a) { $a = substr(self::asin($a), 0, 10); if (strlen($a) === 10) { $alle[] = $a; } } }
                }
            }
        }
        $alle = array_values(array_unique(array_filter($alle)));
        set_transient('sab_alle_asins', $alle, 10 * MINUTE_IN_SECONDS);
        return $alle;
    }

    /** Holt Details für die Übersicht (höchstens 1 Stunde zwischengespeichert, Amazon-Regel für Angebote). */
    private static function uebersicht_daten(string $land, bool $frisch = false): array {
        $key = "sab_uebersicht_{$land}";
        if ($frisch) { delete_transient($key); }
        $c = get_transient($key);
        if (is_array($c)) { return $c; }
        $token = self::token($land); $tag = self::tag($land);
        if (!$token || !$tag) { return ['fehler' => 'Zugangsdaten oder Partner-ID fehlen.', 'items' => []]; }
        $mp = self::LAENDER[$land][0]; $items = []; $fehler = '';
        foreach (array_chunk(self::alle_asins(), 10) as $paket) {
            $r = self::api_post([
                'timeout' => 15,
                'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json', 'x-marketplace' => $mp],
                'body'    => wp_json_encode([
                    'itemIds' => $paket, 'itemIdType' => 'ASIN', 'marketplace' => $mp, 'partnerTag' => $tag,
                    'resources' => ['images.primary.large', 'images.variants.large', 'itemInfo.title',
                        'offersV2.listings.availability', 'offersV2.listings.price', 'offersV2.listings.isBuyBoxWinner',
                        'offersV2.listings.merchantInfo', 'offersV2.listings.dealDetails', 'offersV2.listings.type',
                        'browseNodeInfo.browseNodes', 'browseNodeInfo.websiteSalesRank'],
                ]),
            ]);
            if (is_wp_error($r) || wp_remote_retrieve_response_code($r) !== 200) {
                $fehler = 'Amazon-Abfrage fehlgeschlagen (Code ' . (is_wp_error($r) ? 'Netz' : wp_remote_retrieve_response_code($r))
                        . '): ' . (is_wp_error($r) ? '' : mb_substr((string) wp_remote_retrieve_body($r), 0, 900));
                continue;
            }
            $j = json_decode(wp_remote_retrieve_body($r), true);
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
        }
        // Bewertungen liefert diese Schnittstelle nicht (Antwort immer ohne Werte): keine Abfrage mehr, das spart Abfragen (Limit 1 pro Sekunde)
        $bew = ['fehler' => '', 'werte' => []];
        $out = ['fehler' => $fehler, 'items' => $items, 'bewertungen' => $bew];
        if (!$fehler) { set_transient($key, $out, HOUR_IN_SECONDS); }
        return $out;
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
                . '<td data-v="' . ($z ? (float) $r['m'] : -1) . '">' . ($z ? esc_html(round((float) $r['m'] / 60, 1) . ' Min.') : '–') . '</td></tr>';
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
        if (!empty($d['fehler'])) { return $h . '<div class="notice notice-error inline"><p>' . esc_html($d['fehler']) . '</p></div>'; }
        $namen = self::produkte(); $trotz = (array) get_option('sab_trotz', []);
        $h .= '<p class="description">Stand höchstens 1 Stunde alt. Der Score (0 bis 100) vergleicht Verkaufsrang, Buy Box, Verfügbarkeit, Preis und Aktion. Der Rang gilt nur innerhalb einer Kategorie, innerhalb einer Gruppe ähnlicher Produkte ist der Vergleich aussagekräftig.</p>';
        foreach ($gr as $name => $asins) {
            $zeilen = [];
            foreach ($asins as $a) {
                $x = $d['items'][$a] ?? null;
                if ($x) { $x['trotz'] = isset($trotz[$a]); }
                $zeilen[] = ['asin' => $a, 'x' => $x, 'score' => $x ? self::score($x)[0] : -1];
            }
            usort($zeilen, fn($p, $q) => $q['score'] <=> $p['score']);
            $h .= '<h3 style="margin-top:1.5em">' . esc_html($name) . ' (' . count($zeilen) . ')</h3><table class="widefat striped sab-sort2"><thead><tr><th>#</th><th>ASIN / Name</th><th>Score</th><th>Lieferbar</th><th>Buy Box</th><th>Preis</th><th>Kategorie / Verkaufsrang</th></tr></thead><tbody>';
            $gut = [];
            foreach ($zeilen as $i => $z) {
                $x = $z['x'];
                if (!$x) { $h .= '<tr><td>' . ($i + 1) . '</td><td><code>' . esc_html($z['asin']) . '</code> ' . esc_html($namen[$z['asin']] ?? '') . '</td><td colspan="5"><strong>Amazon kennt das Produkt nicht (mehr)</strong></td></tr>'; continue; }
                $brauchbar = !empty($x['trotz']) || (!empty($x['lieferbar']) && ($x['status'] ?? '') !== 'OUT_OF_STOCK') || (self::unklar($x) && isset($x['rang']) && $x['rang'] <= 20000);
                if ($brauchbar) { $gut[] = $z['asin']; }
                $h .= '<tr><td>' . ($i + 1) . '</td><td><code>' . esc_html($z['asin']) . '</code><br><strong>' . esc_html(($namen[$z['asin']] ?? '') ?: mb_substr((string) ($x['titel'] ?? ''), 0, 70)) . '</strong></td><td>' . self::scorezelle($x) . '</td><td>' . self::lieferzelle($x) . '</td><td>'
                   . (($x['kaufen'] ?? null) === null ? '?' : ($x['kaufen'] ? '<strong style="color:#1a7f37">ja</strong>' : '<strong style="color:#a00">nein</strong>')) . '</td><td>' . esc_html(($x['preis'] ?? '') ?: '–') . self::sparzeile($x) . '</td><td>' . self::rangzelle($x) . '</td></tr>';
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
        return $h;
    }

    /** Fundstellen je ASIN: Artikel/Seiten dieser Webseite und wie oft das Produkt dort vorkommt (Shortcode, AAWP oder fester Link). */
    private static function fundstellen(array $asins): array {
        global $wpdb;
        $out = [];
        if (!isset($wpdb) || !$asins) { return $out; }
        $rows = $wpdb->get_results("SELECT ID, post_title, post_content FROM {$wpdb->posts} WHERE post_status='publish' AND post_type IN ('post','page') AND (post_content LIKE '%amazon.%' OR post_content LIKE '%[amz\\_%' OR post_content LIKE '%[aawp%' OR post_content LIKE '%amzn.to%')", ARRAY_A);
        foreach ((array) $rows as $r) {
            $c = (string) $r['post_content'];
            $ohne = preg_replace('#\[amz_(bild|produkt|link)\b[^\]]*\].*?\[/amz_\1\]|\[(?:amz_[a-z]+|aawp)\b[^\]]*\]#is', '', $c);   // ohne Plugin-Platzhalter: was übrig bleibt, sind feste Links
            foreach ($asins as $a) {
                $n = substr_count($c, $a);
                if ($n > 0) { $out[$a][(int) $r['ID']] = ['titel' => (string) $r['post_title'], 'n' => $n, 'fest' => substr_count($ohne, $a)]; }
            }
        }
        return $out;
    }

    /** Listen: Produkte, die Amazon nicht mehr kennt, und Produkte, die nicht verfügbar sind, je mit Artikeln und Häufigkeit. */
    private static function problem_html(): string {
        $land = self::land('');
        $d = self::uebersicht_daten($land, !empty($_POST['sab_neu']));
        $namen = self::produkte();
        $h = '<h2>Problemprodukte (' . esc_html($land) . ')</h2>'
           . '<p class="description">Stand höchstens 1 Stunde alt. Gezählt werden die Produkte, die per Plugin-Platzhalter (<code>[amz_…]</code>, <code>[aawp]</code>) eingebunden sind, samt allen Stellen in Artikeln dieser Webseite, auch feste Links.</p>'
           . '<form method="post">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="problem"><p><button class="button" name="sab_neu" value="1">Neu laden</button></p></form>';
        if (!empty($d['fehler'])) { return $h . '<div class="notice notice-error inline"><p>' . esc_html($d['fehler']) . '</p></div>'; }
        $weg = []; $nv = []; $uk = []; $wa = []; $trotz = (array) get_option('sab_trotz', []);
        foreach (self::alle_asins() as $asin) {
            $x = $d['items'][$asin] ?? null;
            if (!$x) { $weg[] = $asin; continue; }
            if (isset(((array) get_option('sab_trotz', []))[$asin])) { continue; }  // von dir als lieferbar bestätigt
            if (($x['preis'] ?? '') === '' && (($x['rang'] ?? null) === null || $x['rang'] > 300000)) { $wa[] = $asin; continue; }   // kein Preis und kein (oder ein sehr schlechter) Rang: wahrscheinlich nicht mehr kaufbar
            if (self::unklar($x)) { $uk[] = $asin; } elseif (empty($x['lieferbar']) || ($x['status'] ?? '') === 'OUT_OF_STOCK') { $nv[] = $asin; }
        }
        $fund = self::fundstellen(array_merge($weg, $nv, $uk, $wa));
        $empf = [];
        foreach (array_merge($weg, $wa, $nv, $uk) as $a) { $e = self::empfehlung($a, (array) ($d['items'] ?? []), $trotz); if ($e) { $empf[$a] = $e; } }
        $dp = 'https://' . self::LAENDER[$land][0] . '/dp/';
        $tabelle = function (array $asins, string $leer, bool $tausch = false) use ($fund, $namen, $dp, $d, $empf): string {
            if (!$asins) { return '<p>' . esc_html($leer) . '</p>'; }
            usort($asins, fn($a, $b) => array_sum(array_column($fund[$b] ?? [], 'n')) <=> array_sum(array_column($fund[$a] ?? [], 'n')));
            $t = '<table class="widefat striped sab-sort2"><thead><tr><th>ASIN / Name</th><th>Stellen gesamt</th><th>Artikel (Anzahl im Artikel)</th>' . ($tausch ? '<th>Empfehlung (hoher Score, gleiche Gruppe/Kategorie)</th><th>Austauschen gegen (neue ASIN)</th>' : '') . '</tr></thead><tbody>';
            foreach ($asins as $asin) {
                $f = $fund[$asin] ?? [];
                $titel = ($namen[$asin] ?? '') ?: ($d['items'][$asin]['titel'] ?? '');
                $li = '';
                foreach ($f as $pid => $x) { $li .= '<li><a href="' . esc_url((string) get_permalink($pid)) . '">' . esc_html($x['titel'] ?: ('Beitrag ' . $pid)) . '</a>: ' . (int) $x['n'] . '×' . (!empty($x['fest']) ? ' <span style="color:#a00">(davon ' . (int) $x['fest'] . ' feste Links, noch kein Platzhalter)</span>' : '') . '</li>'; }
                $t .= '<tr><td><a href="' . esc_url($dp . $asin) . '" target="_blank" rel="noopener"><code>' . esc_html($asin) . '</code></a><br><strong>' . esc_html($titel) . '</strong></td>'
                    . '<td>' . (int) array_sum(array_column($f, 'n')) . (array_sum(array_column($f, 'fest')) ? '<br><small style="color:#a00">' . (int) array_sum(array_column($f, 'fest')) . ' davon feste Links: erst im Reiter „Umbau“ umwandeln</small>' : '') . '</td><td>' . ($li ? '<ul style="margin:0">' . $li . '</ul>' : '–') . '</td>'
                    . ($tausch ? '<td>' . (isset($empf[$asin]) ? '<a href="' . esc_url($dp . $empf[$asin][0]) . '" target="_blank" rel="noopener"><code>' . esc_html($empf[$asin][0]) . '</code></a> ' . esc_html($empf[$asin][2]) . '<br><small>Score ' . (int) $empf[$asin][1] . '</small><br><label><input type="checkbox" name="sab_empf[' . esc_attr($asin) . ']" value="' . esc_attr($empf[$asin][0]) . '"> Empfehlung übernehmen</label>' : '–') . '</td>'
                        . '<td><input type="text" name="sab_neu_asin[' . esc_attr($asin) . ']" size="12" maxlength="10" placeholder="B0…"></td>' : '') . '</tr>';
            }
            return $t . '</tbody></table>' . self::tabellenhilfe();
        };
        $h .= '<form method="post">' . wp_nonce_field('sab_speichern', '_wpnonce', true, false) . '<input type="hidden" name="sab_tab" value="problem"><input type="hidden" name="sab_form" value="tausch_neu">'
            . '<h3>Amazon kennt das Produkt nicht mehr (' . count($weg) . ')</h3><p class="description">Neue ASIN eintragen und unten „Austauschen“ klicken: Das Plugin legt eine Austausch-Regel an, danach zeigen alle Plugin-Platzhalter (<code>[amz_…]</code>, <code>[aawp]</code>) das neue Produkt mit Bild, Link und Preis. Feste Links erst im Reiter „Umbau“ umwandeln. Rückgängig: Zeile im Reiter „Produkte und Ersatzlisten“ unter „Austausch-Regeln“ löschen.</p>' . $tabelle($weg, 'Keine.', true);
        $h .= '<h3 style="margin-top:2em">Wahrscheinlich austauschen: kein Preis und kein Verkaufsrang (' . count($wa) . ')</h3><p class="description">Diese Produkte haben weder Preis noch einen brauchbaren Rang (leer oder schlechter als Platz 300.000). Erfahrungsgemäß sind sie bei Amazon nicht mehr kaufbar. Rechts steht, wenn möglich, ein Ersatz mit hohem Score. Bitte vor dem Austausch kurz auf Amazon nachsehen.</p>' . $tabelle($wa, 'Keine.', true);
        $h .= '<h3 style="margin-top:2em">Nicht verfügbar / nicht auf Lager (' . count($nv) . ')</h3>' . $tabelle($nv, 'Keine.', true);
        $h .= '<h3 style="margin-top:2em">Unklar: Amazon meldet „nicht auf Lager“, aber ohne Preis und Verkäufer (' . count($uk) . ')</h3><p class="description">Hier stimmt die Schnittstelle oft nicht mit der Amazon-Seite überein (Beispiel: auf Amazon lieferbar, in der Schnittstelle „No disponible“). Bitte auf Amazon selbst nachsehen. Das Plugin zeigt diese Produkte weiter.</p>' . $tabelle($uk, 'Keine.', true);
        $h .= '<p><button class="button button-primary">Austauschen</button></p></form>';
        return $h;
    }

    private static function markenzellen(string $asin): string {
        $m = (array) get_option('sab_marken', []); $t = $m[$asin] ?? '';
        return '<td><input type="checkbox" name="sab_fav[' . esc_attr($asin) . ']" value="1"' . ($t === 'fav' ? ' checked' : '') . '></td>'
             . '<td><input type="checkbox" name="sab_ers[' . esc_attr($asin) . ']" value="1"' . ($t === 'ers' ? ' checked' : '') . '></td>'
             . '<td><input type="checkbox" name="sab_trotz[' . esc_attr($asin) . ']" value="1"' . (isset(((array) get_option('sab_trotz', []))[$asin]) ? ' checked' : '') . '></td>';
    }

    private static function klickzellen(?array $k): string {
        if (!$k) { return '<td>0</td><td>–</td><td>–</td>'; }
        $z = (int) $k['z']; $n = (int) $k['n'];
        return '<td>' . $n . ' (' . (int) $k['b'] . '/' . (int) $k['x'] . ')</td><td>' . $z . ($n ? ' (' . round(100 * $z / $n) . ' %)' : '') . '</td>'
             . '<td>' . ($z ? esc_html(round((float) $k['m'] / 60, 1) . ' Min.') : '–') . '</td>';
    }

    private static function uebersicht_html(): string {
        $land = self::land('');
        $d = self::uebersicht_daten($land, !empty($_POST['sab_neu']));
        $namen = self::produkte(); $ja = fn($v) => $v === null ? '?' : ($v ? '<strong style="color:#1a7f37">ja</strong>' : '<strong style="color:#a00">nein</strong>');
        $h = '<h2>Übersicht aller Produkte (' . esc_html($land) . ')</h2>'
           . '<p class="description">Stand höchstens 1 Stunde alt. Bilder kommen hier direkt von Amazon (nur im Admin-Bereich, nicht für Besucher). Die Lieferzeit liefert die API nicht; sie ist nur auf der Amazon-Seite mit spanischer Lieferadresse sichtbar.</p>'
           . '<p><button class="button" name="sab_neu" value="1">Übersicht neu laden</button></p>';
        if ($d['fehler']) { $h .= '<div class="notice notice-warning inline"><p>' . esc_html($d['fehler']) . '</p></div>'; }
        if ($d['fehler']) { return $h; }
        if (empty($d['bewertungen']['fehler']) && isset($d['bewertungen']) && !array_filter(array_column($d['bewertungen']['werte'], 'anzahl'), fn($v) => $v !== null)) {
            $h .= '<div class="notice notice-info inline"><p>Amazon liefert über diese Schnittstelle keine Bewertungen (Sterne und Anzahl): Die Abfrage wird ohne Fehler beantwortet, aber ohne Werte.</p></div>';
        }
        if (!empty($d['bewertungen']['fehler'])) { $h .= '<div class="notice notice-info inline"><p>' . esc_html($d['bewertungen']['fehler']) . '</p></div>'; }
        $kz = self::klickzahlen(); $dp = 'https://' . self::LAENDER[$land][0] . '/dp/';
        $h .= '<table class="widefat striped sab-sort2"><thead><tr><th>Bilder</th><th>ASIN / Name</th><th>Favorit</th><th>Ersetzen</th><th title="Du hast auf Amazon geprüft: lieferbar, obwohl die Schnittstelle etwas anderes meldet">Trotzdem lieferbar</th><th>Lieferbar</th><th>Buy Box<br><small>(Kaufen-Button)</small></th><th>Preis / Streichpreis</th><th title="0 bis 100: Verkaufsrang, Buy Box, Verfügbarkeit, Preis, Streichpreis, Aktion">Score</th><th>Aktion / Deal</th><th>Kategorie / Verkaufsrang</th><th>Verkäufer</th><th>Bewertung</th><th>Klicks (Bild/Text)</th><th>kamen zurück</th><th>Ø Zeit weg</th></tr></thead><tbody>';
        foreach (self::alle_asins() as $asin) {
            $x = $d['items'][$asin] ?? null;
            if ($x) { $x['trotz'] = isset(((array) get_option('sab_trotz', []))[$asin]); }
            if (!$x) { $h .= '<tr><td>–</td><td>' . esc_html($asin) . '<br>' . esc_html($namen[$asin] ?? '') . '</td>' . self::markenzellen($asin) . '<td colspan="11"><strong>Amazon kennt das Produkt nicht (mehr)</strong></td></tr>'; continue; }
            $b = '<div style="display:grid;grid-template-columns:repeat(2,minmax(0,64px));gap:4px;width:136px">';
            foreach ($x['bilder'] as $k => $u) { $b .= '<a href="' . esc_url($dp . $asin) . '" target="_blank" rel="noopener" title="Bild ' . ($k + 1) . '" style="display:inline-block;text-align:center;font-size:11px"><img src="' . esc_url($u) . '" alt="" style="width:60px;height:60px;object-fit:contain;margin:0 4px 4px 0;background:#fff" referrerpolicy="no-referrer"><br>' . ($k + 1) . '</a>'; }
            $b .= '</div>';
            $bw = $d['bewertungen']['werte'][$asin] ?? null;
            $bewzelle = $bw && $bw['anzahl'] !== null ? esc_html(($bw['sterne'] !== null ? $bw['sterne'] . ' ★ ' : '') . '(' . $bw['anzahl'] . ')') : '–';
            $h .= '<tr><td style="width:150px">' . $b . '</td><td><a href="' . esc_url($dp . $asin) . '" target="_blank" rel="noopener"><code>' . esc_html($asin) . '</code></a><br><strong>' . esc_html($namen[$asin] ?? '') . '</strong><br><small>'
               . '<a href="' . esc_url($dp . $asin) . '" target="_blank" rel="noopener">' . esc_html(mb_substr($x['titel'], 0, 90)) . '</a></small></td>'
               . self::markenzellen($asin) . '<td>' . self::lieferzelle($x) . '</td><td>' . (!empty($x['trotz']) ? 'ja' : (($x['kaufen'] ?? null) && self::unklar($x) ? '<strong>?</strong>' : (($x['kaufen'] ?? null) && (($x['status'] ?? '') === 'OUT_OF_STOCK' || empty($x['lieferbar'])) ? '<strong>nein</strong><br><small>Amazon zeigt zwar einen Kauf-Eintrag, das Produkt ist aber nicht verfügbar.</small>' : $ja($x['kaufen'])))) . '</td><td data-v="' . (preg_match('/[0-9][0-9.,]*/', (string) $x['preis'], $pm) ? (float) str_replace(',', '.', str_replace('.', '', $pm[0])) : '') . '">' . esc_html($x['preis'] ?: '–') . self::sparzeile($x) . self::sparzeile($x)
               . ($x['preis'] === '' || $x['verkaeufer'] === '' ? '<br><small style="color:#a00">' . esc_html($x['diag'] ?? '') . '</small><details><summary><small>Rohdaten</small></summary><code style="font-size:10px;word-break:break-all">' . esc_html($x['roh'] ?? '') . '</code></details>' : '') . '</td>'
               . '<td data-v="' . self::score($x)[0] . '">' . self::scorezelle($x) . '</td><td>' . self::primezelle($x) . '</td><td data-v="' . (isset($x['rang']) ? (int) $x['rang'] : '') . '">' . self::rangzelle($x) . '</td>'
               . '<td>' . esc_html($x['verkaeufer'] ?: '–') . '</td><td>' . $bewzelle . '</td>'
               . self::klickzellen($kz[$asin] ?? null) . '</tr>';
        }
        return $h . '</tbody></table>' . self::tabellenhilfe() . '<p><button class="button button-primary" name="sab_markieren" value="1">Markierungen speichern</button> <span class="description">Favorit: wird in Ersatzlisten bevorzugt. Ersetzen: rutscht ans Ende und steht unter „Ausfälle und Warnungen“.</span></p>';
    }

    // ---------- Umbau-Werkzeug: feste Amazon-Links in Artikeln => Plugin-Platzhalter ----------

    const UMBAU_PROSEITE = 15; // Artikel pro Durchgang (kurz genug gegen Zeitlimits)

    /** Löst einen Amazon-Kurzlink (Sitestripe, amzn.to / amzn.eu) über seine Weiterleitung auf die Produkt-URL auf; Ergebnis 30 Tage gemerkt. */
    /** Zähler: Kurzlinks, die in diesem Durchgang wegen der Abruf-Grenze noch nicht aufgelöst wurden. */
    private static $kurz_offen = 0;

    private static function kurzlink_aufloesen(string $url): string {
        static $budget = 40;   // höchstens so viele neue Abrufe pro Seitenaufruf, der Rest folgt beim nächsten Klick
        $key = 'sab_kurz_' . md5($url);
        $c = get_transient($key);
        if ($c !== false) { return (string) $c; }
        if ($budget <= 0) { self::$kurz_offen++; return ''; }
        $budget--;
        $r = wp_remote_head($url, ['timeout' => 8, 'redirection' => 6]);
        $ziel = '';
        if (!is_wp_error($r)) {
            $o = $r['http_response'] ?? null;
            $ziel = ($o && method_exists($o, 'get_response_object') && !empty($o->get_response_object()->url)) ? (string) $o->get_response_object()->url : '';
        }
        if ($ziel === '' && !is_wp_error($r)) { return ''; }   // nicht entscheidbar, später nochmal
        set_transient($key, $ziel, $ziel === '' ? DAY_IN_SECONDS : 30 * DAY_IN_SECONDS);
        return $ziel;
    }

    private static function umbau_url(string $url, bool $aufloesen = false): ?array {
        $url = html_entity_decode($url);
        if ($aufloesen && preg_match('#^https?://(?:amzn\.to|amzn\.eu|a\.co)/#i', $url)) { $url = self::kurzlink_aufloesen($url); if ($url === '') { return null; } }
        if (!preg_match('#^https?://(?:www\.)?amazon\.(es|de|fr|it|co\.uk|com)/(?:[^?\#]*/)?(?:dp|gp/product)/([A-Za-z0-9]{10})#i', $url, $u)) { return null; }
        return [strtolower($u[1]), strtoupper($u[2])];
    }

    /**
     * Tabellen: Lässt sich ein Kurzlink nicht als Produkt auflösen, steht aber in derselben Spalte (z. B. Zeilen darüber) ein Bild-/Textlink
     * mit /dp/ASIN, wird der Kurzlink auf diese Produktadresse gesetzt. Gibt es in der Spalte mehrere verschiedene ASINs, bleibt er unverändert.
     */
    private static function kurzlink_spaltenhilfe(string $c): string {
        if (stripos($c, '<table') === false || !preg_match('#amzn\.to|amzn\.eu|a\.co/#i', $c)) { return $c; }
        return preg_replace_callback('#<table\b.*?</table>#is', function ($t) {
            if (!preg_match_all('#<tr\b.*?</tr>#is', $t[0], $rows)) { return $t[0]; }
            $spalten = [];   // Spaltennummer => [asin|land => true]
            $zellen = [];
            foreach ($rows[0] as $ri => $row) {
                if (!preg_match_all('#<t[dh]\b.*?</t[dh]>#is', $row, $cs)) { continue; }
                foreach ($cs[0] as $ci => $cell) {
                    $zellen[$ri][$ci] = $cell;
                    if (preg_match_all('#href=(["\'])(.*?)\1#is', $cell, $hs)) {
                        foreach ($hs[2] as $h) {
                            if (preg_match('~^https?://(?:www\.)?amazon\.(es|de|fr|it|co\.uk|com)/(?:[^?#]*/)?(?:dp|gp/product)/([A-Za-z0-9]{10})~i', html_entity_decode($h), $m)) { $spalten[$ci][strtoupper($m[2]) . '|' . strtolower($m[1])] = true; }
                        }
                    }
                }
            }
            $out = $t[0];
            foreach ($zellen as $ri => $cs) {
                foreach ($cs as $ci => $cell) {
                    if (empty($spalten[$ci]) || count($spalten[$ci]) !== 1 || !preg_match('#href=(["\'])(https?://(?:amzn\.to|amzn\.eu|a\.co)/[^"\']*)\1#i', $cell, $k)) { continue; }
                    if (self::umbau_url($k[2], true)) { continue; }   // lässt sich selbst auflösen: nichts ändern
                    [$asin, $land] = explode('|', array_key_first($spalten[$ci]));
                    $neu = str_replace($k[2], 'https://www.amazon.' . $land . '/dp/' . $asin, $cell);
                    $out = str_replace($cell, $neu, $out);
                }
            }
            return $out;
        }, $c);
    }

    /** Titel, die der Umbau in [amz_titel] verwandelt hat: ASIN => ursprünglicher Text (wird als Kurzname gespeichert). */
    public static array $neue_namen = [];

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
                    if (!preg_match_all('#href=(["\'])(.*?)\1#is', $cell, $hs)) { continue; }
                    foreach ($hs[2] as $h) {
                        $u = self::umbau_url($h);
                        if ($u) { $spalte[$ci][$u[1] . '|' . $u[0]] = [$u[1], $u[0]]; $bildzeile[$ci] = min($bildzeile[$ci] ?? 99, $ri); }
                    }
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
                            return '<' . $cm[1] . $cm[2] . '>[amz_titel asin="' . $asin . '" land="' . $land . '"]</' . $cm[1] . '>';
                        }
                    }
                    return $cm[0];
                }, $rm[0]);
            }, $t[0]);
        }, $c);
    }

    /** Wandelt feste Amazon-Links eines Textes um. @return array{0:string,1:int,2:int} [neuer Text, umgewandelt, übersprungen] */
    public static function umbau_text(string $c, string $nur = ''): array {
        $fest = [];
        // bereits vorhandene Plugin-Platzhalter schützen
        $c = preg_replace_callback('#\[amz_(bild|produkt|link|ebay)\b[^\]]*\].*?\[/amz_\1\]#is', function ($m) use (&$fest) {
            $k = "\0SAB" . count($fest) . "\0"; $fest[$k] = $m[0]; return $k;
        }, $c);
        $c = self::kurzlink_spaltenhilfe($c);
        $c = self::tabellen_umbau($c, $nur);
        $n = 0; $skip = 0;
        $c = preg_replace_callback('#<a\b([^>]*)>(.*?)</a>#is', function ($m) use (&$n, &$skip, $nur) {
            if (!preg_match('#href=(["\'])(.*?)\1#is', $m[1], $h)) { return $m[0]; }
            $u = self::umbau_url($h[2], true);
            if (!$u) { return $m[0]; }
            [$land, $asin] = $u;
            if ($nur !== '' && $asin !== $nur) { return $m[0]; }
            if (!self::tag($land) || strpos($m[2], '[') !== false) { $skip++; return $m[0]; }
            $cls = '';
            if (preg_match('#class=(["\'])(.*?)\1#is', $m[1], $cl)) { $cls = trim(preg_replace('/[^A-Za-z0-9 _-]/', '', $cl[2])); }
            $attr = ' asin="' . $asin . '" land="' . $land . '"' . ($cls !== '' ? ' class="' . $cls . '"' : '');
            if (stripos($m[2], '<img') !== false) { $n++; return '[amz_bild' . $attr . ']' . $m[0] . '[/amz_bild]'; }
            $text = trim(html_entity_decode(strip_tags($m[2])));
            if ($text === '') { $skip++; return $m[0]; }
            $n++;
            return '[amz_link' . $attr . ']' . str_replace(['[', ']'], '', $text) . '[/amz_link]';
        }, $c);
        return [strtr($c, $fest), $n, $skip];
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
            "SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_status='publish' AND p.post_type IN ('post','page') AND (p.post_content LIKE %s OR p.post_content LIKE %s)
             AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id=p.ID AND m.meta_key='_sab_umbau_geprueft' AND m.meta_value=%s) ORDER BY p.ID LIMIT %d",
            '%amazon.%', '%amzn.%', $nur !== '' ? $nur : 'alle', self::UMBAU_PROSEITE));
        $geaendert = 0; $links = 0; $skip = 0;
        foreach ((array) $ids as $id) {
            $post = get_post((int) $id);
            if (!$post) { continue; }
            [$n, $sk, $gea] = self::umbau_artikel((int) $id, $nur);
            $links += $n; $skip += $sk; $geaendert += $gea;
        }
        delete_transient('sab_alle_asins');
        $rest = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_status='publish' AND p.post_type IN ('post','page') AND (p.post_content LIKE %s OR p.post_content LIKE %s)
             AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id=p.ID AND m.meta_key='_sab_umbau_geprueft' AND m.meta_value=%s)",
            '%amazon.%', '%amzn.%', $nur !== '' ? $nur : 'alle'));
        return count((array) $ids) . ' Artikel geprüft, ' . $geaendert . ' geändert, ' . $links . ' Links umgebaut'
             . ($skip ? ', ' . $skip . ' übersprungen (keine Partner-ID für das Land oder Sonderinhalt)' : '')
             . '. ' . ($rest ? 'Noch ' . $rest . ' Artikel offen: bitte nochmal klicken.' : 'Fertig.');
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
    private static function umbau_artikel(int $id, string $nur = ''): array {
        $post = get_post($id);
        if (!$post) { return [0, 0, 0]; }
        self::$kurz_offen = 0;
        self::$neue_namen = [];
        [$neu, $n, $sk] = self::umbau_text((string) $post->post_content, $nur);
        $gea = 0;
        if ($n > 0 && $neu !== $post->post_content) {
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
        $rows = $wpdb->get_results("SELECT ID, post_title, post_content FROM {$wpdb->posts} WHERE post_status='publish' AND post_type IN ('post','page') AND (post_content LIKE '%amazon.%' OR post_content LIKE '%amzn.%') ORDER BY ID", ARRAY_A);
        foreach ((array) $rows as $r) {
            $c = (string) $r['post_content'];
            $c = preg_replace('#\[amz_(bild|produkt|link)\b[^\]]*\].*?\[/amz_\1\]#is', '', $c);
            $fest = 0; $kurz = 0;
            if (preg_match_all('#<a\b[^>]*href=(["\'])(.*?)\1#is', $c, $m)) {
                foreach ($m[2] as $url) {
                    if (self::umbau_url($url)) { $fest++; } elseif (preg_match('#^https?://(?:amzn\.to|amzn\.eu|a\.co)/#i', html_entity_decode($url))) { $kurz++; }
                }
            }
            $fertig = get_post_meta((int) $r['ID'], '_sab_umbau_backup', true) !== '';
            if ($fest + $kurz > 0 || $fertig) { $out[] = ['id' => (int) $r['ID'], 'titel' => (string) $r['post_title'], 'fest' => $fest, 'kurz' => $kurz, 'fertig' => $fertig]; }
        }
        return $out;
    }

    /** Vorschau: jeder Amazon-Link des Artikels vorher und nachher (ohne etwas zu speichern). */
    private static function umbau_vorschau(int $id): string {
        $post = get_post($id);
        if (!$post) { return '<p>Artikel nicht gefunden.</p>'; }
        $c = (string) $post->post_content;
        $h = '<h3>Vorschau für „' . esc_html($post->post_title) . '“</h3><p class="description">Es wird nichts gespeichert. Links, die hier „bleibt unverändert“ heißen, sind kein Produktlink (z. B. Amazon-Startseite) oder haben keine Partner-ID für das Land.</p><table class="widefat striped"><thead><tr><th>Vorher</th><th>Nachher</th></tr></thead><tbody>';
        $n = 0;
        if (preg_match_all('#<a\b[^>]*>.*?</a>#is', preg_replace('#\[amz_(bild|produkt|link)\b[^\]]*\].*?\[/amz_\1\]#is', '', $c), $m)) {
            foreach ($m[0] as $a) {
                if (!preg_match('#href=(["\'])(.*?)\1#is', $a, $hh) || !preg_match('#amazon\.|amzn\.|a\.co/#i', $hh[2])) { continue; }
                [$neu] = self::umbau_text($a);
                $n++;
                $h .= '<tr><td><code style="word-break:break-all">' . esc_html(mb_substr($a, 0, 300)) . '</code></td><td>' . ($neu === $a ? '<em>bleibt unverändert</em>' : '<code style="word-break:break-all">' . esc_html(mb_substr($neu, 0, 300)) . '</code>') . '</td></tr>';
            }
        }
        return $h . ($n ? '' : '<tr><td colspan="2">Keine Amazon-Links gefunden.</td></tr>') . '</tbody></table>';
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
        'ausfaelle'     => 'Ausfälle und Warnungen',
        'statistik'     => 'Statistik',
        'problem'       => 'Problemprodukte',
        'gruppen'       => 'Produktgruppen',
        'umbau'         => 'Umbau',
        'vorschau'      => 'Vorschau',
        'tests'         => 'A/B-Tests',
        'einstellungen' => 'Einstellungen',
        'hilfe'         => 'Hilfe',
    ];

    /** Eigener Punkt "1a Amazon Box" in der Seitenleiste des Backends, mit einem Untermenü je Reiter. */
    public static function menue(): void {
        add_menu_page('1a Amazon Box', '1a Amazon Box', 'manage_options', 'sab', [__CLASS__, 'seite'], 'dashicons-cart', 58);
        foreach (self::TABS as $k => $name) {
            add_submenu_page('sab', $name . ' - 1a Amazon Box', $name, 'manage_options', $k === 'uebersicht' ? 'sab' : 'sab-' . $k, [__CLASS__, 'seite']);
        }
    }

    /** Vorschau mit echten Daten: jeder Slot und jedes Produkt als Karte und als Bild, so wie die Besucher es sehen. */
    private static function vorschau_html(): string {
        $h = '<p>So sehen Besucher die Karten (echte Bilder und Texte aus dem Zwischenspeicher). Läuft ein A/B-Test, zeigt die Vorschau die Variante, die dieser Seite zugeteilt wäre.</p>';
        if (get_option('sab_karten_css') !== '0') { $h .= '<style>' . self::KARTEN_CSS . '</style>'; }
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
        if (!isset($tabs[$tab])) { $tab = 'uebersicht'; }

        $meldung = '';
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
                update_option('sab_default_land', sanitize_key(wp_unslash($_POST['sab_default_land'] ?? 'de')), false);
                update_option('sab_hinweis_auto', !empty($_POST['sab_hinweis_auto']) ? '1' : '0', false);
                update_option('sab_karten_css', !empty($_POST['sab_karten_css']) ? '1' : '0', false);
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
                    $regeln[$alt] = $nw; $neu[] = $alt . ' => ' . $nw;
                }
                if ($neu) {
                    $zeilen = [];
                    foreach ($regeln as $a => $b) { $zeilen[] = $a . ' => ' . $b; }
                    update_option('sab_tausch', implode("\n", $zeilen), false);
                    delete_transient('sab_alle_asins');
                }
                $meldung = $neu ? count($neu) . ' Austausch-Regel(n) angelegt: ' . implode(', ', $neu) . '.' : 'Nichts eingetragen.';
                if ($fehler) { $meldung .= ' Ungültig (nicht 10 Zeichen oder gleich): ' . implode(', ', $fehler) . '.'; }
            } elseif ($form === 'gruppen') {
                update_option('sab_gruppen', sanitize_textarea_field(wp_unslash($_POST['sab_gruppen'] ?? '')), false);
                delete_transient('sab_alle_asins');
                $meldung = 'Gruppen gespeichert.';
            } elseif ($form === 'marken' && !empty($_POST['sab_markieren'])) {
                $m = [];
                foreach ((array) ($_POST['sab_fav'] ?? []) as $a => $x) { $a = self::asin((string) $a); if ($a) { $m[$a] = 'fav'; } }
                foreach ((array) ($_POST['sab_ers'] ?? []) as $a => $x) { $a = self::asin((string) $a); if ($a) { $m[$a] = 'ers'; } } // „Ersetzen“ gewinnt
                update_option('sab_marken', $m, false);
                $tr = [];
                foreach ((array) ($_POST['sab_trotz'] ?? []) as $a => $x) { $a = self::asin((string) $a); if ($a) { $tr[$a] = 1; } }
                update_option('sab_trotz', $tr, false);
                $meldung = 'Markierungen gespeichert.';
            } elseif ($form === 'umbau' && !empty($_POST['sab_bestaetigt'])) {
                $meldung = self::umbau_lauf(self::asin((string) ($_POST['sab_nur'] ?? '')));
            } elseif ($form === 'umbau_einzeln') {
                [$n, $sk, $gea] = self::umbau_artikel((int) ($_POST['sab_id'] ?? 0));
                $meldung = $gea ? $n . ' Link(s) in diesem Artikel umgebaut. Bitte den Artikel ansehen; Rückgängig geht in der Liste.' : 'Nichts umgebaut' . ($sk ? ' (' . $sk . ' übersprungen: keine Partner-ID für das Land oder Sonderinhalt)' : '') . '.';
            } elseif ($form === 'umbau_zurueck_einzeln') {
                $meldung = self::umbau_zurueck_artikel((int) ($_POST['sab_id'] ?? 0));
            } elseif ($form === 'umbau_zurueck' && !empty($_POST['sab_bestaetigt'])) {
                $meldung = self::umbau_zurueck();
            } elseif ($form === 'jetzt') {
                self::pruefen();
                $meldung = 'Prüfung durchgeführt.';
            }
        }

        echo '<div class="wrap"><h1>1a Amazon Box <small style="font-size:12px;color:#666">Version ' . esc_html(self::VERSION) . '</small></h1>';
        if ($meldung) { echo '<div class="notice notice-success"><p>' . esc_html($meldung) . '</p></div>'; }
        echo '<h2 class="nav-tab-wrapper">';
        foreach ($tabs as $k => $name) {
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
        } elseif ($tab === 'ausfaelle') {
            echo '<form method="post">'; wp_nonce_field('sab_speichern');
            echo '<input type="hidden" name="sab_tab" value="ausfaelle"><input type="hidden" name="sab_form" value="jetzt"><p><button class="button">Jetzt alle prüfen</button></p></form>';
            $ers = array_keys(array_filter((array) get_option('sab_marken', []), fn($t) => $t === 'ers'));
            if ($ers) {
                $namen = self::produkte();
                echo '<h2>Bitte austauschen (von dir markiert)</h2><ul>';
                foreach ($ers as $a) { echo '<li><code>' . esc_html($a) . '</code> ' . esc_html($namen[$a] ?? '') . '</li>'; }
                echo '</ul><p class="description">Trage unter „Produkte und Ersatzlisten“ eine Austausch-Regel ein, dann gilt der Ersatz überall.</p>';
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
            $liste = get_option('sab_ausfall', []);
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
        } elseif ($tab === 'statistik') {
            echo self::statistik_html();
        } elseif ($tab === 'gruppen') {
            echo self::gruppen_html();
        } elseif ($tab === 'problem') {
            echo self::problem_html();
        } elseif ($tab === 'umbau') {
            $liste = self::umbau_artikelliste(); $nf = 0; $nk = 0; $nfertig = 0;
            foreach ($liste as $a) { $nf += $a['fest']; $nk += $a['kurz']; $nfertig += $a['fertig'] ? 1 : 0; }
            echo '<h2>Umbau: feste Amazon-Links durch Plugin-Platzhalter ersetzen</h2>'
               . '<ol style="max-width:760px"><li><strong>Vorschau ansehen:</strong> Pro Artikel siehst du, welche Links wie umgebaut würden. Es wird nichts gespeichert.</li>'
               . '<li><strong>Umbauen:</strong> Der Artikel wird gesichert (Original in der Sicherung, dazu eine WordPress-Revision) und die Links werden zu <code>[amz_bild]</code> / <code>[amz_link]</code>. Der alte Code bleibt als Rückfall zwischen den Tags.</li>'
               . '<li><strong>Danach</strong> lassen sich Produkte seitenweit tauschen (Reiter „Problemprodukte“), und der Artikel zählt für Statistik und A/B-Tests. <strong>Rückgängig</strong> geht pro Artikel.</li></ol>'
               . '<p>Tipp: Zuerst <strong>einen einzelnen Artikel</strong> umbauen und im Browser ansehen. Wenn alles stimmt, unten „Alle auf einmal“.</p>'
               . '<p>Gefunden: <strong>' . count($liste) . '</strong> Artikel mit Amazon-Links, davon <strong>' . $nfertig . '</strong> schon umgebaut; <strong>' . $nf . '</strong> feste Produkt-Links und <strong>' . $nk . '</strong> Kurzlinks (amzn.to) offen.</p>';
            $vid = (int) ($_GET['vorschau'] ?? 0);
            if ($vid) { echo self::umbau_vorschau($vid); }
            if ($liste) {
                echo '<table class="widefat striped" style="margin-top:1em"><thead><tr><th>Artikel</th><th>feste Links</th><th>Kurzlinks</th><th>Stand</th><th>Aktion</th></tr></thead><tbody>';
                foreach (array_slice($liste, 0, 200) as $a) {
                    $vu = add_query_arg(['page' => 'sab-umbau', 'vorschau' => $a['id']], admin_url('admin.php'));
                    echo '<tr><td><a href="' . esc_url((string) get_permalink($a['id'])) . '" target="_blank" rel="noopener">' . esc_html($a['titel'] ?: ('Beitrag ' . $a['id'])) . '</a></td><td>' . (int) $a['fest'] . '</td><td>' . (int) $a['kurz'] . '</td>'
                       . '<td>' . ($a['fertig'] ? '<strong style="color:#1a7f37">umgebaut</strong>' : 'offen') . '</td><td><a class="button" href="' . esc_url($vu) . '">Vorschau</a> ';
                    echo '<form method="post" style="display:inline">'; wp_nonce_field('sab_speichern');
                    echo '<input type="hidden" name="sab_tab" value="umbau"><input type="hidden" name="sab_id" value="' . (int) $a['id'] . '">'
                       . ($a['fertig'] ? '<input type="hidden" name="sab_form" value="umbau_zurueck_einzeln"><button class="button">Rückgängig</button>' : '<input type="hidden" name="sab_form" value="umbau_einzeln"><button class="button button-primary">Umbauen</button>')
                       . '</form></td></tr>';
                }
                echo '</tbody></table>' . (count($liste) > 200 ? '<p class="description">Es werden die ersten 200 Artikel gezeigt. „Alle auf einmal“ unten erfasst alle.</p>' : '');
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
               . '<tr><th>Pflichthinweis</th><td><label><input type="checkbox" name="sab_hinweis_auto" value="1"' . (get_option('sab_hinweis_auto') === '1' ? ' checked' : '')
               . '> automatisch im Fuß jeder Seite mit Partnerlinks</label><br><input type="text" name="sab_hinweis_text" class="large-text" placeholder="leer = Standardsatz je Land" value="'
               . esc_attr((string) get_option('sab_hinweis_text', '')) . '"></td></tr>'
               . '<tr><th>Karten-Design</th><td><label><input type="checkbox" name="sab_karten_css" value="1"' . (get_option('sab_karten_css') !== '0' ? ' checked' : '')
               . '> kleines Karten-Design für <code>[amz_produkt]</code> laden (weißer Kasten, grüner Button)</label></td></tr>'
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
               . '<li><strong>Produkt tauschen, wenn es nicht mehr lieferbar ist:</strong> Reiter „Produkte und Ersatzlisten“, Zeile bei Austausch-Regeln eintragen. Gilt sofort überall.</li>'
               . '<li><strong>Favorit / Ersetzen:</strong> Kästchen in der Übersicht. Favoriten werden in Ersatzlisten bevorzugt, „Ersetzen“ rutscht ans Ende und steht unter „Ausfälle und Warnungen“.</li>'
               . '<li><strong>Umbau:</strong> Reiter „Umbau“ wandelt feste Amazon-Links in Artikeln in Plugin-Platzhalter um (mit Sicherung und Rückgängig).</li>'
               . '<li><strong>A/B-Test:</strong> Reiter „A/B-Tests“. Jeder Artikel bekommt fest eine Variante, Klicks werden je Variante gezählt.</li>'
               . '<li>Bilder, Links und Titel werden höchstens 1 Tag zwischengespeichert. Alle Amazon-Links tragen <code>rel="sponsored nofollow noopener"</code>. Die Klickmessung nutzt keine Cookies und keine IP.</li>'
               . '</ul>';
        }
        echo '</div>';
    }
}
SAB::init();
