<?php
/** Repro #28587 — DOMDocument legacy Level-3 property aliases. */
$doc = new DOMDocument();
$doc->loadXML('<?xml version="1.0" encoding="ISO-8859-1" standalone="yes"?><r/>');
var_export([
    $doc->version,
    $doc->xmlVersion,
    $doc->standalone,
    $doc->xmlStandalone,
    $doc->actualEncoding,
    $doc->encoding,
    $doc->config,
]);
echo "\n";
$doc->version = '1.1';
$doc->standalone = false;
echo $doc->xmlVersion, ' ';
var_export($doc->xmlStandalone);
echo "\n";
try {
    $doc->actualEncoding = 'x';
    echo "actualEncoding ok\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
try {
    $doc->config = 'x';
    echo "config ok\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
$r = new ReflectionClass('DOMDocument');
echo 'ref:',
    ($r->hasProperty('version') ? 'v' : '-'),
    ($r->hasProperty('standalone') ? 's' : '-'),
    ($r->hasProperty('actualEncoding') ? 'a' : '-'),
    ($r->hasProperty('config') ? 'c' : '-'),
    "\n";
