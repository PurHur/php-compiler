<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringHashCrypto;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issues #7189 / #7437 / #9164: hash crypto LLVM helpers route through VmHash PHP not native LLVM.
 *
 * @group aot-lint
 */
final class StringHashCryptoRuntimeStandaloneTest extends TestCase
{
    public function testRuntimeShrinkRemovesHashCryptoC(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/hash_crypto.c');
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/JIT/Builtin/hash_crypto_jit_runtime.c');
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/JIT/Builtin/StringHashCryptoNativeJit.php');
        $jit = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringHashCryptoJit.php');
        $php = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringHashCryptoPhp.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/JIT/Builtin/StringHashCryptoLlvm.php');
        $llvm = (string) file_get_contents(__DIR__.'/../../../ext/hash/JitHashCryptoKernel.php');
        $this->assertStringContainsString('emitHkdf', $llvm);
        $this->assertStringNotContainsString('hc_llvm_hkdf_stub', $llvm);
        $this->assertStringContainsString('JitHashCryptoKernel', $llvm);
        $jitThin = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringHashCryptoJit.php');
        $this->assertStringContainsString('JitHashCryptoKernel', $jitThin);
        $this->assertStringContainsString('isThinStandaloneAotMain', $jitThin);
        $this->assertStringContainsString('implementThin', $jitThin);
        $this->assertStringNotContainsString('StringHashCryptoLlvm', $jitThin);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $jitThin);
        $this->assertStringNotContainsString('implementDeferred', $jitThin);
        $this->assertStringNotContainsString('ensureDeferredEqualsStub', $jitThin);
        $this->assertStringNotContainsString('hash_equals_deferred_stub', $jitThin);
        $this->assertStringContainsString('__compiler_hash', $jit);
        $this->assertStringContainsString('StringHashEquals', $jit);
        $this->assertStringContainsString('StringHashHmacAlgos', $jit);
        $this->assertStringContainsString('StringHashAlgos', $jit);
        $this->assertStringContainsString('StringHashCryptoPhp', $jit);
        $this->assertStringNotContainsString('StringHashCryptoNativeJit', $jit);
        $this->assertStringNotContainsString('ensureBitcode', $jit);
        $this->assertStringNotContainsString('hash_crypto_jit_runtime.c', $jit);
        $this->assertStringContainsString('HashCryptoJitHelper', $php);
        $this->assertStringContainsString('__compiler_hash', $php);
        $this->assertStringNotContainsString('__phpc_hc_sha256_transform', $php);
        $linker = (string) file_get_contents(__DIR__.'/../../../lib/AOT/Linker.php');
        $this->assertStringNotContainsString('runtime/hash_crypto.c', $linker);
        $this->assertStringNotContainsString('hash_crypto_jit_runtime.c', $linker);
        $equalsJit = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringHashEquals.php');
        $this->assertStringContainsString('__compiler_hash_equals', $equalsJit);
        $runtime = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringHashCrypto.php');
        $this->assertStringContainsString('StringHashCryptoJit', $runtime);
        $this->assertStringNotContainsString('hash_crypto.c', $runtime);
    }

    public function testEnsureLinkedDefinesHashCryptoForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringHashCrypto::ensureLinked($ctx);

        foreach ([
            '__compiler_hash',
            '__compiler_hash_hmac',
            '__compiler_hash_pbkdf2',
            '__compiler_hash_hkdf',
            '__compiler_hash_equals',
            '__compiler_hash_hmac_algos',
            '__compiler_hash_algos',
        ] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn, $name);
            $this->assertGreaterThan(0, $fn->countBasicBlocks(), $name);
        }
    }

    public function testThinUserScriptLinksHashEqualsHelperBridge(): void
    {
        $prev = getenv('PHP_COMPILER_AOT_USER_SCRIPT');
        putenv('PHP_COMPILER_AOT_USER_SCRIPT=1');
        $_ENV['PHP_COMPILER_AOT_USER_SCRIPT'] = '1';
        try {
            $runtime = new Runtime(Runtime::MODE_AOT);
            $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
            StringHashCrypto::ensureLinked($ctx);
            $fn = $ctx->lookupFunction('__compiler_hash_equals');
            $this->assertNotNull($fn);
            $this->assertGreaterThan(0, $fn->countBasicBlocks());
            $this->assertTrue(
                \PHPCompiler\JIT\JitVmHelperLink::hasNamedBridgeEntry($fn, 'hash_equals_bridge_entry'),
                'thin user-script AOT must emit HashEqualsJitHelper bridge, not deferred stub'
            );
            $this->assertFalse(
                \PHPCompiler\JIT\JitVmHelperLink::hasNamedBridgeEntry($fn, 'hash_equals_kernel_entry')
            );
            $this->assertFalse(
                \PHPCompiler\JIT\JitVmHelperLink::hasNamedBridgeEntry($fn, 'hash_equals_deferred_stub')
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_AOT_USER_SCRIPT');
                unset($_ENV['PHP_COMPILER_AOT_USER_SCRIPT']);
            } else {
                putenv('PHP_COMPILER_AOT_USER_SCRIPT='.$prev);
                $_ENV['PHP_COMPILER_AOT_USER_SCRIPT'] = $prev;
            }
        }
    }
}
