--TEST--
dom DOMElement::removeAttributeNS() namespace attribute removal (#15291)
--FILE--
<?php
$dom = new DOMDocument();
$dom->loadXML('<root xmlns:ex="http://example.com"><ex:child ex:foo="bar"/></root>');
$el = $dom->documentElement->firstChild;
echo (int) $el->hasAttributeNS('http://example.com', 'foo'), "\n";
echo (int) $el->removeAttributeNS('http://example.com', 'foo'), "\n";
echo (int) $el->hasAttributeNS('http://example.com', 'foo'), "\n";
echo (int) $el->removeAttributeNS('http://example.com', 'missing'), "\n";
--EXPECT--
1
1
0
0
