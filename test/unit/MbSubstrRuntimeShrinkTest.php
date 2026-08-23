<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * mb_substr JIT routes through MbSubstrJitHelper PHP (#27028).
 *
 * NestedJIT via {@see \PHPCompiler\JIT\JitVmHelperLink::ensureCompiled} (peer #26598).
 * Helper + Builtin co-located with MbStrcut units to avoid new spine inventory files.
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
        $this->assertStringContainsString('PHP_INT_MIN', $helper);
    }

    public function testMbSubstrUsesJitVmHelperLink(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MbStrcut.php');
        $mbSubstr = \strstr($source, 'final class MbSubstr');
        $this->assertNotFalse($mbSubstr);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $mbSubstr);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $mbSubstr);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $mbSubstr);
        $this->assertStringNotContainsString('parseAndCompile', $mbSubstr);
        $this->assertStringNotContainsString('new JIT(', $mbSubstr);
    }

    public function testMbSubstrBuiltinDelegatesToJitMbSubstr(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/mbstring/mb_substr.php');
        $this->assertStringContainsString('JitMbSubstr::invoke', $source);
        $this->assertStringContainsString('final class JitMbSubstr', $source);
        $this->assertStringNotContainsString(
            'mb_substr() is not lowered for JIT/AOT in this compiler build',
            $source
        );
    }

    /** #34256 — runtime int offsets must coerce via callHelper (raw builder->call SIGSEGVs). */
    public function testMbSubstrAndStrcutUseNestedHelperCoerce(): void
    {
        $substr = (string) \file_get_contents(__DIR__.'/../../ext/mbstring/mb_substr.php');
        $this->assertStringContainsString('JitNestedHelperCoerce::callHelper', $substr);
        $this->assertStringContainsString('extractStringPtrFromHelperResult', $substr);
        $strcut = (string) \file_get_contents(__DIR__.'/../../ext/mbstring/JitMbStrcut.php');
        $this->assertStringContainsString('JitNestedHelperCoerce::callHelper', $strcut);
        $this->assertStringContainsString('extractStringPtrFromHelperResult', $strcut);
    }

    /** #34256 — NestedJIT helpers must not call VmMbstring (silent-wrong / crash under thin AOT). */
    public function testMbSubstrJitHelperAvoidsVmMbstring(): void
    {
        $helper = (string) \file_get_contents(__DIR__.'/../../ext/mbstring/MbStrcutJitHelper.php');
        $this->assertStringNotContainsString('VmMbstring::', $helper);
        $this->assertStringContainsString('NestedJIT must not call', $helper);
        $this->assertStringContainsString('function substrArgv', $helper);
        $this->assertStringContainsString('function strcutArgv', $helper);
        $this->assertStringNotContainsString('private static function', $helper);
        // Ternaries in NestedJIT helper bodies have caused assign-type errors.
        $this->assertStringNotContainsString(' ? ', $helper);
    }
}
