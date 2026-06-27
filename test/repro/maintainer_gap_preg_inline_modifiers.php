<?php

declare(strict_types=1);

/**
 * Issue #12432 — preg_* inline (?modifiers) groups (ext/pcre/php_pcre.c).
 */
$patterns = [
    '/(?i)/',
    '/(?J)/',
    '/(?-i)(?i)/',
    '/(?m)/',
    '/(?s)/',
    '/(?x)/',
    '/(?U)/',
];

foreach ($patterns as $pattern) {
    $result = preg_match($pattern, '');
    $err = preg_last_error();
    if (1 !== $result || 0 !== $err) {
        fwrite(STDERR, "pattern={$pattern} result=".var_export($result, true)." last={$err}\n");
        exit(1);
    }
}

if (1 !== preg_match('/(?i)ABC/', 'abc') || 0 !== preg_last_error()) {
    fwrite(STDERR, "caseless scope failed\n");
    exit(1);
}

echo "ok patterns=".count($patterns)."\n";
