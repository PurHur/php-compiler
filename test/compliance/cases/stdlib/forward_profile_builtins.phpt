--TEST--
stdlib forward profile builtins str_increment/json_validate registered (#14518)
--FILE--
<?php
echo function_exists('json_validate') ? 'jv=yes' : 'jv=no', "\n";
echo function_exists('str_increment') ? 'si=yes' : 'si=no', "\n";
echo function_exists('str_decrement') ? 'sd=yes' : 'sd=no', "\n";
echo str_increment('a'), "\n";
echo json_validate('{}') ? 'valid' : 'invalid', "\n";
?>
--EXPECT--
jv=yes
si=yes
sd=yes
b
valid
