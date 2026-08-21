<?php

declare(strict_types=1);

// #33509 — setAttribute after appendChild must refresh parent INNER_XML.
$d = new DOMDocument();
$d->loadXML('<r/>');
$e = $d->createElement('e');
$d->documentElement->appendChild($e);
$e->setAttribute('k', 'v');
echo 'xml='.trim($d->saveXML($d->documentElement));
echo ' attr='.$e->getAttribute('k');
echo "\n";
