<?php
$d = new DOMDocument();
$e = $d->createElement('r');
$a = $d->createAttribute('id');
$a->value = 'x';
$e->setAttributeNode($a);
$d->appendChild($e);
echo $d->saveXML($e);
