<?php

/**
 * Repro: class_alias() excess argc → ArgumentCountError (#30783).
 *
 * php-src: Zend/zend_builtin_functions.c — PHP_FUNCTION(class_alias)
 *
 * VM:  php bin/vm.php test/repro/issue_30783_class_alias_excess_argc.php
 * JIT: php bin/jit.php test/repro/issue_30783_class_alias_excess_argc.php
 * AOT: php bin/compile.php -o /tmp/ca30783 test/repro/issue_30783_class_alias_excess_argc_aot.php && /tmp/ca30783
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
echo 'exists:', var_export(class_exists('C', false), true), "\n";
