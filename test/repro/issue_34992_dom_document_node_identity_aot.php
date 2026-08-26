<?php

// #34992 — AOT loadXML document DOMNode identity props must match Zend (no SIGSEGV).
$d = new DOMDocument();
$d->loadXML('<r/>');
var_export($d->nodeName);
echo "\n";
var_export($d->namespaceURI);
echo "\n";
var_export($d->prefix);
echo "\n";
var_export($d->localName);
echo "\n";
var_export($d->attributes);
echo "\n";
