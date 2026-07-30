<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\GetenvLookupJitHelper;
use PHPUnit\Framework\TestCase;

/** getenv: always GetenvLookupJitHelper via JitVmHelperLink — no thin libc fork (#9092, #20156, #20644). */
final class GetenvJitRuntimeShrinkTest extends TestCase
{
    public function testStringGetenvAlwaysUsesHelperBridge(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringGetenv.php');
        $this->assertStringContainsString('GetenvLookupJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('getenv_bridge_entry', $source);
        $this->assertStringContainsString('phpc_getenv_kernel', $source);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringNotContainsString('implementKernelBody', $source);
        $this->assertStringNotContainsString('getenv_kernel_entry', $source);
        $this->assertStringNotContainsString('shouldDeferHeavyStreamIoEmitters', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('LibcExtern::register', $source);
        $this->assertStringNotContainsString("lookupFunction('getenv')", $source);
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitGetenvKernel.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/phpc_getenv_kernel.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/GetenvLookupJitHelper.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringGetenvLibcBridge.php');
    }

    public function testGetenvLookupJitHelperDelegatesToKernel(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/GetenvLookupJitHelper.php');
        $this->assertStringContainsString('phpc_getenv_kernel', $source);
        $this->assertStringContainsString('0 !== $localOnly', $source);
        if (!\function_exists('phpc_getenv_kernel')) {
            $this->markTestSkipped('phpc_getenv_kernel requires compiler runtime');
        }
        $path = \getenv('PATH');
        if (false === $path) {
            $this->markTestSkipped('PATH not set in process environ');
        }
        $this->assertSame($path, GetenvLookupJitHelper::fromEnviron('PATH', 0));
        $this->assertNull(GetenvLookupJitHelper::fromEnviron('PATH', 1));
        $this->assertNull(GetenvLookupJitHelper::fromEnviron('__PHPC_GETENV_MISSING_'.bin2hex(random_bytes(4)), 0));
    }

    public function testStringGetenvAllAlwaysUsesHelperBridge(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringGetenvAll.php');
        $this->assertStringContainsString('EnvironMirrorRuntime::ensureLinked', $source);
        $this->assertStringContainsString('EnvironMirrorRuntime::emitFillCall', $source);
        $this->assertStringContainsString('getenv_all_bridge_entry', $source);
        // putenv mirrors via setenv; NestedJIT overlay merge segfaults under thin AOT (#24855).
        $this->assertStringNotContainsString('mergeLocalOverlayIntoNative', $source);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringNotContainsString('JitNestedHelperCoerce::callHelper', $source);
        $this->assertStringNotContainsString('JitEnvironMirrorKernel', $source);
        $this->assertStringNotContainsString('mirrorIntoHashtablePtr', $source);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringNotContainsString('getenv_all_thin_stub', $source);
        $this->assertStringNotContainsString('implementThinStub', $source);
        $this->assertStringNotContainsString('THIN_STUB_ENTRY', $source);
        $this->assertStringNotContainsString('shouldDeferHeavyStreamIoEmitters', $source);
        $this->assertStringNotContainsString('getenv_all_inv_stub', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
    }

    public function testSpineBundleIncludesGetenvKernel(): void
    {
        $spine = (string) \file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('GetenvJitHelper.php', $spine);
        $this->assertStringContainsString('GetenvLookupJitHelper.php', $spine);
        $this->assertStringContainsString('JitGetenvKernel.php', $spine);
        $this->assertStringContainsString('phpc_getenv_kernel.php', $spine);
        $this->assertStringContainsString('StringGetenv.php', $spine);
        $this->assertStringContainsString('StringGetenvAll.php', $spine);
    }

    public function testNestedJitAllowlistsGetenvKernel(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('phpc_getenv_kernel', $source);
        $this->assertStringContainsString('isPreRegisterModuleNestedJitKernel', $source);
    }
}
