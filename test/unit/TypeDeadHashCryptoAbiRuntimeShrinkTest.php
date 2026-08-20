<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on hash/hmac/pbkdf2/hkdf ABI shells from Builtin\Type (#32876).
 *
 * NestedJIT/AOT bridge stays StringHashCrypto → StringHashCryptoPhp.
 * Runtime owner declares module-locally via JitVmHelperLink::ensureBridge so leftover
 * Type empty decls cannot mint hash.1 (#31894 / #32122).
 */
final class TypeDeadHashCryptoAbiRuntimeShrinkTest extends TestCase
{
    /** @return list<string> */
    private function droppedAbis(): array
    {
        return [
            '__compiler_hash',
            '__compiler_hash_hmac',
            '__compiler_hash_pbkdf2',
            '__compiler_hash_hkdf',
        ];
    }

    public function testTypeBuiltinDropsLeftoverAlwaysOnHashCryptoAbis(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#32876', $type);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertDoesNotMatchRegularExpression(
                '/addFunction\(\s*[\'"]'.preg_quote($sym, '/').'[\'"]/',
                $type,
                "Builtin\\Type must not always-declare {$sym} (#32876)"
            );
            $this->assertStringNotContainsString(
                "registerFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-register {$sym} (#32876)"
            );
        }
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        $this->assertStringContainsString("registerFunction('__compiler_convert_uuencode'", $type);
        $this->assertStringContainsString("registerFunction('__compiler_random_bytes'", $type);
        $this->assertStringContainsString('StringHashCrypto::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresHashCryptoAbisModuleLocally(): void
    {
        $php = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringHashCryptoPhp.php');
        $this->assertStringContainsString('#32876', $php);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $php);
        $this->assertStringContainsString('getNamedFunction', $php);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertStringContainsString($sym, $php, "{$sym} must remain owned by StringHashCryptoPhp (#32876)");
        }
    }

    public function testTypeInitializeStillEnsureLinksHashCryptoRuntime(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StringHashCrypto::ensureLinked($this->context)', $type);
    }

    public function testPhpHelpersRemainForDroppedUserScriptBuiltins(): void
    {
        $this->assertFileExists(__DIR__.'/../../ext/standard/HashCryptoJitHelper.php');
        $this->assertFileDoesNotExist(
            dirname(__DIR__, 2).'/lib/AOT/runtime/hash.c'
        );
        $this->assertFileDoesNotExist(
            dirname(__DIR__, 2).'/runtime/hash.c'
        );
    }
}
