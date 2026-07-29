<?php
/**
 * Issue #24992 — final static property override via eval under PROFILE=8.4.
 *
 * Same-script compile is covered by FinalPropertyOverrideCheck; cross-eval
 * must hit VM inheritFromParent (Zend/zend_inheritance.c).
 *
 *   PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_24992_final_static_property_eval_override.php
 *   # expect isFinal=1 then Fatal: Cannot override final property A::$x
 *   # never: override_ok
 */
class A
{
    public final static string $x = 'a';
}
echo 'isFinal=', (new ReflectionProperty('A', 'x'))->isFinal() ? '1' : '0', "\n";
eval('class B extends A { public static string $x = "b"; }');
echo "override_ok\n";
