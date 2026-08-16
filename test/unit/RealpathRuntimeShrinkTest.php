<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\RealpathJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** realpath() JIT routes through RealpathJitHelper PHP not libc realpath LLVM (#15323). */
final class RealpathRuntimeShrinkTest extends TestCase
{
    public function testJitRealpathUsesPhpBridgeNotLibc(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitRealpath.php');
        $this->assertStringContainsString('StringRealpath::invoke', $source);
        $this->assertStringNotContainsString("lookupFunction('realpath')", $source);
        $this->assertStringNotContainsString("lookupFunction('strlen')", $source);
        $this->assertStringNotContainsString('resolveInline', $source);
    }

    public function testStringRealpathBridgeUsesRealpathJitHelper(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringRealpath.php');
        $this->assertStringContainsString('RealpathJitHelper', $bridge);
        $this->assertStringNotContainsString("lookupFunction('realpath')", $bridge);
    }

    public function testRealpathJitHelperDelegatesToVmString(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'phpc-rp-');
        $this->assertNotFalse($path);
        $this->assertSame(VmString::realpath($path), RealpathJitHelper::resolveArgv($path));
        $this->assertNull(RealpathJitHelper::resolveArgv($path.'/missing-15323'));
        @unlink($path);
    }

    public function testSpineBundleIncludesRealpathJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('RealpathJitHelper.php', $spine);
        $this->assertStringContainsString('StringRealpath.php', $spine);
    }

    public function testModuleDropsAlwaysOnRealpathDecl(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/Module.php');
        $this->assertStringNotContainsString("lookupFunction('realpath')", $source);
        $this->assertStringNotContainsString("addFunction('realpath'", $source);
        $this->assertStringContainsString('#30530', $source);
        $this->assertStringContainsString('#31534', $source);
    }

    public function testLibcExternDropsAlwaysOnRealpathAndStrdup(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/LibcExtern.php');
        $this->assertStringNotContainsString("'realpath' =>", $source);
        $this->assertStringNotContainsString("'strdup' =>", $source);
        $this->assertStringContainsString('#31534', $source);
    }
}
