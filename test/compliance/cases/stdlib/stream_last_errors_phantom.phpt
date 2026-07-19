--TEST--
stdlib stream_last_errors()/stream_clear_errors() — phantom on ≤8.5 profiles (#21020)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
// Ensure reference/≤8.5 profile for this phantom gate (unset forward 8.6).
$prev = getenv('PHP_COMPILER_PROFILE');
putenv('PHP_COMPILER_PROFILE');
if (PHPCompiler\CompilerVersion::supportsStreamErrorApi()) {
    if (false !== $prev && false !== $prev) {
        putenv('PHP_COMPILER_PROFILE='.$prev);
    }
    die('skip phantom test not applicable when stream error API is enabled');
}
?>
--FILE--
<?php
echo function_exists('stream_last_errors') ? "last-fail\n" : "last-ok\n";
echo function_exists('stream_clear_errors') ? "clear-fail\n" : "clear-ok\n";
echo class_exists('StreamError') ? "class-fail\n" : "class-ok\n";
echo enum_exists('StreamErrorCode') ? "enum-fail\n" : "enum-ok\n";
try {
    stream_last_errors();
    echo "no-exception\n";
} catch (Throwable $e) {
    echo $e instanceof Error ? 'Error' : get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
last-ok
clear-ok
class-ok
enum-ok
Error: Call to undefined function stream_last_errors()
