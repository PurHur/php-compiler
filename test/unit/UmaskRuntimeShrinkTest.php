<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\UmaskJitHelper;
use PHPUnit\Framework\TestCase;

/** umask() AOT uses libc umask(2); VM helper stays on PHP (#15497 / #33422). */
final class UmaskRuntimeShrinkTest extends TestCase
{
    public function testJitUmaskUsesPhpBridgeNotLibc(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitUmask.php');
        $this->assertStringContainsString('StringUmask::invoke', $source);
        $this->assertStringNotContainsString("lookupFunction('umask')", $source);
        $this->assertStringNotContainsString('ensureLibcUmask', $source);
    }

    public function testStringUmaskBridgeUsesUmaskLibcRuntime(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringUmask.php');
        $this->assertStringContainsString('UmaskLibcRuntime', $bridge);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringContainsString('BasicBlockHelper::tryGetInsertBlock', $bridge);
        $this->assertStringContainsString('BasicBlockHelper::restoreInsertBlock', $bridge);
        $libc = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/UmaskLibcRuntime.php');
        $this->assertStringContainsString("lookupFunction('umask')", $libc);
        $this->assertStringContainsString('LibcExtern::ensureUmask', $libc);
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

    public function testJitSleepGuardsCompileTimeObjectLabelOnValueBoxes(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../ext/standard/JitSleep.php');
        $this->assertStringContainsString('TYPE_OBJECT !== $arg->type', $src);
        $this->assertStringContainsString('#33422', $src);
    }

    public function testSpineBundleIncludesUmaskLibcRuntime(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('UmaskJitHelper.php', $spine);
        $this->assertStringContainsString('StringUmask.php', $spine);
        $this->assertStringContainsString('UmaskLibcRuntime.php', $spine);
    }
}
