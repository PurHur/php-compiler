<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ChunkSplitJitHelper;
use PHPCompiler\ext\standard\VmChunkSplit;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/**
 * chunk_split() JIT routes through ChunkSplitJitHelper + VmChunkSplit
 * (#14626, #21399, #26992, #30859).
 */
final class ChunkSplitRuntimeShrinkTest extends TestCase
{
    public function testStringChunkSplitUsesJitHelperBundle(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringChunkSplit.php');
        $this->assertStringContainsString('ChunkSplitJitHelper', $source);
        $this->assertStringContainsString('VmChunkSplit.php', $source);
        $this->assertStringContainsString('HELPER_BUNDLE', $source);
        $this->assertStringContainsString('ensureCompiledBundle', $source);
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

    public function testUserScriptAotForcesNestedJitOfChunkSplitHelper(): void
    {
        $cache = (string) file_get_contents(__DIR__.'/../../lib/AOT/HelperRuntimeCache.php');
        $this->assertStringContainsString(
            "phpcompiler\\\\ext\\\\standard\\\\chunksplitjithelper::chunksplitargv",
            $cache,
            'USER_SCRIPT_INLINE_ONLY must NestedJIT chunkSplitArgv — prelinked unit.o SIGSEGVs (#30859)'
        );
    }

    /** #30859: NestedJIT-safe VmChunkSplit (strlen/substr; no $s[$i]). */
    public function testChunkSplitJitHelperDelegatesToVmChunkSplit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/ChunkSplitJitHelper.php');
        $this->assertStringContainsString('VmChunkSplit::chunkSplit', $source);
        $this->assertStringNotContainsString('VmString::', $source);
        $this->assertStringNotContainsString('$string[$', $source);
        $this->assertStringNotContainsString('isset($', $source);

        $vm = (string) file_get_contents(__DIR__.'/../../ext/standard/VmChunkSplit.php');
        $this->assertStringContainsString('\\strlen(', $vm);
        $this->assertStringContainsString('\\substr(', $vm);
        $this->assertStringContainsString('chunkFrom', $vm);
        $this->assertStringNotContainsString('$string[$', $vm);
        $this->assertStringNotContainsString('isset($', $vm);
    }

    public function testChunkSplitJitHelperMatchesVmString(): void
    {
        $expected = VmString::chunkSplit('1234567890', 3, '-');
        $this->assertSame($expected, ChunkSplitJitHelper::chunkSplitArgv('1234567890', 3, '-'));
        $this->assertSame($expected, VmChunkSplit::chunkSplit('1234567890', 3, '-'));
        $this->assertSame(
            VmString::chunkSplit('123456789', 3, ':'),
            ChunkSplitJitHelper::chunkSplitArgv('123456789', 3, ':')
        );
        $this->assertSame(
            VmString::chunkSplit('', 4, "\r\n"),
            ChunkSplitJitHelper::chunkSplitArgv('', 4, "\r\n")
        );
        $this->assertSame('ab:cd:', ChunkSplitJitHelper::chunkSplitArgv('abcd', 2, ':'));
    }

    public function testSpineBundleIncludesVmChunkSplit(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('VmChunkSplit.php', $spine);
        $this->assertStringContainsString('ChunkSplitJitHelper.php', $spine);
        $this->assertStringContainsString('StringChunkSplit.php', $spine);
    }
}
