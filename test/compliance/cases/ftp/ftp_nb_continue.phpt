--TEST--
ext/ftp ftp_nb_* symbols exist + TypeError + FTP_* status constants (#6675, php-src ext/ftp/php_ftp.c)
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
echo 'nb_continue=', (int) function_exists('ftp_nb_continue'), "\n";
echo 'nb_fget=', (int) function_exists('ftp_nb_fget'), "\n";
echo 'nb_put=', (int) function_exists('ftp_nb_put'), "\n";
echo 'nb_get=', (int) function_exists('ftp_nb_get'), "\n";
echo 'FTP_FAILED=', FTP_FAILED, "\n";
echo 'FTP_FINISHED=', FTP_FINISHED, "\n";
echo 'FTP_MOREDATA=', FTP_MOREDATA, "\n";

$fp = fopen('php://memory', 'r+b');
try {
    ftp_nb_continue(null);
    echo "null_continue=ok\n";
} catch (TypeError $e) {
    echo 'null_continue=', $e->getMessage(), "\n";
}

try {
    ftp_nb_fget(null, $fp, 'remote.bin', FTP_BINARY);
    echo "null_fget=ok\n";
} catch (TypeError $e) {
    echo 'null_fget=', $e->getMessage(), "\n";
}

try {
    ftp_nb_get(null, '/tmp/out', 'remote.bin', FTP_BINARY);
    echo "null_get=ok\n";
} catch (TypeError $e) {
    echo 'null_get=', $e->getMessage(), "\n";
}

try {
    ftp_nb_put(null, 'remote.bin', '/tmp/in', FTP_BINARY);
    echo "null_put=ok\n";
} catch (TypeError $e) {
    echo 'null_put=', $e->getMessage(), "\n";
}
?>
--EXPECT--
nb_continue=1
nb_fget=1
nb_put=1
nb_get=1
FTP_FAILED=0
FTP_FINISHED=1
FTP_MOREDATA=2
null_continue=ftp_nb_continue(): Argument #1 ($ftp) must be of type FTP\Connection, null given
null_fget=ftp_nb_fget(): Argument #1 ($ftp) must be of type FTP\Connection, null given
null_get=ftp_nb_get(): Argument #1 ($ftp) must be of type FTP\Connection, null given
null_put=ftp_nb_put(): Argument #1 ($ftp) must be of type FTP\Connection, null given
