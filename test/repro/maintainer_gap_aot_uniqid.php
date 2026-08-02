<?php

/**
 * Repro for #26931 — AOT uniqid must match Zend format (no segfault).
 * Runtime prefix so constant-fold cannot hide a broken helper TU.
 */
$prefix = 'p';
$u = uniqid($prefix, true);
echo str_starts_with($u, $prefix) && strlen($u) > 10 ? "ok" : "bad", "\n";
$plain = uniqid($prefix, false);
echo str_starts_with($plain, $prefix) && strlen($plain) === 14 ? "plain" : "bad-plain", "\n";
