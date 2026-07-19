<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** hash()/hash_hmac()/pbkdf2/hkdf JIT routes through HashCryptoJitHelper + EVP NestedJIT leaves (#9164, #21026). */
final class StringHashCryptoRuntimeShrinkTest extends TestCase
{
    public function testStringHashCryptoPhpUsesJitVmHelperLinkBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringHashCryptoPhp.php');
        $this->assertStringContainsString('HashCryptoJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringContainsString('ensureEvpLeaves', $source);
        $this->assertStringContainsString('hc_hash_bridge_entry', $source);
        $this->assertStringNotContainsString('isThinStandaloneAotMain()', $source);
        $this->assertLessThan(200, \substr_count($source, "\n") + 1);
    }

    public function testStringHashCryptoJitDropsThinFork(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringHashCryptoJit.php');
        $this->assertStringContainsString('StringHashCryptoPhp', $source);
        $this->assertStringNotContainsString('isThinStandaloneAotMain()', $source);
        $this->assertStringNotContainsString('implementThin', $source);
        $this->assertStringNotContainsString('JitHashCryptoKernel::implement', $source);
        $this->assertFileExists(__DIR__.'/../../ext/hash/JitHashCryptoKernel.php');
        $this->assertFileExists(__DIR__.'/../../ext/hash/phpc_hash_crypto_hash.php');
    }

    public function testHashCryptoJitHelperCallsNestedJitKernels(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/HashCryptoJitHelper.php');
        $this->assertStringContainsString('phpc_hash_crypto_hash', $source);
        $this->assertStringContainsString('phpc_hash_crypto_hmac', $source);
        $this->assertStringNotContainsString('VmHash::', $source);
        $this->assertStringNotContainsString('VmHashNative::', $source);
    }

    public function testSpineBundleIncludesHashCryptoKernels(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitHashCryptoKernel.php', $spine);
        $this->assertStringContainsString('phpc_hash_crypto_hash.php', $spine);
        $this->assertStringContainsString('HashCryptoKernelArgs.php', $spine);
    }

    public function testKernelEmitsEvpLeafAbisNotCompilerHash(): void
    {
        $kernel = (string) file_get_contents(__DIR__.'/../../ext/hash/JitHashCryptoKernel.php');
        $this->assertStringContainsString('__phpc_hc_evp_hash', $kernel);
        $this->assertStringContainsString('ensureEvpLeaves', $kernel);
        $this->assertStringNotContainsString(
            "implementIfMissing(\$context, '__compiler_hash'",
            $kernel
        );
    }
}
