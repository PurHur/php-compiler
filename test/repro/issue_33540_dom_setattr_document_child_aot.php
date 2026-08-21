<?php
declare(strict_types=1);

// #33540 — setAttribute after appendChild(document) must not SIGSEGV.
$d = new DOMDocument();
$e = $d->createElement('x');
$d->appendChild($e);
$e->setAttribute('k', 'v');
echo $e->getAttribute('k');
echo "\n";
echo trim($d->saveXML($e));
echo "\n";
