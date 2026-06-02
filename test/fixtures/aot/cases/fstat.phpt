--TEST--
AOT: fstat() on open stream handle
--FILE--
<?php
$path = 'test/compliance/cases/stdlib/readfile_fixture/data.txt';
$fp = fopen($path, 'r');
$s = fstat($fp);
echo ($s !== false && $s['size'] > 0) ? 'size' : 'fail', "\n";
echo ($s !== false && $s[7] === $s['size']) ? 'idx' : 'noidx', "\n";
fclose($fp);
--EXPECT--
size
idx
