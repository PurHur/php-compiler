<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_preg_match ABI shell from Builtin\Type (#33187).
 *
 * NestedJIT/AOT bridge stays StringPregMatch / StringPregMatchJit / PregMatchRuntime.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover Type
 * empty decls cannot mint preg_match.1 (#31894 / #32122).
 */
final class TypeDeadPregMatchAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnPregMatchAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33187', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_preg_match[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_preg_match (#33187)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_preg_match'",
            $type,
            'Builtin\\Type must not always-register __compiler_preg_match (#33187)'
        );
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        // Next leftover sentinel (assert_fail_string still Type always-on; #33234 trigger_error / #33237 assert_fail dropped).
        $this->assertStringContainsString("registerFunction('__compiler_assert_fail_string'", $type);
        $this->assertStringContainsString('StringPregMatch::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresPregMatchAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PregMatchRuntime.php');
        $this->assertStringContainsString('#33187', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('addFunction', $owner);
        $this->assertStringContainsString('implementI64PairBridge', $owner);
        $this->assertStringContainsString('__compiler_preg_match', $owner);
        $orchestrator = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringPregMatch.php');
        $this->assertStringContainsString('#33187', $orchestrator);
        $jitDispatch = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringPregMatchJit.php');
        $this->assertStringContainsString('#33187', $jitDispatch);
        $this->assertFileExists(__DIR__.'/../../ext/standard/PregJitHelper.php');
        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitPregMatch.php');
        $this->assertStringContainsString('#33187', $jit);
        $this->assertStringContainsString('StringPregMatch::ensureLinked', $jit);
    }

    public function testTypeInitializeStillEnsureLinksStringPregMatch(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StringPregMatch::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForPregMatchAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/preg_match.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/preg_match.c');
    }
}
