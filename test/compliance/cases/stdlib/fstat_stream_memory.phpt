--TEST--
stdlib fstat() on php://memory — size after write (#10460, ext/standard/filestat.c)
--FILE--
<?php
$handle = fopen('php://memory', 'r+');
fwrite($handle, 'abc');
rewind($handle);
$stat = fstat($handle);
echo is_array($stat) ? 'array' : 'fail', "\n";
echo (int) ($stat['size'] ?? -1), "\n";
echo ($stat !== false && $stat[7] === $stat['size']) ? 'idx' : 'noidx', "\n";
fclose($handle);
--EXPECT--
array
3
idx
