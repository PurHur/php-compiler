<?php

/**
 * Repro #31110 — duplicate class declaration (Zend/zend_compile.c).
 *
 * Zend: Fatal error: Cannot declare class C, because the name is already in use
 * VM (pre-fix): LogicException "Duplicate class definition for C"
 */
echo "before\n";
try {
    class C {}
    class C {}
    echo "after\n";
} catch (Throwable $e) {
    echo 'CAUGHT:', get_class($e), ':', $e->getMessage(), "\n";
}
echo "continued\n";
