<?php
declare(strict_types=1);

// #33556 — text must not steal documentElement; element after text is root.
$d = new DOMDocument();
$t = $d->createTextNode('hi');
$d->appendChild($t);
$e = $d->createElement('x');
$d->appendChild($e);
echo 'fc='.$d->firstChild->nodeName;
echo ' de='.$d->documentElement->tagName;
echo "\n";
