--TEST--
DOMXPath::evaluate invalid expression → warning + false (#22755, ext/dom/xpath.c)
--FILE--
<?php
error_reporting(E_ALL);
$d = new DOMDocument();
$d->loadXML('<r><a>1</a></r>');
$x = new DOMXPath($d);
$bad = $x->evaluate('@@@');
var_export($bad);
echo "\n", gettype($bad), "\n";
$n = $x->evaluate('count(/r/a)');
echo (1.0 === $n) ? "count-ok\n" : "count-bad\n";
?>
--EXPECTF--
PHP Warning:  DOMXPath::evaluate(): Invalid expression in %s on line %d
false
boolean
count-ok
