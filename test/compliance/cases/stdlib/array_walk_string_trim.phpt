--TEST--
stdlib array_walk() string builtin — return value ignored (#14830)
--FILE--
<?php
$arr = array(1 => ' a ');
array_walk($arr, 'trim');
var_export($arr);
?>
--EXPECT--
array (
  1 => ' a ',
)
