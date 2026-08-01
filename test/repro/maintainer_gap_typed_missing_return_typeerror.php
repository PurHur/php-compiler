<?php

/**
 * Repro for #26486 — typed non-void missing return must TypeError with Zend "none returned"
 * (Zend/zend_execute.c zend_verify_return_error). Companion to #26485 (`: mixed`).
 */

function f(): int
{
}

try {
    f();
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
