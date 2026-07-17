<?php
// Repro #19797 — 1-arg createDocumentType must match Zend (empty publicId/systemId).
$i = new DOMImplementation();
$dt = $i->createDocumentType('html');
echo $dt->name, '|', $dt->publicId === '' ? "''" : $dt->publicId, '|', $dt->systemId === '' ? "''" : $dt->systemId, "\n";
