--TEST--
SimpleXMLElement::xpath invalid expression → warning + false (#22720, ext/simplexml/sxe.c)
--FILE--
<?php
error_reporting(E_ALL);
$x = simplexml_load_string('<r><a/></r>');
$bad = $x->xpath('!!!');
var_export($bad);
echo "\n", gettype($bad), "\n";
$empty = $x->xpath('/r/missing');
var_export($empty);
echo "\n", gettype($empty), "\n";
$ok = $x->xpath('/r/a');
echo is_array($ok) && 1 === count($ok) ? "match\n" : "no-match\n";
?>
--EXPECTF--
PHP Warning:  SimpleXMLElement::xpath(): Invalid expression in %s on line %d
false
boolean
array (
)
array
match
