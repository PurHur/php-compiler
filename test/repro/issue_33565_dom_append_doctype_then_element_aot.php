<?php
declare(strict_types=1);

// #33565 — DocumentType must not become documentElement; element after it is root.
$impl = new DOMImplementation();
$dt = $impl->createDocumentType('html');
$d = new DOMDocument();
$d->appendChild($dt);
$e = $d->createElement('x');
$d->appendChild($e);
echo 'fc='.$d->firstChild->nodeName;
echo ' de='.$d->documentElement->tagName;
echo "\n";
