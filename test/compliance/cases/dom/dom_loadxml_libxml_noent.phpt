--TEST--
dom DOMDocument::loadXML(LIBXML_NOENT) substitutes general entities as DOMText (#19796, ext/dom/document.c)
--FILE--
<?php
$xml = '<!DOCTYPE r [<!ENTITY e "hi">]><r>&e;</r>';

$d = new DOMDocument();
$d->loadXML($xml, LIBXML_NOENT);
$c = $d->documentElement->firstChild;
echo get_class($c), '|', $c->nodeName, '|', var_export($c->nodeValue, true), "\n";

$d2 = new DOMDocument();
$d2->loadXML($xml);
$c2 = $d2->documentElement->firstChild;
echo get_class($c2), '|', $c2->nodeName, '|', var_export($c2->nodeValue, true), "\n";

$xml2 = '<!DOCTYPE r [<!ENTITY e "hi">]><r>x&e;y</r>';
$d3 = new DOMDocument();
$d3->loadXML($xml2, LIBXML_NOENT);
$c3 = $d3->documentElement->firstChild;
echo get_class($c3), '|', var_export($c3->nodeValue, true), '|', $d3->documentElement->childNodes->length, "\n";
--EXPECT--
DOMText|#text|'hi'
DOMEntityReference|e|NULL
DOMText|'xhiy'|1
