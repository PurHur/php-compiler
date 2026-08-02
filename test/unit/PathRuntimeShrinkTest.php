<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\PathJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** dirname()/basename() JIT: inline LLVM via JitPath (#26905; NestedJIT PathJitHelper SEGV). */
final class PathRuntimeShrinkTest extends TestCase
{
    public function testJitPathUsesInlineLlvmNotNestedJitBridge(): void
    {
        $jitPath = (string) file_get_contents(__DIR__.'/../../ext/standard/JitPath.php');
        $this->assertStringContainsString('trimTrailingSeparators', $jitPath);
        $this->assertStringContainsString('scanBackwardForSeparator', $jitPath);
        $this->assertStringContainsString('basenameWithSuffix', $jitPath);
        $this->assertStringNotContainsString('StringPath::invoke', $jitPath);
        $this->assertStringNotContainsString('JitVmHelperLink', $jitPath);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringPath.php');
        $this->assertStringContainsString('JitPath::dirname', $bridge);
        $this->assertStringContainsString('JitPath::basename', $bridge);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringNotContainsString('phpc_dirname', $bridge);
        $this->assertStringNotContainsString('PathJitHelper::', $bridge);
    }

    public function testPathJitHelperDelegatesToVmString(): void
    {
        $this->assertSame(VmString::dirname('/foo/bar/baz.txt'), PathJitHelper::dirnameArgv('/foo/bar/baz.txt'));
        $this->assertSame(VmString::dirname('/a/b/c', 2), PathJitHelper::dirnameWithLevelsArgv('/a/b/c', 2));
        $this->assertSame(VmString::basename('/foo/bar/baz.txt'), PathJitHelper::basenameArgv('/foo/bar/baz.txt'));
        $this->assertSame(
            VmString::basename('/foo/bar/baz.txt', '.txt'),
            PathJitHelper::basenameWithSuffixArgv('/foo/bar/baz.txt', '.txt')
        );
        $this->assertSame('dir', VmString::basename('/a/dir', 'dir'));
        $this->assertSame(
            VmString::basename('/a/dir', 'dir'),
            PathJitHelper::basenameWithSuffixArgv('/a/dir', 'dir')
        );
    }

    public function testSpineBundleIncludesPathJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('PathJitHelper.php', $spine);
        $this->assertStringContainsString('StringPath.php', $spine);
    }
}
