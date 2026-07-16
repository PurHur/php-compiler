--TEST--
xmlwriter writeAttribute/writeElement/writeCData/writeComment/setIndent — (#19340, ext/xmlwriter/php_xmlwriter.c)
--FILE--
<?php
$w = new XMLWriter();
$w->openMemory();
$w->startDocument('1.0', 'UTF-8');
$w->startElement('root');
echo 'has_writeAttribute=', method_exists($w, 'writeAttribute') ? '1' : '0', "\n";
echo 'has_writeElement=', method_exists($w, 'writeElement') ? '1' : '0', "\n";
echo 'has_writeCData=', method_exists($w, 'writeCData') ? '1' : '0', "\n";
echo 'has_writeComment=', method_exists($w, 'writeComment') ? '1' : '0', "\n";
echo 'has_setIndent=', method_exists($w, 'setIndent') ? '1' : '0', "\n";
var_export($w->writeAttribute('id', '1'));
echo "\n";
var_export($w->writeElement('child', 'hi'));
echo "\n";
var_export($w->writeCData('x<y'));
echo "\n";
var_export($w->writeComment('c'));
echo "\n";
var_export($w->setIndent(true));
echo "\n";
$w->endElement();
$w->endDocument();
echo $w->outputMemory(), "\n";
?>
--EXPECT--
has_writeAttribute=1
has_writeElement=1
has_writeCData=1
has_writeComment=1
has_setIndent=1
true
true
true
true
true
<?xml version="1.0" encoding="UTF-8"?>
<root id="1"><child>hi</child><![CDATA[x<y]]><!--c--></root>

