--TEST--
stdlib floatval() for strings (JIT)
--FILE--
<?php
echo floatval('3.14'), "\n";
echo floatval('-2.5'), "\n";
echo floatval(''), "\n";
--EXPECT--
3.14
-2.5
0
