<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\standard\VmProcessProcOpenNative;
use PHPUnit\Framework\TestCase;

/** proc_get_status() hash insertion order matches php-src (#13210, #16707). */
final class ProcGetStatusKeyOrderTest extends TestCase
{
    public function testBuildProcStatusArrayKeyOrder(): void
    {
        $status = VmProcessProcOpenNative::buildProcStatusArray(
            'echo ok',
            42,
            true,
            false,
            false,
            -1,
            0,
            0,
        );

        $expected = ['command', 'pid', 'running', 'signaled', 'stopped', 'exitcode', 'termsig', 'stopsig'];
        if (CompilerVersion::supportsProcGetStatusPendingSignals()) {
            $expected[] = 'pending_signals';
        }

        $this->assertSame($expected, \array_keys($status));
        $this->assertArrayNotHasKey('cached', $status);
    }

    public function testBuildProcStatusArrayPendingSignalsOnForwardProfile(): void
    {
        if (!CompilerVersion::supportsProcGetStatusPendingSignals()) {
            $this->markTestSkipped('requires PHP_COMPILER_PROFILE>=8.3');
        }

        $status = VmProcessProcOpenNative::buildProcStatusArray(
            'echo ok',
            42,
            true,
            false,
            false,
            -1,
            0,
            0,
            [],
        );

        $this->assertArrayHasKey('pending_signals', $status);
        $this->assertSame([], $status['pending_signals']);
        $this->assertArrayNotHasKey('cached', $status);
    }
}
