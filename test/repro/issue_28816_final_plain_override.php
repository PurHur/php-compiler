<?php
/**
 * #28816 — PROFILE≥8.4 child cannot override final plain property (php-src-strict).
 *
 * Re-#28627. php-src: Zend/zend_inheritance.c — Cannot override final property A::$x
 * Expect: Fatal (never overridden).
 *
 * Run:
 *   PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_28816_final_plain_override.php
 *   PHP_COMPILER_PROFILE=8.4 php bin/jit.php test/repro/issue_28816_final_plain_override.php
 */
class A { final public int $x = 1; }
class B extends A { public int $x = 2; }
echo "overridden\n";
