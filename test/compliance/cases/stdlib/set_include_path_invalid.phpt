--TEST--
stdlib set_include_path() — empty/false rejected (issue #12165, ext/standard/basic_functions.c)
--FILE--
<?php
$before = get_include_path();
$r1 = set_include_path('');
$r2 = set_include_path(false);
$dir = sys_get_temp_dir() . '/phpc_inc_valid_' . getmypid();
mkdir($dir);
$r3 = set_include_path($dir);
set_include_path($before);
rmdir($dir);
echo var_export($r1, true), "\n";
echo var_export($r2, true), "\n";
echo get_include_path() === $before ? "unchanged\n" : "changed\n";
echo var_export($r3 === $before, true), "\n";
--EXPECT--
false
false
unchanged
true
--CREDITS--
PurHur/php-compiler issue #12165
