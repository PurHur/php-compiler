<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\hash\HashAlgosJitHelper;
use PHPCompiler\ext\standard\HashAlgosRegistry;
use PHPUnit\Framework\TestCase;

/** hash_algos JIT: NestedJIT-safe inline list — no registry kernel (#14909, #20652, #28750, #30794). */
final class StringHashAlgosRuntimeShrinkTest extends TestCase
{
    public function testStringHashAlgosAlwaysUsesHelperBridge(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringHashAlgos.php');
        $this->assertStringContainsString('HashAlgosJitHelper', $runtime);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $runtime);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $runtime);
        $this->assertStringNotContainsString('JitHashAlgosKernel', $runtime);
        $this->assertStringNotContainsString('hash_algos_kernel_entry', $runtime);
        $this->assertStringNotContainsString('implementThinKernel', $runtime);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $runtime);
        $this->assertStringNotContainsString('implementUserScriptKernel', $runtime);
        $this->assertStringNotContainsString('implementInlineRegistry', $runtime);
        $this->assertStringNotContainsString('HashAlgosRegistry::ALL_ALGOS', $runtime);
        $this->assertStringNotContainsString('__hashtable__setStringAt', $runtime);
        $this->assertStringNotContainsString('phpc_hash_algos_kernel', $runtime);

        $helper = (string) file_get_contents(__DIR__.'/../../ext/hash/HashAlgosJitHelper.php');
        // Cross-dir class const is NestedJIT-unsafe under thin AOT (#30794).
        $this->assertStringNotContainsString('HashAlgosRegistry::ALL_ALGOS', $helper);
        $this->assertStringNotContainsString('use PHPCompiler\\ext\\standard\\HashAlgosRegistry', $helper);
        $this->assertStringContainsString("'md2'", $helper);
        $this->assertStringContainsString("'haval256,5'", $helper);
        $this->assertStringContainsString('algosArgv(): array', $helper);
        $this->assertStringNotContainsString('phpc_hash_algos_kernel', $helper);
        $this->assertStringNotContainsString('JitHashAlgosKernel', $helper);
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/hash/phpc_hash_algos_kernel.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/hash/JitHashAlgosKernel.php');

        $this->assertSame(HashAlgosRegistry::ALL_ALGOS, HashAlgosJitHelper::algosArgv());
    }

    public function testContextNoLongerAllowlistsHashAlgosKernels(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringNotContainsString('phpc_hash_algos_kernel', $source);
        $this->assertStringNotContainsString('phpc_hash_hmac_algos_kernel', $source);
    }

    public function testSpineBundleIncludesHashAlgosHelperOmitsKernel(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('HashAlgosJitHelper.php', $spine);
        $this->assertStringContainsString('StringHashAlgos.php', $spine);
        $this->assertStringNotContainsString('JitHashAlgosKernel.php', $spine);
        $this->assertStringNotContainsString('phpc_hash_algos_kernel.php', $spine);
        $this->assertStringNotContainsString('phpc_hash_hmac_algos_kernel.php', $spine);
    }
}
