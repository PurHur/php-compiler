<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\GetenvLookupJitHelper;
use PHPUnit\Framework\TestCase;

/** getenv: GetenvLookupJitHelper via JitVmHelperLink + NestedJIT @getenv leaf — no kernel (#9092, #20644, #29313). */
final class GetenvJitRuntimeShrinkTest extends TestCase
{
    public function testStringGetenvAlwaysUsesHelperBridge(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringGetenv.php');
        $this->assertStringContainsString('GetenvLookupJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('getenv_bridge_entry', $source);
        $this->assertStringContainsString('invokeNestedLeaf', $source);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $source);
        $this->assertStringNotContainsString('phpc_getenv_kernel', $source);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringNotContainsString('implementKernelBody', $source);
        $this->assertStringNotContainsString('getenv_kernel_entry', $source);
        $this->assertStringNotContainsString('shouldDeferHeavyStreamIoEmitters', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitGetenvKernel.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/phpc_getenv_kernel.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/GetenvLookupJitHelper.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringGetenvLibcBridge.php');
    }

    public function testGetenvLookupJitHelperUsesHostGetenvNotKernel(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/GetenvLookupJitHelper.php');
        $this->assertStringContainsString('@\\getenv', $source);
        $this->assertStringContainsString('0 !== $localOnly', $source);
        $this->assertStringNotContainsString('phpc_getenv_kernel', $source);
        $this->assertStringNotContainsString('JitGetenvKernel', $source);

        $path = \getenv('PATH');
        if (false === $path) {
            $this->markTestSkipped('PATH not set in process environ');
        }
        $this->assertSame($path, GetenvLookupJitHelper::fromEnviron('PATH', 0));
        $this->assertNull(GetenvLookupJitHelper::fromEnviron('PATH', 1));
        $this->assertNull(GetenvLookupJitHelper::fromEnviron('__PHPC_GETENV_MISSING_'.bin2hex(random_bytes(4)), 0));
    }

    public function testJitEnvUsesNestedLeafUnderNestedJit(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/JitEnv.php');
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $source);
        $this->assertStringContainsString('getenvNestedLeaf', $source);
        $this->assertStringContainsString('StringGetenv::invokeNestedLeaf', $source);
        $this->assertStringNotContainsString('phpc_getenv_kernel', $source);
        $this->assertStringNotContainsString('JitGetenvKernel', $source);
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

    public function testSpineBundleIncludesGetenvHelperNotKernel(): void
    {
        $spine = (string) \file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('GetenvJitHelper.php', $spine);
        $this->assertStringContainsString('GetenvLookupJitHelper.php', $spine);
        $this->assertStringContainsString('StringGetenv.php', $spine);
        $this->assertStringContainsString('StringGetenvAll.php', $spine);
        $this->assertStringNotContainsString('JitGetenvKernel.php', $spine);
        $this->assertStringNotContainsString('phpc_getenv_kernel.php', $spine);
    }

    public function testNestedJitAllowlistsGetenvBuiltinNotKernel(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString("'getenv'", $source);
        $this->assertStringContainsString('#29313', $source);
        $this->assertStringNotContainsString('phpc_getenv_kernel', $source);
        $this->assertStringContainsString('isPreRegisterModuleNestedJitKernel', $source);
    }
}
