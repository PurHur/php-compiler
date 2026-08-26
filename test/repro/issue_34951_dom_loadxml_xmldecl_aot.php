<?php

// #34951 — AOT loadXML must seed xmlStandalone / xmlVersion from XML declaration.
$d = new DOMDocument();
$d->loadXML('<?xml version="1.0" standalone="yes"?><r/>');
var_dump($d->xmlStandalone);
var_dump($d->standalone);
echo $d->xmlVersion, "\n";

$d2 = new DOMDocument();
$d2->loadXML('<?xml version="1.0" standalone="no"?><r/>');
var_dump($d2->xmlStandalone);

$d3 = new DOMDocument();
$d3->loadXML('<?xml version="1.0"?><r/>');
var_dump($d3->xmlStandalone);

// Keep encoding regression covered alongside standalone/version (#34919 / #34951).
$d4 = new DOMDocument();
$d4->loadXML('<?xml version="1.0" encoding="UTF-8" standalone="yes"?><r/>');
echo $d4->xmlVersion, "\n";
var_dump($d4->xmlStandalone);
var_export($d4->xmlEncoding);
echo "\n";
