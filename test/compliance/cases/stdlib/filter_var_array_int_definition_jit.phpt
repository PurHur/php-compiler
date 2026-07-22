--TEST--
stdlib filter_var_array() int filter-ID overload JIT (#21937)
--FILE--
<?php
var_export(filter_var_array(['a' => '1', 'b' => 'x'], FILTER_VALIDATE_INT));
echo "\n";
--EXPECT--
array (
  'a' => 1,
  'b' => false,
)
