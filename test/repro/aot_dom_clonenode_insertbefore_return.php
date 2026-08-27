<?php
/** #35377 — AOT cloneNode on insertBefore(createElement) DOMNode return (no loadXML). */
$d = new DOMDocument();
$r = $d->appendChild($d->createElement('root'));
$e = $r->insertBefore($d->createElement('e'), null);
$e->setAttribute('k', 'v');
$e->appendChild($d->createElement('c'));
$c = $e->cloneNode(true);
echo $c->tagName, '|', $c->getAttribute('k'), '|', $c->childNodes->length;
echo '|', $c->isSameNode($e) ? 'same' : 'diff';
echo "\n";
$anchor = $r->appendChild($d->createElement('z'));
$e2 = $r->insertBefore($d->createElement('e2'), $anchor);
$e2->setAttribute('a', 'b');
$c2 = $e2->cloneNode(false);
echo $c2->tagName, '|', $c2->getAttribute('a'), '|', $c2->hasChildNodes() ? 'kids' : 'empty', "\n";
