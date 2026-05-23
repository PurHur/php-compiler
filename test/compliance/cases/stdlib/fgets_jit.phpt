--TEST--
JIT: fgets() via __compiler_fgets (issue #1187)
--FILE--
<?php
$path = 'test/compliance/cases/stdlib/fgets_fixture.txt';
$fp = fopen($path, 'r');
echo fgets($fp);
fclose($fp);
--EXPECT--
line one
