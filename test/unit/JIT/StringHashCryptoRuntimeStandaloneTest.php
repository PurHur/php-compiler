<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringHashCrypto;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issues #7189 / #9164 / #21026: hash crypto always-helper via JitVmHelperLink + EVP NestedJIT leaves.
 *
 * @group aot-lint
 */
final class StringHashCryptoRuntimeStandaloneTest extends TestCase
{
    public function testRuntimeShrinkDropsThinStandaloneFork(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/hash_crypto.c');
        $this->assertFileExists(__DIR__.'/../../../ext/hash/JitHashCryptoKernel.php');
        $jit = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringHashCryptoJit.php');
        $php = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringHashCryptoPhp.php');
        $this->assertStringNotContainsString('isThinStandaloneAotMain()', $jit);
        $this->assertStringNotContainsString('implementThin', $jit);
        $this->assertStringContainsString('StringHashCryptoPhp', $jit);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $php);
        $this->assertStringContainsString('ensureEvpLeaves', $php);
        $kernel = (string) file_get_contents(__DIR__.'/../../../ext/hash/JitHashCryptoKernel.php');
        $this->assertStringContainsString('__phpc_hc_evp_hash', $kernel);
    }

    public function testEnsureLinkedDefinesHashCryptoForStandalone(): void
    {
        $prev = getenv('PHP_COMPILER_AOT_USER_SCRIPT');
        putenv('PHP_COMPILER_AOT_USER_SCRIPT=1');
        $_ENV['PHP_COMPILER_AOT_USER_SCRIPT'] = '1';
        try {
            $runtime = new Runtime(Runtime::MODE_AOT);
            $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
            StringHashCrypto::ensureLinked($ctx);

            foreach ([
                '__compiler_hash',
                '__compiler_hash_hmac',
                '__compiler_hash_pbkdf2',
                '__compiler_hash_hkdf',
                '__compiler_hash_equals',
            ] as $name) {
                $fn = $ctx->lookupFunction($name);
                $this->assertNotNull($fn, $name);
                $this->assertGreaterThan(0, $fn->countBasicBlocks(), $name);
            }
            $this->assertTrue(
                JitVmHelperLink::hasNamedBridgeEntry(
                    $ctx->lookupFunction('__compiler_hash'),
                    'hc_hash_bridge_entry'
                )
            );
            $evp = $ctx->module->getNamedFunction('__phpc_hc_evp_hash');
            $this->assertNotNull($evp);
            $this->assertTrue(
                JitVmHelperLink::hasNamedBridgeEntry($evp, 'hc_llvm_hash_entry')
                || $evp->countBasicBlocks() > 0
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

    public function testThinUserScriptLinksHashCryptoHelperBridge(): void
    {
        $prev = getenv('PHP_COMPILER_AOT_USER_SCRIPT');
        putenv('PHP_COMPILER_AOT_USER_SCRIPT=1');
        $_ENV['PHP_COMPILER_AOT_USER_SCRIPT'] = '1';
        try {
            $runtime = new Runtime(Runtime::MODE_AOT);
            $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
            StringHashCrypto::ensureLinked($ctx);
            $fn = $ctx->lookupFunction('__compiler_hash');
            $this->assertNotNull($fn);
            $this->assertTrue(
                JitVmHelperLink::hasNamedBridgeEntry($fn, 'hc_hash_bridge_entry')
            );
            $this->assertFalse(
                JitVmHelperLink::hasNamedBridgeEntry($fn, 'hc_llvm_hash_entry')
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
