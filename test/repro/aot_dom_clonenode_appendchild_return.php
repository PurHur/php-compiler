<?php
/** AOT: cloneNode after appendChild(createElement) return (no loadXML) (#35373). */
$d = new DOMDocument();
$e = $d->appendChild($d->createElement('e'));
$e->setAttribute('k', 'v');
$e->appendChild($d->createElement('c'));
$c = $e->cloneNode(true);
echo $c->tagName, '|', $c->getAttribute('k'), '|', $c->childNodes->length;
echo "\n";
// Baseline: assign createElement then mutate (OK since #35361).
$d2 = new DOMDocument();
$e2 = $d2->createElement('e');
$e2->appendChild($d2->createElement('c'));
$c2 = $e2->cloneNode(true);
echo $c2->tagName, '|', $c2->childNodes->length;
echo "\n";
