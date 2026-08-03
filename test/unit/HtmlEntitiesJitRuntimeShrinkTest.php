<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * HtmlEntitiesJit NestedJIT via JitVmHelperLink::ensureBridge (#26417 / #26889 / peer htmlspecialchars).
 */
final class HtmlEntitiesJitRuntimeShrinkTest extends TestCase
{
    public function testHtmlEntitiesJitUsesEnsureBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/HtmlEntitiesJit.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringContainsString('__string__htmlentities', $source);
        $this->assertStringContainsString('HtmlEntitiesJitHelper', $source);
        $this->assertStringContainsString('ensureStandaloneBodies', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
    }

    public function testHtmlEntitiesJitHelperIsSelfContainedRecursive(): void
    {
        $helper = (string) file_get_contents(__DIR__.'/../../ext/standard/HtmlEntitiesJitHelper.php');
        $this->assertStringContainsString('escapeFrom', $helper);
        $this->assertStringContainsString('entitiesEntQuotesCore', $helper);
        $this->assertStringNotContainsString('VmString::', $helper);
        $this->assertStringNotContainsString('for (', $helper);
        $out = \PHPCompiler\ext\standard\HtmlEntitiesJitHelper::encode(
            '<x>&',
            ENT_QUOTES | ENT_SUBSTITUTE
        );
        $this->assertSame('&lt;x&gt;&amp;', $out);
        $euro = \PHPCompiler\ext\standard\HtmlEntitiesJitHelper::encode(
            '<€>',
            ENT_QUOTES | ENT_SUBSTITUTE
        );
        $this->assertSame('&lt;&euro;&gt;', $euro);
    }

    public function testJitHtmlentitiesRoutesThroughHtmlEntitiesJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitHtmlentities.php');
        $this->assertStringContainsString('HtmlEntitiesJit::encode', $source);
    }

    public function testContextRegistersHtmlEntitiesStandaloneBodies(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('HtmlEntitiesJit::ensureStandaloneBodies', $source);
    }
}
