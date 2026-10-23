<?php
// Offline-Test ohne WordPress: php test-offline.php  (Stubs für die benötigten WP-Funktionen)
define('SAB_SYNC', 1); define('ABSPATH', __DIR__ . '/');
const DAY_IN_SECONDS = 86400; const HOUR_IN_SECONDS = 3600; const MINUTE_IN_SECONDS = 60;
$GLOBALS['opt'] = []; $GLOBALS['tr'] = []; $GLOBALS['sc'] = []; $GLOBALS['http'] = []; $GLOBALS['admin'] = false;
function add_shortcode($n, $f) { $GLOBALS['sc'][$n] = $f; }
function add_action(...$a) {} function add_menu_page(...$a) { $GLOBALS['menu'][] = ['top', $a[3]]; } function add_submenu_page(...$a) { $GLOBALS['menu'][] = ['sub', $a[4]]; } function add_filter(...$a) {} function wp_mail($t,$s,$b) { $GLOBALS['mail'][] = [$t,$s,$b]; return true; } function sanitize_email($e) { return $e; } function plugin_basename($f) { return 'schlanke-amazon-box/schlanke-amazon-box.php'; } function wp_next_scheduled($x) { return true; }
function get_option($k, $d = false) { return $GLOBALS['opt'][$k] ?? $d; }
function update_option($k, $v, $a = null) { $GLOBALS['opt'][$k] = $v; return true; }
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
ok(strpos($h, 'keine Bewertungen') !== false || strpos($h, 'Bewertungen') !== false, 'Bewertungen: Hinweis statt Abfrage');
ok(strpos($h, 'grid-template-columns:repeat(2') !== false, 'Bilder in zwei Spalten');
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
ok(strpos($h, 'keine Bewertungen') !== false, 'Übersicht: Hinweis, dass keine Bewertungen geliefert werden');

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
ok(count($GLOBALS['menu']) === 12 && $GLOBALS['menu'][0] === ['top', 'sab'], 'Menü: ein Hauptpunkt und elf Untermenüs');
ok(in_array(['sub', 'sab-vorschau'], $GLOBALS['menu'], true) && in_array(['sub', 'sab'], $GLOBALS['menu'], true), 'Menü: Untermenü Vorschau und Übersicht');
$GLOBALS['admin'] = true; unset($_GET['tab']); $_GET['page'] = 'sab-hilfe'; $_POST = [];
ob_start(); SAB::seite(); $h = ob_get_clean();
ok(strpos($h, 'nav-tab-active') !== false && preg_match('/nav-tab-active" href="[^"]*sab-hilfe/', $h) === 1, 'Seite: Reiter folgt dem Untermenü');
ok(strpos($h, 'admin.php?page=sab-produkte') !== false, 'Seite: Reiterlinks über admin.php');
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
[$p1, $h1] = $mSc->invoke(null, ['rang' => 800, 'kaufen' => true, 'status' => 'IN_STOCK', 'lieferbar' => true, 'preis' => '9 €', 'spar' => ['streich' => '12 €'], 'deal' => 'X']);
ok($p1 === 100 && $h1 === '', 'Score: bestes Produkt = 100');
[$p2, $h2] = $mSc->invoke(null, ['rang' => 4334, 'kaufen' => true, 'status' => 'OUT_OF_STOCK', 'lieferbar' => true, 'preis' => '', 'verkaeufer' => '']);
ok($p2 === 58 && strpos($h2, 'Schnittstelle prüfen') !== false, 'Score: guter Rang ohne Angebot = Hinweis');
[$p3, $h3] = $mSc->invoke(null, ['kaufen' => false, 'status' => 'OUT_OF_STOCK', 'lieferbar' => false]);
ok($p3 === 0 && $h3 === 'ohne Verkaufsrang', 'Score: nichts = 0');

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
if (!function_exists('get_post')) { function get_post($id) { return (object) ['ID' => $id, 'post_title' => 'Zaun-Artikel', 'post_content' => '<p><a href="https://amzn.to/abc">Zum Zaun</a> <a href="https://www.amazon.es/dp/B0BBBBBBB1?tag=x"><img src="x.jpg"></a></p>']; } }
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
ok(strpos($neu, 'asin="B0TABLE002"') !== false && strpos($neu, 'suche="anillos"') !== false && strpos($neu, 'text="Ver en Amazon"') !== false, 'Karte übernimmt Kurzlink-Text und eBay-Suche');
ok($n === 2, 'Umbau zählt die Karten');
$h = SAB::sc_karten([], '[amz_karte asin="B0TABLE001" land="es" text="Ver en Amazon" ebay="1" suche="x" campid="5338794835"]');
ok(strpos($h, 'class="sab-karten"') !== false && strpos($h, 'class="sab-karte"') !== false && strpos($h, 'sab-karte-btn') !== false && strpos($h, 'sab-karte-ebay') !== false && strpos($h, '_nkw=x') !== false, 'Karte rendert Button und eBay-Link');
$GLOBALS['opt']['sab_karten_umbau'] = '1';
[$neu, ] = SAB::umbau_text(str_replace('https://amzn.to/unbekannt1', 'https://amzn.to/zeigtandres', $tab));
$GLOBALS['kurz']['https://amzn.to/zeigtandres'] = 'https://www.amazon.es/dp/B0OTHER001';
[$neu, ] = SAB::umbau_text(str_replace('https://amzn.to/unbekannt1', 'https://amzn.to/zeigtandres', $tab));
ok(strpos($neu, '[amz_karten]') === false, 'Bild und Kurzlink zu verschiedenen Produkten: Tabelle bleibt');
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
echo $fail ? "\n$fail FEHLER\n" : "\nAlles bestanden\n"; exit($fail ? 1 : 0);
