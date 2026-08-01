--TEST--
stdlib ssh2_forward_listen/accept registration (#26715)
--ENV--
PHP_COMPILER_ENABLE_SSH2=1
--FILE--
<?php
declare(strict_types=1);
if (!function_exists('ssh2_forward_listen') || !function_exists('ssh2_forward_accept')) {
    echo "skip\n";
    exit(0);
}
echo function_exists('ssh2_forward_listen') ? '1' : '0';
echo function_exists('ssh2_forward_accept') ? '1' : '0';
echo "\n";
?>
--EXPECT--
11
