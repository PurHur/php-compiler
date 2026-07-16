--TEST--
AOT: XMLWriter::fullEndElement() explicit close tags (#19551, ext/xmlwriter/php_xmlwriter.c)
--FILE--
<?php
declare(strict_types=1);

$w = new XMLWriter();
$w->openMemory();
$w->startDocument('1.0');
$w->startElement('root');
$w->startElement('child');
$w->text('x');
$w->fullEndElement();
$w->endElement();
$w->endDocument();
echo $w->outputMemory();

$empty = new XMLWriter();
$empty->openMemory();
$empty->startElement('a');
$empty->fullEndElement();
echo $empty->outputMemory();
echo "\n";

$self = new XMLWriter();
$self->openMemory();
$self->startElement('a');
$self->endElement();
echo $self->outputMemory();
echo "\n";
--EXPECT--
<?xml version="1.0"?>
<root><child>x</child></root>
<a></a>
<a/>
--EXPECT_EXIT--
0
