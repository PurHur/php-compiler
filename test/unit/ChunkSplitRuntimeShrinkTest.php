<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ChunkSplitJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** chunk_split() JIT routes through ChunkSplitJitHelper PHP not inline LLVM (#14626, #21399). */
final class ChunkSplitRuntimeShrinkTest extends TestCase
{
    public function testStringChunkSplitUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringChunkSplit.php');
        $this->assertStringContainsString('ChunkSplitJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);

        $jitChunk = (string) file_get_contents(__DIR__.'/../../ext/standard/JitChunkSplit.php');
        $this->assertStringNotContainsString('chunksplit_head', $jitChunk);
        $this->assertStringNotContainsString('function split', $jitChunk);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/chunk_split.php');
        $this->assertStringContainsString('StringChunkSplit::ensureLinked', $builtin);
        $this->assertStringContainsString('__compiler_chunk_split', $builtin);
        $this->assertStringNotContainsString('JitChunkSplit::split', $builtin);
    }

    /** #26992: NestedJIT-safe self-contained helper (no VmString ExternalMethod stub). */
    public function testChunkSplitJitHelperIsSelfContainedAndMatchesVmString(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/ChunkSplitJitHelper.php');
        $this->assertStringNotContainsString('VmString::', $source);
        $this->assertStringContainsString('Self-contained', $source);

        $expected = VmString::chunkSplit('1234567890', 3, '-');
        $this->assertSame($expected, ChunkSplitJitHelper::chunkSplitArgv('1234567890', 3, '-'));
        $this->assertSame(
            VmString::chunkSplit('123456789', 3, ':'),
            ChunkSplitJitHelper::chunkSplitArgv('123456789', 3, ':')
        );
        $this->assertSame(
            VmString::chunkSplit('', 4, "\r\n"),
            ChunkSplitJitHelper::chunkSplitArgv('', 4, "\r\n")
        );
    }

    public function testSpineBundleIncludesChunkSplitJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('ChunkSplitJitHelper.php', $spine);
        $this->assertStringContainsString('StringChunkSplit.php', $spine);
    }
}
