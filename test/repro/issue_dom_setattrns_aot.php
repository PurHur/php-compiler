<?php
declare(strict_types=1);

/**
 * AOT DOMElement::setAttributeNS must store like xmlSetNsProp (#32398).
 * php-src ext/dom/element.c PHP_METHOD(DOMElement, setAttributeNS).
 */
$doc = new DOMDocument();
$el = $doc->createElement('x');
$el->setAttributeNS('http://example.com/ns', 'n:a', '1');
echo $el->getAttributeNS('http://example.com/ns', 'a'), "\n";
echo (int) $el->hasAttributeNS('http://example.com/ns', 'a'), "\n";
