--TEST--
stdlib floatval() for booleans
--FILE--
<?php
echo floatval(true), "\n";
echo floatval(false), "\n";
--EXPECT--
1
0
