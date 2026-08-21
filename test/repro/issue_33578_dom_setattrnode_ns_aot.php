<?php
declare(strict_types=1);

/**
 * #33578 — AOT setAttributeNodeNS keeps xmlns:prefix in saveXML
 * (php-src element.c; peer #33570 / #33526).
 */
$d = new DOMDocument();
$e = $d->createElement('r');
$d->appendChild($e);
$a = $d->createAttributeNS('http://ex', 'x:id');
$a->value = 'v';
$e->setAttributeNodeNS($a);
echo $d->saveXML();
echo 'attrs=', $e->attributes->length, "\n";
