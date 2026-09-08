<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * hash() thin AOT uses native phpc_hash_r1 (#36388); no NestedJIT __compiler_hash.
 */
final class HashRuntimeShrinkTest extends TestCase
{
    public function testStringHashUsesNativeR1NotNestedJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringHash.php');
        $this->assertStringContainsString('HashRuntime', $source);
        $this->assertStringContainsString('phpc_hash_r1', $source);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringNotContainsString('HashCryptoJitHelper::', $source);

        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/HashRuntime.php');
        $this->assertStringContainsString('phpc_hash_r1', $runtime);
        $this->assertStringContainsString('__phpc_hc_evp_hash', $runtime);
        $this->assertStringContainsString('HashVmRuntimeSupport', $runtime);

        $hash = (string) file_get_contents(__DIR__.'/../../ext/standard/hash_.php');
        $this->assertStringContainsString('StringHash::invoke', $hash);
        $this->assertStringContainsString('releaseEphemeralArgAfterCopy', $hash);
        $this->assertStringContainsString('phpc_hash_r1', $hash);
        $this->assertStringContainsString('rejectNullHashDigest', $hash);
    }

    public function testSpineBundleIncludesHashRuntime(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('HashRuntime.php', $spine);
        $this->assertStringContainsString('StringHash.php', $spine);
    }

    public function testOwningCallResultListsHash(): void
    {
        $src = (string) file_get_contents(
            __DIR__.'/../../lib/JIT/Concern/CallResultOperandAssign.php'
        );
        $this->assertStringContainsString("'hash' => true", $src);
    }
}
