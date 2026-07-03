--TEST--
DOM DOMElement::setIdAttributeNS() enables getElementById for namespaced attrs (#15300, ext/dom/element.c)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<root xmlns:ex="http://example.com/ns"><item ex:id="target"/></root>');
$item = $doc->getElementsByTagName('item')->item(0);
echo null === $doc->getElementById('target') ? "before_null\n" : "before_found\n";
$item->setIdAttributeNS('http://example.com/ns', 'id', true);
$found = $doc->getElementById('target');
echo null !== $found ? "after_ok\n" : "after_null\n";
--EXPECT--
before_null
after_ok
