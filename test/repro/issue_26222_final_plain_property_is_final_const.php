<?php
/**
 * Issue #26222 — final plain properties under PROFILE=8.4:
 *   isFinal()===true, ReflectionProperty::IS_FINAL===32, writes allowed (inheritance-only),
 *   child override Fatal. Reference profile rejects `final` on properties (Zend 8.2).
 *
 * php-src: Zend/zend_inheritance.c (override); ext/reflection (isFinal / IS_FINAL);
 * writes are not blocked (no "Cannot modify final property" in zend_object_handlers.c).
 *
 * Run:
 *   PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_26222_final_plain_property_is_final_const.php
 *   # expect: isFinal=1 IS_FINAL=32 bit=1 wrote=2
 */
class C
{
    public final int $x = 1;
}
$r = new ReflectionProperty('C', 'x');
echo 'isFinal=', $r->isFinal() ? '1' : '0', "\n";
echo 'IS_FINAL=', ReflectionProperty::IS_FINAL, "\n";
echo 'bit=', ($r->getModifiers() & ReflectionProperty::IS_FINAL) ? '1' : '0', "\n";
$c = new C();
$c->x = 2;
echo 'wrote=', $c->x, "\n";
