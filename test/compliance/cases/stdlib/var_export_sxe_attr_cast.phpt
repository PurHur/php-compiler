--TEST--
stdlib var_export() — (string) SimpleXMLElement attribute one-arg + attr __set_state (#25339)
--FILE--
<?php
$xml = simplexml_load_string('<root a="1"/>');
var_export((string) $xml['a']);
echo "\n";
echo var_export((string) $xml['a'], true), "\n";
var_export($xml['a']);
echo "\n";
--EXPECT--
'1'
'1'
\SimpleXMLElement::__set_state(array(
   0 => '1',
))
