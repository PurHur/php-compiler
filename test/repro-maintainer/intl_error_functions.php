<?php

declare(strict_types=1);

/**
 * Maintainer repro: intl error introspection when ext/intl builtins are advertised (#5156).
 */

if (!function_exists('intl_get_error_message')) {
    echo "missing\n";
    exit(1);
}

echo function_exists('intl_get_error_code') ? "code_fn\n" : "no_code\n";
echo function_exists('intl_get_error_message') ? "exists\n" : "missing\n";
echo function_exists('intl_is_failure') ? "failure_fn\n" : "no_failure\n";

echo intl_get_error_code(), "\n";
echo intl_get_error_message(), "\n";
echo intl_is_failure(intl_get_error_code()) ? "fail\n" : "ok\n";
echo intl_is_failure(-1) ? "yes\n" : "no\n";
