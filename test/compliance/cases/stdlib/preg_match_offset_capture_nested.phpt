--TEST--
stdlib preg_match() PREG_OFFSET_CAPTURE — nested capture group numbering (#14574, ext/pcre/php_pcre.c)
--FILE--
<?php
preg_match('/(a(b))/', 'ab', $m, PREG_OFFSET_CAPTURE);
echo $m[1][0], ':', $m[1][1], "\n";
echo $m[2][0], ':', $m[2][1], "\n";
preg_match_all('/(\d)/', 'a1b2', $all, PREG_OFFSET_CAPTURE);
echo $all[1][0][0], ':', $all[1][0][1], "\n";
echo $all[1][1][0], ':', $all[1][1][1], "\n";
--EXPECT--
ab:0
b:1
1:1
2:3
