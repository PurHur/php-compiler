--TEST--
xmlwriter procedural xmlwriter_* open_memory streaming — #19514 (ext/xmlwriter/php_xmlwriter.c)
--FILE--
<?php
$w = xmlwriter_open_memory();
var_export(is_object($w) && $w instanceof XMLWriter);
echo "\n";
xmlwriter_start_document($w, '1.0');
xmlwriter_start_element($w, 'root');
xmlwriter_write_attribute($w, 'id', '1');
xmlwriter_text($w, 'hi');
xmlwriter_end_element($w);
xmlwriter_end_document($w);
echo xmlwriter_output_memory($w);
?>
--EXPECT--
true
<?xml version="1.0"?>
<root id="1">hi</root>
