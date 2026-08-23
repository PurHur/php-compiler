<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * mb_substr JIT routes through MbSubstrJitHelper PHP (#27028 / #34256).
 */
final class MbSubstrRuntimeShrinkTest extends TestCase
{
    public function testMbSubstrCompilesMbSubstrJitHelper(): void
    {
        $builtin = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MbStrcut.php');
        $this->assertStringContainsString('final class MbSubstr', $builtin);
        $this->assertStringContainsString('MbSubstrJitHelper::substrArgv', $builtin);
        $this->assertStringContainsString('/ext/mbstring/MbStrcutJitHelper.php', $builtin);
        $helper = (string) \file_get_contents(__DIR__.'/../../ext/mbstring/MbStrcutJitHelper.php');
        $this->assertStringContainsString('final class MbSubstrJitHelper', $helper);
        $this->assertStringNotContainsString('VmMbstring::substr', $helper);
        $this->assertStringContainsString('function substrArgv', $helper);
    }

    public function testMbSubstrUsesJitVmHelperLink(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MbStrcut.php');
        $mbSubstr = \strstr($source, 'final class MbSubstr');
        $this->assertNotFalse($mbSubstr);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $mbSubstr);
        $this->assertStringContainsString('skip stale helper-runtime cache', $mbSubstr);
    }

    public function testMbSubstrBuiltinDelegatesToJitMbSubstr(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/mbstring/mb_substr.php');
        $this->assertStringContainsString('JitMbSubstr::invoke', $source);
        $this->assertStringContainsString('JitNestedHelperCoerce::callHelper', $source);
        $this->assertStringContainsString('constInt(-1, true)', $source);
        $this->assertStringNotContainsString('PHP_INT_MIN', $source);
    }

    public function testMbSubstrAndStrcutUseNestedHelperCoerce(): void
    {
        $strcut = (string) \file_get_contents(__DIR__.'/../../ext/mbstring/JitMbStrcut.php');
        $this->assertStringContainsString('JitNestedHelperCoerce::callHelper', $strcut);
        $this->assertStringContainsString('extractStringPtrFromHelperResult', $strcut);
    }

    public function testMbSubstrJitHelperAvoidsVmMbstringAndPhpIntMin(): void
    {
        $helper = (string) \file_get_contents(__DIR__.'/../../ext/mbstring/MbStrcutJitHelper.php');
        $this->assertStringNotContainsString('VmMbstring::', $helper);
        $this->assertStringNotContainsString('PHP_INT_MIN', $helper);
        $this->assertStringContainsString('function substrArgv', $helper);
        $this->assertStringContainsString('function strcutArgv', $helper);
        $this->assertStringNotContainsString('private static function', $helper);
    }
}
