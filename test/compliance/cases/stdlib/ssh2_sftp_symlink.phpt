--TEST--
stdlib ssh2_sftp_symlink / ssh2_sftp_readlink registration (#26662)
--ENV--
PHP_COMPILER_ENABLE_SSH2=1
--FILE--
<?php
declare(strict_types=1);
if (!function_exists('ssh2_sftp_symlink') || !function_exists('ssh2_sftp_readlink')) {
    echo "skip\n";
    exit(0);
}
echo function_exists('ssh2_sftp_symlink') ? '1' : '0';
echo function_exists('ssh2_sftp_readlink') ? '1' : '0';
echo "\n";
?>
--EXPECT--
11
