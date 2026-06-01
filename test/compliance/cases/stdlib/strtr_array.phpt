--TEST--
stdlib strtr() replace_pairs array form
--FILE--
<?php
echo strtr('baab', ['a' => 'o']), "\n";
echo strtr('abab', ['ab' => 'X', 'b' => 'Y']), "\n";
echo strtr('abc', ['a' => 'A']), "\n";
--EXPECT--
boob
XX
Abc
