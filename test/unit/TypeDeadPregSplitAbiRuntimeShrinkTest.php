<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_preg_split ABI shell from Builtin\Type (#33199).
 *
 * NestedJIT/AOT bridge stays StringPregMatch / StringPregMatchJit / PregMatchRuntime.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover Type
 * empty decls cannot mint preg_split.1 (#31894 / #32122).
 */
final class TypeDeadPregSplitAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnPregSplitAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33199', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_preg_split[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_preg_split (#33199)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_preg_split'",
            $type,
            'Builtin\\Type must not always-register __compiler_preg_split (#33199)'
        );
        // No further Type always-on leftover after #33267 exit/abort drop.
        $this->assertStringContainsString('StringPregMatch::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresPregSplitAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PregMatchRuntime.php');
        $this->assertStringContainsString('#33199', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('implementSplitBridge', $owner);
        $this->assertStringContainsString('__compiler_preg_split', $owner);
        $orchestrator = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringPregMatch.php');
        $this->assertStringContainsString('#33199', $orchestrator);
        $jitDispatch = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringPregMatchJit.php');
        $this->assertStringContainsString('#33199', $jitDispatch);
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitPregSplit.php');
        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitPregSplit.php');
        $this->assertStringContainsString('#33199', $jit);
        $this->assertStringContainsString('StringPregMatch::ensureLinked', $jit);
    }

    public function testTypeInitializeStillEnsureLinksStringPregMatch(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StringPregMatch::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForPregSplitAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/preg_split.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/preg_split.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/phpc_preg_split.c');
    }
}
