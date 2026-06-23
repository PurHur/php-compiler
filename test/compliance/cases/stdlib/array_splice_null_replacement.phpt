--TEST--
stdlib array_splice() explicit null replacement — no NULL element (#10589, ext/standard/array.c)
--FILE--
<?php
$a = [1, 2, 3, 4];
array_splice($a, -3, 2, null);
var_export($a);
?>
--EXPECT--
array (
  0 => 1,
  1 => 4,
)
