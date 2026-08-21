<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_str_getcsv ABI shell from Builtin\Type (#33196).
 *
 * NestedJIT/AOT bridge stays StringStrGetcsv / StringStreamCsv / JitStrGetcsv.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover Type
 * empty decls cannot mint str_getcsv.1 (#31894 / #32122).
 */
final class TypeDeadStrGetcsvAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnStrGetcsvAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33196', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_str_getcsv[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_str_getcsv (#33196)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_str_getcsv'",
            $type,
            'Builtin\\Type must not always-register __compiler_str_getcsv (#33196)'
        );
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        // Next leftover sentinel (session_start_apply still Type always-on; #33258 stream_path dropped).
        $this->assertStringContainsString("registerFunction('__phpc_session_start_apply'", $type);
        $this->assertStringContainsString('StringStreamCsv::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresStrGetcsvAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStrGetcsv.php');
        $this->assertStringContainsString('#33196', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('implementStrGetcsvBridge', $owner);
        $this->assertStringContainsString('__compiler_str_getcsv', $owner);
        $orchestrator = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStreamCsv.php');
        $this->assertStringContainsString('#33196', $orchestrator);
        $this->assertStringContainsString('StringStrGetcsv::ensureLinked', $orchestrator);
        $this->assertFileExists(__DIR__.'/../../ext/standard/CsvStrGetcsvJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitStrGetcsv.php');
        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStrGetcsv.php');
        $this->assertStringContainsString('#33196', $jit);
        $this->assertStringContainsString('StringStrGetcsv::ensureLinked', $jit);
    }

    public function testTypeInitializeStillEnsureLinksStringStreamCsv(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StringStreamCsv::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForStrGetcsvAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/str_getcsv.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/str_getcsv.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/phpc_str_getcsv.c');
    }
}
