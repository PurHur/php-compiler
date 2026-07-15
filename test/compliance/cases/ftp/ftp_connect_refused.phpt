--TEST--
ext/ftp ftp_connect() refused connection returns false (#3353, php-src ext/ftp/php_ftp.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsFtpConnection()) {
    die('skip FTP withheld on reference profile');
}
if (!function_exists('stream_socket_client')) {
    die('skip stream_socket_client unavailable');
}
?>
--FILE--
<?php
$conn = @ftp_connect('127.0.0.1', 21, 1);
var_dump($conn);
var_dump(function_exists('ftp_connect'));
?>
--EXPECT--
bool(false)
bool(true)
