<?php
// Repro for #25475 — AOT textContent read after loadXML must not segfault.
$d = new DOMDocument();
$d->loadXML('<r>hi</r>');
echo $d->documentElement->textContent, "\n";
echo $d->documentElement->nodeValue, "\n";
echo trim($d->saveXML($d->documentElement)), "\n";
