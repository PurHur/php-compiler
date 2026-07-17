--TEST--
xmlwriter procedural write_raw / start_pi / comment / write_dtd — #20049 (ext/xmlwriter/php_xmlwriter.c)
--FILE--
<?php
foreach ([
    'xmlwriter_write_raw',
    'xmlwriter_start_comment',
    'xmlwriter_end_comment',
    'xmlwriter_write_pi',
    'xmlwriter_start_pi',
    'xmlwriter_end_pi',
    'xmlwriter_write_dtd',
] as $fn) {
    echo $fn, '=', function_exists($fn) ? '1' : '0', "\n";
}

$w = xmlwriter_open_memory();
xmlwriter_start_document($w, '1.0');
xmlwriter_start_element($w, 'root');
xmlwriter_write_raw($w, '<raw/>');
xmlwriter_write_pi($w, 'php', 'echo 1');
xmlwriter_end_element($w);
xmlwriter_end_document($w);
echo xmlwriter_output_memory($w), "\n";

$c = xmlwriter_open_memory();
xmlwriter_start_comment($c);
xmlwriter_text($c, 'c');
xmlwriter_end_comment($c);
echo 'comment=', xmlwriter_output_memory($c), "\n";

$pi = xmlwriter_open_memory();
xmlwriter_start_pi($pi, 'php');
xmlwriter_text($pi, 'echo 2');
xmlwriter_end_pi($pi);
echo 'startpi=', xmlwriter_output_memory($pi), "\n";

$d = xmlwriter_open_memory();
xmlwriter_write_dtd($d, 'html', '-//W3C//DTD XHTML 1.0 Transitional//EN', 'http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd');
echo 'dtd=', xmlwriter_output_memory($d), "\n";
?>
--EXPECT--
xmlwriter_write_raw=1
xmlwriter_start_comment=1
xmlwriter_end_comment=1
xmlwriter_write_pi=1
xmlwriter_start_pi=1
xmlwriter_end_pi=1
xmlwriter_write_dtd=1
<?xml version="1.0"?>
<root><raw/><?php echo 1?></root>

comment=<!--c-->
startpi=<?php echo 2?>
dtd=<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
