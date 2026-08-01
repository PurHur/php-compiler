--TEST--
stdlib ssh2_methods_negotiated registration (#26679)
--ENV--
PHP_COMPILER_ENABLE_SSH2=1
--FILE--
<?php
declare(strict_types=1);
if (!function_exists('ssh2_methods_negotiated')) {
    echo "skip\n";
    exit(0);
}
echo function_exists('ssh2_methods_negotiated') ? '1' : '0';
echo "\n";
?>
--EXPECT--
1
