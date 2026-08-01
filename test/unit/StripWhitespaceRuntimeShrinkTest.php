<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * StripWhitespace NestedJIT via JitVmHelperLink::ensureCompiled (#26351 / peer #26347).
 */
final class StripWhitespaceRuntimeShrinkTest extends TestCase
{
    public function testStripWhitespaceUsesEnsureCompiled(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StripWhitespace.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringContainsString('StripWhitespaceJitHelper', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
    }

    public function testJitStripWhitespaceRoutesThroughStripWhitespaceBuiltin(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStripWhitespace.php');
        $this->assertStringContainsString('StripWhitespace::ensureLinked', $source);
        $this->assertStringContainsString('StripWhitespace::helperFunction', $source);
    }
}
