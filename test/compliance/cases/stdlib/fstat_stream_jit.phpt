--TEST--
JIT: fstat() on stream resource via __phpc_stream_path + stat (issue #3482)
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
