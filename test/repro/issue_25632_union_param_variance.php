<?php
/**
 * Issue #25632 — union → narrower param override must compile-fatal (zend_inheritance.c).
 *
 * Zend: Declaration of B::f(int $x): void must be compatible with A::f(string|int $x): void
 * Before fix: VM printed "accepted".
 */
class A { public function f(int|string $x): void {} }
class B extends A { public function f(int $x): void {} }
echo "accepted\n";
