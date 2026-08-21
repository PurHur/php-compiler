<?php
// #33575 — Element::appendChild(EntityReference) must not SIGSEGV; saveXML matches Zend.
$d = new DOMDocument();
$r = $d->createElement('r');
$d->appendChild($r);
$n = $d->createEntityReference('amp');
$r->appendChild($n);
echo $d->saveXML($r);
