<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * hash_hmac() thin AOT uses native phpc_hash_hmac_r1 (#36388); no NestedJIT __compiler_hash_hmac.
 */
final class HashHmacRuntimeShrinkTest extends TestCase
{
    public function testStringHashHmacUsesNativeR1NotNestedJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringHashHmac.php');
        $this->assertStringContainsString('HashHmacRuntime', $source);
        $this->assertStringContainsString('phpc_hash_hmac_r1', $source);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringNotContainsString('HashCryptoJitHelper::', $source);

        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/HashHmacRuntime.php');
        $this->assertStringContainsString('phpc_hash_hmac_r1', $runtime);
        $this->assertStringContainsString('__phpc_hc_evp_hmac', $runtime);
        $this->assertStringContainsString('HashVmRuntimeSupport', $runtime);

        $hmac = (string) file_get_contents(__DIR__.'/../../ext/standard/hash_hmac.php');
        $this->assertStringContainsString('StringHashHmac::invoke', $hmac);
        $this->assertStringContainsString('releaseEphemeralArgAfterCopy', $hmac);
        $this->assertStringContainsString('phpc_hash_hmac_r1', $hmac);
        $this->assertStringContainsString('rejectNullHashHmacDigest', $hmac);
        $this->assertStringNotContainsString('JitHash::hashHmac', $hmac);
    }

    public function testSpineBundleIncludesHashHmacRuntime(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('HashHmacRuntime.php', $spine);
        $this->assertStringContainsString('StringHashHmac.php', $spine);
    }

    public function testOwningCallResultListsHashHmac(): void
    {
        $src = (string) file_get_contents(
            __DIR__.'/../../lib/JIT/Concern/CallResultOperandAssign.php'
        );
        $this->assertStringContainsString("'hash_hmac' => true", $src);
    }
}
