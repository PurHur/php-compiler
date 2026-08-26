<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * HtmlEntityDecodeJit NestedJIT via JitVmHelperLink::ensureBridge (#26441 / #35069 / peer #26889).
 */
final class HtmlEntityDecodeJitRuntimeShrinkTest extends TestCase
{
    public function testHtmlEntityDecodeJitUsesEnsureBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/HtmlEntityDecodeJit.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringContainsString('HtmlEntityDecodeJitHelper', $source);
        $this->assertStringContainsString('__string__html_entity_decode', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $helper = (string) file_get_contents(__DIR__.'/../../ext/standard/HtmlEntityDecodeJitHelper.php');
        $this->assertStringNotContainsString('VmString::', $helper);
        $this->assertStringContainsString('decodeFrom', $helper);
    }

    public function testJitHtmlEntityDecodeRoutesThroughHtmlEntityDecodeJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitHtmlEntityDecode.php');
        $this->assertStringContainsString('HtmlEntityDecodeJit::decode', $source);
        $this->assertStringContainsString('HtmlEntityDecodeJit::decodeWithEncoding', $source);
    }
}
