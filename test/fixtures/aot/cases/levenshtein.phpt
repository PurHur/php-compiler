--TEST--
AOT: levenshtein() (#2406)
--FILE--
<?php
echo levenshtein('kitten', 'sitting'), "\n";
echo levenshtein('', 'abc'), "\n";
echo levenshtein('abc', 'ab', 2, 1, 1), "\n";
--EXPECT--
3
3
1
