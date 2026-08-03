<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\calendar\CalDaysInMonthJitHelper;
use PHPCompiler\ext\calendar\CalendarConstants;
use PHPCompiler\ext\calendar\VmCalendar;
use PHPUnit\Framework\TestCase;

/** cal_days_in_month() JIT routes through CalDaysInMonthJitHelper (#27310). */
final class CalDaysInMonthRuntimeShrinkTest extends TestCase
{
    public function testBuiltinRoutesThroughJitHelperNotRefuse(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/calendar/cal_days_in_month.php');
        $this->assertStringContainsString('JitCalDaysInMonth::invoke', $builtin);
        $this->assertStringNotContainsString('not implemented for JIT', $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/CalDaysInMonthRuntime.php');
        $this->assertStringContainsString('CalDaysInMonthJitHelper', $bridge);
        $this->assertStringContainsString('phpc_cal_days_in_month', $bridge);
    }

    public function testJitHelperSemanticsMatchVmCalendar(): void
    {
        $this->assertSame(
            VmCalendar::calDaysInMonth(CalendarConstants::CAL_GREGORIAN, 2, 2024),
            CalDaysInMonthJitHelper::calDaysInMonthArgv(CalendarConstants::CAL_GREGORIAN, 2, 2024)
        );
        $this->assertSame(29, CalDaysInMonthJitHelper::calDaysInMonthArgv(0, 2, 2024));
        $this->assertSame(28, CalDaysInMonthJitHelper::calDaysInMonthArgv(0, 2, 2023));
    }

    public function testJitHelperRejectsInvalidCalendarId(): void
    {
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('must be a valid calendar ID');
        CalDaysInMonthJitHelper::calDaysInMonthArgv(99, 1, 2000);
    }
}
