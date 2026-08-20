<?php

/**
 * AOT: DOMElement::$childNodes->length after loadXML / createElement (ext/dom/node.c).
 *
 * Writing DOMNode::childNodes slot indices into a DOMElement allocation left the
 * list unset / OOB; ->length then SIGSEGVd in __object__load_value_slot (#32765).
 */

$d = new DOMDocument();
$d->loadXML('<r/>');
echo 'empty:', $d->documentElement->childNodes->length, "\n";

$d2 = new DOMDocument();
$d2->loadXML('<r><a/><b/></r>');
echo 'loaded:', $d2->documentElement->childNodes->length, "\n";

$d3 = new DOMDocument();
$r = $d3->createElement('r');
$d3->appendChild($r);
$r->appendChild($d3->createElement('a'));
$r->appendChild($d3->createElement('b'));
echo 'create:', $r->childNodes->length, '|', $r->firstChild->nextSibling->nodeName, "\n";
