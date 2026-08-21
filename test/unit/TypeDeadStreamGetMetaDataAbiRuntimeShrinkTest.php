<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_stream_get_meta_data ABI shell from Builtin\Type (#33154).
 *
 * NestedJIT/AOT bridge stays StreamMeta / JitStreamMetaKernel / JitStreamMetaThinAot.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover Type
 * empty decls cannot mint stream_get_meta_data.1 (#31894 / #32122).
 */
final class TypeDeadStreamGetMetaDataAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnStreamGetMetaDataAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33154', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_stream_get_meta_data[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_stream_get_meta_data (#33154)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_stream_get_meta_data'",
            $type,
            'Builtin\\Type must not always-register __compiler_stream_get_meta_data (#33154)'
        );
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        // Next leftover sentinel (pending_header_reset still Type always-on; #33245 assert_options / #33249 undef-key dropped).
        $this->assertStringContainsString("registerFunction('__phpc_pending_header_reset'", $type);
        $this->assertStringContainsString('StreamMeta::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresStreamGetMetaDataAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamMetaKernel.php');
        $this->assertStringContainsString('#33154', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('addFunction', $owner);
        $this->assertStringContainsString('__compiler_stream_get_meta_data', $owner);
        $this->assertStringContainsString('implementIfMissing', $owner);
        $orchestrator = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamMeta.php');
        $this->assertStringContainsString('#33154', $orchestrator);
        $this->assertStringContainsString('JitStreamMetaKernel', $orchestrator);
        $thin = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamMetaThinAot.php');
        $this->assertStringContainsString('#33154', $thin);
        $this->assertStringContainsString('__compiler_stream_get_meta_data', $thin);
        $this->assertFileExists(__DIR__.'/../../ext/standard/StreamMetaJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitStreamMetaKernel.php');
    }

    public function testTypeInitializeStillEnsureLinksStreamMeta(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StreamMeta::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForStreamGetMetaDataAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/stream_get_meta_data.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/stream_get_meta_data.c');
    }
}
