--TEST--
JIT: str_word_count() format 1 word list (#3584)
--FILE--
<?php
$w = str_word_count('hello world test', 1);
echo count($w), "\n";
echo $w[0], "\n";
echo $w[1], "\n";
echo $w[2], "\n";
--EXPECT--
3
hello
world
test
