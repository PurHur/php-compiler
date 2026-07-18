--TEST--
xmlwriter procedural start_cdata / start_dtd / write_dtd_* — #20322 (ext/xmlwriter/php_xmlwriter.c)
--FILE--
<?php
foreach ([
    'xmlwriter_start_cdata',
    'xmlwriter_end_cdata',
    'xmlwriter_start_dtd',
    'xmlwriter_end_dtd',
    'xmlwriter_write_dtd_element',
    'xmlwriter_write_dtd_attlist',
    'xmlwriter_start_dtd_entity',
    'xmlwriter_end_dtd_entity',
    'xmlwriter_write_dtd_entity',
] as $fn) {
    echo $fn, '=', function_exists($fn) ? '1' : '0', "\n";
}

$w = xmlwriter_open_memory();
xmlwriter_start_document($w, '1.0');
xmlwriter_start_element($w, 'root');
xmlwriter_start_cdata($w);
xmlwriter_text($w, 'x<y');
xmlwriter_end_cdata($w);
xmlwriter_end_element($w);
xmlwriter_end_document($w);
echo xmlwriter_output_memory($w), "\n";

$d = xmlwriter_open_memory();
xmlwriter_start_dtd($d, 'root');
xmlwriter_write_dtd_element($d, 'root', 'EMPTY');
xmlwriter_write_dtd_attlist($d, 'root', 'id CDATA #IMPLIED');
xmlwriter_start_dtd_entity($d, 'foo', false);
xmlwriter_text($d, 'bar');
xmlwriter_end_dtd_entity($d);
xmlwriter_write_dtd_entity($d, 'baz', 'qux');
xmlwriter_end_dtd($d);
echo xmlwriter_output_memory($d), "\n";
?>
--EXPECT--
xmlwriter_start_cdata=1
xmlwriter_end_cdata=1
xmlwriter_start_dtd=1
xmlwriter_end_dtd=1
xmlwriter_write_dtd_element=1
xmlwriter_write_dtd_attlist=1
xmlwriter_start_dtd_entity=1
xmlwriter_end_dtd_entity=1
xmlwriter_write_dtd_entity=1
<?xml version="1.0"?>
<root><![CDATA[x<y]]></root>

<!DOCTYPE root [<!ELEMENT root EMPTY><!ATTLIST root id CDATA #IMPLIED><!ENTITY foo "bar"><!ENTITY baz "qux">]>
