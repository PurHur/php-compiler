<?php
/**
 * Issue #25912 — concrete class using trait with unimplemented abstract method.
 *
 * Zend runs preceding statements, then E_COMPILE_ERROR at class DECLARE
 * (Zend/zend_compile.c / zend_inheritance.c zend_verify_abstract_class).
 *
 * Expected (php-src-strict):
 *   stdout: before
 *   stderr: PHP Fatal error:  Class C contains 1 abstract method… (C::f)
 *   exit 255; no "accepted"
 */
trait TAbs
{
    abstract public function f();
}
echo "before\n";
class C
{
    use TAbs;
}
echo "accepted\n";
