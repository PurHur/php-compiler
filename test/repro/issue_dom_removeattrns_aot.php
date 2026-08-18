<?php
declare(strict_types=1);

/**
 * AOT DOMElement::removeAttributeNS must drop the namespaced attr (#32398).
 * php-src ext/dom/element.c PHP_METHOD(DOMElement, removeAttributeNS).
 */
$doc = new DOMDocument();
$el = $doc->createElement('x');
$el->setAttributeNS('http://example.com/ns', 'n:a', '1');
$el->removeAttributeNS('http://example.com/ns', 'a');
echo (int) $el->hasAttributeNS('http://example.com/ns', 'a'), "\n";
echo $el->getAttributeNS('http://example.com/ns', 'a'), "\n";
