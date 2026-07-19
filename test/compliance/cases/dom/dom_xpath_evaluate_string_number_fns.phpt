--TEST--
DOMXPath::evaluate() XPath 1.0 string/number core functions (#20818, ext/dom/xpath.c)
--SKIPIF--
<?php
if (!class_exists('DOMXPath', false)) {
    print "skip: DOMXPath not available\n";
}
?>
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<r><a>hello</a><b> world</b></r>');
$xpath = new DOMXPath($doc);
var_export($xpath->evaluate('concat("x","y")'));
echo "\n";
var_export($xpath->evaluate('concat("a","b","c")'));
echo "\n";
var_export($xpath->evaluate('concat(string(//a), string(//b))'));
echo "\n";
var_export($xpath->evaluate('starts-with("hello","he")'));
echo "\n";
var_export($xpath->evaluate('contains("hello","ell")'));
echo "\n";
var_export($xpath->evaluate('substring("hello",2,3)'));
echo "\n";
var_export($xpath->evaluate('substring-before("a,b",",")'));
echo "\n";
var_export($xpath->evaluate('substring-after("a,b",",")'));
echo "\n";
var_export($xpath->evaluate('string-length("hello")'));
echo "\n";
var_export($xpath->evaluate('normalize-space("  a  b  ")'));
echo "\n";
var_export($xpath->evaluate('translate("abc","ac","AC")'));
echo "\n";
var_export($xpath->evaluate('floor(3.7)'));
echo "\n";
var_export($xpath->evaluate('ceiling(3.2)'));
echo "\n";
var_export($xpath->evaluate('round(3.5)'));
echo "\n";
?>
--EXPECT--
'xy'
'abc'
'hello world'
true
true
'ell'
'a'
'b'
5.0
'a b'
'AbC'
3.0
4.0
4.0
