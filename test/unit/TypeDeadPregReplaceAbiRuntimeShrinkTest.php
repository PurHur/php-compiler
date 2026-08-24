<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_preg_replace ABI shell from Builtin\Type (#33191).
 *
 * NestedJIT/AOT bridge stays StringPregMatch / PregMatchRuntime / JitPregReplace.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover Type
 * empty decls cannot mint preg_replace.1 (#31894 / #32122).
 */
final class TypeDeadPregReplaceAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnPregReplaceAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33191', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_preg_replace[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_preg_replace (#33191)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_preg_replace'",
            $type,
            'Builtin\\Type must not always-register __compiler_preg_replace (#33191)'
        );
        // No further Type always-on leftover after #33267 exit/abort drop.
        $this->assertStringContainsString('#34357', $type);
        $this->assertStringNotContainsString('StringPregMatch::ensureLinked($this->context)', $type);
    }

    public function testRuntimeOwnerDeclaresPregReplaceAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PregMatchRuntime.php');
        $this->assertStringContainsString('#33191', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('implementReplaceBridge', $owner);
        $this->assertStringContainsString('__compiler_preg_replace', $owner);
        $orchestrator = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringPregMatch.php');
        $this->assertStringContainsString('#33191', $orchestrator);
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitPregReplace.php');
        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitPregReplace.php');
        $this->assertStringContainsString('#33191', $jit);
        $this->assertStringContainsString('StringPregMatch::ensureLinked', $jit);
    }

    public function testTypeInitializeDoesNotEagerlyEnsureLinkStringPregMatch(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringNotContainsString('StringPregMatch::ensureLinked($this->context)', $type);
        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitPregReplace.php');
        $this->assertStringContainsString('StringPregMatch::ensureLinked', $jit);
    }

    public function testNoNewRuntimeCForPregReplaceAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/preg_replace.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/preg_replace.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/phpc_preg_replace.c');
    }
}
