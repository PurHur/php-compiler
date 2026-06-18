<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** similar_text JIT routes through SimilarTextJitHelper PHP, not hand-written LLVM (#9731). */
final class SimilarTextJitRuntimeShrinkTest extends TestCase
{
    public function testSimilarTextJitHelperDelegatesToVmString(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/SimilarTextJitHelper.php');
        $this->assertStringContainsString('VmString::similar_text', $source);
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
