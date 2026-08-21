<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_stream_supports ABI shell from Builtin\Type (#33145).
 *
 * NestedJIT/AOT bridge stays StreamIo / StreamIoRuntime / JitStreamIoKernel (implementIfMissing).
 * Runtime owner declares module-locally (getNamedFunction first) so leftover Type
 * empty decls cannot mint stream_supports.1 (#31894 / #32122).
 */
final class TypeDeadStreamSupportsAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnStreamSupportsAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33145', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_stream_supports[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_stream_supports (#33145)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_stream_supports'",
            $type,
            'Builtin\\Type must not always-register __compiler_stream_supports (#33145)'
        );
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        // Next leftover sentinel (ftell still Type always-on; stream_enable_crypto dropped in #33159).
        $this->assertStringContainsString("registerFunction('__compiler_ftell'", $type);
        $this->assertStringContainsString('StreamIo::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresStreamSupportsAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamIoKernel.php');
        $this->assertStringContainsString('#33145', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('addFunction', $owner);
        $this->assertStringContainsString('__compiler_stream_supports', $owner);
        $this->assertStringContainsString('implementIfMissing', $owner);
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamIoRuntime.php');
        $this->assertStringContainsString('#33145', $runtime);
        $this->assertStringContainsString('getNamedFunction', $runtime);
        $this->assertStringContainsString('__compiler_stream_supports', $runtime);
        $orchestrator = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamIo.php');
        $this->assertStringContainsString('#33145', $orchestrator);
        $this->assertFileExists(__DIR__.'/../../ext/standard/StreamIoJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitStreamIoKernel.php');
    }

    public function testTypeInitializeStillEnsureLinksStreamIo(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StreamIo::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForStreamSupportsAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/stream_supports.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/stream_supports.c');
    }
}
