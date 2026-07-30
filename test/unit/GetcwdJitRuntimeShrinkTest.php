<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * GetcwdJit NestedJIT via JitVmHelperLink::ensureCompiled (#10451 / #25541 / peer #25527).
 */
final class GetcwdJitRuntimeShrinkTest extends TestCase
{
    public function testGetcwdJitUsesJitVmHelperLink(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/GetcwdJit.php');
        $this->assertStringContainsString('GetcwdJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\BasicBlockHelper;', $source);
        $this->assertLessThan(60, \substr_count($source, "\n") + 1);
    }
}
