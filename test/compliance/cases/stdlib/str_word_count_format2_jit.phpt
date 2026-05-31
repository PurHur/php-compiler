--TEST--
JIT: str_word_count() format 2 offset map (#3584)
--FILE--
<?php
$p = str_word_count('a b', 2);
echo $p[0], "\n";
echo $p[2], "\n";
--EXPECT--
a
b
