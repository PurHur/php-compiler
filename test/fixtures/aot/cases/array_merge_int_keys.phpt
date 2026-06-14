--TEST--
AOT: array_merge() integer-key reindex-append (#4231)
--FILE--
<?php
$r = array_merge([0 => 'a'], [1 => 'b']);
echo count($r), "\n";
echo $r[0], "\n";
echo $r[1], "\n";
--EXPECT--
2
a
b
