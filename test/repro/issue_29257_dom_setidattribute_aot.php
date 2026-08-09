<?php
// #29257 — AOT DOMElement::setIdAttribute on :object child temps + typed createElement
$d = new DOMDocument();
$d->loadXML('<r><a id="x"/><b id="x"/></r>');
$a = $d->documentElement->firstChild;
$b = $a->nextSibling;
$a->setIdAttribute('id', true);
$b->setIdAttribute('id', true);
echo $d->getElementById('x')->nodeName, "\n";

$d2 = new DOMDocument();
$el = $d2->createElement('c');
$el->setAttribute('id', 'y');
$d2->appendChild($el);
$el->setIdAttribute('id', true);
echo $d2->getElementById('y')->nodeName, "\n";
