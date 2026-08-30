--TEST--
AOT: XMLWriter::writeElement leftover of writeElementNS (#35865, ext/xmlwriter/php_xmlwriter.c)
--FILE--
<?php
declare(strict_types=1);

$w = new XMLWriter();
$w->openMemory();
$w->startDocument('1.0', 'UTF-8');
$w->startElement('root');
$w->writeElement('child', 'hi');
$w->writeElement('empty');
$w->writeCdata('x<y');
$w->writeComment('c');
$w->endElement();
$w->endDocument();
echo $w->outputMemory();
--EXPECT--
<?xml version="1.0" encoding="UTF-8"?>
<root><child>hi</child><empty/><![CDATA[x<y]]><!--c--></root>
--EXPECT_EXIT--
0
