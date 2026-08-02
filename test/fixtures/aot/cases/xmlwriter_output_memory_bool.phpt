--TEST--
AOT: XMLWriter::outputMemory(true|false) folds CONST_FETCH bool (#26774)
--FILE--
<?php
declare(strict_types=1);

$w = new XMLWriter();
$w->openMemory();
$w->startDocument('1.0');
$w->startElement('r');
$w->text('x');
$w->endElement();
$w->endDocument();
echo $w->outputMemory(true);

$w2 = new XMLWriter();
$w2->openMemory();
$w2->startElement('a');
$w2->endElement();
echo $w2->flush(false);
echo "\n";
--EXPECT--
<?xml version="1.0"?>
<r>x</r>
<a/>
--EXPECT_EXIT--
0
