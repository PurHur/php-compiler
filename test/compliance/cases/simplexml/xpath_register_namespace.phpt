--TEST--
SimpleXMLElement::registerXPathNamespace applied by xpath() (#20334, ext/simplexml/simplexml.c)
--FILE--
<?php
$x = simplexml_load_string('<r xmlns:a="http://a"><a:x/></r>');
echo 'doc //a:x count=', count($x->xpath('//a:x')), "\n";
$x->registerXPathNamespace('p', 'http://a');
$n = $x->xpath('//p:x');
echo 'reg //p:x count=', count($n), "\n";
$y = simplexml_load_string('<r xmlns="http://def"><x/></r>');
$y->registerXPathNamespace('d', 'http://def');
echo 'def //d:x count=', count($y->xpath('//d:x')), "\n";
echo 'def //x count=', count($y->xpath('//x')), "\n";
?>
--EXPECT--
doc //a:x count=1
reg //p:x count=1
def //d:x count=1
def //x count=0
