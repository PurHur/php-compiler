<?php

declare(strict_types=1);

// #33540 — setAttributeNS after appendChild to the document (same sync path).
$d = new DOMDocument();
$e = $d->createElement('x');
$d->appendChild($e);
$e->setAttributeNS(null, 'k', 'v');
echo 'attr='.$e->getAttribute('k');
echo ' xml='.trim($d->saveXML($e));
echo "\n";
