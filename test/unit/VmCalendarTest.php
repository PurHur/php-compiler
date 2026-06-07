<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\calendar\CalendarConstants;
use PHPCompiler\ext\calendar\VmCalendar;
use PHPUnit\Framework\TestCase;

/**
 * @group calendar_basic
 */
final class VmCalendarTest extends TestCase
{
    public function testCalDaysInMonthGregorianLeapFebruary(): void
    {
        $this->assertSame(29, VmCalendar::calDaysInMonth(CalendarConstants::CAL_GREGORIAN, 2, 2024));
        $this->assertSame(28, VmCalendar::calDaysInMonth(CalendarConstants::CAL_GREGORIAN, 2, 2023));
        $this->assertSame(30, VmCalendar::calDaysInMonth(CalendarConstants::CAL_GREGORIAN, 4, 2024));
    }

    public function testCalDaysInMonthJulian(): void
    {
        $this->assertSame(29, VmCalendar::calDaysInMonth(CalendarConstants::CAL_JULIAN, 2, 2024));
    }

    public function testGregorianToJd(): void
    {
        $this->assertSame(2460385, VmCalendar::gregorianToJd(3, 15, 2024));
        $this->assertSame(2440588, VmCalendar::gregorianToJd(1, 1, 1970));
        $this->assertSame(2299150, VmCalendar::gregorianToJd(10, 4, 1582));
        $this->assertSame(0, VmCalendar::gregorianToJd(0, 1, 2024));
    }

    public function testEasterDate(): void
    {
        $this->assertSame(1711843200, VmCalendar::easterDate(2024));
        $this->assertSame(956448000, VmCalendar::easterDate(2000));
    }

}
