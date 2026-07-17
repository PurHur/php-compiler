<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** getenv: JitVmHelperLink embed + thin libc kernel (#9092, #20156, #20141 shape). */
final class GetenvJitRuntimeShrinkTest extends TestCase
{
    public function testStringGetenvUsesHelperAndThinKernelGate(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringGetenv.php');
        $this->assertStringContainsString('GetenvJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringContainsString('JitGetenvKernel', $source);
        $this->assertStringContainsString('getenv_kernel_entry', $source);
        $this->assertStringContainsString('getenv_bridge_entry', $source);
        $this->assertStringNotContainsString('shouldDeferHeavyStreamIoEmitters', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('LibcExtern::register', $source);
        $this->assertStringNotContainsString("lookupFunction('getenv')", $source);
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitGetenvKernel.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringGetenvLibcBridge.php');
    }

    public function testStringGetenvAllUsesThinStubGate(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringGetenvAll.php');
        $this->assertStringContainsString('GetenvJitHelper::fillAllEnvironmentHashtable', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringContainsString('getenv_all_thin_stub', $source);
        $this->assertStringNotContainsString('shouldDeferHeavyStreamIoEmitters', $source);
        $this->assertStringNotContainsString('getenv_all_inv_stub', $source);
    }

    public function testSpineBundleIncludesGetenvKernel(): void
    {
        $spine = (string) \file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('GetenvJitHelper.php', $spine);
        $this->assertStringContainsString('JitGetenvKernel.php', $spine);
        $this->assertStringContainsString('StringGetenv.php', $spine);
        $this->assertStringContainsString('StringGetenvAll.php', $spine);
    }
}
