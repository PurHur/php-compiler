<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\posix\posix_getrlimit;
use PHPCompiler\ext\posix\posix_setsid;
use PHPCompiler\ext\posix\posix_times;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtins for posix times/rlimit/session (#7173). */
final class PosixTimesRlimitBuiltinTest extends TestCase
{
    public function test_posix_times_matches_host_shape(): void
    {
        if (!\function_exists('posix_times')) {
            $this->markTestSkipped('host ext-posix unavailable');
        }

        $runtime = new Runtime();
        $fn = new posix_times();
        $frame = $fn->getFrame($runtime->vmContext);
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);

        $host = \posix_times();
        $vm = $frame->returnVar->resolveIndirect()->toArray();
        foreach (['ticks', 'utime', 'stime', 'cutime', 'cstime'] as $key) {
            $slot = $vm->find($key);
            $this->assertNotNull($slot, $key);
            $this->assertSame('integer', \gettype($host[$key]));
            $this->assertSame(VMVariable::TYPE_INTEGER, $slot->resolveIndirect()->type, $key);
        }
        $this->assertGreaterThan(0, $vm->find('ticks')->resolveIndirect()->toInt());
    }

    public function test_posix_getrlimit_returns_twenty_keys(): void
    {
        if (!\function_exists('posix_getrlimit')) {
            $this->markTestSkipped('host ext-posix unavailable');
        }

        $runtime = new Runtime();
        $fn = new posix_getrlimit();
        $frame = $fn->getFrame($runtime->vmContext);
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);

        $vm = $frame->returnVar->resolveIndirect()->toArray();
        $host = \posix_getrlimit();
        $this->assertSame(20, \count($host));
        foreach (\array_keys($host) as $key) {
            $slot = $vm->find($key);
            $this->assertNotNull($slot, $key);
            $resolved = $slot->resolveIndirect();
            if (\is_int($host[$key])) {
                $this->assertSame(VMVariable::TYPE_INTEGER, $resolved->type, $key);
            } else {
                $this->assertSame(VMVariable::TYPE_STRING, $resolved->type, $key);
            }
        }
    }

    public function test_posix_setsid_returns_int(): void
    {
        if (!\function_exists('posix_setsid')) {
            $this->markTestSkipped('host ext-posix unavailable');
        }

        $runtime = new Runtime();
        $fn = new posix_setsid();
        $frame = $fn->getFrame($runtime->vmContext);
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);

        $this->assertSame(
            VMVariable::TYPE_INTEGER,
            $frame->returnVar->resolveIndirect()->type
        );
    }
}
