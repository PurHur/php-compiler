<?php

declare(strict_types=1);

/**
 * Issue #11626 — apache_getenv()/apache_setenv() round-trip via process environ bridge.
 */

if (!function_exists('apache_getenv') || !function_exists('apache_setenv')) {
    echo "fail: apache_getenv/apache_setenv not registered\n";
    exit(1);
}

$name = 'PHPC_APACHE_ENV_'.(string) getmypid();
if (!apache_setenv($name, 'probe')) {
    echo "fail: apache_setenv returned false\n";
    exit(1);
}

$got = apache_getenv($name);
if ('probe' !== $got) {
    echo 'fail: apache_getenv got ', var_export($got, true), "\n";
    exit(1);
}

echo "ok\n";
