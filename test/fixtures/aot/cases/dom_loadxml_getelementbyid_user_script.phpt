--TEST--
AOT: DOMDocument::loadXML()/getElementById() DTD ID and xml:id user-script (#19211)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<!DOCTYPE root [<!ATTLIST child id ID #IMPLIED>]><root><child id="target">x</child></root>');
$found = $doc->getElementById('target');
echo null !== $found ? 'dtd:ok' : 'dtd:null', "\n";

$doc2 = new DOMDocument();
$doc2->loadXML('<root xmlns:xml="http://www.w3.org/XML/1998/namespace"><child xml:id="xmlid">x</child></root>');
$found2 = $doc2->getElementById('xmlid');
echo null !== $found2 ? 'xmlid:ok' : 'xmlid:null', "\n";
--EXPECT--
dtd:ok
xmlid:ok
