<?php
/** Repro for #34926 — createAttributeNS Attr value write must update qName in saveXML. */
$d = new DOMDocument();
$e = $d->createElement('r');
$d->appendChild($e);
$a = $d->createAttributeNS('urn:x', 'x:id');
$e->setAttributeNodeNS($a);
$a->value = '1';
echo $d->saveXML();
