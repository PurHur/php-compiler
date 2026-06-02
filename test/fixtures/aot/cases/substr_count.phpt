--TEST--
AOT: substr_count() via LLVM
--FILE--
<?php
echo substr_count('hello', 'l'), "\n";
echo substr_count('abcabc', 'abc'), "\n";
echo substr_count('hello', 'z'), "\n";
echo substr_count('banana', 'ana'), "\n";
echo substr_count('abcabc', 'bc', -1), "\n";
echo substr_count('abcabc', 'bc', -3), "\n";
echo substr_count('abcabc', 'bc', 0, -1), "\n";
--EXPECT--
2
2
0
1
0
1
1
