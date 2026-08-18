<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on empty FS-dir compiler ABI shells from Builtin\Type (#32438).
 *
 * User-script mkdir()/tempnam()/sys_get_temp_dir()/ftok() stay PHP helpers.
 * Runtime owners declare module-locally (getNamedFunction first) so leftover Type
 * addFunction cannot mint name.1 (#31894 / #32122).
 */
final class TypeDeadFsDirAbiRuntimeShrinkTest extends TestCase
{
    /** @return list<string> */
    private function droppedAbis(): array
    {
        return [
            '__compiler_mkdir',
            '__compiler_tempnam',
            '__compiler_sys_get_temp_dir',
            '__compiler_ftok',
        ];
    }

    public function testTypeBuiltinDropsLeftoverAlwaysOnFsDirAbis(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#32438', $type);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertStringNotContainsString(
                "addFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-declare {$sym} (#32438)"
            );
            $this->assertStringNotContainsString(
                "registerFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-register {$sym} (#32438)"
            );
        }
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        $this->assertStringContainsString("registerFunction('__compiler_touch'", $type);
        $this->assertStringContainsString('FtokRuntime::ensureLinked', $type);
    }

    public function testRuntimeOwnersDeclareFsDirAbisModuleLocally(): void
    {
        $fsDir = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/FsDirRuntime.php');
        $this->assertStringContainsString("getNamedFunction('__compiler_touch')", $fsDir);
        $this->assertStringContainsString("'__compiler_mkdir'", $fsDir);
        $this->assertStringContainsString("'__compiler_tempnam'", $fsDir);

        $sys = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SysGetTempDirRuntime.php');
        $this->assertStringContainsString("'__compiler_sys_get_temp_dir'", $sys);
        $this->assertStringContainsString('getNamedFunction(self::ABI)', $sys);

        $ftok = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/FtokRuntime.php');
        $this->assertStringContainsString("'__compiler_ftok'", $ftok);
        $this->assertStringContainsString('getNamedFunction(self::ABI)', $ftok);
    }

    public function testPhpHelpersRemainForDroppedUserScriptBuiltins(): void
    {
        $this->assertStringContainsString(
            'MkdirJitHelper',
            (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringMkdir.php')
        );
        $this->assertStringNotContainsString(
            '__compiler_mkdir',
            (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringMkdir.php')
        );
        $this->assertStringContainsString(
            'TempnamJitHelper',
            (string) file_get_contents(__DIR__.'/../../ext/standard/JitTempnam.php')
        );
        $this->assertStringContainsString(
            'SysGetTempDirRuntime::invoke',
            (string) file_get_contents(__DIR__.'/../../ext/standard/JitSysGetTempDir.php')
        );
        $this->assertStringNotContainsString(
            "lookupFunction('__compiler_sys_get_temp_dir')",
            (string) file_get_contents(__DIR__.'/../../ext/standard/JitSysGetTempDir.php')
        );
        $this->assertStringContainsString(
            'FtokRuntime::invoke',
            (string) file_get_contents(__DIR__.'/../../ext/standard/JitFtok.php')
        );
        $this->assertStringNotContainsString(
            "lookupFunction('__compiler_ftok')",
            (string) file_get_contents(__DIR__.'/../../ext/standard/JitFtok.php')
        );
    }
}
