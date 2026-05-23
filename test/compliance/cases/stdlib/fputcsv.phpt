--TEST--
stdlib fputcsv() writes CSV rows to a file handle (issue #1193)
--FILE--
<?php
$path = sys_get_temp_dir() . '/phpc_fputcsv_test.csv';
$fp = fopen($path, 'w');
$written = fputcsv($fp, ['a', 'b', 'c']);
echo $written === false ? 'fail' : 'ok', "\n";
$written = fputcsv($fp, ['hello', 'world', 'x']);
fclose($fp);
$fp = fopen($path, 'r');
$read1 = fgetcsv($fp);
echo $read1[0], '-', $read1[1], '-', $read1[2], "\n";
$read2 = fgetcsv($fp);
echo $read2[0], '-', $read2[1], '-', $read2[2], "\n";
fclose($fp);
unlink($path);
--EXPECT--
ok
a-b-c
hello-world-x
