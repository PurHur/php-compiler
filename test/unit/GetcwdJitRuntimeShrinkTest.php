<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\GetcwdJitHelper;
use PHPCompiler\ext\standard\VmGetcwdNative;
use PHPUnit\Framework\TestCase;

/**
 * getcwd() AOT via libc realpath(".") (#10451, #26928 — peer SysGetTempDir #26929).
 */
final class GetcwdJitRuntimeShrinkTest extends TestCase
{
    public function testGetcwdJitUsesLibcRealpathNotNestedJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/GetcwdJit.php');
        $this->assertStringContainsString("lookupFunction('realpath')", $source);
        $this->assertStringContainsString('BasicBlockHelper::entryAlloca', $source);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringNotContainsString('HELPER_PATH', $source);
        $this->assertStringNotContainsString('lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertLessThan(120, \substr_count($source, "\n") + 1);
    }

    public function testGetcwdJitHelperStillMatchesVmNative(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/GetcwdJitHelper.php');
        $this->assertStringContainsString('VmGetcwdNative::resolve', $source);
        $viaHelper = GetcwdJitHelper::resolveJit();
        $viaNative = VmGetcwdNative::resolve();
        if (false === $viaNative) {
            $this->assertSame('', $viaHelper);
        } else {
            $this->assertSame($viaNative, $viaHelper);
        }
    }
}
