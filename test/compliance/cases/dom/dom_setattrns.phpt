--TEST--
stdlib DOMElement::setAttributeNS matches xmlSetNsProp (#32398, ext/dom/element.c)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$el = $doc->createElement('x');
$el->setAttributeNS('http://example.com/ns', 'n:a', '1');
echo $el->getAttributeNS('http://example.com/ns', 'a'), "\n";
echo (int) $el->hasAttributeNS('http://example.com/ns', 'a'), "\n";
$el->removeAttributeNS('http://example.com/ns', 'a');
echo (int) $el->hasAttributeNS('http://example.com/ns', 'a'), "\n";
--EXPECT--
1
1
0
