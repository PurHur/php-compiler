--TEST--
stdlib strtolower()
--FILE--
<?php
echo strtolower(''), "\n";
echo strtolower('Hello'), "\n";
echo strtolower('WORLD'), "\n";
echo strtolower('MiXeD'), "\n";
echo strtolower('123'), "\n";
--EXPECT--

hello
world
mixed
123
