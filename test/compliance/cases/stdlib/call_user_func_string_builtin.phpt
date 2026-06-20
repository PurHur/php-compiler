--TEST--
stdlib call_user_func() / call_user_func_array() string builtin callbacks (issue #10359)
--FILE--
<?php
declare(strict_types=1);

var_export(is_callable('strlen'));
echo "\n";
var_export(call_user_func('strlen', 'abc'));
echo "\n";
var_export(call_user_func_array('strlen', ['abc']));
echo "\n";
var_export(is_callable('not_a_real_function_xyz'));
echo "\n";
try {
    call_user_func('not_a_real_function_xyz');
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
true
3
3
false
call_user_func(): Argument #1 ($callback) must be a valid callback, function "not_a_real_function_xyz" not found or invalid function name
