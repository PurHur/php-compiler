<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StrspnJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/**
 * StringStrspn NestedJIT via JitVmHelperLink::ensureCompiled (#24174 / peer #24094).
 */
final class StrspnRuntimeShrinkTest extends TestCase
{
    public function testStringStrspnUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStrspn.php');
        $this->assertStringContainsString('StrspnJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitStrspn.php');

        $lowering = (string) file_get_contents(__DIR__.'/../../ext/standard/SpnJitLowering.php');
        $this->assertStringContainsString('phpc_strspn_extended', $lowering);
        $this->assertStringNotContainsString('JitStrspn', $lowering);
    }

    public function testStrspnJitHelperDelegatesToVmString(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/StrspnJitHelper.php');
        $this->assertStringContainsString('VmString::strspn', $source);
        $this->assertStringContainsString('VmString::strcspn', $source);

        $this->assertSame(3, StrspnJitHelper::strspnArgv('abc123', 'abc'));
        $this->assertSame(3, VmString::strspn('abc123', 'abc'));
        $this->assertSame(3, StrspnJitHelper::strcspnArgv('abc123', '123'));
        $this->assertSame(3, VmString::strcspn('abc123', '123'));
        $this->assertSame(0, StrspnJitHelper::extendedArgvInt('123', 'abc', 0, 0, 1, 1));
        $this->assertSame(3, StrspnJitHelper::extendedArgvInt('abc123', 'abc', 0, 0, 1, 1));
    }

    public function testSpineBundleIncludesStrspnJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringNotContainsString('JitStrspn.php', $spine);
        $this->assertStringContainsString('StrspnJitHelper.php', $spine);
        $this->assertStringContainsString('StringStrspn.php', $spine);
    }
}
