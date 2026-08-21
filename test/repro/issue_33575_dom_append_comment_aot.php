<?php
// #33575 — Element::appendChild(Comment) must not SIGSEGV; saveXML matches Zend.
$d = new DOMDocument();
$r = $d->createElement('r');
$d->appendChild($r);
$c = $d->createComment('hi');
$r->appendChild($c);
echo $d->saveXML($r);
