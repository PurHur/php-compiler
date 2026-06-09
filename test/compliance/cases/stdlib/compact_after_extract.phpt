--TEST--
stdlib compact() after extract() — import-only names visible (#4826, basic_functions.c)
--FILE--
<?php
declare(strict_types=1);
$a = 1;
$b = 2;
$arr = ['a' => 9, 'c' => 3];
extract($arr, EXTR_SKIP);
var_export(compact('a', 'b', 'c'));
?>
--EXPECT--
array (
  'a' => 1,
  'b' => 2,
  'c' => 3,
)
