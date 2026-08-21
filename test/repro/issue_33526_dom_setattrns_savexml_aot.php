<?php

declare(strict_types=1);

// #33526 — setAttributeNS must survive saveXML / appendChild INNER_XML rebuild (peer #33509).
$d = new DOMDocument();
$d->loadXML('<r/>');
$e = $d->createElement('e');
$e->setAttributeNS('http://example.com/ns', 'x:k', 'v');
$d->documentElement->appendChild($e);
echo 'xml='.trim($d->saveXML($d->documentElement));
echo ' attr='.$d->documentElement->firstChild->getAttributeNS('http://example.com/ns', 'k');
echo "\n";
