--TEST--
xmlwriter startDtdAttlist/endDtdAttlist streaming ATTLIST — (#20025, ext/xmlwriter/php_xmlwriter.c)
--FILE--
<?php
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

$w2 = new XMLWriter();
$w2->openMemory();
$w2->startDtd('r');
$w2->startDtdAttlist('el');
$w2->text('a CDATA');
$w2->text(' #IMPLIED');
$w2->endDtdAttlist();
$w2->endDtd();
echo 'multi=', $w2->outputMemory(), "\n";

$w3 = new XMLWriter();
$w3->openMemory();
$w3->startDtd('r');
$w3->startDtdAttlist('el');
$w3->endDtdAttlist();
$w3->endDtd();
echo 'empty=', $w3->outputMemory(), "\n";

$w4 = xmlwriter_open_memory();
$w4obj = $w4; // XMLWriter from open_memory
// Procedural ATTLIST pair; bookend via OOP until xmlwriter_start_dtd lands.
$w4obj->startDtd('root');
xmlwriter_start_dtd_attlist($w4obj, 'root');
xmlwriter_text($w4obj, 'id ID #REQUIRED');
xmlwriter_end_dtd_attlist($w4obj);
$w4obj->endDtd();
echo 'proc=', xmlwriter_output_memory($w4obj), "\n";
?>
--EXPECT--
startDtdAttlist=1
endDtdAttlist=1
proc_start=1
proc_end=1
true
true
true
out=<?xml version="1.0"?>
<!DOCTYPE root [<!ATTLIST root id ID #REQUIRED>]><root/>
multi=<!DOCTYPE r [<!ATTLIST el a CDATA #IMPLIED>]>
empty=<!DOCTYPE r [<!ATTLIST el>]>
proc=<!DOCTYPE root [<!ATTLIST root id ID #REQUIRED>]>
