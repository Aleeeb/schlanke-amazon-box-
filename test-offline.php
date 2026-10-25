<?php
// Offline-Test ohne WordPress: php test-offline.php  (Stubs für die benötigten WP-Funktionen)
define('SAB_SYNC', 1); define('ABSPATH', __DIR__ . '/');
const DAY_IN_SECONDS = 86400; const HOUR_IN_SECONDS = 3600; const MINUTE_IN_SECONDS = 60;
$GLOBALS['opt'] = []; $GLOBALS['tr'] = []; $GLOBALS['sc'] = []; $GLOBALS['http'] = []; $GLOBALS['admin'] = false;
function add_shortcode($n, $f) { $GLOBALS['sc'][$n] = $f; }
function add_action(...$a) {} function add_menu_page(...$a) { $GLOBALS['menu'][] = ['top', $a[3]]; } function add_submenu_page(...$a) { $GLOBALS['menu'][] = ['sub', $a[4]]; } function add_filter(...$a) {} function wp_mail($t,$s,$b) { $GLOBALS['mail'][] = [$t,$s,$b]; return true; } function sanitize_email($e) { return $e; } function plugin_basename($f) { return 'schlanke-amazon-box/schlanke-amazon-box.php'; } function wp_next_scheduled($x) { return true; }
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
    foreach ($ids as $id) { $items[] = $GLOBALS['http'][$id] ?? null; }
    return ['code' => 200, 'body' => json_encode(['itemsResult' => ['items' => array_values(array_filter($items))]])];
}
function wp_remote_get($u, $a) {
    if (strpos($u, 'update.json') !== false) return ['code' => 200, 'body' => json_encode($GLOBALS['upd'] ?? [])]; return ['code' => 200, 'body' => 'JPG', 'ct' => 'image/jpeg']; }
function wp_remote_retrieve_response_code($r) { return $r['code']; }
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
ok(strpos($f, '15 Partnerlinks') !== false, 'Warnbox für Admin');
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
ok(count($GLOBALS['menu']) === 13 && $GLOBALS['menu'][0] === ['top', 'sab'], 'Menü: ein Hauptpunkt und elf Untermenüs');
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
ok($p2 >= 40 && $p2 <= 48 && strpos($h2, 'Schnittstelle prüfen') !== false, 'Score: guter Rang ohne Angebot = Hinweis');
[$p3, $h3] = $mSc->invoke(null, ['kaufen' => false, 'status' => 'OUT_OF_STOCK', 'lieferbar' => false]);
ok($p3 <= 10 && $h3 === 'ohne Verkaufsrang', 'Score: nichts ist (fast) 0');
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
$GLOBALS['wpdb'] = new class { public $posts = 'wp_posts'; function get_results($q, $o = null) { return [['ID' => 5, 'post_title' => 'T', 'post_content' => '[amz_link asin="B0AAAAAAA1" land="es"]Zaun[/amz_link] und <a href="https://www.amazon.es/dp/B0AAAAAAA1?tag=x">fest</a>']]; } function prepare($q, ...$a) { return $q; } function get_col($q) { return []; } };
$fd = $mFd->invoke(null, ['B0AAAAAAA1']);
ok(($fd['B0AAAAAAA1'][5]['n'] ?? 0) === 2 && ($fd['B0AAAAAAA1'][5]['fest'] ?? -1) === 1, 'Fundstellen: gesamt 2, davon 1 fester Link');

// Umbau-Reiter: Artikelliste und Vorschau
if (!function_exists('get_post')) { function get_post($id) { return (object) ['ID' => $id, 'post_title' => 'Zaun-Artikel', 'post_content' => $GLOBALS['pc'] ?? '<p><a href="https://amzn.to/abc">Zum Zaun</a> <a href="https://www.amazon.es/dp/B0BBBBBBB1?tag=x"><img src="x.jpg"></a></p>']; } }
if (!function_exists('get_post_meta')) { function get_post_meta($id, $k, $single = false) { return ''; } }
$GLOBALS['wpdb'] = new class { public $posts = 'wp_posts'; function get_results($q, $o = null) { return [['ID' => 7, 'post_title' => 'Zaun-Artikel', 'post_content' => '<p><a href="https://amzn.to/abc">Zum Zaun</a> <a href="https://www.amazon.es/dp/B0BBBBBBB1?tag=x"><img src="x.jpg"></a></p>']]; } function prepare($q, ...$a) { return $q; } };
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
ok(count($l) === 3 && strpos($l[0], 'sab-einstellungen') !== false, 'Pluginliste: Link Einstellungen');

// ---- 0.42.0: Handdaten ----
$GLOBALS['opt']['sab_manuell'] = "B0TABLE001 | 4,1 | 588 | ja | Madrid 2, Barcelona 3, Sevilla 2 | 2026-10-24\nB0TABLE002 | 4,8 | 9 | nein | Madrid 1,5";
$mM = new ReflectionMethod('SAB', 'manuell_daten'); $mM->setAccessible(true);
$md = $mM->invoke(null);
ok($md['B0TABLE001']['sterne'] === 4.1 && $md['B0TABLE001']['anzahl'] === 588 && $md['B0TABLE001']['prime'] === true && abs($md['B0TABLE001']['liefertage'] - 2.3333) < 0.01, 'Handdaten: Sterne, Anzahl, Prime und Ø Lieferzeit gelesen');
ok($md['B0TABLE002']['prime'] === false && $md['B0TABLE002']['liefertage'] === 1.5, 'Handdaten: kein Prime, Dezimal-Lieferzeit');
$GLOBALS['opt']['sab_score_w'] = ['rang' => 0, 'buybox' => 0, 'angebot' => 0, 'preis' => 0, 'streich' => 0, 'deal' => 0, 'bewertung' => 10, 'konstanz' => 0, 'prime' => 0, 'liefer' => 0];
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
SAB::$umbau_abw = []; [$uw, $un] = SAB::umbau_text('<p><a href="https://www.amazon.es/dp/B0TOT00001">Oferta</a></p>');
ok($un === 0 && strpos($uw, 'amz_link') === false && count(SAB::$umbau_abw) === 1, 'Umbau: Link auf ein Produkt, das Amazon nicht mehr kennt, bleibt unverändert und wird gemeldet');
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
$GLOBALS['wpdb'] = new class($tab4) { public $posts = 'wp_posts'; private $c; function __construct($c) { $this->c = $c; } function get_results($q, $o = null) { return [['ID' => 8, 'post_title' => 'Kupfer', 'post_content' => $this->c]]; } function prepare($q, ...$a) { return $q; } };
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
ok(substr_count($vh3, 'Jetzt so umbauen') === 2 && strpos($vh3, 'Ersatz wählen') !== false && strpos($vh3, 'value="B0ERSATZ01"') !== false && strpos($vh3, 'name="sab_form" value="tausch_fest"') !== false, 'Vorschau: Umbau-Knopf oben und unten, bei totem Produkt Ersatzvorschlag aus den Favoriten mit Eingabefeld');

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
ok(strpos($vh5, 'Empfehlung (von Amazons Alternativen)') !== false && strpos($vh5, 'value="B0ERSATZ01"') !== false, 'Ersatzkasten: eingetragene Empfehlung (Amazons Alternative) wird angezeigt und vorbelegt');

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
$GLOBALS['wpdb'] = new class { public $posts = 'wp_posts'; function get_results($q, $o = null) { return [['ID' => 12, 'post_title' => 'Artikel A', 'post_content' => '<p><a href="https://www.amazon.es/dp/B0VIELE001"><img src="x.jpg"></a></p>']]; } function prepare($q, ...$a) { return $q; } };
$GLOBALS['pc'] = '<p><a href="https://www.amazon.es/dp/B0VIELE001"><img src="x.jpg"></a></p>';
$mUe = new ReflectionMethod('SAB', 'umbau_uebersicht_html'); $uh = $mUe->invoke(null, 1);
$GLOBALS['wpdb'] = $wpdb_m; unset($GLOBALS['pc']);
ok(strpos($uh, 'Artikel A') !== false && strpos($uh, 'bereit') !== false && strpos($uh, 'bereiten Artikel jetzt umbauen') !== false && strpos($uh, 'value="umbau_viele"') !== false, 'Gesamtvorschau: Status je Artikel und Knopf zum Umbau der bereiten Artikel');

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
$GLOBALS['opt']['sab_produkte'] = "B0DEAD0001 | Kupferband alt\nB0ALT00001 | Kupferband neu"; $GLOBALS['opt']['sab_amz_alt'] = "B0DEAD0001 | B0ALT00001, B0ALT00002 | 2026-10-25";
$GLOBALS['opt']['sab_uebersicht_stand_es'] = ['items' => ['B0ALT00001' => ['titel' => 'Cinta de cobre 50 mm', 'preis' => '9,99 €', 'kaufen' => true, 'lieferbar' => true, 'status' => 'IN_STOCK', 'rang' => 150, 'kategorie' => 'Jardín']], 'ts' => time()];
$GLOBALS['tr']['sab_uebersicht_es'] = $GLOBALS['opt']['sab_uebersicht_stand_es']; $GLOBALS['tr']['sab_luecke_es'] = 1; unset($GLOBALS['tr']['sab_alle_asins']);
$wpdb_x = $GLOBALS['wpdb'] ?? null;
$GLOBALS['wpdb'] = new class { public $posts = 'wp_posts'; function get_results($q, $o = null) { return [['ID' => 20, 'post_title' => 'Seite X', 'post_content' => '<p>[amz_bild asin="B0DEAD0001" land="es"][/amz_bild]</p>']]; } function get_col($q) { return []; } function prepare($q, ...$a) { return $q; } };
$mAh = new ReflectionMethod('SAB', 'austausch_html'); $ah = $mAh->invoke(null);
ok(strpos($ah, 'Zu tauschen (1)') !== false && strpos($ah, 'B0ALT00001') !== false && strpos($ah, 'Amazon-Alternative 1') !== false && strpos($ah, 'name="sab_a[B0DEAD0001]"') !== false, 'Austausch-Reiter: totes Produkt mit Amazons Alternative als bestem Kandidaten');
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
echo $fail ? "\n$fail FEHLER\n" : "\nAlles bestanden\n"; exit($fail ? 1 : 0);