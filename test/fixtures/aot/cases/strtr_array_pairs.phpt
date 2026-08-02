--TEST--
AOT strtr() replace_pairs array form (#27056)
--FILE--
<?php
echo strtr('hi', ['h' => 'H', 'i' => 'I']), "\n";
echo strtr('baab', ['a' => 'o']), "\n";
echo strtr('hello', ['he' => 'HE', 'l' => 'L']), "\n";
--EXPECT--
HI
boob
HELLo
