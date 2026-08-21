<?php
declare(strict_types=1);

// #33556 probe — createTextNode then Element; AOT must print fc=#text de=x
$d = new DOMDocument();
$t = $d->createTextNode('hi');
$d->appendChild($t);
$e = $d->createElement('x');
$d->appendChild($e);
echo 'fc='.$d->firstChild->nodeName;
echo ' de='.$d->documentElement->tagName;
echo "\n";
