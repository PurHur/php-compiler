--TEST--
stdlib ssh2_connect registration + connect-fail (#6385)
--ENV--
PHP_COMPILER_ENABLE_SSH2=1
--FILE--
<?php
declare(strict_types=1);
if (!function_exists('ssh2_connect')) {
    echo "skip\n";
    exit(0);
}
echo function_exists('ssh2_connect') ? '1' : '0';
echo function_exists('ssh2_fingerprint') ? '1' : '0';
echo extension_loaded('ssh2') ? '1' : '0';
echo "\n";
$conn = @ssh2_connect('127.0.0.1', 1); // port 1: almost never an SSH daemon
echo false === $conn ? "fail_ok\n" : "unexpected\n";
?>
--EXPECT--
111
fail_ok
