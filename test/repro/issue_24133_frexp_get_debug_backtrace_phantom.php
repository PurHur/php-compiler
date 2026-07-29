<?php

/**
 * Issue #24133 — frexp() and get_debug_backtrace() phantom registration.
 *
 * Neither function exists in php-src. debug_backtrace() is the only supported API.
 * VmMath::frexp / MathFrexp remain as internal helpers (sprintf etc.).
 *
 * Expected: all three function_exists() calls return false.
 */

$results = [];
foreach (['frexp', 'get_debug_backtrace'] as $f) {
    $exists = function_exists($f);
    $results[] = "$f=" . ($exists ? 'EXISTS(BUG)' : 'absent(OK)');
}

// debug_backtrace must still work
$exists = function_exists('debug_backtrace');
$results[] = "debug_backtrace=" . ($exists ? 'exists(OK)' : 'ABSENT(BUG)');

echo implode("\n", $results) . "\n";
