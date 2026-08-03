<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\calendar\CalendarConstants;
use PHPCompiler\ext\calendar\EasterDaysJitHelper;
use PHPCompiler\ext\calendar\VmCalendar;
use PHPUnit\Framework\TestCase;

/** easter_days() JIT routes through EasterDaysJitHelper (#27358). */
final class EasterDaysRuntimeShrinkTest extends TestCase
{
    public function testBuiltinRoutesThroughJitHelperNotRefuse(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/calendar/easter_days.php');
        $this->assertStringContainsString('JitEasterDays::invoke', $builtin);
        $this->assertStringNotContainsString('not implemented for JIT', $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/EasterDaysRuntime.php');
        $this->assertStringContainsString('EasterDaysJitHelper', $bridge);
        $this->assertStringContainsString('phpc_easter_days', $bridge);
    }

    public function testJitHelperSemanticsMatchVmCalendar(): void
    {
        $this->assertSame(
            VmCalendar::easterDays(2024, CalendarConstants::CAL_EASTER_DEFAULT),
            EasterDaysJitHelper::easterDaysArgv(2024, CalendarConstants::CAL_EASTER_DEFAULT)
        );
        $this->assertSame(10, EasterDaysJitHelper::easterDaysArgv(2024, 0));
        $this->assertSame(19, EasterDaysJitHelper::easterDaysArgv(2023, 0));
        $this->assertSame(
            VmCalendar::easterDays(2023, CalendarConstants::CAL_EASTER_DEFAULT),
            EasterDaysJitHelper::easterDaysArgv(2023, CalendarConstants::CAL_EASTER_DEFAULT)
        );
    }

    public function testJitHelperRejectsInvalidYear(): void
    {
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('must be between 1 and');
        EasterDaysJitHelper::easterDaysArgv(0, 0);
    }
}
