<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmWordwrap;
use PHPCompiler\ext\standard\WordwrapJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** wordwrap() JIT routes through WordwrapJitHelper + VmWordwrap (#14565, #17724, #30812). */
final class WordwrapRuntimeShrinkTest extends TestCase
{
    public function testStringWordwrapUsesJitHelperNotLlvmMonolith(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringWordwrap.php');
        $this->assertStringContainsString('WordwrapJitHelper', $source);
        $this->assertStringContainsString('VmWordwrap.php', $source);
        $this->assertStringContainsString('ensureCompiledBundle', $source);
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

        $cache = (string) file_get_contents(__DIR__.'/../../lib/AOT/HelperRuntimeCache.php');
        $this->assertStringContainsString('wordwrapjithelper::wordwrapargv', $cache);
    }

    public function testWordwrapJitHelperDelegatesToVmWordwrapAndMatchesVmString(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/WordwrapJitHelper.php');
        $this->assertDoesNotMatchRegularExpression('/\bVmString::/', $source);
        $this->assertStringContainsString('VmWordwrap::wrap', $source);
        $this->assertStringContainsString('wordwrapArgv', $source);

        $vm = (string) file_get_contents(__DIR__.'/../../ext/standard/VmWordwrap.php');
        $this->assertStringNotContainsString('$text[$', $vm);
        $this->assertStringContainsString('\\strlen(', $vm);
        $this->assertStringContainsString('\\substr(', $vm);

        $this->assertSame(
            "hello\nworld",
            WordwrapJitHelper::wordwrapArgv('hello world', 5, "\n", 0)
        );
        $this->assertSame(
            "hello\nworld",
            VmString::wordwrap('hello world', 5, "\n", false)
        );
        $this->assertSame(
            "hello\nworld",
            VmWordwrap::wrap('hello world', 5, "\n", 0)
        );
        $this->assertSame(
            "hello|\nworld",
            WordwrapJitHelper::wordwrapArgv('hello world', 5, "|\n", 0)
        );
        $this->assertSame(
            'super|calif|ragil|istic',
            WordwrapJitHelper::wordwrapArgv('supercalifragilistic', 5, '|', 1)
        );
        // #27237 / #30812 — issue repro (helper SSOT)
        $this->assertSame('hello|world|foo', WordwrapJitHelper::wordwrapArgv('hello world foo', 5, '|', 0));
        $this->assertSame('hello|world|foo', WordwrapJitHelper::wordwrapArgv('hello world foo', 5, '|', 1));
        $this->assertSame('veryl|ongwo|rd', WordwrapJitHelper::wordwrapArgv('verylongword', 5, '|', 1));
        $this->assertSame(
            'hello|world|foo',
            VmString::wordwrap('hello world foo', 5, '|', true)
        );
        $this->assertSame(
            'abc|def|ghi',
            WordwrapJitHelper::wordwrapArgv('abc def ghi', 3, '|', 1)
        );
    }

    public function testSpineBundleIncludesVmWordwrap(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringNotContainsString('JitWordwrap.php', $spine);
        $this->assertStringNotContainsString('StringWordwrapLlvm.php', $spine);
        $this->assertStringNotContainsString('WordwrapLlvmEmit.php', $spine);
        $this->assertStringContainsString('VmWordwrap.php', $spine);
        $this->assertStringContainsString('WordwrapJitHelper.php', $spine);
        $this->assertStringContainsString('StringWordwrap.php', $spine);
    }
}
