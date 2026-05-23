--TEST--
JIT: ftell() and fseek() via __compiler_ftell / __compiler_fseek (issues #1190, #1191)
--FILE--
<?php
$path = 'test/compliance/cases/stdlib/fgets_fixture.txt';
$fp = fopen($path, 'r');
fseek($fp, 5);
echo ftell($fp), "\n";
echo fgets($fp, 4), "\n";
fclose($fp);
--EXPECT--
5
one
