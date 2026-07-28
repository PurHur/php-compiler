<?php

/**
 * Issue #24396 — zend.assertions cannot cross -1 at runtime (Zend/zend.c OnUpdateAssertions).
 *
 * Expectation (php-src-strict, default start -1):
 *   ini_set('zend.assertions', '1') → false + warning; value stays -1; assert(false) no-throw
 */
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

echo 'start=', var_export(ini_get('zend.assertions'), true), "\n";

$set = @ini_set('zend.assertions', '1');
$err = error_get_last();
if (is_array($err) && str_contains((string) $err['message'], 'zend.assertions may be completely enabled')) {
    echo 'warn=', $err['message'], "\n";
} else {
    echo "warn=missing\n";
}

echo 'set=', var_export($set, true), "\n";
echo 'after=', var_export(ini_get('zend.assertions'), true), "\n";

try {
    assert(false, 'nope');
    echo "assert=no-throw\n";
} catch (Throwable $e) {
    echo 'assert=', $e::class, ':', $e->getMessage(), "\n";
}
