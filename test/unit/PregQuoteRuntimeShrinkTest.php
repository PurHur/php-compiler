<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\PregQuoteJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** preg_quote() JIT routes through PregQuoteJitHelper + JitVmHelperLink (#14743, #21751, #26827, #27564). */
final class PregQuoteRuntimeShrinkTest extends TestCase
{
    public function testStringPregQuoteUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringPregQuote.php');
        $this->assertStringContainsString('PregQuoteJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('ensureJitHelperCompiled', $source);
        $this->assertStringNotContainsString('preg_quote_count_head', $source);
        $this->assertStringNotContainsString('shouldEscape', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitPregQuote.php');

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/preg_quote.php');
        $this->assertStringContainsString('StringPregQuote::ensureLinked', $builtin);
        $this->assertStringContainsString('__string__preg_quote', $builtin);
        $this->assertStringContainsString('__string__alloc', $builtin);
        $this->assertStringNotContainsString('JitPregQuote', $builtin);
    }

    public function testUserScriptAotForcesNestedJitOfPregQuoteHelper(): void
    {
        $cache = (string) file_get_contents(__DIR__.'/../../lib/AOT/HelperRuntimeCache.php');
        $this->assertStringContainsString(
            "phpcompiler\\\\ext\\\\standard\\\\pregquotejithelper::pregquoteargv",
            $cache,
            'USER_SCRIPT_INLINE_ONLY must NestedJIT pregQuoteArgv — prelinked unit.o returns "" (#27564)'
        );
    }

    public function testPregQuoteJitHelperMatchesVmStringWithoutCallingIt(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/PregQuoteJitHelper.php');
        $this->assertStringNotContainsString('VmString::pregQuote', $source);
        $this->assertStringContainsString('needsEscape', $source);

        $expected = VmString::pregQuote('a.b*?', '.');
        $this->assertSame($expected, PregQuoteJitHelper::pregQuoteArgv('a.b*?', '.'));
        $this->assertSame(
            VmString::pregQuote('a.b*?', null),
            PregQuoteJitHelper::pregQuoteArgv('a.b*?', '')
        );
        $this->assertSame('a\\.b\\*c', PregQuoteJitHelper::pregQuoteArgv('a.b*c', '/'));
        $this->assertSame(
            VmString::pregQuote("a\0b"),
            PregQuoteJitHelper::pregQuoteArgv("a\0b", '')
        );
    }

    public function testSpineBundleOmitsDeletedJitPregQuote(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringNotContainsString('JitPregQuote.php', $spine);
        $this->assertStringContainsString('PregQuoteJitHelper.php', $spine);
        $this->assertStringContainsString('StringPregQuote.php', $spine);
    }
}
