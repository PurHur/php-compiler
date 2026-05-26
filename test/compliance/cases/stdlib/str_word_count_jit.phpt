--TEST--
JIT: str_word_count() format 0 (#2382)
--FILE--
<?php
$s = "Hello fri3nd, you are looking good today!";
echo str_word_count($s), "\n";
echo str_word_count("a b c"), "\n";
echo str_word_count(""), "\n";
echo str_word_count("don" . "'" . "t"), "\n";
echo str_word_count("img10 img2 img1"), "\n";
--EXPECT--
8
3
0
1
3
