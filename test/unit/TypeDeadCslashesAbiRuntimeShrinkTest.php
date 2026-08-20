<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on addcslashes/stripcslashes ABI shells from Builtin\Type (#32893).
 *
 * NestedJIT/AOT bridge stays StringCslashes.
 * Runtime owner declares module-locally via JitVmHelperLink::ensureBridge so leftover
 * Type empty decls cannot mint addcslashes.1 (#31894 / #32122).
 */
final class TypeDeadCslashesAbiRuntimeShrinkTest extends TestCase
{
    /** @return list<string> */
    private function droppedAbis(): array
    {
        return [
            '__compiler_addcslashes',
            '__compiler_stripcslashes',
        ];
    }

    public function testTypeBuiltinDropsLeftoverAlwaysOnCslashesAbis(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#32893', $type);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertDoesNotMatchRegularExpression(
                '/addFunction\(\s*[\'"]'.preg_quote($sym, '/').'[\'"]/',
                $type,
                "Builtin\\Type must not always-declare {$sym} (#32893)"
            );
            $this->assertStringNotContainsString(
                "registerFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-register {$sym} (#32893)"
            );
        }
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        $this->assertStringContainsString("registerFunction('__compiler_convert_uuencode'", $type);
        $this->assertStringContainsString('StringCslashes::ensureStandaloneBodies', $type);
    }

    public function testRuntimeOwnerDeclaresCslashesAbisModuleLocally(): void
    {
        $svc = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringCslashes.php');
        $this->assertStringContainsString('#32893', $svc);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $svc);
        $this->assertStringContainsString('getNamedFunction', $svc);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertStringContainsString($sym, $svc, "{$sym} must remain owned by StringCslashes (#32893)");
        }
    }

    public function testTypeInitializeStillEnsureLinksCslashesRuntime(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StringCslashes::ensureStandaloneBodies($this->context)', $type);
    }

    public function testPhpHelpersRemainForDroppedUserScriptBuiltins(): void
    {
        $this->assertFileExists(__DIR__.'/../../ext/standard/CslashesJitHelper.php');
        $this->assertFileDoesNotExist(
            dirname(__DIR__, 2).'/lib/AOT/runtime/cslashes.c'
        );
        $this->assertFileDoesNotExist(
            dirname(__DIR__, 2).'/runtime/cslashes.c'
        );
    }
}
