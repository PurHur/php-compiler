--TEST--
stdlib strpos() JIT not-found is false under ===
--FILE--
<?php
echo strpos('hello', 'x') === false ? 'y' : 'n', "\n";
echo strpos('abc', 'z') === false ? '1' : '0', "\n";
--EXPECT--
y
1
