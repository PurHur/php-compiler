--TEST--
stdlib var_export() near-round float shortening (#15044, ext/standard/var.c)
--FILE--
<?php
$value = round(1.55, 1, PHP_ROUND_HALF_UP);
echo var_export($value, true), "\n";
echo var_export(1.6000000000000001, true), "\n";
--EXPECT--
1.6
1.6
