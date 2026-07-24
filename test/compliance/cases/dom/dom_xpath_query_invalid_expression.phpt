--TEST--
DOMXPath::query invalid expression → warning + false (#22721, ext/dom/xpath.c)
--FILE--
<?php
error_reporting(E_ALL);
$d = new DOMDocument();
$d->loadXML('<r><a/></r>');
$x = new DOMXPath($d);
$bad = $x->query('!!!');
var_export($bad);
echo "\n", gettype($bad), "\n";
$ok = $x->query('/r/a');
echo is_object($ok) && 1 === $ok->length ? "match\n" : "no-match\n";
?>
--EXPECTF--
PHP Warning:  DOMXPath::query(): Invalid expression in %s on line %d
false
boolean
match
