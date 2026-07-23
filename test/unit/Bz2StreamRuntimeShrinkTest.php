<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Bz2StreamRuntime NestedJIT via JitVmHelperLink::ensureCompiled (#22416 / peer #22399).
 * Must route through Bz2StreamJitHelper PHP (#17301).
 */
final class Bz2StreamRuntimeShrinkTest extends TestCase
{
    public function testBz2StreamRuntimeUsesJitVmHelperLink(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Bz2StreamRuntime.php');
        $this->assertStringContainsString('Bz2StreamJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
    }

    public function testBz2StreamJitHelperDelegatesToVmBz2Stream(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/bz2/Bz2StreamJitHelper.php');
        $this->assertStringContainsString('VmBz2Stream', $source);
        $this->assertFileExists(__DIR__.'/../../ext/bz2/Bz2StreamJitHelper.php');
    }
}
