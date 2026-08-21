<?php
declare(strict_types=1);

/**
 * #33570 — AOT setAttributeNode after createAttribute must keep attrs in saveXML (#33509 peer).
 */
$d = new DOMDocument();
$a = $d->createAttribute('id');
$a->value = 'x';
$e = $d->createElement('r');
$e->setAttributeNode($a);
$d->appendChild($e);
echo $d->saveXML();
