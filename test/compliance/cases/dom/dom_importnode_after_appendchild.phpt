--TEST--
DOM: importNode(documentElement, true) after appendChild(createElement) (#24571, re-#18860)
--FILE--
<?php
$src = new DOMDocument();
$src->loadXML('<r><a><b>t</b></a></r>');
$dst = new DOMDocument('1.0');
$dst->appendChild($dst->createElement('root'));
$n = $dst->importNode($src->documentElement, true);
echo $n->nodeName, '/', $n->childNodes->length, "\n";
$dst->documentElement->appendChild($n);
echo trim($dst->saveXML()), "\n";
$n2 = $dst->importNode($src->documentElement->firstChild, true);
echo $n2->nodeName, '/', $n2->childNodes->length, "\n";
?>
--EXPECT--
r/1
<?xml version="1.0"?>
<root><r><a><b>t</b></a></r></root>
a/1
