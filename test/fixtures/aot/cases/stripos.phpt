--TEST--
AOT: stripos() via LLVM (case-insensitive position and offset)
--FILE--
<?php
echo stripos('Hello', 'LL'), "\n";
echo stripos('Hello', 'L', 3), "\n";
--EXPECT--
2
3
