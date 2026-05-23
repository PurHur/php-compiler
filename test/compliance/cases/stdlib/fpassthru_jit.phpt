--TEST--
JIT: fpassthru() via __compiler_fpassthru (issue #1194)
--FILE--
<?php
$path = 'test/compliance/cases/stdlib/fpassthru_fixture/data.txt';
$h = fopen($path, 'r');
$n = fpassthru($h);
fclose($h);
echo $n, "\n";
--EXPECT--
passthru bytes
15
