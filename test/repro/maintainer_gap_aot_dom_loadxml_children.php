<?php
// Repro #26757 — AOT loadXML must keep element children for saveXML/appendChild.
$d = new DOMDocument();
$d->loadXML('<root><x/></root>');
echo 'len=', $d->documentElement->childNodes->length, "\n";
echo 'xml=', trim($d->saveXML($d->documentElement)), "\n";
$d->documentElement->appendChild($d->createElement('a'));
echo 'after=', trim($d->saveXML($d->documentElement)), "\n";
