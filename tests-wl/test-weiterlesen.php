<?php
// Offline-Test ohne WordPress: php tests-wl/test-weiterlesen.php
define('ABSPATH', __DIR__ . '/'); define('AB1_WL_TEST', 1);
function add_action(...$a){} function add_filter(...$a){} function add_shortcode(...$a){} function register_activation_hook(...$a){}
require __DIR__ . '/../1ab-weiterlesen/1ab-weiterlesen-modul.php';
$f = 0; function ok($c, $m) { global $f; echo ($c ? 'OK   ' : 'FEHLT ') . $m . "\n"; if (!$c) $f++; }
$A = ['id'=>1,'titel'=>'Schnecken im Garten abwehren','kat'=>[5],'tag'=>[1],'sprache'=>'de','noindex'=>false,'fokus'=>'schneckenabwehr'];
$B = $A; $B['id']=2; $B['titel']='Schnecken Hausmittel im Garten'; $B['fokus']='hausmittel gegen schnecken';
ok(AB1_Weiterlesen::darf_verlinken($A,$B), 'gleiches Thema, andere Keywords: erlaubt');
$C = $B; $C['sprache']='fr'; ok(!AB1_Weiterlesen::darf_verlinken($A,$C), 'andere Sprache gesperrt');
$C = $B; $C['kat']=[9]; ok(!AB1_Weiterlesen::darf_verlinken($A,$C), 'anderes Thema gesperrt');
$C = $B; $C['fokus']='schneckenabwehr'; ok(!AB1_Weiterlesen::darf_verlinken($A,$C), 'gleiches Fokus-Keyword gesperrt');
$C = $B; $C['noindex']=true; ok(!AB1_Weiterlesen::darf_verlinken($A,$C), 'noindex gesperrt');
ok(!AB1_Weiterlesen::darf_verlinken($A,$A), 'Selbstverlinkung gesperrt');
ok(AB1_Weiterlesen::bewerte($A,$B) > AB1_Weiterlesen::bewerte($A,array_merge($B,['kat'=>[],'tag'=>[],'titel'=>'Etwas ganz anderes'])), 'Bewertung: ähnlicher Artikel höher');
ok(AB1_Weiterlesen::bewerte($A,$B,3) - AB1_Weiterlesen::bewerte($A,$B,0) == 3, 'Gewicht wirkt');
$in = '<p>Eins</p><ul><li>a</li></ul><p>Zwei</p><div><p>Drei</p></div><p>Vier</p><p>Fünf</p>';
$o = AB1_Weiterlesen::fuege_nach_absatz($in, '[X]', 3);
ok(strpos($o, "<p>Drei</p>[X]") === false, "Teaser nicht in Block");
ok(strpos($o, '[X]') > strpos($o, '</div>'), 'Teaser erst nach geschlossenem Block');
ok(AB1_Weiterlesen::fuege_nach_absatz('<p>a</p>', '[X]', 3) === '<p>a</p>', 'zu kurzer Artikel bleibt unverändert');
ok(AB1_Weiterlesen::fuege_nach_absatz('<ul><li><p>a</p></li></ul><p>b</p>', '[X]', 1) === '<ul><li><p>a</p></li></ul><p>b</p>[X]', 'Absatz in Liste wird übersprungen');
list($lo,$hi) = AB1_Weiterlesen::wilson(30,300); ok($lo>0.06 && $hi<0.14 && $lo<0.1 && $hi>0.1, "Wilson 10 %: $lo $hi");
$k1 = AB1_Weiterlesen::kennung('1.2.3.4','UA','g',1000); $k2 = AB1_Weiterlesen::kennung('1.2.3.4','UA','g',1000+86400);
$k3 = AB1_Weiterlesen::kennung('1.2.3.4','UA','g',1000+700000);
ok($k1===$k2 && $k1!==$k3 && strlen($k1)==64 && strpos($k1,'1.2.3.4')===false, 'Kennung stabil in der Woche, wechselt danach, enthält keine IP');
$v5 = ['a','b','c','d','e']; $z = [];
for ($i = 0; $i < 400; $i++) $z[AB1_Weiterlesen::popup_variante('k'.$i, 20000, $v5)] = 1;
ok(count($z) === 5, 'alle fünf Varianten kommen vor');
ok(AB1_Weiterlesen::popup_variante('kx', 20000, $v5) === AB1_Weiterlesen::popup_variante('kx', 20000, $v5), 'am selben Tag stabil für denselben Besucher');
$tage = []; for ($d = 20000; $d < 20040; $d++) $tage[AB1_Weiterlesen::popup_variante('kx', $d, $v5)] = 1;
ok(count($tage) >= 4, 'Variante wechselt mit der Tageszahl');
ok(AB1_Weiterlesen::popup_variante('kx', 1, ['c']) === 'c' && AB1_Weiterlesen::popup_variante('kx', 1, []) === '', 'eine oder keine Variante');
echo $f ? "\n$f Fehler\n" : "\nAlles bestanden\n"; exit($f ? 1 : 0);
