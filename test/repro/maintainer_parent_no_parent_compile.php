<?php

declare(strict_types=1);

/**
 * Issue #7381 — parent:: in class without parent must compile-fatal (zend_compile.c).
 */
class C
{
    public function f(): void
    {
        parent::g();
    }
}
