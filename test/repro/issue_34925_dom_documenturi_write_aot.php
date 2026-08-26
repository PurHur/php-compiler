<?php
// #34925 — DOMDocument documentURI writes must stick; baseURI aliases (read-only).
$d = new DOMDocument();
var_export($d->documentURI);
echo "\n";
var_export($d->baseURI);
echo "\n";
$d->documentURI = 'file:///tmp/x.xml';
var_export($d->documentURI);
echo "\n";
var_export($d->baseURI);
echo "\n";

// loadXML still stamps cwd (#34894 / #34904).
$d2 = new DOMDocument();
$d2->loadXML('<r/>');
$uri = $d2->documentURI;
$base = $d2->baseURI;
echo (is_string($uri) && '' !== $uri) ? 'uri' : 'nouri', "\n";
echo (is_string($base) && '' !== $base) ? 'base' : 'nobase', "\n";
echo ($uri === $base) ? 'same' : 'diff', "\n";
