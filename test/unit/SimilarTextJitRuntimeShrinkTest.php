<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** similar_text JIT NestedJIT via JitVmHelperLink::ensureCompiled (#9731 / #25784). */
final class SimilarTextJitRuntimeShrinkTest extends TestCase
{
    public function testSimilarTextJitHelperDoesNotCallVmString(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/SimilarTextJitHelper.php');
        // Docblock may @see VmString; the call itself must stay local (#26897 NestedJIT stub → 0).
        $this->assertStringNotContainsString('VmString::similar_text(', $source);
        $this->assertStringContainsString('strlen', $source);
    }

    public function testStringSimilarTextJitRoutesThroughSimilarTextJitHelper(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringSimilarText.php');
        $this->assertStringContainsString('SimilarTextJitHelper', $source);
        $this->assertStringNotContainsString('emitSimilarChar', $source);
        $this->assertStringNotContainsString('emitSimilarStr', $source);
        $this->assertStringNotContainsString('__phpc_similar_char', $source);
        $this->assertStringNotContainsString('__phpc_similar_str', $source);

        $jitShim = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringSimilarTextJit.php');
        $this->assertLessThan(20, \substr_count($jitShim, "\n"), 'StringSimilarTextJit must be a thin shim');
    }

    public function testStringSimilarTextRoutesThroughEnsureCompiled(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringSimilarText.php');
        $this->assertStringContainsString('SimilarTextJitHelper::compute', $source);
        $this->assertStringContainsString('phpc_similar_text', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $this->assertLessThan(130, \substr_count($source, "\n") + 1);
    }

    public function testJitSimilarTextRoutesThroughStringSimilarText(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/similar_text.php');
        $this->assertStringContainsString('StringSimilarText::ensureLinked', $source);
    }

    public function testSimilarTextJitHelperSemanticsMatchVmString(): void
    {
        $this->assertSame(
            6,
            \PHPCompiler\ext\standard\SimilarTextJitHelper::compute('Hello World', 'Hello PHP')
        );
        $this->assertSame(
            4,
            \PHPCompiler\ext\standard\SimilarTextJitHelper::compute('kitten', 'sitting')
        );
        $this->assertSame(0, \PHPCompiler\ext\standard\SimilarTextJitHelper::compute('', ''));
    }
}
