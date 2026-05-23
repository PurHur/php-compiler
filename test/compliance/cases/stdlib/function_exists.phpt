--TEST--
stdlib function_exists() VM (issue #1216)
--FILE--
<?php
function user_fn_exists_test(): void
{
}
echo function_exists('strlen') ? "1\n" : "0\n";
echo function_exists('not_a_real_builtin_xyz') ? "1\n" : "0\n";
echo function_exists('user_fn_exists_test') ? "1\n" : "0\n";
--EXPECT--
1
0
1
