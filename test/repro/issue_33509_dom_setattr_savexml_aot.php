<?php

declare(strict_types=1);

// #33509 — setAttribute must survive saveXML / appendChild INNER_XML rebuild.
$d = new DOMDocument();
$d->loadXML('<r/>');
$e = $d->createElement('e');
$e->setAttribute('k', 'v');
$d->documentElement->appendChild($e);
echo 'xml='.trim($d->saveXML($d->documentElement));
echo ' attr='.$d->documentElement->firstChild->getAttribute('k');
echo "\n";
