<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\StrWordCountJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** str_word_count() JIT routes through StrWordCountJitHelper PHP not inline LLVM (#14651). */
final class StrWordCountJitRuntimeShrinkTest extends TestCase
{
    public function testStringStrWordCountUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStrWordCount.php');
        $this->assertStringContainsString('StrWordCountJitHelper', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitStrWordCount.php');

        $builtin = (string) \file_get_contents(__DIR__.'/../../ext/standard/str_word_count.php');
        $this->assertStringContainsString('StringStrWordCount::ensureLinked', $builtin);
        $this->assertStringContainsString('phpc_str_word_count_count', $builtin);
        $this->assertStringNotContainsString('JitStrWordCount', $builtin);
    }

    public function testStrWordCountJitHelperDelegatesToVmString(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/StrWordCountJitHelper.php');
        $this->assertStringContainsString('VmString::str_word_count', $source);

        $s = 'Hello fri3nd, you are looking good today!';
        $this->assertSame(8, StrWordCountJitHelper::countArgv($s));
        $this->assertSame(8, VmString::str_word_count($s));

        $words = StrWordCountJitHelper::wordsArgv('a b c', 1, '');
        $collected = [];
        foreach ($words->iterateKeyed(true) as [, $value]) {
            $collected[] = $value->resolveIndirect()->toString();
        }
        $this->assertSame(['a', 'b', 'c'], $collected);
    }

    public function testSpineBundleIncludesStrWordCountJitHelper(): void
    {
        $spine = (string) \file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringNotContainsString('JitStrWordCount.php', $spine);
        $this->assertStringContainsString('StrWordCountJitHelper.php', $spine);
        $this->assertStringContainsString('StringStrWordCount.php', $spine);
    }
}
