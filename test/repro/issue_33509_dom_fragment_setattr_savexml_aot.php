<?php

declare(strict_types=1);

// #33509 — DocumentFragment expand path shares the same XMLNS_ATTR rebuild.
$d = new DOMDocument();
$d->loadXML('<r/>');
$f = $d->createDocumentFragment();
$e = $d->createElement('e');
$e->setAttribute('k', 'v');
$f->appendChild($e);
$d->documentElement->appendChild($f);
echo 'xml='.trim($d->saveXML($d->documentElement));
echo ' attr='.$d->documentElement->firstChild->getAttribute('k');
echo "\n";
