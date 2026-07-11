--TEST--
stdlib array_diff_assoc()/array_intersect_assoc() loose value compare (#13097)
--FILE--
<?php
var_export(array_diff_assoc([0 => 1], [0 => true]));
echo "\n";
var_export(array_intersect_assoc([0 => 1], [0 => true]));
echo "\n";
--EXPECT--
array (
)
array (
  0 => 1,
)
