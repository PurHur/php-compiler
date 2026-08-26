<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** mb_substr / mb_strcut NestedJIT shrink guards (#27028 / #34256). */
final class MbSubstrRuntimeShrinkTest extends TestCase
{
    public function testMbSubstrCompilesMbSubstrJitHelper(): void
    {
        $builtin = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MbStrcut.php');
        $this->assertStringContainsString('MbSubstrJitHelper::substrArgv', $builtin);
        $this->assertStringContainsString('MbStrcutJitHelper::strcutArgv', $builtin);
        $this->assertStringContainsString('/ext/mbstring/MbStrcutJitHelper.php', $builtin);
        $helper = (string) \file_get_contents(__DIR__.'/../../ext/mbstring/MbStrcutJitHelper.php');
        $this->assertStringContainsString('function substrArgv', $helper);
        $this->assertStringContainsString('function strcutArgv', $helper);
        $this->assertStringNotContainsString('VmMbstring::', $helper);
        $this->assertStringNotContainsString('PHP_INT_MIN', $helper);
        // assertEncodingArgv may use private canon (#34875); peel helpers must stay non-private (#34256).
        $this->assertStringContainsString('private static function canon', $helper);
        $peelOnly = \str_replace('private static function canon', 'CANON', $helper);
        $this->assertStringNotContainsString('private static function', $peelOnly);
        $this->assertStringContainsString('$n = $sliceEnd - $sliceStart', $helper);
        $this->assertStringContainsString('$endAt = $startAt + $lenAt', $helper);
        // NestedJIT zeros rewritten params and plain copies (#34881) — arithmetic locals only.
        $this->assertStringContainsString('$startAt = $start + 0', $helper);
        $this->assertStringContainsString('$fromAt = $from + 0', $helper);
        $this->assertStringContainsString('$lenAt = $length + 0', $helper);
        $this->assertStringNotContainsString('$start = $charLen', $helper);
        $this->assertStringNotContainsString('$from = \\strlen', $helper);
        $this->assertStringNotContainsString('$length = $charLen', $helper);
    }

    public function testMbSubstrUsesJitVmHelperLink(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MbStrcut.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('strcutArgv', $source);
        $this->assertStringContainsString('substrArgv', $source);
        $this->assertStringContainsString('STRCUT_LOGICAL', $source);
        $this->assertStringContainsString('SUBSTR_LOGICAL', $source);
    }

    public function testMbSubstrBuiltinDelegatesToJitMbSubstr(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/mbstring/mb_substr.php');
        $this->assertStringContainsString('JitMbSubstr::invoke', $source);
        $this->assertStringContainsString('JitNestedHelperCoerce::callHelper', $source);
        $this->assertStringContainsString('constInt(-1, true)', $source);
        $this->assertStringNotContainsString('PHP_INT_MIN', $source);
        $this->assertStringNotContainsString('$hasLength', $source);
    }

    public function testMbStrcutUsesNestedHelperCoerce(): void
    {
        $strcut = (string) \file_get_contents(__DIR__.'/../../ext/mbstring/JitMbStrcut.php');
        $this->assertStringContainsString('JitNestedHelperCoerce::callHelper', $strcut);
        $this->assertStringContainsString('extractStringPtrFromHelperResult', $strcut);
    }
}
