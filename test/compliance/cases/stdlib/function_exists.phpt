--TEST--
stdlib function_exists() VM (issue #1216)
--FILE--
<?php
function user_fn() {
    return 1;
}
echo function_exists('strlen') ? '1' : '0', "\n";
echo function_exists('missing_fn_xyz') ? '1' : '0', "\n";
echo function_exists('user_fn') ? '1' : '0', "\n";
--EXPECT--
1
0
1
