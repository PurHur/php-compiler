<?php

declare(strict_types=1);

// #33526 — setAttributeNS(null, …) must survive saveXML (peer #33509).
$d = new DOMDocument();
$d->loadXML('<r/>');
$e = $d->createElement('e');
$e->setAttributeNS(null, 'k', 'v');
$d->documentElement->appendChild($e);
echo 'xml='.trim($d->saveXML($d->documentElement));
echo ' attr='.$d->documentElement->firstChild->getAttribute('k');
echo "\n";
