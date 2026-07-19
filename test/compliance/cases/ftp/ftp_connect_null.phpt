--TEST--
ext/ftp ftp_connect()/ftp_ssl_connect(null) — soft coerce under 8.2 profile (#20484, ext/ftp/php_ftp.c)
--ENV--
PHP_COMPILER_PROFILE=8.2
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
var_export(@ftp_connect(null));
echo "\n";
var_export(@ftp_ssl_connect(null));
echo "\n";
?>
--EXPECT--
false
false
