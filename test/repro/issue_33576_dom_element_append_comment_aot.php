<?php
declare(strict_types=1);

// #33576 — Element::appendChild(comment).
$d = new DOMDocument();
$r = $d->createElement('root');
$d->appendChild($r);
$c = $d->createComment('hi');
$r->appendChild($c);
echo $d->saveXML();
