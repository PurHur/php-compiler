--TEST--
stdlib stat() JIT
--FILE--
<?php
$path = 'test/compliance/cases/stdlib/readfile_fixture/data.txt';
$s = stat($path);
echo ($s !== false) ? $s['size'] : 'fail', "\n";
echo ($s !== false && $s[7] === $s['size']) ? 'idx' : 'noidx', "\n";
$missing = stat('test/compliance/cases/stdlib/stat_missing_xyz.txt');
echo $missing === false ? 'false' : 'bad', "\n";
--EXPECT--
15
idx
false
