--TEST--
AOT preg_filter() array filter (issue #3250)
--FILE--
<?php
$in = ['p1', 'q2', 'r'];
$out = preg_filter('/\d/', '', $in);
echo count($out), ':', implode(',', $out), "\n";
--EXPECT--
2:p,q
