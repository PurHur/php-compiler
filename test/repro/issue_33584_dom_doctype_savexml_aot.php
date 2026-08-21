<?php
declare(strict_types=1);
// #33584 — appendChild(DocumentType)+Element; saveXML must emit <!DOCTYPE …>
$impl = new DOMImplementation();
$dt = $impl->createDocumentType('html');
$d = new DOMDocument();
$d->appendChild($dt);
$e = $d->createElement('html');
$d->appendChild($e);
echo $d->saveXML();
