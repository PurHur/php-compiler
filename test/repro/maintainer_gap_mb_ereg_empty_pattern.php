<?php
/**
 * Repro for #30067 / #20261 — mb_ereg()/mb_eregi() empty/null pattern.
 *
 * Empty → ValueError. Null (non-strict) → Deprecated then ValueError (php-src Z_PARAM_STR).
 * PROFILE=8.4 matches that soft path (not TypeError).
 */
foreach ([null, ''] as $pat) {
    foreach (['mb_ereg', 'mb_eregi'] as $fn) {
        try {
            $r = $fn($pat, 'abc');
            echo $fn, ' pat=', json_encode($pat), ' COERCE ', json_encode($r), "\n";
        } catch (Throwable $e) {
            echo $fn, ' pat=', json_encode($pat), ' ', get_class($e), ': ', $e->getMessage(), "\n";
        }
    }
}
