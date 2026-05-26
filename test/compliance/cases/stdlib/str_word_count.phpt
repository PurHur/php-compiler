--TEST--
stdlib str_word_count() word count and lists (#2382)
--FILE--
<?php
$s = "Hello fri3nd, you are looking good today!";
echo str_word_count($s), "\n";
$w = str_word_count($s, 1);
echo count($w), "\n";
echo $w[0], "\n";
echo $w[1], "\n";
$p = str_word_count($s, 2);
echo $p[0], "\n";
echo $p[6], "\n";
echo $p[35], "\n";
echo str_word_count("a b c"), "\n";
echo str_word_count(""), "\n";
echo str_word_count("don" . "'" . "t"), "\n";
--EXPECT--
8
8
Hello
fri
Hello
fri
today
3
0
1
