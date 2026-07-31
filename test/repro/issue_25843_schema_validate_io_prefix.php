<?php
/**
 * #25843 — schemaValidate/relaxNGValidate missing-path I/O warnings must carry
 * DOMDocument::{method}(): prefix (php-src ext/dom/document.c / php_libxml_error_handler).
 */
error_reporting(E_ALL);
$d = new DOMDocument();
$d->loadXML('<r/>');
var_export($d->schemaValidate('/no/such.xsd'));
echo "\n";
var_export($d->relaxNGValidate('/no/such.rng'));
echo "\n";
