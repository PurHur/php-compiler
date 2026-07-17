--TEST--
xmlwriter startCData/endCData/startPI/endPI streaming — (#19457, ext/xmlwriter/php_xmlwriter.c)
--FILE--
<?php
$w = new XMLWriter();
$w->openMemory();
$w->startElement('r');
echo 'has_startCdata=', method_exists($w, 'startCdata') ? '1' : '0', "\n";
echo 'has_endCdata=', method_exists($w, 'endCdata') ? '1' : '0', "\n";
echo 'has_startPi=', method_exists($w, 'startPi') ? '1' : '0', "\n";
echo 'has_endPi=', method_exists($w, 'endPi') ? '1' : '0', "\n";
var_export($w->startCdata());
echo "\n";
var_export($w->text('a&b<>'));
echo "\n";
var_export($w->endCdata());
echo "\n";
$w->endElement();
echo $w->outputMemory(), "\n";

$w2 = new XMLWriter();
$w2->openMemory();
var_export($w2->startPi('xml-stylesheet'));
echo "\n";
var_export($w2->text('type="text/xsl" href="style.xsl"'));
echo "\n";
var_export($w2->endPi());
echo "\n";
echo $w2->outputMemory(), "\n";
?>
--EXPECT--
has_startCdata=1
has_endCdata=1
has_startPi=1
has_endPi=1
true
true
true
<r><![CDATA[a&b<>]]></r>
true
true
true
<?xml-stylesheet type="text/xsl" href="style.xsl"?>
