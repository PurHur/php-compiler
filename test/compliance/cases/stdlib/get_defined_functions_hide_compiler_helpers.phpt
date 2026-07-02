--TEST--
stdlib get_defined_functions() — hide __compiler_* ABI helpers (#15046)
--FILE--
<?php
$internal = get_defined_functions()['internal'];
var_export(function_exists('__compiler_is_superglobal_name'));
echo "\n";
var_export(in_array('__compiler_is_superglobal_name', $internal, true));
echo "\n";
--EXPECT--
false
false
