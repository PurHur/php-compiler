--TEST--
AOT: strpos() via LLVM (numeric position, offset, not-found)
--FILE--
<?php
echo strpos('hello', 'll'), "\n";
echo strpos('hello', 'l', 3), "\n";
echo strpos('hello', 'x') === false ? 'y' : 'n', "\n";
--EXPECT--
2
3
y
