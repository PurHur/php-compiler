<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Spaceship JIT routes CompareJitHelperScalars via JitVmHelperLink (#9381, #21949).
 */
final class SpaceshipRuntimeShrinkTest extends TestCase
{
    public function testSpaceshipRuntimeUsesJitVmHelperLinkNotHandRolledNestedJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SpaceshipRuntime.php');
        $this->assertStringContainsString('CompareJitHelperScalars', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitSpaceshipCompareKernel', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
    }
}
