<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_stream_set_chunk_size ABI shell from Builtin\Type (#33127).
 *
 * NestedJIT/AOT bridge stays StreamBuffer / JitStreamBufferKernel (implementIfMissing).
 * Runtime owner declares module-locally (getNamedFunction first) so leftover Type
 * empty decls cannot mint stream_set_chunk_size.1 (#31894 / #32122).
 */
final class TypeDeadStreamSetChunkSizeAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnStreamSetChunkSizeAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33127', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_stream_set_chunk_size[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_stream_set_chunk_size (#33127)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_stream_set_chunk_size'",
            $type,
            'Builtin\\Type must not always-register __compiler_stream_set_chunk_size (#33127)'
        );
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        // Next leftover sentinel (serialize_hashtable still Type always-on; #33196 str_getcsv dropped).
        $this->assertStringContainsString("registerFunction('__compiler_serialize_hashtable'", $type);
        $this->assertStringContainsString('StreamBuffer::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresStreamSetChunkSizeAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamBufferKernel.php');
        $this->assertStringContainsString('#33127', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('addFunction', $owner);
        $this->assertStringContainsString('__compiler_stream_set_chunk_size', $owner);
        $this->assertStringContainsString('implementIfMissing', $owner);
        $orchestrator = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamBuffer.php');
        $this->assertStringContainsString('#33127', $orchestrator);
        $this->assertFileExists(__DIR__.'/../../ext/standard/StreamBufferJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitStreamBufferKernel.php');
    }

    public function testTypeInitializeStillEnsureLinksStreamBuffer(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StreamBuffer::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForStreamSetChunkSizeAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/stream_set_chunk_size.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/stream_set_chunk_size.c');
    }
}
