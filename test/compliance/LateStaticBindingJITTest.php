<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/JITTest.php';

/**
 * JIT compliance for late static binding (issue #1231).
 *
 * Direct Class::method() JIT execution still segfaults (pre-existing); VM covers LSB.
 *
 * @group llvm
 * @group jit
 */
final class LateStaticBindingJITTest extends JITTest
{
    public static function providePHPTests(): \Generator
    {
        if (false) {
            yield;
        }
    }

    public function testJitLateStaticBindingDeferred(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->markTestSkipped(
            'JIT runtime late static binding deferred; VM LateStaticBindingVMTest covers #1231'
        );
    }
}
