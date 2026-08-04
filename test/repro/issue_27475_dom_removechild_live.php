<?php
/** Repro #27475 — AOT DOMNode::removeChild live childNodes length / parentNode. */
$d = new DOMDocument();
$r = $d->appendChild($d->createElement('r'));
$a = $d->createElement('a');
$r->appendChild($a);
$r->removeChild($a);
echo $r->childNodes->length, "\n";
echo $a->parentNode !== null ? "parent\n" : "orphan\n";
