--TEST--
stdlib array_walk() string builtin trim — return value ignored (ext/standard/array.c, #14830)
--FILE--
<?php
$arr = [1 => ' a '];
array_walk($arr, 'trim');
var_export($arr);
?>
--EXPECT--
array (
  1 => ' a ',
)
