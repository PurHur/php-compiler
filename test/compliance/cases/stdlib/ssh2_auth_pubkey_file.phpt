--TEST--
stdlib ssh2_auth_pubkey_file registration (#26577)
--ENV--
PHP_COMPILER_ENABLE_SSH2=1
--FILE--
<?php
declare(strict_types=1);
if (!function_exists('ssh2_auth_pubkey_file')) {
    echo "skip\n";
    exit(0);
}
echo function_exists('ssh2_auth_pubkey_file') ? '1' : '0';
echo "\n";
?>
--EXPECT--
1
