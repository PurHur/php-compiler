--TEST--
stdlib intval() truncates floats toward zero
--FILE--
<?php
echo intval(42), "\n";
echo intval(-7), "\n";
echo intval(9.9), "\n";
echo intval(-9.9), "\n";
--EXPECT--
42
-7
9
-9
