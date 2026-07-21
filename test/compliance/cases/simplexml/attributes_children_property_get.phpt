--TEST--
SimpleXMLElement attributes()/children() property __get/isset/empty (#21667, ext/simplexml/sxe.c)
--FILE--
<?php
$sx = simplexml_load_string('<r a="1" b="2"/>');
$a = $sx->attributes();
echo 'prop_b=', (string) $a->b;
echo ' aa_b=', (string) $a['b'];
echo ' isset_a=', isset($a->a) ? '1' : '0';
echo ' empty_a=', empty($a->a) ? '1' : '0';
echo ' miss=', var_export($a->missing, true), "\n";

$sx2 = simplexml_load_string('<r><c>x</c><d>y</d></r>');
$ch = $sx2->children();
echo 'ch_d=', (string) $ch->d;
echo ' isset_d=', isset($ch->d) ? '1' : '0';
echo ' empty_d=', empty($ch->d) ? '1' : '0', "\n";

$sx3 = simplexml_load_string('<r a="" b="0" c="x"/>');
$a3 = $sx3->attributes();
echo 'empty_blank=', empty($a3->a) ? '1' : '0';
echo ' empty_zero=', empty($a3->b) ? '1' : '0';
echo ' empty_x=', empty($a3->c) ? '1' : '0', "\n";
?>
--EXPECT--
prop_b=2 aa_b=2 isset_a=1 empty_a=0 miss=NULL
ch_d=y isset_d=1 empty_d=0
empty_blank=1 empty_zero=1 empty_x=0
