<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ChmodJitHelper;
use PHPCompiler\ext\standard\VmFs;
use PHPUnit\Framework\TestCase;

/** chmod() JIT routes through ChmodJitHelper PHP not libc chmod LLVM (#15458). */
final class ChmodRuntimeShrinkTest extends TestCase
{
    public function testJitChmodUsesPhpBridgeNotLibc(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitChmod.php');
        $this->assertStringContainsString('StringChmod::invoke', $source);
        $this->assertStringNotContainsString("lookupFunction('chmod')", $source);
        $this->assertStringNotContainsString('__compiler_trigger_error', $source);
    }

    public function testStringChmodBridgeUsesChmodJitHelper(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringChmod.php');
        $this->assertStringContainsString('ChmodJitHelper', $bridge);
        $this->assertStringNotContainsString("lookupFunction('chmod')", $bridge);
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

    public function testSpineBundleIncludesChmodJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('ChmodJitHelper.php', $spine);
        $this->assertStringContainsString('StringChmod.php', $spine);
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
