<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_stream_copy_to_stream ABI shell from Builtin\Type (#33182).
 *
 * NestedJIT/AOT bridge stays StreamRead / StreamReadRuntime / JitStreamReadBridgeKernel.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover Type
 * empty decls cannot mint stream_copy_to_stream.1 (#31894 / #32122).
 */
final class TypeDeadStreamCopyToStreamAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnStreamCopyToStreamAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33182', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_stream_copy_to_stream[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_stream_copy_to_stream (#33182)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_stream_copy_to_stream'",
            $type,
            'Builtin\\Type must not always-register __compiler_stream_copy_to_stream (#33182)'
        );
        // No further Type always-on leftover after #33267 exit/abort drop.
        $this->assertDoesNotMatchRegularExpression(
            '/(?<![A-Za-z0-9_])StreamRead::ensureLinked\(\$this->context\)/',
            $type,
            'Type must not eagerly StreamRead::ensureLinked($this->context)'
        );
    }

    public function testRuntimeOwnerDeclaresStreamCopyToStreamAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamReadBridgeKernel.php');
        $this->assertStringContainsString('#33182', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('addFunction', $owner);
        $this->assertStringContainsString('implementI64Bridge', $owner);
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamReadRuntime.php');
        $this->assertStringContainsString('#33182', $runtime);
        $this->assertStringContainsString('getNamedFunction', $runtime);
        $this->assertStringContainsString('__compiler_stream_copy_to_stream', $runtime);
        $orchestrator = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamRead.php');
        $this->assertStringContainsString('#33182', $orchestrator);
        $this->assertFileExists(__DIR__.'/../../ext/standard/StreamReadJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitStreamReadBridgeKernel.php');
        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamCopyToStream.php');
        $this->assertStringContainsString('#33182', $jit);
        $this->assertStringContainsString('StreamReadRuntime::ensureLinked', $jit);
    }

    public function testTypeInitializeDropsEagerStreamReadEnsureLinked(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertDoesNotMatchRegularExpression(
            '/(?<![A-Za-z0-9_])StreamRead::ensureLinked\(\$this->context\)/',
            $type,
            'Type must not eagerly StreamRead::ensureLinked($this->context)'
        );
    }

    public function testNoNewRuntimeCForStreamCopyToStreamAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/stream_copy_to_stream.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/stream_copy_to_stream.c');
    }
}
