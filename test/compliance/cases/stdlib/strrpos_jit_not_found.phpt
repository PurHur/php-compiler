--TEST--
stdlib strrpos() JIT not-found compares as false (==)
--FILE--
<?php
echo strrpos('hello', 'x') == false ? 'y' : 'n', "\n";
echo strrpos('abcabc', 'abc', 4) == false ? '1' : '0', "\n";
--EXPECT--
y
1
