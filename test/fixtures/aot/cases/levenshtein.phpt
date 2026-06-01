--TEST--
AOT: levenshtein() (#2406)
--FILE--
<?php
echo levenshtein('kitten', 'sitting'), "\n";
echo levenshtein('', 'abc'), "\n";
echo levenshtein('abc', 'ab', 2, 1, 1), "\n";
echo levenshtein(str_repeat('a', 300), str_repeat('b', 300)), "\n";
--EXPECT--
3
3
1
300
