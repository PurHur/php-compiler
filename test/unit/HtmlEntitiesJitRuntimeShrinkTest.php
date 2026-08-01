<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * HtmlEntitiesJit NestedJIT via JitVmHelperLink::ensureCompiled (#26417 / peer #25541).
 */
final class HtmlEntitiesJitRuntimeShrinkTest extends TestCase
{
    public function testHtmlEntitiesJitUsesEnsureCompiled(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/HtmlEntitiesJit.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringContainsString('HtmlEntitiesJitHelper', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
    }

    public function testJitHtmlentitiesRoutesThroughHtmlEntitiesJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitHtmlentities.php');
        $this->assertStringContainsString('HtmlEntitiesJit::encode', $source);
    }
}
