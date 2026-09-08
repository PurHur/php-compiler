<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * sha1() thin AOT uses native phpc_sha1_r1 (#36388); no NestedJIT __compiler_hash.
 */
final class Sha1RuntimeShrinkTest extends TestCase
{
    public function testStringSha1UsesNativeR1NotNestedJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringSha1.php');
        $this->assertStringContainsString('Sha1Runtime', $source);
        $this->assertStringContainsString('phpc_sha1_r1', $source);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringNotContainsString('StringHashCrypto', $source);

        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Sha1Runtime.php');
        $this->assertStringContainsString('phpc_sha1_r1', $runtime);
        $this->assertStringContainsString('__phpc_hc_evp_hash', $runtime);
        $this->assertStringContainsString('HashVmRuntimeSupport', $runtime);
        $this->assertStringContainsString("constantStringFromString('sha1')", $runtime);

        $jitSha1 = (string) file_get_contents(__DIR__.'/../../ext/standard/JitSha1.php');
        $this->assertStringContainsString('StringSha1::invoke', $jitSha1);
        $this->assertStringNotContainsString('__string__init', $jitSha1);
        $this->assertStringNotContainsString('StringHashCrypto', $jitSha1);

        $sha1 = (string) file_get_contents(__DIR__.'/../../ext/standard/sha1.php');
        $this->assertStringContainsString('JitSha1::digest', $sha1);
        $this->assertStringContainsString('releaseEphemeralArgAfterCopy', $sha1);
    }

    public function testSpineBundleIncludesSha1Runtime(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('Sha1Runtime.php', $spine);
        $this->assertStringContainsString('StringSha1.php', $spine);
    }

    public function testOwningCallResultListsSha1(): void
    {
        $src = (string) file_get_contents(
            __DIR__.'/../../lib/JIT/Concern/CallResultOperandAssign.php'
        );
        $this->assertStringContainsString("'sha1' => true", $src);
    }
}
