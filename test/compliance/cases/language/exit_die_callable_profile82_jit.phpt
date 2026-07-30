--TEST--
Language JIT: is_callable/function_exists false for exit/die on PROFILE=8.2 (#25421)
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
declare(strict_types=1);
$f = 'exit';
$d = 'die';
echo 'exit_callable=', var_export(is_callable($f), true), "\n";
echo 'die_callable=', var_export(is_callable($d), true), "\n";
echo 'exit_exists=', var_export(function_exists($f), true), "\n";
echo 'die_exists=', var_export(function_exists($d), true), "\n";
--EXPECT--
exit_callable=false
die_callable=false
exit_exists=false
die_exists=false
