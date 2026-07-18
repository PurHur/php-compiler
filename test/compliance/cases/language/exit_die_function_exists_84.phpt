--TEST--
Language: function_exists/is_callable true for exit/die on PHP 8.4 profile (#20575, zend_builtin_functions.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo 'function_exists(exit)=', var_export(function_exists('exit'), true), "\n";
echo 'function_exists(die)=', var_export(function_exists('die'), true), "\n";
echo 'is_callable(exit)=', var_export(is_callable('exit'), true), "\n";
echo 'is_callable(die)=', var_export(is_callable('die'), true), "\n";
echo 'function_exists(eval)=', var_export(function_exists('eval'), true), "\n";
echo 'function_exists(__halt_compiler)=', var_export(function_exists('__halt_compiler'), true), "\n";
$f = exit(...);
echo 'fcc=', $f instanceof Closure ? 'ok' : 'bad', "\n";
--EXPECT--
function_exists(exit)=true
function_exists(die)=true
is_callable(exit)=true
is_callable(die)=true
function_exists(eval)=false
function_exists(__halt_compiler)=false
fcc=ok
