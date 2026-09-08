<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ChunkSplitJitHelper;
use PHPCompiler\ext\standard\VmChunkSplit;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/**
 * chunk_split() thin AOT uses native phpc_chunk_split_r1 (#36388); helper remains SSOT peer.
 */
final class ChunkSplitRuntimeShrinkTest extends TestCase
{
    public function testStringChunkSplitUsesNativeR1NotNestedJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringChunkSplit.php');
        $this->assertStringContainsString('ChunkSplitRuntime', $source);
        $this->assertStringContainsString('phpc_chunk_split_r1', $source);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringNotContainsString('ensureCompiledBundle', $source);
        $this->assertStringNotContainsString('HELPER_BUNDLE', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);

        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ChunkSplitRuntime.php');
        $this->assertStringContainsString('phpc_chunk_split_r1', $runtime);
        $this->assertStringContainsString('__string__alloc', $runtime);
        $this->assertStringContainsString('memcpy', $runtime);

        $jitChunk = (string) file_get_contents(__DIR__.'/../../ext/standard/JitChunkSplit.php');
        $this->assertStringNotContainsString('chunksplit_head', $jitChunk);
        $this->assertStringNotContainsString('function split', $jitChunk);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/chunk_split.php');
        $this->assertStringContainsString('StringChunkSplit::invoke', $builtin);
        $this->assertStringContainsString('phpc_chunk_split_r1', $builtin);
        $this->assertStringContainsString('releaseEphemeralArgAfterCopy', $builtin);
        $this->assertStringNotContainsString('__compiler_chunk_split', $builtin);
        $this->assertStringNotContainsString('JitChunkSplit::split', $builtin);
    }

    public function testOwningCallResultListsChunkSplit(): void
    {
        $src = (string) file_get_contents(
            __DIR__.'/../../lib/JIT/Concern/CallResultOperandAssign.php'
        );
        $this->assertStringContainsString("'chunk_split' => true", $src);
    }

    /**
     * #30859 / #33894: NestedJIT-safe VmChunkSplit remains SSOT for VM / helper parity.
     */
    public function testChunkSplitJitHelperDelegatesToVmChunkSplit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/ChunkSplitJitHelper.php');
        $this->assertStringContainsString('VmChunkSplit::chunkSplit', $source);
        $this->assertStringNotContainsString('VmString::', $source);
        $this->assertStringNotContainsString('$string[$', $source);
        $this->assertStringNotContainsString('isset($', $source);

        $vm = (string) file_get_contents(__DIR__.'/../../ext/standard/VmChunkSplit.php');
        $this->assertStringContainsString('\\strlen(', $vm);
        $this->assertStringContainsString('\\str_split(', $vm);
        $this->assertStringContainsString('\\implode(', $vm);
        $this->assertStringNotContainsString('chunkFrom', $vm);
        $this->assertStringNotContainsString('\\substr(', $vm);
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

    public function testSpineBundleIncludesChunkSplitRuntime(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('ChunkSplitRuntime.php', $spine);
        $this->assertStringContainsString('VmChunkSplit.php', $spine);
        $this->assertStringContainsString('ChunkSplitJitHelper.php', $spine);
        $this->assertStringContainsString('StringChunkSplit.php', $spine);
    }
}
