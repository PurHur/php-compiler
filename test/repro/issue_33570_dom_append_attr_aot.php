<?php
// #33570 — Element::appendChild(Attr) must install via attribute map (php-src node.c).
$d = new DOMDocument();
$e = $d->createElement('r');
$d->appendChild($e);
$a = $d->createAttribute('id');
$a->value = 'x';
$e->appendChild($a);
echo $d->saveXML($e);
