<?php
declare(strict_types=1);

/**
 * #33570 — AOT Element::appendChild(Attr) installs via attribute map (php-src node.c).
 * Zend: firstChild null, attributes length 1, saveXML shows the attr.
 */
$d = new DOMDocument();
$a = $d->createAttribute('id');
$a->value = 'x';
$e = $d->createElement('r');
$e->appendChild($a);
$d->appendChild($e);
echo $d->saveXML();
$fc = $e->firstChild;
echo 'fc=', null === $fc ? 'null' : $fc->nodeName, ' attrs=', $e->attributes->length, "\n";
