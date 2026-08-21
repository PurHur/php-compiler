<?php
declare(strict_types=1);

// #33584 — DocumentType appendChild must appear in saveXML (php-src document.c dump).
$impl = new DOMImplementation();
$dt = $impl->createDocumentType('html');
$d = new DOMDocument();
$d->appendChild($dt);
$d->appendChild($d->createElement('html'));
echo $d->saveXML();
