--TEST--
stdlib strpos() JIT not-found compares as false (==)
--FILE--
<?php
echo strpos('hello', 'x') == false ? 'y' : 'n', "\n";
echo strpos('abc', 'z') == false ? '1' : '0', "\n";
--EXPECT--
y
1
