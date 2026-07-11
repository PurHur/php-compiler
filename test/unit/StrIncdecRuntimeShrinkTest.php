<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StrIncdecJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** str_increment()/str_decrement() JIT routes through StrIncdecJitHelper PHP not inline LLVM (#14850). */
final class StrIncdecRuntimeShrinkTest extends TestCase
{
    public function testStringStrIncdecUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStrIncdec.php');
        $this->assertStringContainsString('StrIncdecJitHelper', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitStrIncdec.php');

        $increment = (string) file_get_contents(__DIR__.'/../../ext/standard/str_increment.php');
        $this->assertStringContainsString('StringStrIncdec::invokeIncrement', $increment);
        $this->assertStringNotContainsString('JitStrIncdec', $increment);

        $decrement = (string) file_get_contents(__DIR__.'/../../ext/standard/str_decrement.php');
        $this->assertStringContainsString('StringStrIncdec::invokeDecrement', $decrement);
        $this->assertStringNotContainsString('JitStrIncdec', $decrement);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStrIncdec.php');
        $this->assertStringContainsString('phpc_str_increment', $bridge);
        $this->assertStringContainsString('phpc_str_decrement', $bridge);
    }

    public function testStrIncdecJitHelperDelegatesToVmString(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/StrIncdecJitHelper.php');
        $this->assertStringContainsString('VmString::strIncrement', $source);
        $this->assertStringContainsString('VmString::strDecrement', $source);

        $this->assertSame('b', StrIncdecJitHelper::incrementArgv('a'));
        $this->assertSame('b', VmString::strIncrement('a'));
        $this->assertSame('a', StrIncdecJitHelper::decrementArgv('b'));
        $this->assertSame('a', VmString::strDecrement('b'));
    }

    public function testSpineBundleIncludesStrIncdecJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringNotContainsString('JitStrIncdec.php', $spine);
        $this->assertStringContainsString('StrIncdecJitHelper.php', $spine);
        $this->assertStringContainsString('StringStrIncdec.php', $spine);
    }
}
