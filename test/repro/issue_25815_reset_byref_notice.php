<?php

/**
 * Issue #25815 — reset/end/next/prev on non-variable call results must emit
 * E_NOTICE and still evaluate (ZEND_SEND_VAR_NO_REF), not by-ref Error.
 * Inline array literals must still throw by-ref Error (#10295 / #10557).
 */
error_reporting(E_ALL);

$ao = new ArrayObject([10, 20, 30]);

try {
    var_export(reset($ao->getArrayCopy()));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    var_export(end($ao->getArrayCopy()));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

$nested = new ArrayObject([100, 200, 300]);
try {
    $copy = $nested->getArrayCopy();
    // Advance past first via next on a fresh copy temp.
    var_export(next((new ArrayObject([1, 2, 3]))->getArrayCopy()));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    reset([]);
    echo "literal: no throw\n";
} catch (Throwable $e) {
    echo 'literal: ', get_class($e), ': ', $e->getMessage(), "\n";
}

$a = [10, 20, 30];
echo 'var: ', var_export(reset($a), true), ' key=', var_export(key($a), true), "\n";
