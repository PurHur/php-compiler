--TEST--
stdlib ssh2_send_eof/ssh2_send_signal registration + type guards (#26736)
--ENV--
PHP_COMPILER_ENABLE_SSH2=1
--FILE--
<?php
declare(strict_types=1);
if (!function_exists('ssh2_send_eof') || !function_exists('ssh2_send_signal')) {
    echo "skip\n";
    exit(0);
}
echo function_exists('ssh2_send_eof') ? '1' : '0';
echo function_exists('ssh2_send_signal') ? '1' : '0';
echo "\n";
try {
    ssh2_send_eof(null);
    echo "eof_type_fail\n";
} catch (TypeError $e) {
    echo (str_contains($e->getMessage(), 'SSH2\\Stream') || str_contains($e->getMessage(), 'SSH2\Stream')) ? "eof_type_ok\n" : "eof_type_msg\n";
}
try {
    ssh2_send_signal(null, 'TERM');
    echo "sig_type_fail\n";
} catch (TypeError $e) {
    echo (str_contains($e->getMessage(), 'SSH2\\Stream') || str_contains($e->getMessage(), 'SSH2\Stream')) ? "sig_type_ok\n" : "sig_type_msg\n";
}
try {
    ssh2_send_eof();
    echo "eof_argc_fail\n";
} catch (ArgumentCountError $e) {
    echo "eof_argc_ok\n";
}
try {
    ssh2_send_signal();
    echo "sig_argc_fail\n";
} catch (ArgumentCountError $e) {
    echo "sig_argc_ok\n";
}
?>
--EXPECT--
11
eof_type_ok
sig_type_ok
eof_argc_ok
sig_argc_ok
