--TEST--
stdlib fgetc() JIT
--FILE--
<?php
$path = 'test/compliance/cases/stdlib/fgetc_fixture.txt';
$fp = fopen($path, 'r');
echo fgetc($fp), "\n";
fclose($fp);
--EXPECT--
H
