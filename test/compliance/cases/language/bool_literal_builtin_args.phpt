--TEST--
Language: builtin calls with bool literal arguments bind correctly (#9324, zend_compile.c)
--FILE--
<?php
var_export(array_slice([0, 1, 2, 3], 1, 2, true));
echo "\n";
var_export(array_chunk([1, 2, 3], 2, true));
echo "\n";
var_export(in_array(1, [1, 2, 3], true));
echo "\n";
?>
--EXPECT--
array (
  1 => 1,
  2 => 2,
)
array (
  0 => 
  array (
    0 => 1,
    1 => 2,
  ),
  1 => 
  array (
    2 => 3,
  ),
)
true
