<?php
$d = new DOMDocument();
$r = $d->createElement('r');
$d->appendChild($r);
$a = $d->createAttribute('id');
$a->value = 'x';
$r->setAttributeNode($a);
echo $d->saveXML($r);
