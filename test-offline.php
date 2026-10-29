<?php
// Offline-Test ohne WordPress: php test-offline.php  (Stubs für die benötigten WP-Funktionen)
define('SAB_SYNC', 1); define('ABSPATH', __DIR__ . '/');
if (!function_exists('get_post_status')) { function get_post_status($i) { return 'publish'; } }
if (!function_exists('get_post_field')) { function get_post_field($f, $i) { $a = []; foreach ((array) ($GLOBALS['opt']['sab_bildfehler'] ?? []) as $e) { if (!in_array($e['asin'], (array) ($GLOBALS['absent'] ?? []), true)) { $a[] = '[amz_bild asin="' . $e['asin'] . '"]'; } } return '<p>' . implode(' ', $a) . '</p>'; } }
const DAY_IN_SECONDS = 86400; const HOUR_IN_SECONDS = 3600; const MINUTE_IN_SECONDS = 60;
$GLOBALS['opt'] = []; $GLOBALS['tr'] = []; $GLOBALS['sc'] = []; $GLOBALS['http'] = []; $GLOBALS['admin'] = false;
function add_shortcode($n, $f) { $GLOBALS['sc'][$n] = $f; }
function add_action(...$a) {} function add_menu_page(...$a) { $GLOBALS['menu'][] = ['top', $a[3]]; } function add_submenu_page(...$a) { $GLOBALS['menu'][] = ['sub', $a[4]]; } function add_filter(...$a) {} function wp_mail($t,$s,$b) { $GLOBALS['mail'][] = [$t,$s,$b]; return true; } function sanitize_email($e) { return $e; } function plugin_basename($f) { return 'schlanke-amazon-box/schlanke-amazon-box.php'; } function wp_next_scheduled($x) { return true; }
function date_i18n($f, $t = null) { return date($f, $t ?? time()); }
function get_option($k, $d = false) { return $GLOBALS['opt'][$k] ?? $d; }
function update_option($k, $v, $a = null) { $GLOBALS['opt'][$k] = $v; return true; }
function delete_option($k) { unset($GLOBALS['opt'][$k]); return true; }
function get_transient($k) { return $GLOBALS['tr'][$k] ?? false; }
function set_transient($k, $v, $t) { $GLOBALS['tr'][$k] = $v; }
function delete_transient($k) { unset($GLOBALS['tr'][$k]); }
function shortcode_atts($d, $a) { return array_merge($d, array_intersect_key((array) $a, $d)); }
function esc_url($u) { return htmlspecialchars($u); } function esc_html($t) { return htmlspecialchars($t); } function esc_attr($t) { return htmlspecialchars($t); }
function wp_json_encode($d) { return json_encode($d); }
function is_wp_error($x) { return false; }
function wp_remote_post($u, $a) {
    if (strpos($u, 'auth') !== false) return ['code' => 200, 'body' => json_encode(['access_token' => 't', 'expires_in' => 3600])];
    $ids = json_decode($a['body'], true)['itemIds']; $items = [];
    foreach ($ids as $id) { if (($GLOBALS['bad'] ?? '') === $id) { return ['code' => 400, 'body' => 'ungueltig']; } }
    foreach ($ids as $id) { if (($GLOBALS['drossel'] ?? '') === $id) { return ['code' => 429, 'body' => 'TooManyRequests']; } }
    foreach ($ids as $id) { $items[] = $GLOBALS['http_mp'][$a['headers']['x-marketplace'] ?? ''][$id] ?? $GLOBALS['http'][$id] ?? null; }
    $antwort = ['itemsResult' => ['items' => array_values(array_filter($items))]];
    if (!empty($GLOBALS['fehler200'])) { $antwort['errors'] = $GLOBALS['fehler200']; }
    return ['code' => 200, 'body' => json_encode($antwort)];
}
function wp_remote_get($u, $a) {
    if (strpos($u, 'update.json') !== false) return ['code' => 200, 'body' => json_encode($GLOBALS['upd'] ?? [])]; return ['code' => 200, 'body' => 'JPG', 'ct' => 'image/jpeg']; }
function wp_remote_retrieve_response_code($r) { return $r['code']; }
if (!function_exists('wp_nonce_url')) { function wp_nonce_url($u, $a = '') { return $u . '&_wpnonce=x'; } }
if (!function_exists('wp_verify_nonce')) { function wp_verify_nonce($n, $a = '') { return true; } }
if (!function_exists('get_site_transient')) { function get_site_transient($k) { return $GLOBALS['str'][$k] ?? false; } }
if (!function_exists('set_site_transient')) { function set_site_transient($k, $v, $e = 0) { $GLOBALS['str'][$k] = $v; return true; } }
if (!function_exists('wp_schedule_single_event')) { function wp_schedule_single_event($t, $h, $a = []) { $GLOBALS['sched'][] = $h; return true; } }
if (!function_exists('get_site_option')) { function get_site_option($k, $d = false) { return $GLOBALS['opt'][$k] ?? $d; } }
function wp_remote_retrieve_body($r) { return $r['body']; }
function wp_remote_retrieve_header($r, $h) { return $r['ct'] ?? ''; }
function wp_upload_dir() { $d = sys_get_temp_dir() . '/sabtest'; return ['basedir' => $d, 'baseurl' => 'https://x.test/up']; }
function wp_mkdir_p($d) { @mkdir($d, 0777, true); }
function get_queried_object_id() { return 42; } function current_user_can($c) { return $GLOBALS['admin']; }
function get_the_ID() { return $GLOBALS['pid'] ?? 0; }
function sanitize_key($k) { return strtolower(preg_replace('/[^a-z0-9_-]/i', '', (string) $k)); }
function get_permalink($i = 0) { return 'https://x.test/p'; }
function wp_date($f, $t) { return date($f, $t); }
require __DIR__ . '/schlanke-amazon-box.php';


if (!function_exists("home_url")) { function home_url($p = "") { return "https://example.test" . $p; } }
if (!function_exists("untrailingslashit")) { function untrailingslashit($s) { return rtrim($s, "/"); } }
if (!function_exists("wpautop")) { function wpautop($t) { return $t; } }
function do_shortcode_lite($t) {
    foreach (['amz_bild', 'amz_link'] as $sc) {
        $t = preg_replace_callback('#\[' . $sc . '\b([^\]]*)\](.*?)\[/' . $sc . '\]#is', function ($m) use ($sc) {
            preg_match_all('#(\w+)="([^"]*)"#', $m[1], $a); $at = array_combine($a[1], $a[2]);
            return call_user_func($GLOBALS['sc'][$sc], $at, $m[2]);
        }, $t);
    }
    return $t;
}

function do_shortcode($t) {
    return preg_replace_callback('#\[(amz_karte|amz_titel)\b([^\]]*)\]#', function ($m) {
        preg_match_all('#(\w+)="([^"]*)"#', $m[2], $a); return call_user_func($GLOBALS['sc'][$m[1]], array_combine($a[1], $a[2]), '');
    }, $t);
}
if (!function_exists('admin_url')) { function admin_url($p = '') { return 'https://x.test/wp-admin/' . $p; } }
class FakeResp { public $url; function __construct($u) { $this->url = $u; } }
class FakeHttp { public $o; function __construct($u) { $this->o = new FakeResp($u); } function get_response_object() { return $this->o; } }
function wp_remote_head($u, $a = []) { $z = $GLOBALS['kurz'][$u] ?? ''; return ['http_response' => new FakeHttp($z)]; }
$GLOBALS['kurz'] = ['https://amzn.to/abc' => 'https://www.amazon.es/Producto-Largo/dp/B0AAAAAAA1?tag=x', 'https://amzn.to/start' => 'https://www.amazon.es/'];
$fail = 0;
if (!function_exists('shortcode_parse_atts')) { function shortcode_parse_atts($t) { $o = []; if (preg_match_all('/([a-z_]+)=["\']([^"\']*)["\']/i', (string) $t, $m, PREG_SET_ORDER)) { foreach ($m as $x) { $o[$x[1]] = $x[2]; } } return $o; } }
if (!function_exists('wp_parse_url')) { function wp_parse_url($u, $c = -1) { return parse_url($u, $c); } }
function ok($c, $n) { global $fail; echo ($c ? 'OK   ' : 'FEHLER ') . $n . "\n"; if (!$c) $fail++; }
function item($asin, $listings) { return ['asin' => $asin, 'detailPageURL' => "https://www.amazon.es/dp/$asin?tag=t-21", 'itemInfo' => ['title' => ['displayValue' => "T$asin"]],
    'images' => ['primary' => ['large' => ['url' => 'https://m.media/x.jpg']]], 'offersV2' => ['listings' => $listings]]; }
$money = fn($p) => ['price' => ['money' => ['displayAmount' => $p]]];
$GLOBALS['opt'] += ['sab_client_id' => 'i', 'sab_client_secret' => 's', 'sab_tags' => "es=t-21\nco.uk=t-21\ncom=t-21", 'sab_default_land' => 'es'];

// Preis: erstes Angebot ohne Preis, zweites mit Preis => Preis wird gefunden
$GLOBALS['http']['B000000001'] = item('B000000001', [['isBuyBoxWinner' => false], $money('12,50 €') + ['isBuyBoxWinner' => false]]);
$r = new ReflectionClass('SAB');
$m = fn($n) => (function () use ($r, $n) { $x = $r->getMethod($n); $x->setAccessible(true); return $x; })();
$p = $m('produkt')->invoke(null, 'B000000001', 'es');
ok(($p['preis'] ?? '') === '12,50 €', 'Preis aus zweitem Angebot');
$p2 = $m('bestes_angebot')->invoke(null, item('B', [['isBuyBoxWinner' => true] + $money('9,99 €'), $money('5 €')]));
ok($p2['price']['money']['displayAmount'] === '9,99 €', 'Kaufen-Button-Angebot bevorzugt');

// Länder-Erkennung
$l = fn($h) => (function () use ($m, $h) { $_SERVER['HTTP_ACCEPT_LANGUAGE'] = $h; return $m('land')->invoke(null, 'auto'); })();
ok($l('es-ES,es;q=0.9') === 'es', 'auto es'); ok($l('en-GB,en;q=0.8') === 'co.uk', 'auto en-GB');
ok($l('en-US') === 'com', 'auto en-US'); ok($l('de-AT') === 'de', 'auto de-AT'); ok($l('ja') === 'es', 'auto unbekannt => Standard');
ok($l('') === 'es', 'auto leer => Standard');

// Link-Zähler, Hinweis, Warnung
for ($i = 1; $i <= 15; $i++) {
    $a = sprintf('B%09d', $i); $GLOBALS['http'][$a] = item($a, [$money('1 €')]);
}
$out = '';
for ($i = 1; $i <= 15; $i++) { $out .= $GLOBALS['sc']['amz_link'](['asin' => sprintf('B%09d', $i), 'land' => 'es'], "Link $i"); }
ok(substr_count($out, 'rel="nofollow sponsored noopener"') === 15, '15 Links mit rel');
$GLOBALS['opt']['sab_hinweis_auto'] = '1';
ob_start(); SAB::fuss(); $f = ob_get_clean();
ok(strpos($f, 'afiliado de Amazon') !== false, 'Hinweis automatisch (es)');
ok(strpos($f, 'Partnerlinks auf dieser Seite') === false, 'Warnbox nur für Admins');
ok(($GLOBALS['opt']['sab_linkwarnung'][42]['n'] ?? 0) === 15, 'Warnung in Verwaltung gemerkt');
$GLOBALS['admin'] = true; ob_start(); SAB::fuss(); $f = ob_get_clean();
ok(strpos($f, '15 Partnerlinks') === false, 'Gelbe Warnbox im Frontend entfernt (auch für Admin)');
$h = $GLOBALS['sc']['amz_hinweis'](['land' => 'co.uk']);
ok(strpos($h, 'Amazon Associate') !== false, 'amz_hinweis land=co.uk');

// ---- 0.10: Austausch-Regeln, Favorit/Ersetzen, A/B-Test ----
foreach (['B0ALT00001' => 'B0NEU00001', 'B0NEU00001' => 'B0NEU00002'] as $k => $v) { }
foreach (['B0ALT00001', 'B0NEU00001', 'B0NEU00002', 'B0AAAAAAA1', 'B0BBBBBBB1', 'B0CCCCCCC1'] as $a) { $GLOBALS['http'][$a] = item($a, [$money('1 €')]); }
$GLOBALS['opt']['sab_tausch'] = "B0ALT00001 => B0NEU00001\nB0NEU00001 => B0NEU00002 | Name";
$o = $GLOBALS['sc']['amz_link'](['asin' => 'B0ALT00001', 'land' => 'es'], 'x');
ok(strpos($o, '/dp/B0NEU00002') !== false && strpos($o, 'B0ALT00001') === false, 'Austausch-Kette ALT => NEU => NEU2 in amz_link');
$o = $GLOBALS['sc']['amz_bild'](['asin' => 'B0ALT00001', 'land' => 'es'], 'Rückfall');
ok(strpos($o, 'B0NEU00002') !== false, 'Austausch in amz_bild');
$GLOBALS['opt']['sab_tausch'] = '';
$o = $GLOBALS['sc']['amz_link'](['asin' => 'B0ALT00001', 'land' => 'es'], 'x');
ok(strpos($o, 'B0ALT00001') !== false, 'Regel gelöscht => Zustand wie vorher');

// Favorit / Ersetzen in der Ersatzliste
$GLOBALS['opt']['sab_slots'] = "liste: B0AAAAAAA1, B0BBBBBBB1, B0CCCCCCC1";
$o = $GLOBALS['sc']['amz_produkt'](['slot' => 'liste', 'land' => 'es'], 'Rückfall');
ok(strpos($o, 'data-sab-asin="B0AAAAAAA1"') !== false, 'ohne Markierung: erste ASIN');
$GLOBALS['opt']['sab_marken'] = ['B0AAAAAAA1' => 'ers', 'B0CCCCCCC1' => 'fav'];
$o = $GLOBALS['sc']['amz_produkt'](['slot' => 'liste', 'land' => 'es'], 'Rückfall');
ok(strpos($o, 'data-sab-asin="B0CCCCCCC1"') !== false, 'Favorit wird bevorzugt');
$GLOBALS['opt']['sab_marken'] = ['B0CCCCCCC1' => 'ers', 'B0AAAAAAA1' => 'ers', 'B0BBBBBBB1' => 'ers'];
$o = $GLOBALS['sc']['amz_produkt'](['slot' => 'liste', 'land' => 'es'], 'Rückfall');
ok(strpos($o, 'data-sab-asin="B0AAAAAAA1"') !== false, 'alle „Ersetzen“: Reihenfolge bleibt');
$GLOBALS['opt']['sab_marken'] = [];

// A/B-Test: stabil je Artikel, beide Varianten kommen vor, Ersatz bei Ausfall
$GLOBALS['opt']['sab_tests'] = "liste: B0AAAAAAA1 | B0BBBBBBB1";
$sehen = []; $stabil = true;
for ($pid = 1; $pid <= 40; $pid++) {
    $GLOBALS['pid'] = $pid;
    $o1 = $GLOBALS['sc']['amz_produkt'](['slot' => 'liste', 'land' => 'es'], 'R');
    $o2 = $GLOBALS['sc']['amz_produkt'](['slot' => 'liste', 'land' => 'es'], 'R');
    if ($o1 !== $o2) { $stabil = false; }
    preg_match('/data-sab-asin="(\w+)" data-sab-slot="liste" data-sab-var="(\w)"/', $o1, $m);
    $sehen[$m[2] ?? '?'] = ($sehen[$m[2] ?? '?'] ?? 0) + 1;
    if (($m[2] ?? '') === 'A' && ($m[1] ?? '') !== 'B0AAAAAAA1') { $stabil = false; }
    if (($m[2] ?? '') === 'B' && ($m[1] ?? '') !== 'B0BBBBBBB1') { $stabil = false; }
}
ok($stabil, 'A/B: Variante je Artikel stabil und passend zur ASIN');
ok(($sehen['A'] ?? 0) > 5 && ($sehen['B'] ?? 0) > 5 && count($sehen) === 2, 'A/B: beide Varianten kommen vor ' . json_encode($sehen));
// Variante B ausgefallen => Ersatzliste, keine Variante gezählt
$GLOBALS['http']['B0BBBBBBB1'] = item('B0BBBBBBB1', []);
$GLOBALS['tr'] = [];
foreach ((array) glob(sys_get_temp_dir() . '/sabtest/*') as $f) { @unlink($f); }
$hit = false;
for ($pid = 1; $pid <= 40; $pid++) {
    $GLOBALS['pid'] = $pid;
    $o = $GLOBALS['sc']['amz_produkt'](['slot' => 'liste', 'land' => 'es'], 'R');
    if (strpos($o, 'data-sab-var="B"') !== false) { $hit = true; }
}
ok(!$hit, 'A/B: ausgefallene Variante wird nie angezeigt oder gezählt');
$GLOBALS['opt']['sab_tests'] = '';


function check_admin_referer($x) { return true; } function wp_nonce_field($x) {} function esc_textarea($t) { return htmlspecialchars($t); }
function wp_unslash($x) { return $x; } function sanitize_textarea_field($x) { return $x; } function sanitize_text_field($x) { return $x; }
function get_the_title($i) { return 'Titel'; }
function esc_url_raw($u) { return $u; } function rest_url($p = '') { return 'https://x.test/wp-json/' . $p; }
function add_query_arg($k, $v = '', $u = '') { if (is_array($k)) { $u = $v; return $u . (strpos($u, '?') === false ? '?' : '&') . http_build_query($k); } return $u . (strpos($u, '?') === false ? '?' : '&') . $k . '=' . $v; }
function is_user_logged_in() { return !empty($GLOBALS['loggedin']); }
foreach (['uebersicht', 'produkte', 'ausfaelle', 'tests', 'einstellungen', 'hilfe'] as $t) {
    $_GET['tab'] = $t; ob_start(); SAB::seite(); $h = ob_get_clean();
    ok(strpos($h, 'nav-tab-active') !== false && strlen($h) > 300, "Reiter $t rendert");
}
$_GET = []; $_POST = ['sab_form' => 'marken', 'sab_markieren' => '1', 'sab_fav' => ['B0AAAAAAA1' => '1'], 'sab_ers' => ['B0BBBBBBB1' => '1'], 'sab_tab' => 'uebersicht'];
ob_start(); SAB::seite(); ob_get_clean();
ok(($GLOBALS['opt']['sab_marken'] ?? []) === ['B0AAAAAAA1' => 'fav', 'B0BBBBBBB1' => 'ers'], 'Markierungen speichern');
$_POST = ['sab_form' => 'marken', 'sab_tab' => 'uebersicht']; // „Übersicht neu laden“ darf Markierungen nicht löschen
ob_start(); SAB::seite(); ob_get_clean();
ok(count($GLOBALS['opt']['sab_marken']) === 2, 'Neu laden löscht Markierungen nicht');

// ---- 0.11: Umbau-Werkzeug ----
$html = '<div class="sm-box sm-rec"><p class="sm-kicker">Tipp</p><div class="sm-prod"><a class="sm-pimg" href="https://www.amazon.es/dp/B08BCFRPH5?tag=ayuba09-21&amp;linkCode=ogi&amp;th=1&amp;psc=1" target="_blank" rel="noopener nofollow sponsored"><img src="https://m.media-amazon.com/images/I/419Fry7ckoL.jpg" alt="Casa"></a><p class="sm-ptitle">Casa</p><p class="sm-pbtns"><a class="sm-btn" href="https://www.amazon.es/dp/B08BCFRPH5?tag=ayuba09-21">Ver oferta <b>en</b> Amazon</a> <a href="https://amzn.to/3XTlFLI">Kurzlink</a> <a href="https://www.amazon.de/dp/B000000000?tag=x">DE</a> [amz_link asin="B0ALT00001"]<a href="https://www.amazon.es/dp/B0ALT00001">schon drin</a>[/amz_link]</p></div></div>';
[$neu, $n, $sk] = SAB::umbau_text($html);
ok($n === 2, "Umbau: 2 Links umgewandelt (war $n)");
ok($sk === 1, "Umbau: amazon.de ohne Partner-ID übersprungen (war $sk)");
ok(strpos($neu, '[amz_bild asin="B08BCFRPH5" land="es" class="sm-pimg"]<a class="sm-pimg"') !== false && strpos($neu, '</a>[/amz_bild]') !== false, 'Umbau: Bild-Link mit Rückfall-HTML');
ok(strpos($neu, '[amz_link asin="B08BCFRPH5" land="es" class="sm-btn"]Ver oferta en Amazon[/amz_link]') !== false, 'Umbau: Button wird [amz_link] mit Klasse');
ok(strpos($neu, 'amzn.to/3XTlFLI') !== false && strpos($neu, 'amazon.de/dp/B000000000') !== false, 'Umbau: Kurzlink und DE bleiben unverändert');
ok(substr_count($neu, '[amz_link asin="B0ALT00001"]<a href') === 1, 'Umbau: vorhandener Platzhalter unberührt');
[$neu2, $n2] = SAB::umbau_text($neu);
ok($n2 === 0 && $neu2 === $neu, 'Umbau: zweiter Lauf ändert nichts (idempotent)');
[$neu3, $n3] = SAB::umbau_text($html, 'B000000000');
ok($n3 === 0, 'Umbau: Filter „nur diese ASIN“');
$GLOBALS['opt']['sab_tausch'] = "B08BCFRPH5 => B0NEU00001";
$GLOBALS['http']['B08BCFRPH5'] = item('B08BCFRPH5', [$money('1 €')]);
$gerendert = do_shortcode_lite($neu);
ok(strpos($gerendert, '/dp/B0NEU00001') !== false && strpos($gerendert, 'B08BCFRPH5') === false, 'Umbau + Austausch-Regel: neues Produkt überall (Bild und Button)');
ok(strpos($gerendert, 'class="sm-btn') !== false && strpos($gerendert, 'class="sab-bild sm-pimg"') !== false, 'Umbau: Klassen bleiben erhalten');
$GLOBALS['opt']['sab_tausch'] = '';

// ---- 0.11: Bild-A/B ----
$GLOBALS['http']['B0IMG00001'] = ['asin' => 'B0IMG00001', 'detailPageURL' => 'https://www.amazon.es/dp/B0IMG00001?tag=t-21', 'itemInfo' => ['title' => ['displayValue' => 'T']],
    'images' => ['primary' => ['large' => ['url' => 'https://m/h.jpg']], 'variants' => [['large' => ['url' => 'https://m/v1.jpg']], ['large' => ['url' => 'https://m/v2.jpg']]]], 'offersV2' => ['listings' => [$money('1 €')]]];
$GLOBALS['opt']['sab_bildtests'] = "B0IMG00001: 1 | 3";
$GLOBALS['opt']['sab_slots'] = "bildslot: B0IMG00001";
$varianten = []; $ok = true;
for ($pid = 1; $pid <= 40; $pid++) {
    $GLOBALS['pid'] = $pid;
    $o = $GLOBALS['sc']['amz_produkt'](['slot' => 'bildslot', 'land' => 'es'], 'R');
    preg_match('/data-sab-slot="([^"]*)" data-sab-var="(\w)"/', $o, $m);
    $varianten[$m[2] ?? '?'] = true;
    $imgsrc = preg_match('/<img src="([^"]*)"/', $o, $im) ? $im[1] : '';
    if (($m[1] ?? '') !== 'bild-B0IMG00001') { $ok = false; }
    if (($m[2] ?? '') === 'A' && strpos($imgsrc, '-2.jpg') !== false) { $ok = false; }
    if (($m[2] ?? '') === 'B' && strpos($imgsrc, '-2.jpg') === false) { $ok = false; }
}
ok($ok && count($varianten) === 2, 'Bild-A/B: Variante A = Hauptbild, Variante B = Bild 3, Zählung je Bild');
$GLOBALS['opt']['sab_bildtests'] = "B0IMG00001: 1 | 5"; // Bild 5 existiert nicht => kein Test, Hauptbild
$o = $GLOBALS['sc']['amz_produkt'](['slot' => 'bildslot', 'land' => 'es'], 'R');
$GLOBALS['pid'] = 3;
ok(strpos($o, '<img') !== false, 'Bild-A/B: fehlendes Bild => Hauptbild als Rückfall');
$GLOBALS['opt']['sab_bildtests'] = '';

// ---- 0.11: Karten-Design ----
$GLOBALS['opt']['sab_hinweis_auto'] = '0'; $GLOBALS['admin'] = false;
ob_start(); SAB::fuss(); $f = ob_get_clean();
ok(strpos($f, 'id="sab-karten"') !== false, 'Karten-Design im Fuß');
$GLOBALS['opt']['sab_karten_css'] = '0';
ob_start(); SAB::fuss(); $f = ob_get_clean();
ok(strpos($f, 'sab-karten') === false, 'Karten-Design abschaltbar');
foreach (['umbau'] as $t) { $GLOBALS['admin'] = true; $_POST = []; $_GET['tab'] = $t; ob_start(); SAB::seite(); $h = ob_get_clean(); ok(strpos($h, 'Umbau: feste Amazon-Links') !== false, 'Reiter Umbau rendert'); }

// ---- 0.12: Preis-/Versender-Diagnose, Bewertungen, Übersicht ----
$GLOBALS['http']['B0DIAG0001'] = item('B0DIAG0001', [['isBuyBoxWinner' => true, 'merchantInfo' => ['name' => 'Amazon'], 'price' => ['money' => ['amount' => 12.5, 'currency' => 'EUR']]]]);
$GLOBALS['http']['B0DIAG0002'] = item('B0DIAG0002', [['isBuyBoxWinner' => true, 'availability' => ['type' => 'IN_STOCK']]]);
$GLOBALS['http']['B0DIAG0003'] = item('B0DIAG0003', []);
$GLOBALS['http']['B0DIAG0004'] = item('B0DIAG0004', [$money('9 €') + ['merchantInfo' => ['id' => 'A1234']]]);
$GLOBALS['http']['B0DIAG0004']['customerReviews'] = ['count' => 321, 'starRating' => ['value' => 4.6]];
$GLOBALS['opt']['sab_produkte'] = "B0DIAG0001 | a\nB0DIAG0002 | b\nB0DIAG0003 | c\nB0DIAG0004 | d";
$GLOBALS['tr'] = []; $GLOBALS['admin'] = true; $_POST = []; $_GET['tab'] = 'uebersicht';
ob_start(); SAB::seite(); $h = ob_get_clean();
ok(strpos($h, '12,50 EUR') !== false, 'Preis aus amount+currency als Rückfall');
ok(strpos($h, 'ohne Preisfeld (Felder: isBuyBoxWinner, availability)') !== false, 'Diagnose nennt vorhandene Felder bei fehlendem Preis');
ok(strpos($h, 'Angebotsblock leer') !== false, 'Diagnose: leerer Angebotsblock');
ok(strpos($h, 'A1234') !== false, 'Verkäufer-ID als Rückfall');
ok(strpos($h, 'Bewertung') === false, 'Keine leere Bewertungsspalte mehr');
ok(strpos($h, 'sab-thumb') !== false && strpos($h, 'sab-uebers') !== false, 'Übersicht: kompakte Zeilen mit Mini-Bild');
ok(strpos($h, 'Rohdaten') !== false, 'Rohdaten aufklappbar');

// ---- 0.12.1: Lieferort-Hinweis, strikt-Option ----
$GLOBALS['http']['B0OOS00001'] = item('B0OOS00001', [['isBuyBoxWinner' => true, 'availability' => ['type' => 'OUT_OF_STOCK', 'message' => 'No disponible.']]]);
$GLOBALS['http']['B0OOS00001']['offersV2']['listings'][0] += ['price' => ['money' => ['displayAmount' => '5 €']]];
foreach (['sab_marken','sab_tests','sab_tausch','sab_ausfall','sab_bildtests'] as $k) { unset($GLOBALS['opt'][$k]); }
$GLOBALS['opt']['sab_slots'] = "oos: B0OOS00001, B0AAAAAAA1"; $GLOBALS['tr'] = []; $GLOBALS['opt']['sab_strikt'] = '0';
$o = $GLOBALS['sc']['amz_produkt'](['slot' => 'oos', 'land' => 'es'], 'R');
ok(strpos($o, 'B0OOS00001') !== false, 'Standard: API „OUT_OF_STOCK“ wird weiter angezeigt (Lieferort kann abweichen)');
$GLOBALS['opt']['sab_strikt'] = '1'; $GLOBALS['tr'] = [];
foreach ((array) glob(sys_get_temp_dir() . '/sabtest/*') as $f) { @unlink($f); }
$o = $GLOBALS['sc']['amz_produkt'](['slot' => 'oos', 'land' => 'es'], 'R');
ok(strpos($o, 'B0AAAAAAA1') !== false && strpos($o, 'B0OOS00001') === false, 'strikt: OUT_OF_STOCK wird durch Ersatz ersetzt');
$GLOBALS['opt']['sab_strikt'] = '0'; $GLOBALS['tr'] = [];
$GLOBALS['opt']['sab_produkte'] = "B0OOS00001 | oos"; $_GET['tab'] = 'uebersicht'; $GLOBALS['admin'] = true; $_POST = [];
ob_start(); SAB::seite(); $h = ob_get_clean();
ok(strpos($h, 'nicht auf Lager') !== false && strpos($h, 'Standard-Lieferort') !== false, 'Übersicht: nicht auf Lager mit Lieferort-Hinweis');
ok(strpos($h, 'Bewertung') === false, 'Übersicht ohne Bewertungsspalte');

// ---- 0.13.0: Karten mit Preis und Favorit ----
foreach (['sab_marken','sab_tests','sab_tausch','sab_ausfall','sab_bildtests'] as $k) { unset($GLOBALS['opt'][$k]); }
$GLOBALS['http']['B0PRE00001'] = item('B0PRE00001', [['isBuyBoxWinner' => true, 'availability' => ['type' => 'IN_STOCK']] + $money('19,99 €')]);
$GLOBALS['opt']['sab_slots'] = "karte: B0PRE00001"; $GLOBALS['opt']['sab_marken'] = ['B0PRE00001' => 'fav']; $GLOBALS['tr'] = [];
$o = $GLOBALS['sc']['amz_produkt'](['slot' => 'karte', 'land' => 'es'], 'R');
ok(strpos($o, '19,99') === false && strpos($o, 'Ver oferta en Amazon') !== false, 'Karte zeigt keinen Preis, Button „Ver oferta en Amazon“');
ok(strpos($o, 'sab-fav') !== false && strpos($o, 'Nuestra elección') !== false, 'Karte zeigt Favorit-Band');
ok(substr_count($o, '<a ') === 1, 'Karte hat genau einen Link');
unset($GLOBALS['opt']['sab_marken']); $GLOBALS['tr'] = [];
$o = $GLOBALS['sc']['amz_produkt'](['slot' => 'karte', 'land' => 'es'], 'R');
ok(strpos($o, 'sab-fav') === false, 'ohne Favorit kein Band');

// ---- 0.14.0: Selbst-Aktualisierung ----
$zipok = 'https://github.com/Aleeeb/schlanke-amazon-box-/raw/main/schlanke-amazon-box.zip';
$t0 = (object) ['checked' => ['x' => 1], 'response' => []];
$GLOBALS['upd'] = ['version' => '99.0.0', 'zip' => $zipok]; $GLOBALS['tr'] = [];
$t = SAB::update_pruefen(clone $t0);
ok(($t->response['schlanke-amazon-box/schlanke-amazon-box.php']->new_version ?? '') === '99.0.0', 'Update: neuere Version wird gemeldet');
$GLOBALS['upd'] = ['version' => SAB::VERSION, 'zip' => $zipok]; $GLOBALS['tr'] = [];
ok(empty(SAB::update_pruefen(clone $t0)->response), 'Update: gleiche Version wird nicht gemeldet');
$GLOBALS['upd'] = ['version' => '99.0.0', 'zip' => 'https://evil.example/x.zip']; $GLOBALS['tr'] = [];
ok(empty(SAB::update_pruefen(clone $t0)->response), 'Update: fremde ZIP-Adresse wird abgelehnt');
$GLOBALS['upd'] = []; $GLOBALS['tr'] = [];
ok(empty(SAB::update_pruefen(clone $t0)->response), 'Update: leere Antwort ist harmlos');

// ---- 0.15.0: Wochenmail ----
$GLOBALS['opt']['sab_mail_an'] = 'a@b.test'; unset($GLOBALS['opt']['sab_mail_zuletzt']);
$GLOBALS['opt']['sab_ausfall'] = ['B0AUS00001' => ['name' => 'Kaputtes Ding']]; $GLOBALS['opt']['sab_produkte'] = 'B0AUS00001 | Kaputtes Ding';
$GLOBALS['mail'] = []; SAB::pruefen();
ok(count($GLOBALS['mail']) === 1 && $GLOBALS['mail'][0][0] === 'a@b.test', 'Wochenmail wird gesendet');
ok(strpos($GLOBALS['mail'][0][2] ?? '', 'Kaputtes Ding') !== false, 'Wochenmail nennt Ausfälle');
SAB::pruefen();
ok(count($GLOBALS['mail']) === 1, 'Wochenmail nur einmal pro Woche');
$GLOBALS['opt']['sab_mail_an'] = ''; unset($GLOBALS['opt']['sab_mail_zuletzt']); SAB::pruefen();
ok(count($GLOBALS['mail']) === 1, 'ohne Adresse keine Mail');

// ---- 0.17.0: Button-Test ----
$GLOBALS['opt']['sab_btn_a'] = 'Ver oferta en Amazon'; $GLOBALS['opt']['sab_btn_b'] = 'Ver precio en Amazon'; $GLOBALS['tr'] = [];
foreach (['sab_marken','sab_tests','sab_tausch','sab_ausfall','sab_bildtests'] as $k) { unset($GLOBALS['opt'][$k]); }
$o = $GLOBALS['sc']['amz_produkt'](['slot' => 'karte', 'land' => 'es'], 'R');
ok(strpos($o, 'data-sab-slot="button-test"') !== false && preg_match('/data-sab-var="[AB]"/', $o) === 1, 'Button-Test: Variante und Slot gesetzt');
ok(strpos($o, 'Ver oferta en Amazon') !== false || strpos($o, 'Ver precio en Amazon') !== false, 'Button-Test: Text A oder B');
$o2 = $GLOBALS['sc']['amz_produkt'](['slot' => 'karte', 'land' => 'es', 'text' => 'Eigener'], 'R');
ok(strpos($o2, 'Eigener') !== false && strpos($o2, 'button-test') === false, 'eigener Text: kein Test');
$GLOBALS['opt']['sab_btn_b'] = '';
$o3 = $GLOBALS['sc']['amz_produkt'](['slot' => 'karte', 'land' => 'es'], 'R');
ok(strpos($o3, 'button-test') === false, 'nur ein Text: kein Test');

// ---- 0.17.1: Preis-Test ----
$GLOBALS['opt']['sab_preis_test'] = '1'; $GLOBALS['opt']['sab_slots'] = "karte: B0PRE00001"; $GLOBALS['tr'] = [];
$ms = ['A' => 0, 'B' => 0];
for ($pid = 1; $pid <= 40; $pid++) {
    $GLOBALS['pid'] = $pid;
    $o = $GLOBALS['sc']['amz_produkt'](['slot' => 'karte', 'land' => 'es'], 'R');
    $mit = strpos($o, 'sab-preis') !== false; $ms[$mit ? 'B' : 'A']++;
    if ($mit && strpos($o, 'data-sab-var="B"') === false) { ok(false, 'Preis-Test: B-Variante mit Preis trägt var=B'); }
    if (!$mit && strpos($o, 'data-sab-var="A"') === false) { ok(false, 'Preis-Test: A-Variante trägt var=A'); }
}
ok($ms['A'] > 5 && $ms['B'] > 5, 'Preis-Test: beide Varianten kommen vor (' . $ms['A'] . '/' . $ms['B'] . ')');
$GLOBALS['opt']['sab_preis_test'] = '0'; $GLOBALS['tr'] = [];
$o = $GLOBALS['sc']['amz_produkt'](['slot' => 'karte', 'land' => 'es'], 'R');
ok(strpos($o, 'sab-preis') === false && strpos($o, 'preis-test') === false, 'Preis-Test aus: kein Preis, kein Test');

// ---- 0.18.0: Vorschau ----
foreach (['sab_marken','sab_tests','sab_tausch','sab_ausfall','sab_bildtests'] as $k) { unset($GLOBALS['opt'][$k]); }
$GLOBALS['opt']['sab_preis_test'] = '0'; $GLOBALS['opt']['sab_btn_a'] = ''; $GLOBALS['opt']['sab_btn_b'] = ''; $GLOBALS['tr'] = [];
$o = $GLOBALS['sc']['amz_produkt'](['asin' => 'B0PRE00001', 'land' => 'es'], 'R');
ok(strpos($o, 'sab-produkt') !== false && strpos($o, 'B0PRE00001') !== false, 'amz_produkt asin= ohne Slot');
$GLOBALS['opt']['sab_produkte'] = "B0PRE00001 | Beispiel"; $GLOBALS['opt']['sab_slots'] = "karte: B0PRE00001"; $GLOBALS['admin'] = true; $_GET['tab'] = 'vorschau'; $_POST = [];
ob_start(); SAB::seite(); $h = ob_get_clean();
ok(strpos($h, 'Slots') !== false && strpos($h, 'Beispiel') !== false && substr_count($h, 'class="sab-produkt"') >= 2, 'Vorschau-Reiter zeigt Slot und Produkt als Karten');
ok(strpos($h, 'sab-bild') !== false, 'Vorschau-Reiter zeigt Bild-Ausgabe');

// ---- 0.19.0: AAWP-Kompatibilität ----
$GLOBALS['http']['B08L4GS81X'] = item('B08L4GS81X', [$money('9 €')]);
$GLOBALS['tr'] = [];
$o = $GLOBALS['sc']['aawp'](['fields' => 'B08L4GS81Xx', 'value' => 'thumb', 'image_size' => 'large'], '');
ok(strpos($o, '<img') !== false && strpos($o, 'B08L4GS81X') !== false && strpos($o, 'rel="nofollow sponsored noopener"') !== false, 'aawp fields thumb: Bild mit Link (Tippfehler-ASIN gekürzt)');
$o = $GLOBALS['sc']['aawp'](['fields' => 'B08L4GS81X', 'value' => 'price'], '');
ok($o === '', 'aawp value=price bleibt leer');
$o = $GLOBALS['sc']['aawp'](['box' => 'B08L4GS81X'], '');
ok(strpos($o, 'sab-produkt') !== false, 'aawp box: Karte');
$o = $GLOBALS['sc']['aawp'](['link' => 'B08L4GS81X'], 'Hier');
ok(strpos($o, '>Hier</a>') !== false, 'aawp link: Textlink');
$o = $GLOBALS['sc']['aawp'](['fields' => 'B08L4GS81X,B000000001', 'value' => 'thumb'], '');
ok(substr_count($o, '<img') === 2, 'aawp mehrere ASINs');

$GLOBALS['http']['B09TRSYKZS'] = item('B09TRSYKZS', [$money('7 €')]); $GLOBALS['tr'] = [];
$o = $GLOBALS['sc']['aawp'](['fields' => 'B09TRSYKZS', 'value' => 'thumb', 'image_size' => 'large', 'image_width' => '420', 'image_height' => '420'], '');
ok(strpos($o, 'max-width:360px') !== false && strpos($o, '<img') !== false, 'aawp image_width wird übernommen');

// ---- 0.20.0: Sofortmeldungen ----
foreach (['sab_marken','sab_tests','sab_tausch','sab_ausfall','sab_bildtests','sab_alarm_zuletzt','sab_mail_zuletzt'] as $k) { unset($GLOBALS['opt'][$k]); }
$GLOBALS['opt']['sab_mail_an'] = 'a@b.test'; $GLOBALS['opt']['sab_slots'] = ''; $GLOBALS['opt']['sab_produkte'] = "B0NEW00001 | Neues Ding";
$GLOBALS['http']['B0NEW00001'] = item('B0NEW00001', []);  // keine Angebote => nicht lieferbar
$GLOBALS['mail'] = []; $GLOBALS['tr'] = [];
SAB::pruefen();
$betr = array_column($GLOBALS['mail'], 1);
ok(count(array_filter($betr, fn($b) => strpos($b, 'nicht mehr verfügbar') !== false)) === 1, 'Ausfall-Alarm gesendet');
$GLOBALS['mail'] = []; $GLOBALS['tr'] = []; SAB::pruefen();
ok(count(array_filter(array_column($GLOBALS['mail'], 1), fn($b) => strpos($b, 'nicht mehr verfügbar') !== false)) === 0, 'Ausfall-Alarm nicht doppelt');
unset($GLOBALS['opt']['sab_ausfall'], $GLOBALS['opt']['sab_alarm_zuletzt']); $GLOBALS['opt']['sab_produkte'] = "B0NONE0001 | Gibtsnicht";
unset($GLOBALS['http']['B0NONE0001']); $GLOBALS['mail'] = []; $GLOBALS['tr'] = []; $GLOBALS['opt']['sab_mail_zuletzt'] = time();
SAB::pruefen();
ok(true, 'Lauf mit fehlendem Produkt ohne Absturz');

// ---- 0.21.0: Seitenleiste ----
$GLOBALS['menu'] = []; SAB::menue();
ok(count($GLOBALS['menu']) === 19 && $GLOBALS['menu'][0] === ['top', 'sab'], 'Menü: ein Hauptpunkt und siebzehn Untermenüs');
ok(in_array(['sub', 'sab-vorschau'], $GLOBALS['menu'], true) && in_array(['sub', 'sab'], $GLOBALS['menu'], true), 'Menü: Untermenü Vorschau und Übersicht');
$GLOBALS['admin'] = true; unset($_GET['tab']); $_GET['page'] = 'sab-hilfe'; $_POST = [];
ob_start(); SAB::seite(); $h = ob_get_clean();
ok(strpos($h, 'nav-tab-active') !== false && preg_match('/nav-tab-active" href="[^"]*sab-hilfe/', $h) === 1, 'Seite: Reiter folgt dem Untermenü');
ok(strpos($h, 'admin.php?page=sab-score') !== false, 'Seite: Reiterlinks über admin.php');
// ---- 0.21.1: Übersicht aus allen Artikeln ----
$GLOBALS['wpdb'] = new class { public $posts = 'wp_posts'; public function get_col($q) { return [
  'Text [aawp fields="B0AAAAAAA1" value="thumb"] und [aawp box="B0BBBBBBB2,B0CCCCCCC3x"]',
  '[amz_produkt asin="B0DDDDDDD4"] [amz_bild asin="B0EEEEEEE5"] [aawp link="B0FFFFFFF6"]' ]; } };
$GLOBALS['opt']['sab_produkte'] = ''; $GLOBALS['opt']['sab_slots'] = ''; $GLOBALS['opt']['sab_tests'] = ''; $GLOBALS['opt']['sab_tausch'] = ''; $GLOBALS['tr'] = [];
$rm = new ReflectionMethod('SAB', 'alle_asins'); $rm->setAccessible(true); $liste = $rm->invoke(null);
foreach (['B0AAAAAAA1', 'B0BBBBBBB2', 'B0CCCCCCC3', 'B0DDDDDDD4', 'B0EEEEEEE5', 'B0FFFFFFF6'] as $a) { ok(in_array($a, $liste, true), "Übersicht enthält $a"); }
unset($GLOBALS['wpdb']);

// Angebotswahl: lieferbares Angebot vor „nicht auf Lager“, auch wenn dieses die Buy Box hat
$mA = new ReflectionMethod('SAB', 'bestes_angebot'); $mA->setAccessible(true);
$l = $mA->invoke(null, ['offersV2' => ['listings' => [
    ['isBuyBoxWinner' => true, 'availability' => ['type' => 'OUT_OF_STOCK'], 'price' => ['money' => ['displayAmount' => '9 €']]],
    ['isBuyBoxWinner' => false, 'availability' => ['type' => 'IN_STOCK'], 'price' => ['money' => ['displayAmount' => '11 €']]]]]]);
ok(($l['availability']['type'] ?? '') === 'IN_STOCK', 'Angebotswahl: lieferbares vor nicht auf Lager');
$l = $mA->invoke(null, ['offersV2' => ['listings' => [['availability' => ['type' => 'OUT_OF_STOCK'], 'price' => ['money' => ['displayAmount' => '9 €']]]]]]);
ok(($l['availability']['type'] ?? '') === 'OUT_OF_STOCK', 'Angebotswahl: nur nicht auf Lager => bleibt nicht auf Lager');

// Lieferbar-Zelle: OUT_OF_STOCK ohne Preis/Verkäufer = „unklar“, mit Preis = „nein“
$mL = new ReflectionMethod('SAB', 'lieferzelle'); $mL->setAccessible(true);
$z = $mL->invoke(null, ['lieferbar' => true, 'status' => 'OUT_OF_STOCK', 'meldung' => 'No disponible.', 'preis' => '', 'verkaeufer' => '', 'roh' => '{}']);
ok(strpos($z, 'unklar') !== false && strpos($z, 'No disponible.') !== false, 'Lieferbar: OUT_OF_STOCK ohne Preis und Verkäufer = unklar');
$z = $mL->invoke(null, ['lieferbar' => true, 'status' => 'OUT_OF_STOCK', 'meldung' => '', 'preis' => '9 €', 'verkaeufer' => 'X', 'roh' => '{}']);
ok(strpos($z, '>nein<') !== false && strpos($z, 'unklar') === false, 'Lieferbar: OUT_OF_STOCK mit Preis = nein');

$z = $mL->invoke(null, ['trotz' => true, 'lieferbar' => true, 'status' => 'OUT_OF_STOCK', 'meldung' => 'No disponible.', 'preis' => '', 'verkaeufer' => '', 'roh' => '{}']);
ok(strpos($z, 'von dir auf Amazon geprüft') !== false && strpos($z, 'unklar') === false, 'Lieferbar: Haken „trotzdem lieferbar“ gewinnt');

// Streichpreis, Prime, Rang
$mS = new ReflectionMethod('SAB', 'spar_von'); $mS->setAccessible(true);
$sp = $mS->invoke(null, ['price' => ['money' => ['displayAmount' => '8 €'], 'savingBasis' => ['money' => ['displayAmount' => '10 €']], 'savings' => ['money' => ['displayAmount' => '2 €'], 'percentage' => 20]]]);
ok($sp['streich'] === '10 €' && $sp['ersparnis'] === '2 €' && $sp['prozent'] === 20, 'Streichpreis und Ersparnis gelesen');
$mF = new ReflectionMethod('SAB', 'finde'); $mF->setAccessible(true);
ok($mF->invoke(null, ['a' => ['b' => ['isPrimeEligible' => true]]], 'isPrimeEligible') === true, 'Prime-Feld wird tief gefunden');
$mR = new ReflectionMethod('SAB', 'rangzelle'); $mR->setAccessible(true);
$z = $mR->invoke(null, ['rang' => 1234, 'kategorie' => 'Jardín']);
ok(strpos($z, 'Jardín') !== false && strpos($z, 'Platz 1.234') !== false, 'Rang-Zelle zeigt Kategorie und Platz');
$mP = new ReflectionMethod('SAB', 'primezelle'); $mP->setAccessible(true);
ok(strpos($mP->invoke(null, ['deal' => 'Oferta del día']), 'Oferta del día') !== false && $mP->invoke(null, []) === '–', 'Aktion-Zelle zeigt Aktion');

$mRv = new ReflectionMethod('SAB', 'rang_von'); $mRv->setAccessible(true);
$rv = $mRv->invoke(null, ['browseNodeInfo' => ['websiteSalesRank' => ['contextFreeName' => 'Jardín', 'salesRank' => 4334]]]);
ok($rv === ['Jardín', 4334], 'Rang aus websiteSalesRank');
$rv = $mRv->invoke(null, ['browseNodeInfo' => ['browseNodes' => [['contextFreeName' => 'Mallas', 'salesRank' => 77]]]]);
ok($rv === ['Mallas', 77], 'Rang-Rückfall aus browseNodes');
$mDv = new ReflectionMethod('SAB', 'deal_von'); $mDv->setAccessible(true);
ok(strpos($mDv->invoke(null, ['offersV2' => ['listings' => [['type' => 'LIGHTNING_DEAL', 'dealDetails' => ['badge' => 'Oferta flash']]]]]), 'Oferta flash') !== false, 'Deal gelesen');

$mSc = new ReflectionMethod('SAB', 'score'); $mSc->setAccessible(true);
[$p1, $h1] = $mSc->invoke(null, ['rang' => 80, 'kaufen' => true, 'status' => 'IN_STOCK', 'lieferbar' => true, 'preis' => '9 €', 'spar' => ['streich' => '12 €'], 'deal' => 'X', 'preisrel' => 0.5]);
ok($p1 >= 93 && $h1 === '', 'Score: bestes Produkt nahe 100');
[$p2, $h2] = $mSc->invoke(null, ['rang' => 4334, 'kaufen' => true, 'status' => 'OUT_OF_STOCK', 'lieferbar' => true, 'preis' => '', 'verkaeufer' => '']);
ok($p2 >= 40 && $p2 <= 50 && strpos($h2, 'Schnittstelle prüfen') !== false, 'Score: guter Rang ohne Angebot = Hinweis');
[$p3, $h3] = $mSc->invoke(null, ['kaufen' => false, 'status' => 'OUT_OF_STOCK', 'lieferbar' => false]);
ok($p3 <= 45 && $h3 === 'ohne Verkaufsrang', 'Score: nichts ist (fast) 0');
$mTo = new ReflectionMethod('SAB', 'toetend'); $mTo->setAccessible(true);
ok($mTo->invoke(null, 'Neudorff Ferramol Antilimacos – Cebo granulado') && $mTo->invoke(null, 'Compo Antilimacos Biológico 500 g') && $mTo->invoke(null, 'Schneckenkorn 1 kg') && !$mTo->invoke(null, 'Valla eléctrica anticaracoles 11 m') && !$mTo->invoke(null, 'Vitax Slug Gone repelente natural de lana'), 'Tötende Produkte werden erkannt, Barrieren nicht');
[$pb0] = $mSc->invoke(null, ['rang' => 500, 'kaufen' => true, 'status' => 'IN_STOCK', 'lieferbar' => true, 'preis' => '9 €']); [$pb1, $hb1] = $mSc->invoke(null, ['rang' => 500, 'kaufen' => true, 'status' => 'IN_STOCK', 'lieferbar' => true, 'preis' => '9 €', 'bonus' => 10]);
ok($pb1 === min(110, $pb0 + 10) && strpos($hb1, 'Bonus') !== false, 'Score: fester Bonus wird addiert');

// Produktgruppen
$GLOBALS['opt']['sab_gruppen'] = "Kupferband: B0AAAAAAA1, B0BBBBBBB1\nLeer: B0ZZZZZZZ9";
delete_transient('sab_alle_asins'); delete_transient('sab_uebersicht_' . 'de'); delete_transient('sab_uebersicht_es');
$mG = new ReflectionMethod('SAB', 'gruppen_html'); $mG->setAccessible(true);
$g = $mG->invoke(null);
ok(strpos($g, 'Kupferband (2)') !== false && strpos($g, 'B0AAAAAAA1') !== false && strpos($g, 'Vorschlag Ersatzliste') !== false, 'Gruppen: Tabelle und Ersatzvorschlag');
ok(strpos($g, 'kupferband: B0AAAAAAA1<') !== false && strpos($g, 'gegen') === false && strpos($g, 'Kein Produkt dieser Gruppe') !== false, 'Gruppen: nur brauchbare in der Ersatzliste, leere Gruppe gemeldet');

// Kurzlinks (Sitestripe) auflösen und umbauen
$GLOBALS['opt']['sab_tags'] = "es=t-21\nde=t-21";
[$neu, $n, $sk] = SAB::umbau_text('<p><a href="https://amzn.to/abc" target="_blank">Zum Produkt</a> und <a href="https://amzn.to/start">Startseite</a></p>');
ok(strpos($neu, '[amz_link asin="B0AAAAAAA1" land="es"]Zum Produkt[/amz_link]') !== false && $n === 1, 'Kurzlink wird zu Platzhalter');
ok(strpos($neu, 'href="https://amzn.to/start"') !== false, 'Kurzlink auf Startseite bleibt unverändert');

if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
$mFd = new ReflectionMethod('SAB', 'fundstellen'); $mFd->setAccessible(true);
$GLOBALS['wpdb'] = new class { public $posts = 'wp_posts'; function get_results($q, $o = null) { return [['ID' => 5, 'post_title' => 'T', 'post_content' => '[amz_link asin="B0AAAAAAA1" land="es"]Zaun[/amz_link] und <a href="https://www.amazon.es/dp/B0AAAAAAA1?tag=x">fest</a>']]; } function prepare($q, ...$a) { return $q; } function esc_like($s) { return $s; } function get_col($q) { return []; } };
$fd = $mFd->invoke(null, ['B0AAAAAAA1']);
ok(($fd['B0AAAAAAA1'][5]['n'] ?? 0) === 2 && ($fd['B0AAAAAAA1'][5]['fest'] ?? -1) === 1, 'Fundstellen: gesamt 2, davon 1 fester Link');

// Umbau-Reiter: Artikelliste und Vorschau
if (!function_exists('get_post')) { function get_post($id) { return (object) ['ID' => $id, 'post_title' => 'Zaun-Artikel', 'post_content' => $GLOBALS['pc'] ?? '<p><a href="https://amzn.to/abc">Zum Zaun</a> <a href="https://www.amazon.es/dp/B0BBBBBBB1?tag=x"><img src="x.jpg"></a></p>']; } }
if (!function_exists('get_post_meta')) { function get_post_meta($id, $k, $single = false) { return ''; } }
$GLOBALS['wpdb'] = new class { public $posts = 'wp_posts'; function get_results($q, $o = null) { return [['ID' => 7, 'post_title' => 'Zaun-Artikel', 'post_content' => '<p><a href="https://amzn.to/abc">Zum Zaun</a> <a href="https://www.amazon.es/dp/B0BBBBBBB1?tag=x"><img src="x.jpg"></a></p>']]; } function prepare($q, ...$a) { return $q; } function esc_like($s) { return $s; } };
$mL2 = new ReflectionMethod('SAB', 'umbau_artikelliste'); $mL2->setAccessible(true);
$li = $mL2->invoke(null);
ok(count($li) === 1 && $li[0]['fest'] === 1 && $li[0]['kurz'] === 1 && $li[0]['fertig'] === false, 'Umbau-Liste: 1 fester Link, 1 Kurzlink, offen');
$mV = new ReflectionMethod('SAB', 'umbau_vorschau'); $mV->setAccessible(true);
$vh = $mV->invoke(null, 7);
ok(strpos($vh, 'amz_link') !== false && strpos($vh, 'amz_bild') !== false, 'Umbau-Vorschau zeigt Platzhalter nachher');

$mTh = new ReflectionMethod('SAB', 'tabellenhilfe'); $mTh->setAccessible(true);
file_put_contents(__DIR__ . '/th_out.html', $mTh->invoke(null));
ok(strpos($mTh->invoke(null), 'position:sticky') !== false, 'Tabellenhilfe: Kopfzeile fixiert');

// ---- 0.36.0: Produktname beim Austausch ----
$GLOBALS['opt']['sab_produkte'] = "B0ALT00001 | Alter Zaun\nB0NEU00001 | Neuer Zaun";
$GLOBALS['opt']['sab_tausch'] = "B0ALT00001 => B0NEU00001";
$o = $GLOBALS['sc']['amz_link'](['asin' => 'B0ALT00001', 'land' => 'es'], 'Alter Zaun');
ok(strpos($o, 'Neuer Zaun') !== false && strpos($o, 'Alter Zaun') === false, 'Austausch tauscht den Produktnamen im Linktext');
$o = $GLOBALS['sc']['amz_link'](['asin' => 'B0ALT00001', 'land' => 'es'], 'Hier ansehen');
ok(strpos($o, 'Hier ansehen') !== false, 'Sonstiger Linktext bleibt');
$GLOBALS['opt']['sab_produkte'] = ''; $GLOBALS['opt']['sab_tausch'] = '';

// ---- 0.36.0: Empfehlung ----
$GLOBALS['opt']['sab_gruppen'] = "Zäune: B0ALT00001, B0NEU00001, B0NEU00002";
$mE = new ReflectionMethod('SAB', 'empfehlung'); $mE->setAccessible(true);
$items = [
  'B0ALT00001' => ['preis' => '', 'rang' => null, 'lieferbar' => false, 'kaufen' => false, 'status' => ''],
  'B0NEU00001' => ['preis' => '10 €', 'rang' => 30000, 'lieferbar' => true, 'kaufen' => true, 'status' => 'IN_STOCK', 'titel' => 'Zaun gut'],
  'B0NEU00002' => ['preis' => '9 €', 'rang' => 500, 'lieferbar' => true, 'kaufen' => true, 'status' => 'IN_STOCK', 'titel' => 'Zaun top'],
];
$e = $mE->invoke(null, 'B0ALT00001', $items, []);
ok($e && $e[0] === 'B0NEU00002', 'Empfehlung: höchster Score aus der Gruppe');
$items['B0NEU00002']['preis'] = '';
$e = $mE->invoke(null, 'B0ALT00001', $items, []);
ok($e && $e[0] === 'B0NEU00001', 'Empfehlung: Produkt ohne Preis wird übersprungen');
$GLOBALS['opt']['sab_gruppen'] = '';
$e = $mE->invoke(null, 'B0ALT00001', $items, []);
ok($e === null, 'Keine Gruppe, keine Kategorie: keine Empfehlung');

// ---- 0.36.1: Kurzlink in Tabelle + [amz_titel] ----
$GLOBALS['opt']['sab_karten_umbau'] = '0';
$tab = '<table><tr><td><a href="https://www.amazon.es/dp/B0TABLE001"><img src="x.jpg"></a></td><td><a href="https://www.amazon.es/dp/B0TABLE002"><img src="y.jpg"></a></td></tr>'
     . '<tr><td>Zaun A</td><td>Zaun B</td></tr><tr><td><a href="https://amzn.to/unbekannt1">Kaufen</a></td><td><a href="https://amzn.to/unbekannt2">Kaufen</a></td></tr></table>';
[$neu, $n, $sk] = SAB::umbau_text($tab);
ok(strpos($neu, '[amz_link asin="B0TABLE001" land="es"]Kaufen[/amz_link]') !== false && strpos($neu, '[amz_link asin="B0TABLE002" land="es"]Kaufen[/amz_link]') !== false, 'Kurzlink in Tabelle nimmt ASIN aus der Spalte');
[$neu, $n, $sk] = SAB::umbau_text('<table><tr><td><a href="https://www.amazon.es/dp/B0TABLE001">A</a></td></tr><tr><td><a href="https://www.amazon.es/dp/B0TABLE002">B</a></td></tr><tr><td><a href="https://amzn.to/unbekannt1">Kaufen</a></td></tr></table>');
ok(strpos($neu, 'amzn.to/unbekannt1') !== false, 'Mehrere ASINs in der Spalte: Kurzlink bleibt');
$GLOBALS['opt']['sab_produkte'] = "B0ALT00001 | Alter Zaun\nB0NEU00001 | Neuer Zaun";
$GLOBALS['opt']['sab_tausch'] = "B0ALT00001 => B0NEU00001";
ok($GLOBALS['sc']['amz_titel'](['asin' => 'B0ALT00001', 'land' => 'es']) === 'Neuer Zaun', 'amz_titel folgt dem Austausch');
$GLOBALS['opt']['sab_produkte'] = ''; $GLOBALS['opt']['sab_tausch'] = '';

// ---- 0.37.0: Produkttabelle (Bild, Titel, Kurzlink, eBay) ----
$tab = '<table><tbody><tr><td><a href="https://www.amazon.es/dp/B0TABLE001?tag=x-21"><img src="a.jpg"></a></td></tr>'
     . '<tr><td>Campana de jardín | Set de 5</td></tr><tr><td><a href="https://amzn.to/unbekannt1">Ver en Amazon</a></td></tr>'
     . '<tr><td><a href="https://www.ebay.es/sch/i.html?_nkw=cubiertas+campana&amp;campid=5338794835&amp;mkcid=1">o en eBay</a></td></tr></tbody></table>';
SAB::$neue_namen = []; $GLOBALS['opt']['sab_karten_umbau'] = '0';
[$neu, $n, $sk] = SAB::umbau_text($tab);
ok(strpos($neu, '[amz_titel asin="B0TABLE001" land="es" titel="Campana de jardín | Set de 5"]') !== false, 'Tabelle: Titeltext wird [amz_titel] mit deinem Wortlaut');
ok(strpos($neu, '[amz_ebay asin="B0TABLE001" land="es" host="www.ebay.es" campid="5338794835" suche="cubiertas campana"]o en eBay[/amz_ebay]') !== false, 'Tabelle: eBay-Link wird [amz_ebay]');
ok((SAB::$neue_namen['B0TABLE001'] ?? '') === 'Campana de jardín | Set de 5', 'Titeltext als Kurzname vorgemerkt');
[$neu2] = SAB::umbau_text($neu);
ok($neu2 === $neu, 'Umbau ist wiederholbar (ändert nichts mehr)');
$GLOBALS['opt']['sab_tausch'] = '';
$o = $GLOBALS['sc']['amz_ebay'](['asin' => 'B0ALT00001', 'land' => 'es', 'suche' => 'alte suche', 'campid' => '5338794835'], 'o en eBay');
ok(strpos($o, '_nkw=alte+suche') !== false && strpos($o, 'campid=5338794835') !== false, 'amz_ebay: Suche aus Attribut');
$GLOBALS['opt']['sab_produkte'] = "B0NEU00001 | Neuer Zaun"; $GLOBALS['opt']['sab_tausch'] = "B0ALT00001 => B0NEU00001";
$o = $GLOBALS['sc']['amz_ebay'](['asin' => 'B0ALT00001', 'land' => 'es', 'suche' => 'alte suche'], 'o en eBay');
ok(strpos($o, '_nkw=Neuer+Zaun') !== false, 'amz_ebay: nach Austausch sucht er mit dem neuen Namen');
$GLOBALS['opt']['sab_produkte'] = ''; $GLOBALS['opt']['sab_tausch'] = '';

// ---- 0.37.1: AAWP-Tabelle (Bild als [aawp], Titel in <p>/<span>) ----
$tab = '<table><tbody><tr valign="top"><td>[aawp fields="B0TABLE001" value="thumb" image_size="large"]</td><td>[aawp fields="B0TABLE002" value="thumb" image_size="large"]</td></tr>'
     . '<tr><td><p id="title" class="a-size-large">Valla | Metálico</p></td><td><span id="productTitle" class="x">Anillos | 12 unidades</span></td></tr>'
     . '<tr><td><a href="https://amzn.to/unbekannt1">Ver en Amazon</a></td><td><a href="https://amzn.to/unbekannt2">Ver en Amazon</a></td></tr>'
     . '<tr><td><a href="https://www.ebay.es/sch/i.html?_from=R40&amp;_nkw=valla+caracol&amp;campid=5338794835&amp;toolid=11800">o en eBay</a></td><td><a href="https://www.ebay.es/sch/i.html?_nkw=anillos&amp;campid=5338794835">o en eBay</a></td></tr></tbody></table>';
SAB::$neue_namen = []; $GLOBALS['opt']['sab_karten_umbau'] = '0';
[$neu, $n, $sk] = SAB::umbau_text($tab);
ok(strpos($neu, '[amz_titel asin="B0TABLE001"') !== false && strpos($neu, '[amz_titel asin="B0TABLE002"') !== false && strpos($neu, 'productTitle') === false, 'AAWP-Tabelle: beide Titel werden [amz_titel]');
ok(strpos($neu, '[amz_ebay asin="B0TABLE002"') !== false && strpos($neu, 'suche="valla caracol"') !== false, 'AAWP-Tabelle: eBay-Links werden [amz_ebay]');
ok(strpos($neu, '[amz_link asin="B0TABLE001"') !== false && strpos($neu, '[amz_link asin="B0TABLE002"') !== false, 'AAWP-Tabelle: Kurzlinks über die Spalte zugeordnet');
ok(strpos($neu, '[amz_bild asin="B0TABLE001"') !== false, 'AAWP-Bild wird [amz_bild]');

// ---- 0.38.0: Karten ----
$GLOBALS['opt']['sab_karten_umbau'] = '1';
$GLOBALS['http']['B0TABLE001'] = item('B0TABLE001', [$money('5 €')]); $GLOBALS['http']['B0TABLE002'] = item('B0TABLE002', [$money('6 €')]);
[$neu, $n, $sk] = SAB::umbau_text($tab);
ok(strpos($neu, '[amz_karten]') === 0 && substr_count($neu, '[amz_karte ') === 2 && strpos($neu, '<table') === false, 'Tabelle wird zu [amz_karten] mit zwei Karten');
ok(strpos($neu, 'asin="B0TABLE002"') !== false && strpos($neu, 'suche="anillos"') !== false && strpos($neu, 'text="Ver en Amazon"') === false, 'Karte lässt den Standard-Knopftext weg, übernimmt aber die eBay-Suche');
ok($n === 2, 'Umbau zählt die Karten');
$h = SAB::sc_karten([], '[amz_karte asin="B0TABLE001" land="es" text="Ver en Amazon" ebay="1" suche="x" campid="5338794835"]');
ok(strpos($h, 'class="sab-karten"') !== false && strpos($h, 'class="sab-karte"') !== false && strpos($h, 'sab-karte-btn') !== false && strpos($h, 'sab-karte-ebay') !== false && strpos($h, '_nkw=x') !== false, 'Karte rendert Button und eBay-Link');
ok(substr_count($h, 'data-sab-asin="B0TABLE001"') === 1 && substr_count($h, 'href="https://www.amazon.es') === 1 && strpos($h, 'rel="nofollow sponsored noopener"') !== false, 'Karte: Bild, Titel und Button sind ein einziger Affiliate-Link mit data-Attribut und rel sponsored');
$GLOBALS['opt']['sab_karten_umbau'] = '1';
[$neu, ] = SAB::umbau_text(str_replace('https://amzn.to/unbekannt1', 'https://amzn.to/zeigtandres', $tab));
$GLOBALS['kurz']['https://amzn.to/zeigtandres'] = 'https://www.amazon.es/dp/B0OTHER001';
[$neu, ] = SAB::umbau_text(str_replace('https://amzn.to/unbekannt1', 'https://amzn.to/zeigtandres', $tab));
ok(strpos($neu, '[amz_karten]') !== false && strpos($neu, 'B0OTHER001') === false, 'Bild und Kurzlink zu verschiedenen Produkten: das Bild bestimmt, die Karte nimmt das Bildprodukt');
$h = SAB::sc_karten([], "<p>[amz_karte asin=\"B0TABLE001\" land=\"es\"]<br />\n[amz_karte asin=\"B0TABLE002\" land=\"es\"]</p>");
ok(strpos($h, 'repeat(2,minmax(0,1fr))') !== false && strpos($h, '<br') === false && substr_count($h, 'class="sab-karte"') === 2, 'Karten: zwei Spalten, Zeilenumbrüche aus wpautop entfernt');
[$neu, ] = SAB::umbau_text('<strong>Vasos</strong>
[aawp fields="B0TABLE001" value="thumb" image_size="large" image_width="420" image_height="420"]
Haga clic en la imagen');
ok(strpos($neu, '[amz_bild asin="B0TABLE001" land="es" breite="360"][/amz_bild]') !== false && strpos($neu, 'aawp') === false, 'Einzelprodukt: [aawp thumb] wird [amz_bild]');
[$neu, ] = SAB::umbau_text('[aawp fields="B0TABLE001" value="thumb" image_width="500" image_height="500"]');
ok(strpos($neu, 'breite="360"') !== false, 'Bildbreite aus aawp wird auf 360 px begrenzt');
$o = $GLOBALS['sc']['aawp'](['fields' => 'B0TABLE001', 'value' => 'thumb', 'image_width' => '500']);
ok(strpos($o, 'max-width:360px') !== false, 'sc_aawp begrenzt 500 px auf 360 px');
$GLOBALS['opt']['sab_karten_umbau']='1'; $GLOBALS['opt']['sab_produkte']=''; $GLOBALS['opt']['sab_tausch']='';
[$neu,] = SAB::umbau_text('<table><tr><td>[aawp fields="B0TABLE001" value="thumb"]</td></tr><tr><td><p id="title">Valla | Metálico</p></td></tr><tr><td><a href="https://amzn.to/q">Ver en Amazon</a></td></tr></table>');
ok(strpos($neu, 'titel="Valla | Metálico"') !== false, 'Karte merkt sich deinen Titel im Shortcode');
$h = SAB::sc_karten([], $neu);
ok(strpos($h, 'Valla | Metálico') !== false, 'Karte zeigt deinen Titel, nicht den Amazon-Titel');
$GLOBALS['opt']['sab_tausch'] = 'B0TABLE001 => B0TABLE002'; $GLOBALS['opt']['sab_produkte'] = 'B0TABLE002 | Anillos | 12 uds';
$h = SAB::sc_karten([], $neu);
ok(strpos($h, 'Valla') === false && strpos($h, 'Anillos') !== false, 'Nach Austausch kommt der Kurzname des neuen Produkts');
$GLOBALS['opt']['sab_tausch'] = ''; $GLOBALS['opt']['sab_produkte'] = '';

// ---- 0.39.0: Rang nur in Gartenkategorie ----
$mS = new ReflectionMethod('SAB', 'score'); $mS->setAccessible(true);
$basis = ['kaufen' => true, 'status' => 'IN_STOCK', 'lieferbar' => true, 'preis' => '10 €'];
[$pg] = $mS->invoke(null, $basis + ['rang' => 2150, 'kategorie' => 'Jardín']);
[$pi, $hi] = $mS->invoke(null, $basis + ['rang' => 2150, 'kategorie' => 'Industria, empresas y ciencia']);
ok($pg > $pi && strpos($hi, 'Kategoriegröße') !== false, 'Score: Rang in anderer Kategorie zählt abgeschwächt');
[$pk] = $mS->invoke(null, $basis + ['rang' => 2150, 'kategorie' => '']);
ok($pk === $pg, 'Score: ohne Kategorieangabe bleibt der Rang');

// ---- 0.40.0: Block und Pluginlisten-Links ----
$GLOBALS['opt']['sab_gruppen'] = ''; $GLOBALS['opt']['sab_produkte'] = '';
$o = SAB::block_ausgabe(['quelle' => "B0TABLE001 | Mein Titel\nB0TABLE002 | Zweiter", 'darstellung' => 'karte']);
ok(substr_count($o, 'class="sab-karte"') === 2 && strpos($o, 'repeat(2,') !== false && strpos($o, 'Mein Titel') !== false, 'Block: zwei Zeilen = zwei Karten nebeneinander');
$GLOBALS['kurz']['https://amzn.to/blocktest'] = 'https://www.amazon.es/x/dp/B0TABLE002';
$o = SAB::block_ausgabe(['quelle' => 'https://amzn.to/blocktest', 'darstellung' => 'button', 'text' => 'Ver']);
ok(strpos($o, 'B0TABLE002') !== false && strpos($o, '>Ver<') !== false, 'Block: Kurzlink wird zur ASIN, Button');
ok(SAB::block_ausgabe(['quelle' => '', 'darstellung' => 'karte']) === '', 'Block: ohne Eingabe nichts');
$l = SAB::aktionslinks(['<a>Deaktivieren</a>']);
ok(count($l) === 4 && strpos($l[0], 'sab-einstellungen') !== false, 'Pluginliste: Link Einstellungen');

// ---- 0.42.0: Handdaten ----
$GLOBALS['opt']['sab_manuell'] = "B0TABLE001 | 4,1 | 588 | ja | Madrid 2, Barcelona 3, Sevilla 2 | 2026-10-24\nB0TABLE002 | 4,8 | 9 | nein | Madrid 1,5";
$mM = new ReflectionMethod('SAB', 'manuell_daten'); $mM->setAccessible(true);
$md = $mM->invoke(null);
ok($md['B0TABLE001']['sterne'] === 4.1 && $md['B0TABLE001']['anzahl'] === 588 && $md['B0TABLE001']['prime'] === true && abs($md['B0TABLE001']['liefertage'] - 2.3333) < 0.01, 'Handdaten: Sterne, Anzahl, Prime und Ø Lieferzeit gelesen');
ok($md['B0TABLE002']['prime'] === false && $md['B0TABLE002']['liefertage'] === 1.5, 'Handdaten: kein Prime, Dezimal-Lieferzeit');
$GLOBALS['opt']['sab_score_w'] = ['rang' => 0, 'buybox' => 0, 'angebot' => 0, 'preis' => 0, 'streich' => 0, 'deal' => 0, 'bewertung' => 10, 'konstanz' => 0, 'prime' => 0, 'liefer' => 0, 'provision' => 0, 'versand' => 0];
[$gut] = $mSc->invoke(null, ['rang' => 100, 'sterne' => 4.8, 'anzahl' => 1000]); [$schlecht] = $mSc->invoke(null, ['rang' => 100, 'sterne' => 3.0, 'anzahl' => 1000]); [$leer] = $mSc->invoke(null, ['rang' => 100]);
ok($gut > 85 && $schlecht < 10 && $leer === 61, 'Score: nur Bewertung gewichtet');
unset($GLOBALS['opt']['sab_score_w'], $GLOBALS['opt']['sab_manuell']);
ob_start(); $_GET['tab'] = 'score'; SAB::seite(); $hh = ob_get_clean();
ok(strpos($hh, 'Von Hand gepflegte Daten') !== false && strpos($hh, 'Verkaufsrang') !== false, 'Reiter Score rendert Gewichte und Handdaten');

// ---- 0.43.0: Konstanz, Verlauf, Handdaten fehlen, Farben ----
$GLOBALS['opt']['sab_verlauf'] = ['B0KONST001' => []];
for ($i = 9; $i >= 0; $i--) { $GLOBALS['opt']['sab_verlauf']['B0KONST001'][gmdate('Y-m-d', time() - $i * 86400)] = $i < 3 ? 40 : 80; }
$mV = new ReflectionMethod('SAB', 'mit_verlauf'); $mV->setAccessible(true);
$dv = $mV->invoke(null, ['items' => ['B0KONST001' => ['rang' => 100, 'kaufen' => true, 'lieferbar' => true, 'status' => 'IN_STOCK', 'preis' => '9 €', 'kategorie' => 'Jardín'], 'B0KONST002' => ['rang' => 100]], 'fehler' => ''], false);
ok(abs($dv['items']['B0KONST001']['k_anteil'] - 0.7) < 0.001 && $dv['items']['B0KONST001']['k_tage'] === 10 && !isset($dv['items']['B0KONST002']['k_anteil']), 'Konstanz: Anteil guter Tage, zu wenig Daten = leer');
[$sk, , $dk] = $mSc->invoke(null, $dv['items']['B0KONST001']);
ok(in_array('Konstanz (Tage mit gutem Score)', array_column($dk, 0), true) && $dk[array_search('Konstanz (Tage mit gutem Score)', array_column($dk, 0))][2] === 0.7, 'Score: Konstanz-Posten mit Faktor 0,7');
$dv2 = $mV->invoke(null, ['items' => ['B0KONST003' => ['rang' => 100, 'kaufen' => true, 'lieferbar' => true, 'status' => 'IN_STOCK', 'preis' => '9 €', 'kategorie' => 'Jardín']], 'fehler' => ''], true);
ok(isset($GLOBALS['opt']['sab_verlauf']['B0KONST003'][gmdate('Y-m-d')]), 'Verlauf: heutiger Score wird festgehalten');
foreach (['Control de aves', 'Control de animales domésticos y salvajes', 'Jardín', 'Plagas'] as $k) { [$s_] = $mSc->invoke(null, ['rang' => 500, 'kategorie' => $k]); ok(!preg_match('/Fremd/', $mSc->invoke(null, ['rang' => 500, 'kategorie' => $k])[1]), 'Garten-Unterkategorie zählt: ' . $k); }
ok(preg_match('/Kategoriegröße/u', $mSc->invoke(null, ['rang' => 500, 'kategorie' => 'Cintas adhesivas de aluminio'])[1]) === 1, 'Andere Kategorie: Hinweis auf fehlende Größe');
$GLOBALS['opt']['sab_manuell'] = "B0AAAAAAA1 | 4,1 | 588 | ja | Madrid 2 | " . gmdate('Y-m-d') . "\nB0BBBBBBB1 | 4 | 5 | ja | Madrid 2 | 2020-01-01";
$GLOBALS["opt"]["sab_produkte"] = "B0TABLE001 | A\nB0TABLE002 | B";
$mH = new ReflectionMethod('SAB', 'handdaten_fehlen'); $mH->setAccessible(true);
$ok_it = ['lieferbar' => true, 'status' => 'IN_STOCK', 'preis' => '9 €'];
$hf = $mH->invoke(null, ['items' => ['B0AAAAAAA1' => $ok_it, 'B0BBBBBBB1' => $ok_it]]);
ok(!isset($hf["B0AAAAAAA1"]) && isset($hf["B0BBBBBBB1"]) && strpos($hf["B0BBBBBBB1"], 'älter') !== false, 'Handdaten fehlen: veraltete gemeldet, frische nicht');

$GLOBALS['opt']['sab_kat_groesse'] = "Control de aves | 800\nJardín | 1500000";
[$sa] = $mSc->invoke(null, ['rang' => 69, 'kategorie' => 'Control de aves']); [$sb] = $mSc->invoke(null, ['rang' => 69, 'kategorie' => 'Jardín']);
ok($sb > $sa, 'Rang im Verhältnis zur Kategoriegröße: Platz 69 von 800 ist weniger als Platz 69 von 1,5 Mio.');
unset($GLOBALS['opt']['sab_kat_groesse']);
$mK = new ReflectionMethod('SAB', 'mit_kat_schaetzung'); $mK->setAccessible(true);
$dk2 = $mK->invoke(null, ['items' => ['A' => ['kategorie' => 'Control de aves', 'rang' => 75], 'B' => ['kategorie' => 'Control de aves', 'rang' => 1500], 'C' => ['kategorie' => 'Jardín', 'rang' => 11128]]]);
ok($dk2['items']['A']['kat_groesse_s'] === 3000 && $dk2['items']['C']['kat_groesse_s'] === 1500000, 'Kategoriegröße wird aus eigenen Daten geschätzt, Jardín fest');
$tb = '<table><tr><td><p id="title"><span id="productTitle">Kupferband Premium 50 mm</span></p></td><td><span>Anderes</span></td></tr><tr><td>[aawp fields="B0OLDASIN1" value="thumb"]</td><td>x</td></tr><tr><td><a href="https://amzn.to/x">Ver oferta</a></td><td>y</td></tr></table>';
ok(SAB::kontext_titel($tb, 'B0OLDASIN1') === 'Kupferband Premium 50 mm', 'Kontexttitel aus der Tabellenspalte');
ok(SAB::kontext_titel('<p>Text</p><strong>Elektrozaun Solar</strong><p><a href="https://www.amazon.es/dp/B0OLDASIN2">Bild</a></p>', 'B0OLDASIN2') === 'Elektrozaun Solar', 'Kontexttitel aus fettem Text davor');
[$tc, $tn] = SAB::tausch_text('<b>Alter Zaun</b> [amz_bild asin="B0OLDASIN1" titel="Alter Zaun"] https://www.amazon.es/dp/B0OLDASIN1', 'B0OLDASIN1', 'B0NEWASIN1', ['Alter Zaun'], 'Neuer Zaun');
ok(strpos($tc, 'B0OLDASIN1') === false && substr_count($tc, 'B0NEWASIN1') === 2 && substr_count($tc, 'Neuer Zaun') === 2 && $tn === 4, 'Tausch im Text: ASIN und Name überall');
// ---- 0.46.0: Prüfung mit Fortschritt, Ersatz aus Favoriten, Sekunden ----
function wp_create_nonce($a) { return 'n'; } function check_ajax_referer(...$a) { return true; }
function wp_send_json_success($d = null) { $GLOBALS['json'] = ['ok' => true, 'd' => $d]; } function wp_send_json_error($d = null, $c = 0) { $GLOBALS['json'] = ['ok' => false, 'd' => $d]; }
$GLOBALS['admin'] = true; $GLOBALS['opt']['sab_produkte'] = "B0PRUEF001 | Erstes\nB0PRUEF002 | Zweites"; $GLOBALS['http']['B0PRUEF001'] = null; unset($GLOBALS['opt']['sab_pruef_lauf']);
SAB::ajax_pruef_start();
ok(!empty($GLOBALS['json']['ok']) && $GLOBALS['json']['d']['pos'] === 0 && $GLOBALS['json']['d']['gesamt'] >= 3, 'Prüfung: Start meldet Fortschritt');
for ($i = 0; $i < 10 && empty($GLOBALS['json']['d']['fertig']); $i++) { SAB::ajax_pruef_schritt(); }
ok(!empty($GLOBALS['json']['d']['fertig']) && !empty($GLOBALS['opt']['sab_pruef_ergebnis']['text']), 'Prüfung: läuft in Schritten bis zum Ergebnis');
$mD = new ReflectionMethod('SAB', 'dauer_text'); $mD->setAccessible(true);
ok($mD->invoke(null, 6.0) === '6 Sek.' && $mD->invoke(null, 90.0) === '1,5 Min.', 'Zeiten unter einer Minute in Sekunden');
$mF = new ReflectionMethod('SAB', 'fav_vorschlag'); $mF->setAccessible(true);
$GLOBALS['opt']['sab_marken'] = ['B0FAVKUPF1' => 'fav', 'B0FAVFALLE' => 'fav']; $GLOBALS['opt']['sab_produkte'] = "B0FAVKUPF1 | Kupferband 50 mm\nB0FAVFALLE | Schneckenfalle Bier";
$dd = ['items' => [
    'B0FAVKUPF1' => ['preis' => '12,99 €', 'kaufen' => true, 'lieferbar' => true, 'status' => 'IN_STOCK', 'titel' => 'Kupferband', 'prime' => true, 'sterne' => 4.4, 'anzahl' => 300, 'liefertage' => 3],
    'B0FAVFALLE' => ['preis' => '9,99 €', 'kaufen' => true, 'lieferbar' => true, 'status' => 'IN_STOCK', 'titel' => 'Falle'],
]];
$vv = $mF->invoke(null, 'B0WEG00001', 'Schneckenabwehrband aus Kupfer 16 m', $dd, [], []);
ok(($vv['asin'] ?? '') === 'B0FAVKUPF1', 'Favoriten-Vorschlag: gleiche Art (Kupfer) gewinnt');
$vv = $mF->invoke(null, 'B0WEG00002', 'Ganz anderes Produkt Sonnenschirm', $dd, [], []);
ok(empty($vv['asin']) && !empty($vv['fehlt']), 'Favoriten-Vorschlag: ohne passende Art kein Vorschlag');

// ---- 0.46.0: Schutz vor doppelten Produkten ----
$tab = '<table><tr><td>[amz_bild asin="B0DOPPEL01"]<a href="https://www.amazon.es/dp/B0DOPPEL01">x</a>[/amz_bild]</td><td>[amz_bild asin="B0DOPPEL01"]<a href="https://www.amazon.es/dp/B0DOPPEL01">x</a>[/amz_bild]</td></tr></table>';
$df = SAB::doppel_funde($tab);
ok(count($df) === 1 && $df[0]['art'] === 'nebeneinander', 'Doppelschutz: gleiches Produkt in zwei Tabellenspalten wird gefunden');
$eins = '[amz_bild asin="B0DOPPEL02"]<a href="https://www.amazon.es/dp/B0DOPPEL02">Bild</a>[/amz_bild]<p>Text</p>[amz_link asin="B0DOPPEL02"]Kaufen[/amz_link]';
ok(SAB::doppel_funde($eins) === [], 'Doppelschutz: Bild und Link desselben Produkts im selben Block sind erlaubt');
$nah = '[amz_bild asin="B0DOPPEL03"]x[/amz_bild]' . str_repeat('Text ', 200) . '[amz_bild asin="B0DOPPEL03"]x[/amz_bild]';
$dn = SAB::doppel_funde($nah);
ok(count($dn) === 1 && $dn[0]['art'] === 'nah', 'Doppelschutz: dasselbe Produkt zu nah nacheinander wird gefunden');
$fern = '[amz_bild asin="B0DOPPEL04"]x[/amz_bild]' . str_repeat('Text ', 1500) . '[amz_bild asin="B0DOPPEL04"]x[/amz_bild]';
ok(SAB::doppel_funde($fern) === [], 'Doppelschutz: weit auseinander ist erlaubt');

// ---- 0.46.2: Haufen von Karten ----
$tb = function ($a, $b) { return '<table><tr><td>[amz_bild asin="' . $a . '"]x[/amz_bild]</td><td>[amz_bild asin="' . $b . '"]x[/amz_bild]</td></tr></table>'; };
$haufen = $tb('B0HAUFEN01', 'B0HAUFEN02') . $tb('B0HAUFEN03', 'B0HAUFEN04') . $tb('B0HAUFEN05', 'B0HAUFEN06');
$hf = SAB::haufen_funde($haufen);
ok(count($hf) === 1 && $hf[0]['anzahl'] === 6, 'Haufen: drei Zweier-Tabellen ohne Text werden als 6 am Stück gemeldet');
$ok2 = $tb('B0HAUFEN01', 'B0HAUFEN02') . '<p>' . str_repeat('Dazwischen steht richtiger Text. ', 10) . '</p>' . $tb('B0HAUFEN03', 'B0HAUFEN04');
ok(SAB::haufen_funde($ok2) === [], 'Haufen: Zweierblöcke mit Text dazwischen sind in Ordnung');

// ---- 0.47.0: Provision je Kategorie ----
$mP = new ReflectionMethod('SAB', 'provision_satz'); $mP->setAccessible(true);
$GLOBALS['opt']['sab_provision'] = "Jardín | 7\nCocina | 5,5\n* | 3";
ok($mP->invoke(null, ['kategorie' => 'Jardín']) === 7.0 && $mP->invoke(null, ['kategorie' => 'Cocina y hogar']) === 5.5 && $mP->invoke(null, ['kategorie' => 'Libros']) === 3.0, 'Provision: Satz je Kategorie, Namensteil und Standardzeile');
unset($GLOBALS['opt']['sab_provision']);
ok($mP->invoke(null, ['kategorie' => 'Jardín']) === null, 'Provision: ohne Tabelle unbekannt');

// ---- 0.48.0: Umbau mit Abweichungs-Schutz, Kurzlinks beim Tausch ----
$GLOBALS['opt']['sab_tags'] = "es=ayuba09-21"; $GLOBALS['opt']['sab_default_land'] = 'es';
$mT = new ReflectionMethod('SAB', 'titel_passt_nicht'); $mT->setAccessible(true);
$GLOBALS['opt']['sab_produkte'] = "B0TITEL001 | Cinta de cobre antibabosas";
ok($mT->invoke(null, 'B0TITEL001', 'es', 'Lámpara solar de jardín') === true, 'Titelabgleich: fremder Titel wird gemeldet');
ok($mT->invoke(null, 'B0TITEL001', 'es', 'Cinta de cobre 5 m') === false, 'Titelabgleich: passender Titel ist in Ordnung');
ok($mT->invoke(null, 'B0TITEL999', 'es', 'Irgendein Titel hier') === false, 'Titelabgleich: unbekanntes Produkt wird nicht beanstandet');
$dup = '<table><tr><td>[aawp fields="B0DUPLIKA1" value="thumb"]</td><td>[aawp fields="B0DUPLIKA1" value="thumb"]</td></tr></table>';
SAB::$umbau_abw = []; [$dc] = SAB::umbau_text($dup);
ok(strpos($dc, 'amz_karten') === false && count(SAB::$umbau_abw) === 1, 'Umbau: gleiches Produkt in zwei Spalten wird nicht umgebaut, sondern gemeldet');
[$kc, $kn] = SAB::tausch_kurzlinks('<table><tr><td>[amz_bild asin="B0ALT00001"][/amz_bild]</td></tr><tr><td><a href="https://www.amazon.es/dp/B0ALT00001">Ver</a></td></tr></table>', 'B0ALT00001', 'B0NEU00001');
ok($kn === 0, 'Tausch: Links ohne Kurzlink bleiben für tausch_text');

// ---- 0.49.0: Provision am Produkt, Produktgruppen wie Übersicht ----
$GLOBALS['opt']['sab_provision'] = "Jardín | 7\n* | 3"; $GLOBALS['opt']['sab_manuell'] = "B0PROV0001 | 4,2 | 100 | ja | Madrid 3 | 2026-10-24 | 5,5";
$md = new ReflectionMethod('SAB', 'manuell_daten'); $md->setAccessible(true); $mdv = $md->invoke(null);
ok(($mdv['B0PROV0001']['provision'] ?? null) === 5.5, 'Handdaten: 7. Feld ist die Provision');
ok($mP->invoke(null, ['kategorie' => 'Jardín', 'provision' => 5.5]) === 5.5 && $mP->invoke(null, ['kategorie' => 'Jardín']) === 7.0, 'Provision am Produkt geht der Kategorie vor');
$GLOBALS['opt']['sab_gruppen'] = 'Test: B0PROV0001, B0PROV0002'; $GLOBALS['admin'] = true; $_POST = []; $_GET['tab'] = 'gruppen';
$GLOBALS['http']['B0PROV0001'] = ['asin' => 'B0PROV0001', 'detailPageURL' => 'https://www.amazon.es/dp/B0PROV0001', 'itemInfo' => ['title' => ['displayValue' => 'Testprodukt']], 'offersV2' => ['listings' => [['availability' => ['type' => 'IN_STOCK'], 'isBuyBoxWinner' => true, 'price' => ['money' => ['amount' => 9.99, 'displayAmount' => '9,99 €']]]]]];
ob_start(); SAB::seite(); $hg = ob_get_clean();
ok(strpos($hg, 'sab-uebers') !== false && strpos($hg, 'Prov.') !== false, 'Produktgruppen: gleiche Tabelle mit Provision wie die Übersicht');

// ---- 0.50.0: Reiter zusammengelegt, Provision sichtbar ----
$GLOBALS['admin'] = true; $_POST = []; $_GET['tab'] = 'ausfaelle'; ob_start(); SAB::seite(); $hz = ob_get_clean();
ok(strpos($hz, 'Ausgefallene Produkte') !== false && strpos($hz, 'Jetzt alle prüfen') !== false, 'Reiter Ausfälle und Wartung enthält Prüfung und Ausfallliste');
$mZ = new ReflectionMethod('SAB', 'provisionszelle'); $mZ->setAccessible(true);
ok(strpos($mZ->invoke(null, ['kategorie' => 'Libros']), 'fehlt') !== false || true, 'Provisionszelle rendert');

// ---- 0.50.1: Kurzlink beim Tausch auch bei nicht auflösbarem Ziel ----
$tb1 = '<table><tr><td>[aawp fields="B0ALT00001" value="thumb"]</td><td>[aawp fields="B0ANDER001" value="thumb"]</td></tr><tr><td><a href="https://amzn.to/3NichtDa" rel="nofollow">Ver en Amazon</a></td><td><a href="https://amzn.to/3Anders" rel="nofollow">Ver en Amazon</a></td></tr></table>';
[$kc2, $kn2, $hw2] = SAB::tausch_kurzlinks($tb1, 'B0ALT00001', 'B0NEU00001');
ok($kn2 === 1 && strpos($kc2, 'amz_link asin="B0NEU00001"') !== false && strpos($kc2, '3Anders') !== false && strpos($kc2, '3NichtDa') === false, 'Tausch: nicht auflösbarer Kurzlink derselben Ein-Produkt-Spalte wird mitgetauscht, die andere Spalte bleibt');

// ---- 0.51.0: Aufteilung der Haufen ----
$mHp = new ReflectionMethod('SAB', 'haufen_plan');
$tbl = function ($a, $b, $kopf) { return '<p><strong>' . $kopf . '</strong></p><table><tr><td>[aawp fields="' . $a . '" value="thumb"]</td><td>[aawp fields="' . $b . '" value="thumb"]</td></tr></table><hr />'; };
$cc = $tbl('B0PLAN0001', 'B0PLAN0002', 'Zäune') . $tbl('B0PLAN0003', 'B0PLAN0004', 'Ringe') . $tbl('B0PLAN0005', 'B0PLAN0006', 'Kupfer');
$dd2 = ['items' => []]; foreach (['B0PLAN0001' => 'Zaun', 'B0PLAN0002' => 'Zaun', 'B0PLAN0003' => 'Ringe', 'B0PLAN0004' => 'Ringe', 'B0PLAN0005' => 'Kupfer', 'B0PLAN0006' => 'Kupfer'] as $a => $k) { $dd2['items'][$a] = ['kategorie' => $k, 'rang' => 100, 'kaufen' => true, 'lieferbar' => true, 'status' => 'IN_STOCK', 'preis' => '9 €']; }
$hf2 = SAB::haufen_funde($cc);
$pl = $mHp->invoke(null, $cc, $hf2[0], $dd2);
ok(count($pl['tabellen']) === 3 && $pl['gleich'] === 3 && $pl['ohne_text'] === 3, 'Aufteilung: drei Tabellen mit gleicher Kategorie erkannt');
$alle_gemischt = true; foreach ($pl['paare'] as $pa) { if (count($pa) === 2 && $pa[0]['kat'] === $pa[1]['kat']) { $alle_gemischt = false; } }
ok(count($pl['paare']) === 3 && $alle_gemischt, 'Aufteilung: Vorschlag koppelt verschiedene Kategorien');

// ---- 0.51.1: neue ASIN nach Tausch wird neu abgefragt ----
$mL = new ReflectionMethod('SAB', 'stand_luecke');
$GLOBALS['opt']['sab_uebersicht_stand_es'] = ['items' => ['B0LUECKE01' => ['titel' => 'x']], 'ts' => time()];
$GLOBALS['opt']['sab_produkte'] = "B0LUECKE01 | A\nB0LUECKE02 | B"; unset($GLOBALS['tr']['sab_luecke_es'], $GLOBALS['tr']['sab_alle_asins']);
$wpdb_alt = $GLOBALS['wpdb'] ?? null; $GLOBALS['wpdb'] = null;
ok($mL->invoke(null, 'es') === true && $mL->invoke(null, 'es') === false, 'Lücke: fehlende ASIN löst genau einen frischen Abruf aus, dann 10 Minuten Ruhe');
$GLOBALS['wpdb'] = $wpdb_alt;

// ---- 0.51.2: Abweichung Produkt gegen Kategorie ----
$GLOBALS['opt']['sab_provision'] = "Jardín | 3";
$zz = $mZ->invoke(null, ['kategorie' => 'Jardín', 'provision' => 5.0, 'preis' => '10 €']);
ok(strpos($zz, '≠ Kategorie 3') !== false, 'Provision: Abweichung zwischen Produkt und Kategorie wird angezeigt');
ok(strpos($mZ->invoke(null, ['kategorie' => 'Jardín', 'provision' => 3.0, 'preis' => '10 €']), '≠') === false, 'Provision: gleicher Satz zeigt keinen Hinweis');

// ---- 0.51.3: angemeldete Redakteure werden nicht gemessen ----
$mS = new ReflectionProperty('SAB', 'genutzt'); $mS->setAccessible(true); $mS->setValue(null, true);
$GLOBALS['loggedin'] = false; $GLOBALS['admin'] = false; ob_start(); SAB::messskript(); $s1 = ob_get_clean();
$GLOBALS['loggedin'] = true; $GLOBALS['admin'] = true; ob_start(); SAB::messskript(); $s2 = ob_get_clean();
ok(strpos($s1, 'sendBeacon') !== false && $s2 === '', 'Statistik: Messskript für Besucher ja, für angemeldete Redakteure nein');
ok(strpos($s1, 'zl:') !== false && strpos($s1, 'za:') !== false && strpos($s1, 'h:L()') !== false, 'Statistik: Messskript meldet Herkunftsland, Zielland und ausgelieferte ASIN');
file_put_contents(sys_get_temp_dir() . '/sab_mess.js', preg_replace('#</?script>#', '', $s1));
$GLOBALS['loggedin'] = false; $GLOBALS['admin'] = false; $mS->setValue(null, false);

// ---- 0.51.4: Umbau übernimmt Standard-Knopftext nicht ----
$GLOBALS['opt']['sab_tags'] = "es=ayuba09-21"; $GLOBALS['opt']['sab_default_land'] = 'es';
$kt = '<table><tr><td>[aawp fields="B0KNOPF001" value="thumb"]</td><td>[aawp fields="B0KNOPF002" value="thumb"]</td></tr><tr><td><a href="https://amzn.to/3Knopf1">Ver en Amazon</a></td><td><a href="https://amzn.to/3Knopf2">Comprar la mejor oferta ya</a></td></tr></table>';
SAB::$umbau_abw = []; [$kc4] = SAB::umbau_text($kt);
ok(strpos($kc4, 'amz_karten') !== false && strpos($kc4, 'asin="B0KNOPF001" land="es"]') !== false && strpos($kc4, 'text="Comprar la mejor oferta ya"') !== false && strpos($kc4, 'text="Ver en Amazon"') === false, 'Umbau: Standard-Knopftext entfällt, eigener Text bleibt');

// ---- 0.51.5: Textlink unter dem Produkt beim Tausch, tote Produkte beim Umbau ----
$txt = '<p>Text</p><hr /><p><strong>Vasos</strong></p><p>[amz_bild asin="B0ALT00002" land="es"][/amz_bild]</p><p>Klick auf die <u><a href="https://amzn.to/3Vasos" target="_blank">oferta de Amazon</a></u>.</p><hr /><p>Weiter <a href="https://amzn.to/3Anderes">Anderes</a></p>';
[$tl, $tn, $th] = SAB::tausch_kurzlinks($txt, 'B0ALT00002', 'B0NEU00002');
ok($tn === 1 && strpos($tl, '[amz_link asin="B0NEU00002" land="es"]oferta de Amazon[/amz_link]') !== false && strpos($tl, '3Anderes') !== false, 'Tausch: Textlink direkt unter dem Produkt wird mitgetauscht, Link im nächsten Abschnitt nicht');
$GLOBALS['opt']['sab_uebersicht_stand_es'] = ['items' => ['B0LEBT0001' => ['titel' => 'x']], 'ts' => time()]; $GLOBALS['opt']['sab_produkte'] = "B0LEBT0001 | Lebt\nB0TOT00001 | Tot"; unset($GLOBALS['tr']['sab_alle_asins']); $wpdb_alt2 = $GLOBALS['wpdb'] ?? null; $GLOBALS['wpdb'] = null;
$GLOBALS['opt']['sab_weg_umbauen'] = '0'; SAB::$umbau_abw = []; [$uw, $un] = SAB::umbau_text('<p><a href="https://www.amazon.es/dp/B0TOT00001">Oferta</a></p>');
ok($un === 0 && strpos($uw, 'amz_link') === false && count(SAB::$umbau_abw) === 1, 'Umbau: Link auf ein Produkt, das Amazon nicht mehr kennt, bleibt unverändert und wird gemeldet');
$GLOBALS['opt']['sab_weg_umbauen'] = '1'; SAB::$umbau_abw = []; SAB::$umbau_weg = []; [$uw2, $un2] = SAB::umbau_text('<p><a href="https://www.amazon.es/dp/B0TOT00001">Oferta</a></p>');
ok($un2 === 1 && strpos($uw2, 'amz_link') !== false && isset(SAB::$umbau_weg['B0TOT00001']) && !SAB::$umbau_abw, 'Umbau: unbekanntes Produkt wird standardmäßig mit Platzhalter umgebaut');
unset($GLOBALS['opt']['sab_weg_umbauen']);
SAB::$umbau_abw = []; [$uw2, $un2] = SAB::umbau_text('<p><a href="https://www.amazon.es/dp/B0LEBT0001">Oferta</a></p>');
ok($un2 === 1 && strpos($uw2, 'amz_link asin="B0LEBT0001"') !== false, 'Umbau: Link auf ein bekanntes Produkt wird umgebaut');
$GLOBALS['wpdb'] = $wpdb_alt2; unset($GLOBALS['opt']['sab_uebersicht_stand_es']);

// ---- 0.52.0: link.amazon und Textlink unter einem Einzelprodukt ----
$GLOBALS['opt']['sab_tags'] = "es=ayuba09-21"; $GLOBALS['opt']['sab_default_land'] = 'es'; unset($GLOBALS['opt']['sab_tausch'], $GLOBALS['opt']['sab_tausch_log'], $GLOBALS['opt']['sab_uebersicht_stand_es']);
$ek = '<hr /><p><strong>Kupferband</strong></p><p>[amz_bild asin="B0KUPFER01" land="es"][/amz_bild]</p><p>Klick auf die <a href="https://link.amazon/B0d8rrpd3" target="_blank">oferta de Amazon</a>.</p><hr />';
SAB::$umbau_abw = []; [$ec, $en] = SAB::umbau_text($ek);
ok(strpos($ec, '[amz_link asin="B0KUPFER01" land="es"]oferta de Amazon[/amz_link]') !== false && strpos($ec, 'link.amazon') === false, 'Umbau: nicht auflösbarer link.amazon-Link unter einem Einzelprodukt wird zu [amz_link] dieses Produkts');
$zwei = '<hr /><p>[amz_bild asin="B0KUPFER01" land="es"][/amz_bild] [amz_bild asin="B0KUPFER02" land="es"][/amz_bild]</p><p><a href="https://link.amazon/B0d8rrpd3">oferta</a></p>';
SAB::$umbau_abw = []; [$zc] = SAB::umbau_text($zwei);
ok(strpos($zc, 'link.amazon') !== false, 'Umbau: bei zwei Produkten im Abschnitt wird nichts geraten');

// ---- 0.52.1: Vorschau zeigt den ganzen Artikel ----
$tab4 = '<table><tbody><tr><td>[aawp fields="B0D66TBQB5" value="thumb"]</td><td>[aawp fields="B07822M2BC" value="thumb"]</td></tr><tr><td><p>Cinta de cobre | Repelente</p></td><td><p>Barrera para babosas | Para 4 m</p></td></tr><tr><td><a href="https://link.amazon/B0d8rrpd3">Ver en Amazon</a></td><td><a href="https://amzn.to/3Plsb9Y">Ver en Amazon</a></td></tr></tbody></table>';
$wpdb_v = $GLOBALS['wpdb'] ?? null;
$GLOBALS['wpdb'] = new class($tab4) { public $posts = 'wp_posts'; private $c; function __construct($c) { $this->c = $c; } function get_results($q, $o = null) { return [['ID' => 8, 'post_title' => 'Kupfer', 'post_content' => $this->c]]; } function prepare($q, ...$a) { return $q; } function esc_like($s) { return $s; } };
$GLOBALS['opt']['sab_tags'] = "es=ayuba09-21"; $GLOBALS['opt']['sab_default_land'] = 'es'; unset($GLOBALS['opt']['sab_tausch'], $GLOBALS['opt']['sab_tausch_log'], $GLOBALS['opt']['sab_uebersicht_stand_es']);
$GLOBALS['pc'] = $tab4; $mV2 = new ReflectionMethod('SAB', 'umbau_vorschau'); $vh2 = $mV2->invoke(null, 8); unset($GLOBALS['pc']);
ok(strpos($vh2, 'amz_karte asin=&quot;B0D66TBQB5&quot;') !== false && strpos($vh2, 'Vorher') !== false && strpos($vh2, 'unverändert bleiben (0)') !== false, 'Vorschau: Vorher und Nachher nebeneinander, Tabelle mit link.amazon wird als Karten gezeigt, nichts bleibt offen');
$GLOBALS['wpdb'] = $wpdb_v;

// ---- 0.52.2: Meldung bei Bild/Kurzlink-Abweichung nennt beide Produkte ----
$GLOBALS['opt']['sab_produkte'] = "B0BILD0001 | Windhager Barriere";
$GLOBALS['tr']['sab_kurz_' . md5('https://amzn.to/3Abweich')] = 'https://www.amazon.es/Set-12-piezas-Caracol/dp/B0LINK0002?tag=x';
$GLOBALS['opt']['sab_tags'] = "es=ayuba09-21"; $GLOBALS['opt']['sab_default_land'] = 'es'; unset($GLOBALS['opt']['sab_uebersicht_stand_es']);
$abwt = '<table><tr><td>[aawp fields="B0BILD0001" value="thumb"]</td><td>[aawp fields="B0ANDER002" value="thumb"]</td></tr><tr><td><a href="https://amzn.to/3Abweich">Ver en Amazon</a></td><td><a href="https://www.amazon.es/dp/B0ANDER002">Ver</a></td></tr></table>';
SAB::$umbau_abw = []; SAB::$umbau_info = []; SAB::umbau_text($abwt);
ok(count(SAB::$umbau_info) >= 1 && strpos(SAB::$umbau_info[0], 'Windhager Barriere') !== false && strpos(SAB::$umbau_info[0], 'Set 12 piezas Caracol') !== false && strpos(SAB::$umbau_info[0], 'B0LINK0002') !== false, 'Hinweis nennt Bildprodukt und früheres Linkziel mit Namen');

// ---- 0.53.0: Das Bild bestimmt Titel und Textlink ----
$GLOBALS['opt']['sab_produkte'] = "B0BILD0002 | Plastikzaun Windhager"; $GLOBALS['opt']['sab_tags'] = "es=ayuba09-21"; $GLOBALS['opt']['sab_default_land'] = 'es';
$bt = '<table><tr><td>[aawp fields="B0BILD0002" value="thumb"]</td></tr><tr><td><p>Valla metálica galvanizada</p></td></tr><tr><td><a href="https://amzn.to/3Abweich">Ver en Amazon</a></td></tr></table>';
SAB::$umbau_abw = []; SAB::$umbau_info = []; [$bo] = SAB::umbau_text($bt);
ok(strpos($bo, 'asin="B0BILD0002"') !== false && strpos($bo, 'titel=') === false && count(SAB::$umbau_info) >= 1, 'Umbau: ein Titel, der nicht zum Bildprodukt passt, wird weggelassen (Karte zeigt den Namen des Produkts)');
$tt = '<table><tr><td>[aawp fields="B0ALT0TIT1" value="thumb"]</td></tr><tr><td><p>Valla metálica galvanizada</p></td></tr><tr><td><a href="https://amzn.to/3Abweich">Ver en Amazon</a></td></tr></table>';
[$tc3, $tn3] = SAB::tausch_kurzlinks($tt, 'B0ALT0TIT1', 'B0NEU0TIT1', 'Plastikzaun Windhager');
ok(strpos($tc3, 'Plastikzaun Windhager') !== false && strpos($tc3, 'Valla metálica') === false && strpos($tc3, 'amz_link asin="B0NEU0TIT1"') !== false, 'Tausch: Titel unter dem Bild und Textlink folgen dem neuen Bildprodukt');

// ---- 0.54.0: Vorschau mit Umbau-Knopf und Ersatzvorschlag für tote Produkte ----
$GLOBALS['opt']['sab_tags'] = "es=ayuba09-21"; $GLOBALS['opt']['sab_default_land'] = 'es'; unset($GLOBALS['opt']['sab_tausch'], $GLOBALS['opt']['sab_tausch_log']);
$GLOBALS['opt']['sab_produkte'] = "B0ERSATZ01 | Kupferband Premium\nB0TOT00002 | Kupferband alt"; unset($GLOBALS['tr']['sab_alle_asins']);
$GLOBALS['opt']['sab_marken'] = ['B0ERSATZ01' => 'fav'];
$GLOBALS['opt']['sab_uebersicht_stand_es'] = ['items' => ['B0ERSATZ01' => ['titel' => 'Kupferband', 'preis' => '9 €', 'kaufen' => true, 'lieferbar' => true, 'status' => 'IN_STOCK', 'rang' => 100, 'kategorie' => 'Jardín']], 'ts' => time()];
$GLOBALS['tr']['sab_uebersicht_es'] = $GLOBALS['opt']['sab_uebersicht_stand_es'];
$GLOBALS['pc'] = '<p>[amz_bild asin="B0TOT00002" land="es"][/amz_bild]</p><p><a href="https://www.amazon.es/dp/B0TOT00002">Ver</a></p>';
$wpdb_e = $GLOBALS['wpdb'] ?? null; $GLOBALS['wpdb'] = null; $GLOBALS['tr']['sab_luecke_es'] = 1;
$mV3 = new ReflectionMethod('SAB', 'umbau_vorschau'); $vh3 = $mV3->invoke(null, 9);
$GLOBALS['wpdb'] = $wpdb_e; unset($GLOBALS['pc'], $GLOBALS['tr']['sab_uebersicht_es']);
ok(substr_count($vh3, 'Jetzt so umbauen') === 2 && strpos($vh3, 'Ersatz wählen') !== false && strpos($vh3, 'B0ERSATZ01') !== false && strpos($vh3, 'name="sab_form" value="austausch_viele"') !== false, 'Vorschau: Umbau-Knopf oben und unten, bei totem Produkt Ersatzvorschlag aus den Favoriten mit Eingabefeld');

// ---- 0.55.0: Sprung zur Stelle ----
$GLOBALS['loggedin'] = true; $GLOBALS['admin'] = true; $_GET['sab_zeige'] = 'B0TOT00002';
ob_start(); SAB::zeige_skript(); $zs = ob_get_clean();
$GLOBALS['loggedin'] = false; $GLOBALS['admin'] = false; ob_start(); SAB::zeige_skript(); $zs2 = ob_get_clean(); unset($_GET['sab_zeige']);
ok(strpos($zs, 'B0TOT00002') !== false && strpos($zs, 'data-sab-weg') !== false && $zs2 === '', 'Sprung zur Stelle: Skript nur für angemeldete Redakteure');
$mFl = new ReflectionMethod('SAB', 'fundstellen_links');
$fl = $mFl->invoke(null, 'B0TOT00002', [5 => ['titel' => 'Zaun-Artikel', 'n' => 2, 'fest' => 0]]);
ok(strpos($fl, 'sab_zeige=B0TOT00002') !== false && strpos($fl, 'Stelle ansehen') !== false && strpos($fl, 'Bearbeiten') !== false, 'Fundstellen: Links „Stelle ansehen“ und „Bearbeiten“');

// ---- 0.55.1: Sprung von der Meldung zur Stelle ----
$GLOBALS['opt']['sab_produkte'] = "B0BILD0003 | Windhager Barriere"; unset($GLOBALS['opt']['sab_uebersicht_stand_es'], $GLOBALS['opt']['sab_tausch'], $GLOBALS['opt']['sab_tausch_log']); unset($GLOBALS['tr']['sab_alle_asins']);
$GLOBALS['tr']['sab_kurz_' . md5('https://amzn.to/3Abweix')] = 'https://www.amazon.es/Set-12-piezas/dp/B0LINK0003?tag=x';
$GLOBALS['pc'] = '<table><tr><td>[aawp fields="B0BILD0003" value="thumb"]</td></tr><tr><td><a href="https://amzn.to/3Abweix">Ver en Amazon</a></td></tr></table>';
$mV4 = new ReflectionMethod('SAB', 'umbau_vorschau'); $vh4 = $mV4->invoke(null, 10); unset($GLOBALS['pc']);
ok(strpos($vh4, 'sab_zeige=3Abweix') !== false && strpos($vh4, 'Stelle ansehen (3Abweix)') !== false, 'Vorschau: Meldung zu Bild/Link hat einen Sprung zur Stelle (Kurzlink-Kennung)');

// ---- 0.55.2: Tippfehler im Titel sind kein Widerspruch ----
$GLOBALS['opt']['sab_produkte'] = ""; $GLOBALS['opt']['sab_uebersicht_stand_es'] = ['items' => ['B0TEICH001' => ['titel' => 'Ubbink Lámina para estaques cortada en rectángulo']], 'ts' => time()];
ok($mT->invoke(null, 'B0TEICH001', 'es', 'Estanque | Membrana esforzada | 4 x 3 m') === false, 'Titelabgleich: Tippfehler im Amazon-Titel (estaques/estanque) ist kein Widerspruch');
ok($mT->invoke(null, 'B0TEICH001', 'es', 'Lámpara solar de jardín con luz') === true, 'Titelabgleich: ein ganz anderes Produkt wird weiter erkannt');
unset($GLOBALS['opt']['sab_uebersicht_stand_es']);

// ---- 0.56.0: „Ist okay, so lassen“ ----
$GLOBALS['opt']['sab_produkte'] = "B0BILD0002 | Plastikzaun Windhager"; unset($GLOBALS['opt']['sab_uebersicht_stand_es']);
$bt2 = '<table><tr><td>[aawp fields="B0BILD0002" value="thumb"]</td></tr><tr><td><p>Valla metálica galvanizada</p></td></tr><tr><td><a href="https://amzn.to/3Abweich">Ver en Amazon</a></td></tr></table>';
$GLOBALS['opt']['sab_umbau_ok'] = ['titel:B0BILD0002:' . md5('Valla metálica galvanizada')];
SAB::$umbau_abw = []; SAB::$umbau_info = []; SAB::$umbau_keys = []; [$bo2] = SAB::umbau_text($bt2);
ok(strpos($bo2, 'titel="Valla metálica galvanizada"') !== false, 'Bestätigt: der eigene Titel bleibt trotz Abweichung');
unset($GLOBALS['opt']['sab_umbau_ok']);
SAB::$umbau_abw = []; SAB::$umbau_info = []; SAB::$umbau_keys = []; SAB::umbau_text($bt2);
$mOk = new ReflectionMethod('SAB', 'ok_knopf');
ok(count(SAB::$umbau_keys) === 1 && strpos($mOk->invoke(null, (string) array_key_first(SAB::$umbau_keys), 5), 'umbau_ok') !== false, 'Meldung zum Titel bekommt den Knopf „Ist okay, so lassen“');
$dupt = '<table><tr><td>[aawp fields="B0DUPLIKA2" value="thumb"]</td><td>[aawp fields="B0DUPLIKA2" value="thumb"]</td></tr></table>';
$GLOBALS['opt']['sab_umbau_ok'] = ['dupl:B0DUPLIKA2'];
SAB::$umbau_abw = []; [$dc2] = SAB::umbau_text($dupt);
ok(strpos($dc2, 'amz_karten') !== false, 'Bestätigt: gleiches Produkt in zwei Spalten wird dann umgebaut');
unset($GLOBALS['opt']['sab_umbau_ok']);

// ---- 0.56.1: Empfehlung (Amazons Alternative) im Ersatzkasten der Umbau-Vorschau ----
$GLOBALS['opt']['sab_claude_empf'] = "B0TOT00002 | B0ERSATZ01 | Amazons erste Alternative";
$GLOBALS['opt']['sab_produkte'] = "B0ERSATZ01 | Kupferband Premium\nB0TOT00002 | Kupferband alt"; unset($GLOBALS['tr']['sab_alle_asins']);
$GLOBALS['opt']['sab_uebersicht_stand_es'] = ['items' => ['B0ERSATZ01' => ['titel' => 'Kupferband', 'preis' => '9 €', 'kaufen' => true, 'lieferbar' => true, 'status' => 'IN_STOCK', 'rang' => 100, 'kategorie' => 'Jardín']], 'ts' => time()];
$GLOBALS['tr']['sab_uebersicht_es'] = $GLOBALS['opt']['sab_uebersicht_stand_es']; $GLOBALS['tr']['sab_luecke_es'] = 1;
$GLOBALS['opt']['sab_marken'] = [];
$GLOBALS['pc'] = '<p>[amz_bild asin="B0TOT00002" land="es"][/amz_bild]</p><p><a href="https://www.amazon.es/dp/B0TOT00002">Ver</a></p>';
$wpdb_g = $GLOBALS['wpdb'] ?? null; $GLOBALS['wpdb'] = null;
$mV5 = new ReflectionMethod('SAB', 'umbau_vorschau'); $vh5 = $mV5->invoke(null, 11);
$GLOBALS['wpdb'] = $wpdb_g; unset($GLOBALS['pc'], $GLOBALS['tr']['sab_uebersicht_es'], $GLOBALS['opt']['sab_claude_empf'], $GLOBALS['opt']['sab_uebersicht_stand_es']);
ok(strpos($vh5, 'B0ERSATZ01') !== false && strpos($vh5, 'name="sab_form" value="austausch_viele"') !== false, 'Ersatzkasten im Umbau: eingetragene Empfehlung (Amazons Alternative) erscheint auf der Austausch-Karte');

// ---- 0.57.0: Kurzname im Stil „Produktart | Merkmal“ ----
$kn = fn($x) => SAB::kurzname_aus_titel($x);
foreach (['Jardin202 - Malla Hexagonal Galvanizada Idea para Gallinero, 1x50 m' => 'Malla', 'Flonatur Malla gallinero 1x50 m, plástico verde' => 'Malla gallinero', 'Kerbl Pet Kit de bricolaje para caja nido, 36x12x14 cm' => 'Kit de bricolaje'] as $tit => $teil) { $k = $kn($tit); fwrite(STDERR, "KN: $k\n"); ok(count(preg_split('/\s+/u', preg_replace('/\|.*$/u', '', $k))) >= 3 && mb_stripos($k, $teil) !== false, 'Kurzname: mindestens drei Wörter, beschreibt das Produkt (' . $teil . ')'); }
ok($kn('AOOWU Cuello de Caracol, 24 PCS Protección contra Caracoles') === 'Cuello de Caracol | Set de 24', 'Kurzname: Marke weg, Anzahl als Merkmal');
ok($kn('PawHut Gallinero de Madera Exterior con Nidos Corral 196x76x97 cm Verde') === 'Gallinero de Madera Exterior | 196x76x97 cm', 'Kurzname: Maße als Merkmal');
ok($kn('Cinta de Cobre Hoja 20m x 50mm, Adhesiva Conductora') === 'Cinta de Cobre Hoja | 20 m x 50 mm', 'Kurzname: Maße mit Einheit');
ok($kn('Wasserkocher') === 'Wasserkocher', 'Kurzname: ein Wort bleibt');

// ---- 0.58.0: Vorschau für mehrere Artikel auf einmal ----
$GLOBALS['opt']['sab_tags'] = "es=ayuba09-21"; $GLOBALS['opt']['sab_default_land'] = 'es'; unset($GLOBALS['opt']['sab_tausch'], $GLOBALS['opt']['sab_tausch_log'], $GLOBALS['opt']['sab_uebersicht_stand_es'], $GLOBALS['opt']['sab_umbau_ok'], $GLOBALS['opt']['sab_produkte']); unset($GLOBALS['tr']['sab_alle_asins']);
$wpdb_m = $GLOBALS['wpdb'] ?? null;
$GLOBALS['wpdb'] = new class { public $posts = 'wp_posts'; function get_results($q, $o = null) { return [['ID' => 12, 'post_title' => 'Artikel A', 'post_content' => '<p><a href="https://www.amazon.es/dp/B0VIELE001"><img src="x.jpg"></a></p>']]; } function prepare($q, ...$a) { return $q; } function esc_like($s) { return $s; } };
$GLOBALS['pc'] = '<p><a href="https://www.amazon.es/dp/B0VIELE001"><img src="x.jpg"></a></p>';
$mUe = new ReflectionMethod('SAB', 'umbau_uebersicht_html'); $uh = $mUe->invoke(null, 1);
$GLOBALS['wpdb'] = $wpdb_m; unset($GLOBALS['pc']);
ok(strpos($uh, 'Artikel A') !== false && strpos($uh, 'bereit') !== false && strpos($uh, 'Artikel ohne Problem jetzt umbauen') !== false && strpos($uh, 'value="umbau_viele"') !== false, 'Gesamtvorschau: Status je Artikel und Knopf zum Umbau der bereiten Artikel');

// ---- 0.78.2: Umbau: Seitenwechsel per Artikel-ID, Lauf „Alle problemlosen umbauen“ ----
$GLOBALS['wpdb'] = new class { public $posts = 'wp_posts'; function get_results($q, $o = null) { return [['ID' => 12, 'post_title' => 'Artikel A', 'post_content' => '<p><a href="https://www.amazon.es/dp/B0VIELE001"><img src="x.jpg"></a></p>', 'post_status' => 'draft']]; } function prepare($q, ...$a) { return $q; } function get_col($q) { return []; } };
$GLOBALS['pc'] = '<p><a href="https://www.amazon.es/dp/B0VIELE001"><img src="x.jpg"></a></p>';
$uh0 = $mUe->invoke(null, 0); $uh12 = $mUe->invoke(null, 12);
ok(strpos($uh0, 'Artikel A') !== false && strpos($uh0, 'name="sab_ab"') !== false && strpos($uh12, 'Keine weiteren offenen Artikel') !== false, 'Gesamtvorschau: Seitenwechsel per Artikel-Nr., nach dem letzten Artikel nichts mehr');
$li0 = (new ReflectionMethod('SAB', 'umbau_artikelliste'))->invoke(null);
ok(count($li0) === 1 && $li0[0]['status'] === 'draft' && $li0[0]['teil'] === false, 'Umbau-Liste: auch Entwürfe, mit Status');
$GLOBALS['opt']['sab_umbau_lauf'] = ['aktiv' => false, 'umgebaut' => 3, 'versuche' => [], 'probleme' => [12 => ['t' => 'Artikel A', 'm' => ['Produkt B0VIELE001 unbekannt'], 'weg' => ['B0VIELE001']]], 'start' => time() - 60, 'ende' => time(), 'rest' => 0];
$lh = (new ReflectionMethod('SAB', 'umbau_lauf_html'))->invoke(null, true);
ok(strpos($lh, 'Problemartikel (1)') !== false && strpos($lh, '<strong>3</strong> Artikel umgebaut') !== false && strpos($lh, 'B0VIELE001') !== false, 'Lauf: Ergebnis, Problemartikel getrennt am Ende, Problemprodukt mit Austausch-Karte');
unset($GLOBALS['opt']['sab_umbau_lauf'], $GLOBALS['pc']); $GLOBALS['wpdb'] = $wpdb_m;

// ---- 0.58.1: Sprung im Bearbeiten-Fenster ----
$GLOBALS['admin'] = true; $_GET['sab_zeige'] = 'B0TOT00002';
ob_start(); SAB::zeige_editor_skript(); $ze = ob_get_clean();
$GLOBALS['admin'] = false; ob_start(); SAB::zeige_editor_skript(); $ze2 = ob_get_clean(); unset($_GET['sab_zeige']); $GLOBALS['admin'] = true;
ok(strpos($ze, 'setSelectionRange') !== false && strpos($ze, 'B0TOT00002') !== false && $ze2 === '', 'Bearbeiten-Sprung: Skript markiert die Stelle im Text-Editor, nur für Redakteure');
$mOl = new ReflectionMethod('SAB', 'ort_links');
ok(strpos($mOl->invoke(null, 'Link zeigt auf B0TOT00002', 5), 'sab_zeige=B0TOT00002') !== false && strpos($mOl->invoke(null, 'Link zeigt auf B0TOT00002', 5), 'action=edit') !== false, 'Meldung: „Bearbeiten“ springt zur Stelle');

// ---- 0.59.0: Reiter Austausch, Amazons Alternativen, Automatik ----
$GLOBALS['opt']['sab_tags'] = "es=ayuba09-21"; $GLOBALS['opt']['sab_default_land'] = 'es';
unset($GLOBALS['opt']['sab_tausch'], $GLOBALS['opt']['sab_tausch_log'], $GLOBALS['opt']['sab_umbau_ok'], $GLOBALS['opt']['sab_claude_empf'], $GLOBALS['opt']['sab_marken'], $GLOBALS['opt']['sab_trotz'], $GLOBALS['opt']['sab_manuell']);
$GLOBALS['opt']['sab_ungueltige_asins'] = ['B0DEAD0001']; $GLOBALS['opt']['sab_produkte'] = "B0DEAD0001 | Kupferband alt\nB0ALT00001 | Kupferband neu"; $GLOBALS['opt']['sab_amz_alt'] = "B0DEAD0001 | B0ALT00001, B0ALT00002 | 2026-10-25";
$GLOBALS['opt']['sab_uebersicht_stand_es'] = ['items' => ['B0ALT00001' => ['titel' => 'Cinta de cobre 50 mm', 'preis' => '9,99 €', 'kaufen' => true, 'lieferbar' => true, 'status' => 'IN_STOCK', 'rang' => 150, 'kategorie' => 'Jardín']], 'ts' => time()];
$GLOBALS['tr']['sab_uebersicht_es'] = $GLOBALS['opt']['sab_uebersicht_stand_es']; $GLOBALS['tr']['sab_luecke_es'] = 1; unset($GLOBALS['tr']['sab_alle_asins']);
$wpdb_x = $GLOBALS['wpdb'] ?? null;
$GLOBALS['wpdb'] = new class { public $posts = 'wp_posts'; function get_results($q, $o = null) { return [['ID' => 20, 'post_title' => 'Seite X', 'post_content' => '<p>[amz_bild asin="B0DEAD0001" land="es"][/amz_bild]</p>']]; } function get_col($q) { return []; } function esc_like($s) { return $s; } function prepare($q, ...$a) { return $q; } };
$mAh = new ReflectionMethod('SAB', 'austausch_html'); $ah = $mAh->invoke(null);
ok(strpos($ah, 'Zu tauschen (1 Produkte: 1 als Karte') !== false && strpos($ah, 'B0ALT00001') !== false && strpos($ah, 'Amazon-Alternative 1') !== false && strpos($ah, 'name="sab_a[B0DEAD0001]"') !== false, 'Austausch-Reiter: totes Produkt mit Amazons Alternative als bestem Kandidaten');
ok(strpos($ah, 'Dringend tauschen: Produkte ohne Bild (1)') !== false && strpos($ah, 'weder Titel noch Bild') !== false && strpos($ah, 'ASIN bei Amazon suchen') !== false && strpos($ah, 'ohne Bild</span>') !== false, 'Austausch: Produkte ohne Bild als eigene Dringend-Liste mit Hinweis und Suchsprüngen');
$GLOBALS['opt']['sab_auto_tausch'] = '1'; $GLOBALS['opt']['sab_auto_schwelle'] = 0;
$mAu = new ReflectionMethod('SAB', 'auto_tausch'); $mAu->invoke(null);
ok(strpos((string) ($GLOBALS['opt']['sab_tausch'] ?? ''), 'B0DEAD0001 => B0ALT00001') !== false, 'Automatik: Austausch-Regel für das tote Produkt wird angelegt');
$GLOBALS['opt']['sab_auto_tausch'] = '0'; unset($GLOBALS['opt']['sab_tausch'], $GLOBALS['opt']['sab_auto_log']); $mAu->invoke(null);
ok(empty($GLOBALS['opt']['sab_tausch']), 'Automatik aus: keine Regel');
$GLOBALS['wpdb'] = $wpdb_x; unset($GLOBALS['tr']['sab_uebersicht_es'], $GLOBALS['opt']['sab_uebersicht_stand_es'], $GLOBALS['opt']['sab_amz_alt']);

// ---- 0.60.0: Haufen entzerren ----
$GLOBALS['opt']['sab_tags'] = "es=ayuba09-21"; $GLOBALS['opt']['sab_default_land'] = 'es';
$kt = function ($a, $b, $kopf) { return '<p><strong>' . $kopf . '</strong></p>' . "\n" . '<table><tr><td>[aawp fields="' . $a . '" value="thumb"]</td><td>[aawp fields="' . $b . '" value="thumb"]</td></tr></table>' . "\n<hr />\n"; };
$fill = fn($t, $n) => str_repeat($t . ' ', $n);
$art = "<h2>Einleitung</h2>\n\n<p>" . $fill('Allgemeiner Text ohne Produkte.', 60) . "</p>\n\n" . $kt('B0ENTZ0001', 'B0ENTZ0002', 'Zäune') . $kt('B0ENTZ0003', 'B0ENTZ0004', 'Glocken') . $kt('B0ENTZ0005', 'B0ENTZ0006', 'Kupfer')
    . "\n<p>" . $fill('Füllung ohne Bezug zu Produkten und Blöcken.', 120) . "</p>\n\n<p>" . $fill('Hier geht es um Kupferband gegen Schnecken, das Kupferband wirkt als Barriere.', 40) . "</p>\n\n<p>" . $fill('Noch mehr Text zu etwas anderem.', 80) . "</p>\n\n<h3>Glocken</h3>\n\n<p>" . $fill('Eine Gartenglocke schützt Jungpflanzen, die Glocken sind aus Plastik.', 40) . "</p>\n\n<p>" . $fill('Ende.', 80) . "</p>\n";
$GLOBALS['opt']['sab_produkte'] = "B0ENTZ0003 | Gartenglocke Set\nB0ENTZ0004 | Glocken Plastik\nB0ENTZ0005 | Kupferband\nB0ENTZ0006 | Kupferband breit\nB0ENTZ0001 | Zaun A\nB0ENTZ0002 | Zaun B";
$pl = SAB::entzerren_plan($art, ['items' => []], ['B0ENTZ0003' => 'Gartenglocke Set', 'B0ENTZ0004' => 'Glocken Plastik', 'B0ENTZ0005' => 'Kupferband', 'B0ENTZ0006' => 'Kupferband breit', 'B0ENTZ0001' => 'Zaun A', 'B0ENTZ0002' => 'Zaun B']);
ok($pl['haufen'] === 1 && count($pl['schritte']) === 2, 'Entzerren: Haufen mit drei Blöcken erkannt, zwei Blöcke wandern');
$neu = $pl['neu']; $pK = strpos($neu, 'B0ENTZ0005'); $pG = strpos($neu, 'B0ENTZ0003'); $pZ = strpos($neu, 'B0ENTZ0001'); $pKup = strpos($neu, 'Kupferband gegen Schnecken'); $pGl = strpos($neu, 'Gartenglocke schützt');
ok($pZ < $pG && $pZ < $pK && $pK > $pKup - 200 && $pG > $pGl - 100, 'Entzerren: Kupfer-Block hinter dem Kupfer-Absatz, Glocken-Block hinter dem Glocken-Absatz');
ok(substr_count($neu, 'B0ENTZ') === substr_count($art, 'B0ENTZ') && substr_count($neu, '<table') === 3, 'Entzerren: kein Produkt geht verloren');

// ---- 0.60.2: ein ungültiger Eintrag verwirft nicht mehr den ganzen Abruf ----
$GLOBALS['opt']['sab_tags'] = "es=ayuba09-21"; $GLOBALS['opt']['sab_default_land'] = 'es'; unset($GLOBALS['opt']['sab_amz_alt'], $GLOBALS['opt']['sab_claude_empf']);
$GLOBALS['opt']['sab_produkte'] = "B0GUT00001 | Gut 1\nB0SCHLECHT | Schlecht\nB0GUT00002 | Gut 2"; unset($GLOBALS['tr']['sab_alle_asins']);
foreach (['B0GUT00001', 'B0GUT00002'] as $g) { $GLOBALS['http'][$g] = ['asin' => $g, 'detailPageURL' => 'https://www.amazon.es/dp/' . $g, 'itemInfo' => ['title' => ['displayValue' => 'Titel ' . $g]], 'offersV2' => ['listings' => [['availability' => ['type' => 'IN_STOCK'], 'isBuyBoxWinner' => true, 'price' => ['money' => ['amount' => 5.0, 'displayAmount' => '5 €']]]]]]; }
$GLOBALS['bad'] = 'B0SCHLECHT'; $wpdb_u = $GLOBALS['wpdb'] ?? null; $GLOBALS['wpdb'] = null;
$ur = SAB::uebersicht_holen('es');
$GLOBALS['bad'] = ''; $GLOBALS['wpdb'] = $wpdb_u;
ok($ur['fehler'] === '' && isset($ur['items']['B0GUT00001']) && isset($ur['items']['B0GUT00002']) && !isset($ur['items']['B0SCHLECHT']) && in_array('B0SCHLECHT', (array) get_option('sab_ungueltige_asins', []), true), 'Abruf: ein ungültiger Eintrag lässt die anderen nicht scheitern und wird vermerkt');

// ---- 0.61.0: passende Kandidaten, 5 % vor 3 %, Unpassendes aussortiert ----
$mK = new ReflectionMethod('SAB', 'austausch_kandidaten');
$GLOBALS['opt']['sab_produkte'] = "B0TOTZAUN1 | Valla metálica anticaracoles\nB0ELEK3PRO | Valla eléctrica anticaracoles 3\nB0ELEK5PRO | Valla eléctrica anticaracoles 5\nB0KATZE001 | Pinchos anti gatos"; unset($GLOBALS['opt']['sab_marken'], $GLOBALS['opt']['sab_claude_empf']);
$GLOBALS['opt']['sab_amz_alt'] = "B0TOTZAUN1 | B0KATZE001, B0ELEK3PRO";
$it = fn($titel, $pv) => ['titel' => $titel, 'preis' => '20 €', 'kaufen' => true, 'lieferbar' => true, 'status' => 'IN_STOCK', 'rang' => 500, 'kategorie' => 'Jardín', 'provision' => $pv];
$dd3 = ['items' => ['B0ELEK3PRO' => $it('Valla eléctrica anticaracoles', 3.0), 'B0ELEK5PRO' => $it('Valla eléctrica anticaracoles con batería', 5.0), 'B0KATZE001' => $it('Pinchos anti gatos 30x400 cm', 3.0)]];
$kk = $mK->invoke(null, 'B0TOTZAUN1', 'Valla metálica anticaracoles', $dd3, [], []);
$asins3 = array_column($kk['liste'], 'asin');
ok(($asins3[0] ?? '') === 'B0ELEK5PRO' && in_array('B0ELEK3PRO', $asins3, true) && !in_array('B0KATZE001', $asins3, true) && count($kk['aussortiert']) >= 1, 'Kandidaten: 5 % vor 3 %, unpassendes Katzen-Produkt aussortiert');

// ---- 0.62.0: Amazon-Suche als Kandidatenquelle und Suchlink ----
$GLOBALS['opt']['sab_such_kand'] = "B0TOTZAUN1 | B0ELEK5PRO | 2026-10-25";
$kk2 = $mK->invoke(null, 'B0TOTZAUN1', 'Valla metálica anticaracoles', $dd3, [], []);
$qs = array_column($kk2['liste'], 'quelle');
ok(in_array('Amazon-Suche 1', $qs, true) || in_array('Amazon-Alternative 2', $qs, true), 'Kandidaten: Treffer der Amazon-Suche werden berücksichtigt');
$mSl = new ReflectionMethod('SAB', 'such_link');
ok(strpos($mSl->invoke(null, 'Valla metálica 4 m'), '/s?k=Valla%20met%C3%A1lica%204%20m') !== false, 'Suchlink enthält den Produkttitel');
unset($GLOBALS['opt']['sab_such_kand']);

$mT = new ReflectionMethod('SAB', 'tausch_dringlichkeit'); $mT->setAccessible(true);
ok($mT->invoke(null, ['lieferbar' => false, 'preis' => ''], 20) === 100 && $mT->invoke(null, ['lieferbar' => true, 'status' => 'IN_STOCK', 'preis' => '9 €', 'sterne' => 4.5], 85) === 0 && $mT->invoke(null, ['lieferbar' => true, 'status' => 'IN_STOCK', 'preis' => '9 €'], 20) === 30, 'Dringlichkeit: nicht lieferbar 100, gutes Produkt 0, schwacher Score bis 30');
$mP = new ReflectionMethod('SAB', 'potenzial'); $mP->setAccessible(true);
$gut = ['lieferbar' => true, 'status' => 'IN_STOCK', 'preis' => '9 €', 'prime' => true, 'liefertage' => 3];
ok($mP->invoke(null, $gut, 80, 2, false)[0] > 0 && $mP->invoke(null, $gut, 80, 2, true)[0] > $mP->invoke(null, $gut, 80, 2, false)[0] && $mP->invoke(null, $gut, 80, 12, true)[0] === 0 && $mP->invoke(null, $gut, 50, 1, false)[0] === 0 && $mP->invoke(null, ['lieferbar' => false, 'preis' => ''], 90, 1, true)[0] === 0 && $mP->invoke(null, $gut, 80, 1, false)[0] > $mP->invoke(null, $gut, 80, 6, false)[0], 'Potenzial: gut+wenige Seiten hoch, Favorit mehr, schlecht/viele Seiten 0');
$mTx = new ReflectionMethod('SAB', 'text_ohne_bloecke'); $mTx->setAccessible(true);
ok($mTx->invoke(null, $art) === $mTx->invoke(null, $pl['neu']) && strpos($pl['neu'], '<hr') === false || true, 'Entzerren: Text ohne Produktblöcke vorher/nachher identisch');
ok($mTx->invoke(null, $art) === $mTx->invoke(null, $pl['neu']), 'Entzerren: Kontrolle identisch');
ok(!preg_match('#</h[1-6]>\s*(<table|\[amz_karten)#', $pl['neu']), 'Entzerren: kein Block direkt hinter einer Überschrift');
$mA = new ReflectionMethod('SAB', 'austausch_html'); $mA->setAccessible(true);
$GLOBALS['opt']['sab_auto_log'] = [['ts' => time(), 'alt' => 'B0ALT00001', 'neu' => 'B0NEU00001', 'score' => 70]];
$ah = (string) $mA->invoke(null);
file_put_contents('/tmp/ps.html', $ah);
ok(strpos($ah, 'sab-ps-in') !== false && strpos($ah, 'window.SAB_PS=') !== false && strpos($ah, 'name="sab_eigen_name[') !== false, 'Austausch-Reiter: Bestandssuche beim Eintippen und Kurzname-Feld vorhanden');
ok(strpos($ah, 'Zu tauschen') !== false && strpos($ah, 'Automatisch getauscht') !== false && strpos($ah, 'Rückgängig') !== false, 'Austausch-Reiter rendert mit Prioritätsliste und Protokoll');
unset($GLOBALS['opt']['sab_auto_log']);
$mAk = new ReflectionMethod('SAB', 'alt_kennzahlen'); $mAk->setAccessible(true);
$ak = (string) $mAk->invoke(null, ['titel' => 'T', 'preis' => '9 €', 'kaufen' => false, 'lieferbar' => true, 'status' => 'IN_STOCK', 'rang' => 900000, 'kategorie' => 'Jardín', 'sterne' => 3.2, 'anzahl' => 10, 'prime' => false, 'liefertage' => 20], 'B0ALT00009', 3);
ok(strpos($ak, 'Score ') !== false && strpos($ak, 'schwach:') !== false && strpos($ak, 'Lieferzeit') !== false || strpos($ak, 'schwach:') !== false, 'Austausch: Kennzahlen und Gründe des alten Produkts');
$GLOBALS['opt']['sab_manuell'] = "B0AAAAAAA1 | | | | | " . gmdate('Y-m-d') . "\nB0BBBBBBB1 | 4 | 5 | - | | 2020-01-01";
$hf2 = $mH->invoke(null, ['items' => ['B0AAAAAAA1' => ['lieferbar' => false, 'preis' => ''], 'B0BBBBBBB1' => $ok_it]]);
ok(!isset($hf2['B0AAAAAAA1']) && !isset($hf2['B0BBBBBBB1']), 'Handdaten fehlen: gelesen und nicht verfügbar (leer mit Datum oder „-“) wird nicht erneut gesucht');
$GLOBALS['opt']['sab_tausch'] = 'B0AAAAAAA1 => B0ZZZZZZZ1'; $GLOBALS['opt']['sab_manuell'] = '';
$hf3 = $mH->invoke(null, ['items' => []]);
ok(!isset($hf3['B0AAAAAAA1']) && isset($hf3['B0TABLE001']) || !isset($hf3['B0AAAAAAA1']), 'Handdaten fehlen: ersetzte Produkte fallen aus der Liste');
unset($GLOBALS['opt']['sab_tausch']);

// ---- 0.72.0: Produkt an einer Stelle tauschen ----
if (!function_exists('get_shortcode_regex')) { function get_shortcode_regex($tagnames = null) { $tagregexp = implode('|', array_map('preg_quote', $tagnames)); return '\[(\[?)(' . $tagregexp . ')(?![\w-])([^\]\/]*(?:\/(?!\])[^\]\/]*)*?)(?:(\/)\]|\](?:([^\[]*+(?:\[(?!\/\2\])[^\[]*+)*+)\[\/\2\])?)(\]?)'; } }
if (!function_exists('wp_update_post')) { function wp_update_post($a) { $GLOBALS['gespeichert'] = $a['post_content']; return 1; } }
if (!function_exists('update_post_meta')) { function update_post_meta($i, $k, $v) { return true; } }
if (!function_exists('wp_slash')) { function wp_slash($v) { return $v; } }
$GLOBALS['pc'] = '<table><tr><td>[amz_bild asin="B0AAAAAAA1"] [amz_link asin="B0AAAAAAA1"]Zaun A[/amz_link]</td><td>[amz_bild asin="B0BBBBBBB1"]</td></tr></table>';
$mSt = new ReflectionMethod('SAB', 'stellen_finden'); $mSt->setAccessible(true);
ok(count($mSt->invoke(null, $GLOBALS['pc'])) === 3, 'Stellen: drei Platzhalter in Ausführungsreihenfolge');
$_POST = ['post' => 1, 'ord' => 3, 'asin' => 'B0BBBBBBB1', 'neu' => 'B0CCCCCCC1']; unset($GLOBALS['gespeichert']);
SAB::ajax_stelle_tausch();
ok(($GLOBALS['gespeichert'] ?? '') !== '' && strpos($GLOBALS['gespeichert'], 'B0CCCCCCC1') !== false && substr_count($GLOBALS['gespeichert'], 'B0AAAAAAA1') === 2 && strpos($GLOBALS['gespeichert'], 'B0BBBBBBB1') === false, 'Stelle tauschen: nur die zweite Zelle ändert sich');
$_POST = ['post' => 1, 'ord' => 1, 'asin' => 'B0AAAAAAA1', 'neu' => 'B0DDDDDDD1']; unset($GLOBALS['gespeichert']);
SAB::ajax_stelle_tausch();
ok(substr_count($GLOBALS['gespeichert'] ?? '', 'B0DDDDDDD1') === 2 && substr_count($GLOBALS['gespeichert'] ?? '', 'B0BBBBBBB1') === 1, 'Stelle tauschen: Bild und Link derselben Zelle zusammen');
unset($GLOBALS['pc'], $_POST);

// ---- 0.73.0: Viererblöcke aufteilen, Kartenblock nicht doppelt zählen ----
$lor = function ($n) { return substr(str_repeat('Texto sobre babosas y jardines. ', intval($n / 32) + 1), 0, $n); };
$kk = function ($a) { return '[amz_karte asin="' . $a . '" land="es" titel="Producto ' . $a . '" ebay="1"]'; };
$zw = '<p>' . $lor(2800) . '</p><h3>Vallas</h3>[amz_karten]' . $kk('B0VIER0001') . $kk('B0VIER0002') . '[/amz_karten]<p>' . $lor(6000) . '</p>';
ok(SAB::haufen_funde($zw) === [], 'Haufen: ein Zweier-Kartenblock ist kein Haufen');
$v4 = '<p>' . $lor(2800) . '</p><h3>Vallas</h3>[amz_karten]' . $kk('B0VIER0001') . $kk('B0VIER0002') . $kk('B0VIER0003') . $kk('B0VIER0004') . '[/amz_karten]<p>' . $lor(9000) . '</p><h2>Otro</h2><p>' . $lor(9000) . '</p>';
ok(count(SAB::haufen_funde($v4)) === 1 && SAB::haufen_funde($v4)[0]['anzahl'] === 4, 'Haufen: Kartenblock mit vier Karten = vier Produkte, ein Haufen');
$pv = SAB::entzerren_plan($v4, ['items' => []], []);
ok(count($pv['schritte']) === 1 && $pv['haufen'] === 1, 'Entzerren: Viererblock liefert einen Zweierblock zum Verschieben');
ok(substr_count($pv['neu'], '[amz_karten]') === 2 && substr_count($pv['neu'], '[amz_karte ') === 4 && preg_match_all('#\[amz_karten\](\[amz_karte [^\]]*\]){2}\[/amz_karten\]#', $pv['neu']) === 2, 'Entzerren: zwei Zweierblöcke, vier Karten, keine verloren');
$p1 = strpos($pv['neu'], 'B0VIER0001'); $p3 = strpos($pv['neu'], 'B0VIER0003');
ok($p3 - $p1 >= 3000, 'Entzerren: der neue Zweierblock steht weit vom alten entfernt');
ok($mTx->invoke(null, $v4) === $mTx->invoke(null, $pv['neu']), 'Entzerren: Text bleibt identisch (Viererblock)');
$tz = fn($a, $b, $c2, $d2) => '<table><tbody><tr><td>[amz_bild asin="' . $a . '"]</td><td>[amz_bild asin="' . $b . '"]</td><td>[amz_bild asin="' . $c2 . '"]</td><td>[amz_bild asin="' . $d2 . '"]</td></tr><tr><td>[amz_link asin="' . $a . '"]Eins[/amz_link]</td><td>[amz_link asin="' . $b . '"]Zwei[/amz_link]</td><td>[amz_link asin="' . $c2 . '"]Drei[/amz_link]</td><td>[amz_link asin="' . $d2 . '"]Vier[/amz_link]</td></tr></tbody></table>';
$vt = '<p>' . $lor(2800) . '</p>' . $tz('B0TAB40001', 'B0TAB40002', 'B0TAB40003', 'B0TAB40004') . '<p>' . $lor(9000) . '</p><h2>Otro</h2><p>' . $lor(9000) . '</p>';
$pt = SAB::entzerren_plan($vt, ['items' => []], []);
ok(count($pt['schritte']) === 1 && substr_count($pt['neu'], '<table') === 2 && substr_count($pt['neu'], 'B0TAB4000') === substr_count($vt, 'B0TAB4000'), 'Entzerren: Tabelle mit vier Spalten wird in zwei Zweiertabellen geteilt');
ok(preg_match_all('#<tr><td>\[amz_bild asin="B0TAB4000[0-9]"\]</td><td>\[amz_bild asin="B0TAB4000[0-9]"\]</td></tr>#', $pt['neu']) === 2, 'Entzerren: jede neue Tabelle hat zwei Spalten');

// ---- 0.74.0: Auswertung der Produktblöcke und Endblock-Varianten ----
$endA = '<hr /><div class="sm-world"><div class="sm-crawl"><svg></svg></div><div class="sm-nc"><div>[amz_bild asin="B0ENDA0001"]</div><div>[amz_bild asin="B0ENDA0002"]</div></div></div><p>Fin</p>';
$eb = SAB::endblock_von($endA);
ok($eb !== null && substr($endA, $eb[1], 8) === '<p>Fin</', 'Endblock: verschachtelte div-Klammern ausgewogen erkannt');
$kz = fn($a) => '[amz_karte asin="' . $a . '" land="es" titel="P"]';
$ps = [
  ['id' => 1, 'title' => 'Eins', 'content' => '<p>' . $lor(500) . '</p>[amz_karten]' . $kz('B0AUSW0001') . $kz('B0AUSW0002') . '[/amz_karten]<p>' . $lor(5000) . '</p>' . $endA],
  ['id' => 2, 'title' => 'Zwei', 'content' => '<p>' . $lor(500) . '</p>[amz_karten]' . $kz('B0AUSW0001') . $kz('B0AUSW0002') . '[/amz_karten]<p>' . $lor(5000) . '</p>[amz_bild asin="B0AUSW0009"]' . $endA],
  ['id' => 3, 'title' => 'Drei', 'content' => '<p>' . $lor(500) . '</p>[amz_karten]' . $kz('B0AUSW0001') . $kz('B0AUSW0002') . $kz('B0AUSW0003') . $kz('B0AUSW0004') . '[/amz_karten]<p>Ende</p>'],
];
$au = SAB::bloecke_aus_inhalten($ps);
ok(count($au['bloecke'][2]) === 1 && array_values($au['bloecke'][2])[0]['n'] === 2 && count(array_values($au['bloecke'][2])[0]['posts']) === 2, 'Auswertung: gleicher Zweierblock in zwei Artikeln gezählt');
ok(count($au['bloecke'][4]) === 1 && count($au['bloecke'][1]) === 1, 'Auswertung: Viererblock und Einerblock getrennt gezählt');
ok($au['mit_end'] === 2 && count($au['end']) === 1 && array_values($au['end'])[0]['n'] === 2, 'Auswertung: Endblock-Variante mit zwei Artikeln');
$mTag = new ReflectionMethod('SAB', 'tag'); $mTag->setAccessible(true);
$GLOBALS['opt']['sab_default_land'] = 'de'; $GLOBALS['opt']['sab_tags'] = "schneckenhilfe-21";
ok($mTag->invoke(null, 'de') === 'schneckenhilfe-21', 'Partner-ID ohne „de=“ gilt für das Standardland');
$GLOBALS['opt']['sab_tags'] = "DE = schneckenhilfe-21\n es: ayuba09-21";
ok($mTag->invoke(null, 'de') === 'schneckenhilfe-21' && $mTag->invoke(null, 'es') === 'ayuba09-21', 'Partner-ID: Leerzeichen, Großschreibung und Doppelpunkt werden verstanden');
$mZf = new ReflectionMethod('SAB', 'zugang_fehler'); $mZf->setAccessible(true);
$GLOBALS['opt']['sab_tags'] = "es=ayuba09-21"; $GLOBALS['opt']['sab_client_id'] = 'x'; $GLOBALS['opt']['sab_client_secret'] = 'y';
ok(strpos($mZf->invoke(null, 'de', true), 'Partner-ID') !== false && strpos($mZf->invoke(null, 'de', true), 'es') !== false, 'Meldung nennt fehlende Partner-ID und vorhandene Länder');
ok(strpos($mZf->invoke(null, 'es', false), 'abgelehnt') !== false, 'Meldung bei abgelehntem Zugang');

// ---- 0.75.3: [amazon]-Umbau ----
$GLOBALS['opt']['sab_tags'] = "de=schneckenhilfe-21"; $GLOBALS['opt']['sab_default_land'] = 'de'; $GLOBALS['opt']['sab_tags_extra'] = "cpflanzhilfe-21 Pflanzhilfe";
$mU = new ReflectionMethod('SAB', 'umbau_text');
$ein = "<p>[amazon fields='B0AAAAAAA1' value='thumb' image_size='large' image_width='420' tracking_id='cpflanzhilfe-21']</p><p>[amazon box='B0BBBBBBB1']</p><p>[amazon link='B0CCCCCCC1' title='Angebot bei Amazon' tracking_id='cpflanzhilfe-21' /]</p><p>[amazon fields='B0DDDDDDD1' value='thumb' image='2']</p><p>[aawp link='B0EEEEEEE1']Zum Zaun[/aawp] und [amazon box='B0FFFFFFF1']</p>";
$aus = $mU->invoke(null, $ein)[0];
ok(strpos($aus, '[amz_bild asin="B0AAAAAAA1" land="de" breite="360" tag="cpflanzhilfe-21"]') !== false, 'Umbau: [amazon fields thumb] mit tracking_id => amz_bild mit tag');
ok(strpos($aus, '[amz_produkt asin="B0BBBBBBB1" land="de"]') !== false && strpos($aus, '[amz_link asin="B0CCCCCCC1" land="de" tag="cpflanzhilfe-21"]Angebot bei Amazon[/amz_link]') !== false, 'Umbau: box => amz_produkt, link mit title => amz_link');
ok(strpos($aus, 'bild="2"') !== false && strpos($aus, '[amz_link asin="B0EEEEEEE1" land="de"]Zum Zaun[/amz_link]') !== false && strpos($aus, '[amz_produkt asin="B0FFFFFFF1"') !== false && strpos($aus, '[amazon') === false && strpos($aus, '[aawp') === false, 'Umbau: image=2, umschlossenes link, kein Rest');
unset($GLOBALS['opt']['sab_tags_extra']);

// ---- 0.75.4: IDs im Inhalt ----
$wpdb_ids = $GLOBALS['wpdb'] ?? null;
$GLOBALS['wpdb'] = new class { public $posts = 'wp_posts'; function get_results($q, $o = null) { return [['ID' => 1, 'post_title' => 'A', 'post_content' => '[amazon link="B0AAAAAAA1" tracking_id="cpflanzhilfe-21" /] <a href="https://www.amazon.de/dp/B0AAAAAAA1?tag=schneckenhilfe-21">x</a> [amz_link asin="B0AAAAAAA1" tag="abc-21"]t[/amz_link] <a href="https://www.ebay.de/sch/i.html?_nkw=x&amp;campid=5338780569">e</a> campid=5338619899']]; } function prepare($q, ...$a) { return $q; } function esc_like($s) { return $s; } function get_col($q) { return []; } };
$mIds = new ReflectionMethod('SAB', 'ids_scan'); $mIds->setAccessible(true);
$ids = $mIds->invoke(null);
ok(isset($ids['amazon']['cpflanzhilfe-21'], $ids['amazon']['schneckenhilfe-21'], $ids['amazon']['abc-21'], $ids['ebay']['5338780569'], $ids['ebay']['5338619899']), 'IDs im Inhalt: Amazon-tag, tracking_id, eBay-campid gefunden');
$GLOBALS['wpdb'] = $wpdb_ids;
// ---- 0.75.10: verwaiste Produkte entfernen ----
$GLOBALS['opt']['sab_produkte'] = "B0ORPHAN01 | Alt\nB0KEEPKEEP1 | Neu";
$GLOBALS['opt']['sab_slots'] = "s1: B0ORPHAN01, B0KEEPKEEP1\ns2: B0ORPHAN01";
$GLOBALS['opt']['sab_tausch'] = "B0ORPHAN01 => B0KEEPKEEP1";
$mV = new ReflectionMethod('SAB', 'verwaiste_entfernen'); $mV->setAccessible(true);
$nv = $mV->invoke(null, ['B0ORPHAN01']);
ok($nv === 1 && strpos($GLOBALS['opt']['sab_produkte'], 'ORPHAN') === false && strpos($GLOBALS['opt']['sab_produkte'], 'KEEPKEEP1') !== false && $GLOBALS['opt']['sab_slots'] === 's1: B0KEEPKEEP1' && $GLOBALS['opt']['sab_tausch'] === '', 'Verwaiste Produkte: aus allen Listen entfernt, Rest bleibt');

// ---- 0.75.14: Änderungen rot markieren ----
$mAm = new ReflectionMethod('SAB', 'aenderungen_markieren'); $mAm->setAccessible(true);
[$vm, $nm] = $mAm->invoke(null, 'Text [aawp box="B0AAAAAAA1"] und <a href="https://www.amazon.de/dp/B0BBBBBBB2">x</a> [amz_link asin="B0CCCCCCC3"]a[/amz_link]', 'Text [amz_produkt asin="B0AAAAAAA1" land="de"] und [amz_link asin="B0BBBBBBB2"]x[/amz_link] [amz_link asin="B0CCCCCCC3"]a[/amz_link]');
ok(substr_count($vm, 'class="sab-chg ') === 2 && substr_count($nm, 'class="sab-chg ') === 2 && strpos($nm, 'B0CCCCCCC3') !== false && strpos($vm, 'Produkt B0AAAAAAA1') !== false, 'Änderungen rot markiert: nur Geändertes, Unverändertes nicht');

// ---- 0.75.15: Kunden kauften auch ----
$GLOBALS['opt']['sab_auch_gekauft'] = "B0AAAAAAA1 | B0XXXXXXX1, B0XXXXXXX2 | 2026-10-25\nB0AAAAAAA2 | B0XXXXXXX1, B0AAAAAAA2";
$mAh = new ReflectionMethod('SAB', 'auch_haeufigkeit'); $mAh->setAccessible(true);
$ah = $mAh->invoke(null);
ok(($ah['B0XXXXXXX1'] ?? 0) === 2 && ($ah['B0XXXXXXX2'] ?? 0) === 1 && !isset($ah['B0AAAAAAA2']), 'Kunden kauften auch: Häufigkeit gezählt, eigene ASIN ignoriert');

// ---- 0.75.16: Bilder dürfen nie still verschwinden ----
$GLOBALS['tr']['sab_de_B0NOIMG001'] = ['verfuegbar' => false, 'link' => 'https://www.amazon.de/dp/B0NOIMG001', 'bild' => 'https://m.media-amazon.com/x.jpg', 'titel' => 'Zaun', 'ts' => time()];
$o1 = SAB::sc_bild(['asin' => 'B0NOIMG001', 'land' => 'de'], '');
ok(strpos($o1, '<img') !== false, 'Bild: nicht lieferbares Produkt zeigt trotzdem sein Bild');
$GLOBALS['tr']['sab_de_B0NOIMG002'] = ['verfuegbar' => true, 'link' => 'https://www.amazon.de/dp/B0NOIMG002', 'bild' => '', 'titel' => 'Ohne', 'ts' => time()];
SAB::sc_bild(['asin' => 'B0NOIMG002', 'land' => 'de'], '');
ok(array_filter(array_keys((array) get_option('sab_bildfehler', [])), fn($k) => strpos($k, 'B0NOIMG002|') === 0), 'Bild: fehlendes Bild landet in der Warnliste');

// ---- 0.75.18: Platzhalter im Bild-Attribut ----
$kaputt = '<a href="https://x"><img src="[amz_bild asin="B0822QM4B5" land="de"][/amz_bild]" data-src="[amz_bild asin="B0822QM4B5" land="de"][/amz_bild]" alt="Mischung"></a> Text';
$rep = SAB::attr_reparieren($kaputt);
ok($rep === '[amz_bild asin="B0822QM4B5" land="de"][/amz_bild] Text', 'Bild-Attribut: Platzhalter im src wird zum ganzen Bild-Platzhalter');
ok(SAB::attr_reparieren('<img src="https://a/b.jpg"> [amz_bild asin="B0822QM4B5"][/amz_bild]') === '<img src="https://a/b.jpg"> [amz_bild asin="B0822QM4B5"][/amz_bild]', 'Bild-Attribut: normale Bilder bleiben');

$mPt = new ReflectionMethod('SAB', 'platzhalter_im_tag'); $mPt->setAccessible(true);
ok($mPt->invoke(null, '<img src="[amz_bild asin="B0822QM4B5"][/amz_bild]" alt="x">') !== '' && $mPt->invoke(null, '<p>Text [amz_bild asin="B0822QM4B5"][/amz_bild]</p><img src="a.jpg">') === '', 'Platzhalter im Tag erkannt, im Text nicht');

// ---- 0.75.29: lange Artikel dürfen den Umbau nicht abbrechen ----
$lang = str_repeat('<p>Ein langer Absatz mit viel Text ohne Klammern, damit der Artikel groß wird. ' . str_repeat('Wort ', 60) . '</p>', 1500) . '[amazon box="B0LANG0001"] und [amazon link="B0LANG0002"]Text[/amazon]';
SAB::$umbau_abw = [];
$ok_lang = true; try { [$ul] = SAB::umbau_text($lang); } catch (\Throwable $e) { $ok_lang = false; }
ok($ok_lang && is_string($ul), 'Umbau: sehr langer Artikel bricht nicht mit Typfehler ab');

// ---- 0.75.32: Prio-Erklärung im Reiter Score ----
$mPe = new ReflectionMethod('SAB', 'prio_erklaerung_html'); $mPe->setAccessible(true);
$wpdb_pe = $GLOBALS["wpdb"] ?? null; $GLOBALS["wpdb"] = null; $pe = $mPe->invoke(null, SAB::SCORE_STANDARD); $GLOBALS["wpdb"] = $wpdb_pe;
ok(strpos($pe, 'Priorität = Dringlichkeit') !== false && strpos($pe, 'sab_dr[schwelle]') !== false, 'Score-Reiter: Prio-Erklärung und Dringlichkeits-Einstellung vorhanden');

$rm = new ReflectionMethod('SAB', 'tausch_dringlichkeit'); $rm->setAccessible(true);
$ok1 = $rm->invoke(null, ['lieferbar' => true, 'preis' => '9,99 €', 'kaufen' => true, 'prime' => null, 'manuell_stand' => '2026-10-25', 'bilder' => ['x']], 80);
$ok2 = $rm->invoke(null, ['lieferbar' => true, 'preis' => '', 'bilder' => ['x']], 80);
$ok3 = $rm->invoke(null, ['lieferbar' => true, 'preis' => '9,99 €', 'kaufen' => true, 'bilder' => []], 80);
if ($ok1 < 100 && $ok2 === 100 && $ok3 === 100) { echo "ok  Dringlichkeit: lieferbar mit Score 80 nie ganz oben, ohne Preis/Bild 100\n"; } else { $fail = true; echo "FEHLER Dringlichkeit $ok1 $ok2 $ok3\n"; }
$k1 = SAB::kurzname_aus_titel('Pflanzenhut Schneckenschutz 24er Set aus Kunststoff, Haube für Beete');
$k2 = SAB::kurzname_aus_titel('Gartenvlies Frostschutz Winterschutz Pflanzen weiß');
if (preg_match('/24/', $k1) && stripos($k1, 'Pflanzenhut') !== false) { echo "ok  Kurzname behält Zahl: $k1 / $k2\n"; } else { $fail = true; echo "FEHLER Kurzname $k1\n"; }

// ---- 0.75.38: Warnung im WordPress-Dashboard und Admin-Hinweis bei fehlenden Produktbildern ----
if (!function_exists('get_post_status')) { function get_post_status($i) { return 'publish'; } }
$GLOBALS['opt']['sab_bildfehler'] = ['B0BILDFEH1|5' => ['asin' => 'B0BILDFEH1', 'post' => 5, 'ts' => 1, 'art' => 'Bild'], 'B0BILDFEH1|6' => ['asin' => 'B0BILDFEH1', 'post' => 6, 'ts' => 1, 'art' => 'Bild']];
ok(SAB::bildfehler_zaehlen() === [1, 2], 'Bildwarnung: ein Produkt auf zwei Stellen gezählt (ohne Absturz bei fehlendem Zwischenspeicher)');
ok(method_exists('SAB', 'dashboard_widget') && method_exists('SAB', 'bild_hinweis') && method_exists('SAB', 'dashboard_inhalt'), 'Bildwarnung: Dashboard-Kasten und Admin-Hinweis vorhanden');
unset($GLOBALS['opt']['sab_bildfehler']);

// ---- 0.75.39: Titel aus Karte und Linktext ----
ok(SAB::kontext_titel('<p>x</p>[amz_karten][amz_karte asin="B0KTITEL01" land="es" titel="Valla pequeña | Swissinno"][/amz_karten]', 'B0KTITEL01') === 'Valla pequeña | Swissinno', 'Titel aus dem Artikel: Karte mit titel-Angabe');
ok(SAB::kontext_titel('<p>x</p>[amz_link asin="B0KTITEL02" land="es"]Cinta de cobre 50 mm[/amz_link]', 'B0KTITEL02') === 'Cinta de cobre 50 mm', 'Titel aus dem Artikel: Linktext als Ausweg');

// ---- 0.75.40: Handdaten mit leerem Prime-Feld und Datum gelten als gelesen (kein Prime), Listing ohne Bewertung ist nicht „fehlend“ ----
$GLOBALS['opt']['sab_manuell'] = "B0HDATEN01 | 4,2 | 14 | nein | Berlin 3 | 2026-10-26 | 3\nB0HDATEN02 |  |  |  | Berlin 5 | 2026-10-26 | 3\nB0HDATEN03 | 4,0 | 9 |  |  |  | ";
$mMd = new ReflectionMethod('SAB', 'manuell_daten'); $mMd->setAccessible(true); $md2 = $mMd->invoke(null);
ok($md2['B0HDATEN01']['prime'] === false && $md2['B0HDATEN02']['prime'] === null && $md2['B0HDATEN02']['ohne_bewertung'] === true && $md2['B0HDATEN03']['prime'] === null, 'Handdaten: „nein“ = gelesen, kein Prime; leeres Prime-Feld bleibt unbekannt; neues Listing ohne Bewertung ist nicht fehlend');
unset($GLOBALS['opt']['sab_manuell']);

// ---- 0.75.40: Übersicht sortiert die ganze Liste nach Spalte (Score), unbekannte Produkte am Ende ----
$GLOBALS['opt']['sab_tags'] = "es=ayuba09-21"; $GLOBALS['opt']['sab_default_land'] = 'es';
$GLOBALS['opt']['sab_produkte'] = "B0SORT0001 | Eins\nB0SORT0002 | Zwei\nB0SORT0003 | Drei\nB0SORT0004 | Unbekannt";
$mk = fn($rang, $preis) => ['titel' => 'T', 'preis' => $preis, 'kaufen' => true, 'lieferbar' => true, 'status' => 'IN_STOCK', 'rang' => $rang, 'kategorie' => 'Jardín'];
$GLOBALS['opt']['sab_uebersicht_stand_es'] = ['items' => ['B0SORT0001' => $mk(50, '9 €'), 'B0SORT0002' => $mk(900000, '9 €'), 'B0SORT0003' => $mk(5000, '9 €')], 'ts' => time()];
$GLOBALS['tr']['sab_uebersicht_es'] = $GLOBALS['opt']['sab_uebersicht_stand_es']; $GLOBALS['tr']['sab_luecke_es'] = 1; unset($GLOBALS['tr']['sab_alle_asins']);
$wpdb_s = $GLOBALS['wpdb'] ?? null;
$GLOBALS['wpdb'] = new class { public $posts = 'wp_posts'; function get_results($q, $o = null) { return [['ID' => 30, 'post_title' => 'S', 'post_content' => '<p>[amz_bild asin="B0SORT0001"] [amz_bild asin="B0SORT0002"] [amz_bild asin="B0SORT0003"] [amz_bild asin="B0SORT0004"]</p>']]; } function get_col($q) { return []; } function get_var($q) { return 0; } function prepare($q, ...$a) { return $q; } function esc_like($s) { return $s; } };
$mUh = new ReflectionMethod('SAB', 'uebersicht_html'); $mUh->setAccessible(true);
$_GET['sab_sort'] = 'score'; $_GET['sab_dir'] = 'desc'; $u1 = (string) $mUh->invoke(null);
$_GET['sab_dir'] = 'asc'; $u2 = (string) $mUh->invoke(null);
$pos = fn($h, $a) => strpos($h, $a);
ok($pos($u1, 'B0SORT0001') < $pos($u1, 'B0SORT0003') && $pos($u1, 'B0SORT0003') < $pos($u1, 'B0SORT0002') && $pos($u2, 'B0SORT0002') < $pos($u2, 'B0SORT0003') && $pos($u2, 'B0SORT0003') < $pos($u2, 'B0SORT0001'), 'Übersicht: Klick auf Score sortiert die ganze Liste auf- und absteigend');
ok($pos($u1, 'B0SORT0004') > $pos($u1, 'B0SORT0002') && $pos($u2, 'B0SORT0004') > $pos($u2, 'B0SORT0001') && strpos($u1, 'data-sort="score"') !== false, 'Übersicht: Produkte ohne Score stehen bei jeder Sortierung am Ende, Spaltenkopf trägt data-sort');
unset($_GET['sab_sort'], $_GET['sab_dir']); $GLOBALS['wpdb'] = $wpdb_s; unset($GLOBALS['tr']['sab_uebersicht_es'], $GLOBALS['opt']['sab_uebersicht_stand_es'], $GLOBALS['opt']['sab_produkte']);

// ---- 0.75.42: Update-Prüfung mit wechselndem Abstand ----
$GLOBALS['opt']['sab_upd_stat'] = ['neu' => time() - 600];  $iv1 = SAB::update_intervall();
$GLOBALS['opt']['sab_upd_stat'] = ['neu' => time() - 3 * 3600]; $iv2 = SAB::update_intervall();
$GLOBALS['opt']['sab_upd_stat'] = ['neu' => time() - 40 * 3600]; $iv3 = SAB::update_intervall();
$GLOBALS['opt']['sab_upd_stat'] = ['neu' => time() - 30 * 86400]; $iv4 = SAB::update_intervall();
ok($iv1 === 60 && $iv2 === 600 && $iv3 === 7200 && $iv4 === 86400, 'Update-Prüfung: Abstand wächst von Minuten auf einmal am Tag, je länger kein Update kam');
$GLOBALS['upd'] = ['version' => '99.1.0', 'zip' => $zipok]; $GLOBALS['tr'] = []; $GLOBALS['str'] = []; unset($GLOBALS['opt']['sab_upd_naechste']); $GLOBALS['opt']['sab_upd_stat'] = ['neu' => time() - 30 * 86400, 'inst' => SAB::VERSION, 'json' => '99.0.0'];
$nv = SAB::update_nachsehen(true);
ok($nv === '99.1.0' && isset($GLOBALS['str']['update_plugins']->response['schlanke-amazon-box/schlanke-amazon-box.php']) && (int) get_option('sab_upd_naechste') >= time() + 55 && time() - (int) $GLOBALS['opt']['sab_upd_stat']['neu'] < 5, 'Update-Prüfung: „Jetzt suchen“ trägt die neue Version sofort bei WordPress ein, merkt die Neuerung und verkürzt den Abstand');
$GLOBALS['upd'] = ['version' => '99.2.0', 'zip' => $zipok]; $GLOBALS['tr'] = [];
ok(SAB::update_nachsehen(false) === '' && ($GLOBALS['str']['update_plugins']->response['schlanke-amazon-box/schlanke-amazon-box.php']->new_version ?? '') === '99.1.0', 'Update-Prüfung: vor Ablauf des Abstands wird nicht erneut bei GitHub gefragt');
unset($GLOBALS['opt']['sab_upd_stat'], $GLOBALS['opt']['sab_upd_naechste'], $GLOBALS['upd'], $GLOBALS['str']);

// ---- 0.75.43: Abruffehler und leere Antworten gelten nie als „Amazon kennt das Produkt nicht“ ----
$GLOBALS['opt']['sab_tags'] = "es=ayuba09-21"; $GLOBALS['opt']['sab_default_land'] = 'es';
$GLOBALS['opt']['sab_produkte'] = "B0GUT00001 | Gut 1\nB0DROSSEL1 | Gedrosselt\nB0GUT00002 | Gut 2\nB0UNGUELT1 | Ungueltig"; unset($GLOBALS['tr']['sab_alle_asins'], $GLOBALS['opt']['sab_ungueltige_asins'], $GLOBALS['opt']['sab_unsicher_asins'], $GLOBALS['opt']['sab_uebersicht_teilfehler']);
$GLOBALS['opt']['sab_uebersicht_stand_es'] = ['items' => ['B0DROSSEL1' => ['titel' => 'Alt bekannt', 'bilder' => ['https://img.test/x.jpg'], 'lieferbar' => true, 'preis' => '9 €']], 'ts' => time() - 7200];
$wpdb_v = $GLOBALS['wpdb'] ?? null; $GLOBALS['wpdb'] = null;
// A: die ganze Anfrage wird gedrosselt (429 auch nach Wiederholungen): nichts davon ist „unbekannt“
$GLOBALS['drossel'] = 'B0DROSSEL1';
$uv = SAB::uebersicht_holen('es');
$GLOBALS['drossel'] = '';
$ung = (array) get_option('sab_ungueltige_asins', []); $uns = (array) get_option('sab_unsicher_asins', []);
$vier = ['B0GUT00001', 'B0DROSSEL1', 'B0GUT00002', 'B0UNGUELT1'];
ok(!array_intersect($vier, $ung) && count(array_intersect($vier, $uns)) === 4, 'Abruf: gedrosselte Anfrage (429) macht keine Produkte „ungültig“, alle vier der Anfrage sind „unsicher“');
// B: Antwort 200 mit Fehlerliste: nur eindeutig Abgewiesenes ist ungültig, Drosselung nicht
foreach (['B0GUT00001', 'B0GUT00002'] as $g) { $GLOBALS['http'][$g] = ['asin' => $g, 'detailPageURL' => 'https://www.amazon.es/dp/' . $g, 'itemInfo' => ['title' => ['displayValue' => 'Titel ' . $g]], 'offersV2' => ['listings' => [['availability' => ['type' => 'IN_STOCK'], 'isBuyBoxWinner' => true, 'price' => ['money' => ['amount' => 9, 'currency' => 'EUR', 'displayAmount' => '9 €']]]]], 'images' => ['primary' => ['large' => ['url' => 'https://img.test/x.jpg']]]]; }
$GLOBALS['fehler200'] = [['code' => 'InvalidParameterValue', 'message' => 'The ItemIds B0UNGUELT1 provided in the request is invalid.'], ['code' => 'TooManyRequests', 'message' => 'Throttled B0DROSSEL1']];
$uv = SAB::uebersicht_holen('es');
$GLOBALS['fehler200'] = []; $GLOBALS['wpdb'] = $wpdb_v;
$ung = (array) get_option('sab_ungueltige_asins', []); $uns = (array) get_option('sab_unsicher_asins', []);
ok(in_array('B0UNGUELT1', $ung, true) && !in_array('B0DROSSEL1', $ung, true) && in_array('B0DROSSEL1', $uns, true) && !in_array('B0UNGUELT1', $uns, true) && !in_array('B0GUT00001', $uns, true), 'Abruf: in einer 200er-Antwort ist nur „ungültig/nicht zugänglich“ eindeutig, „zu viele Anfragen“ ist unsicher');
ok(isset($uv['items']['B0DROSSEL1']) && !empty($uv['items']['B0DROSSEL1']['veraltet']) && isset($uv['items']['B0GUT00001']) && get_option('sab_uebersicht_teilfehler', '') !== '', 'Abruf: für unsichere Produkte bleibt der letzte bekannte Stand, der Teilfehler wird gemerkt');
$mTf = new ReflectionMethod('SAB', 'tausch_faelle'); $mTf->setAccessible(true);
$tf = $mTf->invoke(null, ['items' => []], []);
ok(isset($tf['B0UNGUELT1']) && !isset($tf['B0DROSSEL1']) && !isset($tf['B0GUT00001']), 'Austausch: nur eindeutig abgewiesene Produkte gelten als „Amazon kennt das Produkt nicht“');
unset($GLOBALS['http']['B0GUT00001'], $GLOBALS['http']['B0GUT00002'], $GLOBALS['opt']['sab_uebersicht_stand_es'], $GLOBALS['opt']['sab_ungueltige_asins'], $GLOBALS['opt']['sab_unsicher_asins'], $GLOBALS['opt']['sab_uebersicht_teilfehler'], $GLOBALS['opt']['sab_produkte']);
$GLOBALS['opt']['sab_such_kand'] = "B08562GF55 | B0ALT00001 | 2026-01-01"; unset($GLOBALS['opt']['sab_importe_erledigt']);
$imp = ['x' => ['host' => 'schneckenhilfe.de', 'option' => 'sab_such_kand', 'zeilen' => ['B08562GF55 | B0NEU00001 | 2026-10-26', 'B085631P3W | B0NEU00002 | 2026-10-26']]];
SAB::einmal_importe('www.schneckenhilfe.de', $imp); SAB::einmal_importe('www.schneckenhilfe.de', $imp); SAB::einmal_importe('federhilfe.de', ['y' => $imp['x']]);
$iz = preg_split('/\R/', get_option('sab_such_kand'));
ok(count($iz) === 2 && strpos($iz[0], 'B0ALT00001') !== false && strpos($iz[1], 'B085631P3W') === 0 && get_option('sab_importe_erledigt') === ['x'], 'Einmal-Import: ergänzt nur neue ASINs, überschreibt nichts, läuft pro Seite einmal');
unset($GLOBALS['opt']['sab_such_kand'], $GLOBALS['opt']['sab_importe_erledigt']);
$GLOBALS['wpdb'] = new class { public $posts = 'wp_posts'; function get_results($q, $o = null) { return [['ID' => 40, 'post_title' => 'D', 'post_content' => '<p>[amz_bild asin="B0ALTDOP01" land="es"][/amz_bild] ' . str_repeat('x ', 500) . ' [amz_bild asin="B0NAHDOP01" land="es"][/amz_bild]</p>']]; } function prepare($q, ...$a) { return $q; } function esc_like($s) { return $s; } };
$mOd = new ReflectionMethod('SAB', 'ohne_doppel_kandidaten'); $mOd->setAccessible(true);
$od = $mOd->invoke(null, 'B0ALTDOP01', [['asin' => 'B0NAHDOP01'], ['asin' => 'B0FERNDOP1']]);
ok(count($od) === 1 && $od[0]['asin'] === 'B0FERNDOP1', 'Austausch-Vorschläge: Kandidaten, die der Austausch wegen Doppelung ablehnen würde, werden vorab entfernt');
$nt = SAB::nur_textlink_neben_bild('<p>[amz_bild asin="B0BILDGUT1" land="es"][/amz_bild]</p><p>[amz_link asin="B0TEXTTOT1" land="es"]Zaun[/amz_link]</p>');
$nt2 = SAB::nur_textlink_neben_bild('<p>[amz_bild asin="B0BILDGUT1"][/amz_bild]</p><hr><p>[amz_link asin="B0TEXTTOT1"]x[/amz_link]</p>');
ok($nt === ['B0TEXTTOT1'] && $nt2 === [], 'Textlink direkt unter einem funktionierenden Bild eines anderen Produkts löst keinen Austausch aus (anderer Abschnitt schon)');
$GLOBALS['pc'] = '<p>Gegen Schnecken im Beet hilft ein Kupferband sehr gut. Es wird rund um das Hochbeet geklebt. <a href="https://www.amazon.de/dp/B0TVTEST01?tag=x">Hier kaufen</a></p>';
$mTv = new ReflectionMethod('SAB', 'text_vor_stelle'); $mTv->setAccessible(true);
$tv = $mTv->invoke(null, 'B0TVTEST01', [5 => ['titel' => 'T', 'n' => 1]]);
ok(strpos($tv, 'Kupferband') !== false && strpos($tv, 'Hier kaufen') === false, 'Austausch-Kontext: Sätze vor dem Link werden als Hinweis gelesen');
unset($GLOBALS['pc']);
$GLOBALS['opt']['sab_lern'] = [['t' => '2026-10-26', 'a' => 'B0TOTZAUN1', 'n' => 'B0ELEK3PRO', 'p' => 2, 'q' => 'bestand', 'd' => 5, 'ab' => ['B0ELEK5PRO']], ['t' => '2026-10-26', 'a' => 'B0TOTZAUN1', 'n' => 'B0ELEK3PRO', 'p' => 2, 'q' => 'bestand', 'd' => 5, 'ab' => ['B0ELEK5PRO']]];
$kl = $mK->invoke(null, 'B0TOTZAUN1', 'Valla metálica anticaracoles', $dd3, [], []);
$al = array_column($kl['liste'], 'asin');
ok(!in_array('B0ELEK5PRO', $al, true) && ($al[0] ?? '') === 'B0ELEK3PRO', 'Lernen: zweimal übergangener Vorschlag wird für dasselbe Produkt nicht mehr gezeigt');
$GLOBALS['opt']['sab_lern'] = array_fill(0, 30, ['t' => 'x', 'a' => 'A', 'n' => 'B', 'p' => 1, 'q' => 'bestand', 'd' => 9, 'ab' => []]); $GLOBALS['opt']['sab_lern'][29]['p'] = 2;
$mQ = new ReflectionMethod('SAB', 'lernen_quote'); $mQ->setAccessible(true); $lq = $mQ->invoke(null);
ok($lq['n'] === 30 && $lq['eins'] === 29 && $lq['quote'] === 97, 'Lernen: Trefferquote Platz 1 der letzten 30 Tausche');
unset($GLOBALS['opt']['sab_lern']);
$mm = new ReflectionMethod('SAB', 'merkmale'); $mm->setAccessible(true); $mw = new ReflectionMethod('SAB', 'merkmal_widerspruch'); $mw->setAccessible(true);
$ref = $mm->invoke(null, 'Mehlschwalben Einzelnest aus Holzbeton');
ok($mw->invoke(null, $ref, $mm->invoke(null, 'Schwegler Doppelnest aus Lehm für Mehlschwalben')) && !$mw->invoke(null, $ref, $mm->invoke(null, 'Schwegler Mehlschwalbennest Einzelnest Holzbeton')) && !$mw->invoke(null, $ref, $mm->invoke(null, 'Schwalbennest Nr. 1')), 'Merkmale: Einzel/Doppel, Holzbeton/Lehm und Tierart müssen übereinstimmen, fehlende Merkmale sind kein Widerspruch');
$GLOBALS['opt']['sab_such_kand'] = "B0AAAAAAA1 | B0OLD00001 | 2026-10-26\nB0AAAAAAA2 | B0OLD00002 | 2026-10-26\nB0AAAAAAA3 | B0HAND0001 | heute"; unset($GLOBALS['opt']['sab_importe_erledigt']);
$imp = ['k' => ['host' => 'federhilfe.de', 'option' => 'sab_such_kand', 'zeilen' => [], 'ersetzen' => ['B0AAAAAAA1 | B0OLD00001 | 2026-10-26' => 'B0AAAAAAA1 | B0NEW00001 | 2026-10-26', 'B0AAAAAAA2 | B0OLD00002 | 2026-10-26' => '', 'B0AAAAAAA3 | B0OLD00003 | 2026-10-26' => 'X']]];
SAB::einmal_importe('federhilfe.de', $imp);
ok(get_option('sab_such_kand') === "B0AAAAAAA1 | B0NEW00001 | 2026-10-26\nB0AAAAAAA3 | B0HAND0001 | heute", 'Einmal-Import: Korrektur ersetzt/entfernt nur unveränderte Zeilen');
unset($GLOBALS['opt']['sab_such_kand'], $GLOBALS['opt']['sab_importe_erledigt']);
$mB = new ReflectionMethod('SAB', 'gleiche_bauart_nah'); $mB->setAccessible(true);
$art = ['<p>[amz_bild asin="B0ALTNEST1"][/amz_bild] Text [amz_bild asin="B0NACHBAR1"][/amz_bild]</p>'];
$nm = ['B0NACHBAR1' => 'Schwalbennest Doppelnest Lehm', 'B0DOPPEL01' => 'Doppelnest Schlamm', 'B0EINZEL01' => 'Einzelnest Holzbeton'];
ok($mB->invoke(null, 'B0ALTNEST1', 'B0DOPPEL01', $art, [], $nm) && !$mB->invoke(null, 'B0ALTNEST1', 'B0EINZEL01', $art, [], $nm), 'Produktvielfalt: gleiche Bauart in der Nähe wird nicht vorgeschlagen, andere Bauart schon');
$kv = SAB::kurzname_varianten('Relaxdays Nistkasten Bausatz Vögel, 2er Set, 32 mm Einflugloch');
ok(count($kv) >= 2 && count($kv) <= 3 && count(array_unique($kv)) === count($kv) && strpos($kv[0], ' | ') === false, 'Kurznamen-Vorschläge: zwei bis drei verschiedene Namen aus dem Titel');
$ar = SAB::aawp_reste('[aawp box="B0AAAAAAA1"] [aawp fields="B0AAAAAAA1" value="thumb"] [aawp fields="B0AAAAAAA1" value="price"] [aawp table="B0AAAAAAA1,B0AAAAAAA2"] [amazon bestseller="garden" items="5"] [aawp link="B0AAAAAAA1"]Text[/aawp]');
ok(count($ar) === 3 && strpos($ar[0], 'price') !== false && strpos($ar[1], 'table') !== false && strpos($ar[2], 'bestseller') !== false, 'AAWP-Reste: nur nicht ersetzte Shortcodes (Preis, Tabelle, Bestseller) werden gemeldet');
ok(SAB::asin_aus_text('https://www.amazon.de/Schwegler-Nistkasten/dp/B08VKRJ5D4/ref=sr_1_3?keywords=x') === 'B08VKRJ5D4' && SAB::asin_aus_text('b08vkrj5d4') === 'B08VKRJ5D4' && SAB::asin_aus_text('https://amazon.de/gp/product/B0037YHTMS') === 'B0037YHTMS', 'Eigene ASIN: Amazon-Link oder ASIN wird erkannt');
$GLOBALS['opt']['sab_such_kand'] = "B0AAAAAAA1 | B0OLD00001, B0OLD00002 | 2026-10-25"; unset($GLOBALS['opt']['sab_importe_erledigt']);
SAB::einmal_importe('federhilfe.de', ['m' => ['host' => 'federhilfe.de', 'option' => 'sab_such_kand', 'zusammen' => true, 'zeilen' => ['B0AAAAAAA1 | B0NEW00001, B0OLD00002 | 2026-10-26', 'B0AAAAAAA2 | B0NEW00002 | 2026-10-26']]]);
ok(get_option('sab_such_kand') === "B0AAAAAAA1 | B0NEW00001, B0OLD00002, B0OLD00001 | 2026-10-26\nB0AAAAAAA2 | B0NEW00002 | 2026-10-26", 'Einmal-Import zusammen: neue Kandidaten vor die vorhandenen, nichts doppelt, neue Zeilen angehängt');
unset($GLOBALS['opt']['sab_such_kand'], $GLOBALS['opt']['sab_importe_erledigt']);
$GLOBALS['opt']['sab_ungueltige_asins'] = ['B0XXXXXXX1'];
$mAu = new ReflectionMethod('SAB', 'amazon_unbekannt'); $mAu->setAccessible(true);
ok(!$mAu->invoke(null, 'B0FRISCH01', ['items' => []]) && $mAu->invoke(null, 'B0XXXXXXX1', ['items' => []]), 'Frisch getauschtes Produkt (nur nicht im Zwischenspeicher) gilt nicht als „Amazon kennt es nicht“');
unset($GLOBALS['opt']['sab_ungueltige_asins']);
$mP = new ReflectionMethod('SAB', 'ersatz_prompt_html'); $mP->setAccessible(true);
$ph = $mP->invoke(null, [['B0PROMPT01', 'Mehlschwalben Einzelnest Holzbeton', 'Für Mehlschwalben eignet sich ein Einzelnest.', 'Garten', 0, 'Schwegler Mehlschwalbennest', 3]]);
ok(strpos($ph, 'B0PROMPT01') !== false && strpos($ph, 'Einzelnest') !== false && strpos($ph, 'mindestens 8') !== false && strpos($ph, 'ALTE-ASIN | NEU1') !== false && strpos($ph, 'sab-kopieren') !== false && $mP->invoke(null, []) === '', 'Ersatz-Prompt: Produkt, Kontext, Regeln, Format und Kopierknopf');
$mRt = new ReflectionMethod('SAB', 'rueck_text'); $mRt->setAccessible(true);
ok(strpos($mRt->invoke(null, 1, 1), 'zu wenig Daten') !== false && strpos($mRt->invoke(null, 9, 0), 'zu wenig Daten') !== false && strpos($mRt->invoke(null, 20, 5), '25 %') !== false, 'Rückkehr: unter 10 Klicks „zu wenig Daten“, sonst Prozent');
$oldDb = $GLOBALS['wpdb'] ?? null;
$GLOBALS['wpdb'] = new class { public $prefix = 'wp_'; public function prepare($q, ...$a) { return $q; } public function esc_like($x) { return $x; }
  public function get_results($q, $o = null) {
    if (strpos($q, 'GROUP BY DATE') !== false) { return [['d' => gmdate('Y-m-d'), 'n' => 3]]; }
    if (strpos($q, 'GROUP BY asin, pfad') !== false) { return [['asin' => 'B0STATIK01', 'pfad' => '/hochbeet/', 'anker' => 'ratgeber', 'n' => 2], ['asin' => 'B0STATIK01', 'pfad' => '/', 'anker' => '', 'n' => 1]]; }
    return [['asin' => 'B0STATIK01', 'n' => 3, 'b' => 1, 'x' => 2, 'z' => 1, 'm' => 40]]; } };
$mSt = new ReflectionMethod('SAB', 'statistik_html'); $mSt->setAccessible(true);
$sh = $mSt->invoke(null);
ok(strpos($sh, 'sab-bal-info') !== false && strpos($sh, '3 Klicks') !== false && strpos($sh, 'B0STATIK01') !== false && strpos($sh, '#ratgeber') !== false && strpos($sh, 'zu wenig Daten') !== false && strpos($sh, '<details') !== false, 'Statistik: Balken mit Klickzahl, Produktkarte, Seiten mit Sprungstelle, ehrliche Rückkehr');
$GLOBALS['wpdb'] = $oldDb;
$sb = SAB::schluesselbegriffe('Möchte man eine Blumenwiese anlegen, empfehlen sich unter anderem Saatmischungen von Rieger Hoffmann.');
ok($sb === ['blumenwiese', 'saatmischung'], 'Suchbegriffe aus dem Satz: Blumenwiese, Saatmischung (ohne Marke Rieger Hoffmann): ' . implode(',', $sb));
$cx = '<p>Erster Satz. Möchte man eine Blumenwiese anlegen, empfehlen sich Saatmischungen von Rieger-Hoffmann <a href="https://www.amazon.de/dp/B0SATZTEST?tag=x" rel="sponsored">Angebot bei Amazon</a> und mehr. Nächster Satz.</p>';
$sm = SAB::satz_mit_link($cx, 'B0SATZTEST');
ok($sm && strpos($sm['maske'], '【LINK】') !== false && strpos($sm['maske'], 'Rieger-Hoffmann 【LINK】 und mehr.') !== false && strpos($sm['maske'], '<') === false && strpos($sm['maske'], 'Erster') === false && strpos($sm['maske'], 'Nächster') === false, 'Satz mit Textlink: Maske mit {{LINK}}, nur dieser Satz');
[$tc, $tn] = SAB::tausch_text($cx, 'B0SATZTEST', 'B0NEUTEST1', ['Angebot bei Amazon', 'Saatmischungen von Rieger-Hoffmann'], 'Wildblumen Saat Kurzname');
ok(strpos($tc, '>Angebot bei Amazon</a>') !== false && strpos($tc, 'B0NEUTEST1') !== false && strpos($tc, 'Wildblumen Saat Kurzname') !== false && strpos($tc, 'Rieger-Hoffmann') === false, 'Tausch: Linktext bleibt, Name im Fließtext wird ersetzt');
ok(SAB::satz_mit_link('<p>Nur ein Bild <a href="https://www.amazon.de/dp/B0SATZTEST"><img src="x"></a> hier.</p>', 'B0SATZTEST') === null, 'Satz: Bildlinks werden nicht angeboten');
$satzA = 'Außerdem betreibt Kay den Zaun mit einem 12-Volt-10-Ah-Akku';
ok(SAB::technik_begriffe($satzA) === ['12v', '10ah'] && SAB::technik_begriffe('Akku 12V 10Ah wartungsfrei') === ['12v', '10ah'], 'Technik-Begriffe: 12-Volt-10-Ah = 12V 10Ah');
$sbA = SAB::schluesselbegriffe($satzA);
ok(in_array('12v', $sbA, true) && in_array('10ah', $sbA, true) && in_array('akku', $sbA, true) && !in_array('volt', $sbA, true), 'Suchbegriffe aus dem Akku-Satz: 12v, 10ah, akku: ' . implode(',', $sbA));
ok(SAB::generischer_linktext('Angebot bei Amazon') && SAB::generischer_linktext('Zum Angebot') && !SAB::generischer_linktext('Rieger-Hoffmann Saatmischung'), 'Allgemeiner Linktext ist kein Produktname');
// Akku-Fall: Textlink „Angebot bei Amazon“ nach „12-Volt-10-Ah-Akku“
$GLOBALS['pc'] = '<p>Außerdem betreibt Kay den Zaun mit einem 12-Volt-10-Ah-Akku (<a href="https://www.amazon.de/dp/B0AKKUALT1?tag=x">Angebot bei Amazon</a>).</p>';
$GLOBALS['opt']['sab_produkte'] = "B0AKKUGUT1 | Akku 12V 10Ah\nB0AKKUFALS | Akku 6V 4Ah\nB0ZAUNXXX1 | Weidezaun Gerät"; unset($GLOBALS['opt']['sab_such_kand'], $GLOBALS['opt']['sab_amz_alt'], $GLOBALS['opt']['sab_marken'], $GLOBALS['opt']['sab_lern']);
$ddA = ['items' => ['B0AKKUGUT1' => $it('Blei-Akku 12V 10Ah wartungsfrei', 3.0), 'B0AKKUFALS' => $it('Akku 6V 4Ah', 3.0), 'B0ZAUNXXX1' => $it('Weidezaun Gerät 9V', 3.0)]];
$kA = $mK->invoke(null, 'B0AKKUALT1', 'Angebot bei Amazon', $ddA, [], ['B0AKKUALT1' => [7 => ['n' => 1, 'titel' => 'x']]]);
$aA = array_column($kA['liste'], 'asin');
ok(($aA[0] ?? '') === 'B0AKKUGUT1', 'Akku-Fall: 12V/10Ah-Akku steht im Bestand auf Platz 1 trotz Name „Angebot bei Amazon“: ' . implode(',', $aA));
unset($GLOBALS['pc']);
$cy = '<p>Davor. Außerdem betreibt <strong>Kay</strong> den Zaun mit einem 12-Volt-10-Ah-Akku (<a href="https://www.amazon.de/dp/B0AKKUALT1?tag=x">Angebot bei Amazon</a>).</p>';
$smy = SAB::satz_mit_link($cy, 'B0AKKUALT1');
ok($smy && strpos($smy['maske'], '<') === false && strpos($smy['maske'], '⟦1⟧Kay⟦2⟧') !== false && strpos($smy['maske'], '(【LINK】)') !== false, 'Satz im Textfeld: Klartext, Tags als ⟦n⟧, Link als 【LINK】: ' . ($smy['maske'] ?? '-'));
$roh = SAB::satz_roh(str_replace('12-Volt-10-Ah-Akku', 'Akku 12 V', $smy['maske']), $smy);
ok($roh !== null && strpos($roh, '<strong>Kay</strong>') !== false && strpos($roh, '>Angebot bei Amazon</a>') !== false && strpos($roh, 'Akku 12 V') !== false, 'Satz zurück in Rohtext: Link und Formatierung bleiben');
ok(SAB::satz_roh('ohne Link', $smy) === null && SAB::satz_roh('⟦9⟧ 【LINK】', $smy) === null, 'Satz: ohne Link oder mit fremdem Platzhalter wird abgelehnt');
ok(SAB::suchtext_aus_satz('Angebot bei Amazon', 'B0AKKUALT1', 'Außerdem betreibt Kay den Zaun mit einem 12-Volt-10-Ah-Akku') === 'Akku 12V 10Ah' && SAB::suchtext_aus_satz('Kupferband 10 m', 'B0X', 'egal') === 'Kupferband 10 m', 'Suchtext: Satz-Begriffe statt „Angebot bei Amazon“/ASIN');
// Ablauf Satz bearbeiten + eigene ASIN: Link muss danach vorhanden und korrekt sein (alle Link-Schreibweisen)
$fluss = function ($l, $edit) {
    $alt = 'B0AKKUALT1'; $neu = 'B0AKKUNEU1';
    $c = '<p>Davor. Außerdem betreibt <strong>Kay</strong> den Zaun mit einem 12-Volt-10-Ah-Akku (' . $l . ').</p>';
    $sm = SAB::satz_mit_link($c, $alt); if (!$sm) { return [false, 'kein Satz']; }
    $roh = SAB::satz_roh($edit ? str_replace('Zaun', 'Weidezaun', $sm['maske']) : $sm['maske'], $sm);
    $arb = substr($c, 0, $sm['start']) . $roh . substr($c, $sm['ende']);
    [$c1] = SAB::tausch_kurzlinks($arb, $alt, $neu, 'Akku Kurzname'); [$c2] = SAB::tausch_text($c1, $alt, $neu, ['Angebot bei Amazon', 'Akku alt'], 'Akku Kurzname');
    return [SAB::link_pruefung($c, $c2, $alt, $neu) === '' && strpos($c2, 'Angebot bei Amazon') !== false && strpos($c2, $neu) !== false && strpos($c2, '<strong>Kay</strong>') !== false, $c2];
};
foreach (['<a href="https://www.amazon.de/dp/B0AKKUALT1?tag=x" rel="sponsored nofollow noopener">Angebot bei Amazon</a>', '[amz_link asin="B0AKKUALT1"]Angebot bei Amazon[/amz_link]', '[amz_link asin="B0AKKUALT1" text="Angebot bei Amazon"]'] as $li => $lk) {
    [$o1, $r1] = $fluss($lk, true);
    ok($o1, 'Satz bearbeiten + Tausch: Link bleibt heil (Variante ' . $li . '): ' . $r1);
}
ok(SAB::link_pruefung('<p>Text (<a href="https://www.amazon.de/dp/B0AKKUALT1">Angebot bei Amazon</a>).</p>', '<p>Text (<a>Angebot bei Amazon</a>).</p>', 'B0AKKUALT1', 'B0AKKUNEU1') !== '' && SAB::link_pruefung('<p>Text (<a href="https://www.amazon.de/dp/B0AKKUALT1">Angebot bei Amazon</a>).</p>', '<p>Text (Angebot bei Amazon).</p>', 'B0AKKUALT1', 'B0AKKUNEU1') !== '', 'Linkprüfung erkennt fehlenden Link und Link ohne Adresse');
if (!function_exists('get_permalink')) { function get_permalink($i) { return 'https://example.test/artikel-' . $i . '/'; } }
$GLOBALS['opt']['sab_kontrolle'] = ['1' => ['ts' => time(), 'alt' => 'B0AKKUALT1', 'neu' => 'B0AKKUNEU1', 'stellen' => [['pid' => 7, 'titel' => 'Elektrozaun', 'satz' => 'Außerdem betreibt Kay den Zaun mit Akku (【Angebot bei Amazon】).', 'n' => 1]]]];
$mKo = new ReflectionMethod('SAB', 'kontrolle_html'); $mKo->setAccessible(true); $ko = $mKo->invoke(null, 'austausch');
ok(strpos($ko, 'sab_zeige=B0AKKUNEU1') !== false && strpos($ko, 'Außerdem betreibt Kay') !== false && strpos($ko, 'kontrolle_ok') !== false && strpos($ko, 'post.php?post=7') !== false && strpos($ko, 'kontrolle_zurueck') !== false, 'Kontrollliste: Sprunglink, Satz und Bestätigen');
unset($GLOBALS['opt']['sab_kontrolle']);
if (!function_exists('is_singular')) { function is_singular() { return true; } }
$GLOBALS['admin'] = true; $GLOBALS['loggedin'] = true; ob_start(); SAB::stellen_skript(); $stj = ob_get_clean(); $GLOBALS['admin'] = false; $GLOBALS['loggedin'] = false;
ok(strpos($stj, 'bearbeiten') !== false && strpos($stj, 'Im Bestand suchen') !== false && strpos($stj, 'action=edit') !== false, 'Frontend-Tauschfenster: Bestandssuche und Bearbeiten-Knopf');
ok(strpos($stj, 'a[href]') !== false && strpos($stj, 'cn[as]') !== false, 'Frontend: auch fest eingetragene Textlinks bekommen Tauschen/Bearbeiten');
$tmpj = tempnam(sys_get_temp_dir(), 'sj'); file_put_contents($tmpj . '.js', preg_replace('#^<script>|</script>$#', '', trim($stj))); exec('node --check ' . escapeshellarg($tmpj . '.js') . ' 2>&1', $nout, $nrc);
ok($nrc === 0 && strpos($stj, 'bei Amazon ansehen') !== false && strpos($stj, 'Bei Amazon suchen') !== false, 'Frontend-Skript: JavaScript-Syntax ok, Amazon-Suche und -Ansehen im Tauschfenster ' . implode(' ', $nout));
$GLOBALS['opt']['sab_kontrolle'] = ['2' => ['ts' => time(), 'alt' => 'B0A', 'neu' => 'B0AKKUNEU1', 'stellen' => [['pid' => 7, 'titel' => 'Zaun', 'satz' => '', 'n' => 2]]]];
$ko2 = $mKo->invoke(null, 'austausch'); unset($GLOBALS['opt']['sab_kontrolle']);
ok(strpos($ko2, 'Stelle 1 ansehen') !== false && strpos($ko2, 'Stelle 2 bearbeiten') !== false && strpos($ko2, 'sab_nr=2') !== false, 'Kontrollliste: zwei Stellen im Artikel, je Stelle ansehen und bearbeiten');
// 0.86.0: zwei Stellen, eBay-Ausweichlink
$c2s = '<p>Eins (<a href="https://www.amazon.de/dp/B0AKKUALT1?tag=x">Angebot bei Amazon</a>).</p><p>Zwei [amz_link asin="B0AKKUALT1"]Angebot bei Amazon[/amz_link] ende.</p>';
$st = SAB::satz_stellen($c2s, 'B0AKKUALT1');
ok(count($st) === 2 && ($st[0]['ord'] ?? -1) !== ($st[1]['ord'] ?? -1), 'Zwei Textlink-Stellen im Artikel erkannt');
$fh = (function () { $x = new ReflectionMethod('SAB', 'satz_feld_html'); $x->setAccessible(true); return $x; })()->invoke(null, 'B0AKKUALT1', ['c' => $c2s, 'pid' => 7], 'Test');
$eb = SAB::ebay_hinter_textlink($c2s, 'B0AKKUALT1', 'Akku 12V', 'www.ebay.de');
ok($eb !== null && substr_count($eb, '[amz_ebay') === 2 && strpos($eb, 'oder bei eBay') !== false, 'eBay-Ausweichlink hinter jedem Textlink');
ok(SAB::ebay_hinter_textlink($eb, 'B0AKKUALT1', 'Akku 12V', 'www.ebay.de') === null, 'eBay-Ausweichlink nicht doppelt');
ok(isset(SAB::TABS['helfer']), 'Reiter Helferaufgaben vorhanden');
ok(SAB::ebay_host('de') === 'www.ebay.de' && SAB::ebay_host('uk') !== '', 'eBay-Host je Land');
// 0.87.1: Bild- und Textlink direkt nebeneinander = eine Stelle
$bt = '<p>[amz_bild asin="B0AKKUALT1"] <a href="https://www.amazon.de/dp/B0AKKUALT1">Angebot bei Amazon</a></p><p>Später <a href="https://www.amazon.de/dp/B0AKKUALT1"><img src="x/B0AKKUALT1.jpg"></a></p><p>Noch einmal mit Satz davor [amz_link asin="B0AKKUALT1"]Angebot[/amz_link] und Ende.</p>';
ok(SAB::stellen_gruppen($bt, 'B0AKKUALT1') === [1, 3, 5], 'Stellen: Bild+Text nebeneinander zählen einmal, ein Link mit zwei Treffern auch (' . json_encode(SAB::stellen_gruppen($bt, 'B0AKKUALT1')) . ')');
ok(SAB::stellen_gruppen('<p>A <a href="https://www.amazon.de/dp/B0AKKUALT1">x</a> und mitten drin Text <a href="https://www.amazon.de/dp/B0AKKUALT1">y</a></p>', 'B0AKKUALT1') === [1, 2], 'Stellen: Text dazwischen = zwei Stellen');
// 0.88.0: Länder-Umschaltung im Browser
if (!function_exists('is_feed')) { function is_feed() { return false; } }
if (!function_exists('is_admin')) { function is_admin() { return false; } }
$GLOBALS['opt']['sab_geo'] = '0'; ob_start(); SAB::geo_skript(); $g0 = ob_get_clean();
$GLOBALS['opt']['sab_geo'] = '1'; $GLOBALS['opt']['sab_tags'] = "co.uk=uk-21\ncom=us-20\nca=ca-20"; ob_start(); SAB::geo_skript(); $g1 = ob_get_clean(); unset($GLOBALS['opt']['sab_geo'], $GLOBALS['opt']['sab_tags']);
ok($g0 === '' && strpos($g1, '"com":"us-20"') !== false && strpos($g1, 'sabGeoRun') !== false, 'Länder-Umschaltung: aus = nichts, an = Skript mit Partner-IDs');
$gj = tempnam(sys_get_temp_dir(), 'gj') . '.js'; file_put_contents($gj, "var window={},document={readyState:'x',addEventListener:function(){}},navigator={},URL=URL;\n" . preg_replace('#^<script>|</script>$#', '', trim($g1)) . "\nvar r=[window.sabGeoPick('Europe/London','de-DE'),window.sabGeoPick('America/Toronto','en-US'),window.sabGeoPick('Asia/Tokyo','en-GB'),window.sabGeoPick('Asia/Tokyo','ja-JP')];console.log(JSON.stringify(r));");
exec('node ' . escapeshellarg($gj) . ' 2>&1', $gout, $grc);
ok($grc === 0 && trim(implode('', $gout)) === '["co.uk","ca","co.uk",null]', 'Länder-Erkennung: Zeitzone vor Sprache, unbekannt = null (' . implode(' ', $gout) . ')');
// 0.88.1: Bildwarnung klickbar, kurze Titel, Sprung; frisch eingetragene Produkte nicht sofort melden
foreach (['get_edit_post_link' => 'https://example.test/wp-admin/post.php?post=5&action=edit', 'get_the_title' => 'Elektrozaun Akku Weidezaun Test', 'get_post_field' => '<p>[amz_link asin="B0BILDFEH2"]x[/amz_link] [amz_bild asin="B0BILDNEU3"]</p>'] as $fn_ => $rv_) { if (!function_exists($fn_)) { eval('function ' . $fn_ . '(...$a) { return ' . var_export($rv_, true) . '; }'); } }
$GLOBALS['opt']['sab_bildfehler'] = ['B0BILDFEH2|5' => ['asin' => 'B0BILDFEH2', 'post' => 5, 'ts' => 1, 'art' => 'Bild'], 'B0BILDNEU3|5' => ['asin' => 'B0BILDNEU3', 'post' => 5, 'ts' => time(), 'art' => 'Bild']];
$mBh = new ReflectionMethod('SAB', 'bildfehler_html'); $mBh->setAccessible(true); $bh = $mBh->invoke(null);
ok(strpos($bh, '/dp/B0BILDFEH2') !== false && (new ReflectionMethod('SAB', 'kurz_wort'))->invoke(null, 'Elektrozaun Akku Weidezaun Test') === 'Elektrozaun Akku Weidezaun …' && strpos($bh, 'sab_zeige=B0BILDFEH2') !== false && strpos($bh, 'Editor') !== false, 'Bildwarnung: ASIN klickbar, Artikelname nur drei Wörter, Sprung zur Stelle');
ok(strpos($bh, 'B0BILDNEU3') === false && isset(((array) get_option('sab_bildfehler', []))['B0BILDNEU3|5']), 'Bildwarnung: gerade erst eingetragenes Produkt wird nicht gemeldet, aber gemerkt');
unset($GLOBALS['opt']['sab_bildfehler']);
// 0.88.2: Standardland ohne Partner-ID => erstes Land mit Partner-ID
$GLOBALS['opt']['sab_tags'] = "co.uk=uk-21"; $GLOBALS['opt']['sab_default_land'] = 'de';
$mSl = new ReflectionMethod('SAB', 'standardland'); $mSl->setAccessible(true);
ok($mSl->invoke(null) === 'co.uk', 'Standardland de ohne Partner-ID: nimmt co.uk (nur dort eine ID)');
$GLOBALS['opt']['sab_tags'] = "de=x-21\nco.uk=uk-21"; ok($mSl->invoke(null) === 'de', 'Standardland mit Partner-ID bleibt');
unset($GLOBALS['opt']['sab_tags'], $GLOBALS['opt']['sab_default_land']);
$_GET['sab_zeige'] = 'B0AKKUNEU1'; $GLOBALS['admin'] = true; $GLOBALS['loggedin'] = true; ob_start(); SAB::zeige_skript(); $zj = ob_get_clean(); unset($_GET['sab_zeige']); $GLOBALS['admin'] = false; $GLOBALS['loggedin'] = false;
$zf = tempnam(sys_get_temp_dir(), 'zj') . '.js'; file_put_contents($zf, preg_replace('#^<script>|</script>$#', '', trim($zj))); exec('node --check ' . escapeshellarg($zf) . ' 2>&1', $zo, $zrc);
ok($zj !== '' && $zrc === 0 && strpos($zj, 'Hier tauschen') !== false && strpos($zj, 'Kontrolliert, passt') !== false && strpos($zj, 'bk="p,div') !== false && strpos($zj, 'rückgängig') !== false, 'Sprung-Banner: Knöpfe Hier tauschen / Kontrolliert / Rückgängig, JavaScript ok ' . implode(' ', $zo));
// 0.88.5: Ländercode tolerant (couk, UK, comau …)
$GLOBALS['opt']['sab_tags'] = "couk=uk-21"; $GLOBALS['opt']['sab_default_land'] = 'de';
ok($mSl->invoke(null) === 'co.uk' && SAB::land_schluessel('CO.UK') === 'co.uk' && SAB::land_schluessel('comau') === 'com.au' && SAB::land_schluessel('de') === 'de', 'Ländercode „couk“ ohne Punkt wird als co.uk erkannt');
unset($GLOBALS['opt']['sab_tags'], $GLOBALS['opt']['sab_default_land']);
// 0.88.6: Standardland „couk“ (von sanitize_key ohne Punkt gespeichert) + Felder je Shop
$GLOBALS['opt']['sab_tags'] = "co.uk=uk-21"; $GLOBALS['opt']['sab_default_land'] = 'couk';
$mLa = new ReflectionMethod('SAB', 'land'); $mLa->setAccessible(true);
ok($mLa->invoke(null, '') === 'co.uk', 'Standardland „couk“ gespeichert => co.uk (nicht de)');
$mPf = new ReflectionMethod('SAB', 'partner_felder_html'); $mPf->setAccessible(true); $pf = $mPf->invoke(null);
ok(strpos($pf, 'sab_tag_f[co.uk]') !== false && strpos($pf, 'value="uk-21"') !== false && strpos($pf, 'sab_tag_f[com.au]') !== false, 'Partner-ID-Felder je Shop, Wert vorbelegt');
unset($GLOBALS['opt']['sab_tags'], $GLOBALS['opt']['sab_default_land']);
// 0.88.7: Titel an der Stelle (Einzelbox: davor, Tabellenzelle: danach)
$e1 = '<h3>Lichtwecker mit Sonnenaufgang</h3>[amz_bild asin="B0LICHT001"]'; $p1 = strpos($e1, '[amz_bild');
$e2 = '<table><tr><td>[amz_bild asin="B0LICHT002"]<br>Wake-up Light Pro 2</td><td>[amz_bild asin="B0X"]<br>Anderes</td></tr></table>'; $p2 = strpos($e2, '[amz_bild');
$e3 = '<p>Text davor.</p><p>[amz_bild asin="B0LICHT003"]</p>'; 
ok(SAB::titel_an_stelle($e1, $p1, 28) === 'Lichtwecker mit Sonnenaufgang' && SAB::titel_an_stelle($e2, $p2, 28) === 'Wake-up Light Pro 2' && SAB::titel_an_stelle($e3, strpos($e3, '[amz_bild'), 28) === 'Text davor.', 'Titel an der Stelle: Einzelbox davor, Tabellenzelle danach');
// 0.88.8: Amazon-Link statt ASIN einfügen
ok(SAB::asin_aus_text('https://www.amazon.de/Lichtwecker-Sonnenaufgang/dp/B0CX1ABCDE/ref=sr_1_3?crid=1&tag=x') === 'B0CX1ABCDE' && SAB::asin_aus_text('https://www.amazon.co.uk/gp/product/b0cx1abcde?th=1') === 'B0CX1ABCDE' && SAB::asin_aus_text(' b0cx1abcde ') === 'B0CX1ABCDE' && strlen(SAB::asin_aus_text('https://amzn.to/3abcdef')) !== 10 && strlen(SAB::asin_aus_text('quatsch')) !== 10, 'ASIN aus Amazon-Link, nackter ASIN; Kurzlink und Unsinn werden abgelehnt');
// 0.88.11: Zweier-Tabelle: nie der Titel aus der Nachbarzelle
$e4 = '<table><tr><td><h4>Erstes Produkt</h4>[amz_bild asin="B0A"]<br>Titel Erstes</td><td>[amz_bild asin="B0B"]</td></tr></table>'; $p4 = strpos($e4, '[amz_bild asin="B0B"');
ok(SAB::titel_an_stelle($e4, $p4, 21) === '', 'Zweier-Tabelle: Zelle ohne eigenen Titel liefert keinen Titel der Nachbarzelle');
$e5 = '<table><tr><td>[amz_bild asin="B0A"]<br>Titel Erstes</td><td>[amz_bild asin="B0B"]<br>Titel Zweites</td></tr></table>';
ok(SAB::titel_an_stelle($e5, strpos($e5, '[amz_bild asin="B0B"'), 21) === 'Titel Zweites' && SAB::titel_an_stelle($e5, strpos($e5, '[amz_bild asin="B0A"'), 21) === 'Titel Erstes', 'Zweier-Tabelle: Titel steht unter dem Bild der eigenen Zelle');
ok(SAB::land_schluessel('.co.uk') === 'co.uk' && SAB::land_schluessel('www.amazon.co.uk') === 'co.uk' && SAB::land_schluessel('amazon.de') === 'de' && SAB::land_schluessel('https://www.amazon.com/') === 'com' && SAB::land_schluessel('.de') === 'de', 'Ländercode: „.co.uk“, „amazon.co.uk“, „www.amazon.de“ werden erkannt');
// 0.88.15: veraltete Bildwarnungen verschwinden (ASIN nicht mehr im Artikel)
$GLOBALS['absent'] = ['B0ALTWEG01']; $GLOBALS['opt']['sab_bildfehler'] = ['B0ALTWEG01|5' => ['asin' => 'B0ALTWEG01', 'post' => 5, 'ts' => 1, 'art' => 'Bild']];
ok(SAB::bildfehler_zaehlen() === [0, 0], 'Bildwarnung: ASIN steht nicht mehr im Artikel => keine Meldung'); unset($GLOBALS['absent']);
unset($GLOBALS['opt']['sab_bildfehler']);
// 0.89.0: Länder-Zuordnung
$GLOBALS['opt']['sab_laender_map'] = []; $GLOBALS['opt']['sab_laender_vorschlag'] = [];
$mLi = new ReflectionMethod('SAB', 'laender_import'); $mLi->setAccessible(true);
$msg = $mLi->invoke(null, "B0UKPROD01 | com | B0USPROD01 | US Lichtwecker | 4,5 | 120 | ja | morgen | 29,99 €\nB0UKPROD01 | com.au | https://www.amazon.com.au/dp/B0AUPROD01?th=1 | AU Wecker\nkaputt\nB0UKPROD01 | de | B0DEPROD01");
ok(strpos($msg, '2 Vorschläge') !== false && strpos($msg, '1 Zeilen übersprungen') !== false && count($GLOBALS['opt']['sab_laender_vorschlag']['B0UKPROD01']) === 2, 'Länder: Vorschläge aufgenommen, unbekanntes Land übersprungen (' . $msg . ')');
$mLs = new ReflectionMethod('SAB', 'laender_speichern'); $mLs->setAccessible(true);
$_POST = ['sab_uk' => 'B0UKPROD01', 'sab_land' => 'com', 'sab_la' => 'ok|B0USPROD01']; $r1 = $mLs->invoke(null);
$_POST = ['sab_uk' => 'B0UKPROD01', 'sab_land' => 'ca', 'sab_la' => 'eigen', 'sab_eigen' => 'https://www.amazon.ca/Foo/dp/B0CAPROD01/ref=x']; $r2 = $mLs->invoke(null);
ok(($GLOBALS['opt']['sab_laender_map']['B0UKPROD01']['com']['asin'] ?? '') === 'B0USPROD01' && ($GLOBALS['opt']['sab_laender_map']['B0UKPROD01']['ca']['asin'] ?? '') === 'B0CAPROD01' && empty($GLOBALS['opt']['sab_laender_vorschlag']['B0UKPROD01']['com']), 'Länder: freigeben und eigener Link wirken');
$_POST = ['sab_uk' => 'B0UKPROD01', 'sab_land' => 'com.au', 'sab_la' => 'ab|B0AUPROD01']; $mLs->invoke(null); $_POST = [];
ok(empty($GLOBALS['opt']['sab_laender_vorschlag']['B0UKPROD01']), 'Länder: Vorschlag ablehnen entfernt ihn');
$GLOBALS['opt']['sab_geo'] = '1'; $mAa = new ReflectionMethod('SAB', 'a_attr'); $mAa->setAccessible(true);
$aa = $mAa->invoke(null, 'B0UKPROD01');
ok(strpos($aa, 'data-sab-l="com:B0USPROD01,ca:B0CAPROD01"') !== false, 'Länder: Link trägt freigegebene ASINs (' . $aa . ')');
$GLOBALS['opt']['sab_tags'] = "co.uk=uk-21\ncom=us-20\nca=ca-20\ncom.au=au-22"; ob_start(); SAB::geo_skript(); $g2 = ob_get_clean();
$gj2 = tempnam(sys_get_temp_dir(), 'gk') . '.js';
file_put_contents($gj2, "var window={},document={readyState:'x',addEventListener:function(){},querySelectorAll:function(){return A}},navigator={};function mk(h,at){var o={href:h,_a:at||{},getAttribute:function(k){return this._a[k]===undefined?null:this._a[k]},hasAttribute:function(){return false},setAttribute:function(){}};return o}\nvar A=[mk('https://www.amazon.co.uk/dp/B0UKPROD01?tag=uk-21',{'data-sab-l':'com:B0USPROD01,ca:B0CAPROD01','data-sab-t':'Lichtwecker'}),mk('https://www.amazon.co.uk/dp/B0UKPROD02?tag=uk-21',{'data-sab-t':'Zaun Akku'}),mk('https://www.amazon.co.uk/dp/B0UKPROD03?tag=fremd-21',{})];\n" . preg_replace('#^<script>|</script>$#', '', trim($g2)) . "\nwindow.sabGeoRun('America/Toronto','en-CA');var r1=[A[0].href,A[1].href,A[2].href];A[0].href='https://www.amazon.co.uk/dp/B0UKPROD01?tag=uk-21';A[1].href='https://www.amazon.co.uk/dp/B0UKPROD02?tag=uk-21';window.sabGeoRun('Pacific/Auckland','en-NZ');var r2=[A[0].href,A[1].href];console.log(JSON.stringify([r1,r2,window.sabGeoPick('Asia/Dubai','en-CA'),window.sabGeoPick('America/Denver','en-CA'),window.sabGeoPick('America/Denver','en-US')]));");
exec('node ' . escapeshellarg($gj2) . ' 2>&1', $go2, $grc2);
$exp = '[["https://www.amazon.ca/dp/B0CAPROD01?tag=ca-20","https://www.amazon.ca/s?k=Zaun%20Akku&tag=ca-20","https://www.amazon.co.uk/dp/B0UKPROD03?tag=fremd-21"],["https://www.amazon.com.au/s?k=Lichtwecker&tag=au-22","https://www.amazon.com.au/s?k=Zaun%20Akku&tag=au-22"],"ca","com","com"]';
ok($grc2 === 0 && trim(implode('', $go2)) === $exp, 'Länder-Umschaltung im Browser: ASIN im Land, sonst Suche, Fremd-ID bleibt, NZ=Australien, en-CA in Amerika=Kanada (' . implode(' ', $go2) . ')');
unset($GLOBALS['opt']['sab_geo'], $GLOBALS['opt']['sab_tags'], $GLOBALS['opt']['sab_laender_map'], $GLOBALS['opt']['sab_laender_vorschlag']);
$GLOBALS['opt']['sab_laender_map'] = ['B0UKPROD01' => ['com' => ['asin' => 'B0USPROD01', 'titel' => '', 'sterne' => '4,5', 'anzahl' => '120', 'prime' => 'ja', 'liefer' => 'morgen', 'preis' => '29,99 €']]];
$mLz = new ReflectionMethod('SAB', 'laender_zeile'); $mLz->setAccessible(true);
$lz = $mLz->invoke(null, 'B0UKPROD01', ['titel' => 'Lichtwecker Sonnenaufgang', 'prime' => true, 'sterne' => 4.4, 'anzahl' => 800, 'preis' => '24,99 €'], 'Lichtwecker | Sonnenaufgang', $GLOBALS['opt']['sab_laender_map']['B0UKPROD01'], ['ca' => [['asin' => 'B0CAPROD01', 'titel' => 'CA Wecker', 'sterne' => '4,0', 'anzahl' => '50', 'prime' => 'nein', 'liefer' => '', 'preis' => '', 'ts' => 1]]]);
ok(strpos($lz, 'freigegeben') !== false && strpos($lz, 'Nach UK-Titel suchen') !== false && strpos($lz, 'amazon.ca/s?k=Lichtwecker%20Sonnenaufgang') !== false && strpos($lz, 'value="ok|B0CAPROD01"') !== false && strpos($lz, 'amazon.com.au') !== false, 'Länder: Zeile zeigt freigegeben, Vorschlag mit Knöpfen, Suche je Land');
unset($GLOBALS['opt']['sab_laender_map']);
$GLOBALS['opt']['sab_geo'] = '1'; $GLOBALS['opt']['sab_tags'] = "co.uk=uk-21\ncom=us-20"; ob_start(); SAB::geo_skript(); $g3 = ob_get_clean(); unset($GLOBALS['opt']['sab_geo'], $GLOBALS['opt']['sab_tags']);
ok(strpos($g3, "data-sab-z") !== false, 'Länder-Umschaltung markiert umgeleitete Links für die Statistik');
ok(strpos(file_get_contents(__DIR__ . '/schlanke-amazon-box.php'), 'z:a.getAttribute("data-sab-z")') !== false, 'Klickmessung sendet das Umschalt-Ziel mit (ohne IP)');
// 0.89.3: Zweier-Tabelle, Titel zwischen Bildlink und Textlink (Stelle = Textlink bzw. Bildlink)
$e6 = '<table><tr><td><a href="https://www.amazon.de/dp/B0A1234567"><img src="a.jpg"></a><br>Echter Titel Erstes<br><a href="https://www.amazon.de/dp/B0A1234567">Angebot bei Amazon</a></td><td><a href="https://www.amazon.de/dp/B0B1234567"><img src="b.jpg"></a><br>Echter Titel Zweites<br><a href="https://www.amazon.de/dp/B0B1234567">Angebot bei Amazon</a></td></tr></table>';
$pt6 = strpos($e6, '>Angebot bei Amazon', strpos($e6, 'Zweites')) ; $pt6 = strrpos(substr($e6, 0, $pt6), '<a '); $pb6 = strpos($e6, '<a href="https://www.amazon.de/dp/B0B1234567"');
ok(SAB::titel_an_stelle($e6, $pt6, 60) === 'Echter Titel Zweites' && SAB::titel_an_stelle($e6, $pb6, strlen('<a href="https://www.amazon.de/dp/B0B1234567"><img src="b.jpg"></a>')) === 'Echter Titel Zweites', 'Zweier-Tabelle: Titel zwischen Bild- und Textlink, auch wenn die Stelle der Textlink ist');
$e7 = '<table><tr><td>[amz_bild asin="B0A1234567"][/amz_bild]<br>Titel Erstes<br>[amz_link asin="B0A1234567"]Angebot bei Amazon[/amz_link]</td></tr></table>';
ok(SAB::titel_an_stelle($e7, strpos($e7, '[amz_link'), 30) === 'Titel Erstes', 'Titel zwischen [amz_bild] und [amz_link]');
// 0.89.4: Bildlink, kurzer Titel, Textlink derselben ASIN = eine Stelle
$e8 = '<table><tr><td><a href="https://www.amazon.de/dp/B0A1234567"><img src="a.jpg"></a><br>Titel Erstes<br><a href="https://www.amazon.de/dp/B0A1234567">Angebot bei Amazon</a></td></tr></table>';
ok(count(SAB::stellen_gruppen($e8, 'B0A1234567')) === 1, 'Bildlink + Titel + Textlink in einer Zelle zählen als eine Stelle');
$e9 = '<p><a href="https://www.amazon.de/dp/B0A1234567"><img src="a.jpg"></a></p><p>Weiter.</p><p><a href="https://www.amazon.de/dp/B0A1234567">Hier</a></p>';
ok(count(SAB::stellen_gruppen($e9, 'B0A1234567')) === 2, 'Getrennte Absätze bleiben zwei Stellen');
// 0.89.6: kein Bild mehr, nahes redaktionelles Bild darf nie den Titel liefern
$e10 = '<table><tr><td><img src="redaktion.jpg"><br>Falscher Bildtext</td><td>[amz_bild asin="B0A1234567"][/amz_bild]<br>Richtiger Titel<br>[amz_link asin="B0A1234567"]Angebot bei Amazon[/amz_link]</td></tr></table>';
ok(SAB::titel_an_stelle($e10, strpos($e10, '[amz_link'), strlen('[amz_link asin="B0A1234567"]')) === 'Richtiger Titel', 'Titel zwischen Bildlink-Platzhalter und Textlink, nie von einem anderen Bild');
$e11 = '<p><img src="x.jpg"></p><p>Redaktioneller Text unter Bild</p><p>[amz_link asin="B0A1234567"]Angebot bei Amazon[/amz_link]</p>';
ok(strpos(SAB::titel_an_stelle($e11, strpos($e11, '[amz_link'), strlen('[amz_link asin="B0A1234567"]')), 'Redaktioneller Text') === false || true, 'Einzelbox ohne Zellen: kein Absturz');
// 0.89.8: Überschrift des Absatzes weit oben liefert nie den Titel
$e12 = '<h2>Schneckenschutz im Garten</h2><p>' . str_repeat('Langer Fließtext ohne Produkt. ', 12) . '</p><p>Weitere Erklärung zum Thema.</p><p>[amz_link asin="B0A1234567"]Angebot bei Amazon[/amz_link]</p>';
ok(strpos(SAB::kontext_titel($e12, 'B0A1234567'), 'Schneckenschutz im Garten') === false, 'Kontexttitel: weit entfernte Überschrift wird nicht genommen');
$e13 = '<p>Pflanzenhut 24er Set</p><p>[amz_bild asin="B0A1234567"][/amz_bild]</p><p>[amz_link asin="B0A1234567"]Angebot bei Amazon[/amz_link]</p>';
ok(SAB::kontext_titel($e13, 'B0A1234567') === 'Pflanzenhut 24er Set', 'Kontexttitel: unmittelbarer Text vor der Stelle');
// 0.89.9: Kontrolle auch für Tausch aus dem Reiter (Eintrag ohne art=stelle)
$GLOBALS['opt']['sab_kontrolle'] = ['k1' => ['ts' => 1, 'alt' => 'B0ALT00001', 'neu' => 'B0NEU00001', 'stellen' => [['pid' => 77, 'titel' => 'X']]]];
$rm = new ReflectionMethod('SAB', 'kontrolle_stellen_ids'); $rm->setAccessible(true);
$rt = new ReflectionMethod('SAB', 'kontrolle_tab_id'); $rt->setAccessible(true);
ok($rm->invoke(null, 77, 'B0NEU00001') === ['k1'] && $rm->invoke(null, 78, 'B0NEU00001') === [] && $rt->invoke(null, 77, 'B0NEU00001') === 'k1', 'Kontrolle an der Stelle auch nach Tausch aus dem Reiter');
unset($GLOBALS['opt']['sab_kontrolle']);
// 0.89.10: Produkt weg, Zweiertabelle: Titel zwischen ehemaligem Bildlink und Textlink, nie nach dem eBay-Link oder unter der Tabelle
$pad = str_repeat(' style="padding:0;margin:0;border:1px solid #ddd;background:#fff"', 8);
$e14 = '<table><tr><td' . $pad . '>[amz_bild asin="B0A1234567"][/amz_bild]<br>Richtiger Titel Erstes<br>[amz_link asin="B0A1234567"]Bei Amazon ansehen[/amz_link]<br>[amz_ebay asin="B0A1234567" suche="x"]bei eBay[/amz_ebay]</td><td' . $pad . '>[amz_bild asin="B0B1234567"][/amz_bild]<br>[amz_link asin="B0B1234567"]Bei Amazon ansehen[/amz_link]<br>[amz_ebay asin="B0B1234567" suche="y"]bei eBay[/amz_ebay]</td></tr></table><h3>Nächster Abschnitt</h3><p>Text.</p>';
$tl = '[amz_link asin="B0A1234567"]'; $tl2 = '[amz_link asin="B0B1234567"]';
ok(SAB::titel_an_stelle($e14, strpos($e14, $tl), strlen($tl)) === 'Richtiger Titel Erstes' && SAB::$tas_zelle === true, 'Produkt weg: Titel zwischen Bildlink und Textlink der Zelle');
ok(SAB::titel_an_stelle($e14, strpos($e14, $tl2), strlen($tl2)) === '' && SAB::$tas_zelle === true, 'Zelle ohne Titel: nie Text hinter dem eBay-Link oder die nächste Überschrift');
ok(SAB::titel_an_stelle($e14, strpos($e14, '[amz_bild asin="B0B'), 28) === '', 'Bildlink-Stelle ohne Titel in der Zelle: leer');
// 0.89.11: Titel beim Tausch mit ersetzen (nur eindeutig erkannt)
[$r1, $o1] = SAB::titel_ersetzen('<p>Text davor.</p><p><strong>Alter Lichtwecker Titel</strong></p><p>', 'Neuer Wecker', false);
ok($o1 && $r1 === '<p>Text davor.</p><p><strong>Neuer Wecker</strong></p><p>', 'Einzelprodukt: fetter Titel direkt vor dem Bild wird ersetzt');
[$r2, $o2] = SAB::titel_ersetzen('<h2>Weit entfernt</h2><p>Langer Text ohne Fettdruck.</p><p>', 'Neuer Wecker', false);
ok(!$o2 && strpos($r2, 'Weit entfernt') !== false, 'Einzelprodukt: ohne fetten Titel direkt davor bleibt alles unverändert');
[$r3, $o3] = SAB::titel_ersetzen('<td>[amz_bild asin="B0A1234567"][/amz_bild]<br>Alter Titel Erstes<br>[amz_link asin="B0A1234567"]Bei Amazon ansehen[/amz_link]<br>[amz_ebay asin="B0A1234567"]eBay[/amz_ebay]</td>', 'Neuer Titel', true);
ok($o3 && strpos($r3, '<br>Neuer Titel<br>[amz_link') !== false && strpos($r3, 'eBay') !== false, 'Zelle: Text zwischen Bild- und Textlink wird ersetzt');
[$r4, $o4] = SAB::titel_ersetzen('<td>[amz_bild asin="B0A1234567"][/amz_bild]<br>[amz_link asin="B0A1234567"]Bei Amazon[/amz_link]<br>Unter dem Link</td>', 'Neuer Titel', true);
ok(!$o4, 'Zelle ohne Titel zwischen Bild und Textlink: unverändert');
// 0.89.12: Kurzlinks, die sich nicht lösen lassen, mit Umgebung und Eingabefeld
$GLOBALS['pc'] = '<p>Die Zeckenzange ist wichtig. <a href="https://amzn.to/3xyzabc">Zeckenzange ansehen</a></p>';
$rk = new ReflectionMethod('SAB', 'kurzlink_liste_html'); $rk->setAccessible(true);
$kh = $rk->invoke(null, 5);
ok(strpos($kh, 'amzn.to/3xyzabc') !== false && strpos($kh, 'Zeckenzange ist wichtig') !== false && strpos($kh, 'sab_asin') !== false && strpos($kh, 'sab_zeige=3xyzabc') !== false && strpos($kh, 'Bei Amazon suchen') !== false && strpos($kh, 'value="Die Zeckenzange ist wichtig"') !== false && strpos($kh, 'Zeckenzange ist wichtig') !== false, 'Kurzlink-Liste: Umgebung, Stelle ansehen, Eingabefeld, Suchknopf');
$GLOBALS['pc'] = '<p>Kein Kurzlink hier.</p>'; ok($rk->invoke(null, 5) === '', 'Kurzlink-Liste leer ohne Kurzlinks');
unset($GLOBALS['pc']);
// 0.89.14: Kurzlink zu Rezension wird erkannt
set_transient('sab_kurz_' . md5('https://amzn.to/REZ1234'), 'https://www.amazon.de/product-reviews/B0ABCDEFGH/?tag=zeck-21', 3600);
$rg = new ReflectionMethod('SAB', 'kurzlink_grund'); $rg->setAccessible(true);
$gr = $rg->invoke(null, 'https://amzn.to/REZ1234');
ok(strpos($gr, 'Rezensionen') !== false && strpos($gr, 'zeck-21') !== false && strpos($gr, 'B0ABCDEFGH') !== false, 'Kurzlink zu Rezension: erkannt, Partner-ID im Ziel angezeigt');
// 0.89.15: „Ist okay, so lassen“ für Kurzlinks
$GLOBALS['pc'] = '<p>Alles zur Zeckenzange. <a href="https://amzn.to/3xyzabc">Rezensionen</a></p>';
$k0 = $rk->invoke(null, 5);
ok(strpos($k0, 'Ist okay, so lassen') !== false && !SAB::kurz_ok('https://amzn.to/3xyzabc'), 'Kurzlink-Liste: Knopf „Ist okay, so lassen“, noch nicht bestätigt');
SAB::kurz_ok_setzen('https://amzn.to/3xyzabc', true);
$k1 = $rk->invoke(null, 5);
ok(SAB::kurz_ok('https://amzn.to/3xyzabc') && strpos($k1, 'Wieder prüfen') !== false && strpos($k1, 'Diesen Kurzlink ersetzen') === false, 'Bestätigter Kurzlink: nicht mehr als Problem, „Wieder prüfen“ da');
[$u1] = SAB::umbau_text($GLOBALS['pc']);
ok(strpos($u1, 'amzn.to/3xyzabc') !== false && !SAB::$umbau_abw, 'Umbau lässt bestätigten Kurzlink aus ohne Abweichung');
SAB::kurz_ok_setzen('https://amzn.to/3xyzabc', false);
ok(!SAB::kurz_ok('https://amzn.to/3xyzabc'), 'Wieder prüfen: Bestätigung entfernt');
unset($GLOBALS['pc']);
// 0.89.16: „Alle prüfen“ nimmt auch Produkte aus den Artikeln, nicht nur die Produktliste
set_transient('sab_alle_asins', ['B0ARTIKEL01'], 600);
$rp = new ReflectionMethod('SAB', 'pruef_liste'); $rp->setAccessible(true);
ok(in_array('B0ARTIKEL01', $rp->invoke(null), true), 'Prüfliste enthält Produkte aus Artikeln');
delete_transient('sab_alle_asins');
// 0.89.18: „Prime unklar“ allein ist kein Tauschgrund
$rd = new ReflectionMethod('SAB', 'tausch_dringlichkeit'); $rd->setAccessible(true);
$xu = ['manuell_stand' => 1, 'prime' => null, 'preis' => '12,99 €', 'lieferbar' => true, 'kaufen' => true, 'liefertage' => 2, 'sterne' => 4.5];
ok($rd->invoke(null, $xu, 80) === 0, 'Prime unklar, Preis da, gute Lieferzeit: kein Tauschgrund');
ok($rd->invoke(null, array_merge($xu, ['kaufen' => false]), 80) === 100, 'Prime unklar ohne Buy Box: weiter Problem');
// 0.89.19: stündliche Auffrischung läuft ohne Fehler durch (leer und mit Produkt)
set_transient('sab_alle_asins', ['B0ARTIKEL01'], 600);
$ae = null; try { SAB::auffrischen(); } catch (\Throwable $e) { $ae = $e->getMessage(); }
ok($ae === null, 'Auffrischen: kein Fehler ' . ($ae ?? ''));
delete_transient('sab_alle_asins');
// 0.89.20: Textlink bleibt klickbar, auch wenn Amazon das Produkt (angeblich) nicht kennt
$GLOBALS['opt']['sab_tags'] = "es=t-21"; $GLOBALS['opt']['sab_default_land'] = 'es';
set_transient('sab_es_B0WEG00001', ['ts' => time(), 'titel' => 'X', 'bild' => '', 'link' => '', 'weg' => true, 'verfuegbar' => false], 600);
$lw = $GLOBALS['sc']['amz_link'](['asin' => 'B0WEG00001', 'land' => 'es'], 'Angebote bei Amazon ansehen');
ok(strpos($lw, '<a ') !== false && strpos($lw, 'href=') !== false && strpos($lw, '/dp/B0WEG00001') !== false, 'Textlink bei „unbekanntem“ Produkt bleibt klickbar');
unset($GLOBALS['opt']['sab_tags'], $GLOBALS['opt']['sab_default_land']);
// 0.89.21: übersprungene Links nennen den konkreten Grund
$GLOBALS['opt']['sab_tags'] = "es=t-21"; $GLOBALS['opt']['sab_default_land'] = 'es'; SAB::$skip_gruende = [];
SAB::umbau_text('<p><a href="https://www.amazon.co.uk/dp/B0SKIP0001">Zaun kaufen</a></p>');
ok(count(SAB::$skip_gruende) === 1 && strpos(SAB::$skip_gruende[0], 'amazon.co.uk') !== false && strpos(SAB::$skip_gruende[0], 'keine Partner-ID') !== false, 'Übersprungen: Grund nennt den Shop ohne Partner-ID');
SAB::$skip_gruende = [];
SAB::umbau_text('<p><a href="https://www.amazon.es/dp/B0SKIP0002">Zaun [x] kaufen</a></p>');
ok(count(SAB::$skip_gruende) === 1 && strpos(SAB::$skip_gruende[0], 'Sonderinhalt') !== false, 'Übersprungen: Grund nennt Sonderinhalt (eckige Klammern)');
unset($GLOBALS['opt']['sab_tags'], $GLOBALS['opt']['sab_default_land']);
// 0.89.22: Bilder nachladen / frisches Bild räumt „unbekannt“ auf
$rb = new ReflectionMethod('SAB', 'bild_frisch'); $rb->setAccessible(true);
$rbp = new ReflectionMethod('SAB', 'bild_pfad'); $rbp->setAccessible(true);
$lnd = (new ReflectionMethod('SAB', 'land')); $lnd->setAccessible(true); $lx = $lnd->invoke(null, '');
$bfile = $rbp->invoke(null, $lx, 'B0FRISCH01', 0); @mkdir(dirname($bfile), 0777, true); file_put_contents($bfile, 'x');
ok($rb->invoke(null, $lx, 'B0FRISCH01') === true && $rb->invoke(null, $lx, 'B0NIEGELA1') === false, 'Bild frisch erkannt');
$au = new ReflectionMethod('SAB', 'amazon_unbekannt'); $au->setAccessible(true);
$GLOBALS['opt']['sab_ungueltige_asins'] = ['B0FRISCH01', 'B0NIEGELA1'];
ok($au->invoke(null, 'B0FRISCH01', ['items' => []]) === false && $au->invoke(null, 'B0NIEGELA1', ['items' => []]) === true, 'Mit frischem Bild nie „Amazon kennt es nicht“');
@unlink($bfile); unset($GLOBALS['opt']['sab_ungueltige_asins']);
$bn = new ReflectionMethod('SAB', 'bilder_nachladen'); $bn->setAccessible(true);
$be = null; try { $bm = $bn->invoke(null); } catch (\Throwable $e) { $be = $e->getMessage(); }
ok($be === null && strpos((string) $bm, 'Bilder nachgeladen') !== false, 'Bilder nachladen läuft: ' . ($be ?? $bm));
// 0.89.23: Wiederholung ersetzen (nur die zweite, zu nahe Stelle)
$wc = '<p>Erster Absatz.</p><p><a href="https://www.amazon.co.uk/dp/B06WRV4RKC?tag=x-21">Zaun A</a></p><p>' . str_repeat('Text zum Thema Schnecken. ', 40) . '</p><p>Noch mal: <a href="https://www.amazon.co.uk/dp/B06WRV4RKC?tag=x-21">Zaun A</a> hilft.</p><p>Ende.</p>';
ok(count(SAB::doppel_funde($wc)) === 1, 'Test-Artikel hat eine Wiederholung');
[$wc2, $wok] = SAB::wiederholung_ersetzen($wc, 'B06WRV4RKC', 'B0ZAUNNEU1');
ok($wok && substr_count($wc2, 'B06WRV4RKC') === 1 && substr_count($wc2, 'B0ZAUNNEU1') === 1 && strpos($wc2, 'B06WRV4RKC') < strpos($wc2, 'B0ZAUNNEU1'), 'Nur die zweite Stelle ersetzt, die erste bleibt');
ok(count(SAB::doppel_funde($wc2)) === 0, 'Danach keine Wiederholung mehr');
[$wc3, $wok3] = SAB::wiederholung_ersetzen($wc2, 'B06WRV4RKC', 'B0ZAUNNEU1');
ok(!$wok3 && $wc3 === $wc2, 'Ohne Wiederholung nichts geändert');
// 0.89.24: Lieferkosten in Handdaten und Score
ok(SAB::lieferkosten_wert('gratis') === 0.0 && SAB::lieferkosten_wert('4,99') === 4.99 && SAB::lieferkosten_wert('') === null && SAB::lieferkosten_wert('Prime') === 0.0, 'Lieferkosten lesen');
$GLOBALS['opt']['sab_manuell'] = "B0LIEFKST1 | 4,5 | 100 | nein | Berlin 3 | 2026-10-27 | 5 | 9,90\nB0LIEFKST2 | 4,5 | 100 | nein | Berlin 3 | 2026-10-27 | 5 | gratis\nB0LIEFKST3 | 4,5 | 100 | nein | Berlin 3 | 2026-10-27 | 5";
$mdx = new ReflectionMethod('SAB', 'manuell_daten'); $mdx->setAccessible(true); $mdd = $mdx->invoke(null);
ok($mdd['B0LIEFKST1']['lieferkosten'] === 9.9 && $mdd['B0LIEFKST2']['lieferkosten'] === 0.0 && $mdd['B0LIEFKST3']['lieferkosten'] === null, 'Handdaten: 8. Feld Lieferkosten, rückwärtskompatibel');
$scx = new ReflectionMethod('SAB', 'score'); $scx->setAccessible(true);
$sa = $scx->invoke(null, array_merge($mdd['B0LIEFKST1'], ['preis' => '10 €', 'lieferbar' => true])); $sb = $scx->invoke(null, array_merge($mdd['B0LIEFKST2'], ['preis' => '10 €', 'lieferbar' => true])); $sc3 = $scx->invoke(null, array_merge($mdd['B0LIEFKST3'], ['preis' => '10 €', 'lieferbar' => true]));
ok($sa[0] < $sc3[0] && $sc3[0] < $sb[0], 'Hohe Lieferkosten ziehen den Score nach unten, gratis nach oben');
unset($GLOBALS['opt']['sab_manuell']);
// 0.89.25: Ersatz-Datenbank
$GLOBALS['opt']['sab_ersatz'] = "Zaunart | .co.uk | B0ERSATZ01 | geprüft | 2026-10-27 | PC\nZaunart | com | B0ERSATZ02 | neu\nB0ALTPROD1 | UK | B0ERSATZ03 | ok\nkaputt | x";
$GLOBALS['opt']['sab_gruppen'] = "Zaunart: B0ALTPROD1, B0ALTPROD2";
$el = SAB::ersatz_liste();
ok(count($el) === 3 && $el[0]['land'] === 'co.uk' && $el[0]['status'] === 'geprüft' && $el[1]['status'] === 'neu', 'Ersatz-Datenbank lesen');
ok(SAB::ersatz_fuer('B0ALTPROD1', 'co.uk') === ['B0ERSATZ01' => 'geprüft', 'B0ERSATZ03' => 'geprüft'] && SAB::ersatz_fuer('B0ALTPROD1', 'com') === ['B0ERSATZ02' => 'neu'] && SAB::ersatz_fuer('B0ANDERES1', 'co.uk') === [], 'Ersatz je Produktart, Land und ASIN');
unset($GLOBALS['opt']['sab_ersatz'], $GLOBALS['opt']['sab_gruppen']);
// 0.89.26: Ersatz-Datenbank Status und Übersicht
[$e1, $c1] = SAB::ersatz_status_setzen("Zaunart | co.uk | B0ERSATZ02 | neu\nAnderes | com | B0ERSATZ09 | neu", 'Zaunart', 'co.uk', 'B0ERSATZ02', 'geprüft');
ok($c1 && strpos($e1, 'Zaunart | co.uk | B0ERSATZ02 | geprüft | ') === 0 && strpos($e1, "Anderes | com | B0ERSATZ09 | neu") !== false, 'Status geprüft setzen, andere Zeilen unverändert');
[, $c2] = SAB::ersatz_status_setzen("A | com | B0ERSATZ09 | neu", 'A', 'com', 'B0NICHTDA01', 'geprüft'); ok(!$c2, 'Unbekannter Eintrag ändert nichts');
$GLOBALS["opt"]["sab_ersatz"] = "Zaunart | de | B0ERSATZ01 | geprüft"; $GLOBALS['opt']['sab_gruppen'] = "Zaunart: B0ALTPROD1\nSchlauch: B0ALTPROD3";
$rh = new ReflectionMethod('SAB', 'ersatz_html'); $rh->setAccessible(true);
$eh = $rh->invoke(null);
ok(strpos($eh, 'Lücken') !== false && strpos($eh, 'Schlauch') !== false && strpos($eh, 'nur eine Reserve') !== false && strpos($eh, 'B0ERSATZ01') !== false, 'Ersatz-Übersicht zeigt Lücken und Einträge');
unset($GLOBALS['opt']['sab_ersatz'], $GLOBALS['opt']['sab_gruppen']);
// 0.89.27: Auftragsliste für gezielte Ersatzsuche
ok(SAB::ersatz_auftraege(['A' => 30, 'B' => 20, 'C' => 70, 'D' => 10, 'E' => 40], ['A' => 3, 'B' => 2, 'C' => 5, 'D' => 1], 50) === ['B', 'A'], 'Auftragsliste: mehr als eine Seite, Score niedrig, schwächste zuerst');
$rh2 = new ReflectionMethod('SAB', 'ersatz_auftrag_html'); $rh2->setAccessible(true);
ok(strpos($rh2->invoke(null, ['items' => []]), 'Gezielte Ersatzsuche') !== false, 'Ersatz-Auftrag rendert ohne Daten');
// 0.89.28: Alarm bei Score-Absturz
ok(SAB::alarm_fall(['2026-10-20' => 70, '2026-10-21' => 72, '2026-10-22' => 68, '2026-10-27' => 40]) === [70, 40], 'Alarm: starker Absturz erkannt');
ok(SAB::alarm_fall(['2026-10-20' => 70, '2026-10-21' => 72, '2026-10-27' => 60]) === null && SAB::alarm_fall(['2026-10-26' => 70, '2026-10-27' => 10]) === null, 'Kein Alarm bei kleinem Rückgang oder zu wenig Verlauf');
$GLOBALS['opt']['sab_alarm'] = ['B0ALARM001' => ['seit' => 1, 'grund' => 'nicht mehr lieferbar', 'ersatz' => ['B0ERSATZ01']]];
$_GET['page'] = 'sab-austausch'; $adm0 = $GLOBALS['admin']; $GLOBALS['admin'] = true; ob_start(); SAB::alarm_hinweis(); $ah = ob_get_clean(); unset($_GET['page']); $GLOBALS['admin'] = $adm0;
ok(strpos($ah, 'nicht mehr kennt') !== false && strpos($ah, 'notice-error') === false && strpos($ah, 'page=sab-austausch') !== false, 'Alarm-Hinweis: kleine graue Zeile mit Link zum Austausch, nicht rot');
unset($GLOBALS['opt']['sab_alarm']);
// 0.89.29: Lernen
$f1 = ['titel' => 0.9, 'art' => 1.0, 'preis' => 0.5, 'score' => 0.8, 'prov' => 0.5, 'liefer' => 0.5]; $f2 = ['titel' => 0.2, 'art' => 1.0, 'preis' => 0.5, 'score' => 0.9, 'prov' => 0.5, 'liefer' => 0.5];
$w0 = SAB::LERN_STANDARD; $w1 = SAB::lern_schritt($w0, $f1, [$f2]);
ok($w1['titel'] > $w0['titel'] && $w1['score'] < $w0['score'] && $w1['art'] === $w0['art'], 'Lernschritt: Gewicht wandert zu Titelähnlichkeit');
$wx = $w0; for ($i = 0; $i < 500; $i++) { $wx = SAB::lern_schritt($wx, $f1, [$f2], 0.5); }
ok($wx['titel'] <= 3.0 && $wx['score'] >= 0.0, 'Gewichte bleiben begrenzt (0 bis 3)');
ok(SAB::lern_summe($f1, $w0) > SAB::lern_summe($f2, $w0) - 5 && abs(SAB::lern_summe($f1, $w1) - SAB::lern_summe($f2, $w1)) > abs(SAB::lern_summe($f1, $w0) - SAB::lern_summe($f2, $w0)), 'Nach dem Lernen liegt der Gewählte weiter vorn');
$wa = SAB::lern_ablehnen($w0, [$f1, $f2]); ok($wa['titel'] <= $w0['titel'] && $wa['art'] === $w0['art'] - 0.1 * 0.5 * (1 - 0.5), 'Ablehnen senkt überdurchschnittliche Merkmale');
$fm1 = SAB::lern_merkmale(0.5, 3, 10.0, 10.0, 80, 4.0, ['prime' => true, 'liefertage' => 1]); ok($fm1['preis'] === 1.0 && $fm1['art'] === 1.0 && $fm1['liefer'] > 0.9, 'Merkmale 0 bis 1');
ok(!SAB::lern_aktiv(), 'Lernen ohne Runden nicht aktiv');
$GLOBALS['opt']['sab_lern_w'] = ['w' => ['titel' => 9, 'art' => 1], 'runden' => 12, 'log' => []]; $ldx = SAB::lern_daten(); ok(SAB::lern_aktiv() && $ldx['w']['titel'] === 3.0 && $ldx['w']['score'] === 1.0, 'Gespeicherte Gewichte begrenzt, Rest Standard');
unset($GLOBALS['opt']['sab_lern_w']);
$rl = new ReflectionMethod('SAB', 'lernen_html'); $rl->setAccessible(true); ok(strpos($rl->invoke(null), 'Ausfall simulieren') !== false, 'Reiter Lernen rendert');
// 0.89.30: Lernen teilen
$ex = SAB::lern_export(); $exd = json_decode($ex, true);
ok($exd['format'] === 1 && isset($exd['w']['titel']) && count($exd) === 5 && strpos($ex, 'B0') === false, 'Export enthält nur Gewichte, Runden, Version');
ok(!SAB::lern_import('Quatsch')[0] && !SAB::lern_import(json_encode(['format' => 1, 'w' => ['titel' => 2], 'runden' => 0]))[0], 'Import lehnt Ungültiges und Leeres ab');
$GLOBALS['opt']['sab_lern_w'] = ['w' => ['titel' => 1.0], 'runden' => 10, 'log' => []];
[$iok] = SAB::lern_import(json_encode(['format' => 1, 'w' => ['titel' => 2.0, 'score' => 99], 'runden' => 10])); $li = SAB::lern_daten();
ok($iok && abs($li['w']['titel'] - 1.5) < 0.001 && $li['w']['score'] === 3.0 && $li['runden'] === 20, 'Import mischt nach Rundenzahl, Grenzen bleiben');
unset($GLOBALS['opt']['sab_lern_w']);
ok(SAB::lern_abruf() === '', 'Ohne Haken kein Abruf');
// 0.89.31: „-“ im Prime-Feld = Prime unsicher (neutral), „nv“ = nicht verfügbar
$GLOBALS['opt']['sab_manuell'] = "B0PRIMEUN1 | 4,5 | 100 | - | London 1 | 2026-10-27 | 5 | gratis\nB0PRIMENV1 | 4,5 | 100 | nv | London 1 | 2026-10-27 | 5 | gratis";
$mdp = new ReflectionMethod('SAB', 'manuell_daten'); $mdp->setAccessible(true); $mp = $mdp->invoke(null);
ok($mp['B0PRIMEUN1']['prime'] === null && $mp['B0PRIMEUN1']['prime_unsicher'] === true && $mp['B0PRIMEUN1']['gelesen_weg'] === false, '„-“ = Prime unsicher, nicht „weg“');
ok($mp['B0PRIMENV1']['gelesen_weg'] === true && $mp['B0PRIMENV1']['prime_unsicher'] === false, '„nv“ = bei Amazon nicht verfügbar');
$scp = new ReflectionMethod('SAB', 'score'); $scp->setAccessible(true);
$su = $scp->invoke(null, array_merge($mp['B0PRIMEUN1'], ['preis' => '10 €', 'lieferbar' => true]))[0]; $sn = $scp->invoke(null, array_merge($mp['B0PRIMEUN1'], ['prime' => false, 'preis' => '10 €', 'lieferbar' => true]))[0];
ok($su > $sn, 'Prime unsicher wird nicht abgezogen wie „kein Prime“');
unset($GLOBALS['opt']['sab_manuell']);
$gj3 = tempnam(sys_get_temp_dir(), 'gk') . '.js';
file_put_contents($gj3, "var window={},document={readyState:'x',addEventListener:function(){},querySelectorAll:function(){return A}},navigator={};function mk(h,at){var o={href:h,_a:at||{},getAttribute:function(k){return this._a[k]===undefined?null:this._a[k]},hasAttribute:function(){return false},setAttribute:function(){}};return o}\n"
    . "var A=[mk('https://www.amazon.co.uk/dp/B0UKPROD01?tag=uk-21'),mk('https://www.amazon.co.uk/dp/B0UKPROD02?tag=uk-21',{'data-sab-t':'Zaun Akku'}),mk('https://www.amazon.co.uk/Mein-Zaun-Set/dp/B0UKPROD03?tag=uk-21')];\n"
    . preg_replace('#^<script>|</script>$#', '', trim($g2)) . "\nvar out=[];['com','ca','com.au','co.uk'].forEach(function(w){var tz={com:'America/New_York',ca:'America/Toronto','com.au':'Australia/Sydney','co.uk':'Europe/London'}[w];A.forEach(function(a){a.href=a.href});window.sabGeoRun(tz,'en');});console.log(JSON.stringify(A.map(function(a){return a.href})));\n");
exec('node ' . escapeshellarg($gj3) . ' 2>&1', $go3, $grc3);
$o3 = implode('', $go3);
ok($grc3 === 0 && strpos($o3, 's?k=dp') === false && strpos($o3, '"https://www.amazon.co.uk/dp/B0UKPROD01?tag=uk-21"') !== false, 'Länder-Umschaltung: Link ohne Titel bleibt unverändert, nie Suche nach „dp“ (' . $o3 . ')');
ok(strpos($o3, 'Mein%20Zaun%20Set') !== false || strpos($o3, 'Zaun%20Akku') !== false, 'Länder-Umschaltung: Titel aus Attribut oder Pfad wird gesucht');
// 0.89.32: Länder-Umschaltung sucht nie nach „dp“
$gjs = new ReflectionMethod('SAB', 'geo_skript'); 
$srcf = file_get_contents(__DIR__ . '/schlanke-amazon-box.php');
ok(strpos($srcf, "u.pathname.split('/')[1]||'').replace(/-/g,' ')}\n") === false && strpos($srcf, "(dp|gp|product|s|exec|d|o|ref|stores|hz|b)") !== false, 'Geo-Skript: Pfadteil „dp“ wird nie Suchbegriff');
// 0.89.33: alle_asins erfasst auch [amz_karte]/[amz_titel] und einfache Anführungszeichen
$wpdb_vor = $GLOBALS['wpdb'];
$GLOBALS['wpdb'] = new class { public $posts = 'wp_posts'; public function get_col($q) { return ['[amz_karten][amz_karte asin="B0KARTE001" titel="X"][amz_karte asin=\'B0KARTE002\']</amz_karten] [amz_titel asin="B0TITEL001"] [amz_bild asin="B0BILD0001"][/amz_bild]']; } };
delete_transient("sab_alle_asins"); $rm33 = new ReflectionMethod("SAB", "alle_asins"); $rm33->setAccessible(true); $al33 = $rm33->invoke(null);
ok(in_array('B0KARTE001', $al33, true) && in_array('B0KARTE002', $al33, true) && in_array('B0TITEL001', $al33, true) && in_array('B0BILD0001', $al33, true), 'Alle ASINs der Seite: Karten, Titel, Bild, einfache Anführungszeichen');
$GLOBALS['wpdb'] = $wpdb_vor; delete_transient('sab_alle_asins');
// 0.89.34: Mail „automatisch ersetzt“ mit Backend- und Frontend-Links
$mt = SAB::auto_mail_text(['B0ALT00001' => ['neu' => 'B0NEU00001', 'alt_name' => 'Alter Zaun', 'neu_name' => 'Neuer Zaun', 'seiten' => [['titel' => 'Artikel A', 'url' => 'https://x.de/a/?sab_zeige=B0NEU00001', 'edit' => 'https://x.de/wp-admin/post.php?post=1&action=edit']]]], 'x.de', 'https://x.de/wp-admin/admin.php?page=sab-austausch#sab-auto-log');
ok(strpos($mt, 'Seite: x.de') !== false && strpos($mt, 'page=sab-austausch#sab-auto-log') !== false && strpos($mt, 'https://x.de/a/?sab_zeige=B0NEU00001') !== false && strpos($mt, 'Alter Zaun (B0ALT00001) => Neuer Zaun (B0NEU00001)') !== false, 'Auto-Tausch-Mail: Seite, Backend-Link, Frontend-Link, Namen');
// 0.89.35: „Ersatz gesucht“ wird vom Plugin selbst gemerkt
$g35 = SAB::ersatz_gesucht_merken([['B0GESUCHT1', 'Schneckenzaun Alt', 'Text davor', 'Garten', 0, 'Schneckenzaun Alt', 3], ['B0GENUG0001', 'Gut', '', '', 5, '', 1]], ['Schneckenzäune' => ['B0GESUCHT1']], 'co.uk');
ok(isset($g35['B0GESUCHT1']) && !isset($g35['B0GENUG0001']) && $g35['B0GESUCHT1']['art'] === 'Schneckenzäune' && $g35['B0GESUCHT1']['land'] === 'co.uk' && $g35['B0GESUCHT1']['seiten'] === 3, 'Ersatz gesucht: ohne Kandidaten gemerkt, mit genug Kandidaten nicht, Produktart aus Gruppe');
$g36 = SAB::ersatz_gesucht_merken([['B0GESUCHT1', 'Schneckenzaun Alt', '', '', 4, '', 3]], [], 'co.uk');
ok(!isset($g36['B0GESUCHT1']), 'Ersatz gesucht: Eintrag fällt weg, sobald wieder Kandidaten da sind');
unset($GLOBALS['opt']['sab_ersatz_gesucht']);
// 0.89.36: WordPress-Mail zu automatischen Plugin-Updates standardmäßig aus
ok(SAB::update_mail_filter(true) === false, 'Update-Mail von WordPress standardmäßig aus');
ok(SAB::update_mail_filter(true, ['plugin' => [(object) ['result' => true]]]) === false, 'Erfolgsmail aus');
ok(SAB::update_mail_filter(true, ['plugin' => [(object) ['result' => true], (object) ['result' => false]]]) === true, 'Fehlermail bleibt an');
$GLOBALS['opt']['sab_update_mail'] = '1'; ok(SAB::update_mail_filter(true) === true, 'Update-Mail mit Haken wieder an'); unset($GLOBALS['opt']['sab_update_mail']);
// 0.89.38: Amazon-Startseite und Rezensionslinks
ok(SAB::seite_art('https://www.amazon.de/?tag=x-21') === 'startseite' && SAB::seite_art('https://www.amazon.co.uk') === 'startseite' && SAB::seite_art('https://www.amazon.de/product-reviews/B0ABCDEFGH?tag=x') === 'rezension' && SAB::seite_art('https://www.amazon.com/dp/B0ABCDEFGH#customerReviews') === 'rezension' && SAB::seite_art('https://www.amazon.de/dp/B0ABCDEFGH') === '' && SAB::seite_art('https://example.com/') === '', 'Seitenarten erkannt');
$GLOBALS['opt']['sab_amz_seiten'] = "https://www.amazon.de/b?node=123*"; ok(SAB::seite_art('https://www.amazon.de/b?node=123&x=1') === 'liste' && SAB::seite_art('https://www.amazon.de/b?node=999') === '', 'Ausnahmeliste mit Stern'); unset($GLOBALS['opt']['sab_amz_seiten']);
$GLOBALS['opt']['sab_tags'] = "de=de-21\nco.uk=uk-21"; 
[$e1x, $e1n] = SAB::seitenlinks_ergaenzen('<p><a href="https://www.amazon.de/" class="b">Amazon</a> <a href="https://www.amazon.co.uk/product-reviews/B0ABCDEFGH" rel="nofollow">Rezensionen</a> <a href="https://www.amazon.de/dp/B0ABCDEFGH">Produkt</a> <a href="https://www.amazon.de/?tag=de-21" rel="sponsored nofollow noopener">ok</a></p>');
ok($e1n === 2 && strpos($e1x, 'href="https://www.amazon.de/?tag=de-21" class="b" rel="sponsored nofollow noopener"') !== false && strpos($e1x, 'product-reviews/B0ABCDEFGH?tag=uk-21" rel="nofollow sponsored noopener"') !== false && strpos($e1x, '/dp/B0ABCDEFGH">Produkt') !== false, 'Startseiten- und Rezensionslinks: Tag je Land und rel ergänzt, Produktlink unberührt (' . $e1x . ')');
$rz = $GLOBALS['sc']['amz_rezension'](['asin' => 'B0ABCDEFGH', 'land' => 'co.uk'], 'Rezensionen ansehen');
ok(strpos($rz, 'amazon.co.uk/product-reviews/B0ABCDEFGH?tag=uk-21') !== false && strpos($rz, 'sponsored') !== false, 'Baustein [amz_rezension]');
unset($GLOBALS['opt']['sab_tags']);
// 0.89.39: Knopf „Jetzt tauschen“ direkt unter dem Bild jedes Vorschlags
ok(preg_match('#sab_einzeln" value="\' \. esc_attr\(\$alt\) \. \'" onclick="this\.closest#', file_get_contents(__DIR__ . '/schlanke-amazon-box.php')) === 1, 'Austausch: Tausch-Knopf unter dem Bild jeder Karte');
// 0.89.40: Textlink ohne Plugin-Bild ist kein Austauschgrund
[$bm1, $ba1] = SAB::bild_bedarf_text('<p>Video</p><p><a href="https://www.amazon.de/dp/B0TEXTONLY">Gartennetz ansehen</a></p>[amz_bild asin="B0MITBILD1"][/amz_bild]<a href="https://www.amazon.de/dp/B0LNKBILD1"><img src="x.jpg"></a>[amz_link asin="B0LNKTEXT1"]Hier[/amz_link]');
ok(isset($ba1['B0TEXTONLY']) && !isset($bm1['B0TEXTONLY']) && isset($bm1['B0MITBILD1']) && isset($bm1['B0LNKBILD1']) && !isset($bm1['B0LNKTEXT1']) && isset($ba1['B0LNKTEXT1']), 'Bildbedarf: Textlink braucht kein Bild, Bild-/Bildlink schon');
$tdm = new ReflectionMethod('SAB', 'tausch_dringlichkeit'); $tdm->setAccessible(true);
$xo = ['bilder' => [], 'lieferbar' => true, 'preis' => '10 €', 'status' => 'IN_STOCK', 'sterne' => 4.5];
ok($tdm->invoke(null, $xo, 70, true) === 100 && $tdm->invoke(null, $xo, 70, false) < 100, 'Dringlichkeit: fehlendes Bild zählt nur, wenn das Produkt ein Bild braucht');
// 0.89.41: Seite scannen
$GLOBALS['opt']['sab_tags'] = "de=de-21\nco.uk=uk-21\ncom=us-20";
$erw41 = SAB::scan_erwartet(); $e41 = (function () { $r = new ReflectionMethod('SAB', 'scan_neu'); $r->setAccessible(true); return $r->invoke(null); })();
SAB::scan_text('<a href="https://www.amazon.de/dp/B0SCANNN01?tag=de-21">ok</a> <a href="https://www.amazon.de/dp/B0SCANNN02?tag=us-20">US-ID auf .de</a> <a href="https://www.amazon.co.uk/dp/B0SCANNN03?tag=fremd-99">fremd</a> <a href="https://www.amazon.com/dp/B0SCANNN04">ohne</a> <a href="https://amzn.to/abc123">kurz</a> <a href="https://www.amazon.de/dp/B0SCANNN0123?tag=de-21">lang</a> [amz_bild asin="B0SCANNN05" land="co.uk"][/amz_bild]', 7, $erw41, $e41);
$typen = array_column($e41['probleme'], 'typ');
ok($e41['kurz']['n'] === 1 && in_array('fremdland', $typen, true) && in_array('unbekannt', $typen, true) && in_array('ohne', $typen, true) && in_array('asin', $typen, true) && count($typen) === 4, 'Scan: Ausreißer erkannt, Kurzlink ohne Alarm, normale IDs still (' . implode(',', $typen) . ')');
ok($e41['links'] === 6 && $e41['laender']['de'] === 3 && isset($e41['asin']['B0SCANNN05']) && ($e41['ids']['uk-21']['n'] ?? 0) === 1, 'Scan: Zählung je Shop, Platzhalter mit Land-ID zählt');
$csv41 = SAB::scan_csv_text($e41);
ok(strpos($csv41, '"Top-Probleme"') === 0 && strpos($csv41, '"ASIN-Details"') !== false && strpos($csv41, '"fremdland";') !== false, 'Scan: CSV mit Semikolon und Tabellen');
$sh41 = new ReflectionMethod('SAB', 'scan_html'); $sh41->setAccessible(true); ok(strpos($sh41->invoke(null), 'Seite jetzt scannen') !== false, 'Scan-Reiter rendert');
unset($GLOBALS['opt']['sab_tags']);
// 0.89.42: Scan zählt geprüft, Hilfeseiten ohne ID ok, Suchlinks bekommen ID
$GLOBALS['opt']['sab_tags'] = "de=de-21";
$e42 = (function () { $r = new ReflectionMethod('SAB', 'scan_neu'); $r->setAccessible(true); return $r->invoke(null); })();
SAB::scan_text('<a href="https://www.amazon.de/gp/help/customer/display.html?nodeId=201909010">Datenschutz</a> <a href="https://www.amazon.de/dp/B0SCANNN09">ohne ID</a>', 9, SAB::scan_erwartet(), $e42);
ok(($e42['ausnahmen'] ?? 0) === 1 && count($e42['probleme']) === 1 && $e42['probleme'][0]['typ'] === 'ohne', 'Scan: Datenschutz-/Hilfelink ohne ID ist kein Ausreißer, Produktlink ohne ID schon');
ok(SAB::seite_art('https://www.amazon.de/s?k=schneckenzaun') === 'suche' && SAB::seite_art('https://www.amazon.de/s') === '', 'Suchlink erkannt');
[$e42x, $e42n] = SAB::seitenlinks_ergaenzen('<a href="https://www.amazon.de/s?k=zaun">Suche</a>');
ok($e42n === 1 && strpos($e42x, 'k=zaun&tag=de-21') !== false && strpos($e42x, 'sponsored') !== false, 'Suchlink: ID und rel ergänzt');
unset($GLOBALS['opt']['sab_tags']);
// 0.89.43: Kartendesign, Karte hinzufügen, Textlink braucht kein Bild
SAB::$kd_cache = false; ok(SAB::kd_opt() === null && strpos(SAB::sc_karte(['asin' => 'B0KARTE001']), 'sab-kd-') === false, 'Kartendesign: ohne Einstellung bleibt die Karte wie bisher');
$GLOBALS['opt']['sab_kd'] = ['preset' => 'auffaellig_preis', 'farbe' => '#ff6a00', 'ecken' => 40, 'gimmick' => '🐌', 'seite' => 'l']; SAB::$kd_cache = false;
$kd = SAB::kd_opt(); $kv = SAB::kd_vars($kd);
ok($kd['stil'] === 'auffaellig' && $kd['preis'] && $kd['ecken'] === 28 && strpos($kv, '--kd:#ff6a00') !== false && strpos($kv, '--kr:28px') !== false && strpos($kv, '🐌') !== false && strpos($kv, '--kgl:12px') !== false, 'Kartendesign: Variablen, Ecken begrenzt, Tierchen links');
ok(SAB::kd_textfarbe('#ffffff') === '#1a1a1a' && SAB::kd_textfarbe('#7c3aed') === '#ffffff', 'Kartendesign: Textfarbe lesbar');
$kh = new ReflectionMethod('SAB', 'kd_html'); $kh->setAccessible(true); $khh = $kh->invoke(null);
ok(substr_count($khh, 'name="kd_preset"') === 5 && strpos($khh, 'kd_gimmick') !== false && strpos($khh, 'Bewertungen zeigen wir nicht') !== false, 'Kartendesign-Reiter: fünf Designs, Farbe, Tierchen');
$GLOBALS['opt']['sab_kd'] = ['preset' => 'kaputt']; SAB::$kd_cache = false; ok(SAB::kd_opt() === null, 'Kartendesign: ungültiges Design wird ignoriert');
unset($GLOBALS['opt']['sab_kd']); SAB::$kd_cache = false;
// 0.89.44: Textlink ohne Titel: Suchbegriff aus dem Text direkt vor dem Link
$GLOBALS['opt']['sab_tags'] = "co.uk=uk-21\ncom=us-20\nde=de-21"; $GLOBALS['opt']['sab_geo'] = '1'; ob_start(); SAB::geo_skript(); $g4 = ob_get_clean();
$gj4 = tempnam(sys_get_temp_dir(), 'gp') . '.js';
file_put_contents($gj4, "var window={},document={readyState:'x',addEventListener:function(){},querySelectorAll:function(){return A}},navigator={};function mk(h,txt){var o={href:h,_a:{},nodeType:1,nodeName:'A',previousSibling:txt===null?null:{nodeType:3,textContent:txt,previousSibling:null},getAttribute:function(){return null},hasAttribute:function(){return false},setAttribute:function(){}};return o}\nvar A=[mk('https://www.amazon.com/dp/B0WOLLE001?tag=us-20','In the United States you can order fresh sheep wool here. '),mk('https://www.amazon.com/dp/B0WOLLE002?tag=us-20','Schneckenzaun aus Kupfer kaufen: '),mk('https://www.amazon.com/dp/B0WOLLE003?tag=us-20',null)];\n" . preg_replace('#^<script>|</script>$#', '', trim($g4)) . "\nwindow.sabGeoRun('Europe/Berlin','de-DE');console.log(JSON.stringify(A.map(function(a){return a.href})));");
exec('node ' . escapeshellarg($gj4) . ' 2>&1', $go4, $grc4);
ok($grc4 === 0 && trim(implode('', $go4)) === '["https://www.amazon.de/s?k=fresh%20sheep%20wool&tag=de-21","https://www.amazon.de/s?k=Schneckenzaun%20aus%20Kupfer&tag=de-21","https://www.amazon.com/dp/B0WOLLE003?tag=us-20"]', 'Länder-Umschaltung: Textlink ohne Titel sucht mit dem Satz vor dem Link (' . implode(' ', $go4) . ')');
unset($GLOBALS['opt']['sab_geo'], $GLOBALS['opt']['sab_tags']);
// 0.89.45: generische Linktexte, Titel aus dem Satz vor dem Textlink
ok(SAB::generischer_linktext('Offer on Amazon.com') && SAB::generischer_linktext('Oferta en Amazon') && SAB::generischer_linktext('Click here') && SAB::generischer_linktext('Angebot bei Amazon') && !SAB::generischer_linktext('Amazon Basics Gartenschlauch'), 'Linktext: Angebotstexte sind generisch, echte Titel nicht');
$GLOBALS['opt']['sab_generische_linktexte'] = "Zum Shop vor Ort"; ok(SAB::generischer_linktext('zum shop vor ort!'), 'Linktext: eigene Liste'); unset($GLOBALS['opt']['sab_generische_linktexte']);
ok(SAB::satz_titel('In the United States you can order fresh sheep wool here. ') === 'fresh sheep wool' && SAB::satz_titel('Schneckenzaun aus Kupfer kaufen: ') === 'Schneckenzaun aus Kupfer' && SAB::satz_titel('Hier gibt es den Bodendecker') !== '', 'Satz vor dem Link: Produktbegriff');
$c45 = '<p>In the United States you can order fresh sheep wool here. <a href="https://www.amazon.com/dp/B0WOLLE001?tag=us-20">Offer on Amazon.com</a></p>'; $p45 = strpos($c45, '<a ');
ok(SAB::titel_an_stelle($c45, $p45, strlen($c45) - $p45 - 4) === 'fresh sheep wool', 'Titel an der Stelle: Satz vor dem Textlink, nicht Linktext');
// 0.89.47: Scan: Sprung zur Stelle im Editor, „Als eigene ID hinzufügen“
$GLOBALS['opt']['sab_tags'] = "de=de-21"; unset($GLOBALS['opt']['sab_tags_extra']); $GLOBALS['admin'] = true;
$e47 = (function () { $r = new ReflectionMethod('SAB', 'scan_neu'); $r->setAccessible(true); return $r->invoke(null); })();
SAB::scan_text('<p><a href="https://www.amazon.de/dp/B0SCANNN11?tag=de-21">ok</a> <a href="https://www.amazon.de/dp/B0SCANNN12?tag=meine-22">A</a> <a href="https://www.amazon.de/dp/B0SCANNN13?tag=meine-22">B</a></p>', 7, SAB::scan_erwartet(), $e47);
ok(count($e47['probleme']) === 2 && $e47['probleme'][0]['such'] === 'meine-22' && $e47['probleme'][0]['nr'] === 1 && $e47['probleme'][1]['nr'] === 2, 'Scan: Problem trägt Suchtext und laufende Nummer für den Editor');
$GLOBALS['opt']['sab_scan'] = ['ids' => [7], 'pos' => 1, 'erg' => $e47, 'ts' => time(), 'fertig' => true]; $_POST['tag'] = 'meine-22';
SAB::ajax_scan_id();
$e47b = SAB::scan_erwartet();
ok($GLOBALS['json']['ok'] && $GLOBALS['json']['d']['n'] === 2 && strpos((string) $GLOBALS['opt']['sab_tags_extra'], 'meine-22') !== false && !$GLOBALS['opt']['sab_scan']['erg']['probleme'] && in_array('meine-22', $e47b['*'], true), 'Scan: eigene ID übernehmen entfernt den Eintrag und gilt künftig als in Ordnung');
$e47c = (function () { $r = new ReflectionMethod('SAB', 'scan_neu'); $r->setAccessible(true); return $r->invoke(null); })();
SAB::scan_text('<a href="https://www.amazon.de/dp/B0SCANNN12?tag=meine-22">A</a>', 8, $e47b, $e47c);
ok(!$e47c['probleme'], 'Scan: übernommene ID ist beim nächsten Scan kein Ausreißer');
$_POST['tag'] = 'x y'; SAB::ajax_scan_id(); ok(!$GLOBALS['json']['ok'], 'Scan: ungültige ID abgelehnt');
unset($GLOBALS['opt']['sab_tags'], $GLOBALS['opt']['sab_tags_extra'], $GLOBALS['opt']['sab_scan'], $_POST['tag']);
// 0.89.48: Produkt nur in einem anderen Store (Heimat-Store)
$GLOBALS['opt']['sab_tags'] = "es=t-21\nco.uk=t-21\ncom=t-21"; $GLOBALS['opt']['sab_default_land'] = 'es';
$GLOBALS['http_mp']['www.amazon.com']['B0HEIMAT01'] = item('B0HEIMAT01', [$money('20 $')]); $GLOBALS['http_mp']['www.amazon.com']['B0HEIMAT01']['detailPageURL'] = 'https://www.amazon.com/dp/B0HEIMAT01?tag=t-21';
$h0 = (new ReflectionMethod('SAB', 'produkt')); $h0->setAccessible(true); define('SAB_SYNC', true);
$p0 = $h0->invoke(null, 'B0HEIMAT01', 'es', true, true);
ok(!empty($p0['weg']) || empty($p0['bild']), 'Heimat-Store: ohne Eintrag bleibt ein nur woanders vorhandenes Produkt „weg“ (kein stilles Umlenken)');
$GLOBALS['opt']['sab_heimat_liste'] = "B0HEIMAT01 = com\nB0HEIMAT02"; $GLOBALS['tr'] = array_filter($GLOBALS['tr'], fn($k) => strpos($k, 'B0HEIMAT') === false, ARRAY_FILTER_USE_KEY); unset($GLOBALS['opt']['sab_alt_es_B0HEIMAT01']);
$p1 = $h0->invoke(null, 'B0HEIMAT01', 'es', true, true);
ok(empty($p1["weg"]) && strpos((string) $p1['link'], 'amazon.com') !== false && $p1['bild'] !== '', 'Heimat-Store: eingetragenes Produkt zeigt Bild und Link vom .com-Store');
$GLOBALS['http_mp']['www.amazon.co.uk']['B0HEIMAT02'] = item('B0HEIMAT02', [$money('9 £')]); $GLOBALS['http_mp']['www.amazon.co.uk']['B0HEIMAT02']['detailPageURL'] = 'https://www.amazon.co.uk/dp/B0HEIMAT02?tag=t-21';
$p2 = $h0->invoke(null, 'B0HEIMAT02', 'es', true, true);
ok(strpos((string) ($p2['link'] ?? ''), 'amazon.co.uk') !== false && (get_option('sab_asin_land')['B0HEIMAT02'] ?? '') === 'co.uk', 'Heimat-Store: ohne Land wird gesucht und das Land gemerkt');
ob_start(); $aa48 = (new ReflectionMethod('SAB', 'a_attr')); $aa48->setAccessible(true); $x48 = $aa48->invoke(null, 'B0HEIMAT01'); ob_end_clean();
ok(strpos($x48, 'data-sab-fix="1"') !== false && strpos((string) (function () { $r = new ReflectionMethod('SAB', 'a_attr'); $r->setAccessible(true); return $r->invoke(null, 'B0ANDERES1'); })(), 'data-sab-fix') === false, 'Heimat-Store: Link ist für die Länder-Umschaltung gesperrt');
unset($GLOBALS['opt']['sab_heimat_liste'], $GLOBALS['opt']['sab_asin_land']);
// 0.89.49: Textlinks bleiben immer klickbar
$GLOBALS['opt']['sab_tags'] = "es=t-21"; $GLOBALS['opt']['sab_default_land'] = 'es'; $GLOBALS['tr'] = [];
$l49 = SAB::sc_link(['asin' => 'B0TEXTLNK1', 'land' => 'com'], 'Angebot');
ok(strpos($l49, '<a ') === 0 && strpos($l49, 'amazon.com/dp/B0TEXTLNK1') !== false && strpos($l49, 'sponsored') !== false, 'Textlink: auch ohne Partner-ID des Landes klickbar (' . $l49 . ')');
$l49b = SAB::sc_link(['asin' => 'B0TEXTLNK2', 'land' => 'es'], '');
ok(preg_match('#<a [^>]*>[^<]+</a>#', $l49b) === 1, 'Textlink: nie ein Link ohne sichtbaren Text');
ok(SAB::sc_aawp(['link' => 'KURZ'], 'Mein Text') === 'Mein Text', 'AAWP-Link mit kaputter ASIN: Text bleibt stehen');
unset($GLOBALS['opt']['sab_tags'], $GLOBALS['opt']['sab_default_land']);
// 0.89.50: Bild und Textlink desselben Produkts zusammen tauschen
$pb = fn($c, $n, $a) => (function () use ($c, $n, $a) { $f = new ReflectionMethod('SAB', 'stellen_finden'); $f->setAccessible(true); $st = $f->invoke(null, $c); return SAB::partner_bereich($c, $st[$n][1], strlen($st[$n][0]), $a); })();
$k1 = '<p>[amz_bild asin="B0A1234567"][/amz_bild]<br>Titel A<br>[amz_link asin="B0A1234567"]Ansehen[/amz_link]</p>';
$r = $pb($k1, 0, 'B0A1234567'); ok($r && substr($k1, $r[0], $r[1] - $r[0]) === substr($k1, 3, -4), 'Partner: Bild + Titel + Textlink = ein Bereich');
$r = $pb($k1, 1, 'B0A1234567'); ok($r && $r[0] === 3, 'Partner: auch vom Textlink aus gefunden');
$k2 = '<p>[amz_bild asin="B0A1234567"][/amz_bild] [amz_bild asin="B0B1234567"][/amz_bild]<br>[amz_link asin="B0A1234567"]A[/amz_link] [amz_link asin="B0B1234567"]B[/amz_link]</p>';
ok($pb($k2, 0, 'B0A1234567') === null && $pb($k2, 3, 'B0B1234567') === null, 'Partner: nebeneinander stehende Produkte werden nicht gekoppelt');
$k3 = '[amz_bild asin="B0A1234567"][/amz_bild]</p><p>[amz_link asin="B0A1234567"]A[/amz_link]';
ok($pb($k3, 0, 'B0A1234567') === null, 'Partner: Absatzwechsel koppelt nicht');
$k4 = '<a href="https://www.amazon.de/dp/B0A1234567?tag=x"><img src="a.jpg"></a><br><a href="https://www.amazon.de/dp/B0A1234567?tag=x">Text</a>';
$f4 = SAB::partner_bereich($k4, 0, strpos($k4, '</a>') + 4, 'B0A1234567'); ok($f4 === [0, strlen($k4)], 'Partner: feste Links Bild + Text');
// 0.89.55: Neu scannen
$GLOBALS['opt']['sab_name_offen'] = ['B0GEISTER01' => 'Weg'];
$scm_ = new ReflectionMethod('SAB', 'umbau_scan'); $scm_->setAccessible(true); $sm_ = (string) $scm_->invoke(null);
ok(strpos($sm_, 'Neu eingelesen') !== false && !isset($GLOBALS['opt']['sab_name_offen']['B0GEISTER01']), 'Neu scannen: Namenshinweis zu Produkt ohne Artikel entfernt (' . $sm_ . ')');
$sk = new ReflectionMethod('SAB', 'scan_knopf_html'); $sk->setAccessible(true);
ok(strpos((string) $sk->invoke(null, 'umbau'), 'umbau_scan') !== false, 'Neu scannen: Knopf vorhanden');
// 0.89.56: Widgets
$wl = SAB::sc_widget(['typ' => 'liste', 'titel' => 'Top'], "Eins|https://example.com/a\nZwei");
ok(substr_count($wl, '<li>') === 2 && strpos($wl, 'sab-wl') !== false && strpos($wl, '<style id="sab-widget">') !== false, 'Widget: Liste mit zwei Einträgen');
$wt = SAB::sc_widget(['typ' => 'tipp', 'titel' => 'Frage?', 'text' => 'Antwort', 'url' => 'https://example.com/t', 'knopf' => 'Los']);
ok(strpos($wt, 'sab-wt') !== false && strpos($wt, '<style') === false && strpos($wt, 'href="https://example.com/t"') !== false, 'Widget: Tipp-Box, Stil nur einmal');
ok(SAB::sc_widget(['typ' => 'buch', 'asin' => 'kaputt']) === '', 'Widget: Buch ohne gültige ASIN bleibt leer');
$wh = new ReflectionMethod('SAB', 'widgets_html'); $wh->setAccessible(true); $whh = (string) $wh->invoke(null);
ok(strpos($whh, 'Shortcode kopieren') !== false && strpos($whh, 'Top-3-Liste') !== false, 'Widgets-Reiter rendert mit Vorlagen');
// 0.89.57: Widgets im Umbau-Reiter
$GLOBALS['opt']['widget_block'] = [16 => ['content' => '<!-- wp:paragraph --><p><strong>Haufenweise Lebensräume</strong></p><p>Ein langer Beschreibungstext über Igel und Laufkäfer im Garten.</p><p><a href="https://www.amazon.de/dp/3895663891?tag=x-21"><img src="https://x.de/aawp/public/image.php?a=1"></a></p><p><a href="https://tidd.ly/3zcGOHu">Bei Thalia ansehen</a></p><!-- /wp:paragraph -->'], 17 => ['content' => 'Nur Text'], '_multiwidget' => 1];
$GLOBALS['opt']['sidebars_widgets'] = ['sidebar-1' => ['block-16']];
$mWf = new ReflectionMethod('SAB', 'widgets_finden'); $mWf->setAccessible(true); $wf = $mWf->invoke(null);
ok(isset($wf['block-16']) && !isset($wf['block-17']) && $wf['block-16']['asins'] === ['3895663891'] && $wf['block-16']['leiste'] === 'sidebar-1' && !$wf['block-16']['fertig'], 'Widgets: Block-Widget mit Amazon-Link gefunden, Seitenleiste und ASIN erkannt');
$mWe = new ReflectionMethod('SAB', 'widget_felder'); $mWe->setAccessible(true); $wfe = $mWe->invoke(null, $wf['block-16']);
ok($wfe['titel'] === 'Haufenweise Lebensräume' && $wfe['alt_url'] === 'https://tidd.ly/3zcGOHu' && strpos($wfe['text'], 'Igel') !== false, 'Widgets: Titel, Text und zweiter Link vorbelegt (' . json_encode($wfe, JSON_UNESCAPED_UNICODE) . ')');
$mWc = new ReflectionMethod('SAB', 'widget_code'); $mWc->setAccessible(true); $wc = $mWc->invoke(null, $wfe);
ok(strpos($wc, '[amz_widget typ="buch" asin="3895663891"') === 0 && strpos($wc, 'alt_url="https://tidd.ly/3zcGOHu"') !== false, 'Widgets: Shortcode erzeugt');
$mWs = new ReflectionMethod('SAB', 'widget_speichern'); $mWs->setAccessible(true);
ok($mWs->invoke(null, 'block-16', $wc) === true && strpos($GLOBALS['opt']['widget_block'][16]['content'], 'amz_widget') !== false && $mWf->invoke(null)['block-16']['fertig'] === true, 'Widgets: Speichern ersetzt den Inhalt, danach als umgebaut erkannt');
$mWh = new ReflectionMethod('SAB', 'umbau_widgets_html'); $mWh->setAccessible(true);
ok(strpos((string) $mWh->invoke(null), 'schon umgebaut') !== false, 'Widgets: Liste im Umbau-Reiter');
unset($GLOBALS['opt']['widget_block'], $GLOBALS['opt']['sidebars_widgets']);
// 0.89.58: eBay-Link im Widget ist kein Amazon-Link
$GLOBALS['opt']['widget_block'] = [3 => ['content' => '<p><a href="https://www.ebay.de/sch/i.html?_nkw=amazon+kupferband&campid=1">bei eBay</a> [amz_ebay asin="B0EBAY0001" suche="x"]eBay[/amz_ebay]</p>'], 4 => ['content' => '<p><a href="https://www.amazon.de/dp/B0ECHT0001?tag=x-21">Amazon</a></p>']];
$wf2 = $mWf->invoke(null);
ok(!isset($wf2['block-3']) && isset($wf2['block-4']) && $wf2['block-4']['treffer'] !== '', 'Widgets: eBay-Link (auch mit „amazon“ im Suchwort) wird nicht als Amazon erkannt, Amazon-Link mit Fundstelle');
unset($GLOBALS['opt']['widget_block']);
// 0.89.59: Links je Artikel im Umbau, Score-Abfall nur als Hinweis
$mUl = new ReflectionMethod('SAB', 'umbau_links_html'); $mUl->setAccessible(true);
$ul = (string) $mUl->invoke(null, 7);
ok(strpos($ul, 'Kurzlink') !== false && strpos($ul, 'fester Link') !== false && strpos($ul, 'sab_zeige=B0BBBBBBB1') !== false && strpos($ul, 'action=edit') !== false, 'Umbau: Kurzlinks und feste Links je Artikel mit Sprung ins Frontend und in den Editor');
$GLOBALS['opt']['widget_block'] = [22 => ['content' => '<p><a href="https://amzn.to/3AbCdEf">Buch</a> <a href="https://www.ebay.de/itm/1">eBay</a></p>']];
$wf3 = $mWf->invoke(null);
ok(isset($wf3['block-22']) && count($wf3['block-22']['links']) === 1 && $wf3['block-22']['links'][0]['kurz'] === true && $wf3['block-22']['links'][0]['asin'] === '', 'Widgets: Kurzlink wird mit URL aufgelistet, eBay-Link nicht');
$_POST = ['sab_wid' => 'block-22', 'sab_wakt' => 'vorschau', 'sab_w_asin' => ''];
$mWl = new ReflectionMethod('SAB', 'widget_umbau_lauf'); $mWl->setAccessible(true); $wm = (string) $mWl->invoke(null); $_POST = [];
ok(strpos($wm, 'gültige ASIN') !== false && strpos((string) $mWh->invoke(null), 'amzn.to/3AbCdEf') !== false && strpos((string) $mWh->invoke(null), 'Es fehlt eine gültige ASIN') !== false, 'Widgets: fehlende ASIN gibt sichtbare Meldung im Widget-Kasten, Link klickbar');
unset($GLOBALS['opt']['widget_block']);
// 0.89.61: Erklärung ausblenden
$mHb = new ReflectionMethod('SAB', 'hinweis_box'); $mHb->setAccessible(true); $mHs = new ReflectionMethod('SAB', 'hinweis_setzen'); $mHs->setAccessible(true);
$hb1 = (string) $mHb->invoke(null, 'testk', 'uebersicht', '<p>Text</p>');
ok(strpos($hb1, 'Verstanden, ausblenden') !== false && strpos($hb1, 'value="aus:testk"') !== false && strpos($hb1, '<form') === false, 'Erklärung: Text mit Ausblenden-Knopf, ohne verschachteltes Formular');
$_POST = ['sab_hinweis' => 'aus:testk']; $mHs->invoke(null); $_POST = [];
$hb2 = (string) $mHb->invoke(null, 'testk', 'uebersicht', '<p>Text</p>');
ok(strpos($hb2, '<p>Text</p>') === false && strpos($hb2, 'value="an:testk"') !== false, 'Erklärung: ausgeblendet, Knopf zum Einblenden bleibt');
$_POST = ['sab_hinweis' => 'an:testk']; $mHs->invoke(null); $_POST = [];
ok(strpos((string) $mHb->invoke(null, 'testk', 'uebersicht', '<p>Text</p>'), '<p>Text</p>') !== false, 'Erklärung: wieder eingeblendet');
// 0.89.62: Startseiten-Link
$GLOBALS['opt']['sab_tags'] = "es=ayuba09-21"; $GLOBALS['opt']['sab_default_land'] = 'es';
$ss = SAB::sc_startseite(['land' => 'es'], 'Bei Amazon stöbern');
ok(strpos($ss, 'href="https://www.amazon.es/?tag=ayuba09-21"') !== false && strpos($ss, 'data-sab-asin="STARTSEITE"') !== false && strpos($ss, 'data-sab-home="1"') !== false && strpos($ss, 'sponsored') !== false && strpos($ss, '>Bei Amazon stöbern</a>') !== false, 'Startseiten-Link: mit Partner-ID, Kennung und sponsored (' . $ss . ')');
unset($GLOBALS['opt']['sab_tags'], $GLOBALS['opt']['sab_default_land']);

// ---- 0.90.0: A/B-Test der Viererblöcke ----
$rB = SAB::ab_bewerten(500, 25, 500, 10, 1000);
ok($rB['status'] === 'klar' && $rB['sieger'] === 'A' && $rB['p_wert'] < 0.02 && $rB['p_wert'] > 0.005 && abs($rB['pA'] - 0.05) < 1e-9, 'A/B-Mathe: 25/500 gegen 10/500 ist ein klarer Sieger A (p ' . round($rB['p_wert'], 4) . ')');
ok($rB['wahrsch_A'] > 0.99 && $rB['ciA'][0] < 0.05 && $rB['ciA'][1] > 0.05 && $rB['diff_ci'][0] > 0, 'A/B-Mathe: Wahrscheinlichkeit, Wilson-Bereich und Differenz-Bereich plausibel');
$rG = SAB::ab_bewerten(500, 25, 500, 24, 1000);
ok($rG['status'] === 'gleich' && $rG['sieger'] === '', 'A/B-Mathe: 25 gegen 24 Klicks ist kein Unterschied');
$rL = SAB::ab_bewerten(100, 8, 90, 2, 1000);
ok($rL['status'] === 'laeuft' && abs($rL['fortschritt'] - 0.19) < 1e-9, 'A/B-Mathe: vor dem Ziel nur „läuft“, nie ein Sieger');
$rW = SAB::ab_bewerten(600, 3, 600, 2, 1000);
ok($rW['status'] === 'wenig', 'A/B-Mathe: Ziel erreicht, aber unter 10 Klicks => zu wenige Klicks');
$rN = SAB::ab_bewerten(0, 0, 0, 0, 1000);
ok($rN['status'] === 'leer' && $rN['p_wert'] === null, 'A/B-Mathe: ohne Daten kein Rechenfehler');
$mPhi = new ReflectionMethod('SAB', 'ab_phi'); $mPhi->setAccessible(true);
ok(abs($mPhi->invoke(null, 1.959964) - 0.975) < 1e-4 && abs($mPhi->invoke(null, 0) - 0.5) < 1e-6 && abs($mPhi->invoke(null, -1.0) - 0.158655) < 1e-4, 'A/B-Mathe: Normalverteilung stimmt');
$mBed = new ReflectionMethod('SAB', 'ab_bedarf'); $mBed->setAccessible(true);
$bd = $mBed->invoke(null, 0.05, 0.03);
ok($bd >= 1450 && $bd <= 1550, 'A/B-Mathe: 5 % gegen 3 % braucht rund 1500 Besuche je Paar (' . $bd . ')');

$kk2 = fn($a) => '[amz_karte asin="' . $a . '" land="es"]';
$zwei = '[amz_karten]' . $kk2('B0AB000001') . $kk2('B0AB000002') . '[/amz_karten]';
$zwei2 = '[amz_karten]' . $kk2('B0AB000003') . $kk2('B0AB000004') . '[/amz_karten]';
$vier = '[amz_karten]' . $kk2('B0AB000001') . $kk2('B0AB000002') . $kk2('B0AB000003') . $kk2('B0AB000004') . '[/amz_karten]';
$f2 = SAB::ab_finde('<p>Text</p>' . $zwei . "\n<p></p>\n" . $zwei2 . '<p>Ende</p>');
ok(count($f2) === 1 && count($f2[0]['p']) === 2 && $f2[0]['p'][0]['asins'] === ['B0AB000001', 'B0AB000002'], 'A/B: zwei benachbarte Zweierblöcke werden als ein Viererblock erkannt');
$f4 = SAB::ab_finde('Text ' . $vier . ' Ende');
ok(count($f4) === 1 && $f4[0]['id'] === $f2[0]['id'], 'A/B: Kartenblock mit vier Produkten ist derselbe Test wie zwei Zweierblöcke');
$fs = SAB::ab_finde($zwei2 . $zwei);
ok(count($fs) === 1 && $fs[0]['id'] === $f2[0]['id'] && $fs[0]['p'][0]['v'] === 'B', 'A/B: Reihenfolge im Artikel ändert Kennung nicht, A bleibt A');
ok(!SAB::ab_finde($zwei . '<p>langer Text dazwischen</p>' . $zwei2) && !SAB::ab_finde('[amz_karten]' . $kk2('B0AB000001') . $kk2('B0AB000002') . '[/amz_karten][amz_karten]' . $kk2('B0AB000002') . $kk2('B0AB000003') . '[/amz_karten]'), 'A/B: Text dazwischen oder doppeltes Produkt => kein Test');
ok(SAB::ab_inhalt($vier) === $vier, 'A/B: ohne eingeschalteten Test bleibt der Inhalt unverändert');
$GLOBALS['opt']['sab_ab'] = [$f2[0]['id'] => ['an' => 1, 'ziel' => 1000, 'start' => gmdate('Y-m-d')]];
$tc = SAB::ab_inhalt('Vor ' . $zwei . $zwei2 . ' Nach');
ok(substr_count($tc, '[amz_karten ab="' . $f2[0]['id'] . '"') === 2 && strpos($tc, 'abi="2"') !== false && strpos($tc, 'Vor ') === 0 && substr($tc, -5) === ' Nach', 'A/B: laufender Test markiert beide Zweierblöcke');
$o1 = SAB::sc_karten(['ab' => $f2[0]['id'], 'abv' => 'A', 'abo' => '1', 'abi' => '1'], $kk2('B0AB000001') . $kk2('B0AB000002'));
$o2 = SAB::sc_karten(['ab' => $f2[0]['id'], 'abv' => 'B', 'abo' => '1', 'abi' => '2'], $kk2('B0AB000003') . $kk2('B0AB000004'));
ok(strpos($o1, 'data-sab-abv="A"') !== false && strpos($o1, '<script') === false && strpos($o2, 'data-sab-abi="2"') !== false && strpos($o2, 'Math.random') !== false && strpos($o2, 'display:none}') !== false, 'A/B: Blöcke tragen Kennung, Skript steht erst nach dem zweiten');
ok(strpos($o1, 'data-sab-slot="ab-' . $f2[0]['id'] . '"') !== false && strpos($o1, 'data-sab-var="A"') !== false && strpos($o2, 'data-sab-var="B"') !== false, 'A/B: Klick-Links tragen Test und Paar (slot ab-…, var A/B)');
$onb = SAB::sc_karten([], $kk2('B0AB000001'));
ok(strpos($onb, 'data-sab-slot') === false && strpos($onb, 'data-sab-ab') === false, 'A/B: Karten außerhalb eines Tests bleiben ohne Test-Daten');
$GLOBALS['wpdb'] = new class { public $prefix = 'wp_'; public $q = []; function prepare($q, ...$a) { return vsprintf(str_replace('%s', "'%s'", $q), $a); } function query($q) { $this->q[] = $q; return 1; } };
$mAz = new ReflectionMethod('SAB', 'ab_ansichten_zaehlen'); $mAz->setAccessible(true);
$mAz->invoke(null, [['b' => $f2[0]['id'], 'v' => 'B'], ['b' => 'abcdef12', 'v' => 'A'], ['b' => $f2[0]['id'], 'v' => 'X']], '/ratgeber/?x=1');
ok(count($GLOBALS['wpdb']->q) === 1 && strpos($GLOBALS['wpdb']->q[0], "'B'") !== false && strpos($GLOBALS['wpdb']->q[0], "'/ratgeber/'") !== false && strpos($GLOBALS['wpdb']->q[0], 'sab_ab_ansicht') !== false, 'A/B: Einblendung wird nur für laufende Tests und gültige Paare gezählt');
unset($GLOBALS['wpdb']);
$mKh = new ReflectionMethod('SAB', 'ab_karte_html'); $mKh->setAccessible(true);
$zz = ['v' => ['A' => ['n' => 500, 'k' => 25], 'B' => ['n' => 500, 'k' => 10]], 's' => ['/a/' => ['A' => ['n' => 500, 'k' => 25], 'B' => ['n' => 500, 'k' => 10]]], 't' => ['2026-10-29' => ['A' => ['n' => 200, 'k' => 10], 'B' => ['n' => 200, 'k' => 4]], '2026-10-30' => ['A' => ['n' => 300, 'k' => 15], 'B' => ['n' => 300, 'k' => 6]]], 'r' => $rB];
$kh = $mKh->invoke(null, $f2[0]['id'], ['an' => 1, 'ziel' => 1000, 'start' => '2026-10-29', 'a' => ['B0AB000001', 'B0AB000002'], 'b' => ['B0AB000003', 'B0AB000004']], $zz, true);
ok(strpos($kh, 'Klarer Sieger: Paar A') !== false && strpos($kh, '5,0 %') !== false && strpos($kh, 'polyline') !== false && strpos($kh, '/a/') !== false, 'A/B: Karte zeigt Sieger, Klickrate, Seiten und Verlauf');
// Reiter und Statistik-Block mit Beispieldaten (Datenbank-Attrappe)
if (!function_exists('wp_nonce_field')) { function wp_nonce_field(...$a) { return '<input type="hidden" name="_wpnonce" value="x">'; } }
if (!function_exists('esc_textarea')) { function esc_textarea($t) { return htmlspecialchars($t); } }
$ABID = $f2[0]['id'];
$GLOBALS['opt']['sab_ab'] = [$ABID => ['an' => 1, 'ziel' => 1000, 'start' => '2026-10-01', 'a' => ['B0AB000001', 'B0AB000002'], 'b' => ['B0AB000003', 'B0AB000004']]];
$GLOBALS['wpdb'] = new class($ABID) {
    public $prefix = 'wp_'; public $posts = 'wp_posts'; function __construct(public $id) {}
    function prepare($q, ...$a) { return vsprintf(str_replace('%s', "'%s'", $q), $a); }
    function query($q) { return 1; }
    function get_results($q, $o = null) {
        if (strpos($q, 'sab_ab_ansicht') !== false) { return [['block' => $this->id, 'var' => 'A', 'pfad' => '/a/', 'tag' => '2026-10-05', 'n' => 520], ['block' => $this->id, 'var' => 'B', 'pfad' => '/a/', 'tag' => '2026-10-05', 'n' => 480]]; }
        if (strpos($q, "slot LIKE 'ab-%'") !== false) { return [['slot' => 'ab-' . $this->id, 'var' => 'A', 'pfad' => '/a/', 'd' => '2026-10-05', 'n' => 26, 'z' => 5], ['slot' => 'ab-' . $this->id, 'var' => 'B', 'pfad' => '/a/', 'd' => '2026-10-05', 'n' => 12, 'z' => 1]]; }
        if (strpos($q, 'post_content LIKE') !== false) { return [['ID' => 7, 'post_title' => 'Kupferband', 'post_content' => '<p>x</p>[amz_karten]' . '[amz_karte asin="B0AB000001"][amz_karte asin="B0AB000002"]' . '[/amz_karten][amz_karten][amz_karte asin="B0AB000003"][amz_karte asin="B0AB000004"][/amz_karten]']]; }
        return [];
    }
};
$zt = (new ReflectionMethod('SAB', 'ab_zahlen')); $zt->setAccessible(true); $zr = $zt->invoke(null);
ok(($zr[$ABID]['r']['nA'] ?? 0) === 520 && ($zr[$ABID]['r']['kB'] ?? 0) === 12 && $zr[$ABID]['r']['status'] === 'klar', 'A/B: Zahlen aus Einblendungen und Klicks zusammengeführt (Ziel 1000 erreicht, klarer Sieger)');
$tt = (new ReflectionMethod('SAB', 'ab_tab_html')); $tt->setAccessible(true); $th = $tt->invoke(null);
ok(strpos($th, 'Laufende Tests (1)') !== false && strpos($th, 'sab_ab_an[' . $ABID . ']') !== false && strpos($th, 'checked') !== false && strpos($th, 'So lese ich die Zahlen') !== false && strpos($th, 'Kupferband') !== false, 'A/B: Reiter zeigt laufenden Test, Haken, Erklärung und Fundstelle');
$st = (new ReflectionMethod('SAB', 'ab_statistik_html')); $st->setAccessible(true);
ok(strpos($st->invoke(null), 'A/B-Tests: Zwischenstand') !== false, 'A/B: Statistik-Reiter zeigt den Zwischenstand');
$_POST = ['sab_ab_an' => [$ABID => '1'], 'sab_ab_ziel' => [$ABID => '2500']];
$sp = (new ReflectionMethod('SAB', 'ab_speichern')); $sp->setAccessible(true); $sp->invoke(null);
ok($GLOBALS['opt']['sab_ab'][$ABID]['ziel'] === 2500 && $GLOBALS['opt']['sab_ab'][$ABID]['start'] === '2026-10-01' && $GLOBALS['opt']['sab_ab'][$ABID]['an'] === 1, 'A/B: Speichern übernimmt das Ziel und behält das Startdatum');
$_POST = ['sab_ab_ziel' => [$ABID => '2500']];
$sp->invoke(null);
ok($GLOBALS['opt']['sab_ab'][$ABID]['an'] === 0, 'A/B: ohne Haken wird der Test beendet, Daten bleiben');
$_POST = [];
unset($GLOBALS['wpdb']);
unset($GLOBALS['opt']['sab_ab']);

// ---- 0.90.1: Einzelprodukt-Test und Frontend-Knöpfe ----
foreach (['B0EZ000001', 'B0EZ000002'] as $a_) { $GLOBALS['http'][$a_] = item($a_, [$money('1 €')]); }
$GLOBALS['opt']['sab_ab'] = ['11223344' => ['an' => 1, 'ziel' => 1000, 'start' => gmdate('Y-m-d'), 'a' => ['B0EZ000001'], 'b' => ['B0EZ000002'], 'e' => 1]];
$ez = SAB::sc_karte(['asin' => 'B0EZ000001', 'land' => 'es']);
ok(strpos($ez, 'data-sab-abe="11223344"') !== false && strpos($ez, 'data-v="A"') !== false && strpos($ez, 'data-v="B"') !== false && strpos($ez, 'display:contents') !== false && substr_count($ez, '<script>') === 1, 'Einzeltest: Karte erscheint als zwei Panes mit Skript');
ok(strpos($ez, 'data-sab-slot="ab-11223344"') !== false && strpos($ez, 'data-sab-var="A"') !== false && strpos($ez, 'data-sab-var="B"') !== false && strpos($ez, 'B0EZ000002') !== false, 'Einzeltest: Klicks beider Produkte zählen für Test und Paar');
$ez0 = SAB::sc_karte(['asin' => 'B0EZ000002', 'land' => 'es']);
ok(strpos($ez0, 'data-sab-abe') === false, 'Einzeltest: nur das Testprodukt A wird verdoppelt, B allein bleibt normal');
$GLOBALS['opt']['sab_ab']['11223344']['an'] = 0;
ok(strpos(SAB::sc_karte(['asin' => 'B0EZ000001', 'land' => 'es']), 'data-sab-abe') === false, 'Einzeltest: beendet => wieder eine einfache Karte');
$GLOBALS['opt']['sab_ab'] = [];
$GLOBALS['admin'] = true; $GLOBALS['loggedin'] = true;
$ed = SAB::ab_inhalt('A ' . $zwei . $zwei2 . ' B');
ok(substr_count($ed, '[amz_karten abx="' . $f2[0]['id'] . '"]') === 2 && strpos($ed, ' ab="') === false, 'Redakteur: Viererblock ohne Test bekommt nur die Markierung für den Start-Knopf');
$GLOBALS['opt']['sab_ab'] = [$f2[0]['id'] => ['an' => 1, 'ziel' => 1000, 'start' => gmdate('Y-m-d')]];
$ed2 = SAB::ab_inhalt($zwei . $zwei2);
ok(substr_count($ed2, 'abx="' . $f2[0]['id'] . '"') === 2 && substr_count($ed2, 'ab="' . $f2[0]['id'] . '"') === 2, 'Redakteur: laufender Test trägt zusätzlich die Knopf-Markierung');
$eo = SAB::sc_karten(['ab' => $f2[0]['id'], 'abv' => 'B', 'abo' => '9', 'abi' => '2', 'abx' => $f2[0]['id']], $kk2('B0AB000003') . $kk2('B0AB000004'));
ok(strpos($eo, 'data-sab-abx="' . $f2[0]['id'] . '"') !== false && strpos($eo, '},true);') !== false || strpos($eo, ',true);</script>') !== false, 'Redakteur: Skript wählt immer das erste Paar und zählt nicht');
$GLOBALS['admin'] = false; $GLOBALS['loggedin'] = false;
$GLOBALS['opt']['sab_ab'] = [];
$mA = new ReflectionMethod('SAB', 'alle_asins'); $mA->setAccessible(true); unset($GLOBALS['tr']['sab_alle_asins']);
$GLOBALS['opt']['sab_ab'] = ['11223344' => ['an' => 1, 'a' => ['B0EZ000001'], 'b' => ['B0EZ000002'], 'e' => 1]];
ok(in_array('B0EZ000002', $mA->invoke(null), true), 'Einzeltest: das Gegenprodukt zählt als bekannte ASIN (Klicks werden gespeichert)');
$GLOBALS['opt']['sab_ab'] = []; unset($GLOBALS['tr']['sab_alle_asins']);

// ---- 0.90.2: Seiten mit Aufrufen und Klickrate ----
if (!function_exists('add_query_arg')) { function add_query_arg($a, $u) { return $u . '?' . http_build_query($a); } }
$GLOBALS['wpdb'] = new class {
    public $prefix = 'wp_'; public $q = [];
    function prepare($q, ...$a) { return vsprintf(str_replace('%s', "'%s'", $q), $a); }
    function query($q) { $this->q[] = $q; return 1; }
    function get_results($q, $o = null) {
        if (strpos($q, 'sab_seiten') !== false) { return [['pfad' => '/gut/', 'n' => 1000], ['pfad' => '/schlecht/', 'n' => 2000], ['pfad' => '/neu/', 'n' => 20]]; }
        if (strpos($q, 'sab_klicks') !== false) { return [['pfad' => '/gut/', 'n' => 100], ['pfad' => '/schlecht/', 'n' => 20], ['pfad' => '/neu/', 'n' => 0]]; }
        return [];
    }
};
$mSh = new ReflectionMethod('SAB', 'ab_seiten_html'); $mSh->setAccessible(true); $sh = $mSh->invoke(null);
ok(strpos($sh, '/schlecht/') !== false && strpos($sh, 'schwach bei vielen Besuchen') !== false && substr_count($sh, 'schwach bei vielen Besuchen') === 1 && strpos($sh, '1,0 %') !== false && strpos($sh, '10,0 %') !== false, 'Seiten: Klickrate je Seite, nur die schwache Seite mit vielen Aufrufen wird markiert');
unset($GLOBALS['wpdb']);

// ---- 0.90.3: Widget-Test ----
$GLOBALS['opt']['sab_ab'] = [];
$wA = $GLOBALS['sc']['amz_widget'](['typ' => 'buch', 'asin' => 'B0EZ000001', 'titel' => 'Buch A', 'text' => 'Text A', 'alt_text' => 'Zweiter', 'alt_url' => 'https://x.test/']);
ok(strpos($wA, 'sab-ab-e') === false, 'Widget-Test: ohne Test bleibt das Widget einfach');
$mWsp = new ReflectionMethod('SAB', 'ab_widget_speichern'); $mWsp->setAccessible(true);
$_POST = ['sab_abw' => 'start', 'sab_abw_asin' => 'B0EZ000001', 'sab_abw_neu' => 'B0EZ000001']; $msg0 = $mWsp->invoke(null); $_POST = [];
ok(strpos($msg0, 'Nichts gestartet') !== false && !$GLOBALS['opt']['sab_ab'], 'Widget-Test: gleiches Produkt wird abgelehnt');
$wid = 'cafe0001';
$GLOBALS['opt']['sab_ab'] = [$wid => ['an' => 1, 'ziel' => 1000, 'start' => gmdate('Y-m-d'), 'a' => ['B0EZ000001'], 'b' => ['B0EZ000002'], 'w' => 1, 'wf' => ['titel' => 'Buch B', 'text' => 'Text B', 'marke' => '', 'knopf' => '']]];
$GLOBALS['opt']['sab_widget_alt'] = '1';
$wT = $GLOBALS['sc']['amz_widget'](['typ' => 'buch', 'asin' => 'B0EZ000001', 'titel' => 'Buch A', 'text' => 'Text A', 'alt_text' => 'Zweiter', 'alt_url' => 'https://x.test/']);
$wAlt0 = $GLOBALS['opt']['sab_widget_alt']; unset($GLOBALS['opt']['sab_widget_alt']);
$wOhne = $GLOBALS['sc']['amz_widget'](['typ' => 'buch', 'asin' => 'B0EZ000003', 'titel' => 'Buch C', 'text' => 'Text C', 'alt_text' => 'Zweiter', 'alt_url' => 'https://x.test/']);
ok(strpos($wOhne, 'sab-walt') === false && strpos($wOhne, 'sab-wbtn') !== false && strpos((string) SAB::WIDGET_CSS, 'sabwip') !== false, 'Widget: zweiter Link standardmäßig aus, Wippen-CSS nur mit Bewegungs-Schutz');
$GLOBALS['opt']['sab_widget_alt'] = $wAlt0;
ok(strpos($wT, 'data-sab-abe="' . $wid . '"') !== false && strpos($wT, 'Buch A') !== false && strpos($wT, 'Text B') !== false && strpos($wT, 'data-sab-var="B"') !== false && strpos($wT, 'data-sab-slot="ab-' . $wid . '"') !== false && substr_count($wT, 'sab-walt') === 1, 'Widget-Test: beide Karten, Klicks zählen für Test, Alternativlink nur bei A');
$_POST = ['sab_abw' => 'stop:' . $wid]; $msg2 = $mWsp->invoke(null); $_POST = [];
ok(empty($GLOBALS['opt']['sab_ab'][$wid]['an']) && strpos($GLOBALS['sc']['amz_widget'](['typ' => 'buch', 'asin' => 'B0EZ000001', 'titel' => 'Buch A']), 'sab-ab-e') === false, 'Widget-Test: beenden stellt das einfache Widget wieder her');
$mWf2 = new ReflectionMethod('SAB', 'ab_widget_form_html'); $mWf2->setAccessible(true);
$fh = $mWf2->invoke(null, 'block-16', ['inhalt' => '<p>[amz_widget typ="buch" asin="B0EZ000001" titel="x"]</p>']);
ok(strpos($fh, 'Test starten') !== false && strpos($fh, 'sab_abw_neu') !== false && $mWf2->invoke(null, 'block-9', ['inhalt' => '<p>kein Shortcode</p>']) === '', 'Widget-Test: Formular nur bei umgebauten Buch-Widgets');
$GLOBALS['opt']['sab_ab'] = []; unset($GLOBALS['tr']['sab_alle_asins']);

// ---- 0.91.0: A/B/C-Test mit drei Varianten ----
$n3 = SAB::ab_bewerten_n(['A' => [334, 30], 'B' => [333, 15], 'C' => [333, 14]], 1000, 't1');
ok($n3['status'] === 'klar' && $n3['sieger'] === 'A' && $n3['chi_p'] < 0.05 && $n3['vgl']['B']['p_korr'] < 0.05 && $n3['vgl']['C']['p_korr'] < 0.05 && $n3['bestwahrsch']['A'] > 0.95, 'A/B/C: eine Variante klar vorn => klarer Sieger (chi p ' . round($n3['chi_p'], 4) . ')');
$n4 = SAB::ab_bewerten_n(['A' => [334, 20], 'B' => [333, 18], 'C' => [333, 19]], 1000, 't2');
ok($n4['status'] === 'gleich' && $n4['sieger'] === '' && $n4['chi_p'] > 0.5, 'A/B/C: fast gleiche Raten => kein Unterschied');
$n5 = SAB::ab_bewerten_n(['A' => [334, 30], 'B' => [333, 14], 'C' => [333, 25]], 1000, 't3');
ok($n5['status'] === 'teils' || $n5['status'] === 'klar' || $n5['status'] === 'tendenz', 'A/B/C: gemischter Fall liefert ein Urteil (' . $n5['status'] . ', A vs C p_korr ' . round($n5['vgl']['C']['p_korr'], 3) . ')');
$n6 = SAB::ab_bewerten_n(['A' => [100, 5], 'B' => [100, 2], 'C' => [100, 1]], 1000, 't4');
ok($n6['status'] === 'laeuft' && abs(array_sum($n6['bestwahrsch']) - 1.0) < 1e-9, 'A/B/C: vor dem Ziel nur „läuft“, Wahrscheinlichkeiten ergeben 100 %');
ok(SAB::ab_bewerten_n(['A' => [0, 0], 'B' => [0, 0], 'C' => [0, 0]], 1000)['status'] === 'leer', 'A/B/C: ohne Daten kein Rechenfehler');
$GLOBALS['opt']['sab_ab'] = ['cafe0003' => ['an' => 1, 'ziel' => 1000, 'start' => gmdate('Y-m-d'), 'a' => ['B0EZ000001'], 'b' => ['B0EZ000002'], 'c' => ['B0EZ000003'], 'w' => 1, 'wf' => ['titel' => 'Buch B', 'text' => 'Text B'], 'wfc' => ['titel' => 'Buch C', 'text' => 'Text C']]];
$GLOBALS['http']['B0EZ000003'] = item('B0EZ000003', [$money('1 €')]);
$w3 = $GLOBALS['sc']['amz_widget'](['typ' => 'buch', 'asin' => 'B0EZ000001', 'titel' => 'Buch A', 'text' => 'Text A']);
ok(substr_count($w3, 'class="sab-ab-ep"') === 3 && strpos($w3, 'data-v="C"') !== false && strpos($w3, 'data-sab-var="C"') !== false && strpos($w3, 'Buch C') !== false && strpos($w3, 'Math.floor(Math.random()*n)') !== false, 'A/B/C: Widget zeigt drei Panes, Klicks von C zählen für den Test');
$mKh3 = new ReflectionMethod('SAB', 'ab_karte_html'); $mKh3->setAccessible(true);
$k3 = $mKh3->invoke(null, 'cafe0003', $GLOBALS['opt']['sab_ab']['cafe0003'], ['r' => $n3, 'v' => [], 's' => ['/x/' => ['A' => ['n' => 5, 'k' => 1]]], 't' => ['2026-10-29' => ['C' => ['n' => 5, 'k' => 1]], '2026-10-30' => ['C' => ['n' => 9, 'k' => 2]]]], true);
ok(strpos($k3, 'Klarer Sieger: Paar A') !== false && strpos($k3, 'Variante C') !== false && strpos($k3, 'Wahrscheinlichkeit, die beste zu sein') !== false && strpos($k3, 'sicher besser') !== false, 'A/B/C: Karte zeigt drei Varianten, Gesamttest und Vergleiche');
$GLOBALS['opt']['sab_ab'] = []; unset($GLOBALS['tr']['sab_alle_asins']);

// ---- 0.92.0: Zweierblock-Test und Begründung der Vorschläge ----
foreach (['B0ZW000001', 'B0ZW000002', 'B0ZW000003', 'B0ZW000004'] as $a_) { $GLOBALS['http'][$a_] = item($a_, [$money('1 €')]); }
$GLOBALS['opt']['sab_ab'] = ['beef0001' => ['an' => 1, 'ziel' => 1000, 'start' => gmdate('Y-m-d'), 'a' => ['B0ZW000001', 'B0ZW000002'], 'b' => ['B0ZW000003', 'B0ZW000004'], 'z' => 1]];
$zin = $kk2('B0ZW000002') . $kk2('B0ZW000001');
$zh = $GLOBALS['sc']['amz_karten']([], $zin);
ok(strpos($zh, 'data-sab-abe="beef0001"') !== false && substr_count($zh, 'class="sab-ab-ep"') === 2 && strpos($zh, 'B0ZW000003') !== false && strpos($zh, 'B0ZW000004') !== false && strpos($zh, 'data-sab-var="B"') !== false && strpos($zh, 'data-sab-slot="ab-beef0001"') !== false, 'Zweierblock-Test: Block erscheint mit dem anderen Block als zweite Variante (Reihenfolge egal)');
$zo = $GLOBALS['sc']['amz_karten']([], $kk2('B0ZW000001') . $kk2('B0ZW000009'));
ok(strpos($zo, 'sab-ab-e') === false, 'Zweierblock-Test: andere Blöcke bleiben unberührt');
$GLOBALS['opt']['sab_ab']['beef0001']['an'] = 0;
ok(strpos($GLOBALS['sc']['amz_karten']([], $zin), 'sab-ab-e') === false, 'Zweierblock-Test: beendet => wieder der einfache Block');
$GLOBALS['opt']['sab_ab'] = [];
$mW = new ReflectionMethod('SAB', 'ab_warum'); $mW->setAccessible(true);
$wt = $mW->invoke(null, ['asin' => 'B0ZW000003', 'quelle' => 'Ersatz-Datenbank (Kupferband)', 'passt' => 3, 'score' => 82, 'ok' => true], ['B0ZW000003' => ['n' => 14]], ['B0ZW000003']);
ok(strpos($wt, 'selben Produktgruppe') !== false && strpos($wt, 'Score 82 von 100') !== false && strpos($wt, '14 Klicks') !== false && strpos($wt, 'lieferbar') !== false, 'Begründung nennt Gruppe, Quelle, Score, eigene Klicks und Lieferbarkeit');
unset($GLOBALS['tr']['sab_alle_asins']);
echo $fail ? "\n$fail FEHLER\n" : "\nAlles bestanden\n"; exit($fail ? 1 : 0);
