<?php
/** #35373 — AOT cloneNode on appendChild(createElement) DOMNode return (no loadXML). */
$d = new DOMDocument();
$e = $d->appendChild($d->createElement('e'));
$e->setAttribute('k', 'v');
$e->appendChild($d->createElement('c'));
$c = $e->cloneNode(true);
echo $c->tagName, '|', $c->getAttribute('k'), '|', $c->childNodes->length;
echo '|', $c->isSameNode($e) ? 'same' : 'diff';
echo "\n";
$shallow = $e->cloneNode(false);
echo $shallow->tagName, '|', $shallow->getAttribute('k'), '|', $shallow->hasChildNodes() ? 'kids' : 'empty', "\n";
