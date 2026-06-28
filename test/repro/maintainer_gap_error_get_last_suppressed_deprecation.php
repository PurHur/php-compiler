<?php

// Intentionally no declare(strict_types=1) — Zend deprecates strlen(null) on PHP 8.2 (#13139).

@strlen(null);
$e = error_get_last();
if (null === $e) {
    fwrite(STDERR, "fail: error_get_last() is NULL after @strlen(null)\n");
    exit(1);
}
if (8192 !== $e['type']) {
    fwrite(STDERR, "fail: expected E_DEPRECATED (8192), got type {$e['type']}\n");
    exit(1);
}
if (!str_contains($e['message'], 'strlen')) {
    fwrite(STDERR, "fail: message does not mention strlen(): {$e['message']}\n");
    exit(1);
}

@trigger_error('user-dep', E_USER_DEPRECATED);
$u = error_get_last();
if (null === $u || 16384 !== $u['type'] || 'user-dep' !== $u['message']) {
    fwrite(STDERR, "fail: @ E_USER_DEPRECATED path regressed\n");
    exit(1);
}

echo "ok\n";
