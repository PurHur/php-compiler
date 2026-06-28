<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmProcessProcOpenNative;
use PHPUnit\Framework\TestCase;

/** proc_get_status() hash insertion order matches php-src (#13210). */
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

        $this->assertSame(
            ['command', 'pid', 'running', 'signaled', 'stopped', 'exitcode', 'termsig', 'stopsig'],
            \array_keys($status),
        );
    }
}
