--TEST--
ext/dom DOMDocument::saveHTML() emits xmlns / xmlns:* nsDef like Zend htmlNodeDump (#30350)
--FILE--
<?php
declare(strict_types=1);

$doc = new DOMDocument();
$doc->loadXML('<root xmlns="urn:d" xmlns:p="urn:x"><a p:y="1">t</a></root>');
echo trim($doc->saveHTML()), "\n";
echo trim($doc->saveHTML($doc->documentElement)), "\n";

$doc2 = new DOMDocument();
$el = $doc2->createElementNS('urn:d', 'root');
$doc2->appendChild($el);
$a = $doc2->createElementNS('urn:x', 'p:a');
$a->setAttributeNS('urn:x', 'p:y', '1');
$el->appendChild($a);
echo trim($doc2->saveHTML()), "\n";
--EXPECT--
<root xmlns="urn:d" xmlns:p="urn:x"><a p:y="1">t</a></root>
<root xmlns="urn:d" xmlns:p="urn:x"><a p:y="1">t</a></root>
<root xmlns="urn:d"><p:a xmlns:p="urn:x" p:y="1"></p:a></root>
