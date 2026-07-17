<?php

declare(strict_types=1);

/**
 * Repro #20025 — XMLWriter::startDtdAttlist()/endDtdAttlist() streaming ATTLIST.
 * Zend: startDtd → startDtdAttlist → text → endDtdAttlist emits <!ATTLIST …>.
 */
$w = new XMLWriter();
$w->openMemory();
$w->startDocument('1.0');
foreach (['startDtdAttlist', 'endDtdAttlist'] as $m) {
    echo $m, '=', method_exists($w, $m) ? '1' : '0', "\n";
}
echo 'proc_start=', function_exists('xmlwriter_start_dtd_attlist') ? '1' : '0', "\n";
echo 'proc_end=', function_exists('xmlwriter_end_dtd_attlist') ? '1' : '0', "\n";
$w->startDtd('root');
var_export($w->startDtdAttlist('root'));
echo "\n";
var_export($w->text('id ID #REQUIRED'));
echo "\n";
var_export($w->endDtdAttlist());
echo "\n";
$w->endDtd();
$w->startElement('root');
$w->endElement();
echo 'out=', $w->outputMemory(), "\n";
