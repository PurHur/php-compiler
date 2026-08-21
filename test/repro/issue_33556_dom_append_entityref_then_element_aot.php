<?php
declare(strict_types=1);

// #33556 — entity-ref must not become documentElement; element after it is root.
$d = new DOMDocument();
$r = $d->createEntityReference('amp');
$d->appendChild($r);
$e = $d->createElement('x');
$d->appendChild($e);
echo 'fc='.$d->firstChild->nodeName;
echo ' de='.$d->documentElement->tagName;
echo "\n";
