<?php
declare(strict_types=1);

// #33576 — Element::appendChild(CDATA) must appear in saveXML (not SIGSEGV).
$d = new DOMDocument();
$r = $d->createElement('root');
$d->appendChild($r);
$c = $d->createCDATASection('x<y');
$r->appendChild($c);
echo $d->saveXML();
