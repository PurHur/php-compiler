--TEST--
XMLWriter::*Ns() accepts colon in prefix — #25310 (ext/xmlwriter/php_xmlwriter.c)
--FILE--
<?php
$w = new XMLWriter();
$w->openMemory();
$w->startElementNS('urn:a', 'r', 'p');
$w->endElement();
echo $w->outputMemory(), "\n";

$w2 = new XMLWriter();
$w2->openMemory();
$w2->startElement('r');
$w2->writeAttributeNS('urn:a', 'x', 'p', 'v');
$w2->endElement();
echo $w2->outputMemory(), "\n";

$w3 = new XMLWriter();
$w3->openMemory();
$w3->writeElementNS('urn:a', 'r', 'p', 'hi');
echo $w3->outputMemory(), "\n";

$w4 = new XMLWriter();
$w4->openMemory();
$w4->startElement('r');
$w4->startAttributeNS('urn:a', 'x', 'p');
$w4->text('v');
$w4->endAttribute();
$w4->endElement();
echo $w4->outputMemory(), "\n";
?>
--EXPECT--
<urn:a:r xmlns:urn:a="p"/>
<r urn:a:x="v" xmlns:urn:a="p"/>
<urn:a:r xmlns:urn:a="p">hi</urn:a:r>
<r urn:a:x="v" xmlns:urn:a="p"/>
