--TEST--
AOT: empty attributes NamedNodeMap length + setAttribute pin (#33128)
--FILE--
<?php
$d = new DOMDocument();
$el = $d->createElement('r');
echo $el->attributes->length, "\n";
$el->setAttribute('id', 'x');
echo $el->attributes->length, ' ', $el->attributes->item(0)->name, "\n";
$d2 = new DOMDocument();
$d2->loadXML('<r xmlns:x="urn:x" x:a="1"/>');
echo $d2->documentElement->getAttributeNS('urn:x', 'a'), "\n";
--EXPECT--
0
1 id
1
