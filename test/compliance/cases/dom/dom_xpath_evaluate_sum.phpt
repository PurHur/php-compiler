--TEST--
DOMXPath::evaluate() sum() over node-set (#19682, ext/dom/xpath.c)
--SKIPIF--
<?php
if (!class_exists('DOMXPath', false)) {
    print "skip: DOMXPath not available\n";
}
?>
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<r><a>3</a><a>4</a></r>');
$xpath = new DOMXPath($doc);
var_export($xpath->evaluate('sum(//a)'));
echo "\n";
var_export($xpath->evaluate('sum(//missing)'));
echo "\n";
var_export($xpath->evaluate('count(//a)'));
echo "\n";
$nanDoc = new DOMDocument();
$nanDoc->loadXML('<r><a>3</a><a>x</a></r>');
$nanXpath = new DOMXPath($nanDoc);
$nan = $nanXpath->evaluate('sum(//a)');
echo (is_float($nan) && is_nan($nan)) ? "nan\n" : "not-nan\n";
?>
--EXPECT--
7.0
0.0
2.0
nan
