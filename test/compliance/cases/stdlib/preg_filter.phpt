--TEST--
stdlib preg_filter() filters array and replaces scalars (issue #3250)
--FILE--
<?php
$in = ['a1', 'b2', 'c'];
$out = preg_filter('/\d/', '', $in);
echo implode(',', $out), "\n";

echo preg_filter('/(\d+)/', 'n$1', 'x5y'), "\n";
echo preg_filter('/\d/', '', 'abc') === null ? 'null' : 'bad', "\n";
echo function_exists('preg_filter') ? 'yes' : 'no', "\n";
--EXPECT--
a,b
xn5y
null
yes
