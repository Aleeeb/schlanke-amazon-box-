<?php
/**
 * Plugin Name: Amazon-Box schlank
 * Description: Kleiner Ersatz für AAWP. Zeigt Amazon-Produktbilder, Namen und Textlinks über die Creators API. Bilder kommen vom eigenen Server. Erkennt ausgefallene Produkte und setzt den nächsten Ersatz aus einer Liste ein.
 * Version: 0.19.1
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) { exit; }

/*
 * Zugangsdaten: am einfachsten unter Werkzeuge > Schlanke Amazon-Box eintragen.
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
 * Einstellungen: Werkzeuge > Schlanke Amazon-Box (Produkte mit Kurznamen, Ersatzlisten, Ausfälle).
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

    const VERSION = '0.19.1';
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
        add_shortcode('amz_hinweis', [__CLASS__, 'sc_hinweis']);
        add_shortcode('aawp',        [__CLASS__, 'sc_aawp']);
        add_action('sab_pruefen',    [__CLASS__, 'pruefen']);
        add_action('admin_menu',     [__CLASS__, 'menue']);
        add_action('rest_api_init',  [__CLASS__, 'rest']);
        add_action('wp_footer',      [__CLASS__, 'messskript']);
        add_action('wp_footer',      [__CLASS__, 'fuss'], 5);
        add_filter('pre_set_site_transient_update_plugins', [__CLASS__, 'update_pruefen']);
        add_action('admin_init',     [__CLASS__, 'tabelle']);
        if (!wp_next_scheduled('sab_pruefen')) { wp_schedule_event(time() + 300, 'daily', 'sab_pruefen'); }
    }

    // ---------- Zugang ----------

    // Zugangsdaten: wp-config.php hat Vorrang, sonst die Eingabemaske unter Werkzeuge > Schlanke Amazon-Box

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
    private static function produkt(string $asin, string $land, bool $frisch = false): ?array {
        if (!$asin) { return null; }
        if ($frisch) { delete_transient("sab_{$land}_{$asin}"); }
        $c = get_transient("sab_{$land}_{$asin}");
        if ($c) { return $c; }

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

    private static function abrufen(string $asin, string $land, array $alt): ?array {
        $token = self::token($land);
        $tag   = self::tag($land);
        if (!$token || !$tag) { return null; }
        $mp = self::LAENDER[$land][0];

        $r = wp_remote_post(self::API, [
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
            'verfuegbar' => !empty($it['offersV2']['listings']) && (get_option('sab_strikt') !== '1' || (string) ($l['availability']['type'] ?? '') !== 'OUT_OF_STOCK'),
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

    /** Angebot mit Kaufen-Button, sonst das erste mit Preis, sonst das erste. */
    private static function bestes_angebot(array $it): ?array {
        $ls = (array) ($it['offersV2']['listings'] ?? []);
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
        foreach (array_unique($alle) as $asin) {
            $p = self::produkt($asin, $land, true);
            if (!$p) { continue; } // Amazon nicht erreichbar: nichts ändern
            if (self::brauchbar($p)) { self::ausfall_loeschen($asin); } else { self::ausfall_setzen($asin, $land, $p); }
        }
        self::wochenmail();
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
        if (wp_mail($an, 'Schlanke Amazon-Box: Wochenbericht', $text)) { update_option('sab_mail_zuletzt', time(), false); }
    }

    private static function wochenmail_text(array $klicks, array $ausfall, array $warn): string {
        $namen = self::produkte();
        $z = ['Schlanke Amazon-Box: die letzten 7 Tage', ''];
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

    private static function bild_url(array $p, string $asin, string $land, int $idx = 0): string {
        $quelle = $idx > 0 ? ($p['bilder'][$idx] ?? '') : ($p['bild'] ?? '');
        if (!$quelle) { return ''; }
        $up   = wp_upload_dir();
        $dir  = $up['basedir'] . '/sab';
        $name = "{$land}-{$asin}" . ($idx > 0 ? "-{$idx}" : '') . '.jpg';
        $file = "$dir/$name";
        $alter = file_exists($file) ? time() - filemtime($file) : PHP_INT_MAX;

        if ($alter > self::TTL) {
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
        $m = get_transient('sab_update_json');
        if ($m === false) {
            $r = wp_remote_get(self::UPDATE_JSON, ['timeout' => 8]);
            $m = (!is_wp_error($r) && wp_remote_retrieve_response_code($r) === 200) ? json_decode((string) wp_remote_retrieve_body($r), true) : [];
            set_transient('sab_update_json', is_array($m) ? $m : [], 6 * HOUR_IN_SECONDS);
        }
        $v = (string) ($m['version'] ?? ''); $zip = (string) ($m['zip'] ?? '');
        if ($v === '' || strpos($zip, self::UPDATE_REPO) !== 0 || version_compare($v, self::VERSION, '<=')) { return $t; }
        $key = plugin_basename(__FILE__);
        $t->response[$key] = (object) ['slug' => 'schlanke-amazon-box', 'plugin' => $key, 'new_version' => $v, 'package' => $zip, 'url' => self::UPDATE_REPO];
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
                echo '<div style="position:fixed;left:8px;bottom:8px;z-index:99999;background:#fff3cd;border:1px solid #d4a017;color:#000;padding:6px 10px;font:13px sans-serif">Schlanke Amazon-Box: ' . (int) $n . ' Partnerlinks auf dieser Seite (Warnung ab ' . self::LINK_WARNUNG . ')</div>';
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
     *   [aawp fields="ASIN" value="thumb|image"]  => Bild      (image_size wird ignoriert)
     *   [aawp fields="ASIN" value="title"]        => Titel als Link;  value="button" => Button-Link
     *   [aawp box="ASIN"]                          => Karte
     *   [aawp link="ASIN"]Text[/aawp]             => Textlink
     * Mehrere ASINs mit Komma. Alles andere (Preis, Bewertung, Tabellen usw.) bleibt absichtlich leer.
     * Tippfehler wie ein überzähliges Zeichen am Ende der ASIN werden abgeschnitten.
     */
    public static function sc_aawp($a, $inhalt = ''): string {
        $a = shortcode_atts(['fields' => '', 'box' => '', 'link' => '', 'value' => 'thumb', 'image_size' => '', 'class' => '', 'land' => '', 'title' => ''], (array) $a);
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
                    $out .= self::sc_bild(['asin' => $asin, 'land' => $a['land'], 'class' => $a['class']], '');
                } elseif ($v === 'title') {
                    $out .= self::sc_link(['asin' => $asin, 'land' => $a['land'], 'class' => $a['class']], '');
                } elseif ($v === 'button') {
                    $out .= self::sc_link(['asin' => $asin, 'land' => $a['land'], 'class' => 'sab-text'], self::BUTTON_TEXT[self::land($a['land'])] ?? self::BUTTON_TEXT['de']);
                }
            }
        }
        return $out;
    }

    public static function sc_link($a, $inhalt = ''): string {
        $a = shortcode_atts(['asin' => '', 'land' => '', 'class' => ''], $a);
        $asin = self::getauscht(self::asin($a['asin']));
        $p = self::produkt($asin, self::land($a['land']));
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
        global $wpdb;
        if (isset($wpdb)) {
            $rows = $wpdb->get_col("SELECT post_content FROM {$wpdb->posts} WHERE post_status='publish' AND post_type IN ('post','page') AND post_content LIKE '%[amz\\_%'");
            foreach ((array) $rows as $c) {
                if (preg_match_all('/\[amz_(?:bild|link)[^\]]*asin="([A-Za-z0-9]{10})"/', $c, $m)) { $alle = array_merge($alle, array_map([__CLASS__, 'asin'], $m[1])); }
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
            $r = wp_remote_post(self::API, [
                'timeout' => 15,
                'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json', 'x-marketplace' => $mp],
                'body'    => wp_json_encode([
                    'itemIds' => $paket, 'itemIdType' => 'ASIN', 'marketplace' => $mp, 'partnerTag' => $tag,
                    'resources' => ['images.primary.large', 'images.variants.large', 'itemInfo.title',
                        'offersV2.listings.availability', 'offersV2.listings.price', 'offersV2.listings.isBuyBoxWinner',
                        'offersV2.listings.merchantInfo'],
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
                    'kaufen'   => $l ? (isset($l['isBuyBoxWinner']) ? (bool) $l['isBuyBoxWinner'] : null) : false,
                    'preis'    => self::preis_von($l),
                    'verkaeufer' => self::verkaeufer_von($l),
                    'diag'     => self::diagnose($it),
                    'roh'      => mb_substr((string) wp_json_encode($it['offersV2'] ?? null), 0, 1800),
                ];
            }
        }
        // Bewertungen in einer eigenen Abfrage: ein Fehler hier darf die Übersicht nicht stören
        $bew = ['fehler' => '', 'werte' => []];
        if (!$fehler && $items) {
            foreach (array_chunk(array_keys($items), 10) as $paket) {
                $r = wp_remote_post(self::API, [
                    'timeout' => 15,
                    'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json', 'x-marketplace' => $mp],
                    'body'    => wp_json_encode(['itemIds' => $paket, 'itemIdType' => 'ASIN', 'marketplace' => $mp, 'partnerTag' => $tag,
                        'resources' => ['customerReviews.count', 'customerReviews.starRating']]),
                ]);
                if (is_wp_error($r) || wp_remote_retrieve_response_code($r) !== 200) {
                    $bew['fehler'] = 'Bewertungen: Amazon antwortete mit Code ' . (is_wp_error($r) ? 'Netz' : wp_remote_retrieve_response_code($r))
                                   . ': ' . (is_wp_error($r) ? '' : mb_substr((string) wp_remote_retrieve_body($r), 0, 400));
                    break;
                }
                $j = json_decode(wp_remote_retrieve_body($r), true);
                foreach ((array) ($j['itemsResult']['items'] ?? []) as $it) {
                    $cr = $it['customerReviews'] ?? [];
                    $stern = $cr['starRating']['value'] ?? ($cr['starRating'] ?? null);
                    $bew['werte'][self::asin($it['asin'] ?? '')] = ['sterne' => is_array($stern) ? null : $stern, 'anzahl' => $cr['count'] ?? null];
                }
            }
        }
        $out = ['fehler' => $fehler, 'items' => $items, 'bewertungen' => $bew];
        if (!$fehler) { set_transient($key, $out, HOUR_IN_SECONDS); }
        return $out;
    }

    private static function markenzellen(string $asin): string {
        $m = (array) get_option('sab_marken', []); $t = $m[$asin] ?? '';
        return '<td><input type="checkbox" name="sab_fav[' . esc_attr($asin) . ']" value="1"' . ($t === 'fav' ? ' checked' : '') . '></td>'
             . '<td><input type="checkbox" name="sab_ers[' . esc_attr($asin) . ']" value="1"' . ($t === 'ers' ? ' checked' : '') . '></td>';
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
        $namen = self::produkte(); $ja = fn($v) => $v === null ? '?' : ($v ? 'ja' : 'nein');
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
        $h .= '<table class="widefat striped"><thead><tr><th>Bilder</th><th>ASIN / Name</th><th>Favorit</th><th>Ersetzen</th><th>Lieferbar</th><th>Kaufen-Button</th><th>Preis</th><th>Verkäufer</th><th>Bewertung</th><th>Klicks (Bild/Text)</th><th>kamen zurück</th><th>Ø Zeit weg</th></tr></thead><tbody>';
        foreach (self::alle_asins() as $asin) {
            $x = $d['items'][$asin] ?? null;
            if (!$x) { $h .= '<tr><td>–</td><td>' . esc_html($asin) . '<br>' . esc_html($namen[$asin] ?? '') . '</td>' . self::markenzellen($asin) . '<td colspan="8"><strong>Amazon kennt das Produkt nicht (mehr)</strong></td></tr>'; continue; }
            $b = '<div style="display:grid;grid-template-columns:repeat(2,minmax(0,64px));gap:4px;width:136px">';
            foreach ($x['bilder'] as $k => $u) { $b .= '<a href="' . esc_url($dp . $asin) . '" target="_blank" rel="noopener" title="Bild ' . ($k + 1) . '" style="display:inline-block;text-align:center;font-size:11px"><img src="' . esc_url($u) . '" alt="" style="width:60px;height:60px;object-fit:contain;margin:0 4px 4px 0;background:#fff" referrerpolicy="no-referrer"><br>' . ($k + 1) . '</a>'; }
            $b .= '</div>';
            $bw = $d['bewertungen']['werte'][$asin] ?? null;
            $bewzelle = $bw && $bw['anzahl'] !== null ? esc_html(($bw['sterne'] !== null ? $bw['sterne'] . ' ★ ' : '') . '(' . $bw['anzahl'] . ')') : '–';
            $h .= '<tr><td style="width:150px">' . $b . '</td><td><a href="' . esc_url($dp . $asin) . '" target="_blank" rel="noopener"><code>' . esc_html($asin) . '</code></a><br><strong>' . esc_html($namen[$asin] ?? '') . '</strong><br><small>'
               . '<a href="' . esc_url($dp . $asin) . '" target="_blank" rel="noopener">' . esc_html(mb_substr($x['titel'], 0, 90)) . '</a></small></td>'
               . self::markenzellen($asin) . '<td>' . (($x['status'] ?? '') === 'OUT_OF_STOCK' ? '<strong>laut API: nein</strong><br><small>Amazon prüft für einen Standard-Lieferort. Auf Amazon mit Lieferadresse Spanien kann es trotzdem lieferbar sein.</small>' : ($x['lieferbar'] ? 'ja' : '<strong>nein</strong>')) . '</td><td>' . $ja($x['kaufen']) . '</td><td>' . esc_html($x['preis'] ?: '–')
               . ($x['preis'] === '' || $x['verkaeufer'] === '' ? '<br><small style="color:#a00">' . esc_html($x['diag'] ?? '') . '</small><details><summary><small>Rohdaten</small></summary><code style="font-size:10px;word-break:break-all">' . esc_html($x['roh'] ?? '') . '</code></details>' : '') . '</td>'
               . '<td>' . esc_html($x['verkaeufer'] ?: '–') . '</td><td>' . $bewzelle . '</td>'
               . self::klickzellen($kz[$asin] ?? null) . '</tr>';
        }
        return $h . '</tbody></table><p><button class="button button-primary" name="sab_markieren" value="1">Markierungen speichern</button> <span class="description">Favorit: wird in Ersatzlisten bevorzugt. Ersetzen: rutscht ans Ende und steht unter „Ausfälle und Warnungen“.</span></p>';
    }

    // ---------- Umbau-Werkzeug: feste Amazon-Links in Artikeln => Plugin-Platzhalter ----------

    const UMBAU_PROSEITE = 15; // Artikel pro Durchgang (kurz genug gegen Zeitlimits)

    private static function umbau_url(string $url): ?array {
        $url = html_entity_decode($url);
        if (!preg_match('#^https?://(?:www\.)?amazon\.(es|de|fr|it|co\.uk|com)/(?:[^?\#]*/)?(?:dp|gp/product)/([A-Za-z0-9]{10})#i', $url, $u)) { return null; }
        return [strtolower($u[1]), strtoupper($u[2])];
    }

    /** Wandelt feste Amazon-Links eines Textes um. @return array{0:string,1:int,2:int} [neuer Text, umgewandelt, übersprungen] */
    public static function umbau_text(string $c, string $nur = ''): array {
        $fest = [];
        // bereits vorhandene Plugin-Platzhalter schützen
        $c = preg_replace_callback('#\[amz_(bild|produkt|link)\b[^\]]*\].*?\[/amz_\1\]#is', function ($m) use (&$fest) {
            $k = "\0SAB" . count($fest) . "\0"; $fest[$k] = $m[0]; return $k;
        }, $c);
        $n = 0; $skip = 0;
        $c = preg_replace_callback('#<a\b([^>]*)>(.*?)</a>#is', function ($m) use (&$n, &$skip, $nur) {
            if (!preg_match('#href=(["\'])(.*?)\1#is', $m[1], $h)) { return $m[0]; }
            $u = self::umbau_url($h[2]);
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
            "SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_status='publish' AND p.post_type IN ('post','page') AND p.post_content LIKE %s
             AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id=p.ID AND m.meta_key='_sab_umbau_geprueft' AND m.meta_value=%s) ORDER BY p.ID LIMIT %d",
            '%amazon.%', $nur !== '' ? $nur : 'alle', self::UMBAU_PROSEITE));
        $geaendert = 0; $links = 0; $skip = 0;
        foreach ((array) $ids as $id) {
            $post = get_post((int) $id);
            if (!$post) { continue; }
            [$neu, $n, $sk] = self::umbau_text((string) $post->post_content, $nur);
            $links += $n; $skip += $sk;
            if ($n > 0 && $neu !== $post->post_content) {
                if (get_post_meta($id, '_sab_umbau_backup', true) === '') { update_post_meta($id, '_sab_umbau_backup', wp_slash($post->post_content)); }
                wp_update_post(['ID' => (int) $id, 'post_content' => wp_slash($neu)]);
                $geaendert++;
            }
            update_post_meta($id, '_sab_umbau_geprueft', $nur !== '' ? $nur : 'alle');
        }
        delete_transient('sab_alle_asins');
        $rest = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_status='publish' AND p.post_type IN ('post','page') AND p.post_content LIKE %s
             AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id=p.ID AND m.meta_key='_sab_umbau_geprueft' AND m.meta_value=%s)",
            '%amazon.%', $nur !== '' ? $nur : 'alle'));
        return count((array) $ids) . ' Artikel geprüft, ' . $geaendert . ' geändert, ' . $links . ' Links umgebaut'
             . ($skip ? ', ' . $skip . ' übersprungen (keine Partner-ID für das Land oder Sonderinhalt)' : '')
             . '. ' . ($rest ? 'Noch ' . $rest . ' Artikel offen: bitte nochmal klicken.' : 'Fertig.');
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

    public static function menue(): void {
        add_management_page('Schlanke Amazon-Box', 'Schlanke Amazon-Box', 'manage_options', 'sab', [__CLASS__, 'seite']);
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
        $tabs = [
            'uebersicht'    => 'Übersicht',
            'produkte'      => 'Produkte und Ersatzlisten',
            'ausfaelle'     => 'Ausfälle und Warnungen',
            'umbau'         => 'Umbau',
            'vorschau'      => 'Vorschau',
            'tests'         => 'A/B-Tests',
            'einstellungen' => 'Einstellungen',
            'hilfe'         => 'Hilfe',
        ];
        $tab = sanitize_key((string) ($_POST['sab_tab'] ?? ($_GET['tab'] ?? 'uebersicht')));
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
            } elseif ($form === 'marken' && !empty($_POST['sab_markieren'])) {
                $m = [];
                foreach ((array) ($_POST['sab_fav'] ?? []) as $a => $x) { $a = self::asin((string) $a); if ($a) { $m[$a] = 'fav'; } }
                foreach ((array) ($_POST['sab_ers'] ?? []) as $a => $x) { $a = self::asin((string) $a); if ($a) { $m[$a] = 'ers'; } } // „Ersetzen“ gewinnt
                update_option('sab_marken', $m, false);
                $meldung = 'Markierungen gespeichert.';
            } elseif ($form === 'umbau' && !empty($_POST['sab_bestaetigt'])) {
                $meldung = self::umbau_lauf(self::asin((string) ($_POST['sab_nur'] ?? '')));
            } elseif ($form === 'umbau_zurueck' && !empty($_POST['sab_bestaetigt'])) {
                $meldung = self::umbau_zurueck();
            } elseif ($form === 'jetzt') {
                self::pruefen();
                $meldung = 'Prüfung durchgeführt.';
            }
        }

        echo '<div class="wrap"><h1>Schlanke Amazon-Box <small style="font-size:12px;color:#666">Version ' . esc_html(self::VERSION) . '</small></h1>';
        if ($meldung) { echo '<div class="notice notice-success"><p>' . esc_html($meldung) . '</p></div>'; }
        echo '<h2 class="nav-tab-wrapper">';
        foreach ($tabs as $k => $name) {
            echo '<a class="nav-tab' . ($k === $tab ? ' nav-tab-active' : '') . '" href="' . esc_url('tools.php?page=sab&tab=' . $k) . '">' . esc_html($name) . '</a>';
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
        } elseif ($tab === 'umbau') {
            $an = self::umbau_analyse(); $namen = self::produkte();
            echo '<h2>Umbau: feste Amazon-Links in Artikeln durch Plugin-Platzhalter ersetzen</h2>'
               . '<p>Danach lassen sich Produkte zentral tauschen (Austausch-Regeln), testen und prüfen. Bild-Links werden zu <code>[amz_bild]</code>, Textlinks und Buttons zu <code>[amz_link]</code>; der bisherige Code bleibt als Rückfall zwischen den Tags. Vorher wird jeder Artikel einzeln gesichert, und WordPress legt zusätzlich eine Revision an. <strong>Kurzlinks (amzn.to) können nicht umgebaut werden.</strong></p>'
               . '<p>Gefunden: <strong>' . (int) $an['artikel'] . '</strong> Artikel mit festen Amazon-Links, <strong>' . (int) $an['fertig'] . '</strong> Stellen laufen schon über das Plugin, <strong>' . (int) $an['kurz'] . '</strong> Kurzlinks (amzn.to).</p>';
            if ($an['asins']) {
                echo '<table class="widefat striped"><thead><tr><th>ASIN</th><th>Name</th><th>Land</th><th>Stellen</th><th>Artikel</th></tr></thead><tbody>';
                foreach ($an['asins'] as $r) {
                    echo '<tr><td><a href="' . esc_url('https://' . self::LAENDER[$r['land']][0] . '/dp/' . $r['asin']) . '" target="_blank" rel="noopener"><code>' . esc_html($r['asin']) . '</code></a></td><td>' . esc_html($namen[$r['asin']] ?? '')
                       . '</td><td>' . esc_html($r['land']) . '</td><td>' . (int) $r['stellen'] . '</td><td>' . count($r['artikel']) . '</td></tr>';
                }
                echo '</tbody></table>';
            }
            echo '<form method="post" style="margin-top:1.5em">'; wp_nonce_field('sab_speichern');
            echo '<input type="hidden" name="sab_tab" value="umbau"><input type="hidden" name="sab_form" value="umbau">'
               . '<p>Nur diese ASIN umbauen (leer = alle): <input type="text" name="sab_nur" size="14"></p>'
               . '<p><label><input type="checkbox" name="sab_bestaetigt" value="1"> Ja, die nächsten ' . self::UMBAU_PROSEITE . ' Artikel jetzt umbauen</label></p>'
               . '<p><button class="button button-primary">Umbauen (' . self::UMBAU_PROSEITE . ' Artikel pro Klick)</button></p></form>';
            echo '<form method="post">'; wp_nonce_field('sab_speichern');
            echo '<input type="hidden" name="sab_tab" value="umbau"><input type="hidden" name="sab_form" value="umbau_zurueck">'
               . '<p><label><input type="checkbox" name="sab_bestaetigt" value="1"> Ja, alles auf den Zustand vor dem Umbau zurücksetzen</label> <button class="button">Rückgängig</button></p></form>';
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
               . '<tr><th>Wochenbericht</th><td><input type="email" name="sab_mail_an" class="regular-text" placeholder="leer = keine Mail" value="' . esc_attr((string) get_option('sab_mail_an', '')) . '"><p class="description">Einmal pro Woche eine Mail: Klicks, Ausfälle, Seiten mit vielen Partnerlinks.</p></td></tr>'
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
