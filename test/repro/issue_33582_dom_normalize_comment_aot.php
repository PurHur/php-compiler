<?php
declare(strict_types=1);
/** #33582 — normalize after loadXML text/comment/text. */
$d = new DOMDocument();
$d->loadXML('<r>a<!--c-->b</r>');
$r = $d->documentElement;
$r->normalize();
echo $r->childNodes->length, ' ', $r->firstChild->nodeValue, "\n";
