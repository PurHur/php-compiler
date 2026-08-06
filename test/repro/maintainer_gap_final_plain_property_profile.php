<?php
/**
 * #28149 — final plain properties vs PHP_COMPILER_PROFILE (php-src-strict).
 *
 * php-src: Zend/zend_compile.c (final property declare), Zend/zend_inheritance.c
 * (child override), ext/reflection/php_reflection.stub.php (isFinal).
 *
 * Reference / unset profile (Zend 8.2): compile Fatal on the declaration —
 * never print parsed / isFinal / child_ok.
 *
 * PROFILE≥8.4: declaration accepted, ReflectionProperty::isFinal() true, then
 * eval child redeclaration is Fatal (never child_ok). Child is eval'd so isFinal
 * is observable before the inheritance check (same shape as #26339).
 *
 * Run:
 *   php bin/vm.php test/repro/maintainer_gap_final_plain_property_profile.php
 *   PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_gap_final_plain_property_profile.php
 *   PHP_COMPILER_PROFILE=8.4 php bin/jit.php test/repro/maintainer_gap_final_plain_property_profile.php
 */
class A
{
    public final string $x = 'a';
}
echo "parsed\n";
$r = new ReflectionProperty('A', 'x');
echo 'isFinal=', $r->isFinal() ? '1' : '0', "\n";
eval('class B extends A { public string $x = "b"; }');
echo "child_ok\n";
