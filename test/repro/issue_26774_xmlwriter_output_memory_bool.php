<?php
// #26774 — XMLWriter::outputMemory(true) under user-script AOT (php-src-strict)
$w = new XMLWriter();
$w->openMemory();
$w->startDocument('1.0');
$w->startElement('r');
$w->text('x');
$w->endElement();
$w->endDocument();
echo $w->outputMemory(true);
