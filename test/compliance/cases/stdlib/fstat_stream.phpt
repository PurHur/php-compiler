--TEST--
stdlib fstat() on stream resource
--FILE--
<?php
$path = 'test/compliance/cases/stdlib/readfile_fixture/data.txt';
echo function_exists('fstat') ? 'yes' : 'no', "\n";
$fp = fopen($path, 'r');
$s = fstat($fp);
echo ($s !== false && $s['size'] > 0) ? 'size' : 'fail', "\n";
echo ($s !== false && $s[7] === $s['size']) ? 'idx' : 'noidx', "\n";
fclose($fp);
--EXPECT--
yes
size
idx
