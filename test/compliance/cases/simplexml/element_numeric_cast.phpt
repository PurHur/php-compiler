--TEST--
SimpleXMLElement (int)/(float) cast from element text — no object→1 warning (#22715, sxe_object_cast_ex)
--FILE--
<?php
$xml = simplexml_load_string('<r><a/><e>12</e><f>3.5</f></r>');
var_export((int)($xml->e));
echo "\n";
var_export((int)($xml->a));
echo "\n";
var_export((float)($xml->e));
echo "\n";
var_export((float)($xml->f));
echo "\n";
var_export((string)($xml->e));
echo "\n";
?>
--EXPECT--
12
0
12.0
3.5
'12'
