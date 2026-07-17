--TEST--
AOT: XMLWriter::startAttribute()/endAttribute() streaming attributes (#19820, ext/xmlwriter/php_xmlwriter.c)
--FILE--
<?php
declare(strict_types=1);

$w = new XMLWriter();
$w->openMemory();
$w->startElement('a');
$w->startAttribute('x');
$w->text('1');
$w->endAttribute();
$w->startAttribute('y');
$w->text('a&b');
$w->text('<');
$w->endAttribute();
$w->endElement();
echo $w->outputMemory();
echo "\n";
--EXPECT--
<a x="1" y="a&amp;b&lt;"/>
--EXPECT_EXIT--
0
