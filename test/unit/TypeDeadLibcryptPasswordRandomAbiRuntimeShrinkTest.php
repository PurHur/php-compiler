<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on libcrypt / password_random_bytes ABI shells from Builtin\Type (#32851).
 *
 * NestedJIT/AOT bridges stay LibcryptRuntime / PasswordRandomBytesRuntime.
 * Runtime owners declare module-locally (getNamedFunction first) so leftover Type
 * empty decls cannot mint libcrypt.1 / password_random_bytes.1 (#31894 / #32122).
 */
final class TypeDeadLibcryptPasswordRandomAbiRuntimeShrinkTest extends TestCase
{
    /** @return list<string> */
    private function droppedAbis(): array
    {
        return [
            '__compiler_libcrypt',
            '__compiler_password_random_bytes',
        ];
    }

    public function testTypeBuiltinDropsLeftoverAlwaysOnLibcryptPasswordRandomAbis(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#32851', $type);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertDoesNotMatchRegularExpression(
                '/addFunction\(\s*[\'"]'.preg_quote($sym, '/').'[\'"]/',
                $type,
                "Builtin\\Type must not always-declare {$sym} (#32851)"
            );
            $this->assertStringNotContainsString(
                "registerFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-register {$sym} (#32851)"
            );
        }
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        // Next leftover sentinel (strftime still Type always-on; #33213 unserialize / #33215 format_datetime dropped).
        $this->assertStringContainsString("registerFunction('__compiler_strftime'", $type);
        $this->assertStringContainsString('LibcryptRuntime::ensureLinked', $type);
        $this->assertStringContainsString('PasswordRandomBytesRuntime::ensureLinked', $type);
    }

    public function testRuntimeOwnersDeclareAbisModuleLocally(): void
    {
        $libcrypt = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/LibcryptRuntime.php');
        $this->assertStringContainsString('#32851', $libcrypt);
        $this->assertStringContainsString("getNamedFunction(self::ABI_NAME)", $libcrypt);
        $this->assertStringContainsString('__compiler_libcrypt', $libcrypt);
        $this->assertStringContainsString('module->addFunction(', $libcrypt);

        $pw = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PasswordRandomBytesRuntime.php');
        $this->assertStringContainsString('#32851', $pw);
        $this->assertStringContainsString('getNamedFunction(self::ABI)', $pw);
        $this->assertStringContainsString('__compiler_password_random_bytes', $pw);
        $this->assertStringContainsString('module->addFunction(', $pw);
    }

    public function testTypeInitializeStillEnsureLinksOwners(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('LibcryptRuntime::ensureLinked($this->context)', $type);
        $this->assertStringContainsString('PasswordRandomBytesRuntime::ensureLinked($this->context)', $type);
    }

    public function testPhpHelpersRemainForDroppedUserScriptBuiltins(): void
    {
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitLibcrypt.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitPasswordRandomBytes.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/LibcryptJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/RandomBytesJitHelper.php');
        $this->assertFileDoesNotExist(
            dirname(__DIR__, 2).'/lib/AOT/runtime/phpc_libcrypt.c'
        );
        $this->assertFileDoesNotExist(
            dirname(__DIR__, 2).'/lib/AOT/runtime/phpc_password_random_bytes.c'
        );
    }
}
