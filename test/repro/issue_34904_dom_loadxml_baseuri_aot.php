<?php

// #34904 — AOT loadXML document baseURI must match Zend (no SIGSEGV).
$d = new DOMDocument();
var_export($d->baseURI);
echo "\n";
$d->loadXML('<r/>');
var_export($d->baseURI);
echo "\n";
var_export($d->documentURI);
echo "\n";
