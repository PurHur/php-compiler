--TEST--
JIT: levenshtein() (#2406)
--FILE--
<?php
echo levenshtein('kitten', 'sitting'), "\n";
echo levenshtein('', 'xyz'), "\n";
echo levenshtein('foo', 'bar'), "\n";
--EXPECT--
3
3
3
