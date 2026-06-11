--TEST--
stdlib mktime() — JIT lowering
--JIT--
--FILE--
<?php
echo mktime(22, 13, 20, 11, 14, 2023), "\n";
--EXPECT--
1700000000
