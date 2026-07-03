--TEST--
Stdlib: DOMDocument::importNode() cross-document import (#14337, ext/dom/php_dom.c)
--FILE--
<?php
$doc1 = new DOMDocument();
$doc1->loadXML('<x>text</x>');
$doc2 = new DOMDocument();
$imported = $doc2->importNode($doc1->documentElement, true);
echo $imported->nodeName, ':', $imported->textContent, "\n";
$doc2->appendChild($imported);
echo trim($doc2->saveXML()), "\n";
?>
--EXPECT--
x:text
<?xml version="1.0"?>
<x>text</x>
