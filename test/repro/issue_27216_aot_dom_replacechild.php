<?php
/** Repro #27216 — AOT DOMNode::replaceChild after createElement append chain. */
$d = new DOMDocument();
$r = $d->appendChild($d->createElement('root'));
$a = $r->appendChild($d->createElement('a'));
$b = $d->createElement('b');
$r->replaceChild($b, $a);
echo $r->childNodes->length, ':', $r->firstChild->nodeName, "\n";
