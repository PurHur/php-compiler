--TEST--
stdlib count_chars() PHP 8 modes
--FILE--
<?php
$s = "hello\x00world";
echo count(count_chars($s, 0)), "\n";
echo count(count_chars($s, 1)), "\n";
echo count(count_chars($s, 2)), "\n";
echo bin2hex(count_chars($s, 3)), "\n";
echo strlen(count_chars($s, 4)), "\n";
$m1 = count_chars($s, 1);
echo $m1[0], " ", $m1[100], " ", $m1[108], " ", $m1[111], "\n";
--EXPECT--
256
8
248
006465686c6f7277
248
1 1 3 2
