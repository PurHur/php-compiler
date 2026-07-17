--TEST--
ext/ftp transfer+dir API exists + TypeError under 8.4 profile (#20033, php-src ext/ftp/php_ftp.c)
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
    'ftp_pasv', 'ftp_get', 'ftp_put', 'ftp_nlist', 'ftp_rawlist',
    'ftp_chdir', 'ftp_mkdir', 'ftp_delete', 'ftp_size', 'ftp_mdtm',
] as $f) {
    echo $f, '=', (int) function_exists($f), "\n";
}

try {
    ftp_pasv(null, true);
    echo "null_pasv=ok\n";
} catch (TypeError $e) {
    echo 'null_pasv=', $e->getMessage(), "\n";
}

try {
    ftp_chdir(new stdClass(), '/');
    echo "bad_chdir=ok\n";
} catch (TypeError $e) {
    echo 'bad_chdir=', $e->getMessage(), "\n";
}

try {
    ftp_size(null, 'x');
    echo "null_size=ok\n";
} catch (TypeError $e) {
    echo 'null_size=', $e->getMessage(), "\n";
}
?>
--EXPECT--
ftp_pasv=1
ftp_get=1
ftp_put=1
ftp_nlist=1
ftp_rawlist=1
ftp_chdir=1
ftp_mkdir=1
ftp_delete=1
ftp_size=1
ftp_mdtm=1
null_pasv=ftp_pasv(): Argument #1 ($ftp) must be of type FTP\Connection, null given
bad_chdir=ftp_chdir(): Argument #1 ($ftp) must be of type FTP\Connection, stdClass given
null_size=ftp_size(): Argument #1 ($ftp) must be of type FTP\Connection, null given
