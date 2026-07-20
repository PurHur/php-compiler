--TEST--
DOMXPath::evaluate() namespace-uri() returns element namespace (#21238, ext/dom/xpath.c)
--SKIPIF--
<?php
if (!class_exists('DOMXPath', false)) {
    print "skip: DOMXPath not available\n";
}
?>
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r xmlns:x="urn:x" xmlns="urn:def"><x:a/><c/></r>');
$xp = new DOMXPath($d);
$xp->registerNamespace('x', 'urn:x');
$xp->registerNamespace('d', 'urn:def');
var_export($xp->evaluate('namespace-uri(//x:a)'));
echo "\n";
var_export($xp->evaluate('namespace-uri(/*)'));
echo "\n";
var_export($xp->evaluate('namespace-uri(//d:c)'));
echo "\n";
var_export($xp->evaluate('local-name(//x:a)'));
echo "\n";
var_export($xp->evaluate('name(//x:a)'));
echo "\n";
?>
--EXPECT--
'urn:x'
'urn:def'
'urn:def'
'a'
'x:a'
