<?php

declare(strict_types=1);

/**
 * Focused guard #12915 — disable_functions must be empty string, not false.
 */

$value = ini_get('disable_functions');
if ('string' !== gettype($value)) {
    echo 'fail: gettype expected string, got '.gettype($value)."\n";
    exit(1);
}
if ('' !== $value) {
    echo 'fail: expected empty string, got '.var_export($value, true)."\n";
    exit(1);
}
echo "ok\n";
