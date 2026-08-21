<?php
declare(strict_types=1);

// #33546 — comment-only append leaves documentElement null.
$d = new DOMDocument();
$c = $d->createComment('note');
$d->appendChild($c);
echo 'fc='.$d->firstChild->nodeName;
echo ' de='.(null === $d->documentElement ? 'null' : 'set');
echo "\n";
