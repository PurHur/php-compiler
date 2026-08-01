--TEST--
stdlib ssh2_tunnel registration (#26677)
--ENV--
PHP_COMPILER_ENABLE_SSH2=1
--FILE--
<?php
declare(strict_types=1);
if (!function_exists('ssh2_tunnel')) {
    echo "skip\n";
    exit(0);
}
echo function_exists('ssh2_tunnel') ? '1' : '0';
echo "\n";
?>
--EXPECT--
1
