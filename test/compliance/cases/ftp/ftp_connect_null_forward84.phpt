--TEST--
ext/ftp ftp_connect()/ftp_ssl_connect(null) — TypeError on 8.4 forward profile (#20484, ext/ftp/ftp.stub.php)
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
try {
    ftp_connect(null);
    echo "ftp_connect_uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    ftp_ssl_connect(null);
    echo "ftp_ssl_connect_uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
ftp_connect(): Argument #1 ($hostname) must be of type string, null given
ftp_ssl_connect(): Argument #1 ($hostname) must be of type string, null given
