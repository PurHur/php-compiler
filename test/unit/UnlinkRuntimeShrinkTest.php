<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\UnlinkJitHelper;
use PHPCompiler\ext\standard\VmFs;
use PHPUnit\Framework\TestCase;

/** unlink() JIT/AOT routes through thin libc unlink(2) (#33412 / peer mkdir #33402). */
final class UnlinkRuntimeShrinkTest extends TestCase
{
    public function testJitUnlinkUsesStringUnlinkBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitUnlink.php');
        $this->assertStringContainsString('StringUnlink::invoke', $source);
        $this->assertStringNotContainsString("lookupFunction('unlink')", $source);
    }

    public function testStringUnlinkBridgeUsesLibcRuntime(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringUnlink.php');
        $this->assertStringContainsString('UnlinkLibcRuntime::emit', $bridge);
        $this->assertStringContainsString('BasicBlockHelper::tryGetInsertBlock', $bridge);
        $this->assertStringContainsString('BasicBlockHelper::restoreInsertBlock', $bridge);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
        $libc = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/UnlinkLibcRuntime.php');
        $this->assertStringContainsString('unlink(2)', $libc);
        $this->assertStringContainsString('#33412', $libc);
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

    public function testSpineBundleIncludesUnlinkLibcRuntime(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('UnlinkJitHelper.php', $spine);
        $this->assertStringContainsString('StringUnlink.php', $spine);
        $this->assertStringContainsString('UnlinkLibcRuntime.php', $spine);
    }
}
