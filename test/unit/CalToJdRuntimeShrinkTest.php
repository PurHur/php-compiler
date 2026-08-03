<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\calendar\CalToJdJitHelper;
use PHPCompiler\ext\calendar\CalendarConstants;
use PHPCompiler\ext\calendar\VmCalendar;
use PHPUnit\Framework\TestCase;

/** cal_to_jd() JIT routes through CalToJdJitHelper (#27366). */
final class CalToJdRuntimeShrinkTest extends TestCase
{
    public function testBuiltinRoutesThroughJitHelperNotRefuse(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/calendar/cal_to_jd.php');
        $this->assertStringContainsString('JitCalToJd::invoke', $builtin);
        $this->assertStringNotContainsString('not implemented for JIT', $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/CalToJdRuntime.php');
        $this->assertStringContainsString('CalToJdJitHelper', $bridge);
        $this->assertStringContainsString('phpc_cal_to_jd', $bridge);
    }

    public function testJitHelperSemanticsMatchVmCalendar(): void
    {
        $this->assertSame(
            2461256,
            CalToJdJitHelper::calToJdArgv(CalendarConstants::CAL_GREGORIAN, 8, 3, 2026)
        );
        $this->assertSame(
            VmCalendar::calToJd(CalendarConstants::CAL_GREGORIAN, 8, 3, 2026),
            CalToJdJitHelper::calToJdArgv(CalendarConstants::CAL_GREGORIAN, 8, 3, 2026)
        );
    }

    public function testJitHelperRejectsInvalidCalendarId(): void
    {
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('must be a valid calendar ID');
        CalToJdJitHelper::calToJdArgv(99, 1, 1, 2000);
    }
}
