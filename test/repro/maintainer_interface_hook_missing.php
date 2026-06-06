<?php
declare(strict_types=1);

/**
 * Issue #6965 / #6770: interface property hooks — implementing class must declare property.
 *
 * Zend reference: Zend/zend_compile.c + Zend/zend_property_hooks.c
 */
interface I {
    public int $x { get; set; }
}
class Bad implements I {
    public int $y = 1; // missing $x
}
