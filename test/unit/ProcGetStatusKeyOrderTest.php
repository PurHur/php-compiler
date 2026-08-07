<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\standard\VmProcessProcOpenNative;
use PHPUnit\Framework\TestCase;

/** proc_get_status() hash insertion order matches php-src (#13210, #17362, #28527). */
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

        $expected = ['command', 'pid'];
        if (CompilerVersion::supportsProcGetStatusCached()) {
            $expected[] = 'cached';
        }
        $expected = array_merge($expected, ['running', 'signaled', 'stopped', 'exitcode', 'termsig', 'stopsig']);
        if (CompilerVersion::supportsProcGetStatusPendingSignals()) {
            $expected[] = 'pending_signals';
        }

        $this->assertSame($expected, \array_keys($status));
        $this->assertArrayNotHasKey('pending_signals', $status);
    }

    public function testBuildProcStatusArrayPendingSignalsRetired(): void
    {
        $this->assertFalse(CompilerVersion::supportsProcGetStatusPendingSignals());

        $status = VmProcessProcOpenNative::buildProcStatusArray(
            'echo ok',
            42,
            true,
            false,
            false,
            -1,
            0,
            0,
            [15],
        );

        $this->assertArrayNotHasKey('pending_signals', $status);
    }

    public function testBuildProcStatusArrayCachedOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsProcGetStatusCached());

            $running = VmProcessProcOpenNative::buildProcStatusArray(
                'echo ok',
                42,
                true,
                false,
                false,
                -1,
                0,
                0,
                [],
                false,
            );
            $this->assertSame(
                ['command', 'pid', 'cached', 'running', 'signaled', 'stopped', 'exitcode', 'termsig', 'stopsig'],
                \array_keys($running),
            );
            $this->assertFalse($running['cached']);

            $exited = VmProcessProcOpenNative::buildProcStatusArray(
                'echo ok',
                42,
                false,
                false,
                false,
                0,
                0,
                0,
                [],
                true,
            );
            $this->assertTrue($exited['cached']);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
