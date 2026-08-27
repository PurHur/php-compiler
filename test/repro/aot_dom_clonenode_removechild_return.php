<?php
/** #35386 — AOT cloneNode on removeChild return (no loadXML). */
$d = new DOMDocument();
$r = $d->appendChild($d->createElement('root'));
$e = $r->appendChild($d->createElement('e'));
$e->setAttribute('k', 'v');
$e->appendChild($d->createElement('c'));
$removed = $r->removeChild($e);
$c = $removed->cloneNode(true);
echo $c->tagName, '|', $c->getAttribute('k'), '|', $c->childNodes->length;
echo '|', $c->isSameNode($removed) ? 'same' : 'diff';
echo "\n";
$shallow = $removed->cloneNode(false);
echo $shallow->tagName, '|', $shallow->getAttribute('k'), '|', $shallow->hasChildNodes() ? 'kids' : 'empty', "\n";
