--TEST--
stdlib addcslashes() stripcslashes() substr_replace() JIT (issue #3356)
--FILE--
<?php
echo addcslashes('test', 'a..z'), "\n";
echo stripcslashes('\\x41'), "\n";
echo substr_replace('abcdef', 'X', -3, 2), "\n";
--EXPECT--
\t\e\s\t
A
abcXf
