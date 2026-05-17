--TEST--
stdlib strval() for scalar values
--FILE--
<?php
echo strval(42), "\n";
echo strval(-3), "\n";
echo strval(1.5), "\n";
echo strval(true), "\n";
echo strval(false), "\n";
echo strval(null), "\n";
echo strval('hi'), "\n";
--EXPECT--
42
-3
1.5
1

hi
