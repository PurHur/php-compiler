<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on preg_match_all/ex ABI shells from Builtin\Type (#33188).
 *
 * Completes the preg_match family after #33187 dropped `__compiler_preg_match` alone.
 * NestedJIT/AOT bridge stays StringPregMatch / StringPregMatchJit / PregMatchRuntime.
 */
final class TypeDeadPregMatchAllAbiRuntimeShrinkTest extends TestCase
{
    /** @return list<string> */
    private function droppedAbis(): array
    {
        return [
            '__compiler_preg_match_all',
            '__compiler_preg_match_ex',
            '__compiler_preg_match_all_ex',
        ];
    }

    public function testTypeBuiltinDropsLeftoverAlwaysOnPregMatchFamilyAbis(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33188', $type);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertDoesNotMatchRegularExpression(
                '/addFunction\(\s*[\'"]'.preg_quote($sym, '/').'[\'"]/',
                $type,
                "Builtin\\Type must not always-declare {$sym} (#33188)"
            );
            $this->assertStringNotContainsString(
                "registerFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-register {$sym} (#33188)"
            );
        }
        // No further Type always-on leftover after #33267 exit/abort drop.
        $this->assertStringContainsString('#34357', $type);
        $this->assertStringNotContainsString('StringPregMatch::ensureLinked($this->context)', $type);
    }

    public function testRuntimeOwnerDeclaresPregMatchFamilyAbisModuleLocally(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PregMatchRuntime.php');
        $this->assertStringContainsString('#33188', $runtime);
        $this->assertStringContainsString('getNamedFunction', $runtime);
        $this->assertStringContainsString('implementI64PairBridge', $runtime);
        $this->assertStringContainsString('implementMatchExBridge', $runtime);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertStringContainsString($sym, $runtime);
        }
        $orchestrator = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringPregMatch.php');
        $this->assertStringContainsString('#33188', $orchestrator);
        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitPregMatchAll.php');
        $this->assertStringContainsString('#33188', $jit);
        $this->assertStringContainsString('StringPregMatch::ensureLinked', $jit);
    }

    public function testTypeInitializeDoesNotEagerlyEnsureLinkStringPregMatch(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringNotContainsString('StringPregMatch::ensureLinked($this->context)', $type);
        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitPregMatchAll.php');
        $this->assertStringContainsString('StringPregMatch::ensureLinked', $jit);
    }

    public function testNoNewRuntimeCForPregMatchFamilyAbis(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/preg_match_all.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/preg_match_all.c');
    }
}
