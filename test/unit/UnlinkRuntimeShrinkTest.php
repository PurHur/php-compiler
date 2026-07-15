<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\UnlinkJitHelper;
use PHPCompiler\ext\standard\VmFs;
use PHPUnit\Framework\TestCase;

/** unlink() JIT routes through UnlinkJitHelper PHP not libc unlink LLVM (#15471). */
final class UnlinkRuntimeShrinkTest extends TestCase
{
    public function testJitUnlinkUsesPhpBridgeNotLibc(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitUnlink.php');
        $this->assertStringContainsString('StringUnlink::invoke', $source);
        $this->assertStringNotContainsString("lookupFunction('unlink')", $source);
    }

    public function testStringUnlinkBridgeUsesUnlinkJitHelper(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringUnlink.php');
        $this->assertStringContainsString('UnlinkJitHelper', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringNotContainsString("lookupFunction('unlink')", $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
        $this->assertStringNotContainsString('implementLibcBridge', $bridge);
    }

    public function testUnlinkJitHelperDelegatesToVmFs(): void
    {
        $file = sys_get_temp_dir().'/phpc-unlink-'.bin2hex(random_bytes(4)).'.tmp';
        $this->assertNotFalse(file_put_contents($file, 'x'));
        $this->assertTrue(UnlinkJitHelper::invokeArgv($file));
        $this->assertFileDoesNotExist($file);
        $this->assertFalse(UnlinkJitHelper::invokeArgv($file));
        $this->assertSame(VmFs::unlink($file), UnlinkJitHelper::invokeArgv($file));
    }

    public function testSpineBundleIncludesUnlinkJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('UnlinkJitHelper.php', $spine);
        $this->assertStringContainsString('StringUnlink.php', $spine);
    }
}
