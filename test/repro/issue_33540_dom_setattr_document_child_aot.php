<?php

declare(strict_types=1);

// #33540 — setAttribute after appendChild to the document must not SIGSEGV under AOT.
$d = new DOMDocument();
$e = $d->createElement('x');
$d->appendChild($e);
$e->setAttribute('k', 'v');
echo 'attr='.$e->getAttribute('k');
echo ' xml='.trim($d->saveXML($e));
echo "\n";
