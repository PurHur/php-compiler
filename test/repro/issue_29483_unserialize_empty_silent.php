<?php
/**
 * Repro #29483 — unserialize('') → false with no Error-at-offset (php-src var.c empty buffer).
 * AOT-safe: avoids set_error_handler / non-int NestedJIT decode paths.
 */
error_reporting(E_ALL);
$empty = '';
$r = unserialize($empty);
echo var_export($r, true), "\n";
$r2 = unserialize('');
echo var_export($r2, true), "\n";
