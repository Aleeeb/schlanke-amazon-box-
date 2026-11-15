<?php
// Offline-Test (reine Funktionen) für 0.41: php tests-wl/test-weiterlesen-041.php <Pfad zu weiterlesen-modul.php>
define('ABSPATH', __DIR__ . '/'); 
function add_action(...$a){} function add_filter(...$a){} function add_shortcode(...$a){} function register_activation_hook(...$a){}
require $argv[1];
$f = 0; function ok($c, $m) { global $f; echo ($c ? 'OK   ' : 'FEHLT ') . $m . "\n"; if (!$c) $f++; }
$w = AB1_Weiterlesen::textwoerter('<p>Nistkasten Nistkasten Wildbiene und die Hecke. Nistkasten bauen für Wildbiene</p><script>x</script>');
ok($w[0] === 'nistkasten' && in_array('wildbiene', $w) && !in_array('und', $w), 'Textwörter nach Häufigkeit: ' . implode(',', $w));
$A = ['id'=>1,'titel'=>'A','kat'=>[1],'tag'=>[],'sprache'=>'de','noindex'=>false,'fokus'=>'','wort'=>['nistkasten','wildbiene','hecke']];
$B = $A; $B['id']=2; $B['wort']=['nistkasten','wildbiene','hecke'];
$C = $A; $C['id']=3; $C['wort']=['zecke','impfung','fell'];
ok(AB1_Weiterlesen::bewerte($A,$B) > AB1_Weiterlesen::bewerte($A,$C), 'Textähnlichkeit hebt passenden Artikel');
ok(AB1_Weiterlesen::bewerte($A,$C,4) > AB1_Weiterlesen::bewerte($A,$C,0), 'Bonus (Biodiversität) wirkt');
ok(AB1_Weiterlesen::kartenzahlen('6, 3 ,4,4,99,5,7') === [3,4,5,6], 'Kartenzahlen: sortiert, eindeutig, höchstens vier');
ok(AB1_Weiterlesen::kartenzahlen('') === [4], 'Kartenzahlen: Standard');
$q1 = AB1_Weiterlesen::qualitaet_wert(80,150,0.02); $q2 = AB1_Weiterlesen::qualitaet_wert(10,20,0);
ok($q1 > 0.7 && $q2 < 0.15 && $q1 <= 1, "Qualitätswert $q1 / $q2");
// Normalisierung: Ziel X ist überall beliebt (10 %), Ziel Y 1 %. Variante a zeigt nur X, b zeigt X und Y.
$s = ['a'=>[10=>1000], 'b'=>[10=>500, 20=>500]]; $c = ['a'=>[10=>100], 'b'=>[10=>50, 20=>5]];
$n = AB1_Weiterlesen::normalisiere($s, $c);
ok(abs($n['a']['lift'] - 1.0) < 0.01 && abs($n['b']['lift'] - 1.0) < 0.01, 'Gleiche Beliebtheit je Ziel: beide Varianten Lift 1,0 (' . round($n['a']['lift'],2) . '/' . round($n['b']['lift'],2) . ')');
$raw_a = 100/1000; $raw_b = 55/1000; ok($raw_a > $raw_b, 'Rohklickrate würde a fälschlich vorne sehen');
$c2 = ['a'=>[10=>100], 'b'=>[10=>60, 20=>12]]; $n2 = AB1_Weiterlesen::normalisiere($s, $c2);
ok($n2['b']['lift'] > $n2['a']['lift'], 'Echt bessere Variante b liegt bereinigt vorne');
ok(AB1_Weiterlesen::thema_aus_zaehlung(10,1)==='maulwurf' && AB1_Weiterlesen::thema_aus_zaehlung(1,10)==='wuehlmaus' && AB1_Weiterlesen::thema_aus_zaehlung(5,4)==='beide' && AB1_Weiterlesen::thema_aus_zaehlung(1,1)==='', 'Themenerkennung Maulwurf/Wühlmaus/beide/unklar');
ok(AB1_Weiterlesen::thema_passt('maulwurf','maulwurf') && !AB1_Weiterlesen::thema_passt('maulwurf','wuehlmaus') && AB1_Weiterlesen::thema_passt('beide','wuehlmaus') && AB1_Weiterlesen::thema_passt('','wuehlmaus') && AB1_Weiterlesen::thema_passt('wuehlmaus','alle'), 'Push-Platz mit Thema: passt nur zum richtigen Artikel');
$M = $A; $M['thema']='maulwurf'; $W = $A; $W['id']=9; $W['thema']='wuehlmaus'; $G = $A; $G['id']=8; $G['thema']='';
ok(!AB1_Weiterlesen::darf_verlinken($M,$W) && !AB1_Weiterlesen::darf_verlinken($W,$M), 'Maulwurf-Artikel verlinkt nie Wühlmaus-Artikel (und umgekehrt)');
ok(AB1_Weiterlesen::darf_verlinken($M,$G) && AB1_Weiterlesen::darf_verlinken($G,$W), 'allgemeine Artikel bleiben mit beiden verlinkbar');
echo $f ? "\n$f Fehler\n" : "\nAlles bestanden\n"; exit($f ? 1 : 0);
