--TEST--
stdlib strrpos()
--FILE--
<?php
echo strrpos('abcabc', 'abc'), "\n";
echo strrpos('hello', 'll'), "\n";
echo strrpos('hello', 'x') == false ? 'y' : 'n', "\n";
echo strrpos('hello', 'l', 3), "\n";
echo strrpos('abcabc', 'abc', 4) == false ? 'y' : 'n', "\n";
--EXPECT--
3
2
y
3
y
