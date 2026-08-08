<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\calendar\UnixtojdJitHelper;
use PHPCompiler\ext\calendar\VmCalendar;
use PHPUnit\Framework\TestCase;

/** unixtojd() JIT routes through UnixtojdJitHelper (#27367). */
final class UnixtojdRuntimeShrinkTest extends TestCase
{
    public function testBuiltinRoutesThroughJitHelperNotRefuse(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/calendar/unixtojd.php');
        $this->assertStringContainsString('JitUnixtojd::invoke', $builtin);
        $this->assertStringNotContainsString('not implemented for JIT', $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/UnixtojdRuntime.php');
        $this->assertStringContainsString('UnixtojdJitHelper', $bridge);
        $this->assertStringContainsString('phpc_unixtojd', $bridge);
    }

    public function testJitHelperSemanticsMatchVmCalendarUnderUtc(): void
    {
        $prev = date_default_timezone_get();
        date_default_timezone_set('UTC');
        try {
            $this->assertSame(2460891, UnixtojdJitHelper::unixtojdArgv(1754236800));
            $this->assertSame(
                VmCalendar::unixtojd(1754236800),
                UnixtojdJitHelper::unixtojdArgv(1754236800)
            );
            $this->assertSame(
                VmCalendar::unixtojd(0),
                UnixtojdJitHelper::unixtojdArgv(0)
            );
            $this->assertSame(
                VmCalendar::unixtojd(2440588 * 0 + 86400),
                UnixtojdJitHelper::unixtojdArgv(86400)
            );
            $this->assertFalse(VmCalendar::unixtojd(\PHP_INT_MAX));
            $this->assertSame(
                UnixtojdJitHelper::FALSE_SENTINEL,
                UnixtojdJitHelper::unixtojdArgv(\PHP_INT_MAX)
            );
        } finally {
            date_default_timezone_set($prev);
        }
    }
}
