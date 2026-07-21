--TEST--
ext/ftp ftp_connect()/ftp_ssl_connect(null) — DEP+false on 8.4 forward profile (#21757, ext/ftp/ftp.c)
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
    var_export(@ftp_connect(null));
    echo " COERCED\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    var_export(@ftp_ssl_connect(null));
    echo " COERCED\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
false COERCED
false COERCED
