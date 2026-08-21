<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_fgetcsv ABI shell from Builtin\Type (#33189).
 *
 * NestedJIT/AOT bridge stays StringStreamCsv / StringFgetcsvJit.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover Type
 * empty decls cannot mint fgetcsv.1 (#31894 / #32122).
 */
final class TypeDeadFgetcsvAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnFgetcsvAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33189', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_fgetcsv[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_fgetcsv (#33189)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_fgetcsv'",
            $type,
            'Builtin\\Type must not always-register __compiler_fgetcsv (#33189)'
        );
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        // Next leftover sentinel (phpc_deploy_path still Type always-on; #33207 serialize_* dropped).
        $this->assertStringContainsString("registerFunction('__compiler_phpc_deploy_path'", $type);
        $this->assertStringContainsString('StringStreamCsv::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresFgetcsvAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringFgetcsvJit.php');
        $this->assertStringContainsString('#33189', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('addFunction', $owner);
        $this->assertStringContainsString('implementFgetcsvBridge', $owner);
        $this->assertStringContainsString('__compiler_fgetcsv', $owner);
        $orchestrator = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStreamCsv.php');
        $this->assertStringContainsString('#33189', $orchestrator);
        $this->assertStringContainsString('StringFgetcsvJit::implement', $orchestrator);
        $this->assertFileExists(__DIR__.'/../../ext/standard/CsvStrGetcsvJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitFgetcsv.php');
        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitFgetcsv.php');
        $this->assertStringContainsString('#33189', $jit);
        $this->assertStringContainsString('StringStreamCsv::ensureLinked', $jit);
    }

    public function testTypeInitializeStillEnsureLinksStringStreamCsv(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StringStreamCsv::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForFgetcsvAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/fgetcsv.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/fgetcsv.c');
    }
}
