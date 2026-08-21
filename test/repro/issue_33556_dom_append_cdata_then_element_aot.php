<?php
declare(strict_types=1);

// #33556 — CDATA must not become documentElement; element after CDATA is root.
$d = new DOMDocument();
$c = $d->createCDATASection('hi');
$d->appendChild($c);
$e = $d->createElement('x');
$d->appendChild($e);
echo 'fc='.$d->firstChild->nodeName;
echo ' de='.$d->documentElement->tagName;
echo "\n";
