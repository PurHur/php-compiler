<?php
/**
 * Issue #25457 — PROFILE=8.4 child override of a final plain property must emit
 * Zend-shaped Fatal error (not "parseAndCompile failure: …").
 *
 * php-src: Zend/zend_inheritance.c — "Cannot override final property %s::$%s"
 *
 *   PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_25457_final_plain_property_override_zend_fatal.php
 *   # expect exit 255 + Fatal error: Cannot override final property C::$x in … on line …
 *   # never: override_ok / parseAndCompile failure
 */
class C
{
    public final string $x = 'a';
}
class D extends C
{
    public string $x = 'b';
}
echo "override_ok\n";
