<?php
/**
 * Maintainer gap repro — XMLWriter::startDtdElement()/endDtdElement() (#20032).
 */
$w = new XMLWriter();
$w->openMemory();
echo 'startDtdElement=', method_exists($w, 'startDtdElement') ? '1' : '0', "\n";
echo 'endDtdElement=', method_exists($w, 'endDtdElement') ? '1' : '0', "\n";
$w->startDtd('root');
$w->startDtdElement('child');
$w->text('(#PCDATA)');
$w->endDtdElement();
$w->endDtd();
$w->startElement('root');
$w->endElement();
echo $w->outputMemory(), "\n";
