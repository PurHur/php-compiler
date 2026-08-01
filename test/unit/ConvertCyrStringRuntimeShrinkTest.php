<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ConvertCyrString NestedJIT via JitVmHelperLink::ensureCompiled (#26395 / peer #26351).
 */
final class ConvertCyrStringRuntimeShrinkTest extends TestCase
{
    public function testConvertCyrStringUsesEnsureCompiled(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ConvertCyrString.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringContainsString('ConvertCyrStringJitHelper', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
    }

    public function testJitConvertCyrStringRoutesThroughConvertCyrStringBuiltin(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitConvertCyrString.php');
        $this->assertStringContainsString('ConvertCyrString::ensureLinked', $source);
        $this->assertStringContainsString('ConvertCyrString::helperFunction', $source);
    }
}
