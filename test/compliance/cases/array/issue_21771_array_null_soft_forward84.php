<?php
/**
 * Repro #21771 — array-family builtins soft-null under PROFILE=8.4
 * (Zend DEP + legacy coerce; ext/standard/array.c, basic_functions.c).
 *
 * VM:  PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_21771_array_null_soft_forward84.php
 * JIT: PHP_COMPILER_PROFILE=8.4 php bin/jit.php test/repro/issue_21771_array_null_soft_forward84.php
 */
set_error_handler(static function (int $n, string $m): bool {
    if (E_DEPRECATED === $n) {
        echo "DEP\n";

        return true;
    }

    return false;
});

$checks = [
    // count(null)/sizeof(null) always TypeError (Countable|array) — #21914 (re-#21771)
    // array_keys(null) always TypeError (typed array) — #21915 (re-#21771)
    'array_merge' => static fn () => array_merge(null) === [],
    'in_array' => static fn () => in_array('a', null) === false,
    'array_flip' => static fn () => array_flip(null) === [],
    'array_sum' => static fn () => array_sum(null) === 0,
    // iterator_to_array(null) is always TypeError (typed Traversable|array) — #21893
    'array_map' => static fn () => array_map(static fn ($x) => $x, null) === [],
];

foreach ($checks as $name => $fn) {
    try {
        echo $fn() ? "{$name} OK\n" : "{$name} FAIL\n";
    } catch (Throwable $e) {
        echo $name, ' ', get_class($e), ':', $e->getMessage(), "\n";
    }
}
