<?php
/**
 * Repro #22826 — FILTER_VALIDATE_EMAIL domain label rules (php-src logical_filters.c).
 */
foreach ([
    'test@-example.com',
    'a@b..com',
    'test@.com',
    'test@example.com.',
    'ok@example.com',
    'a@b',
    'user@ex--ample.com',
    'a@b-.com',
    'a@-b.com',
] as $e) {
    echo $e, '=', var_export(filter_var($e, FILTER_VALIDATE_EMAIL), true), "\n";
}
