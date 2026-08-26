<?php
// #34916 — DOMDocument xmlStandalone/xmlVersion writes must stick (not MetaProps hardcodes).
$d = new DOMDocument();
echo (int) $d->xmlStandalone, ' ', $d->xmlVersion, "\n";
$d->xmlStandalone = true;
$d->xmlVersion = '1.1';
echo (int) $d->xmlStandalone, ' ', $d->xmlVersion, "\n";

// Legacy aliases (php-src document.c) — each slot sticks; no abort.
$d2 = new DOMDocument();
$d2->standalone = true;
$d2->version = '1.1';
echo (int) $d2->standalone, ' ', $d2->version, "\n";

// Defaults after loadXML must still match Zend (#34894).
$d3 = new DOMDocument();
$d3->loadXML('<r/>');
echo $d3->xmlVersion, "\n";
var_export($d3->xmlStandalone);
echo "\n";
