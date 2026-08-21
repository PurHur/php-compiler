<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on sscanf/vfscanf ABI shells from Builtin\Type (#32935).
 *
 * NestedJIT/AOT bridges stay Sscanf / StringSscanfByRef / StringSscanfArray /
 * StringVfscanf / SscanfJitHelper. Runtime owner declares module-locally
 * (getNamedFunction first) so leftover Type empty decls cannot mint sscanf.1 /
 * vfscanf.1 (#31894 / #32122).
 */
final class TypeDeadSscanfAbiRuntimeShrinkTest extends TestCase
{
    /** @return list<string> */
    private function droppedAbis(): array
    {
        return [
            '__compiler_sscanf',
            '__compiler_sscanf_array',
            '__compiler_sscanf_ex',
            '__compiler_vfscanf',
        ];
    }

    public function testTypeBuiltinDropsLeftoverAlwaysOnSscanfAbis(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#32935', $type);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertDoesNotMatchRegularExpression(
                '/addFunction\(\s*[\'"]'.preg_quote($sym, '/').'[\'"]/',
                $type,
                "Builtin\\Type must not always-declare {$sym} (#32935)"
            );
            $this->assertStringNotContainsString(
                "registerFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-register {$sym} (#32935)"
            );
        }
        $this->assertStringContainsString('LibcExtern::ensureExitAbort', $type);
        $this->assertStringContainsString('Sscanf::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresSscanfAbisModuleLocally(): void
    {
        $router = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Sscanf.php');
        $byRef = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringSscanfByRef.php');
        $array = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringSscanfArray.php');
        $vfscanf = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringVfscanf.php');
        $this->assertStringContainsString('#32935', $router);
        $this->assertStringContainsString('#32935', $byRef);
        $this->assertStringContainsString('#32935', $array);
        $this->assertStringContainsString('#32935', $vfscanf);
        $this->assertStringContainsString('getNamedFunction', $byRef);
        $this->assertStringContainsString('getNamedFunction', $array);
        $this->assertStringContainsString('getNamedFunction', $vfscanf);
        $this->assertStringContainsString('__compiler_sscanf', $byRef);
        $this->assertStringContainsString('__compiler_sscanf_ex', $byRef);
        $this->assertStringContainsString('__compiler_sscanf_array', $array);
        $this->assertStringContainsString('__compiler_vfscanf', $vfscanf);
        $this->assertFileExists(__DIR__.'/../../ext/standard/SscanfJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitSscanf.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitVfscanf.php');
    }

    public function testTypeInitializeStillEnsureLinksSscanfRuntime(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('Sscanf::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForSscanfAbis(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/sscanf.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/sscanf.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/vfscanf.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/vfscanf.c');
    }
}
