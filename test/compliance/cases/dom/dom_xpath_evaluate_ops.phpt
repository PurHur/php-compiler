--TEST--
DOMXPath::evaluate() comparisons, arithmetic, not(), name() (#20280, ext/dom/xpath.c)
--SKIPIF--
<?php
if (!class_exists('DOMXPath', false)) {
    print "skip: DOMXPath not available\n";
}
?>
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<r><a/><a/><b id="x">hi</b></r>');
$xpath = new DOMXPath($doc);
var_export($xpath->evaluate('count(//a) > 1'));
echo "\n";
var_export($xpath->evaluate('count(//a) = 2'));
echo "\n";
var_export($xpath->evaluate('2 > 1'));
echo "\n";
var_export($xpath->evaluate('1+1'));
echo "\n";
var_export($xpath->evaluate('count(//a) + 1'));
echo "\n";
var_export($xpath->evaluate('not(//c)'));
echo "\n";
var_export($xpath->evaluate('name(//b)'));
echo "\n";
var_export($xpath->evaluate('count(//a) != 1'));
echo "\n";
var_export($xpath->evaluate('3*4'));
echo "\n";
?>
--EXPECT--
true
true
true
2.0
3.0
true
'b'
true
12.0
