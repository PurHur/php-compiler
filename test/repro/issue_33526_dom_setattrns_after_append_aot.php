<?php

declare(strict_types=1);

// #33526 — setAttributeNS after appendChild must survive saveXML (peer #33509).
$d = new DOMDocument();
$d->loadXML('<r/>');
$e = $d->createElement('e');
$d->documentElement->appendChild($e);
$e->setAttributeNS('http://example.com/ns', 'x:k', 'v');
echo 'xml='.trim($d->saveXML($d->documentElement));
echo ' attr='.$e->getAttributeNS('http://example.com/ns', 'k');
echo "\n";
