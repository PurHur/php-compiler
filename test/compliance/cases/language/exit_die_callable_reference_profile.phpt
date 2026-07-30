--TEST--
Language: is_callable/function_exists false for exit/die on reference profile (#25421, zend_compile.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (PHPCompiler\CompilerVersion::supportsExitFunctionForm()) {
    die('skip exit function form enabled on PHP 8.4.0+ target');
}
?>
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
