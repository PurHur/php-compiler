--TEST--
AOT preg_match named capture populates $matches (#28611)
--FILE--
<?php
preg_match('/(?<n>\d+)/', 'a12', $m);
echo ($m['n'] ?? 'MISSING'), "\n";
echo ($m[0] ?? 'MISSING0'), ' ', ($m[1] ?? 'MISSING1'), "\n";
echo isset($m['n']) ? 'named=1' : 'named=0', "\n";
--EXPECT--
12
12 12
named=1
