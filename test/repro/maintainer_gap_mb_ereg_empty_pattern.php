<?php
/**
 * Repro for #20261 — mb_ereg()/mb_eregi() empty/null pattern must ValueError / TypeError.
 *
 * Default profile: empty → ValueError; null → deprecate then ValueError.
 * PROFILE=8.4: null → TypeError; empty → ValueError.
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
