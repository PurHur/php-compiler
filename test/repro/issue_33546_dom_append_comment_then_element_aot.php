<?php
declare(strict_types=1);

// #33546 — comment must not become documentElement; element after comment is root.
$d = new DOMDocument();
$c = $d->createComment('hi');
$d->appendChild($c);
$e = $d->createElement('x');
$d->appendChild($e);
echo 'fc='.$d->firstChild->nodeName;
echo ' de='.$d->documentElement->tagName;
echo "\n";
