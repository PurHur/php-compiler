<?php
declare(strict_types=1);

// #19211 — DTD ID type + xml:id must index without validateOnParse (php-src ext/dom/node.c)

$doc = new DOMDocument();
$doc->loadXML('<!DOCTYPE root [<!ATTLIST child id ID #IMPLIED>]><root><child id="target">x</child></root>');
$found = $doc->getElementById('target');
echo null !== $found ? "dtd:ok\n" : "dtd:null\n";

$doc2 = new DOMDocument();
$doc2->loadXML('<root xmlns:xml="http://www.w3.org/XML/1998/namespace"><child xml:id="xmlid">x</child></root>');
$found2 = $doc2->getElementById('xmlid');
echo null !== $found2 ? "xmlid:ok\n" : "xmlid:null\n";
