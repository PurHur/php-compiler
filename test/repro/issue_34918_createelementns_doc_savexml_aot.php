<?php

// #34918 — createElementNS + appendChild + document saveXML (no SIGSEGV).
$d = new DOMDocument();
$el = $d->createElementNS('urn:x', 'x:item');
$d->appendChild($el);
echo null === $el->nextSibling ? 'null' : 'sib', PHP_EOL;
echo $d->saveXML();
