<?php

/**
 * Issue #19332 — zlib one-shots reject null on PHP_COMPILER_PROFILE=8.4 (re-#19112).
 *
 * Run with: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_gap_zlib_null_forward84.php
 */
foreach (['gzcompress', 'gzencode', 'gzdeflate', 'gzdecode', 'gzuncompress'] as $fn) {
    try {
        $r = $fn(null);
        echo $fn, ' COERCE ', gettype($r), "\n";
    } catch (Throwable $e) {
        echo $fn, ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
