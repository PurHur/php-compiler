<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\standard\StrxfrmJitHelper;
use PHPUnit\Framework\TestCase;

/**
 * strxfrm() AOT via StrxfrmJitHelper PHP + NestedJIT libc leaf (#30420).
 */
final class StrxfrmRuntimeShrinkTest extends TestCase
{
    public function testBuiltinRoutesThroughStringStrxfrm(): void
    {
        if (!CompilerVersion::supportsStrxfrm()) {
            $this->markTestSkipped('strxfrm gated on this profile');
        }
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/strxfrm.php');
        $this->assertStringContainsString('StringStrxfrm::invoke', $source);
        $this->assertStringNotContainsString("lookupFunction('strxfrm')", $source);
    }

    public function testStringStrxfrmRoutesThroughJitVmHelperLink(): void
    {
        if (!CompilerVersion::supportsStrxfrm()) {
            $this->markTestSkipped('strxfrm gated on this profile');
        }
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStrxfrm.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringContainsString('StrxfrmJitHelper', $bridge);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $bridge);
        $this->assertStringContainsString('JitStrxfrm::invokeLibcLeaf', $bridge);
        $this->assertStringContainsString('__compiler_strxfrm', $bridge);
        $this->assertStringNotContainsString("lookupFunction('strxfrm')", $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
    }

    public function testNestedLeafUsesLibcStrxfrm(): void
    {
        if (!CompilerVersion::supportsStrxfrm()) {
            $this->markTestSkipped('strxfrm gated on this profile');
        }
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStrxfrm.php');
        $this->assertStringContainsString('invokeLibcLeaf', $source);
        $this->assertStringContainsString("lookupFunction('strxfrm')", $source);
        $this->assertStringContainsString('ensureLibcStrxfrm', $source);
    }

    public function testModuleDropsAlwaysOnStrxfrmDecl(): void
    {
        if (!CompilerVersion::supportsStrxfrm()) {
            $this->markTestSkipped('strxfrm gated on this profile');
        }
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/Module.php');
        $this->assertStringNotContainsString("lookupFunction('strxfrm')", $source);
        $this->assertStringContainsString('#30420', $source);
    }

    public function testStrxfrmJitHelperUsesHostBuiltin(): void
    {
        if (!\function_exists('strxfrm')) {
            $this->markTestSkipped('strxfrm unavailable on host');
        }
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/StrxfrmJitHelper.php');
        $this->assertStringContainsString('\\strxfrm(', $source);
        $this->assertStringNotContainsString('VmLocaleCollate::', $source);

        $got = StrxfrmJitHelper::strxfrmArgv('hello');
        $this->assertSame(\strxfrm('hello'), $got);
    }

    public function testNestedJitAllowlistsStrxfrmBuiltin(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString("'strxfrm'", $source);
        $this->assertStringContainsString('#30420', $source);
        $this->assertStringContainsString('isPreRegisterModuleNestedJitKernel', $source);
    }

    public function testSpineBundleIncludesStrxfrmArtifacts(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('StrxfrmJitHelper.php', $spine);
        $this->assertStringContainsString('StringStrxfrm.php', $spine);
        $this->assertStringContainsString('JitStrxfrm.php', $spine);
    }
}
