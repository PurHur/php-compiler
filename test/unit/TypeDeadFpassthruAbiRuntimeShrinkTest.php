<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_fpassthru ABI shell from Builtin\Type (#33106).
 *
 * NestedJIT/AOT bridge stays StreamReadRuntime / JitStreamReadBridgeKernel /
 * StreamReadJitHelper (implementI64Bridge). Runtime owner declares module-locally
 * (getNamedFunction first) so leftover Type empty decls cannot mint fpassthru.1
 * (#31894 / #32122).
 */
final class TypeDeadFpassthruAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnFpassthruAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33106', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_fpassthru[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_fpassthru (#33106)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_fpassthru'",
            $type,
            'Builtin\\Type must not always-register __compiler_fpassthru (#33106)'
        );
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        // Next leftover sentinel (stream_supports still Type always-on; read_buffer dropped in #33142).
        $this->assertStringContainsString("registerFunction('__compiler_stream_supports'", $type);
        $this->assertStringContainsString('StreamRead::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresFpassthruAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamReadRuntime.php');
        $this->assertStringContainsString('#33106', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('addFunction', $owner);
        $this->assertStringContainsString('__compiler_fpassthru', $owner);
        $this->assertStringContainsString('implementI64Bridge', $owner);
        $this->assertFileExists(__DIR__.'/../../ext/standard/StreamReadJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitStreamReadBridgeKernel.php');
    }

    public function testTypeInitializeStillEnsureLinksStreamRead(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StreamRead::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForFpassthruAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/fpassthru.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/fpassthru.c');
    }
}
