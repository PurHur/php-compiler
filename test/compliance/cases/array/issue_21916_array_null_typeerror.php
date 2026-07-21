<?php
/**
 * Repro #21916 / #21926 — Z_PARAM_ARRAY null → TypeError (php-src ext/standard/array.c).
 *
 * Soft-null under PROFILE=8.4 was an inverted #21771 claim; Zend 8.2 always TypeErrors.
 *
 * VM:  php bin/vm.php test/repro/issue_21916_array_null_typeerror.php
 * JIT: php bin/jit.php test/repro/issue_21916_array_null_typeerror.php
 */
$cases = [
    'in_array' => static fn () => in_array(1, null),
    'array_merge' => static fn () => array_merge(null),
    'array_flip' => static fn () => array_flip(null),
    'array_map' => static fn () => array_map(static fn ($x) => $x, null),
    'array_sum' => static fn () => array_sum(null),
];

foreach ($cases as $name => $fn) {
    try {
        var_export($fn());
        echo " ({$name} uncaught)\n";
    } catch (Throwable $e) {
        echo $name, ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
