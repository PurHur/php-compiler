--TEST--
JIT clamp() (#17336, #21022 — PHP 8.6, ext/standard/math.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
putenv('PHP_COMPILER_PROFILE=8.6');
if (!PHPCompiler\CompilerVersion::supportsClamp()) {
    die('skip clamp requires PHP_COMPILER_PROFILE=8.6');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.6
--FILE--
<?php
echo clamp(5, 1, 3), "\n";
echo clamp(0, 1, 3), "\n";
echo clamp(2, 1, 3), "\n";
?>
--EXPECT--
3
1
2
