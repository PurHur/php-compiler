<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\PregJitHelper;
use PHPUnit\Framework\TestCase;

/** preg_* JIT embed routes through PregJitHelper PHP not StringPregMatchStandaloneLlvm (#9542). */
final class PregMatchRuntimeShrinkTest extends TestCase
{
    public function testStringPregMatchJitIsThinDispatcher(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringPregMatchJit.php');
        $this->assertStringContainsString('PregMatchRuntime', $source);
        $this->assertStringNotContainsString('StringPregMatchStandaloneLlvm::implement', $source);
        $this->assertStringNotContainsString('pcre2_match_8', $source);
        $this->assertStringNotContainsString('emitMatchEx', $source);
        $this->assertLessThan(80, \substr_count($source, "\n") + 1);
    }

    public function testPregMatchRuntimeUsesJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PregMatchRuntime.php');
        $this->assertStringContainsString('PregJitHelper', $source);
        $this->assertStringNotContainsString('pcre2_compile', $source);

        $standalone = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringPregMatchStandaloneLlvm.php');
        $this->assertStringContainsString('implementReplaceCallbackOnly', $standalone);
        $this->assertStringNotContainsString('public static function implement(', $standalone);
        $this->assertLessThan(1400, substr_count($standalone, "\n") + 1);
    }

    public function testPregJitHelperDelegatesToVmPregNative(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/PregJitHelper.php');
        $this->assertStringContainsString('VmPregNative::pregMatch', $source);
        $this->assertStringContainsString('VmPregNative::pregReplace', $source);
        $this->assertStringContainsString('VmPregMatches::hostMatchesToHashTable', $source);
    }

    public function testPregJitHelperMatchArgvSemantics(): void
    {
        $this->assertSame(1, PregJitHelper::matchArgv('/(\d+)/', 'abc123'));
        $this->assertSame(0, PregJitHelper::lastError());
        $this->assertSame(0, PregJitHelper::matchArgv('/z/', 'abc'));
        $this->assertSame(-1, PregJitHelper::matchArgv('[', 'abc'));
        $this->assertSame(1, PregJitHelper::lastError());
    }
}
