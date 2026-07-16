<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** hash_hmac_algos JIT: PHP helper for embed; ext kernel for user-script AOT (#18908, #19355). */
final class StringHashHmacAlgosRuntimeShrinkTest extends TestCase
{
    public function testStringHashHmacAlgosUsesJitHelperAndExtKernel(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringHashHmacAlgos.php');
        $this->assertStringContainsString('HashAlgosJitHelper', $runtime);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $runtime);
        $this->assertStringContainsString('UserScriptAotDeferNestedJit::shouldDefer', $runtime);
        $this->assertStringContainsString('JitHashAlgosKernel', $runtime);
        $this->assertStringContainsString('hash_hmac_algos_kernel_entry', $runtime);
        $this->assertStringNotContainsString('implementInlineRegistry', $runtime);
        $this->assertStringNotContainsString('HashAlgosRegistry::HMAC_ALGOS', $runtime);
        $this->assertStringNotContainsString('__hashtable__setStringAt', $runtime);

        $helper = (string) file_get_contents(__DIR__.'/../../ext/hash/HashAlgosJitHelper.php');
        $this->assertStringContainsString('VmHash::hmacAlgos', $helper);
        $this->assertStringContainsString('hmacAlgosArgv', $helper);
    }

    public function testSpineBundleIncludesHashAlgosKernelForHmac(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitHashAlgosKernel.php', $spine);
        $this->assertStringContainsString('StringHashHmacAlgos.php', $spine);
    }
}
