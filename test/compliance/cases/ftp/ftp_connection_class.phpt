--TEST--
ext/ftp FTP\Connection class registered on PHP 8.4 profile (#7270, ext/ftp/ftp.stub.php)
--ENV--
PHP_COMPILER_PROFILE=8.4
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsFtpConnection()) {
    die('skip FTP\\Connection withheld on reference profile');
}
?>
--FILE--
<?php
echo class_exists('FTP\\Connection', false) ? "exists\n" : "missing\n";
echo (new ReflectionClass('FTP\\Connection'))->getName(), "\n";
--EXPECT--
exists
FTP\Connection
