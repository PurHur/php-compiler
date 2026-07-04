<?php

declare(strict_types=1);

/**
 * Issue #15873 — static local anonymous class initializer must compile-fatal (Zend/zend_compile.c).
 */
function f(): void
{
    static $x = new class {};
    echo "ok\n";
}

f();
