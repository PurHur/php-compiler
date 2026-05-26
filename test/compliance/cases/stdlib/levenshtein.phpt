--TEST--
stdlib levenshtein()
--FILE--
<?php
echo levenshtein('kitten', 'sitting'), "\n";
echo levenshtein('abc', 'abc'), "\n";
echo levenshtein('', 'abc'), "\n";
echo levenshtein('a', 'b', 5, 5, 5), "\n";
echo levenshtein(str_repeat('a', 256), 'b'), "\n";
--EXPECT--
3
0
3
5
-1
