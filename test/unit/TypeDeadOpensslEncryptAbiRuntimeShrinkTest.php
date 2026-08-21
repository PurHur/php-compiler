<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on openssl_encrypt/decrypt ABI shells from Builtin\Type (#32859).
 *
 * NestedJIT/AOT bridge stays OpensslEncryptRuntime.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover Type
 * empty decls cannot mint openssl_encrypt.1 (#31894 / #32122).
 */
final class TypeDeadOpensslEncryptAbiRuntimeShrinkTest extends TestCase
{
    /** @return list<string> */
    private function droppedAbis(): array
    {
        return [
            '__compiler_openssl_encrypt',
            '__compiler_openssl_decrypt',
            '__compiler_openssl_encrypt_take_tag',
            '__compiler_openssl_encrypt_tag_is_null',
        ];
    }

    public function testTypeBuiltinDropsLeftoverAlwaysOnOpensslEncryptAbis(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#32859', $type);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertDoesNotMatchRegularExpression(
                '/addFunction\(\s*[\'"]'.preg_quote($sym, '/').'[\'"]/',
                $type,
                "Builtin\\Type must not always-declare {$sym} (#32859)"
            );
            $this->assertStringNotContainsString(
                "registerFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-register {$sym} (#32859)"
            );
        }
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        $this->assertStringContainsString("registerFunction('__compiler_proc_close'", $type);
        $this->assertStringContainsString('OpensslEncryptRuntime::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresOpensslEncryptAbisModuleLocally(): void
    {
        $svc = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/OpensslEncryptRuntime.php');
        $this->assertStringContainsString('#32859', $svc);
        $this->assertStringContainsString('declareAbi(', $svc);
        $this->assertStringContainsString('getNamedFunction($name)', $svc);
        $this->assertStringContainsString('module->addFunction(', $svc);
        $this->assertStringNotContainsString('lookupFunction($name)', $svc);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertStringContainsString($sym, $svc);
        }
    }

    public function testTypeInitializeStillEnsureLinksOpensslEncryptRuntime(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('OpensslEncryptRuntime::ensureLinked($this->context)', $type);
    }

    public function testPhpHelpersRemainForDroppedUserScriptBuiltins(): void
    {
        $this->assertFileExists(__DIR__.'/../../ext/openssl/OpensslEncryptJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/openssl/JitOpensslEncrypt.php');
        $this->assertFileExists(__DIR__.'/../../ext/openssl/openssl_encrypt.php');
        $this->assertFileExists(__DIR__.'/../../ext/openssl/openssl_decrypt.php');
        $this->assertFileDoesNotExist(
            dirname(__DIR__, 2).'/lib/AOT/runtime/openssl_cipher.c'
        );
        $this->assertFileDoesNotExist(
            dirname(__DIR__, 2).'/lib/AOT/runtime/phpc_openssl_encrypt.c'
        );
    }
}
