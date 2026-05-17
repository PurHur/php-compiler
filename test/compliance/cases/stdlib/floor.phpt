--TEST--
stdlib floor() for integers and floats
--FILE--
<?php
echo floor(3), "\n";
echo floor(-3), "\n";
echo floor(2.9), "\n";
echo floor(-2.1), "\n";
--EXPECT--
3
-3
2
-3
