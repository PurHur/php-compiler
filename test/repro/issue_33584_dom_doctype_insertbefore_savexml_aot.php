<?php
declare(strict_types=1);

// #33584 — insertBefore(DocumentType, documentElement) must not SIGSEGV; saveXML keeps DOCTYPE.
$impl = new DOMImplementation();
$dt = $impl->createDocumentType('html');
$d = new DOMDocument();
$el = $d->createElement('html');
$d->appendChild($el);
$d->insertBefore($dt, $el);
echo $d->saveXML();
