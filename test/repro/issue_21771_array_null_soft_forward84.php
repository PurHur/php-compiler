<?php
/**
 * Repro #21771 / #21916 — Z_PARAM_ARRAY null is always TypeError (Zend 8.2),
 * including under PHP_COMPILER_PROFILE=8.4. Soft-null/DEP was inverted.
 *
 * VM:  PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_21771_array_null_soft_forward84.php
 * JIT: PHP_COMPILER_PROFILE=8.4 php bin/jit.php test/repro/issue_21771_array_null_soft_forward84.php
 */
$checks = [
    // count(null)/sizeof(null) always TypeError (Countable|array) — #21914 (re-#21771)
    // array_keys(null) always TypeError (typed array) — #21915 (re-#21771)
    // in_array/array_merge/array_flip/array_map/array_sum — always TypeError (#21916)
    'array_merge' => static fn () => array_merge(null),
    'in_array' => static fn () => in_array('a', null),
    'array_flip' => static fn () => array_flip(null),
    'array_sum' => static fn () => array_sum(null),
    // iterator_to_array(null) is always TypeError (typed Traversable|array) — #21893
    'array_map' => static fn () => array_map(static fn ($x) => $x, null),
];

foreach ($checks as $name => $fn) {
    try {
        var_export($fn());
        echo " ({$name} uncaught)\n";
    } catch (Throwable $e) {
        echo $name, ' ', get_class($e), "\n";
    }
}
