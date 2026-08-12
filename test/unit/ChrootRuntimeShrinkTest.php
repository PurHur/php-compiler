<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ChrootJitHelper;
use PHPUnit\Framework\TestCase;

/**
 * chroot() JIT: ChrootJitHelper + StringChroot NestedJIT leaf (#30558, #3500).
 */
final class ChrootRuntimeShrinkTest extends TestCase
{
    public function testJitChrootUsesPhpBridgeNotLibc(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitChroot.php');
        $this->assertStringContainsString('StringChroot::invoke', $source);
        $this->assertStringNotContainsString("lookupFunction('chroot')", $source);
        $this->assertStringNotContainsString('LibcExtern', $source);
        $this->assertStringNotContainsString('StringTriggerErrorJit', $source);
        $this->assertStringNotContainsString('__compiler_trigger_error', $source);
    }

    public function testStringChrootUsesHelperBridgeAndNestedLeaf(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringChroot.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringContainsString('invokeNestedLeaf', $source);
        $this->assertStringContainsString('ChrootJitHelper', $source);
        $this->assertStringContainsString('__compiler_chroot', $source);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('LibcExtern::', $source);
    }

    public function testChrootJitHelperUsesHostChrootNotKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/ChrootJitHelper.php');
        $this->assertStringContainsString('public static function invokeArgv(string $path): int', $source);
        $this->assertStringContainsString('@\\chroot', $source);
        $this->assertStringNotContainsString('TriggerErrorJitHelper', $source);
        $this->assertStringNotContainsString('VmChrootPure::', $source);
        $this->assertStringNotContainsString('VmChrootNative::', $source);
        $this->assertStringNotContainsString('VmFs::', $source);

        if (!\function_exists('chroot')) {
            $this->markTestSkipped('host chroot unavailable');
        }
        // Typical unprivileged process cannot chroot; helper must return 0, not throw.
        $this->assertSame(0, ChrootJitHelper::invokeArgv('/no/such/phpc-chroot-helper-30558'));
    }

    public function testModuleDropsAlwaysOnChrootDecl(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/Module.php');
        $this->assertStringNotContainsString("lookupFunction('chroot')", $source);
        $this->assertStringNotContainsString("addFunction('chroot'", $source);
        $this->assertStringContainsString('#30558', $source);
    }

    public function testSpineBundleIncludesChrootHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('ChrootJitHelper.php', $spine);
        $this->assertStringContainsString('StringChroot.php', $spine);
        $this->assertStringContainsString('JitChroot.php', $spine);
    }

    public function testContextWhitelistsChroot(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString("'chroot'", $source);
        $this->assertStringContainsString('#30558', $source);
    }
}
