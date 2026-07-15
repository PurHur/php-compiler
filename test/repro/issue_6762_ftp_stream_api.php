<?php
declare(strict_types=1);

/**
 * Issue #6762 repro — stream FTP API advertisement (PHP_COMPILER_PROFILE=8.4).
 */
echo 'fget=', function_exists('ftp_fget') ? '1' : '0', "\n";
echo 'fput=', function_exists('ftp_fput') ? '1' : '0', "\n";
echo 'mlsd=', function_exists('ftp_mlsd') ? '1' : '0', "\n";
echo 'systype=', function_exists('ftp_systype') ? '1' : '0', "\n";
echo 'binary=', defined('FTP_BINARY') ? (string) FTP_BINARY : 'missing', "\n";

$fp = fopen('php://memory', 'r+b');
try {
    ftp_fget(null, $fp, 'x', FTP_BINARY);
    echo "null_args=ok\n";
} catch (TypeError $e) {
    echo 'null_args=', $e->getMessage(), "\n";
}
echo "done\n";
