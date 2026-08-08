<?php
/**
 * Repro #28537 — range() step ValueError text under PROFILE≥8.3 (php-src array.c).
 * Default/8.2 profile keeps the legacy single message; forward profile splits zero vs span.
 */
try {
    range(1, 2, 0);
    echo "zero:ok\n";
} catch (Throwable $e) {
    echo 'zero:', $e->getMessage(), "\n";
}
try {
    range(1, 10, 100);
    echo "span:ok\n";
} catch (Throwable $e) {
    echo 'span:', $e->getMessage(), "\n";
}
