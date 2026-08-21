<?php
// #33575 — Element::appendChild(CDATA) must not SIGSEGV; saveXML matches Zend.
$d = new DOMDocument();
$r = $d->createElement('r');
$d->appendChild($r);
$n = $d->createCDATASection('hi');
$r->appendChild($n);
echo $d->saveXML($r);
