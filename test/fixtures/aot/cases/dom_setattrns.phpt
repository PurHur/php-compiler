--TEST--
AOT DOMElement::setAttributeNS createElement (#32398, ext/dom/element.c xmlSetNsProp)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$el = $doc->createElement('x');
$el->setAttributeNS('http://example.com/ns', 'n:a', '1');
echo $el->getAttributeNS('http://example.com/ns', 'a'), "\n";
echo (int) $el->hasAttributeNS('http://example.com/ns', 'a'), "\n";
--EXPECT--
1
1
