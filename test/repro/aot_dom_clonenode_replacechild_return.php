<?php
/** #35386 — AOT cloneNode on replaceChild() DOMNode return (returns oldChild; no loadXML). */
$d = new DOMDocument();
$r = $d->appendChild($d->createElement('root'));
$old = $r->appendChild($d->createElement('old'));
$old->setAttribute('pre', '1');
$e = $r->replaceChild($d->createElement('e'), $old);
echo 'ret=', $e->tagName, '|pre=', $e->getAttribute('pre'), "\n";
$e->setAttribute('k', 'v');
$e->appendChild($d->createElement('c'));
$c = $e->cloneNode(true);
echo $c->tagName, '|', $c->getAttribute('k'), '|', $c->getAttribute('pre'), '|', $c->childNodes->length;
echo '|', $c->isSameNode($e) ? 'same' : 'diff';
echo "\n";
echo 'rootKids=', $r->childNodes->length, '|first=', $r->firstChild->tagName, "\n";
