<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on ini/silence ABI shells from Builtin\Type (#32779).
 *
 * User-script ini_get()/ini_set()/@ stay PHP helpers.
 * Runtime owners declare module-locally (getNamedFunction first) so leftover Type
 * empty decls cannot mint name.1 (#31894 / #32122).
 */
final class TypeDeadIniSilenceAbiRuntimeShrinkTest extends TestCase
{
    /** @return list<string> */
    private function droppedAbis(): array
    {
        return [
            '__compiler_ini_get',
            '__compiler_ini_cfg_get',
            '__compiler_ini_set',
            '__compiler_ini_restore',
            '__compiler_error_reporting',
            '__compiler_begin_silence',
            '__compiler_end_silence',
        ];
    }

    public function testTypeBuiltinDropsLeftoverAlwaysOnIniSilenceAbis(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#32779', $type);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertStringNotContainsString(
                "addFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-declare {$sym} (#32779)"
            );
            $this->assertStringNotContainsString(
                "registerFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-register {$sym} (#32779)"
            );
            $this->assertStringNotContainsString(
                "'{$sym}' =>",
                $type,
                "Builtin\\Type must not always-declare {$sym} in a table (#32779)"
            );
        }
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        $this->assertStringContainsString("registerFunction('__compiler_readfile'", $type);
        $this->assertStringContainsString('IniRuntime::ensureLinked', $type);
    }

    public function testRuntimeOwnersDeclareIniSilenceAbisModuleLocally(): void
    {
        $ini = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/IniRuntime.php');
        $this->assertStringContainsString('#32779', $ini);
        $this->assertStringContainsString("getNamedFunction('__compiler_ini_get')", $ini);
        $this->assertStringContainsString('SilenceRuntime::ensureLinked', $ini);
        foreach (['__compiler_ini_get', '__compiler_ini_cfg_get', '__compiler_ini_set', '__compiler_ini_restore'] as $sym) {
            $this->assertStringContainsString($sym, $ini);
        }

        $silence = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SilenceRuntime.php');
        $this->assertStringContainsString('#32779', $silence);
        $this->assertStringContainsString("getNamedFunction('__compiler_begin_silence')", $silence);
        foreach (['__compiler_begin_silence', '__compiler_end_silence', '__compiler_error_reporting'] as $sym) {
            $this->assertStringContainsString($sym, $silence);
        }
    }

    public function testTypeRegisterStillEnsureLinksIniRuntime(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('IniRuntime::ensureLinked($this->context)', $type);
    }

    public function testPhpHelpersRemainForDroppedUserScriptBuiltins(): void
    {
        $this->assertFileExists(__DIR__.'/../../ext/standard/IniJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/ErrorSilenceJitHelper.php');
        $this->assertStringContainsString(
            'IniJitHelper',
            (string) file_get_contents(__DIR__.'/../../ext/standard/IniJitHelper.php')
        );
        $this->assertStringContainsString(
            'ErrorSilenceJitHelper',
            (string) file_get_contents(__DIR__.'/../../ext/standard/ErrorSilenceJitHelper.php')
        );
    }
}
