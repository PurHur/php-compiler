<?php
/**
 * #29811 — mb_split(null) under strict_types must TypeError (php-src php_mbregex.c).
 *
 * Run:
 *   php test/repro/maintainer_gap_mb_split_null_strict.php
 *   php bin/vm.php test/repro/maintainer_gap_mb_split_null_strict.php
 *   php bin/jit.php test/repro/maintainer_gap_mb_split_null_strict.php
 *   php bin/compile.php -o /tmp/mb_split_null_strict.bin test/repro/maintainer_gap_mb_split_null_strict.php && /tmp/mb_split_null_strict.bin
 */
declare(strict_types=1);

try {
    var_export(mb_split(null, 'a'));
    echo "\nbad:mb_split(null,\"a\"):coerced\n";
} catch (Throwable $e) {
    echo 'ok:mb_split(null,"a"):', get_class($e), "\n";
    echo $e->getMessage(), "\n";
}

try {
    var_export(mb_split('a', null));
    echo "\nbad:mb_split(\"a\",null):coerced\n";
} catch (Throwable $e) {
    echo 'ok:mb_split("a",null):', get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
