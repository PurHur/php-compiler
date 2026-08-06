<?php

declare(strict_types=1);

/**
 * Repro for #28153 — ob_get_status() string-key order (name first like php-src).
 */
ob_start();
echo 'x';
$s = ob_get_status(false);
ob_end_clean();
echo implode(',', array_keys($s)), "\n";
echo 'name_idx=', (string) array_search('name', array_keys($s), true), "\n";
