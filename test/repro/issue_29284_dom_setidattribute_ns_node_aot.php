<?php
// #29284 — AOT DOMElement::setIdAttributeNS / setIdAttributeNode (:object + typed)
$d = new DOMDocument();
$d->loadXML('<r><a xmlns:e="http://example.com" e:aid="x"/><b xmlns:e="http://example.com" e:aid="x"/></r>');
$a = $d->documentElement->firstChild;
$b = $a->nextSibling;
$a->setIdAttributeNS('http://example.com', 'aid', true);
$b->setIdAttributeNS('http://example.com', 'aid', true);
echo $d->getElementById('x')->nodeName, "\n";

$d2 = new DOMDocument();
$d2->loadXML('<r><c id="y"/></r>');
$el = $d2->documentElement->firstChild;
$el->setIdAttributeNode($el->getAttributeNode('id'), true);
echo $d2->getElementById('y')->nodeName, "\n";

$d3 = new DOMDocument();
$el3 = $d3->createElement('d');
$el3->setAttribute('id', 'z');
$d3->appendChild($el3);
$el3->setIdAttributeNode($el3->getAttributeNode('id'), true);
echo $d3->getElementById('z')->nodeName, "\n";
