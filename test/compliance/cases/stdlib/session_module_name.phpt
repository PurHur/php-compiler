--TEST--
stdlib session_module_name() get and set (#5749, ext/session/session.c)
--FILE--
<?php
$m0 = session_module_name();
$m1 = session_module_name('files');
$m2 = session_module_name();
var_export(function_exists('session_module_name'));
echo "\n";
echo $m0, "\n", $m1, "\n", $m2, "\n";
--EXPECT--
true
files
files
files
