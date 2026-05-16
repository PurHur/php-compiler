--TEST--
stdlib abs() for integers
--FILE--
<?php
echo abs(0), "\n";
echo abs(42), "\n";
echo abs(-7), "\n";
--EXPECT--
0
42
7
