<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StrRepeatJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** str_repeat() JIT routes through StrRepeatJitHelper + JitVmHelperLink (#14602, #21601). */
final class StrRepeatRuntimeShrinkTest extends TestCase
{
    public function testStringStrRepeatUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStrRepeat.php');
        $this->assertStringContainsString('StrRepeatJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('BasicBlockHelper', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitStrRepeat.php');

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/str_repeat.php');
        $this->assertStringContainsString('StringStrRepeat::ensureLinked', $builtin);
        $this->assertStringContainsString('__compiler_str_repeat', $builtin);
        $this->assertStringNotContainsString('JitStrRepeat', $builtin);
        $this->assertStringNotContainsString('strrepeat_head', $builtin);
    }

    public function testStrRepeatJitHelperDelegatesToVmString(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/StrRepeatJitHelper.php');
        $this->assertStringContainsString('VmString::repeat', $source);

        $this->assertSame('ababab', StrRepeatJitHelper::strRepeatArgv('ab', 3));
        $this->assertSame('ababab', VmString::repeat('ab', 3));
    }

    public function testSpineBundleIncludesStrRepeatJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringNotContainsString('JitStrRepeat.php', $spine);
        $this->assertStringContainsString('StrRepeatJitHelper.php', $spine);
        $this->assertStringContainsString('StringStrRepeat.php', $spine);
    }
}
