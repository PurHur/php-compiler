<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Skip property-hook runtime tests when Zend 8.2 reference profile rejects hook syntax (#12574). */
trait PropertyHookProfileSkipTrait
{
    protected function skipUnlessPropertyHooks(): void
    {
        if (!CompilerVersion::supportsPropertyHooks()) {
            $this->markTestSkipped('property hooks disabled on Zend 8.2 reference profile (#12574)');
        }
    }
}
