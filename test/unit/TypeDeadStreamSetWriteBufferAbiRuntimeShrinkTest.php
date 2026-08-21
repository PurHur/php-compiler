<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_stream_set_write_buffer ABI shell from Builtin\Type (#33139).
 *
 * NestedJIT/AOT bridge stays StreamBuffer / JitStreamBufferKernel (implementIfMissing).
 * Runtime owner declares module-locally (getNamedFunction first) so leftover Type
 * empty decls cannot mint stream_set_write_buffer.1 (#31894 / #32122).
 */
final class TypeDeadStreamSetWriteBufferAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnStreamSetWriteBufferAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33139', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_stream_set_write_buffer[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_stream_set_write_buffer (#33139)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_stream_set_write_buffer'",
            $type,
            'Builtin\\Type must not always-register __compiler_stream_set_write_buffer (#33139)'
        );
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        // Next leftover sentinel (fseek still Type always-on; stream_get_line dropped in #33170).
        $this->assertStringContainsString("registerFunction('__compiler_fseek'", $type);
        $this->assertStringContainsString('StreamBuffer::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresStreamSetWriteBufferAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamBufferKernel.php');
        $this->assertStringContainsString('#33139', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('addFunction', $owner);
        $this->assertStringContainsString('__compiler_stream_set_write_buffer', $owner);
        $this->assertStringContainsString('implementIfMissing', $owner);
        $orchestrator = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamBuffer.php');
        $this->assertStringContainsString('#33139', $orchestrator);
        $this->assertFileExists(__DIR__.'/../../ext/standard/StreamBufferJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitStreamBufferKernel.php');
    }

    public function testTypeInitializeStillEnsureLinksStreamBuffer(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StreamBuffer::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForStreamSetWriteBufferAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/stream_set_write_buffer.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/stream_set_write_buffer.c');
    }
}
