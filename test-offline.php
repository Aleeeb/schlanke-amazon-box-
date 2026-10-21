<?php
// Offline-Test ohne WordPress: php test-offline.php  (Stubs für die benötigten WP-Funktionen)
define('ABSPATH', __DIR__ . '/');
const DAY_IN_SECONDS = 86400; const HOUR_IN_SECONDS = 3600; const MINUTE_IN_SECONDS = 60;
$GLOBALS['opt'] = []; $GLOBALS['tr'] = []; $GLOBALS['sc'] = []; $GLOBALS['http'] = []; $GLOBALS['admin'] = false;
function add_shortcode($n, $f) { $GLOBALS['sc'][$n] = $f; }
function add_action(...$a) {} function add_filter(...$a) {} function wp_mail($t,$s,$b) { $GLOBALS['mail'][] = [$t,$s,$b]; return true; } function sanitize_email($e) { return $e; } function plugin_basename($f) { return 'schlanke-amazon-box/schlanke-amazon-box.php'; } function wp_next_scheduled($x) { return true; }
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
ok(strpos($h, '4.6 ★ (321)') !== false, 'Bewertungen angezeigt');
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
ok(strpos($h, 'laut API: nein') !== false && strpos($h, 'Standard-Lieferort') !== false, 'Übersicht: „laut API: nein“ mit Lieferort-Hinweis');
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
ok(strpos($o, 'max-width:420px') !== false && strpos($o, '<img') !== false, 'aawp image_width wird übernommen');
echo $fail ? "\n$fail FEHLER\n" : "\nAlles bestanden\n"; exit($fail ? 1 : 0);
