--TEST--
AOT: strval() for scalars
--FILE--
<?php
echo strval(42), "\n";
echo strval(true), "\n";
echo strval(false), "\n";
echo strval(null), "\n";
echo strval('hi'), "\n";
--EXPECTF--
42
1

hi
