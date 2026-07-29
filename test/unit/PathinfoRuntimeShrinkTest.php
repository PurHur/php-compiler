<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\PathinfoJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** pathinfo() extension/filename JIT routes through PathinfoJitHelper PHP not inline LLVM (#15322). */
final class PathinfoRuntimeShrinkTest extends TestCase
{
    public function testJitPathinfoUsesPathinfoJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitPathinfo.php');
        $this->assertStringContainsString('StringPathinfo::invokeExtension', $source);
        $this->assertStringContainsString('StringPathinfo::invokeFilename', $source);
        $this->assertStringContainsString('StringPathinfo::invokeComponent', $source);
        $this->assertStringNotContainsString('scanBackwardForDot', $source);
        $this->assertStringNotContainsString('extensionFromBasename', $source);
        $this->assertStringNotContainsString('string_trim::jitCopySlice', $source);
    }

    public function testStringPathinfoBridgeUsesPathinfoJitHelper(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringPathinfo.php');
        $this->assertStringContainsString('PathinfoJitHelper', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $bridge);
    }

    public function testPathinfoJitHelperDelegatesToVmString(): void
    {
        $path = '/var/www/html/index.php';
        $this->assertSame(VmString::pathExtension($path), PathinfoJitHelper::extensionArgv($path));
        $this->assertSame(VmString::pathFilename($path), PathinfoJitHelper::filenameArgv($path));
        $this->assertSame(
            VmString::pathinfo($path, 4),
            PathinfoJitHelper::componentArgv($path, 4)
        );
        $this->assertSame(
            VmString::pathinfo($path, 12),
            PathinfoJitHelper::componentArgv($path, 12)
        );
    }

    /** php-src php_pathinfo options==0 → '' (#24941). */
    public function testPathinfoFlagsZeroReturnsEmptyString(): void
    {
        $this->assertSame('', VmString::pathinfo('/a/b.txt', 0));
        $this->assertSame('string', \gettype(VmString::pathinfo('/a/b.txt', 0)));
    }

    public function testSpineBundleIncludesPathinfoJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('PathinfoJitHelper.php', $spine);
        $this->assertStringContainsString('StringPathinfo.php', $spine);
    }
}
