<?php
/**
 * #25660 — eval inherit must reject abstractizing a concrete parent method.
 *
 * Expect Zend/VM/AOT compile fatal:
 *   Cannot make non abstract method A::f() abstract in class B
 *
 * @see php-src Zend/zend_inheritance.c
 */
eval('class A { public function f(): void {} }');
eval('abstract class B extends A { abstract public function f(): void; }');
echo "LOADED\n";
