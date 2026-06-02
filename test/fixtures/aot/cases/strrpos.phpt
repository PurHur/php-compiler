--TEST--
AOT: strrpos() via LLVM (last match and offset)
--FILE--
<?php
echo strrpos('abcabc', 'abc'), "\n";
echo strrpos('hello', 'l', 3), "\n";
echo strrpos('abcabc', 'bc', -3), "\n";
echo strrpos('abcabc', 'bc', -1), "\n";
--EXPECT--
3
3
1
4
