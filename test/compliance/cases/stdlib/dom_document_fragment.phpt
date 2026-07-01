--TEST--
stdlib DOMDocumentFragment createDocumentFragment/appendChild (#6317, ext/dom/php_dom.c)
--FILE--
<?php
$doc = new DOMDocument();
$root = $doc->createElement('root');
$doc->appendChild($root);
$frag = $doc->createDocumentFragment();
echo get_class($frag), "\n";
$child = $doc->createElement('item');
$frag->appendChild($child);
$root->appendChild($frag);
echo $frag->childNodes->length, "\n";
echo $root->childNodes->length, "\n";
echo $root->firstChild->nodeName, "\n";
echo trim($doc->saveXML()), "\n";
?>
--EXPECT--
DOMDocumentFragment
0
1
item
<?xml version="1.0"?>
<root><item/></root>
