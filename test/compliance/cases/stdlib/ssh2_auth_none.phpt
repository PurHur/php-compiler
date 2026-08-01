--TEST--
stdlib ssh2_auth_none registration (#26678)
--ENV--
PHP_COMPILER_ENABLE_SSH2=1
--FILE--
<?php
declare(strict_types=1);
if (!function_exists('ssh2_auth_none')) {
    echo "skip\n";
    exit(0);
}
echo function_exists('ssh2_auth_none') ? '1' : '0';
echo "\n";
?>
--EXPECT--
1
