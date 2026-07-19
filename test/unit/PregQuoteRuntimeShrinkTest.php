<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\PregQuoteJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** preg_quote() JIT routes through PregQuoteJitHelper PHP not inline LLVM (#14743). */
final class PregQuoteRuntimeShrinkTest extends TestCase
{
    public function testStringPregQuoteUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringPregQuote.php');
        $this->assertStringContainsString('PregQuoteJitHelper', $source);
        $this->assertStringNotContainsString('preg_quote_count_head', $source);
        $this->assertStringNotContainsString('shouldEscape', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitPregQuote.php');

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/preg_quote.php');
        $this->assertStringContainsString('StringPregQuote::ensureLinked', $builtin);
        $this->assertStringContainsString('__string__preg_quote', $builtin);
        $this->assertStringNotContainsString('JitPregQuote', $builtin);
    }

    public function testPregQuoteJitHelperDelegatesToVmString(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/PregQuoteJitHelper.php');
        $this->assertStringContainsString('VmString::pregQuote', $source);

        $expected = VmString::pregQuote('a.b*?', '.');
        $this->assertSame($expected, PregQuoteJitHelper::pregQuoteArgv('a.b*?', '.'));
        $this->assertSame($expected, VmString::pregQuote('a.b*?', '.'));
        $this->assertSame(
            VmString::pregQuote('a.b*?', null),
            PregQuoteJitHelper::pregQuoteArgv('a.b*?', '')
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
