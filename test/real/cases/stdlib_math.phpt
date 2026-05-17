--TEST--
Integration: round, sqrt, gettype with existing stdlib
--FILE--
<?php
echo round(sqrt(10)), "\n";
echo gettype(round(2.6)), "\n";
echo intval(round(sqrt(2))), "\n";
--EXPECT--
3
double
1
