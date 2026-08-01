<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * HtmlEntityDecodeJit NestedJIT via JitVmHelperLink::ensureCompiled (#26441 / peer #26417).
 */
final class HtmlEntityDecodeJitRuntimeShrinkTest extends TestCase
{
    public function testHtmlEntityDecodeJitUsesEnsureCompiled(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/HtmlEntityDecodeJit.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringContainsString('HtmlEntityDecodeJitHelper', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
    }

    public function testJitHtmlEntityDecodeRoutesThroughHtmlEntityDecodeJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitHtmlEntityDecode.php');
        $this->assertStringContainsString('HtmlEntityDecodeJit::decode', $source);
        $this->assertStringContainsString('HtmlEntityDecodeJit::decodeWithEncoding', $source);
    }
}
