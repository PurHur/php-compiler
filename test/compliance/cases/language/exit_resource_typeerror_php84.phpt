--TEST--
Language: exit(resource) TypeError uses lowercase resource (#29594, zend_types.h)
--SKIPIF--
<?php
if (!PHPCompiler\CompilerVersion::supportsExitFunctionForm()) {
    echo "skip requires PHP_COMPILER_PROFILE=8.4 exit()/die() function form\n";
}
?>
--FILE--
<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
$r = fopen('php://memory', 'r');
try {
    exit($r);
} catch (Throwable $t) {
    echo get_class($t), ': ', $t->getMessage(), "\n";
}
?>
--EXPECT--
TypeError: exit(): Argument #1 ($status) must be of type string|int, resource given
