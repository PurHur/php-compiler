<?php
declare(strict_types=1);

// #33556 — text then element: firstChild is text; documentElement is the element.
$d = new DOMDocument();
$t = $d->createTextNode('hi');
$d->appendChild($t);
$e = $d->createElement('x');
$d->appendChild($e);
echo 'fc='.$d->firstChild->nodeName;
echo ' de='.$d->documentElement->tagName;
echo "\n";
