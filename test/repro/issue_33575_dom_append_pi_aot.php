<?php
// #33575 — Element::appendChild(PI) must not SIGSEGV; saveXML matches Zend.
$d = new DOMDocument();
$r = $d->createElement('r');
$d->appendChild($r);
$n = $d->createProcessingInstruction('x', 'y');
$r->appendChild($n);
echo $d->saveXML($r);
