<?php
/**
 * #28523 — PROFILE≥8.4 sibling child cannot override final plain property.
 *
 * php-src: Zend/zend_inheritance.c — Cannot override final property A::$x
 * Expect: Fatal (never OVERRIDE_OK).
 *
 * Run:
 *   PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_28523_final_plain_property_84.php
 *   PHP_COMPILER_PROFILE=8.4 php bin/jit.php test/repro/issue_28523_final_plain_property_84.php
 */
class A { public final int $x = 1; }
class B extends A { public int $x = 2; }
echo "OVERRIDE_OK B::x=", (new B)->x, "\n";
