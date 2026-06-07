--TEST--
JIT: fgetcsv() via StringFgetcsvJit (issue #1192, #6750)
--FILE--
<?php
$path = 'test/compliance/cases/stdlib/fgetcsv_fixture.csv';
$fp = fopen($path, 'r');
$row1 = fgetcsv($fp);
echo $row1[0], '-', $row1[1], '-', $row1[2], "\n";
$row2 = fgetcsv($fp);
echo $row2[0], '-', $row2[1], '-', $row2[2], "\n";
fclose($fp);
--EXPECT--
a-b-c
hello-world-x

