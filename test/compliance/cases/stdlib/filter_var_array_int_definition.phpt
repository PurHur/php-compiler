--TEST--
stdlib filter_var_array()/filter_input_array() int filter-ID overload (#21937, ext/filter/filter.c)
--GET--
a=1&b=x
--FILE--
<?php
var_export(filter_var_array(['a' => '1', 'b' => 'x'], FILTER_VALIDATE_INT));
echo "\n";
var_export(filter_input_array(INPUT_GET, FILTER_VALIDATE_INT));
echo "\n";
var_export(filter_var_array(['a' => '1'], ['a' => FILTER_VALIDATE_INT]));
echo "\n";
--EXPECT--
array (
  'a' => 1,
  'b' => false,
)
array (
  'a' => 1,
  'b' => false,
)
array (
  'a' => 1,
)
