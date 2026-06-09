--TEST--
stdlib levenshtein() negative/zero custom costs JIT (#4798)
--JIT--
--FILE--
<?php
echo levenshtein('a', 'b', -1, 1, 1), "\n";
echo levenshtein('a', 'b', 0, 1, 1), "\n";
echo levenshtein('abc', 'ab', 0, 1, 1), "\n";
--EXPECT--
0
1
1
