<?php
// #33864 — AOT DOMAttr::$textContent must mirror value/nodeValue (php-src ext/dom/node.c).
$d = new DOMDocument();
$attr = $d->createAttribute('q');
$attr->value = 'v';
var_dump($attr->textContent);
var_dump($attr->nodeValue);
$attr->textContent = 'w';
var_dump($attr->value);
var_dump($attr->textContent);
echo "done\n";
