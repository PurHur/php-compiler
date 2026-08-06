--TEST--
SimpleXMLElement::xpath() count under is_array ternary (#27413)
--FILE--
<?php
$xml = simplexml_load_string('<r><a id="1">x</a><a id="2">y</a></r>');
$n = $xml->xpath('//a[@id="2"]');
echo is_array($n) ? count($n) : 'fail', '|', (string) $n[0], "\n";
?>
--EXPECT--
1|y
