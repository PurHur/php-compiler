--TEST--
SimpleXMLElement::xpath() count under is_array ternary matches Zend (#27413)
--FILE--
<?php
$xml = simplexml_load_string('<r><a id="1">x</a><a id="2">y</a></r>');
$n = $xml->xpath('//a[@id="2"]');
echo is_array($n) ? count($n) : 'fail', '|', (string) $n[0], "\n";
$n2 = $xml->xpath('/r/a');
echo 'abs=', count($n2), '|', (string) $n2[0], '|', (string) $n2[1], "\n";
?>
--EXPECT--
1|y
abs=2|x|y
