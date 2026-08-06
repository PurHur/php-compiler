<?php

declare(strict_types=1);

/**
 * Repro for #26736 — ssh2_send_eof / ssh2_send_signal registration + guards.
 * Run: PHP_COMPILER_ENABLE_SSH2=1 php bin/vm.php test/repro/issue_26736_ssh2_send_eof_signal.php
 */
echo 'eof=', function_exists('ssh2_send_eof') ? '1' : '0', "\n";
echo 'sig=', function_exists('ssh2_send_signal') ? '1' : '0', "\n";
try {
    ssh2_send_eof(null);
    echo "eof_type=fail\n";
} catch (TypeError $e) {
    echo (str_contains($e->getMessage(), 'SSH2\\Stream') || str_contains($e->getMessage(), 'SSH2\Stream'))
        ? "eof_type=ok\n"
        : "eof_type=msg\n";
}
try {
    ssh2_send_signal(null, 'TERM');
    echo "sig_type=fail\n";
} catch (TypeError $e) {
    echo (str_contains($e->getMessage(), 'SSH2\\Stream') || str_contains($e->getMessage(), 'SSH2\Stream'))
        ? "sig_type=ok\n"
        : "sig_type=msg\n";
}
