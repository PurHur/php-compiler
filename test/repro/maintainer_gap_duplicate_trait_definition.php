<?php

/**
 * Repro #31110 — duplicate trait declaration (Zend/zend_compile.c).
 *
 * Zend: Fatal error: Cannot declare trait T, because the name is already in use
 * VM (pre-fix): LogicException "Duplicate trait definition for T"
 */
echo "before\n";
try {
    trait T {}
    trait T {}
    echo "after\n";
} catch (Throwable $e) {
    echo 'CAUGHT:', get_class($e), ':', $e->getMessage(), "\n";
}
echo "continued\n";
