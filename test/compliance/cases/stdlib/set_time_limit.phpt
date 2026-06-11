--TEST--
stdlib set_time_limit(), ignore_user_abort(), connection_aborted() CLI basics (#3242)
--FILE--
<?php
var_export(function_exists('set_time_limit'));
echo "\n";
var_export(function_exists('ignore_user_abort'));
echo "\n";
var_export(function_exists('connection_aborted'));
echo "\n";
var_export(set_time_limit(0));
echo "\n";
var_export(ignore_user_abort(true));
echo "\n";
var_export(ignore_user_abort(null));
echo "\n";
var_export(connection_aborted());
echo "\n";
--EXPECT--
true
true
true
true
0
1
0
