--TEST--
stdlib strripos()
--FILE--
<?php
echo strripos('abcABC', 'a'), "\n";
echo strripos('abcABC', 'A'), "\n";
echo strripos('Hello', 'LL'), "\n";
echo strripos('abcabc', 'abc'), "\n";
echo strripos('hello', 'x') == false ? 'y' : 'n', "\n";
echo strripos('hello', 'l', 3), "\n";
echo strripos('abcabc', 'abc', 4) == false ? 'y' : 'n', "\n";
--EXPECT--
3
3
2
3
y
3
y
