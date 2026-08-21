<?php
declare(strict_types=1);
/** #33582 — CDATA + PI appendChild + saveXML. */
$d = new DOMDocument();
$r = $d->createElement('r');
$d->appendChild($r);
$r->appendChild($d->createCDATASection('x'));
$r->appendChild($d->createProcessingInstruction('t', 'd'));
echo $d->saveXML($r), "\n";
