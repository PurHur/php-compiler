--TEST--
stdlib var_export() after settype(list, object) — no fatal (#12042, ext/standard/var.c)
--FILE--
<?php
$list = [1, 2, 3];
settype($list, 'object');
echo var_export($list, true);
?>
--EXPECT--
(object) array (
  0 => 1,
  1 => 2,
  2 => 3,
)
