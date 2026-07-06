<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\WordwrapJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** wordwrap() user-script AOT defers nested JIT; full builds use WordwrapJitHelper (#14565, #16734). */
final class WordwrapRuntimeShrinkTest extends TestCase
{
    public function testStringWordwrapUsesJitHelperNotLlvmMonolith(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringWordwrap.php');
        $this->assertStringContainsString('WordwrapJitHelper', $source);
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitWordwrap.php');

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/wordwrap.php');
        $this->assertStringContainsString('JitWordwrap::wrap', $builtin);
        $this->assertStringContainsString('shouldDeferNestedHelper', $builtin);
    }

    public function testWordwrapJitHelperDelegatesToVmString(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/WordwrapJitHelper.php');
        $this->assertStringContainsString('VmString::wordwrap', $source);

        $this->assertSame(
            "hello\nworld",
            WordwrapJitHelper::wordwrapArgv('hello world', 5, "\n", 0)
        );
        $this->assertSame(
            "hello\nworld",
            VmString::wordwrap('hello world', 5, "\n", false)
        );
    }

    public function testSpineBundleOmitsDeletedJitWordwrap(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringNotContainsString('JitWordwrap.php', $spine);
        $this->assertStringContainsString('WordwrapJitHelper.php', $spine);
        $this->assertStringContainsString('StringWordwrap.php', $spine);
    }
}
