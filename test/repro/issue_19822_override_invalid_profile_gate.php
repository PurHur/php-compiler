<?php

declare(strict_types=1);

/**
 * Issue #19822 — #[\Override] on a non-overriding method is inert on the 8.2 reference
 * profile and CompileError under PHP_COMPILER_PROFILE=8.3 / 8.4 (Zend/zend_compile.c).
 *
 * Run:
 *   unset PHP_COMPILER_PROFILE
 *   php bin/vm.php test/repro/issue_19822_override_invalid_profile_gate.php
 *   # → ok
 *
 *   PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_19822_override_invalid_profile_gate.php
 *   # → parseAndCompile failure: B::g() has #[\Override] …
 */
class A
{
    public function f(): int
    {
        return 1;
    }
}

class B extends A
{
    #[\Override]
    public function g(): int
    {
        return 2;
    }
}

echo "ok\n";
