<?php

/**
 * #27476 — AOT DOMNode::appendChild() same-parent move: live childNodes length/order.
 * Expect: 2 / b / a
 */
$d = new DOMDocument();
$r = $d->appendChild($d->createElement('r'));
$a = $d->createElement('a');
$b = $d->createElement('b');
$r->appendChild($a);
$r->appendChild($b);
$r->appendChild($a); // move
echo $r->childNodes->length, "\n";
echo $r->firstChild->nodeName, "\n";
echo $r->lastChild->nodeName, "\n";
