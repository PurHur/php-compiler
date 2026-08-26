<?php
// #34894 — loadXML must expose document meta props (no SIGSEGV).
$d = new DOMDocument();
$d->loadXML('<r/>');
echo $d->xmlVersion, "\n";
var_export($d->xmlEncoding);
echo "\n";
var_export($d->xmlStandalone);
echo "\n";
var_export($d->documentURI);
echo "\n";
echo is_object($d->implementation) ? "impl_obj\n" : "impl_missing\n";
echo get_class($d->implementation), "\n";

// XML declaration fields (Zend keeps version even when unsupported).
$d2 = new DOMDocument();
@$d2->loadXML('<?xml version="1.0" encoding="UTF-8" standalone="yes"?><r/>');
echo $d2->xmlVersion, "\n";
echo $d2->xmlEncoding, "\n";
var_export($d2->xmlStandalone);
echo "\n";

// No loadXML — must not SIGSEGV.
$d3 = new DOMDocument();
echo $d3->xmlVersion, "\n";
var_export($d3->documentURI);
echo "\n";
echo is_object($d3->implementation) ? "impl_ok\n" : "impl_bad\n";
