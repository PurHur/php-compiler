--TEST--
AOT: strrpos() via LLVM (last match and offset)
--FILE--
<?php
echo strrpos('abcabc', 'abc'), "\n";
echo strrpos('hello', 'l', 3), "\n";
--EXPECT--
3
3
