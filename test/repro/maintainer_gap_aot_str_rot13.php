<?php

/**
 * Repro for #26868 — AOT str_rot13 must match Zend (no segfault).
 * Use a runtime string so constant-fold cannot hide a broken helper TU.
 */
$s = $argv[1] ?? 'PHP';
echo str_rot13($s), "\n";
echo str_rot13(str_rot13('Hello')), "\n";
