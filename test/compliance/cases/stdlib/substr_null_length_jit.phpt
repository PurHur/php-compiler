--TEST--
stdlib JIT: substr family null $length means "to end" (issue #4297)
--FILE--
<?php
echo substr('hello', 1, null), "\n";
echo substr_count('abcabcabc', 'abc', 0, null), "\n";
echo substr_compare('abcde', 'bc', 1, null), "\n";
echo substr_replace('abcdef', 'X', 2, null), "\n";
--EXPECT--
ello
3
1
abX
