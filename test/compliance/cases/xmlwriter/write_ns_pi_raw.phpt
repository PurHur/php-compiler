--TEST--
xmlwriter writeAttributeNS/writeElementNS/writePi/writeRaw — (#19371, ext/xmlwriter/php_xmlwriter.c)
--FILE--
<?php
$w = new XMLWriter();
$w->openMemory();
$w->startDocument('1.0', 'UTF-8');
$w->startElement('root');
echo 'has_writeAttributeNS=', method_exists($w, 'writeAttributeNS') ? '1' : '0', "\n";
echo 'has_writeElementNS=', method_exists($w, 'writeElementNS') ? '1' : '0', "\n";
echo 'has_writePi=', method_exists($w, 'writePi') ? '1' : '0', "\n";
echo 'has_writeRaw=', method_exists($w, 'writeRaw') ? '1' : '0', "\n";
var_export($w->writeAttributeNS('a', 'x', 'http://ex', '1'));
echo "\n";
var_export($w->writeElementNS('a', 'child', 'http://ex', 'v'));
echo "\n";
var_export($w->writePi('php', 'echo 1'));
echo "\n";
var_export($w->writeRaw('<raw/>'));
echo "\n";
$w->endElement();
$w->endDocument();
echo $w->outputMemory(), "\n";
?>
--EXPECT--
has_writeAttributeNS=1
has_writeElementNS=1
has_writePi=1
has_writeRaw=1
true
true
true
true
<?xml version="1.0" encoding="UTF-8"?>
<root a:x="1" xmlns:a="http://ex"><a:child xmlns:a="http://ex">v</a:child><?php echo 1?><raw/></root>
