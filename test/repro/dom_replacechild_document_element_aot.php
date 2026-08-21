<?php
// #33379 — AOT replaceChild of documentElement must update root (php-src ext/dom/node.c)
$d = new DOMDocument();
$d->loadXML('<old/>');
$n = $d->createElement('new');
$d->replaceChild($n, $d->documentElement);
echo 'de=' . $d->documentElement->tagName . "\n";
echo 'xml=' . trim($d->saveXML()) . "\n";
