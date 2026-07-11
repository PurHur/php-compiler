--TEST--
dom DOMElement::removeAttributeNS() namespace attribute removal (#15291, #15358)
--FILE--
<?php
$dom = new DOMDocument();
$dom->loadXML('<root xmlns:ex="http://example.com"><ex:child ex:foo="bar"/></root>');
$el = $dom->documentElement->firstChild;
echo (int) $el->hasAttributeNS('http://example.com', 'foo'), "\n";
var_export($el->removeAttributeNS('http://example.com', 'foo'));
echo "\n";
echo (int) $el->hasAttributeNS('http://example.com', 'foo'), "\n";
var_export($el->removeAttributeNS('http://example.com', 'missing'));
echo "\n";
?>
--EXPECT--
1
NULL
0
NULL
