<?php
declare(strict_types=1);

// #33556 — PI then element; tagName #pi must not become documentElement.
$d = new DOMDocument();
$p = $d->createProcessingInstruction('xml-stylesheet', 'type="text/xsl" href="x.xsl"');
$d->appendChild($p);
$e = $d->createElement('x');
$d->appendChild($e);
echo 'fc='.$d->firstChild->nodeName;
echo ' de='.$d->documentElement->tagName;
echo "\n";
