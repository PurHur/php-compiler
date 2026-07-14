<?php

declare(strict_types=1);

// Issue #6548 — mb_send_mail() registration and transport-unavailable false return.
if (!function_exists('mb_send_mail')) {
    echo "fail: mb_send_mail not registered\n";
    exit(1);
}

try {
    $ok = mb_send_mail('user@example.com', 'subject', 'body');
    if (false !== $ok) {
        echo "fail: expected false when transport unavailable\n";
        exit(1);
    }
    echo "ok\n";
} catch (Throwable $e) {
    echo 'fail: '.get_class($e).': '.$e->getMessage()."\n";
    exit(1);
}
