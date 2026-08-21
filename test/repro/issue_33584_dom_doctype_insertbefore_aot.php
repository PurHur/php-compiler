<?php
declare(strict_types=1);
// #33584 — insertBefore(DocumentType, documentElement) must not SIGSEGV
$impl = new DOMImplementation();
$dt = $impl->createDocumentType('html');
$d = new DOMDocument();
$e = $d->createElement('html');
$d->appendChild($e);
$d->insertBefore($dt, $e);
echo $d->saveXML();
