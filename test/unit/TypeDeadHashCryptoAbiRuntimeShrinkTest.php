<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on hash/hmac/pbkdf2/hkdf ABI shells from Builtin\Type (#32876).
 *
 * NestedJIT/AOT bridge stays StringHashCrypto → StringHashCryptoPhp.
 * Runtime owner declares module-locally (getNamedFunction first via ensureBridge) so
 * leftover Type empty decls cannot mint hash.1 (#31894 / #32122).
 *
 * hash_equals / hash_*_algos already dropped by #32875.
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
            $this->assertDoesNotMatchRegularExpression(
                '/registerFunction\(\s*[\'"]'.preg_quote($sym, '/').'[\'"]/',
                $type,
                "Builtin\\Type must not always-register {$sym} (#32876)"
            );
        }
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        $this->assertStringContainsString("registerFunction('__compiler_proc_open'", $type);
        $this->assertStringContainsString('StringHashCrypto::ensureLinked', $type);
        // Peer #32875 already dropped these always-on shells
        $this->assertStringContainsString('#32875', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_hash_equals[\'"]/',
            $type
        );
    }

    public function testRuntimeOwnerDeclaresHashCryptoAbisModuleLocally(): void
    {
        $svc = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringHashCryptoPhp.php');
        $this->assertStringContainsString('#32876', $svc);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $svc);
        $this->assertStringContainsString('getNamedFunction(', $svc);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertStringContainsString($sym, $svc);
        }
    }

    public function testTypeInitializeStillEnsureLinksStringHashCrypto(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StringHashCrypto::ensureLinked($this->context)', $type);
    }

    public function testPhpHelpersRemainForDroppedUserScriptBuiltins(): void
    {
        $this->assertFileExists(__DIR__.'/../../ext/standard/HashCryptoJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../lib/JIT/Builtin/StringHashCrypto.php');
        $this->assertFileExists(__DIR__.'/../../lib/JIT/Builtin/StringHashCryptoPhp.php');
        $this->assertFileDoesNotExist(
            dirname(__DIR__, 2).'/lib/AOT/runtime/hash_crypto.c'
        );
        $this->assertFileDoesNotExist(
            dirname(__DIR__, 2).'/lib/AOT/runtime/phpc_hash.c'
        );
    }
}
