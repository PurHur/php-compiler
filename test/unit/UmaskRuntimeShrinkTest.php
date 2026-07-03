<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\UmaskJitHelper;
use PHPUnit\Framework\TestCase;

/** umask() JIT routes through UmaskJitHelper PHP not libc umask LLVM (#15497). */
final class UmaskRuntimeShrinkTest extends TestCase
{
    public function testJitUmaskUsesPhpBridgeNotLibc(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitUmask.php');
        $this->assertStringContainsString('StringUmask::invoke', $source);
        $this->assertStringNotContainsString("lookupFunction('umask')", $source);
        $this->assertStringNotContainsString('ensureLibcUmask', $source);
    }

    public function testStringUmaskBridgeUsesUmaskJitHelper(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringUmask.php');
        $this->assertStringContainsString('UmaskJitHelper', $bridge);
        $this->assertStringNotContainsString("lookupFunction('umask')", $bridge);
    }

    public function testUmaskJitHelperMatchesPhpUmask(): void
    {
        $saved = (int) \umask();
        try {
            $this->assertSame((int) \umask(), UmaskJitHelper::getArgv());
            $prev = UmaskJitHelper::setArgv(0022);
            $this->assertSame(0022, (int) \umask());
            $this->assertSame($saved, UmaskJitHelper::setArgv($prev));
        } finally {
            \umask($saved);
        }
    }

    public function testSpineBundleIncludesUmaskJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('UmaskJitHelper.php', $spine);
        $this->assertStringContainsString('StringUmask.php', $spine);
    }
}
