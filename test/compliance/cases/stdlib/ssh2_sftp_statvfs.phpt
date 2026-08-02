--TEST--
stdlib ssh2_sftp_statvfs registration + type guard (#26740)
--ENV--
PHP_COMPILER_ENABLE_SSH2=1
--FILE--
<?php
declare(strict_types=1);
if (!function_exists('ssh2_sftp_statvfs')) {
    echo "skip\n";
    exit(0);
}
echo function_exists('ssh2_sftp_statvfs') ? '1' : '0';
echo "\n";
try {
    ssh2_sftp_statvfs(null, '/');
    echo "type_fail\n";
} catch (TypeError $e) {
    echo (str_contains($e->getMessage(), 'SSH2\\Sftp') || str_contains($e->getMessage(), 'SSH2\Sftp')) ? "type_ok\n" : "type_msg\n";
}
?>
--EXPECT--
1
type_ok
