<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** similar_text JIT/AOT via JitSimilarTextKernel (#30810; NestedJIT helper retired for thin AOT). */
final class SimilarTextJitRuntimeShrinkTest extends TestCase
{
    public function testSimilarTextJitHelperDoesNotCallVmString(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/SimilarTextJitHelper.php');
        // Host/unit SSOT peel — must not call VmString (legacy NestedJIT stub → 0).
        $this->assertStringNotContainsString('VmString::similar_text(', $source);
        $this->assertStringContainsString('strlen', $source);
    }

    public function testStringSimilarTextRoutesThroughJitSimilarTextKernel(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringSimilarText.php');
        $this->assertStringContainsString('JitSimilarTextKernel', $source);
        $this->assertStringNotContainsString('SimilarTextJitHelper::', $source);
        $this->assertStringNotContainsString('JitVmHelperLink', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertLessThan(50, \substr_count($source, "\n") + 1);

        $kernel = (string) \file_get_contents(__DIR__.'/../../ext/standard/JitSimilarTextKernel.php');
        $this->assertStringContainsString('__phpc_similar_char', $kernel);
        $this->assertStringContainsString('__phpc_similar_str', $kernel);
        $this->assertStringContainsString('phpc_similar_text', $kernel);
        // No 255-byte cap (#18543).
        $this->assertStringNotContainsString('MAX_LEN', $kernel);
        $this->assertStringNotContainsString('PHPC_SIM_MAX_LEN', $kernel);
        $this->assertStringNotContainsString('too_long', $kernel);
        $this->assertStringNotContainsString('sim_too_long', $kernel);

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
