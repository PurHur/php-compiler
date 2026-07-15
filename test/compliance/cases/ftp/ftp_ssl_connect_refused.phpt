--TEST--
ext/ftp ftp_ssl_connect() refused connection returns false (#6565, #19045, php-src ext/ftp/php_ftp.c)
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
$conn = @ftp_ssl_connect('127.0.0.1', 990, 1);
var_dump($conn);
var_dump(function_exists('ftp_ssl_connect'));
?>
--EXPECT--
bool(false)
bool(true)
