<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * GetimagesizeJit stays a thin no-op; parse/HT live in LLVM (#27291 / peer #25527).
 */
final class GetimagesizeJitRuntimeShrinkTest extends TestCase
{
    public function testGetimagesizeJitIsThinNoopWithoutNestedJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/GetimagesizeJit.php');
        $this->assertStringContainsString('ensureStandaloneBodies', $source);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertLessThan(40, \substr_count($source, "\n") + 1);
        $llvm = (string) file_get_contents(__DIR__.'/../../ext/standard/GetimagesizeParseLlvm.php');
        $this->assertStringContainsString('__string__strlen', $llvm);
        $this->assertStringContainsString('#27291', $llvm);
    }
}
