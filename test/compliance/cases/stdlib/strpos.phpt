--TEST--
stdlib strpos()
--FILE--
<?php
echo strpos('hello', 'll'), "\n";
echo strpos('hello', 'x') == false ? 'y' : 'n', "\n";
echo strpos('hello', 'l', 3), "\n";
--EXPECT--
2
y
3
