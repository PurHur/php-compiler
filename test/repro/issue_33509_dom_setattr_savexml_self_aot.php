<?php

declare(strict_types=1);

// #33509 — node-scoped saveXML must include setAttribute attrs.
$d = new DOMDocument();
$e = $d->createElement('e');
$e->setAttribute('k', 'v');
$d->appendChild($e);
echo 'self='.trim($d->saveXML($e));
echo ' root='.trim($d->saveXML($d->documentElement));
echo "\n";
