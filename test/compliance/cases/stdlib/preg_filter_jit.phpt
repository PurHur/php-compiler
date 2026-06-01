--TEST--
JIT preg_filter() array filter (issue #3250)
--FILE--
<?php
$in = ['x1', 'y2', 'z'];
$out = preg_filter('/\d/', '', $in);
echo count($out), ':', implode(',', $out), "\n";
--EXPECT--
2:x,y
