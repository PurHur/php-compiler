<?php
/**
 * Issue #27122 (re-#26339) — child cannot redeclare a final plain property.
 *
 * php-src-strict (PROFILE=8.4): Zend/zend_inheritance.c
 * "Cannot override final property %s::$%s".
 *
 *   PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer-gaps/final_prop_override.php
 *
 * Expect exit 255: Cannot override final property C::$name
 */
class C
{
    public final string $name = 'x';
}
class D extends C
{
    public string $name = 'y';
}
echo (new D())->name, "\n";
