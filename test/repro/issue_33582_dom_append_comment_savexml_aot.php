<?php
declare(strict_types=1);
/** #33582 — appendChild(comment) must not SIGSEGV; saveXML matches Zend. */
$d = new DOMDocument();
$r = $d->createElement('r');
$d->appendChild($r);
$c = $d->createComment('c');
$r->appendChild($c);
echo $c->nodeName, '|', $d->saveXML($r), "\n";
