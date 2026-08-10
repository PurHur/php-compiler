--TEST--
mb_detect_order(null) returns current order under strict_types (#29920)
--FILE--
<?php
declare(strict_types=1);
error_reporting(E_ALL);
echo var_export(mb_detect_order(null), true), "\n";
echo mb_detect_order('UTF-8,ASCII') ? "set\n" : "set-fail\n";
echo var_export(mb_detect_order(null), true), "\n";
echo mb_detect_order('ASCII,UTF-8') ? "reset\n" : "reset-fail\n";
echo var_export(mb_detect_order(), true), "\n";
?>
--EXPECT--
array (
  0 => 'ASCII',
  1 => 'UTF-8',
)
set
array (
  0 => 'UTF-8',
  1 => 'ASCII',
)
reset
array (
  0 => 'ASCII',
  1 => 'UTF-8',
)
