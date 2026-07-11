<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Support;

use PHPCompiler\CompilerVersion;

/** Skip builtin stub enum tests on Zend 8.2 reference profile (#13630). */
trait BuiltinStubEnumTestSkip
{
    protected function skipUnlessBuiltinStubEnumsEnabled(): void
    {
        if (!CompilerVersion::supportsBuiltinStubEnums()) {
            self::markTestSkipped('builtin stub enums withheld on reference profile (#13630)');
        }
    }
}
