<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** hash_algos JIT: PHP helper for embed; thin kernel for standalone AOT (#14909, #19355, #20050). */
final class StringHashAlgosRuntimeShrinkTest extends TestCase
{
    public function testStringHashAlgosUsesJitHelperAndExtKernel(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringHashAlgos.php');
        $this->assertStringContainsString('HashAlgosJitHelper', $runtime);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $runtime);
        $this->assertStringContainsString('isThinStandaloneAotMain', $runtime);
        $this->assertStringContainsString('JitHashAlgosKernel', $runtime);
        $this->assertStringContainsString('hash_algos_kernel_entry', $runtime);
        $this->assertStringContainsString('implementThinKernel', $runtime);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $runtime);
        $this->assertStringNotContainsString('implementUserScriptKernel', $runtime);
        $this->assertStringNotContainsString('implementInlineRegistry', $runtime);
        $this->assertStringNotContainsString('HashAlgosRegistry::ALL_ALGOS', $runtime);
        $this->assertStringNotContainsString('__hashtable__setStringAt', $runtime);
        $this->assertFileExists(__DIR__.'/../../ext/hash/JitHashAlgosKernel.php');

        $helper = (string) file_get_contents(__DIR__.'/../../ext/hash/HashAlgosJitHelper.php');
        $this->assertStringContainsString('VmHash::algos', $helper);
    }

    public function testKernelEmitsRegistryNotBuiltin(): void
    {
        $kernel = (string) file_get_contents(__DIR__.'/../../ext/hash/JitHashAlgosKernel.php');
        $this->assertStringContainsString('HashAlgosRegistry::ALL_ALGOS', $kernel);
        $this->assertStringContainsString('HashAlgosRegistry::HMAC_ALGOS', $kernel);
        $this->assertStringContainsString('__hashtable__setStringAt', $kernel);
        $this->assertStringContainsString('emitAlgosBody', $kernel);
        $this->assertStringContainsString('emitHmacAlgosBody', $kernel);
    }

    public function testSpineBundleIncludesHashAlgosKernel(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitHashAlgosKernel.php', $spine);
        $this->assertStringContainsString('HashAlgosJitHelper.php', $spine);
        $this->assertStringContainsString('StringHashAlgos.php', $spine);
    }
}
