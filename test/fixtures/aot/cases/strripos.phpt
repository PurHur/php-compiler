--TEST--
AOT: strripos() via LLVM (case-insensitive reverse position and offset)
--FILE--
<?php
echo strripos('abcABC', 'a'), "\n";
echo strripos('Hello', 'LL'), "\n";
echo strripos('hello', 'l', 3), "\n";
--EXPECT--
3
2
3
