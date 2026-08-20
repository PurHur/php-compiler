--TEST--
AOT: DOMElement childNodes->length after loadXML/createElement
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r/>');
echo 'empty:', $d->documentElement->childNodes->length, "\n";

$d2 = new DOMDocument();
$d2->loadXML('<r><a/><b/></r>');
echo 'loaded:', $d2->documentElement->childNodes->length, "\n";

$d3 = new DOMDocument();
$r = $d3->createElement('r');
$d3->appendChild($r);
$r->appendChild($d3->createElement('a'));
$r->appendChild($d3->createElement('b'));
echo 'create:', $r->childNodes->length, '|', $r->firstChild->nextSibling->nodeName, "\n";
--EXPECT--
empty:0
loaded:2
create:2|b
