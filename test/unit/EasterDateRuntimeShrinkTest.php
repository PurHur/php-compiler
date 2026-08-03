<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\calendar\CalendarConstants;
use PHPCompiler\ext\calendar\EasterDateJitHelper;
use PHPCompiler\ext\calendar\VmCalendar;
use PHPUnit\Framework\TestCase;

/** easter_date() JIT routes through EasterDateJitHelper (#27356). */
final class EasterDateRuntimeShrinkTest extends TestCase
{
    public function testBuiltinRoutesThroughJitHelperNotRefuse(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/calendar/easter_date.php');
        $this->assertStringContainsString('JitEasterDate::invoke', $builtin);
        $this->assertStringNotContainsString('not implemented for JIT', $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/EasterDateRuntime.php');
        $this->assertStringContainsString('EasterDateJitHelper', $bridge);
        $this->assertStringContainsString('phpc_easter_date', $bridge);
    }

    public function testJitHelperSemanticsMatchVmCalendarUnderUtc(): void
    {
        $prev = date_default_timezone_get();
        date_default_timezone_set('UTC');
        try {
            $this->assertSame(1711843200, EasterDateJitHelper::easterDateArgv(2024, 0));
            $this->assertSame(
                VmCalendar::easterDate(2024, CalendarConstants::CAL_EASTER_DEFAULT),
                EasterDateJitHelper::easterDateArgv(2024, CalendarConstants::CAL_EASTER_DEFAULT)
            );
            $this->assertSame(
                VmCalendar::easterDate(2023, CalendarConstants::CAL_EASTER_DEFAULT),
                EasterDateJitHelper::easterDateArgv(2023, CalendarConstants::CAL_EASTER_DEFAULT)
            );
        } finally {
            date_default_timezone_set($prev);
        }
    }

    public function testJitHelperRejectsInvalidYear(): void
    {
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('must be a year after 1970');
        EasterDateJitHelper::easterDateArgv(1969, 0);
    }
}
