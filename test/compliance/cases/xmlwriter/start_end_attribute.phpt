--TEST--
xmlwriter startAttribute/endAttribute streaming attributes — (#19820, ext/xmlwriter/php_xmlwriter.c)
--FILE--
<?php
$w = new XMLWriter();
$w->openMemory();
$w->startElement('a');
echo 'has_startAttribute=', method_exists($w, 'startAttribute') ? '1' : '0', "\n";
echo 'has_endAttribute=', method_exists($w, 'endAttribute') ? '1' : '0', "\n";
var_export($w->startAttribute('x'));
echo "\n";
var_export($w->text('1'));
echo "\n";
var_export($w->endAttribute());
echo "\n";
$w->startAttribute('y');
$w->text('a&b');
$w->text('<');
$w->endAttribute();
$w->endElement();
echo $w->outputMemory(), "\n";

$w = new XMLWriter();
$w->openMemory();
$w->startElement('a');
var_export($w->endAttribute());
echo "\n";

$w = xmlwriter_open_memory();
xmlwriter_start_element($w, 'a');
xmlwriter_start_attribute($w, 'x');
xmlwriter_text($w, '1');
xmlwriter_end_attribute($w);
xmlwriter_end_element($w);
echo xmlwriter_output_memory($w), "\n";
?>
--EXPECT--
has_startAttribute=1
has_endAttribute=1
true
true
true
<a x="1" y="a&amp;b&lt;"/>
false
<a x="1"/>
