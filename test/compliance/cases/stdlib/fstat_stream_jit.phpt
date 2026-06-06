--TEST--
JIT: fstat() on stream resource via stream path + stat() (issues #3482, #6764)
--FILE--
<?php
$path = 'test/compliance/cases/stdlib/readfile_fixture/data.txt';
$fp = fopen($path, 'r');
$s = fstat($fp);
echo ($s !== false) ? $s['size'] : 'fail', "\n";
echo ($s !== false && $s[7] === $s['size']) ? 'idx' : 'noidx', "\n";
fclose($fp);
--EXPECT--
15
idx
