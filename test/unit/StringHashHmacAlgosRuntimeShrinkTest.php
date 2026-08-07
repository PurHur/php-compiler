<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\hash\HashAlgosJitHelper;
use PHPCompiler\ext\standard\HashAlgosRegistry;
use PHPUnit\Framework\TestCase;

/** hash_hmac_algos JIT: NestedJIT-safe HashAlgosRegistry list — no registry kernel (#18908, #20652, #28750). */
final class StringHashHmacAlgosRuntimeShrinkTest extends TestCase
{
    public function testStringHashHmacAlgosAlwaysUsesHelperBridge(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringHashHmacAlgos.php');
        $this->assertStringContainsString('HashAlgosJitHelper', $runtime);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $runtime);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $runtime);
        $this->assertStringNotContainsString('JitHashAlgosKernel', $runtime);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $runtime);
        $this->assertStringNotContainsString('phpc_hash_hmac_algos_kernel', $runtime);

        $helper = (string) file_get_contents(__DIR__.'/../../ext/hash/HashAlgosJitHelper.php');
        $this->assertStringContainsString('HashAlgosRegistry::HMAC_ALGOS', $helper);
        $this->assertStringContainsString('hmacAlgosArgv(): array', $helper);
        $this->assertStringNotContainsString('phpc_hash_hmac_algos_kernel', $helper);
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/hash/phpc_hash_hmac_algos_kernel.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/hash/JitHashAlgosKernel.php');

        $this->assertSame(HashAlgosRegistry::HMAC_ALGOS, HashAlgosJitHelper::hmacAlgosArgv());
    }

    public function testSpineBundleOmitsHashHmacAlgosKernel(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('HashAlgosJitHelper.php', $spine);
        $this->assertStringNotContainsString('phpc_hash_hmac_algos_kernel.php', $spine);
        $this->assertStringNotContainsString('JitHashAlgosKernel.php', $spine);
    }
}
