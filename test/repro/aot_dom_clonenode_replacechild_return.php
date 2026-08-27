<?php
/** #35386 — AOT cloneNode on replaceChild return (oldChild; no loadXML). */
$d = new DOMDocument();
$r = $d->appendChild($d->createElement('root'));
$old = $r->appendChild($d->createElement('old'));
$old->setAttribute('k', 'v');
$old->appendChild($d->createElement('c'));
$e = $r->replaceChild($d->createElement('e'), $old);
$c = $e->cloneNode(true);
echo $c->tagName, '|', $c->getAttribute('k'), '|', $c->childNodes->length;
echo '|', $c->isSameNode($e) ? 'same' : 'diff';
echo "\n";
