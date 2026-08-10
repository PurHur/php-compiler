<?php
/**
 * #29778 — mb_chr/mb_ord(null) under strict_types must TypeError (php-src mbstring.c).
 *
 * Run:
 *   php test/repro/maintainer_gap_mb_chr_ord_null_strict.php
 *   php bin/vm.php test/repro/maintainer_gap_mb_chr_ord_null_strict.php
 *   php bin/jit.php test/repro/maintainer_gap_mb_chr_ord_null_strict.php
 *   php bin/compile.php -o /tmp/mb_chr_ord_null_strict.bin test/repro/maintainer_gap_mb_chr_ord_null_strict.php && /tmp/mb_chr_ord_null_strict.bin
 */
declare(strict_types=1);

try {
    var_export(mb_chr(null));
    echo "\nbad:mb_chr(null):coerced\n";
} catch (Throwable $e) {
    echo 'ok:mb_chr(null):', get_class($e), "\n";
    echo $e->getMessage(), "\n";
}

try {
    var_export(mb_ord(null));
    echo "\nbad:mb_ord(null):coerced\n";
} catch (Throwable $e) {
    echo 'ok:mb_ord(null):', get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
