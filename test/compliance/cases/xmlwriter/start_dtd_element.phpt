--TEST--
xmlwriter startDtdElement/endDtdElement streaming ELEMENT — (#20032, ext/xmlwriter/php_xmlwriter.c)
--FILE--
<?php
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

$w2 = new XMLWriter();
$w2->openMemory();
$w2->startDtd('r');
$w2->startDtdElement('el');
$w2->text('EMP');
$w2->text('TY');
$w2->endDtdElement();
$w2->endDtd();
echo 'multi=', $w2->outputMemory(), "\n";

$w3 = new XMLWriter();
$w3->openMemory();
$w3->startDtd('r');
$w3->startDtdElement('el');
$w3->endDtdElement();
$w3->endDtd();
echo 'empty=', $w3->outputMemory(), "\n";

$w4 = xmlwriter_open_memory();
$w4obj = $w4; // XMLWriter from open_memory
// Procedural ELEMENT pair; bookend via OOP until xmlwriter_start_dtd lands.
$w4obj->startDtd('root');
xmlwriter_start_dtd_element($w4obj, 'child');
xmlwriter_text($w4obj, '(#PCDATA)');
xmlwriter_end_dtd_element($w4obj);
$w4obj->endDtd();
echo 'proc=', xmlwriter_output_memory($w4obj), "\n";
?>
--EXPECT--
startDtdElement=1
endDtdElement=1
proc_start=1
proc_end=1
true
true
true
out=<?xml version="1.0"?>
<!DOCTYPE root [<!ELEMENT child (#PCDATA)>]><root/>
multi=<!DOCTYPE r [<!ELEMENT el EMPTY>]>
empty=<!DOCTYPE r [<!ELEMENT el>]>
proc=<!DOCTYPE root [<!ELEMENT child (#PCDATA)>]>
