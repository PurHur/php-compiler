--TEST--
stdlib ssh2_sftp_mkdir / rmdir / unlink registration (#26610)
--ENV--
PHP_COMPILER_ENABLE_SSH2=1
--FILE--
<?php
declare(strict_types=1);
if (!function_exists('ssh2_sftp_mkdir') || !function_exists('ssh2_sftp_rmdir') || !function_exists('ssh2_sftp_unlink')) {
    echo "skip\n";
    exit(0);
}
echo function_exists('ssh2_sftp_mkdir') ? '1' : '0';
echo function_exists('ssh2_sftp_rmdir') ? '1' : '0';
echo function_exists('ssh2_sftp_unlink') ? '1' : '0';
echo "\n";
?>
--EXPECT--
111
