<?php

/**
 * AOT-only repro: direct calls (no variable-function) — #30783.
 *
 * php-src: Zend/zend_builtin_functions.c — PHP_FUNCTION(class_alias)
 *
 * AOT: php bin/compile.php -o /tmp/ca30783aot test/repro/issue_30783_class_alias_excess_argc_aot.php && /tmp/ca30783aot
 */
class A
{
}
try {
    class_alias('A', 'B', true, 1);
    echo "excess:NO_THROW\n";
} catch (Throwable $e) {
    echo 'excess:', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    class_alias('A');
    echo "short:NO_THROW\n";
} catch (Throwable $e) {
    echo 'short:', get_class($e), ':', $e->getMessage(), "\n";
}
echo 'ok:', var_export(class_alias('A', 'C'), true), "\n";
