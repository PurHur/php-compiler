--TEST--
ext/ftp ftp_fget/fput/mlsd/systype exist + TypeError (#6762, php-src ext/ftp/php_ftp.c)
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
echo 'fget=', (int) function_exists('ftp_fget'), "\n";
echo 'fput=', (int) function_exists('ftp_fput'), "\n";
echo 'mlsd=', (int) function_exists('ftp_mlsd'), "\n";
echo 'systype=', (int) function_exists('ftp_systype'), "\n";
echo 'login=', (int) function_exists('ftp_login'), "\n";
echo 'FTP_BINARY=', FTP_BINARY, "\n";
echo 'FTP_ASCII=', FTP_ASCII, "\n";

$fp = fopen('php://memory', 'r+b');
try {
    ftp_fget(null, $fp, 'remote.bin', FTP_BINARY);
    echo "null_ftp=ok\n";
} catch (TypeError $e) {
    echo 'null_ftp=', $e->getMessage(), "\n";
}

$obj = new stdClass();
try {
    ftp_fget($obj, $fp, 'remote.bin', FTP_BINARY);
    echo "bad_ftp=ok\n";
} catch (TypeError $e) {
    echo 'bad_ftp=', $e->getMessage(), "\n";
}

try {
    ftp_fput(null, 'remote.bin', $fp, FTP_BINARY);
    echo "null_fput=ok\n";
} catch (TypeError $e) {
    echo 'null_fput=', $e->getMessage(), "\n";
}
?>
--EXPECT--
fget=1
fput=1
mlsd=1
systype=1
login=1
FTP_BINARY=2
FTP_ASCII=1
null_ftp=ftp_fget(): Argument #1 ($ftp) must be of type FTP\Connection, null given
bad_ftp=ftp_fget(): Argument #1 ($ftp) must be of type FTP\Connection, stdClass given
null_fput=ftp_fput(): Argument #1 ($ftp) must be of type FTP\Connection, null given
