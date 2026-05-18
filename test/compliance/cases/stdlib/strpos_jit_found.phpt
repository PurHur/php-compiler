--TEST--
stdlib strpos() JIT found offset
--FILE--
<?php
echo strpos('hello', 'll'), "\n";
echo strpos('hello', 'l', 3), "\n";
--EXPECT--
2
3
