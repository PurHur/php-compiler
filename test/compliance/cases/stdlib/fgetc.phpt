--TEST--
stdlib fgetc() reads one byte from a file handle
--FILE--
<?php
$path = 'test/compliance/cases/stdlib/fgetc_fixture.txt';
$fp = fopen($path, 'r');
$c = fgetc($fp);
echo $c, "\n";
$d = fgetc($fp);
echo $d, "\n";
$e = fgetc($fp);
echo strlen($e), "\n";
fclose($fp);
--EXPECT--
H
i
0
