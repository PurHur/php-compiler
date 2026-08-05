<?php
/**
 * #27818 — trait-imported final plain property under PROFILE=8.4 (php-src-strict).
 *
 * Thin AOT previously left isFinal=0 and accepted child overrides because
 * inheritTraitInstanceProperties did not copy ZEND_ACC_FINAL into
 * Object_::finalPropertyNames (unlike class-declared finals / #27315).
 *
 * php-src: Zend/zend_compile.c, Zend/zend_inheritance.c, Zend/zend_traits.c,
 * ext/reflection/php_reflection.c
 *
 * Run:
 *   PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_gap_final_plain_property_trait_84.php
 *   PHP_COMPILER_PROFILE=8.4 php bin/jit.php test/repro/maintainer_gap_final_plain_property_trait_84.php
 *   PHP_COMPILER_PROFILE=8.4 ./phpc build -o /tmp/ft84 test/repro/maintainer_gap_final_plain_property_trait_84.php && /tmp/ft84
 */
trait T
{
    final public string $x = 't';
}

class A
{
    use T;
}

$a = new A();
$a->x = 'z';
echo "wrote\n";

$rp = new ReflectionProperty(A::class, 'x');
echo 'isFinal=', (int) $rp->isFinal(), "\n";
