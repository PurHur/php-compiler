<?php

$before = get_include_path();
echo 'before:', $before, "\n";

$rEmpty = set_include_path('');
echo 'ret_empty:', var_export($rEmpty, true), "\n";
echo 'after_empty:', get_include_path(), "\n";

$rFalse = set_include_path(false);
echo 'ret_false:', var_export($rFalse, true), "\n";
echo 'after_false:', get_include_path(), "\n";

$dir = sys_get_temp_dir() . '/phpc_inc_valid_' . getmypid();
@mkdir($dir);
$rValid = set_include_path($dir);
echo 'ret_valid:', var_export($rValid === $before, true), "\n";
echo 'after_valid:', get_include_path() === $dir ? 'ok' : 'bad', "\n";
set_include_path($before);
@rmdir($dir);
