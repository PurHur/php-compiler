<?php
declare(strict_types=1);

// #33576 — Element::appendChild(entity-ref).
$d = new DOMDocument();
$r = $d->createElement('root');
$d->appendChild($r);
$e = $d->createEntityReference('amp');
$r->appendChild($e);
echo $d->saveXML();
