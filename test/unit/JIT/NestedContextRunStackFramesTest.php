<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit\JIT;

use PHPCompiler\JIT\NestedContextMethodLlvm;
use PHPUnit\Framework\TestCase;

/** Nested JIT Context helpers for php-in-PHP ext helpers (#13245). */
final class NestedContextRunStackFramesTest extends TestCase
{
    public function testNestedContextRunStackFramesRegistered(): void
    {
        $this->assertTrue(
            NestedContextMethodLlvm::isNestedContextMethod('runstackframes'),
            'missing nested Context::runStackFrames() handler'
        );
    }
}
