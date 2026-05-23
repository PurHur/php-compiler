--TEST--
stdlib atan2()
--FILE--
<?php
echo atan2(0, 1), "\n";
echo intval(atan2(1, 1) * 1000), "\n";
echo intval(atan2(1, 0) * 1000), "\n";
echo intval(atan2(3.0, 4.0) * 1000), "\n";
--EXPECT--
0
785
1570
643
