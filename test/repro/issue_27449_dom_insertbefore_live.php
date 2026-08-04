<?php
/** Repro #27449 — AOT DOMNode::insertBefore live childNodes / firstChild / parentNode. */
$d = new DOMDocument();
$r = $d->appendChild($d->createElement('r'));
$a = $d->createElement('a');
$b = $d->createElement('b');
$r->appendChild($a);
$r->insertBefore($b, $a);
echo $r->childNodes->length, "\n";
echo $r->firstChild->nodeName, "\n";
echo $b->parentNode !== null ? "parent\n" : "orphan\n";
