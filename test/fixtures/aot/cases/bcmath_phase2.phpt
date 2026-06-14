--TEST--
AOT: bcmod/bcpow/bcsqrt smoke (#6042)
--FILE--
<?php
echo bcmod('10', '3'), "\n";
echo bcpow('2', '8'), "\n";
echo bcsqrt('4'), "\n";
--EXPECT--
1
256
2
