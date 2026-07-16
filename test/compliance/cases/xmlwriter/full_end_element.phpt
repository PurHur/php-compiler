--TEST--
xmlwriter fullEndElement — explicit close tags vs endElement self-close (#19551, ext/xmlwriter/php_xmlwriter.c)
--FILE--
<?php
$w = new XMLWriter();
$w->openMemory();
$w->startDocument('1.0');
$w->startElement('root');
$w->startElement('child');
$w->text('x');
echo 'has_fullEndElement=', method_exists($w, 'fullEndElement') ? '1' : '0', "\n";
var_export($w->fullEndElement());
echo "\n";
$w->endElement();
$w->endDocument();
echo $w->outputMemory();

$empty = new XMLWriter();
$empty->openMemory();
$empty->startElement('a');
var_export($empty->fullEndElement());
echo "\n";
echo $empty->outputMemory(false), "\n";

$self = new XMLWriter();
$self->openMemory();
$self->startElement('a');
var_export($self->endElement());
echo "\n";
echo $self->outputMemory(false), "\n";
?>
--EXPECT--
has_fullEndElement=1
true
<?xml version="1.0"?>
<root><child>x</child></root>
true
<a></a>
true
<a/>
