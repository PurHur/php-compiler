--TEST--
stdlib ssh2_sftp_realpath registration (#26661)
--ENV--
PHP_COMPILER_ENABLE_SSH2=1
--FILE--
<?php
declare(strict_types=1);
if (!function_exists('ssh2_sftp_realpath')) {
    echo "skip\n";
    exit(0);
}
echo function_exists('ssh2_sftp_realpath') ? '1' : '0';
echo "\n";
?>
--EXPECT--
1
