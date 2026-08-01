--TEST--
stdlib ssh2_sftp_rename / ssh2_sftp_chmod registration (#26611)
--ENV--
PHP_COMPILER_ENABLE_SSH2=1
--FILE--
<?php
declare(strict_types=1);
if (!function_exists('ssh2_sftp_rename') || !function_exists('ssh2_sftp_chmod')) {
    echo "skip\n";
    exit(0);
}
echo function_exists('ssh2_sftp_rename') ? '1' : '0';
echo function_exists('ssh2_sftp_chmod') ? '1' : '0';
echo "\n";
?>
--EXPECT--
11
