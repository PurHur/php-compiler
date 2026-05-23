--TEST--
AOT strtr()
--FILE--
<?php
echo strtr('abc', 'a', 'A'), "\n";
echo strtr('baab', 'ab', '12'), "\n";
echo strtr('hello', 'lo', '12'), "\n";
--EXPECT--
Abc
2112
he112
