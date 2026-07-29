<?php
/**
 * Issue #24992 — PROFILE=8.4 final plain property Reflection + child override.
 *
 * php-src-strict (Zend 8.4 / Zend/zend_compile.c + zend_inheritance.c +
 * ext/reflection/php_reflection.c):
 * - ReflectionProperty::isFinal() true
 * - eval child redeclaration is compile Fatal (not override_ok)
 *
 *   PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_gap_final_property_profile84.php
 */
class A
{
    public final string $x = 'a';
}
echo 'isFinal=', (new ReflectionProperty('A', 'x'))->isFinal() ? '1' : '0', "\n";
eval('class B extends A { public string $x = "b"; }');
echo "override_ok\n";
