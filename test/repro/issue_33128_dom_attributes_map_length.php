<?php

declare(strict_types=1);

/**
 * AOT: empty DOMElement must expose live attributes NamedNodeMap (#33128).
 * php-src ext/dom/namednodemap.c — length 0 then setAttribute pins.
 */
$d = new DOMDocument();
$el = $d->createElement('r');
echo $el->attributes->length, "\n";
$el->setAttribute('id', 'x');
echo $el->attributes->length, ' ', $el->attributes->item(0)->name, "\n";

$d2 = new DOMDocument();
$d2->loadXML('<r/>');
$el2 = $d2->documentElement;
$el2->setAttributeNS('urn:x', 'x:a', '1');
echo $el2->attributes->length, ' ', $el2->getAttributeNS('urn:x', 'a'), "\n";

$d3 = new DOMDocument();
$d3->loadXML('<r xmlns:x="urn:x" x:a="1"/>');
echo var_export($d3->documentElement->getAttributeNS('urn:x', 'a'), true), "\n";
