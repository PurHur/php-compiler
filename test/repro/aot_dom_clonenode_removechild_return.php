<?php
/** #35386 — AOT cloneNode on removeChild() DOMNode return (no loadXML). */
$d = new DOMDocument();
$r = $d->appendChild($d->createElement('root'));
$e = $r->appendChild($d->createElement('e'));
$e->setAttribute('k', 'v');
$e->appendChild($d->createElement('c'));
$removed = $r->removeChild($e);
$c = $removed->cloneNode(true);
echo $c->tagName, '|', $c->getAttribute('k'), '|', $c->childNodes->length;
echo '|', $c->isSameNode($removed) ? 'same' : 'diff';
echo '|rootKids=', $r->childNodes->length;
echo "\n";
