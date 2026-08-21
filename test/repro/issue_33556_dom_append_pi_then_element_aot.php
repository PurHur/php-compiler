<?php
declare(strict_types=1);

// #33556 — PI must not become documentElement; element after PI is root.
$d = new DOMDocument();
$p = $d->createProcessingInstruction('xml-stylesheet', 'type="text/xsl"');
$d->appendChild($p);
$e = $d->createElement('x');
$d->appendChild($e);
echo 'fc='.$d->firstChild->nodeName;
echo ' de='.$d->documentElement->tagName;
echo "\n";
