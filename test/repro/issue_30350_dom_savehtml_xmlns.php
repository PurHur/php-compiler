<?php
/** Repro #30350 — saveHTML must emit xmlns / xmlns:* like Zend htmlNodeDump. */
$doc = new DOMDocument();
$doc->loadXML('<root xmlns="urn:d" xmlns:p="urn:x"><a p:y="1">t</a></root>');
echo 'load=' . trim($doc->saveHTML()) . "\n";
echo 'root=' . trim($doc->saveHTML($doc->documentElement)) . "\n";

$doc2 = new DOMDocument();
$el = $doc2->createElementNS('urn:d', 'root');
$doc2->appendChild($el);
$a = $doc2->createElementNS('urn:x', 'p:a');
$a->setAttributeNS('urn:x', 'p:y', '1');
$el->appendChild($a);
echo 'createNS=' . trim($doc2->saveHTML()) . "\n";
echo 'xml_ok=' . (false !== strpos($doc2->saveXML(), 'xmlns="urn:d"') ? 'yes' : 'no') . "\n";
