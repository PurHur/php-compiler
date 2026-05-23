--TEST--
stdlib atan2() JIT
--FILE--
<?php
echo atan2(0, 1), "\n";
echo intval(atan2(1, 1) * 1000), "\n";
--EXPECT--
0
785
