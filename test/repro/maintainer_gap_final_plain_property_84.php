<?php
/**
 * #27315 — final plain properties under PROFILE=8.4 (php-src-strict).
 *
 * Zend 8.4+: `final` is inheritance-only (post-construct writes succeed);
 * ReflectionProperty::isFinal() is true. Thin AOT previously returned isFinal=0
 * because ReflectionProperty skipped __construct / TYPE_STRING slots dropped boxes.
 *
 * php-src: Zend/zend_compile.c, Zend/zend_inheritance.c, ext/reflection/php_reflection.c
 *
 * Run:
 *   PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_gap_final_plain_property_84.php
 *   PHP_COMPILER_PROFILE=8.4 php bin/jit.php test/repro/maintainer_gap_final_plain_property_84.php
 *   PHP_COMPILER_PROFILE=8.4 ./phpc build -o /tmp/f84 test/repro/maintainer_gap_final_plain_property_84.php && /tmp/f84
 */
class A
{
    final public string $x = 'a';
}

$a = new A();
$a->x = 'b';
echo "wrote\n";

$rp = new ReflectionProperty(A::class, 'x');
echo 'isFinal=', (int) $rp->isFinal(), "\n";
