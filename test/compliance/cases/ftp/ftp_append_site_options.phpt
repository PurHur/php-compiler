--TEST--
ext/ftp append/SITE/options API exists + TypeError under 8.4 profile (#20060, php-src ext/ftp/php_ftp.c)
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
foreach ([
    'ftp_append', 'ftp_alloc', 'ftp_chmod', 'ftp_raw', 'ftp_site',
    'ftp_set_option', 'ftp_get_option',
] as $f) {
    echo $f, '=', (int) function_exists($f), "\n";
}

try {
    ftp_append(null, 'r', 'l');
    echo "null_append=ok\n";
} catch (TypeError $e) {
    echo 'null_append=', $e->getMessage(), "\n";
}

try {
    ftp_set_option(new stdClass(), FTP_TIMEOUT_SEC, 90);
    echo "bad_set=ok\n";
} catch (TypeError $e) {
    echo 'bad_set=', $e->getMessage(), "\n";
}

try {
    ftp_get_option(null, FTP_TIMEOUT_SEC);
    echo "null_get=ok\n";
} catch (TypeError $e) {
    echo 'null_get=', $e->getMessage(), "\n";
}
?>
--EXPECT--
ftp_append=1
ftp_alloc=1
ftp_chmod=1
ftp_raw=1
ftp_site=1
ftp_set_option=1
ftp_get_option=1
null_append=ftp_append(): Argument #1 ($ftp) must be of type FTP\Connection, null given
bad_set=ftp_set_option(): Argument #1 ($ftp) must be of type FTP\Connection, stdClass given
null_get=ftp_get_option(): Argument #1 ($ftp) must be of type FTP\Connection, null given
