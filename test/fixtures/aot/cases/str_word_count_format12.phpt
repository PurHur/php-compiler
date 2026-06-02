--TEST--
AOT: str_word_count() formats 1 and 2 (#3584)
--FILE--
<?php
$w = str_word_count('a b c', 1);
echo count($w), "\n";
echo $w[0], "\n";
$m = str_word_count('a b', 2);
echo $m[0], "\n";
echo $m[2], "\n";
--EXPECT--
3
a
a
b
