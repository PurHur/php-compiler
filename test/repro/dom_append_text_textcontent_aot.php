<?php
/** #35300 — AOT Element textContent/nodeValue after appendChild(createTextNode). */
$d = new DOMDocument();
$el = $d->createElement('a');
$d->appendChild($el);
$el->appendChild($d->createTextNode('hi'));
echo 'tc=', $el->textContent, "\n";
echo 'nv=', (string) $el->nodeValue, "\n";
echo 'xml=', $d->saveXML($el), "\n";

// Nested element text must aggregate.
$child = $d->createElement('b');
$el->appendChild($child);
$child->appendChild($d->createTextNode('yo'));
echo 'agg=', $el->textContent, "\n";
