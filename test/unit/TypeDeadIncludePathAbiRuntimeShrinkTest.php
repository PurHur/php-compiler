<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on include_path ABI shells from Builtin\Type (#32793).
 *
 * User-script get_include_path()/set_include_path()/stream_resolve_include_path()
 * stay PHP helpers. Runtime owner declares module-locally (getNamedFunction first)
 * so leftover Type empty decls cannot mint name.1 (#31894 / #32122).
 */
final class TypeDeadIncludePathAbiRuntimeShrinkTest extends TestCase
{
    /** @return list<string> */
    private function droppedAbis(): array
    {
        return [
            '__compiler_include_path_init',
            '__compiler_get_include_path',
            '__compiler_set_include_path',
            '__compiler_restore_include_path',
            '__compiler_stream_resolve_include_path',
        ];
    }

    public function testTypeBuiltinDropsLeftoverAlwaysOnIncludePathAbis(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#32793', $type);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertDoesNotMatchRegularExpression(
                '/addFunction\(\s*[\'"]'.preg_quote($sym, '/').'[\'"]/',
                $type,
                "Builtin\\Type must not always-declare {$sym} (#32793)"
            );
            $this->assertStringNotContainsString(
                "registerFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-register {$sym} (#32793)"
            );
            $this->assertStringNotContainsString(
                "'{$sym}' =>",
                $type,
                "Builtin\\Type must not always-declare {$sym} in a table (#32793)"
            );
        }
        // No further Type always-on leftover after exit/abort drop (#33267).
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]exit[\'"]/',
            $type,
            'Builtin\\Type must not always-declare exit (#33267)'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]abort[\'"]/',
            $type,
            'Builtin\\Type must not always-declare abort (#33267)'
        );
        $this->assertStringContainsString("registerFunction('__compiler_proc_close'", $type);
        $this->assertStringContainsString('IncludePathRuntime::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresIncludePathAbisModuleLocally(): void
    {
        $orch = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/IncludePathRuntime.php');
        $this->assertStringContainsString('#32793', $orch);
        $this->assertStringContainsString("getNamedFunction('__compiler_get_include_path')", $orch);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertStringContainsString($sym, $orch);
        }
    }

    public function testTypeRegisterStillEnsureLinksIncludePathRuntime(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('IncludePathRuntime::ensureLinked($this->context)', $type);
    }

    public function testPhpHelpersRemainForDroppedUserScriptBuiltins(): void
    {
        $this->assertFileExists(__DIR__.'/../../ext/standard/IncludePathJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/IncludePathResolveJitHelper.php');
        $this->assertStringContainsString(
            'IncludePathJitHelper',
            (string) file_get_contents(__DIR__.'/../../ext/standard/IncludePathJitHelper.php')
        );
        $this->assertFileDoesNotExist(
            dirname(__DIR__, 2).'/lib/AOT/runtime/phpc_include_path.c'
        );
    }
}
