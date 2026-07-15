--TEST--
ext/ftp ftp_connect() enum hostname operand TypeError (#3353, php-src-strict)
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
?>
--FILE--
<?php
enum Host: string {
    case Local = '127.0.0.1';
}
try {
    ftp_connect(Host::Local);
    echo "no error\n";
} catch (TypeError $e) {
    echo get_class($e), "\n";
    echo str_contains($e->getMessage(), 'Host') ? "enum type\n" : "other\n";
}
?>
--EXPECT--
TypeError
enum type
