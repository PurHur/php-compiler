--TEST--
stdlib ssh2_sftp_stat / ssh2_sftp_lstat registration (#26609)
--ENV--
PHP_COMPILER_ENABLE_SSH2=1
--FILE--
<?php
declare(strict_types=1);
if (!function_exists('ssh2_sftp_stat') || !function_exists('ssh2_sftp_lstat')) {
    echo "skip\n";
    exit(0);
}
echo function_exists('ssh2_sftp_stat') ? '1' : '0';
echo function_exists('ssh2_sftp_lstat') ? '1' : '0';
echo "\n";
?>
--EXPECT--
11
