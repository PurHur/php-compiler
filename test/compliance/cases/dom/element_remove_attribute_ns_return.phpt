--TEST--
dom DOMElement::removeAttributeNS() return value parity (#15358, ext/dom/php_dom.c)
--FILE--
<?php
$dom = new DOMDocument();
$dom->loadXML('<root xmlns:ex="http://example.com"><ex:child ex:foo="bar"/></root>');
$el = $dom->documentElement->firstChild;
var_export($el->removeAttributeNS('http://example.com', 'foo'));
echo "\n";
echo (int) $el->hasAttributeNS('http://example.com', 'foo'), "\n";
var_export($el->removeAttributeNS('http://example.com', 'missing'));
echo "\n";
?>
--EXPECT--
NULL
0
NULL
