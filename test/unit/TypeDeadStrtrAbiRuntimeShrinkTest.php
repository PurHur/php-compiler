<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on strtr ABI shells from Builtin\Type (#32858).
 *
 * NestedJIT/AOT bridge stays StringStrtr.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover Type
 * empty decls cannot mint strtr.1 (#31894 / #32122).
 */
final class TypeDeadStrtrAbiRuntimeShrinkTest extends TestCase
{
    /** @return list<string> */
    private function droppedAbis(): array
    {
        return [
            '__compiler_strtr',
            '__compiler_strtr_array',
        ];
    }

    public function testTypeBuiltinDropsLeftoverAlwaysOnStrtrAbis(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#32858', $type);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertDoesNotMatchRegularExpression(
                '/addFunction\(\s*[\'"]'.preg_quote($sym, '/').'[\'"]/',
                $type,
                "Builtin\\Type must not always-declare {$sym} (#32858)"
            );
            $this->assertStringNotContainsString(
                "registerFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-register {$sym} (#32858)"
            );
        }
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        $this->assertStringContainsString("registerFunction('__compiler_readfile'", $type);
        $this->assertStringContainsString('StringStrtr::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresStrtrAbisModuleLocally(): void
    {
        $svc = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStrtr.php');
        $this->assertStringContainsString('#32858', $svc);
        $this->assertStringContainsString('getNamedFunction($abiName)', $svc);
        $this->assertStringContainsString('module->addFunction($abiName, $ft)', $svc);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertStringContainsString($sym, $svc);
        }
    }

    public function testTypeInitializeStillEnsureLinksStringStrtr(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StringStrtr::ensureLinked($this->context)', $type);
    }

    public function testPhpHelpersRemainForDroppedUserScriptBuiltins(): void
    {
        $this->assertFileExists(__DIR__.'/../../ext/standard/StrtrTwoStringJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/StrtrArrayJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/strtr.php');
        $this->assertFileDoesNotExist(
            dirname(__DIR__, 2).'/lib/AOT/runtime/phpc_strtr.c'
        );
        $this->assertFileDoesNotExist(
            dirname(__DIR__, 2).'/lib/AOT/runtime/phpc_strtr_array.c'
        );
    }
}
