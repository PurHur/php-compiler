--TEST--
stdlib preg_match() PREG_OFFSET_CAPTURE and subject offset (issue #3148)
--FILE--
<?php
preg_match('/(a)/', 'abc', $m, PREG_OFFSET_CAPTURE);
echo $m[0][0], ':', $m[0][1], "\n";
echo $m[1][0], ':', $m[1][1], "\n";

preg_match('/(a)/', 'xxabc', $m2, PREG_OFFSET_CAPTURE, 2);
echo $m2[0][0], ':', $m2[0][1], "\n";

preg_match('/(a)(b)?/', 'a', $m3, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);
echo $m3[0][0], ':', $m3[0][1], "\n";
echo $m3[2][1], "\n";

preg_match_all('/(\d)/', 'a1b22', $all, PREG_PATTERN_ORDER | PREG_OFFSET_CAPTURE);
echo $all[1][0][0], ':', $all[1][0][1], "\n";
echo $all[1][2][0], ':', $all[1][2][1], "\n";
--EXPECT--
a:0
a:0
a:2
a:0
-1
1:1
2:4
