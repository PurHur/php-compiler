<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\NCompareJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/**
 * StringNCompare NestedJIT wrap → JitVmHelperLink::ensureBridge only (#24410 / peer #24382).
 */
final class NCompareRuntimeShrinkTest extends TestCase
{
    public function testStringNCompareUsesJitVmHelperLinkNotOuterNestedJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringNCompare.php');
        $this->assertStringContainsString('NCompareJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertLessThan(120, \substr_count($source, "\n") + 1);
    }

    public function testNCompareJitHelperDelegatesToVmString(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/NCompareJitHelper.php');
        $this->assertStringContainsString('VmString::memcmp', $source);
        $this->assertStringContainsString('VmString::strncmp', $source);

        $this->assertSame(0, NCompareJitHelper::memcmpArgv('abc', 'abc', 3));
        $this->assertSame(0, VmString::memcmp('abc', 'abc', 3));
        $this->assertSame(0, NCompareJitHelper::strncmpArgv('abcdef', 'abcxyz', 3));
        $this->assertSame(0, VmString::strncmp('abcdef', 'abcxyz', 3));
        $this->assertLessThan(0, NCompareJitHelper::memcmpArgv('abc', 'abd', 3));
        $this->assertGreaterThan(0, NCompareJitHelper::strncmpArgv('abd', 'abc', 3));
    }

    public function testSpineBundleIncludesNCompareJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('NCompareJitHelper.php', $spine);
        $this->assertStringContainsString('StringNCompare.php', $spine);
    }
}
