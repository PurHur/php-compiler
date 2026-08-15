<?php
/**
 * #31168 — FCC in function-static initializer must compile-fatal on PROFILE≤8.2
 * (Zend/zend_compile.c). On 8.3+ arbitrary static init, FCC is legal (php-src).
 *
 * Run:
 *   PHP_COMPILER_PROFILE=8.2 php bin/vm.php test/repro/issue_31168_fcc_function_static_init.php
 * Expect: Constant expression contains invalid operations
 */
function f() {
    static $x = strlen(...);
    echo "ok\n";
}
f();
