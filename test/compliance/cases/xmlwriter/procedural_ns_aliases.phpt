--TEST--
xmlwriter procedural NS aliases — #20320 (ext/xmlwriter/php_xmlwriter.c)
--FILE--
<?php
foreach ([
    'xmlwriter_start_element_ns',
    'xmlwriter_write_element_ns',
    'xmlwriter_start_attribute_ns',
    'xmlwriter_write_attribute_ns',
] as $fn) {
    echo $fn, '=', function_exists($fn) ? '1' : '0', "\n";
}

$w = xmlwriter_open_memory();
xmlwriter_start_document($w, '1.0');
xmlwriter_start_element_ns($w, 'ex', 'root', 'http://example.com');
xmlwriter_write_attribute_ns($w, 'ex', 'id', 'http://example.com', '1');
xmlwriter_write_element_ns($w, 'ex', 'child', 'http://example.com', 'x');
xmlwriter_end_element($w);
xmlwriter_end_document($w);
echo xmlwriter_output_memory($w), "\n";

$a = xmlwriter_open_memory();
xmlwriter_start_element($a, 'root');
xmlwriter_start_attribute_ns($a, 'ex', 'id', 'http://example.com');
xmlwriter_text($a, '1');
xmlwriter_end_attribute($a);
xmlwriter_end_element($a);
echo 'attrns=', xmlwriter_output_memory($a), "\n";
?>
--EXPECT--
xmlwriter_start_element_ns=1
xmlwriter_write_element_ns=1
xmlwriter_start_attribute_ns=1
xmlwriter_write_attribute_ns=1
<?xml version="1.0"?>
<ex:root xmlns:ex="http://example.com" ex:id="1"><ex:child xmlns:ex="http://example.com">x</ex:child></ex:root>

attrns=<root ex:id="1" xmlns:ex="http://example.com"/>
