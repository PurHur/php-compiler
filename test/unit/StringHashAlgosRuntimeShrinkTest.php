<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** hash_algos JIT: always HashAlgosJitHelper via JitVmHelperLink — no thin kernel fork (#14909, #20652). */
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

        $helper = (string) file_get_contents(__DIR__.'/../../ext/hash/HashAlgosJitHelper.php');
        $this->assertStringContainsString('phpc_hash_algos_kernel', $helper);
        $this->assertStringContainsString('algosArgv(): array', $helper);
        $this->assertFileExists(__DIR__.'/../../ext/hash/phpc_hash_algos_kernel.php');
        $this->assertFileExists(__DIR__.'/../../ext/hash/JitHashAlgosKernel.php');
    }

    public function testSpineBundleIncludesHashAlgosHelperAndKernel(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('HashAlgosJitHelper.php', $spine);
        $this->assertStringContainsString('StringHashAlgos.php', $spine);
        $this->assertStringContainsString('JitHashAlgosKernel.php', $spine);
        $this->assertStringContainsString('phpc_hash_algos_kernel.php', $spine);
    }
}
