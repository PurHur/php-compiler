--TEST--
AOT: XMLWriter::startElementNS()/startAttributeNS() namespaced streaming (#19446, ext/xmlwriter/php_xmlwriter.c)
--FILE--
<?php
declare(strict_types=1);

$w = new XMLWriter();
$w->openMemory();
$w->startElementNS('p', 'el', 'urn:x');
$w->endElement();
echo $w->outputMemory(), "\n";

$w2 = new XMLWriter();
$w2->openMemory();
$w2->startElement('root');
$w2->startAttributeNS('p', 'a', 'urn:x');
$w2->text('val');
$w2->endAttribute();
$w2->endElement();
echo $w2->outputMemory(), "\n";

$w3 = new XMLWriter();
$w3->openMemory();
$w3->startElementNS(null, 'el', 'urn:default');
$w3->text('hi');
$w3->endElement();
echo $w3->outputMemory(), "\n";
--EXPECT--
<p:el xmlns:p="urn:x"/>
<root p:a="val" xmlns:p="urn:x"/>
<el xmlns="urn:default">hi</el>
--EXPECT_EXIT--
0
