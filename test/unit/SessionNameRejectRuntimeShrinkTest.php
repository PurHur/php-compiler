<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\SessionNameJitHelper;
use PHPCompiler\ext\standard\VmSession;
use PHPUnit\Framework\TestCase;

/**
 * SessionNameRejectRuntime routes through SessionNameJitHelper PHP via
 * JitVmHelperLink::ensureCompiled (#12563 / #25092 / peer #25042).
 */
final class SessionNameRejectRuntimeShrinkTest extends TestCase
{
    public function testSessionNameRejectRuntimeRoutesThroughJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SessionNameRejectRuntime.php');
        $this->assertStringContainsString('SessionNameJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $this->assertStringNotContainsString('PHP_COMPILER_SELFHOST_AOT', $source);
        $this->assertStringNotContainsString('putenv', $source);
        $this->assertLessThan(100, \substr_count($source, "\n") + 1);
    }

    public function testSessionNameJitHelperDelegatesToVmSession(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/SessionNameJitHelper.php');
        $this->assertStringContainsString('VmSession::isRejectedSessionName', $source);
        $this->assertStringContainsString('VmSession::rejectedSessionNameMessage', $source);
    }

    public function testSessionNameJitHelperSemanticsMatchVmSession(): void
    {
        $this->assertTrue(SessionNameJitHelper::isRejected(''));
        $this->assertTrue(VmSession::isRejectedSessionName(''));
        $this->assertSame(
            VmSession::isRejectedSessionName('PHPSESSID'),
            SessionNameJitHelper::isRejected('PHPSESSID')
        );
        $this->assertFalse(SessionNameJitHelper::isRejected('PHPSESSID'));
        $this->assertSame(
            VmSession::rejectedSessionNameMessage(''),
            SessionNameJitHelper::warningMessage('')
        );
    }
}
