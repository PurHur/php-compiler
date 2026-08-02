<?php

/**
 * Repro for #26882 — AOT soundex must match Zend (no segfault).
 * Use a runtime string so constant-fold cannot hide a broken helper TU.
 */
$s = $argv[1] ?? 'Euler';
echo soundex($s), "\n";
echo soundex('Washington'), "\n";
echo soundex(''), "\n";
