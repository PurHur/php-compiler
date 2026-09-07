<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * md5() thin AOT uses native phpc_md5_r1 (#36388); no NestedJIT __compiler_hash.
 */
final class Md5RuntimeShrinkTest extends TestCase
{
    public function testStringMd5UsesNativeR1NotNestedJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringMd5.php');
        $this->assertStringContainsString('Md5Runtime', $source);
        $this->assertStringContainsString('phpc_md5_r1', $source);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringNotContainsString('StringHashCrypto', $source);

        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Md5Runtime.php');
        $this->assertStringContainsString('phpc_md5_r1', $runtime);
        $this->assertStringContainsString('__phpc_hc_evp_hash', $runtime);
        $this->assertStringContainsString('HashVmRuntimeSupport', $runtime);
        $this->assertStringContainsString("constantStringFromString('md5')", $runtime);

        $jitMd5 = (string) file_get_contents(__DIR__.'/../../ext/standard/JitMd5.php');
        $this->assertStringContainsString('StringMd5::invoke', $jitMd5);
        $this->assertStringNotContainsString('__string__init', $jitMd5);
        $this->assertStringNotContainsString('StringHashCrypto', $jitMd5);

        $md5 = (string) file_get_contents(__DIR__.'/../../ext/standard/md5.php');
        $this->assertStringContainsString('JitMd5::digest', $md5);
        $this->assertStringContainsString('releaseEphemeralArgAfterCopy', $md5);
    }

    public function testSpineBundleIncludesMd5Runtime(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('Md5Runtime.php', $spine);
        $this->assertStringContainsString('StringMd5.php', $spine);
    }

    public function testOwningCallResultListsMd5(): void
    {
        $src = (string) file_get_contents(
            __DIR__.'/../../lib/JIT/Concern/CallResultOperandAssign.php'
        );
        $this->assertStringContainsString("'md5' => true", $src);
    }
}
