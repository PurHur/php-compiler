--TEST--
AOT: stream_last_errors()/stream_clear_errors() PHP 8.6 stream error store (#21020)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
putenv('PHP_COMPILER_PROFILE=8.6');
if (!PHPCompiler\CompilerVersion::supportsStreamErrorApi()) {
    die('skip stream error API requires PHP_COMPILER_PROFILE=8.6');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.6
--FILE--
<?php
$path = '/no/such/path/php-compiler-aot-stream-errors-'.getmypid();
@fopen($path, 'r');
$errors = stream_last_errors();
echo count($errors) >= 1 ? "count-ok\n" : "count-fail\n";
$e = $errors[0];
echo $e instanceof StreamError ? "obj-ok\n" : "obj-fail\n";
echo $e->code === StreamErrorCode::OpenFailed ? "code-ok\n" : "code-fail\n";
stream_clear_errors();
$cleared = stream_last_errors();
echo is_array($cleared) && 0 === count($cleared) ? "cleared-ok\n" : "cleared-fail\n";
?>
--EXPECT--
count-ok
obj-ok
code-ok
cleared-ok
