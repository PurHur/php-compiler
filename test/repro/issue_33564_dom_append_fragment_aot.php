<?php
declare(strict_types=1);

// #33564 — DocumentFragment append to document must not SIGSEGV.
$d = new DOMDocument();
$f = $d->createDocumentFragment();
$f->appendChild($d->createElement('y'));
$d->appendChild($f);
echo 'fc='.$d->firstChild->nodeName;
echo ' de='.$d->documentElement->tagName;
echo "\n";
