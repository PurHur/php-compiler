<?php
/**
 * #20322 — procedural xmlwriter_start_cdata / start_dtd / write_dtd_* after #20049.
 * Zend: all function_exists true; VM before fix: false.
 */
$need = [
    'xmlwriter_start_cdata',
    'xmlwriter_end_cdata',
    'xmlwriter_start_dtd',
    'xmlwriter_end_dtd',
    'xmlwriter_write_dtd_element',
    'xmlwriter_write_dtd_attlist',
    'xmlwriter_start_dtd_entity',
    'xmlwriter_end_dtd_entity',
    'xmlwriter_write_dtd_entity',
];
foreach ($need as $fn) {
    echo $fn, '=', function_exists($fn) ? '1' : '0', "\n";
}

if (!function_exists('xmlwriter_start_cdata')) {
    echo "MISSING\n";
    exit(0);
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
