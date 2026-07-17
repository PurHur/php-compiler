--TEST--
SimpleXMLElement isset/empty on child elements (#19707, ext/simplexml/sxe.c)
--FILE--
<?php
$s = simplexml_load_string('<r a="1"><b>2</b><e></e><z>0</z></r>');
echo 'isset_b=', isset($s->b) ? '1' : '0';
echo ' empty_b=', empty($s->b) ? '1' : '0';
echo ' b=', (string) $s->b, "\n";
echo 'isset_missing=', isset($s->missing) ? '1' : '0';
echo ' empty_missing=', empty($s->missing) ? '1' : '0', "\n";
echo 'isset_attr=', isset($s['a']) ? '1' : '0', "\n";
echo 'isset_e=', isset($s->e) ? '1' : '0';
echo ' empty_e=', empty($s->e) ? '1' : '0', "\n";
echo 'isset_z=', isset($s->z) ? '1' : '0';
echo ' empty_z=', empty($s->z) ? '1' : '0', "\n";
$n = simplexml_load_string('<r><c><d>x</d></c></r>');
echo 'isset_nested=', isset($n->c->d) ? '1' : '0';
echo ' empty_nested=', empty($n->c->d) ? '1' : '0', "\n";
?>
--EXPECT--
isset_b=1 empty_b=0 b=2
isset_missing=0 empty_missing=1
isset_attr=1
isset_e=1 empty_e=1
isset_z=1 empty_z=1
isset_nested=1 empty_nested=0
