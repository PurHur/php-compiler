--TEST--
stdlib settype(list, object) get_object_vars() numeric keys (#12042, ext/standard/type.c var.c)
--FILE--
<?php
$list = [1, 2, 3];
settype($list, 'object');
var_export(get_object_vars($list));
?>
--EXPECT--
array (
  0 => 1,
  1 => 2,
  2 => 3,
)
