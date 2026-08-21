<?php
declare(strict_types=1);

// #33576 — Element::appendChild(PI).
$d = new DOMDocument();
$r = $d->createElement('root');
$d->appendChild($r);
$p = $d->createProcessingInstruction('t', 'd');
$r->appendChild($p);
echo $d->saveXML();
