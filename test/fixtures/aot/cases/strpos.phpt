--TEST--
AOT: strpos() via LLVM (numeric position and offset)
--FILE--
<?php
echo strpos('hello', 'll'), "\n";
echo strpos('hello', 'l', 3), "\n";
echo strpos('abc', 'bc', -1) == false ? "false\n" : "?\n";
echo stripos('abc', 'B', -1) == false ? "false\n" : "?\n";
echo strpos('abcdef', 'de', -4), "\n";
--EXPECT--
2
3
false
false
3
