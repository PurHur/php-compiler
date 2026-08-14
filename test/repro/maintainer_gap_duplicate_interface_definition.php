<?php

/**
 * Repro #31110 — duplicate interface declaration (Zend/zend_compile.c).
 *
 * Zend: Fatal error: Cannot declare interface I, because the name is already in use
 * VM (pre-fix): LogicException "Duplicate interface definition for I"
 */
echo "before\n";
try {
    interface I {}
    interface I {}
    echo "after\n";
} catch (Throwable $e) {
    echo 'CAUGHT:', get_class($e), ':', $e->getMessage(), "\n";
}
echo "continued\n";
