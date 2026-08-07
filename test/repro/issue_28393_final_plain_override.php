<?php
/**
 * #28393 — issue-body repro B (php-src-strict, PROFILE≥8.4).
 *
 * Expect: Fatal error — Cannot override final property A::$x (never override_ok).
 *
 * Run:
 *   PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_28393_final_plain_override.php
 *   PHP_COMPILER_PROFILE=8.4 php bin/jit.php test/repro/issue_28393_final_plain_override.php
 *   PHP_COMPILER_PROFILE=8.4 ./phpc build -o /tmp/i28393b test/repro/issue_28393_final_plain_override.php
 */
class A
{
    final public string $x = 'a';
}
class B extends A
{
    public string $x = 'c';
}
echo "override_ok\n";
