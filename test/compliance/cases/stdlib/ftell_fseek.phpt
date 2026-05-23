--TEST--
stdlib ftell() and fseek() on a file handle (issues #1190, #1191)
--FILE--
<?php
$path = 'test/compliance/cases/stdlib/fgets_fixture.txt';
$fp = fopen($path, 'r');
echo ftell($fp), "\n";
fseek($fp, 5);
echo ftell($fp), "\n";
echo fgets($fp, 4), "\n";
echo fseek($fp, 0, 0), "\n";
echo ftell($fp), "\n";
fclose($fp);
--EXPECT--
0
5
one
0
0
