--TEST--
stdlib clamp() — phantom on ≤8.5 profiles (#21022)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
$prev = getenv('PHP_COMPILER_PROFILE');
putenv('PHP_COMPILER_PROFILE');
if (PHPCompiler\CompilerVersion::supportsClamp()) {
    if (is_string($prev) && '' !== $prev) {
        putenv('PHP_COMPILER_PROFILE='.$prev);
    }
    die('skip phantom test not applicable when clamp is enabled');
}
?>
--FILE--
<?php
echo function_exists('clamp') ? "exists-fail\n" : "exists-ok\n";
try {
    echo clamp(5, 1, 3), "\n";
    echo "no-exception\n";
} catch (Throwable $e) {
    echo $e instanceof Error ? 'Error' : get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
exists-ok
Error: Call to undefined function clamp()
