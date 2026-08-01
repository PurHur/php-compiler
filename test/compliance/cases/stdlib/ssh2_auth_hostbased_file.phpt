--TEST--
stdlib ssh2_auth_hostbased_file registration (#26714)
--ENV--
PHP_COMPILER_ENABLE_SSH2=1
--FILE--
<?php
declare(strict_types=1);
if (!function_exists('ssh2_auth_hostbased_file')) {
    echo "skip\n";
    exit(0);
}
echo function_exists('ssh2_auth_hostbased_file') ? '1' : '0';
echo "\n";
?>
--EXPECT--
1
