--TEST--
JIT mb_detect_order(null) returns current order under strict_types (#29920)
--JIT--
--FILE--
<?php
declare(strict_types=1);
error_reporting(E_ALL);
echo var_export(mb_detect_order(null), true), "\n";
?>
--EXPECT--
array (
  0 => 'ASCII',
  1 => 'UTF-8',
)
