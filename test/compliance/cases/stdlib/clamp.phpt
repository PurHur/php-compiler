--TEST--
stdlib clamp() (#17336, #21022 — PHP 8.6 RFC clamp_v2, ext/standard/math.c)
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
echo function_exists('clamp') ? "exists-ok\n" : "exists-fail\n";
echo clamp(5, 1, 3), "\n";
echo clamp(0, 1, 3), "\n";
echo clamp(2, 1, 3), "\n";
echo clamp(1.5, 1.0, 3.0), "\n";
try {
    clamp(1, 3, 2);
    echo "uncaught\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
exists-ok
3
1
2
1.5
clamp(): Argument #2 ($min) must be smaller than or equal to argument #3 ($max)
