--TEST--
stdlib substr_count()
--FILE--
<?php
echo substr_count('hello', 'l'), "\n";
echo substr_count('abcabc', 'abc'), "\n";
echo substr_count('hello', 'z'), "\n";
echo substr_count('banana', 'ana'), "\n";
echo substr_count('hello world', 'o', 4), "\n";
echo substr_count('abcabcabc', 'abc', 0, 6), "\n";
--EXPECT--
2
2
0
1
2
2
