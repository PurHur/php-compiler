--TEST--
stdlib strtr()
--FILE--
<?php
echo strtr('abc', 'a', 'A'), "\n";
echo strtr('baab', 'ab', '12'), "\n";
echo strtr('hello', 'lo', '12'), "\n";
echo strtr('same', '', 'x'), "\n";
echo strtr('aba', 'aa', '12'), "\n";
--EXPECT--
Abc
2112
he112
same
121
