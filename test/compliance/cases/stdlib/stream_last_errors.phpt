--TEST--
stdlib stream_last_errors()/stream_clear_errors() — PHP 8.6 stream error store (#21020)
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
echo function_exists('stream_last_errors') ? "last-ok\n" : "last-fail\n";
echo function_exists('stream_clear_errors') ? "clear-ok\n" : "clear-fail\n";
echo class_exists('StreamError') ? "class-ok\n" : "class-fail\n";
echo enum_exists('StreamErrorCode') ? "enum-ok\n" : "enum-fail\n";

$path = '/no/such/path/php-compiler-stream-errors-'.getmypid();
@fopen($path, 'r');
$errors = stream_last_errors();
echo is_array($errors) ? "arr-ok\n" : "arr-fail\n";
echo count($errors) >= 1 ? "count-ok\n" : "count-fail\n";
$e = $errors[0];
echo $e instanceof StreamError ? "obj-ok\n" : "obj-fail\n";
echo $e->code === StreamErrorCode::OpenFailed ? "code-ok\n" : "code-fail\n";
$msg = $e->message;
echo is_string($msg) && str_contains($msg, 'Failed to open stream') ? "msg-ok\n" : "msg-fail\n";
echo $e->wrapperName === 'plainfile' ? "wrap-ok\n" : "wrap-fail\n";
echo true === $e->terminating ? "term-ok\n" : "term-fail\n";

stream_clear_errors();
$cleared = stream_last_errors();
echo is_array($cleared) && 0 === count($cleared) ? "cleared-ok\n" : "cleared-fail\n";
?>
--EXPECT--
last-ok
clear-ok
class-ok
enum-ok
arr-ok
count-ok
obj-ok
code-ok
msg-ok
wrap-ok
term-ok
cleared-ok
