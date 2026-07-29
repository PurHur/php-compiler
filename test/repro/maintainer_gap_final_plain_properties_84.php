<?php
/**
 * Issue #24770 (re-#23665) — final plain properties under PROFILE=8.4.
 *
 * php-src-strict (Zend 8.4 / Zend/zend_compile.c + zend_inheritance.c +
 * ext/reflection/php_reflection.c):
 * - ReflectionProperty::isFinal() true for instance + static final props
 * - child redeclaration of a final plain property is compile-time Fatal
 *
 * Ternaries between class decls put the child Class_ in a successor CFG block;
 * FinalPropertyOverrideCheck must still see it (#24770).
 *
 *   PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_gap_final_plain_properties_84.php
 *
 * Expect exit 255: Cannot override final property A::$x (no override_allowed=1).
 */
class A
{
    public final string $x = 'a';
}
echo 'instance_isFinal=', (new ReflectionProperty('A', 'x'))->isFinal() ? '1' : '0', "\n";

class S
{
    public final static string $s = 's';
}
echo 'static_isFinal=', (new ReflectionProperty('S', 's'))->isFinal() ? '1' : '0', "\n";

class B extends A
{
    public string $x = 'b';
}
echo "override_allowed=1\n";
exit(1);
