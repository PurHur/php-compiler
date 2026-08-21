<?php
declare(strict_types=1);

// #33556 — PI then element: firstChild nodeName is the PI target; documentElement is the element.
$d = new DOMDocument();
$p = $d->createProcessingInstruction('xml-stylesheet', 'type="text/xsl"');
$d->appendChild($p);
$e = $d->createElement('x');
$d->appendChild($e);
echo 'fc='.$d->firstChild->nodeName;
echo ' de='.$d->documentElement->tagName;
echo "\n";
