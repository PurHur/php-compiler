<?php
/**
 * Issue #25633 — by-ref ↔ by-value method override must compile-fatal (zend_inheritance.c).
 *
 *   php test/repro/issue_25633_byref_override.php
 *   php bin/vm.php test/repro/issue_25633_byref_override.php
 */
class A { public function f(&$x) {} }
class B extends A { public function f($x) {} }
echo "accepted\n";
