--TEST--
xmlwriter XMLWriter::openMemory streaming output — v1 (#6065, ext/xmlwriter/php_xmlwriter.c)
--FILE--
<?php
var_export(class_exists('XMLWriter', false));
echo "\n";
$w = new XMLWriter();
$w->openMemory();
$w->startDocument('1.0', 'UTF-8');
$w->startElement('root');
$w->text('hi');
$w->endElement();
echo $w->outputMemory(), "\n";
echo (int) extension_loaded('xmlwriter'), "\n";
?>
--EXPECT--
true
<?xml version="1.0" encoding="UTF-8"?>
<root>hi</root>
1
