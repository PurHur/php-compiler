--TEST--
Language JIT: function_exists/is_callable true for exit/die on PHP 8.4 profile (#20575)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo 'function_exists(exit)=', var_export(function_exists('exit'), true), "\n";
echo 'function_exists(die)=', var_export(function_exists('die'), true), "\n";
echo 'is_callable(exit)=', var_export(is_callable('exit'), true), "\n";
echo 'is_callable(die)=', var_export(is_callable('die'), true), "\n";
echo 'function_exists(eval)=', var_export(function_exists('eval'), true), "\n";
--EXPECT--
function_exists(exit)=true
function_exists(die)=true
is_callable(exit)=true
is_callable(die)=true
function_exists(eval)=false
