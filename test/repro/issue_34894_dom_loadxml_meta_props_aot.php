<?php

// #34894 — AOT loadXML document meta props must match Zend (no SIGSEGV).
$d = new DOMDocument();
$d->loadXML('<r/>');
echo $d->xmlVersion, "\n";
var_export($d->xmlEncoding);
echo "\n";
var_export($d->xmlStandalone);
echo "\n";
echo is_object($d->implementation) ? 'impl' : 'noimpl', "\n";
echo (int) $d->implementation->hasFeature('XML', '1.0'), "\n";

$d2 = new DOMDocument();
$d2->loadXML('<?xml version="1.0" encoding="UTF-8"?><r/>');
var_export($d2->xmlEncoding);
echo "\n";

$uri = $d->documentURI;
echo (is_string($uri) && '' !== $uri) ? 'uri' : 'nouri', "\n";
