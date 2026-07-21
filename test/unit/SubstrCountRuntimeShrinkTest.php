<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\SubstrCountJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** substr_count() JIT routes through SubstrCountJitHelper PHP not inline LLVM (#14691, #21773). */
final class SubstrCountRuntimeShrinkTest extends TestCase
{
    public function testStringSubstrCountUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringSubstrCount.php');
        $this->assertStringContainsString('SubstrCountJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitSubstrCount.php');

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/substr_count.php');
        $this->assertStringContainsString('StringSubstrCount::ensureLinked', $builtin);
        $this->assertStringContainsString('phpc_substr_count', $builtin);
        $this->assertStringNotContainsString('JitSubstrCount', $builtin);
    }

    public function testSubstrCountJitHelperDelegatesToVmString(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/SubstrCountJitHelper.php');
        $this->assertStringContainsString('VmString::substr_count', $source);

        $this->assertSame(4, SubstrCountJitHelper::countArgv('hello hello', 'l', 0, 0, 0));
        $this->assertSame(4, VmString::substr_count('hello hello', 'l'));
        $this->assertSame(2, SubstrCountJitHelper::countArgv('hello hello', 'll', 0, 0, 0));
        $this->assertSame(2, SubstrCountJitHelper::countArgv('hello hello', 'l', 6, 0, 0));
        $this->assertSame(2, SubstrCountJitHelper::countArgv('hello hello', 'l', 0, 5, 1));
    }

    public function testSpineBundleIncludesSubstrCountJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringNotContainsString('JitSubstrCount.php', $spine);
        $this->assertStringContainsString('SubstrCountJitHelper.php', $spine);
        $this->assertStringContainsString('StringSubstrCount.php', $spine);
    }
}
