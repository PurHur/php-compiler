<?php
/**
 * #20049 — procedural xmlwriter_write_raw / start_pi / write_dtd* after #19514.
 * Zend: all function_exists true; VM before fix: false / MISSING.
 */
$need = [
    'xmlwriter_write_raw',
    'xmlwriter_start_comment',
    'xmlwriter_end_comment',
    'xmlwriter_write_pi',
    'xmlwriter_start_pi',
    'xmlwriter_end_pi',
    'xmlwriter_write_dtd',
];
foreach ($need as $fn) {
    echo $fn, '=', function_exists($fn) ? '1' : '0', "\n";
}

$w = new XMLWriter();
$w->openMemory();
$w->startDocument();
$w->writeRaw('<x/>');
echo 'oop_raw=', (strpos($w->outputMemory(), '<x/>') !== false) ? '1' : '0', "\n";

if (!function_exists('xmlwriter_write_raw')) {
    echo "proc_raw=MISSING\n";
    exit(0);
}

$p = xmlwriter_open_memory();
xmlwriter_start_document($p);
xmlwriter_write_raw($p, '<x/>');
echo 'proc_raw=', (strpos(xmlwriter_output_memory($p), '<x/>') !== false) ? '1' : '0', "\n";

$c = xmlwriter_open_memory();
xmlwriter_start_comment($c);
xmlwriter_text($c, 'hi');
xmlwriter_end_comment($c);
echo 'proc_comment=', xmlwriter_output_memory($c), "\n";

$pi = xmlwriter_open_memory();
xmlwriter_start_element($pi, 'r');
xmlwriter_write_pi($pi, 'php', 'echo 1');
xmlwriter_end_element($pi);
echo 'proc_pi=', xmlwriter_output_memory($pi), "\n";

$d = xmlwriter_open_memory();
xmlwriter_write_dtd($d, 'r', null, null, '<!ELEMENT r EMPTY>');
echo 'proc_dtd=', xmlwriter_output_memory($d), "\n";
