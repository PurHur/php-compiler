<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * GetimagesizeJit NestedJIT via JitVmHelperLink::ensureCompiled (#3271 / #25527 / peer #25519).
 */
final class GetimagesizeJitRuntimeShrinkTest extends TestCase
{
    public function testGetimagesizeJitUsesJitVmHelperLink(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/GetimagesizeJit.php');
        $this->assertStringContainsString('GetimagesizeJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\BasicBlockHelper;', $source);
        $this->assertLessThan(90, \substr_count($source, "\n") + 1);
    }
}
