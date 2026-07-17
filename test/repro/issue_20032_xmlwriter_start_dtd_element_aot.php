<?php
/**
 * AOT verify #20032 — streaming startDtdElement/endDtdElement.
 * Avoids method_exists/var_export (pre-existing thin-AOT gaps with XMLWriter).
 */
$w = new XMLWriter();
$w->openMemory();
$w->startDocument('1.0');
$w->startDtd('root');
$w->startDtdElement('child');
$w->text('(#PCDATA)');
$w->endDtdElement();
$w->endDtd();
$w->startElement('root');
$w->endElement();
echo $w->outputMemory(), "\n";
