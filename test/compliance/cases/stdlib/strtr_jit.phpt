--TEST--
stdlib strtr() JIT
--FILE--
<?php
echo strtr('abc', 'a', 'A'), "\n";
echo strtr('baab', 'ab', '12'), "\n";
--EXPECT--
Abc
2112
