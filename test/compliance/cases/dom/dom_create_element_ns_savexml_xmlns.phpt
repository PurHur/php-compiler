--TEST--
DOMDocument::createElementNS() saveXML emits xmlns (#19397, ext/dom/node.c)
--FILE--
<?php
$d = new DOMDocument('1.0', 'UTF-8');
$el = $d->createElementNS('http://example.com/ns', 'ex:root');
$d->appendChild($el);
$child = $d->createElementNS('http://example.com/other', 'ot:child');
$el->appendChild($child);
echo $d->saveXML();
echo 'attrs=', $el->attributes->length, "\n";
echo 'lookup=', $el->lookupNamespaceURI('ex'), "\n";
$same = $d->createElementNS('http://example.com/ns', 'ex:same');
$el->appendChild($same);
echo $d->saveXML();
$def = new DOMDocument('1.0', 'UTF-8');
$r = $def->createElementNS('http://example.com/ns', 'root');
$def->appendChild($r);
$c = $def->createElementNS('http://example.com/ns', 'child');
$r->appendChild($c);
echo $def->saveXML();
?>
--EXPECT--
<?xml version="1.0" encoding="UTF-8"?>
<ex:root xmlns:ex="http://example.com/ns"><ot:child xmlns:ot="http://example.com/other"/></ex:root>
attrs=0
lookup=http://example.com/ns
<?xml version="1.0" encoding="UTF-8"?>
<ex:root xmlns:ex="http://example.com/ns"><ot:child xmlns:ot="http://example.com/other"/><ex:same/></ex:root>
<?xml version="1.0" encoding="UTF-8"?>
<root xmlns="http://example.com/ns"><child/></root>
