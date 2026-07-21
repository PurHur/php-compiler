<?php
/**
 * AOT smoke #21521 — sscanf(null $format) soft-null under PROFILE=8.4.
 * Constant null folds at compile time; gettype+count (avoid is_array ternary / var_export).
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$r = sscanf('abc', null);
echo gettype($r), ' ', (string) count($r), "\n";
