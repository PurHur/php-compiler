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
        // Type::initialize no longer eagerly StringStreamCsv::ensureLinked (#34445);
        // JitStrGetcsv links StringStrGetcsv before lookup.
        $this->assertStringContainsString('#34445', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/(?<![A-Za-z0-9_])StringStreamCsv::ensureLinked\(\$this->context\)/',
            $type,
            'Builtin\\Type::initialize must not eagerly StringStreamCsv::ensureLinked (#34445)'
        );
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

    public function testTypeInitializeDropsEagerStringStreamCsvEnsureLinked(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertDoesNotMatchRegularExpression(
            '/(?<![A-Za-z0-9_])StringStreamCsv::ensureLinked\(\$this->context\)/',
            $type,
            'Builtin\\Type::initialize must not eagerly StringStreamCsv::ensureLinked (#34445)'
        );
    }

    public function testNoNewRuntimeCForStrGetcsvAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/str_getcsv.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/str_getcsv.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/phpc_str_getcsv.c');
    }
}
