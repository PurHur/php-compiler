<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on password crypto ABI shells from Builtin\Type (#32855).
 *
 * NestedJIT/AOT bridge stays PasswordCryptoRuntime.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover Type
 * empty decls cannot mint password_hash.1 (#31894 / #32122).
 */
final class TypeDeadPasswordCryptoAbiRuntimeShrinkTest extends TestCase
{
    /** @return list<string> */
    private function droppedAbis(): array
    {
        return [
            '__compiler_password_hash',
            '__compiler_password_verify',
            '__compiler_crypt',
            '__compiler_password_get_info',
            '__compiler_password_needs_rehash',
            '__compiler_password_algos',
        ];
    }

    public function testTypeBuiltinDropsLeftoverAlwaysOnPasswordCryptoAbis(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#32855', $type);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertDoesNotMatchRegularExpression(
                '/addFunction\(\s*[\'"]'.preg_quote($sym, '/').'[\'"]/',
                $type,
                "Builtin\\Type must not always-declare {$sym} (#32855)"
            );
            $this->assertStringNotContainsString(
                "registerFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-register {$sym} (#32855)"
            );
        }
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        $this->assertStringContainsString("registerFunction('__compiler_popen'", $type);
        $this->assertStringContainsString('PasswordCryptoRuntime::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresPasswordCryptoAbisModuleLocally(): void
    {
        $svc = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PasswordCryptoRuntime.php');
        $this->assertStringContainsString('#32855', $svc);
        $this->assertStringContainsString('declareAbi(', $svc);
        $this->assertStringContainsString('getNamedFunction($name)', $svc);
        $this->assertStringContainsString('module->addFunction(', $svc);
        $this->assertStringNotContainsString('lookupFunction($name)', $svc);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertStringContainsString($sym, $svc);
        }
    }

    public function testTypeInitializeStillEnsureLinksPasswordCryptoRuntime(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('PasswordCryptoRuntime::ensureLinked($this->context)', $type);
    }

    public function testPhpHelpersRemainForDroppedUserScriptBuiltins(): void
    {
        $this->assertFileExists(__DIR__.'/../../ext/standard/PasswordJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitPassword.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/password_hash.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/crypt.php');
        $this->assertFileDoesNotExist(
            dirname(__DIR__, 2).'/lib/AOT/runtime/phpc_password_hash.c'
        );
        $this->assertFileDoesNotExist(
            dirname(__DIR__, 2).'/lib/AOT/runtime/phpc_crypt.c'
        );
    }
}
