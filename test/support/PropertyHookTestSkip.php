<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Support;

use PHPCompiler\CompilerVersion;

/** Skip property-hook pipeline tests on Zend 8.2 reference profile (#12574). */
trait PropertyHookTestSkip
{
    protected function skipUnlessPropertyHooksEnabled(): void
    {
        if (!CompilerVersion::supportsPropertyHooks()) {
            self::markTestSkipped('property hooks disabled on reference profile (#12574)');
        }
    }
}
