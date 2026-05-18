--TEST--
stdlib strtoupper()
--FILE--
<?php
echo strtoupper(''), "\n";
echo strtoupper('hello'), "\n";
echo strtoupper('World'), "\n";
echo strtoupper('MiXeD'), "\n";
echo strtoupper('123'), "\n";
--EXPECT--

HELLO
WORLD
MIXED
123
