<?php
declare(strict_types=1);

/**
 * #35185 — AOT Element::appendChild(Attr) on loadXML documentElement keeps attr in saveXML.
 * Peer createElement path: test/repro/issue_33570_dom_append_attr_aot.php.
 */
$d = new DOMDocument();
$d->loadXML('<r/>');
$a = $d->createAttribute('id');
$a->value = '1';
$d->documentElement->appendChild($a);
echo $d->saveXML($d->documentElement), "\n";
$fc = $d->documentElement->firstChild;
echo 'fc=', null === $fc ? 'null' : $fc->nodeName, ' attrs=', $d->documentElement->attributes->length, "\n";
