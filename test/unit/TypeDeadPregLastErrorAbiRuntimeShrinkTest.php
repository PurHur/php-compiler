<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_preg_last_error(+_msg) ABI shells from Builtin\Type (#33192).
 *
 * NestedJIT/AOT bridge stays StringPregMatch / StringPregMatchJit / PregMatchRuntime.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover Type
 * empty decls cannot mint preg_last_error.1 (#31894 / #32122).
 */
final class TypeDeadPregLastErrorAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnPregLastErrorAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33192', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_preg_last_error[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_preg_last_error (#33192)'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_preg_last_error_msg[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_preg_last_error_msg (#33192)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_preg_last_error'",
            $type,
            'Builtin\\Type must not always-register __compiler_preg_last_error (#33192)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_preg_last_error_msg'",
            $type,
            'Builtin\\Type must not always-register __compiler_preg_last_error_msg (#33192)'
        );
        // No further Type always-on leftover after #33267 exit/abort drop.
        $this->assertStringContainsString('#34357', $type);
        $this->assertStringNotContainsString('StringPregMatch::ensureLinked($this->context)', $type);
    }

    public function testRuntimeOwnerDeclaresPregLastErrorAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PregMatchRuntime.php');
        $this->assertStringContainsString('#33192', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('implementLastErrorBridge', $owner);
        $this->assertStringContainsString('implementLastErrorMsgBridge', $owner);
        $this->assertStringContainsString('__compiler_preg_last_error', $owner);
        $this->assertStringContainsString('__compiler_preg_last_error_msg', $owner);
        $orchestrator = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringPregMatch.php');
        $this->assertStringContainsString('#33192', $orchestrator);
        $jitDispatch = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringPregMatchJit.php');
        $this->assertStringContainsString('#33192', $jitDispatch);
        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitPregLastError.php');
        $this->assertStringContainsString('#33192', $jit);
        $this->assertStringContainsString('StringPregMatch::ensureLinked', $jit);
        $jitMsg = (string) file_get_contents(__DIR__.'/../../ext/standard/JitPregLastErrorMsg.php');
        $this->assertStringContainsString('#33192', $jitMsg);
        $this->assertStringContainsString('StringPregMatch::ensureLinked', $jitMsg);
    }

    public function testTypeInitializeDoesNotEagerlyEnsureLinkStringPregMatch(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringNotContainsString('StringPregMatch::ensureLinked($this->context)', $type);
        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitPregLastError.php');
        $this->assertStringContainsString('StringPregMatch::ensureLinked', $jit);
    }

    public function testNoNewRuntimeCForPregLastErrorAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/preg_last_error.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/preg_last_error.c');
    }
}
