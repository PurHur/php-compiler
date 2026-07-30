<?php
/**
 * Maintainer gap repro — XMLWriter::*Ns() colon in prefix (#25310).
 */
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
