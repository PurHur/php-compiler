<?php
/**
 * Repro #20032 — XMLWriter::startDtdElement()/endDtdElement() streaming ELEMENT.
 * Zend: startDtd → startDtdElement → text → endDtdElement emits <!ELEMENT …>.
 */
$w = new XMLWriter();
$w->openMemory();
$w->startDocument('1.0');
foreach (['startDtdElement', 'endDtdElement'] as $m) {
    echo $m, '=', method_exists($w, $m) ? '1' : '0', "\n";
}
echo 'proc_start=', function_exists('xmlwriter_start_dtd_element') ? '1' : '0', "\n";
echo 'proc_end=', function_exists('xmlwriter_end_dtd_element') ? '1' : '0', "\n";
$w->startDtd('root');
var_export($w->startDtdElement('child'));
echo "\n";
var_export($w->text('(#PCDATA)'));
echo "\n";
var_export($w->endDtdElement());
echo "\n";
$w->endDtd();
$w->startElement('root');
$w->endElement();
echo 'out=', $w->outputMemory(), "\n";
