<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_stream_enable_crypto ABI shell from Builtin\Type (#33159).
 *
 * NestedJIT/AOT bridge stays StreamMeta / JitStreamMetaKernel / JitStreamMetaThinAot.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover Type
 * empty decls cannot mint stream_enable_crypto.1 (#31894 / #32122).
 */
final class TypeDeadStreamEnableCryptoAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnStreamEnableCryptoAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33159', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_stream_enable_crypto[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_stream_enable_crypto (#33159)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_stream_enable_crypto'",
            $type,
            'Builtin\\Type must not always-register __compiler_stream_enable_crypto (#33159)'
        );
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        // Next leftover sentinel (assert_fail_string still Type always-on; #33234 trigger_error / #33237 assert_fail dropped).
        $this->assertStringContainsString("registerFunction('__compiler_assert_fail_string'", $type);
        $this->assertStringContainsString('StreamMeta::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresStreamEnableCryptoAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamMetaKernel.php');
        $this->assertStringContainsString('#33159', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('addFunction', $owner);
        $this->assertStringContainsString('__compiler_stream_enable_crypto', $owner);
        $this->assertStringContainsString('implementIfMissing', $owner);
        $orchestrator = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamMeta.php');
        $this->assertStringContainsString('#33159', $orchestrator);
        $this->assertStringContainsString('JitStreamMetaKernel', $orchestrator);
        $this->assertFileExists(__DIR__.'/../../ext/standard/StreamMetaJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitStreamEnableCrypto.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitStreamMetaKernel.php');
    }

    public function testTypeInitializeStillEnsureLinksStreamMeta(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StreamMeta::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForStreamEnableCryptoAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/stream_enable_crypto.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/stream_enable_crypto.c');
    }
}
