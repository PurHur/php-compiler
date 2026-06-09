--TEST--
stdlib get_cfg_var() reads php.ini compile-time values (issue #3205, #6119)
--FILE--
<?php
$val = get_cfg_var('display_errors');
echo ($val !== false && is_string($val)) ? "cfg_ok\n" : "cfg_fail\n";
echo get_cfg_var('nonexistent') === false ? "missing_false\n" : "missing_bad\n";
--EXPECT--
cfg_ok
missing_false
