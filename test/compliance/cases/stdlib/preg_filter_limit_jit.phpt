--TEST--
JIT preg_filter() limit argument (issue #4079)
--FILE--
<?php
echo preg_filter('/a/', 'X', 'aaa', 2), "\n";
$in = ['baa', 'ccc'];
$out = preg_filter('/a/', 'X', $in, 1);
echo count($out), ':', implode(',', $out), "\n";
--EXPECT--
XXa
1:bXa
