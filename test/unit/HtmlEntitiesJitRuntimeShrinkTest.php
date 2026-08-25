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
        $this->assertSame(
            '(',
            \PHPCompiler\ext\standard\HtmlEntitiesJitHelper::encode(
                "\xC3\x28",
                ENT_QUOTES | ENT_IGNORE
            )
        );
        $this->assertSame(
            "\xEF\xBF\xBD",
            \PHPCompiler\ext\standard\HtmlEntitiesJitHelper::encode(
                "\x01",
                ENT_DISALLOWED | ENT_HTML5
            )
        );
    }

    public function testJitHtmlentitiesRoutesThroughHtmlEntitiesJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitHtmlentities.php');
        $this->assertStringContainsString('HtmlEntitiesJit::encode', $source);
    }

    public function testContextMinimalDoesNotEagerlyLinkHtmlEntities(): void
    {
        // Lazy at ext/standard/htmlentities.php (#34612 / peer #34605).
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $minimalPos = strpos($source, 'private function ensureMinimalUserStandaloneBodies');
        $this->assertNotFalse($minimalPos);
        $minimalEnd = strpos($source, 'private function ensureBootstrapAotStandaloneBodies', $minimalPos);
        $this->assertNotFalse($minimalEnd);
        $minimalBody = substr($source, $minimalPos, $minimalEnd - $minimalPos);
        $this->assertStringNotContainsString(
            'HtmlEntitiesJit::ensureStandaloneBodies',
            $minimalBody,
            'ensureMinimal must not eagerly NestedJIT htmlentities (#34612)'
        );
        $this->assertStringContainsString('#34612', $source);
        $html = (string) file_get_contents(__DIR__.'/../../ext/standard/htmlentities.php');
        $this->assertStringContainsString('HtmlEntitiesJit::ensureLinked', $html);
    }
}
