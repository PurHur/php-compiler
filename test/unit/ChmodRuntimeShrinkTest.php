<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ChmodJitHelper;
use PHPCompiler\ext\standard\VmFs;
use PHPUnit\Framework\TestCase;

/** chmod() JIT/AOT routes through thin libc chmod(2) (#33418 / peer unlink #33412). */
final class ChmodRuntimeShrinkTest extends TestCase
{
    public function testJitChmodUsesStringChmodBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitChmod.php');
        $this->assertStringContainsString('StringChmod::invoke', $source);
        $this->assertStringNotContainsString("lookupFunction('chmod')", $source);
        $this->assertStringNotContainsString('__compiler_trigger_error', $source);
    }

    public function testStringChmodBridgeUsesLibcRuntime(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringChmod.php');
        $this->assertStringContainsString('ChmodLibcRuntime::emit', $bridge);
        $this->assertStringContainsString('BasicBlockHelper::tryGetInsertBlock', $bridge);
        $this->assertStringContainsString('BasicBlockHelper::restoreInsertBlock', $bridge);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
        $libc = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ChmodLibcRuntime.php');
        $this->assertStringContainsString('chmod(2)', $libc);
        $this->assertStringContainsString('#33418', $libc);
    }

    public function testChmodJitHelperDelegatesToVmFs(): void
    {
        $file = sys_get_temp_dir().'/phpc-chmod-'.bin2hex(random_bytes(4)).'.tmp';
        $this->assertNotFalse(file_put_contents($file, 'x'));
        $this->assertTrue(ChmodJitHelper::invokeArgv($file, 0644));
        $this->assertSame(VmFs::chmod($file, 0600), ChmodJitHelper::invokeArgv($file, 0600));
        $this->assertFalse(ChmodJitHelper::invokeArgv($file.'/missing-15458', 0644));
        unlink($file);
    }

    public function testSpineBundleIncludesChmodLibcRuntime(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('ChmodJitHelper.php', $spine);
        $this->assertStringContainsString('StringChmod.php', $spine);
        $this->assertStringContainsString('ChmodLibcRuntime.php', $spine);
    }

    public function testNestedConsumersDeclareChmodModuleLocally(): void
    {
        $tempnam = (string) file_get_contents(__DIR__.'/../../ext/standard/JitTempnamKernel.php');
        $this->assertStringContainsString("['chmod', \$i32, [\$i8p, \$i32]]", $tempnam);
        $m5 = (string) file_get_contents(__DIR__.'/../../lib/JIT/M5TrivialEchoNative.php');
        $this->assertStringContainsString('ensureChmod', $m5);
        $this->assertStringContainsString('#31374', $m5);
    }
}
