<?php

/**
 * Issue #21311 — gzdeflate/gzinflate/gzdecode/gzuncompress(null) soft-null DEP+coerce under PROFILE=8.4.
 *
 * Zend 8.4 emits E_DEPRECATED and coerces to '' (reverts over-strict #19332).
 *
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_gap_zlib_deflate_inflate_null84.php
 */
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP\n";

        return true;
    }
    if (E_WARNING === $no) {
        echo "WARN\n";

        return true;
    }

    return false;
});

try {
    $deflated = gzdeflate(null);
    echo 'gzdeflate: OK len=', strlen($deflated), "\n";
} catch (TypeError $e) {
    echo "gzdeflate: TypeError\n";
    exit(1);
}

foreach (['gzinflate', 'gzdecode', 'gzuncompress'] as $fn) {
    try {
        $r = $fn(null);
        echo $fn, ': OK ', var_export($r, true), "\n";
    } catch (TypeError $e) {
        echo $fn, ": TypeError\n";
        exit(1);
    }
}

echo "OK\n";
