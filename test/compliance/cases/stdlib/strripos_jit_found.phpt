--TEST--
stdlib strripos() JIT — found and not found
--FILE--
<?php
echo strripos('abcABC', 'a'), "\n";
echo strripos('hello', 'x') == false ? 'y' : 'n', "\n";
--EXPECT--
3
y
