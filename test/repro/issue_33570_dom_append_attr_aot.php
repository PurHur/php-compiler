<?php
$d = new DOMDocument();
$r = $d->createElement('r');
$d->appendChild($r);
$a = $d->createAttribute('id');
$a->value = 'x';
$r->appendChild($a);
echo $d->saveXML($r);
