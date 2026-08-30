<?php
declare(strict_types=1);

/**
 * AOT XMLWriter::writeElement leftover of writeElementNS (#35865 / #19371).
 * php-src: ext/xmlwriter/php_xmlwriter.c zim_XMLWriter_writeElement
 */
$w = new XMLWriter();
$w->openMemory();
$w->startDocument('1.0', 'UTF-8');
$w->startElement('root');
var_export($w->writeElement('child', 'hi'));
echo "\n";
var_export($w->writeElement('empty'));
echo "\n";
var_export($w->writeCdata('x<y'));
echo "\n";
var_export($w->writeComment('c'));
echo "\n";
$w->endElement();
$w->endDocument();
echo $w->outputMemory();

$ind = new XMLWriter();
$ind->openMemory();
$ind->setIndent(true);
$ind->setIndentString('  ');
$ind->startElement('r');
$ind->writeElement('a', '1');
$ind->endElement();
echo $ind->outputMemory();
