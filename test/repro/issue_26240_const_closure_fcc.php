<?php
/**
 * Issue #26240: Closures / first-class callables in constant expressions under PROFILE=8.5.
 *
 * Run: PHP_COMPILER_PROFILE=8.5 php bin/vm.php test/repro/issue_26240_const_closure_fcc.php
 */
const C = static fn(int $x): int => $x + 1;
echo 'C=', (C)(2), "\n";
const D = strlen(...);
echo 'D=', (D)('ab'), "\n";
const E = static function (int $x): int {
    return $x + 1;
};
echo 'E=', (E)(2), "\n";
