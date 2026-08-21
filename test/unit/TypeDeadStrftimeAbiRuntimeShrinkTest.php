<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_strftime ABI shell from Builtin\Type (#33222).
 *
 * NestedJIT/AOT bridge stays StringStrftime / StrftimeJitHelper / JitDate::formatStrftime.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover Type
 * empty decls cannot mint strftime.1 (#31894 / #32122).
 */
final class TypeDeadStrftimeAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnStrftimeAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33222', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_strftime[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_strftime (#33222)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_strftime'",
            $type,
            'Builtin\\Type must not always-register __compiler_strftime (#33222)'
        );
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        // Next leftover sentinel (session_start_apply still Type always-on; #33258 stream_path dropped).
        $this->assertStringContainsString("registerFunction('__phpc_session_start_apply'", $type);
        $this->assertStringContainsString('StringStrftime::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresStrftimeAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStrftime.php');
        $this->assertStringContainsString('#33222', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('implementStrftimeBridge', $owner);
        $this->assertStringContainsString('__compiler_strftime', $owner);
        $this->assertFileExists(__DIR__.'/../../ext/standard/StrftimeJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitDate.php');
        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitDate.php');
        $this->assertStringContainsString('#33222', $jit);
        $this->assertStringContainsString('StringStrftime::ensureLinked', $jit);
    }

    public function testTypeInitializeStillEnsureLinksStringStrftime(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StringStrftime::ensureLinked($this->context)', $type);
    }

    public function testStringBuiltinStillImplementsStrftimeOnFullLoad(): void
    {
        $string = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/String_.php');
        $this->assertStringContainsString('StringStrftime::implement($this->context)', $string);
    }

    public function testNoNewRuntimeCForStrftimeAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/strftime.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/strftime.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/phpc_strftime.c');
    }
}
