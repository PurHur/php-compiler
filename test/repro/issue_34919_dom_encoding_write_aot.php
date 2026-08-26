<?php

// #34919 — AOT DOMDocument::$encoding write+read (+ xmlEncoding alias) must match Zend.
$d = new DOMDocument();
var_export($d->encoding);
echo PHP_EOL;
var_export($d->xmlEncoding);
echo PHP_EOL;
$d->encoding = 'ISO-8859-1';
var_export($d->encoding);
echo PHP_EOL;
var_export($d->xmlEncoding);
echo PHP_EOL;
var_export($d->actualEncoding);
echo PHP_EOL;

$d2 = new DOMDocument();
$d2->loadXML('<?xml version="1.0" encoding="UTF-8"?><r/>');
var_export($d2->encoding);
echo PHP_EOL;
var_export($d2->xmlEncoding);
echo PHP_EOL;

$d3 = new DOMDocument();
$d3->loadXML('<r/>');
var_export($d3->encoding);
echo PHP_EOL;
