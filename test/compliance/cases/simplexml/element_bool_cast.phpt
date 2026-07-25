--TEST--
SimpleXMLElement (bool) cast / empty($sxe) — present node true; missing false (#22714, sxe_object_cast_ex)
--FILE--
<?php
$xml = simplexml_load_string('<r><a/><b>0</b><d>x</d></r>');
echo 'bool_a=', (bool)($xml->a) ? '1' : '0', "\n";
echo 'bool_b=', (bool)($xml->b) ? '1' : '0', "\n";
echo 'bool_d=', (bool)($xml->d) ? '1' : '0', "\n";
echo 'bool_z=', (bool)($xml->z) ? '1' : '0', "\n";
$z = $xml->z;
echo 'empty_z=', empty($z) ? '1' : '0', "\n";
$a = $xml->a;
echo 'empty_a=', empty($a) ? '1' : '0', "\n";
$root = simplexml_load_string('<empty/>');
echo 'bool_root=', (bool)$root ? '1' : '0', ' empty_root=', empty($root) ? '1' : '0', "\n";
$rooted = simplexml_load_string('<empty attr="1"/>');
echo 'bool_root_attr=', (bool)$rooted ? '1' : '0', ' empty_root_attr=', empty($rooted) ? '1' : '0', "\n";
$text0 = simplexml_load_string('<r>0</r>');
echo 'bool_text0=', (bool)$text0 ? '1' : '0', "\n";
?>
--EXPECT--
bool_a=1
bool_b=1
bool_d=1
bool_z=0
empty_z=1
empty_a=0
bool_root=0 empty_root=1
bool_root_attr=1 empty_root_attr=0
bool_text0=1
