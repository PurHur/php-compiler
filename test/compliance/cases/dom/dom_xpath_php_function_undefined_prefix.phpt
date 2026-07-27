--TEST--
DOMXPath php:function without php NS — warning + bool false (#23534, ext/dom/xpath.c)
--FILE--
<?php
error_reporting(E_ALL);
$dom = new DOMDocument();
$dom->loadXML('<r><a>hello</a></r>');
$xp = new DOMXPath($dom);
$xp->registerPhpFunctions();
$n = $xp->evaluate('string(php:function("strtoupper", string(/r/a)))');
var_export($n);
echo "\n", gettype($n), "\n";
$xp->registerNamespace('php', 'http://php.net/xpath');
$ok = $xp->evaluate('string(php:function("strtoupper", string(/r/a)))');
var_export($ok);
echo "\n", gettype($ok), "\n";
?>
--EXPECTF--
PHP Warning:  DOMXPath::evaluate(): xmlXPathCompOpEval: function function bound to undefined prefix php in %s on line %d
false
boolean
'HELLO'
string
