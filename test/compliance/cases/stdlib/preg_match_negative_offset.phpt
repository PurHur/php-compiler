--TEST--
stdlib preg_match()/preg_match_all() negative $offset (#16513, ext/pcre/php_pcre.c)
--FILE--
<?php
preg_match('/(\w+)/', 'abc', $m, PREG_OFFSET_CAPTURE, -1);
echo $m[0][0], ':', $m[0][1], "\n";
echo $m[1][0], ':', $m[1][1], "\n";

preg_match_all('/a/', 'banana', $all, PREG_OFFSET_CAPTURE, -1);
echo $all[0][0][0], ':', $all[0][0][1], "\n";

preg_match('/(a)/', 'xxabc', $m2, PREG_OFFSET_CAPTURE, 2);
echo $m2[0][0], ':', $m2[0][1], "\n";
--EXPECT--
c:2
c:2
a:5
a:2
