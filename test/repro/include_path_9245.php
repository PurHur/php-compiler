<?php
$old = get_include_path();
set_include_path($old . PATH_SEPARATOR . '/tmp');
var_export(str_contains(get_include_path(), '/tmp'));
echo "\n";
restore_include_path();
var_export(get_include_path() === $old);
echo "\n";
var_export(stream_resolve_include_path('compile_driver.php') !== false);
echo "\n";
