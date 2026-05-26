--TEST--
AOT: levenshtein() (#2406)
--FILE--
<?php
echo levenshtein('kitten', 'sitting'), "\n";
echo levenshtein('abc', 'abc'), "\n";
echo levenshtein('a', 'b', 5, 5, 5), "\n";
--EXPECT--
3
0
5
