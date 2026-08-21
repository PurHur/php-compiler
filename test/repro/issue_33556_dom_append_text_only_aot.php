<?php
declare(strict_types=1);

// #33556 — text-only append leaves documentElement null.
$d = new DOMDocument();
$t = $d->createTextNode('hi');
$d->appendChild($t);
echo 'fc='.$d->firstChild->nodeName;
echo ' de='.(null === $d->documentElement ? 'null' : 'set');
echo "\n";
