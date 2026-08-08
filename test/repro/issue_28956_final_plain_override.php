<?php
/**
 * #28956 — PROFILE≥8.4 child cannot override final plain property (php-src-strict).
 *
 * Re-#28816. Exact issue-body: sibling class redeclares `public string $x`.
 * php-src: Zend/zend_inheritance.c — Cannot override final property A::$x
 * Expect: Fatal (never "overridden").
 *
 * Run:
 *   PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_28956_final_plain_override.php
 *   PHP_COMPILER_PROFILE=8.4 php bin/jit.php test/repro/issue_28956_final_plain_override.php
 */
class A { final public string $x = "a"; }
class B extends A { public string $x = "b"; }
echo "overridden\n";
