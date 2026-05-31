--TEST--
AOT: get_declared_functions() user bucket (issue #3739)
--FILE--
<?php
function declared_user_fn() {}
$funcs = get_declared_functions();
echo array_key_exists('internal', $funcs) && array_key_exists('user', $funcs) ? '1' : '0';
echo in_array('declared_user_fn', $funcs['user'], true) ? '1' : '0';
echo in_array('strlen', $funcs['internal'], true) ? '1' : '0';
echo "\n";
--EXPECT--
111
