<?php
/**
 * Issue #28374 / re-#6965/#6770: interface hooked properties must be declared on implementors.
 *
 * Zend reference: Zend/zend_inheritance.c, Zend/zend_property_hooks.c
 */
interface I {
    public string $name { get; set; }
}
class Good implements I {
    public string $name = 'g';
}
echo (new Good())->name, "\n";
class BadI implements I {}
echo "BadI ok\n";
new BadI();
