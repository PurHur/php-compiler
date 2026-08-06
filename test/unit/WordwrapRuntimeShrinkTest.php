<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\WordwrapJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** wordwrap() JIT routes through WordwrapJitHelper PHP for embed + user-script AOT (#14565, #17724). */
final class WordwrapRuntimeShrinkTest extends TestCase
{
    public function testStringWordwrapUsesJitHelperNotLlvmMonolith(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringWordwrap.php');
        $this->assertStringContainsString('WordwrapJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('StringWordwrapLlvm', $source);
        $this->assertStringNotContainsString('WordwrapLlvmEmit', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringWordwrapLlvm.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/WordwrapLlvmEmit.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitWordwrap.php');

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/wordwrap.php');
        $this->assertStringContainsString('StringWordwrap::ensureLinked', $builtin);
        $this->assertStringNotContainsString('JitWordwrap', $builtin);
    }

    public function testWordwrapJitHelperIsSelfContainedAndMatchesVmString(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/WordwrapJitHelper.php');
        $this->assertDoesNotMatchRegularExpression('/\bVmString::/', $source);
        $this->assertStringContainsString('Self-contained', $source);
        $this->assertStringContainsString('wordwrapArgv', $source);

        $this->assertSame(
            "hello\nworld",
            WordwrapJitHelper::wordwrapArgv('hello world', 5, "\n", 0)
        );
        $this->assertSame(
            "hello\nworld",
            VmString::wordwrap('hello world', 5, "\n", false)
        );
        $this->assertSame(
            "hello|\nworld",
            WordwrapJitHelper::wordwrapArgv('hello world', 5, "|\n", 0)
        );
        $this->assertSame(
            'super|calif|ragil|istic',
            WordwrapJitHelper::wordwrapArgv('supercalifragilistic', 5, '|', 1)
        );
        // #27237 — issue repro (helper SSOT; AOT default cache covered by test/repro/issue_27237_aot_wordwrap.php)
        $this->assertSame('hello|world|foo', WordwrapJitHelper::wordwrapArgv('hello world foo', 5, '|', 0));
        $this->assertSame('hello|world|foo', WordwrapJitHelper::wordwrapArgv('hello world foo', 5, '|', 1));
        $this->assertSame('veryl|ongwo|rd', WordwrapJitHelper::wordwrapArgv('verylongword', 5, '|', 1));
        $this->assertSame(
            'hello|world|foo',
            VmString::wordwrap('hello world foo', 5, '|', true)
        );
    }

    public function testSpineBundleOmitsDeletedJitWordwrap(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringNotContainsString('JitWordwrap.php', $spine);
        $this->assertStringNotContainsString('StringWordwrapLlvm.php', $spine);
        $this->assertStringNotContainsString('WordwrapLlvmEmit.php', $spine);
        $this->assertStringContainsString('WordwrapJitHelper.php', $spine);
        $this->assertStringContainsString('StringWordwrap.php', $spine);
    }
}
