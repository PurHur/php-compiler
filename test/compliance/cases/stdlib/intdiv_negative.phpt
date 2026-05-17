--TEST--
stdlib intdiv with negative operands (truncates toward zero)
--FILE--
<?php
echo intdiv(7, -3), "\n";
echo intdiv(-7, 3), "\n";
echo intdiv(-7, -3), "\n";
--EXPECT--
-2
-2
2
